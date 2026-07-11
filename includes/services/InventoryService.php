<?php
/**
 * InventoryService — parts catalog, stock movements, job consumption
 */
class InventoryService
{
    public static function listParts(bool $activeOnly = true): array
    {
        ensureSchemaUpdates();
        $db = getDB();
        $sql = 'SELECT * FROM parts';
        if ($activeOnly) {
            $sql .= ' WHERE is_active=1';
        }
        $sql .= ' ORDER BY name';
        return $db->query($sql)->fetchAll();
    }

    public static function lowStockParts(): array
    {
        ensureSchemaUpdates();
        $db = getDB();
        return $db->query('SELECT * FROM parts WHERE is_active=1 AND stock_qty <= min_stock ORDER BY stock_qty ASC')->fetchAll();
    }

    public static function getPart(int $id): ?array
    {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM parts WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return array{ok:bool,error?:string,id?:int}
     */
    public static function savePart(array $data, ?int $id = null): array
    {
        ensureSchemaUpdates();
        $name = trim($data['name'] ?? '');
        $sku = trim($data['sku'] ?? '');
        if ($name === '') {
            return ['ok' => false, 'error' => 'Part name is required. / Nama alat ganti diperlukan.'];
        }
        $db = getDB();
        $fields = [
            $name,
            $sku !== '' ? $sku : null,
            trim($data['category'] ?? 'General') ?: 'General',
            (float) ($data['unit_price'] ?? 0),
            (float) ($data['cost_price'] ?? 0),
            (int) ($data['stock_qty'] ?? 0),
            (int) ($data['min_stock'] ?? 5),
            trim($data['supplier'] ?? '') ?: null,
            !empty($data['is_active']) ? 1 : 0,
        ];

        try {
            if ($id) {
                $db->prepare('
                    UPDATE parts SET name=?, sku=?, category=?, unit_price=?, cost_price=?, stock_qty=?, min_stock=?, supplier=?, is_active=?
                    WHERE id=?
                ')->execute([...$fields, $id]);
                return ['ok' => true, 'id' => $id];
            }
            $db->prepare('
                INSERT INTO parts (name, sku, category, unit_price, cost_price, stock_qty, min_stock, supplier, is_active)
                VALUES (?,?,?,?,?,?,?,?,?)
            ')->execute($fields);
            return ['ok' => true, 'id' => (int) $db->lastInsertId()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not save part (SKU may be duplicate). / Tidak dapat simpan alat ganti.'];
        }
    }

    /**
     * Adjust stock with audit trail.
     * @return array{ok:bool,error?:string}
     */
    public static function adjustStock(int $partId, int $delta, string $reason, ?int $userId = null, string $type = 'adjustment'): array
    {
        ensureSchemaUpdates();
        $db = getDB();
        $part = self::getPart($partId);
        if (!$part) {
            return ['ok' => false, 'error' => 'Part not found. / Alat ganti tidak dijumpai.'];
        }
        $newQty = (int) $part['stock_qty'] + $delta;
        if ($newQty < 0) {
            return ['ok' => false, 'error' => 'Insufficient stock for ' . $part['name'] . '. / Stok tidak mencukupi.'];
        }

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE parts SET stock_qty=? WHERE id=?')->execute([$newQty, $partId]);
            $db->prepare('
                INSERT INTO stock_movements (part_id, movement_type, qty, balance_after, reason, created_by)
                VALUES (?,?,?,?,?,?)
            ')->execute([
                $partId,
                $type,
                $delta,
                $newQty,
                $reason,
                $userId,
            ]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'Stock adjustment failed. / Pelarasan stok gagal.'];
        }

        if ($newQty <= (int) $part['min_stock']) {
            notify(1, 'Low Stock Alert / Amaran Stok Rendah', $part['name'] . ' is low: ' . $newQty . ' left (min ' . $part['min_stock'] . ').', 'alert', baseUrl('admin/inventory.php'));
        }

        return ['ok' => true];
    }

    /**
     * Consume parts for a job — deduct stock + write job_parts rows.
     *
     * @param array<int,array{part_id:int,qty:int}> $partsUsed
     * @return array{ok:bool,error?:string,parts_total?:float}
     */
    public static function consumePartsForJob(int $appointmentId, array $partsUsed, ?int $userId = null): array
    {
        ensureSchemaUpdates();
        $db = getDB();
        try {
            $db->query('SELECT 1 FROM job_parts LIMIT 1');
        } catch (Throwable $e) {
            self::repairJobPartsTable($db);
        }
        $total = 0.0;
        $ownTx = !$db->inTransaction();

        if ($ownTx) {
            $db->beginTransaction();
        }
        try {
            foreach ($partsUsed as $row) {
                $partId = (int) ($row['part_id'] ?? 0);
                $qty = (int) ($row['qty'] ?? 0);
                if ($partId <= 0 || $qty <= 0) {
                    continue;
                }
                $part = self::getPart($partId);
                if (!$part) {
                    throw new RuntimeException('Part not found: ' . $partId);
                }
                if ((int) $part['stock_qty'] < $qty) {
                    throw new RuntimeException('Insufficient stock for ' . $part['name']);
                }
                $unit = (float) $part['unit_price'];
                $line = round($unit * $qty, 2);
                $total += $line;
                $newQty = (int) $part['stock_qty'] - $qty;

                $db->prepare('UPDATE parts SET stock_qty=? WHERE id=?')->execute([$newQty, $partId]);
                $db->prepare('
                    INSERT INTO stock_movements (part_id, movement_type, qty, balance_after, reason, reference_id, created_by)
                    VALUES (?,?,?,?,?,?,?)
                ')->execute([
                    $partId, 'out', -$qty, $newQty,
                    'Used on appointment #' . $appointmentId,
                    $appointmentId, $userId,
                ]);
                $db->prepare('
                    INSERT INTO job_parts (appointment_id, part_id, qty, unit_price_at_time, total)
                    VALUES (?,?,?,?,?)
                ')->execute([$appointmentId, $partId, $qty, $unit, $line]);
            }
            if ($ownTx) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage() . ' / Stok tidak mencukupi.'];
        }

        return ['ok' => true, 'parts_total' => $total];
    }

    public static function partsForAppointment(int $appointmentId): array
    {
        ensureSchemaUpdates();
        try {
            $db = getDB();
            try {
                $db->query('SELECT 1 FROM job_parts LIMIT 1');
            } catch (Throwable $e) {
                self::repairJobPartsTable($db);
            }
            $stmt = $db->prepare('
                SELECT jp.*, p.name, p.sku
                FROM job_parts jp
                JOIN parts p ON p.id = jp.part_id
                WHERE jp.appointment_id=?
                ORDER BY jp.id
            ');
            $stmt->execute([$appointmentId]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function partsMarginSummary(?string $from = null, ?string $to = null): array
    {
        ensureSchemaUpdates();
        $empty = ['parts_revenue' => 0.0, 'parts_cost' => 0.0, 'parts_margin' => 0.0];
        try {
            $db = getDB();
            // Repair orphan/corrupt job_parts (MySQL error 1932)
            try {
                $db->query('SELECT 1 FROM job_parts LIMIT 1');
            } catch (Throwable $e) {
                self::repairJobPartsTable($db);
            }
            $sql = '
                SELECT COALESCE(SUM(jp.total),0) AS parts_revenue,
                       COALESCE(SUM(jp.qty * p.cost_price),0) AS parts_cost
                FROM job_parts jp
                JOIN parts p ON p.id = jp.part_id
                JOIN appointments a ON a.id = jp.appointment_id
                WHERE a.status = "Completed"
            ';
            $params = [];
            if ($from) {
                $sql .= ' AND a.appointment_date >= ?';
                $params[] = $from;
            }
            if ($to) {
                $sql .= ' AND a.appointment_date <= ?';
                $params[] = $to;
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch() ?: ['parts_revenue' => 0, 'parts_cost' => 0];
            $rev = (float) $row['parts_revenue'];
            $cost = (float) $row['parts_cost'];
            return [
                'parts_revenue' => $rev,
                'parts_cost'    => $cost,
                'parts_margin'  => round($rev - $cost, 2),
            ];
        } catch (Throwable $e) {
            return $empty;
        }
    }

    /** Recreate job_parts if InnoDB tablespace is missing (error 1932). */
    public static function repairJobPartsTable(?PDO $db = null): void
    {
        $db = $db ?: getDB();
        try {
            $db->exec('DROP TABLE IF EXISTS job_parts');
        } catch (Throwable $e) {
            // ignore
        }
        $db->exec("CREATE TABLE IF NOT EXISTS job_parts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            appointment_id INT NOT NULL,
            history_id INT DEFAULT NULL,
            part_id INT NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            unit_price_at_time DECIMAL(10,2) NOT NULL DEFAULT 0,
            total DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_job_parts_appt (appointment_id),
            INDEX idx_job_parts_part (part_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
