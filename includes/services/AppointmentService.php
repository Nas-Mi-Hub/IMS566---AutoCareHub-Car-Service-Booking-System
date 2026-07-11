<?php
/**
 * AppointmentService — booking, slot locking, work updates
 */
class AppointmentService
{
    /**
     * Create a booking with transaction + slot capacity lock to reduce race conditions.
     *
     * @return array{ok:bool,error?:string,appointment_id?:int,booking_type?:string}
     */
    public static function createBooking(
        int $userId,
        int $vehicleId,
        int $packageId,
        string $date,
        string $timeWindow,
        string $bookingType = 'package',
        ?string $notes = null
    ): array {
        ensureSchemaUpdates();
        $db = getDB();

        if ($date < todayDate()) {
            return ['ok' => false, 'error' => 'Appointment date must be today or in the future. / Tarikh mesti hari ini atau akan datang.'];
        }
        if (!in_array($timeWindow, serviceTimeSlots(), true)) {
            return ['ok' => false, 'error' => 'Please select a valid time slot. / Sila pilih slot masa yang sah.'];
        }
        // Real workshop flow: always quote-first (no immediate pay)
        $bookingType = 'package';
        if (!canAddBooking($userId)) {
            return ['ok' => false, 'error' => 'Monthly booking limit reached. Contact the workshop. / Had tempahan bulanan tercapai.'];
        }

        $vCheck = $db->prepare('SELECT id, contact_no FROM vehicles WHERE id=? AND user_id=?');
        $vCheck->execute([$vehicleId, $userId]);
        $vehicle = $vCheck->fetch();
        if (!$vehicle) {
            return ['ok' => false, 'error' => 'Invalid vehicle. / Kenderaan tidak sah.'];
        }

        if ($packageId <= 0) {
            $packageId = self::resolveInspectionPackageId();
        }

        $pkg = $db->prepare('SELECT id, name, price FROM service_packages WHERE id=? AND is_active=1');
        $pkg->execute([$packageId]);
        $package = $pkg->fetch();
        if (!$package) {
            return ['ok' => false, 'error' => 'Invalid service package. / Pakej servis tidak sah.'];
        }

        try {
            $db->beginTransaction();

            // Accurate capacity check (COUNT — not PDO rowCount)
            $cntStmt = $db->prepare("
                SELECT COUNT(*) FROM appointments
                WHERE appointment_date=? AND TRIM(time_window)=TRIM(?) AND status != 'Cancelled'
                FOR UPDATE
            ");
            $cntStmt->execute([$date, $timeWindow]);
            $count = (int) $cntStmt->fetchColumn();
            if ($count >= getMaxBookingsPerSlot()) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'That time slot is full. Please choose another. / Slot masa penuh. Sila pilih slot lain.'];
            }

            $db->prepare('
                INSERT INTO appointments
                    (user_id, vehicle_id, package_id, appointment_date, time_window, status, notes, booking_type, quote_status)
                VALUES (?,?,?,?,?,?,?,?,?)
            ')->execute([
                $userId,
                $vehicleId,
                $packageId,
                $date,
                $timeWindow,
                'Approved',
                $notes,
                $bookingType,
                'pending', // always await mechanic quote
            ]);
            $apptId = (int) $db->lastInsertId();
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'error' => 'Could not create booking. Please try again. / Tidak dapat buat tempahan.'];
        }

        $userStmt = $db->prepare('SELECT full_name, email, contact_no FROM users WHERE id=?');
        $userStmt->execute([$userId]);
        $u = $userStmt->fetch() ?: [];
        $phone = $vehicle['contact_no'] ?: ($u['contact_no'] ?? '');

        notifyWithEmail(
            $userId,
            'Booking Received — Awaiting Quote / Tempahan Diterima',
            'Your booking for ' . $date . ' at ' . $timeWindow . ' (' . $package['name'] . ') is received. A mechanic will inspect and send a quote before payment. / Tempahan diterima. Mekanik akan hantar sebut harga sebelum bayaran.',
            'appointment',
            baseUrl('user/appointments.php'),
            true,
            true
        );
        notify(1, 'New Booking (Awaiting Quote)', ($u['full_name'] ?? 'Customer') . " · {$date} {$timeWindow} · " . $package['name'] . ($phone ? " · 📞 {$phone}" : ''), 'appointment', baseUrl('admin/appointments.php'));
        $mechanics = $db->query("SELECT id FROM users WHERE role='mechanic' AND is_active=1")->fetchAll();
        foreach ($mechanics as $m) {
            notify((int) $m['id'], 'New Job to Quote', ($u['full_name'] ?? 'Customer') . " booked {$date} {$timeWindow}. Submit a quote after inspection.", 'appointment', baseUrl('mechanic/appointments.php'));
        }

        return [
            'ok'             => true,
            'appointment_id' => $apptId,
            'booking_type'   => $bookingType,
            'awaiting_quote' => true,
        ];
    }

    public static function resolveInspectionPackageId(): int
    {
        $db = getDB();
        $id = $db->query("SELECT id FROM service_packages WHERE is_active=1 AND (name LIKE '%Inspection%' OR name LIKE '%Diagnos%') ORDER BY price ASC LIMIT 1")->fetchColumn();
        if ($id) {
            return (int) $id;
        }
        // Fallback: cheapest active package
        $id = $db->query('SELECT id FROM service_packages WHERE is_active=1 ORDER BY price ASC LIMIT 1')->fetchColumn();
        return (int) $id;
    }

    /**
     * Complete or start job with optional notes, photos, and parts usage.
     *
     * @param array<int,array{part_id:int,qty:int}> $partsUsed
     * @param list<string> $photoPaths
     */
    public static function updateWork(
        int $appointmentId,
        string $newStatus,
        int $mechanicId,
        ?string $workNote = null,
        ?int $dropoffMileage = null,
        array $partsUsed = [],
        array $photoPaths = [],
        ?float $laborHours = null
    ): array {
        ensureSchemaUpdates();
        $db = getDB();
        $stmt = $db->prepare('
            SELECT a.*, v.plate_no, v.brand, v.model_variant, sp.name AS pkg_name, sp.price,
                   u.email AS customer_email, u.full_name AS customer_name, u.contact_no AS customer_phone
            FROM appointments a
            JOIN vehicles v ON v.id = a.vehicle_id
            JOIN service_packages sp ON sp.id = a.package_id
            JOIN users u ON u.id = a.user_id
            WHERE a.id = ?
        ');
        $stmt->execute([$appointmentId]);
        $appt = $stmt->fetch();

        if (!$appt) {
            return ['ok' => false, 'error' => 'Appointment not found. / Temujanji tidak dijumpai.'];
        }

        $isInspection = ($appt['booking_type'] ?? 'package') === 'inspection';
        $needsPayment = !$isInspection || !empty($appt['paid_at']) || (($appt['quote_status'] ?? '') === 'approved' && !empty($appt['paid_at']));

        // Inspection jobs can start before full package payment if quote still pending
        if ($newStatus === 'In Progress' && $isInspection && empty($appt['paid_at'])) {
            // allow start for inspection
        } elseif ($newStatus !== 'In Progress' || !$isInspection) {
            if (!appointmentIsPaid($appt) && $newStatus === 'Completed' && !$isInspection) {
                return ['ok' => false, 'error' => 'This job has not been paid yet. / Kerja ini belum dibayar.'];
            }
            if (!appointmentIsPaid($appt) && $newStatus === 'In Progress' && !$isInspection) {
                return ['ok' => false, 'error' => 'This job has not been paid yet. / Kerja ini belum dibayar.'];
            }
        }

        if ($appt['status'] === 'Cancelled' || $appt['status'] === 'Completed') {
            return ['ok' => false, 'error' => 'This appointment is already closed. / Temujanji ini sudah ditutup.'];
        }

        $vehicle = $appt['plate_no'] . ' — ' . $appt['pkg_name'];
        $customerId = (int) $appt['user_id'];

        // Persist photos / notes on any update
        $photosJson = null;
        if (!empty($photoPaths)) {
            $existing = [];
            if (!empty($appt['inspection_photos'])) {
                $decoded = json_decode((string) $appt['inspection_photos'], true);
                if (is_array($decoded)) {
                    $existing = $decoded;
                }
            }
            $photosJson = json_encode(array_values(array_merge($existing, $photoPaths)), JSON_UNESCAPED_SLASHES);
        }

        if ($newStatus === 'In Progress') {
            if (!in_array($appt['status'], ['Approved', 'In Progress'], true)) {
                return ['ok' => false, 'error' => 'Cannot start this job. / Tidak boleh mulakan kerja ini.'];
            }
            if ($dropoffMileage === null || $dropoffMileage < 0) {
                return ['ok' => false, 'error' => 'Please record the vehicle mileage (km) at drop-off. / Sila rekod bacaan odometer (km).'];
            }

            $sql = "UPDATE appointments SET status='In Progress', mechanic_id=?, dropoff_mileage=?";
            $params = [$mechanicId, $dropoffMileage];
            if ($workNote !== null && $workNote !== '') {
                $sql .= ', job_notes = CONCAT(COALESCE(job_notes,\'\'), IF(job_notes IS NULL OR job_notes=\'\', \'\', \'\n\'), ?)';
                $params[] = $workNote;
            }
            if ($photosJson !== null) {
                $sql .= ', inspection_photos=?';
                $params[] = $photosJson;
            }
            if ($laborHours !== null) {
                $sql .= ', labor_hours=?';
                $params[] = $laborHours;
            }
            $sql .= ' WHERE id=?';
            $params[] = $appointmentId;
            $db->prepare($sql)->execute($params);
            $db->prepare('UPDATE vehicles SET current_mileage=? WHERE id=?')->execute([$dropoffMileage, $appt['vehicle_id']]);

            $mech = $db->prepare('SELECT full_name FROM users WHERE id=?');
            $mech->execute([$mechanicId]);
            $mechName = $mech->fetchColumn() ?: 'Workshop team';

            notifyWithEmail(
                $customerId,
                'Work Started / Kerja Dimulakan',
                "{$mechName} has started work on {$vehicle}. Mileage: " . number_format($dropoffMileage) . " km.",
                'appointment',
                baseUrl('user/appointments.php'),
                true,
                true
            );

            return ['ok' => true, 'message' => 'Job started. Mileage recorded: ' . number_format($dropoffMileage) . ' km. / Kerja dimulakan.'];
        }

        if ($newStatus === 'Completed') {
            if (!in_array($appt['status'], ['Approved', 'In Progress'], true)) {
                return ['ok' => false, 'error' => 'Cannot complete this job. / Tidak boleh selesaikan kerja ini.'];
            }

            $mileage = $dropoffMileage;
            if ($mileage === null && !empty($appt['dropoff_mileage'])) {
                $mileage = (int) $appt['dropoff_mileage'];
            }
            if ($mileage === null || $mileage < 0) {
                return ['ok' => false, 'error' => 'Please record the vehicle mileage (km) before completing. / Sila rekod odometer sebelum selesai.'];
            }

            $gross = (float) ($appt['price'] ?? 0);
            if (!empty($appt['quote_amount']) && (float) $appt['quote_amount'] > 0) {
                $gross = (float) $appt['quote_amount'];
            }
            $histStmt = $db->prepare('SELECT id, gross_payment FROM service_history WHERE appointment_id=?');
            $histStmt->execute([$appointmentId]);
            $histRow = $histStmt->fetch();
            if ($histRow) {
                $gross = (float) $histRow['gross_payment'];
            }

            $commPct = getMechanicCommissionPercent();
            $partsTotal = 0.0;

            $db->beginTransaction();
            try {
                // Deduct parts via InventoryService
                if (!empty($partsUsed)) {
                    $partsResult = InventoryService::consumePartsForJob($appointmentId, $partsUsed, $mechanicId);
                    if (!$partsResult['ok']) {
                        $db->rollBack();
                        return $partsResult;
                    }
                    $partsTotal = (float) $partsResult['parts_total'];
                    // Add parts to gross if not already in package price
                    if ($partsTotal > 0 && empty($histRow)) {
                        $gross += $partsTotal;
                    } elseif ($partsTotal > 0 && $histRow) {
                        $gross = (float) $histRow['gross_payment'] + $partsTotal;
                        $db->prepare('UPDATE service_history SET gross_payment=? WHERE appointment_id=?')
                           ->execute([$gross, $appointmentId]);
                    }
                }

                $commAmt = round($gross * ($commPct / 100), 2);
                $partsJson = !empty($partsUsed) ? json_encode($partsUsed) : null;
                $finalNotes = $workNote ?: ($appt['job_notes'] ?? null);

                if ($histRow) {
                    $db->prepare('
                        UPDATE service_history SET completion_date=?, mechanic_id=?, commission_amount=?, commission_percent=?,
                               job_notes=COALESCE(?, job_notes), parts_used=COALESCE(?, parts_used),
                               parts_total=COALESCE(?, parts_total), labor_total=?, gross_payment=?
                        WHERE appointment_id=?
                    ')->execute([
                        todayDate(), $mechanicId, $commAmt, $commPct,
                        $finalNotes, $partsJson, $partsTotal > 0 ? $partsTotal : null,
                        (float) ($appt['price'] ?? 0), $gross, $appointmentId,
                    ]);
                } else {
                    $db->prepare('
                        INSERT INTO service_history
                            (appointment_id,user_id,vehicle_id,package_id,mechanic_id,completion_date,gross_payment,
                             commission_amount,commission_percent,job_notes,parts_used,parts_total,labor_total)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ')->execute([
                        $appointmentId, $appt['user_id'], $appt['vehicle_id'], $appt['package_id'], $mechanicId,
                        todayDate(), $gross, $commAmt, $commPct, $finalNotes, $partsJson,
                        $partsTotal, (float) ($appt['price'] ?? 0),
                    ]);
                }

                $upd = "UPDATE appointments SET status='Completed', mechanic_id=?, dropoff_mileage=COALESCE(dropoff_mileage,?)";
                $updParams = [$mechanicId, $mileage];
                if ($finalNotes) {
                    $upd .= ', job_notes=?';
                    $updParams[] = $finalNotes;
                }
                if ($photosJson !== null) {
                    $upd .= ', inspection_photos=?';
                    $updParams[] = $photosJson;
                }
                if ($laborHours !== null) {
                    $upd .= ', labor_hours=?';
                    $updParams[] = $laborHours;
                }
                $upd .= ' WHERE id=?';
                $updParams[] = $appointmentId;
                $db->prepare($upd)->execute($updParams);

                $db->prepare('UPDATE vehicles SET current_mileage=?, last_service_mileage=?, last_service_date=? WHERE id=?')
                   ->execute([$mileage, $mileage, todayDate(), $appt['vehicle_id']]);

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                return ['ok' => false, 'error' => 'Could not complete job. / Tidak dapat selesaikan kerja.'];
            }

            $intervalKm = getServiceIntervalKm();
            $intervalMonths = getServiceIntervalMonths();
            $nextKm = $mileage + $intervalKm;
            $nextDate = date('Y-m-d', strtotime('+' . $intervalMonths . ' months'));
            $commAmt = round($gross * ($commPct / 100), 2);
            $checklist = nextServiceChecklist((string) $appt['pkg_name'], $mileage);
            try {
                $db->prepare('UPDATE appointments SET next_service_notes=? WHERE id=?')
                   ->execute([$checklist, $appointmentId]);
            } catch (Throwable $e) { /* ignore */ }

            notifyWithEmail(
                $customerId,
                'Service Completed + Next Service / Servis Selesai',
                "Work on {$vehicle} is complete.\nMileage recorded: " . number_format($mileage) . " km.\n\n" . $checklist,
                'receipt',
                baseUrl('user/history.php'),
                true,
                true
            );
            notify($mechanicId, 'Commission Earned / Komisen Diperoleh', "You earned " . formatRM($commAmt) . " ({$commPct}%) on {$vehicle}.", 'success', baseUrl('mechanic/commission.php'));

            sendServiceReminderEmail(
                (string) $appt['customer_email'],
                (string) $appt['customer_name'],
                (string) $appt['plate_no'],
                $mileage,
                $nextKm,
                $nextDate
            );

            return [
                'ok'         => true,
                'message'    => 'Job completed. Commission: ' . formatRM($commAmt) . " ({$commPct}%). Parts: " . formatRM($partsTotal) . ' / Kerja selesai.',
                'commission' => $commAmt,
                'parts_total'=> $partsTotal,
            ];
        }

        return ['ok' => false, 'error' => 'Invalid status update. / Kemaskini status tidak sah.'];
    }
}
