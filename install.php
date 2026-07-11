<?php
/**
 * AutoCare Hub — One-time installer
 * Creates database, tables, seed data, and demo accounts.
 * Delete or rename this file after successful installation.
 */
require_once __DIR__ . '/config/database.php';

$messages = [];
$errors = [];

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . DB_NAME . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE ' . DB_NAME);

    $schemaPath = __DIR__ . '/schema.sql';
    if (!is_readable($schemaPath)) {
        $schemaPath = __DIR__ . '/database/schema.sql';
    }
    if (!is_readable($schemaPath)) {
        throw new PDOException('Schema file not found.');
    }

    $sql = file_get_contents($schemaPath);
    $sql = preg_replace('/^\s*CREATE\s+DATABASE[^;]+;/im', '', $sql);
    $sql = preg_replace('/^\s*USE\s+\w+\s*;/im', '', $sql);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    $schemaImported = importSchemaSql($pdo, $sql);
    if (!$schemaImported) {
        throw new PDOException('Schema import failed.');
    }

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    if (!$tableCheck) {
        throw new PDOException('Schema import failed — users table was not created.');
    }
    $messages[] = 'Database schema created.';

    $pdo->exec('USE ' . DB_NAME);

    $paidCol = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'paid_at'")->fetch();
    if (!$paidCol) {
        $pdo->exec('ALTER TABLE appointments ADD COLUMN paid_at DATETIME DEFAULT NULL AFTER notes');
        $messages[] = 'Added payment tracking to appointments.';
    }
    $methodCol = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'payment_method'")->fetch();
    if (!$methodCol) {
        $pdo->exec('ALTER TABLE appointments ADD COLUMN payment_method VARCHAR(30) DEFAULT NULL AFTER paid_at');
        $messages[] = 'Added payment method to appointments.';
    }

    // Check if already seeded
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        seedData($pdo);
        $messages[] = 'Demo data and accounts seeded successfully.';
    } else {
        $messages[] = 'Database already contains data — seed skipped.';
    }
} catch (PDOException $e) {
    $errors[] = 'Database error: ' . $e->getMessage();
}

function importSchemaSql(PDO $pdo, string $sql): bool
{
    $candidates = [];
    if (PHP_OS_FAMILY === 'Windows' && is_executable('C:/xampp/mysql/bin/mysql.exe')) {
        $candidates[] = 'C:/xampp/mysql/bin/mysql.exe';
    }
    $which = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where mysql 2>nul' : 'which mysql 2>/dev/null'));
    if ($which !== '') {
        foreach (preg_split('/\r\n|\r|\n/', $which) as $line) {
            $line = trim($line);
            if ($line !== '' && is_executable($line)) {
                $candidates[] = $line;
            }
        }
    }

    foreach (array_unique($candidates) as $mysqlBin) {
        $args = [$mysqlBin, '-u' . DB_USER];
        if (DB_PASS !== '') {
            $args[] = '-p' . DB_PASS;
        }
        $args[] = DB_NAME;
        $proc = @proc_open($args, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            continue;
        }
        fwrite($pipes[0], "USE " . DB_NAME . ";\n" . $sql);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code === 0) {
            return true;
        }
    }

    $buffer = '';
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $buffer .= $ch;
        if ($ch === ';') {
            $stmt = trim($buffer);
            $buffer = '';
            if ($stmt === '' || $stmt === ';') {
                continue;
            }
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (!str_contains($msg, 'already exists') && !str_contains($msg, 'Duplicate')) {
                    return false;
                }
            }
        }
    }
    return true;
}

