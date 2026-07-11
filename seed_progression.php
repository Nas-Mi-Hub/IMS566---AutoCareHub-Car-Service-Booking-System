<?php
/**
 * One-time demo progression seeder.
 * Adds more bookings, completed services, commissions & parts usage
 * so admin / mechanic / ahmad dashboards show real activity & profit.
 *
 * Open: http://localhost/autocarhub/seed_progression.php
 * Delete this file after use.
 */
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Seed Progression</title>';
echo '<style>body{font-family:system-ui;background:#0f172a;color:#e2e8f0;padding:2rem}';
echo '.ok{color:#34d399}.err{color:#f87171}code{background:#1e293b;padding:.15rem .4rem;border-radius:4px}</style></head><body>';
echo '<h1>AutoCare Hub — Demo Progression Seed</h1>';

try {
    $db = getDB();
    $sqlFile = __DIR__ . '/database/seed_demo_progression.sql';
    if (!is_readable($sqlFile)) {
        throw new RuntimeException('Missing database/seed_demo_progression.sql');
    }
    $sql = file_get_contents($sqlFile);
    // Strip USE and comments for multi-exec
    $sql = preg_replace('/^\s*USE\s+\w+\s*;/im', '', $sql);

    // Check markers — gate on v2 so people who already ran the original (v1) seed
    // still get the bigger v2 batch (more bookings/services/profit/inventory usage).
    $db->exec("INSERT IGNORE INTO workshop_settings (setting_key, setting_value) VALUES ('demo_progression_seeded','0')");
    $db->exec("INSERT IGNORE INTO workshop_settings (setting_key, setting_value) VALUES ('demo_progression_seeded_v2','0')");
    $stmt = $db->prepare("SELECT setting_value FROM workshop_settings WHERE setting_key='demo_progression_seeded_v2'");
    $stmt->execute();
    $seededV2 = $stmt->fetchColumn();
    if ($seededV2 === '1') {
        echo '<p class="ok">Already fully seeded (v1 + v2 markers set).</p>';
        echo '<p>To re-seed v2 only: run in MySQL:<br><code>UPDATE workshop_settings SET setting_value=\'0\' WHERE setting_key=\'demo_progression_seeded_v2\';</code><br>then reload this page.</p>';
    } else {

        // Prefer mysql CLI for complex SQL
        $mysql = 'C:\\xampp\\mysql\\bin\\mysql.exe';
        if (is_executable($mysql)) {
            $cmd = escapeshellarg($mysql) . ' -u' . escapeshellarg(DB_USER);
            if (DB_PASS !== '') {
                $cmd .= ' -p' . escapeshellarg(DB_PASS);
            }
            $cmd .= ' ' . escapeshellarg(DB_NAME) . ' < ' . escapeshellarg($sqlFile);
            // Windows cmd redirection
            $full = 'cmd /c ' . $cmd . ' 2>&1';
            exec($full, $out, $code);
            if ($code !== 0) {
                // fallback: force marker and PHP inserts
                echo '<p class="err">mysql CLI exit ' . $code . ': ' . htmlspecialchars(implode("\n", $out)) . '</p>';
                echo '<p>Trying PHP fallback seeder…</p>';
                seedProgressionPhp($db);
                seedProgressionV2Php($db);
            } else {
                echo '<p class="ok">✓ Seeded via mysql CLI (includes v2 bigger dataset)</p>';
                echo '<pre style="font-size:12px;color:#94a3b8">' . htmlspecialchars(implode("\n", $out)) . '</pre>';
            }
        } else {
            seedProgressionPhp($db);
            seedProgressionV2Php($db);
            echo '<p class="ok">✓ Seeded via PHP fallback</p>';
        }
    }

    // Summary
    $rev = (float) $db->query("SELECT COALESCE(SUM(gross_payment),0) FROM service_history WHERE status='Settled'")->fetchColumn();
    $comm = (float) $db->query("SELECT COALESCE(SUM(commission_amount),0) FROM service_history WHERE status='Settled'")->fetchColumn();
    $ahmad = (int) $db->query('SELECT COUNT(*) FROM service_history WHERE user_id=4')->fetchColumn();
    $m1 = (int) $db->query('SELECT COUNT(*) FROM service_history WHERE mechanic_id=2')->fetchColumn();
    $m2 = (int) $db->query('SELECT COUNT(*) FROM service_history WHERE mechanic_id=3')->fetchColumn();
    $appts = (int) $db->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
    $hist = (int) $db->query('SELECT COUNT(*) FROM service_history')->fetchColumn();

    echo '<h2>Current totals</h2><ul>';
    echo '<li>Appointments: <strong>' . $appts . '</strong></li>';
    echo '<li>Service history rows: <strong>' . $hist . '</strong></li>';
    echo '<li>Total revenue (settled): <strong>RM ' . number_format($rev, 2) . '</strong></li>';
    echo '<li>Total commissions: <strong>RM ' . number_format($comm, 2) . '</strong></li>';
    echo '<li>Est. profit (rev − commission): <strong>RM ' . number_format($rev - $comm, 2) . '</strong></li>';
    echo '<li>Ahmad completed services: <strong>' . $ahmad . '</strong></li>';
    echo '<li>mechanic1 jobs: <strong>' . $m1 . '</strong> · mechanic2: <strong>' . $m2 . '</strong></li>';
    echo '</ul>';
    echo '<p><a style="color:#f97316" href="admin/dashboard.php">→ Admin dashboard</a> · ';
    echo '<a style="color:#f97316" href="mechanic/dashboard.php">Mechanic</a> · ';
    echo '<a style="color:#f97316" href="user/dashboard.php">Customer (ahmad)</a></p>';
    echo '<p style="color:#64748b;font-size:12px">Delete <code>seed_progression.php</code> after use.</p>';
} catch (Throwable $e) {
    echo '<p class="err">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
echo '</body></html>';

function seedProgressionPhp(PDO $db): void
{
    $seeded = $db->query("SELECT setting_value FROM workshop_settings WHERE setting_key='demo_progression_seeded'")->fetchColumn();
    if ($seeded === '1') {
        return;
    }
    $db->exec("INSERT IGNORE INTO workshop_settings (setting_key, setting_value) VALUES ('demo_progression_seeded','0')");

    // Vehicles for Ahmad
    $plates = [
        [4, 'Ahmad Razak bin Ismail', '012-3456789', 'ACH 8801', 'Perodua', 'Myvi 1.5 AV', 'Hatchback', 2022, 38500, 28000, '2026-04-10'],
        [4, 'Ahmad Razak bin Ismail', '012-3456789', 'ACH 8802', 'Honda', 'City RS', 'Sedan', 2021, 52000, 42000, '2026-03-15'],
        [5, 'Siti Nurhaliza binti Omar', '013-9876543', 'ACH 5501', 'Toyota', 'Yaris', 'Hatchback', 2020, 41000, 31000, '2026-05-01'],
        [6, 'Tan Wei Ming', '016-2233445', 'ACH 6601', 'Mazda', 'CX-5', 'SUV', 2019, 68000, 58000, '2026-02-20'],
    ];
    $insV = $db->prepare('INSERT IGNORE INTO vehicles (user_id,owner_name,contact_no,plate_no,brand,model_variant,vehicle_type,year,current_mileage,last_service_mileage,last_service_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($plates as $p) {
        try {
            $insV->execute($p);
        } catch (Throwable $e) { /* skip dup */ }
    }

    $vid = static function (PDO $db, string $plate, int $fallbackUser) {
        $s = $db->prepare('SELECT id FROM vehicles WHERE plate_no=? LIMIT 1');
        $s->execute([$plate]);
        $id = (int) $s->fetchColumn();
        if ($id) {
            return $id;
        }
        $s = $db->prepare('SELECT id FROM vehicles WHERE user_id=? ORDER BY id LIMIT 1');
        $s->execute([$fallbackUser]);
        return (int) $s->fetchColumn();
    };

    $va1 = $vid($db, 'WXY 1234', 4);
    $va2 = $vid($db, 'JHR 3456', 4);
    $va3 = $vid($db, 'WNA3596', 4) ?: $va1;
    $va4 = $vid($db, 'ACH 8801', 4) ?: $va1;
    $va5 = $vid($db, 'ACH 8802', 4) ?: $va1;
    $vs1 = $vid($db, 'BJK 5678', 5);
    $vs2 = $vid($db, 'SEL 7890', 5);
    $vs3 = $vid($db, 'ACH 5501', 5) ?: $vs1;
    $vt1 = $vid($db, 'PKN 9012', 6);
    $vt2 = $vid($db, 'PEN 2468', 6);
    $vt3 = $vid($db, 'ACH 6601', 6) ?: $vt1;

    $hist = [
        // Ahmad — many completions
        [4, $va1, 1, 2, '2026-01-08', 120, 18, 15, 5, 'Minyak enjin licin'],
        [4, $va1, 3, 2, '2026-01-22', 150, 22.5, 15, 4, null],
        [4, $va2, 5, 3, '2026-02-05', 350, 52.5, 15, 5, 'Full service tip-top'],
        [4, $va1, 2, 2, '2026-02-18', 280, 42, 15, 5, 'Brake bagus'],
        [4, $va2, 4, 3, '2026-03-02', 50, 7.5, 15, 4, null],
        [4, $va3, 1, 2, '2026-03-15', 120, 18, 15, 5, null],
        [4, $va1, 6, 3, '2026-03-28', 180, 27, 15, 4, 'Battery baru OK'],
        [4, $va4, 5, 2, '2026-04-10', 350, 52.5, 15, 5, null],
        [4, $va2, 3, 2, '2026-04-22', 150, 22.5, 15, 5, 'AC sejuk'],
        [4, $va5, 7, 3, '2026-05-05', 220, 33, 15, 4, null],
        [4, $va1, 1, 2, '2026-05-18', 120, 18, 15, 5, null],
        [4, $va4, 2, 2, '2026-06-03', 280, 42, 15, 5, 'Recommended'],
        [4, $va2, 5, 3, '2026-06-15', 350, 52.5, 15, 5, null],
        [4, $va5, 1, 2, '2026-06-28', 120, 18, 15, 4, null],
        [4, $va1, 3, 3, '2026-07-05', 150, 22.5, 15, 5, 'Quick service'],
        [4, $va3, 4, 2, '2026-07-08', 50, 7.5, 15, null, null],
        // Siti
        [5, $vs1, 1, 2, '2026-01-12', 120, 18, 15, 5, null],
        [5, $vs1, 5, 3, '2026-02-20', 350, 52.5, 15, 5, 'Sangat puas hati'],
        [5, $vs2, 2, 2, '2026-03-10', 280, 42, 15, 4, null],
        [5, $vs3, 3, 3, '2026-04-05', 150, 22.5, 15, 5, null],
        [5, $vs1, 6, 2, '2026-05-14', 180, 27, 15, 4, null],
        [5, $vs2, 1, 3, '2026-06-08', 120, 18, 15, 5, null],
        [5, $vs3, 5, 2, '2026-07-02', 350, 52.5, 15, 5, 'Best bengkel'],
        // Tan
        [6, $vt1, 2, 3, '2026-01-18', 280, 42, 15, 4, null],
        [6, $vt1, 1, 2, '2026-02-25', 120, 18, 15, 5, null],
        [6, $vt2, 5, 3, '2026-03-20', 350, 52.5, 15, 5, null],
        [6, $vt3, 7, 2, '2026-04-18', 220, 33, 15, 4, null],
        [6, $vt1, 3, 3, '2026-05-22', 150, 22.5, 15, 5, null],
        [6, $vt2, 4, 2, '2026-06-10', 50, 7.5, 15, 4, null],
        [6, $vt3, 1, 3, '2026-07-01', 120, 18, 15, 5, null],
    ];

    $insH = $db->prepare('INSERT INTO service_history (user_id,vehicle_id,package_id,mechanic_id,completion_date,gross_payment,commission_amount,commission_percent,rating,feedback,status,labor_total) VALUES (?,?,?,?,?,?,?,?,?,?,\'Settled\',?)');
    foreach ($hist as $h) {
        $insH->execute([$h[0], $h[1], $h[2], $h[3], $h[4], $h[5], $h[6], $h[7], $h[8], $h[9], $h[5]]);
    }

    $today = date('Y-m-d');
    $appts = [
        [4, $va1, 5, null, date('Y-m-d', strtotime('+1 day')), '09:00 AM', 'Approved', null, null, 'pending', null, null],
        [4, $va5, 2, 2, date('Y-m-d', strtotime('+2 day')), '10:30 AM', 'Approved', 295, 'Pads + fluid', 'approved', null, null],
        [4, $va2, 1, 2, date('Y-m-d', strtotime('+3 day')), '02:00 PM', 'Approved', 135, 'Oil synthetic', 'approved', date('Y-m-d H:i:s', strtotime('-1 day')), 'online_banking'],
        [4, $va4, 3, 3, $today, '03:30 PM', 'In Progress', 160, 'Gas + clean', 'approved', date('Y-m-d H:i:s', strtotime('-1 day')), 'cash'],
        [5, $vs1, 1, null, date('Y-m-d', strtotime('+1 day')), '12:00 PM', 'Approved', null, null, 'pending', null, null],
        [5, $vs2, 4, 3, date('Y-m-d', strtotime('+2 day')), '09:00 AM', 'Approved', 55, '4-wheel', 'approved', date('Y-m-d H:i:s', strtotime('-1 day')), 'qr_code'],
        [6, $vt1, 7, 2, date('Y-m-d', strtotime('+1 day')), '05:00 PM', 'Approved', 240, 'Bushing', 'approved', null, null],
        [6, $vt2, 6, null, date('Y-m-d', strtotime('+4 day')), '10:30 AM', 'Approved', null, null, 'pending', null, null],
    ];
    $insA = $db->prepare('INSERT INTO appointments (user_id,vehicle_id,package_id,mechanic_id,appointment_date,time_window,status,quote_amount,quote_notes,quote_status,paid_at,payment_method,booking_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'package\')');
    foreach ($appts as $a) {
        $insA->execute($a);
    }

    // Parts on oil jobs
    try {
        $oil = (int) $db->query("SELECT id FROM parts WHERE sku='OIL-5W30-4L' LIMIT 1")->fetchColumn();
        $filter = (int) $db->query("SELECT id FROM parts WHERE sku='FLT-OIL-UNI' LIMIT 1")->fetchColumn();
        $pad = (int) $db->query("SELECT id FROM parts WHERE sku='BRK-PAD-F' LIMIT 1")->fetchColumn();
        $ap = $db->query("SELECT id FROM appointments WHERE status='Completed' ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_COLUMN);
        $insP = $db->prepare('INSERT INTO job_parts (appointment_id,part_id,qty,unit_price_at_time,total) VALUES (?,?,?,?,?)');
        $priceOil = (float) $db->query("SELECT unit_price FROM parts WHERE id=" . (int) $oil)->fetchColumn();
        $priceF = (float) $db->query("SELECT unit_price FROM parts WHERE id=" . (int) $filter)->fetchColumn();
        $priceB = (float) $db->query("SELECT unit_price FROM parts WHERE id=" . (int) $pad)->fetchColumn();
        $i = 0;
        foreach ($ap as $aid) {
            if ($oil) {
                $insP->execute([(int) $aid, $oil, 1, $priceOil, $priceOil]);
            }
            if ($filter && $i % 2 === 0) {
                $insP->execute([(int) $aid, $filter, 1, $priceF, $priceF]);
            }
            if ($pad && $i % 3 === 0) {
                $insP->execute([(int) $aid, $pad, 1, $priceB, $priceB]);
            }
            $i++;
        }
    } catch (Throwable $e) { /* job_parts may be empty */ }

    $db->prepare("INSERT INTO notifications (user_id,title,message,type,link) VALUES (?,?,?,?,?)")
       ->execute([1, 'Revenue update', 'Demo progression data loaded: more bookings, completions and profit.', 'system', 'admin/dashboard.php']);
    $db->prepare("INSERT INTO notifications (user_id,title,message,type,link) VALUES (?,?,?,?,?)")
       ->execute([4, 'Service history updated', 'Your past services and spending are now available on the dashboard.', 'receipt', 'user/dashboard.php']);
    $db->prepare("INSERT INTO notifications (user_id,title,message,type,link) VALUES (?,?,?,?,?)")
       ->execute([2, 'Commission earned', 'You have multiple completed jobs — check Commission.', 'success', 'mechanic/commission.php']);

    $db->exec("UPDATE workshop_settings SET setting_value='1' WHERE setting_key='demo_progression_seeded'");
}

/**
 * V2 PHP fallback — mirrors the "v2" section of seed_demo_progression.sql.
 * Adds more vehicles/bookings/completions/profit + wider parts usage,
 * gated by its own marker so it only runs once and is safe alongside seedProgressionPhp().
 */
function seedProgressionV2Php(PDO $db): void
{
    $seeded = $db->query("SELECT setting_value FROM workshop_settings WHERE setting_key='demo_progression_seeded_v2'")->fetchColumn();
    if ($seeded === '1') {
        return;
    }
    $db->exec("INSERT IGNORE INTO workshop_settings (setting_key, setting_value) VALUES ('demo_progression_seeded_v2','0')");

    $plates = [
        [4, 'Ahmad Razak bin Ismail', '012-3456789', 'ACH 9101', 'Proton', 'X50 1.5T Premium', 'SUV', 2023, 21000, 11000, '2026-06-01'],
        [4, 'Ahmad Razak bin Ismail', '012-3456789', 'ACH 9102', 'Toyota', 'Corolla Altis', 'Sedan', 2022, 33500, 23000, '2026-05-20'],
        [5, 'Siti Nurhaliza binti Omar', '013-9876543', 'ACH 9201', 'Honda', 'HR-V e:HEV', 'SUV', 2023, 19800, 9800, '2026-06-10'],
        [5, 'Siti Nurhaliza binti Omar', '013-9876543', 'ACH 9202', 'Perodua', 'Ativa AV', 'SUV', 2022, 26400, 16400, '2026-04-28'],
        [6, 'Tan Wei Ming', '016-2233445', 'ACH 9301', 'Nissan', 'X-Trail', 'SUV', 2021, 47000, 37000, '2026-03-30'],
        [6, 'Tan Wei Ming', '016-2233445', 'ACH 9302', 'Mitsubishi', 'Xpander', 'MPV', 2020, 55200, 45200, '2026-02-14'],
    ];
    $insV = $db->prepare('INSERT IGNORE INTO vehicles (user_id,owner_name,contact_no,plate_no,brand,model_variant,vehicle_type,year,current_mileage,last_service_mileage,last_service_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($plates as $p) {
        try {
            $insV->execute($p);
        } catch (Throwable $e) { /* skip dup */ }
    }

    $vid = static function (PDO $db, string $plate, int $fallbackUser) {
        $s = $db->prepare('SELECT id FROM vehicles WHERE plate_no=? LIMIT 1');
        $s->execute([$plate]);
        $id = (int) $s->fetchColumn();
        if ($id) {
            return $id;
        }
        $s = $db->prepare('SELECT id FROM vehicles WHERE user_id=? ORDER BY id LIMIT 1');
        $s->execute([$fallbackUser]);
        return (int) $s->fetchColumn();
    };

    $va1 = $vid($db, 'ACH 9101', 4);
    $va2 = $vid($db, 'ACH 9102', 4) ?: $va1;
    $vs1 = $vid($db, 'ACH 9201', 5);
    $vs2 = $vid($db, 'ACH 9202', 5) ?: $vs1;
    $vt1 = $vid($db, 'ACH 9301', 6);
    $vt2 = $vid($db, 'ACH 9302', 6) ?: $vt1;

    $hist = [
        [4, $va1, 5, 2, '2026-01-15', 350, 52.5, 15, 5, 'Top notch full service'],
        [4, $va2, 1, 3, '2026-02-08', 120, 18, 15, 5, null],
        [4, $va1, 6, 2, '2026-03-01', 180, 27, 15, 4, null],
        [4, $va2, 2, 3, '2026-03-25', 280, 42, 15, 5, 'Brakes feel new'],
        [4, $va1, 3, 2, '2026-04-14', 150, 22.5, 15, 5, null],
        [4, $va2, 7, 3, '2026-05-02', 220, 33, 15, 4, null],
        [4, $va1, 1, 2, '2026-05-27', 120, 18, 15, 5, 'Always reliable'],
        [4, $va2, 5, 3, '2026-06-19', 350, 52.5, 15, 5, null],
        [4, $va1, 4, 2, '2026-07-09', 50, 7.5, 15, 4, null],
        [4, $va2, 3, 3, '2026-07-11', 150, 22.5, 15, 5, 'Cepat siap'],
        [5, $vs1, 5, 2, '2026-01-20', 350, 52.5, 15, 5, null],
        [5, $vs2, 2, 3, '2026-02-14', 280, 42, 15, 4, null],
        [5, $vs1, 1, 2, '2026-03-05', 120, 18, 15, 5, 'Best oil change'],
        [5, $vs2, 6, 3, '2026-04-01', 180, 27, 15, 5, null],
        [5, $vs1, 3, 2, '2026-05-09', 150, 22.5, 15, 4, null],
        [5, $vs2, 5, 3, '2026-06-21', 350, 52.5, 15, 5, 'Very satisfied'],
        [5, $vs1, 1, 2, '2026-07-04', 120, 18, 15, 5, null],
        [5, $vs2, 4, 3, '2026-07-10', 50, 7.5, 15, 4, null],
        [6, $vt1, 2, 2, '2026-01-25', 280, 42, 15, 4, null],
        [6, $vt2, 5, 3, '2026-02-19', 350, 52.5, 15, 5, null],
        [6, $vt1, 1, 2, '2026-03-12', 120, 18, 15, 5, 'Puas hati'],
        [6, $vt2, 7, 3, '2026-04-22', 220, 33, 15, 4, null],
        [6, $vt1, 6, 2, '2026-05-16', 180, 27, 15, 5, null],
        [6, $vt2, 3, 3, '2026-06-24', 150, 22.5, 15, 5, 'AC sejuk gila'],
        [6, $vt1, 1, 2, '2026-07-06', 120, 18, 15, 4, null],
        [6, $vt2, 4, 3, '2026-07-11', 50, 7.5, 15, 5, null],
    ];
    $insH = $db->prepare('INSERT INTO service_history (user_id,vehicle_id,package_id,mechanic_id,completion_date,gross_payment,commission_amount,commission_percent,rating,feedback,status,labor_total) VALUES (?,?,?,?,?,?,?,?,?,?,\'Settled\',?)');
    foreach ($hist as $h) {
        $insH->execute([$h[0], $h[1], $h[2], $h[3], $h[4], $h[5], $h[6], $h[7], $h[8], $h[9], $h[5]]);
    }

    $today = date('Y-m-d');
    $appts = [
        // Completed (drives parts usage + this-month profit)
        [4, $va1, 5, 2, '2026-07-09', '09:00 AM', 'Completed', 350, 'Full pkg', 'approved', '2026-07-08 09:00:00', 'online_banking'],
        [5, $vs1, 1, 2, '2026-07-04', '10:00 AM', 'Completed', 120, 'Oil + filter', 'approved', '2026-07-03 08:30:00', 'cash'],
        [6, $vt1, 2, 3, '2026-07-06', '01:00 PM', 'Completed', 280, 'Pads front', 'approved', '2026-07-05 11:00:00', 'qr_code'],
        [4, $va2, 3, 3, '2026-07-11', '03:00 PM', 'Completed', 150, 'Gas top-up', 'approved', '2026-07-10 09:00:00', 'online_banking'],
        // Active pipeline
        [4, $va1, 6, null, date('Y-m-d', strtotime('+1 day')), '11:00 AM', 'Approved', null, null, 'pending', null, null],
        [5, $vs2, 7, 3, date('Y-m-d', strtotime('+2 day')), '01:30 PM', 'Approved', 235, 'Bushing + linkage', 'approved', null, null],
        [6, $vt2, 4, null, date('Y-m-d', strtotime('+3 day')), '09:30 AM', 'Approved', null, null, 'pending', null, null],
        [4, $va2, 2, 2, $today, '04:00 PM', 'In Progress', 280, 'Pads all round', 'approved', date('Y-m-d H:i:s', strtotime('-1 day')), 'cash'],
    ];
    $insA = $db->prepare('INSERT INTO appointments (user_id,vehicle_id,package_id,mechanic_id,appointment_date,time_window,status,quote_amount,quote_notes,quote_status,paid_at,payment_method,booking_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'package\')');
    foreach ($appts as $a) {
        $insA->execute($a);
    }

    // Wider parts usage (air filter, spark plug, coolant, wiper, battery)
    try {
        $skus = ['FLT-AIR-UNI' => 1, 'SPK-IRD' => 2, 'CLN-1L' => 1, 'WIP-22' => 2, 'BAT-60' => 1];
        $ap = $db->query("SELECT id FROM appointments WHERE status='Completed' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
        $insP = $db->prepare('INSERT INTO job_parts (appointment_id,part_id,qty,unit_price_at_time,total) VALUES (?,?,?,?,?)');
        $i = 0;
        foreach ($skus as $sku => $qty) {
            $partId = (int) $db->query("SELECT id FROM parts WHERE sku=" . $db->quote($sku))->fetchColumn();
            $price = (float) $db->query("SELECT unit_price FROM parts WHERE id=" . (int) $partId)->fetchColumn();
            if ($partId && !empty($ap[$i])) {
                $insP->execute([(int) $ap[$i], $partId, $qty, $price, round($price * $qty, 2)]);
            }
            $i++;
        }
    } catch (Throwable $e) { /* job_parts may be missing */ }

    // Stock deductions (also pushes Battery 60Ah into low-stock territory)
    $db->exec("UPDATE parts SET stock_qty = GREATEST(0, stock_qty - 4) WHERE sku='FLT-AIR-UNI'");
    $db->exec("UPDATE parts SET stock_qty = GREATEST(0, stock_qty - 6) WHERE sku='SPK-IRD'");
    $db->exec("UPDATE parts SET stock_qty = GREATEST(0, stock_qty - 3) WHERE sku='CLN-1L'");
    $db->exec("UPDATE parts SET stock_qty = GREATEST(0, stock_qty - 2) WHERE sku='WIP-22'");
    $db->exec("UPDATE parts SET stock_qty = 2 WHERE sku='BAT-60'");
    $db->exec("UPDATE parts SET stock_qty = stock_qty + 20 WHERE sku='OIL-5W30-4L'");

    $db->prepare("INSERT INTO notifications (user_id,title,message,type,link) VALUES (?,?,?,?,?)")
       ->execute([1, 'Low stock alert', 'Battery 60Ah is running low (2 left). Reorder soon.', 'alert', 'admin/inventory.php']);
    $db->prepare("INSERT INTO notifications (user_id,title,message,type,link) VALUES (?,?,?,?,?)")
       ->execute([1, 'Revenue update', 'More demo bookings and completions loaded — profit trend updated.', 'system', 'admin/dashboard.php']);

    $db->exec("UPDATE workshop_settings SET setting_value='1' WHERE setting_key='demo_progression_seeded_v2'");
}