function seedData(PDO $pdo): void
{
    $pass = fn(string $p) => password_hash($p, PASSWORD_DEFAULT);

    // Users
    $users = [
        ['admin',    'admin@autocarehub.my',    $pass('Admin@123'),    'admin',    'System Administrator', '019-0000001'],
        ['mechanic1','mechanic@autocarehub.my', $pass('Mechanic@123'), 'mechanic', 'Hafiz bin Abdullah',  '012-1111222'],
        ['mechanic2','mechanic2@autocarehub.my', $pass('Mechanic@123'),'mechanic', 'Kumar a/l Rajan',      '013-3333444'],
        ['ahmad',    'ahmad@email.com',         $pass('Customer@123'), 'customer', 'Ahmad Razak bin Ismail', '012-3456789'],
        ['siti',     'siti@email.com',          $pass('Customer@123'), 'customer', 'Siti Nurhaliza binti Omar','013-9876543'],
        ['tanwm',    'tan@email.com',           $pass('Customer@123'), 'customer', 'Tan Wei Ming',           '016-2233445'],
    ];
    $stmt = $pdo->prepare('INSERT INTO users (username,email,password_hash,role,full_name,contact_no) VALUES (?,?,?,?,?,?)');
    foreach ($users as $u) $stmt->execute($u);

    // Service packages
    $packages = [
        ['Tukar Minyak Enjin',       'Full engine oil & filter change',           'Maintenance', 120,  45],
        ['Brake Service Overhaul',   'Brake pad replacement & fluid check',       'Safety',      280,  120],
        ['Aircond Service Tuning',   'Gas refill, coil clean, blower check',      'Electrical',  150,  90],
        ['Alignment Tayar',          '4-wheel alignment & balancing',             'Tyres',        50,  30],
        ['Full Service Package',     'Comprehensive 10-point inspection',         'Maintenance', 350,  180],
        ['Battery Replacement',      'New battery install & terminal clean',      'Electrical',  180,  30],
        ['Suspension Check & Repair','Shock absorber & linkage inspection',       'Mechanical',  220,  90],
    ];
    $stmt = $pdo->prepare('INSERT INTO service_packages (name,description,category,price,duration_mins) VALUES (?,?,?,?,?)');
    foreach ($packages as $p) $stmt->execute($p);

    // Vehicles (with type & mileage)
    $vehicles = [
        [4,'Ahmad Razak bin Ismail','012-3456789','WXY 1234','Proton','Saga Premium 1.3','Sedan',45200,35000,'2026-01-05'],
        [5,'Siti Nurhaliza binti Omar','013-9876543','BJK 5678','Perodua','Myvi 1.5 AV','Hatchback',32100,22000,'2026-02-12'],
        [6,'Tan Wei Ming','016-2233445','PKN 9012','Honda','City VTEC 1.5','Sedan',61000,51000,'2025-12-18'],
        [4,'Ahmad Razak bin Ismail','012-3456789','JHR 3456','Toyota','Vios 1.5G','Sedan',28000,null,null],
        [5,'Siti Nurhaliza binti Omar','013-9876543','SEL 7890','Proton','X50 1.5T Flagship','SUV',18500,8500,'2026-03-28'],
        [6,'Lee Kok Wai','011-6677889','PEN 2468','Perodua','Bezza 1.3 X','Sedan',42000,null,null],
    ];
    $stmt = $pdo->prepare('INSERT INTO vehicles (user_id,owner_name,contact_no,plate_no,brand,model_variant,vehicle_type,current_mileage,last_service_mileage,last_service_date) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($vehicles as $v) $stmt->execute($v);

    // Appointments
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $appts = [
        [4,1,1,2,$today,'09:00 AM','Approved',null,null],
        [5,2,3,2,$today,'10:30 AM','Approved',$today . ' 08:15:00','online_banking'],
        [6,3,2,2,$today,'02:00 PM','In Progress',$today . ' 09:00:00','qr_code'],
        [4,4,5,2,$tomorrow,'09:00 AM','Approved',null,null],
        [5,5,4,3,$tomorrow,'11:00 AM','Approved',$tomorrow . ' 10:00:00','cash'],
        [6,6,6,3,$tomorrow,'03:00 PM','Approved',null,null],
    ];
    $stmt = $pdo->prepare('INSERT INTO appointments (user_id,vehicle_id,package_id,mechanic_id,appointment_date,time_window,status,paid_at,payment_method) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($appts as $a) $stmt->execute($a);

    // Service history (June 2026) with commission (15%)
    $history = [
        [4,1,1,2,'2026-06-05',120,18.00,15.00,5,null],
        [5,2,3,2,'2026-06-12',150,22.50,15.00,4,'Good service'],
        [6,3,2,3,'2026-06-18',280,42.00,15.00,null,null],
        [4,4,5,2,'2026-06-22',350,52.50,15.00,null,null],
        [5,5,4,3,'2026-06-28',50,7.50,15.00,5,'Fast and clean'],
    ];
    $stmt = $pdo->prepare('INSERT INTO service_history (user_id,vehicle_id,package_id,mechanic_id,completion_date,gross_payment,commission_amount,commission_percent,rating,feedback) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($history as $h) $stmt->execute($h);

    // Notifications
    $notifs = [
        [1,'System Online','AutoCare Hub PHP system initialized successfully.','system',null],
        [4,'Booking Confirmed','Your booking is confirmed. Pay from My Appointments to secure your slot.','appointment','user/appointments.php'],
        [5,'Payment Received','Your payment for Brake Service is confirmed. Receipt is ready.','receipt','user/receipt.php'],
        [2,'New Job Available','BJK 5678 is ready for service today.','appointment','mechanic/appointments.php'],
        [6,'Work Started on Your Car','A mechanic has started work on your vehicle.','appointment','user/appointments.php'],
    ];
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id,title,message,type,link) VALUES (?,?,?,?,?)');
    foreach ($notifs as $n) $stmt->execute($n);

    // Messages
    $msgs = [
        [4,2,'Question about brake service','Hi, can I bring my car earlier for the brake service tomorrow?'],
        [2,4,'Re: Question about brake service','Yes, you can come in at 08:30 AM. We have a bay available.'],
        [5,1,'Feedback on service','Excellent aircond service last week. Thank you!'],
    ];
    $stmt = $pdo->prepare('INSERT INTO messages (sender_id,receiver_id,subject,body) VALUES (?,?,?,?)');
    foreach ($msgs as $m) $stmt->execute($m);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AutoCare Hub — Installer</title>
<script>(function(){try{var t=localStorage.getItem('ach-theme');if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t);document.documentElement.style.colorScheme=t;}}catch(e){}})();</script>
<link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="installer-page"><div class="installer-box">
<button id="theme-toggle" type="button" class="theme-toggle-btn installer-theme-toggle" aria-label="Toggle dark or light mode" title="Toggle theme">
  <svg id="theme-icon-sun" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:none"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
  <svg id="theme-icon-moon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
</button>
<h1>AutoCare Hub Installer</h1>
<?php foreach ($messages as $m): ?><p class="ok">✓ <?= htmlspecialchars($m) ?></p><?php endforeach; ?>
<?php foreach ($errors as $e): ?><p class="err">✕ <?= htmlspecialchars($e) ?></p><?php endforeach; ?>
<?php if (empty($errors)): ?>
<p><strong>Demo accounts:</strong></p>
<ul class="installer-demo-list">
<li>Admin: <code>admin</code> / <code>Admin@123</code></li>
<li>Mechanic: <code>mechanic1</code> or <code>mechanic2</code> / <code>Mechanic@123</code></li>
<li>Customer: <code>ahmad</code>, <code>siti</code>, or <code>tanwm</code> / <code>Customer@123</code></li>
</ul>
<p><a href="index.php">→ Go to AutoCare Hub</a></p>
<p class="installer-note">Delete install.php after setup for security.</p>
<?php endif; ?>
</div>
<script src="assets/js/theme.js"></script>
<script src="assets/js/app.js"></script>
</body></html>