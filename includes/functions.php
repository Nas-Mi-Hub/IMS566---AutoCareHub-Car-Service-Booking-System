<?php
require_once __DIR__ . '/../config/database.php';

// Service layer (v2) — safe to load early; classes call helpers defined below
spl_autoload_register(function (string $class): void {
    $map = [
        'AppointmentService'  => __DIR__ . '/services/AppointmentService.php',
        'PaymentService'      => __DIR__ . '/services/PaymentService.php',
        'InventoryService'    => __DIR__ . '/services/InventoryService.php',
        'NotificationService' => __DIR__ . '/services/NotificationService.php',
    ];
    if (isset($map[$class]) && is_file($map[$class])) {
        require_once $map[$class];
    }
});

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/* ── CSRF Protection ─────────────────────────────────────────── */

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/**
 * Validate CSRF on POST. Redirects with flash on failure.
 */
function validateCsrf(?string $redirectTo = null): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $valid = is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
    if ($valid) {
        return;
    }
    flash('error', 'Security check failed. Please try again. / Semakan keselamatan gagal. Sila cuba lagi.');
    $fallback = $redirectTo
        ?? ($_SERVER['HTTP_REFERER'] ?? baseUrl('index.php'));
    redirect($fallback);
}

function statusBadge(string $status, ?array $appt = null): string
{
    $label = $status;
    if ($appt && $status === 'Approved') {
        $label = appointmentDisplayStatus($appt);
    }
    $map = [
        'Pending'           => 'badge-pending',
        'Approved'          => 'badge-approved',
        'Awaiting Payment'  => 'badge-pending',
        'Confirmed'         => 'badge-approved',
        'In Progress'       => 'badge-progress',
        'Completed'         => 'badge-completed',
        'Cancelled'         => 'badge-cancelled',
        'Settled'           => 'badge-settled',
    ];
    $cls = $map[$label] ?? $map[$status] ?? 'badge-settled';
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

function formatRM(float|int|string $amount): string
{
    return 'RM ' . number_format((float) $amount, 2);
}

function todayDate(): string
{
    return date('Y-m-d');
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function vehicleLabel(array $v): string
{
    $type = !empty($v['vehicle_type']) ? ' [' . $v['vehicle_type'] . ']' : '';
    $year = !empty($v['year']) ? ' (' . $v['year'] . ')' : '';
    return $v['plate_no'] . ' – ' . $v['owner_name'] . ' – ' . $v['brand'] . ' ' . $v['model_variant'] . $year . $type;
}

function vehicleTypes(): array
{
    return ['Sedan', 'SUV', 'MPV', 'Hatchback', 'Pickup', 'Coupe', 'Van', 'Others'];
}

function serviceTimeSlots(): array
{
    return ['09:00 AM', '10:30 AM', '12:00 PM', '02:00 PM', '03:30 PM', '05:00 PM'];
}

/**
 * Schema migrations — runs once per PHP process AND skips entirely when
 * workshop_settings.schema_version already matches SCHEMA_VERSION.
 * (Previously re-checked 20+ columns + CREATE TABLE on every page load.)
 */
function ensureSchemaUpdates(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $db = getDB();
    } catch (Throwable $e) {
        return; // MySQL down — fail soft so pages can show an error instead of hanging forever
    }

    $target = defined('SCHEMA_VERSION') ? SCHEMA_VERSION : '2.1.1';

    // File fast-path (no DB) — survives concurrent page loads
    $flagFile = __DIR__ . '/../storage/.schema_' . preg_replace('/[^0-9.]/', '', $target);
    if (is_file($flagFile)) {
        return;
    }

    // Fast path: one tiny SELECT; skip all migrations when already up to date
    try {
        $stmt = $db->prepare('SELECT setting_value FROM workshop_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute(['schema_version']);
        $current = $stmt->fetchColumn();
        if ($current !== false && (string) $current === (string) $target) {
            @file_put_contents($flagFile, date('c'));
            return;
        }
    } catch (Throwable $e) {
        // workshop_settings may not exist yet — continue into full migrate
    }

    // Prevent stampede: only one process runs heavy ALTER/CREATE at a time
    $lockFile = __DIR__ . '/../storage/.schema_migrate.lock';
    $lockFp = @fopen($lockFile, 'c+');
    if ($lockFp && !flock($lockFp, LOCK_EX | LOCK_NB)) {
        // Another request is migrating — do not queue behind it
        if ($lockFp) {
            fclose($lockFp);
        }
        return;
    }

    // ── appointments columns ──
    $apptCols = [
        'paid_at'          => "ALTER TABLE appointments ADD COLUMN paid_at DATETIME DEFAULT NULL AFTER notes",
        'payment_method'   => "ALTER TABLE appointments ADD COLUMN payment_method VARCHAR(30) DEFAULT NULL AFTER paid_at",
        'payment_proof'    => "ALTER TABLE appointments ADD COLUMN payment_proof VARCHAR(255) DEFAULT NULL AFTER payment_method",
        'dropoff_mileage'  => "ALTER TABLE appointments ADD COLUMN dropoff_mileage INT DEFAULT NULL AFTER notes",
        'booking_type'     => "ALTER TABLE appointments ADD COLUMN booking_type ENUM('package','inspection') NOT NULL DEFAULT 'package' AFTER package_id",
        'quote_amount'     => "ALTER TABLE appointments ADD COLUMN quote_amount DECIMAL(10,2) DEFAULT NULL",
        'quote_notes'      => "ALTER TABLE appointments ADD COLUMN quote_notes TEXT",
        'quote_status'     => "ALTER TABLE appointments ADD COLUMN quote_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none'",
        'job_notes'        => "ALTER TABLE appointments ADD COLUMN job_notes TEXT",
        'inspection_photos'=> "ALTER TABLE appointments ADD COLUMN inspection_photos JSON DEFAULT NULL",
        'labor_hours'      => "ALTER TABLE appointments ADD COLUMN labor_hours DECIMAL(5,2) DEFAULT NULL",
        'refund_status'    => "ALTER TABLE appointments ADD COLUMN refund_status ENUM('none','requested','approved','rejected','paid') NOT NULL DEFAULT 'none'",
        'refund_reason'    => "ALTER TABLE appointments ADD COLUMN refund_reason TEXT DEFAULT NULL",
        'refund_requested_at' => "ALTER TABLE appointments ADD COLUMN refund_requested_at DATETIME DEFAULT NULL",
        'next_service_notes'  => "ALTER TABLE appointments ADD COLUMN next_service_notes TEXT DEFAULT NULL",
    ];
    foreach ($apptCols as $col => $sql) {
        try {
            $exists = $db->query("SHOW COLUMNS FROM appointments LIKE " . $db->quote($col))->fetch();
            if (!$exists) {
                $db->exec($sql);
            }
        } catch (Throwable $e) { /* ignore */ }
    }

    // vehicles: year
    try {
        $exists = $db->query("SHOW COLUMNS FROM vehicles LIKE 'year'")->fetch();
        if (!$exists) {
            $db->exec("ALTER TABLE vehicles ADD COLUMN year SMALLINT DEFAULT NULL AFTER model_variant");
        }
    } catch (Throwable $e) { /* ignore */ }

    // One-time status cleanup only during migrate
    try {
        $db->exec("UPDATE appointments SET status='Approved' WHERE status='Pending'");
    } catch (Throwable $e) { /* ignore */ }

    // ── vehicles columns ──
    $vehCols = [
        'vehicle_type'         => "ALTER TABLE vehicles ADD COLUMN vehicle_type VARCHAR(30) NOT NULL DEFAULT 'Sedan' AFTER model_variant",
        'current_mileage'      => "ALTER TABLE vehicles ADD COLUMN current_mileage INT DEFAULT NULL AFTER vehicle_type",
        'last_service_mileage' => "ALTER TABLE vehicles ADD COLUMN last_service_mileage INT DEFAULT NULL AFTER current_mileage",
        'last_service_date'    => "ALTER TABLE vehicles ADD COLUMN last_service_date DATE DEFAULT NULL AFTER last_service_mileage",
    ];
    foreach ($vehCols as $col => $sql) {
        try {
            $exists = $db->query("SHOW COLUMNS FROM vehicles LIKE " . $db->quote($col))->fetch();
            if (!$exists) {
                $db->exec($sql);
            }
        } catch (Throwable $e) { /* ignore */ }
    }

    // ── service_history columns ──
    $histCols = [
        'commission_amount'  => "ALTER TABLE service_history ADD COLUMN commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER gross_payment",
        'commission_percent' => "ALTER TABLE service_history ADD COLUMN commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER commission_amount",
        'rating'             => "ALTER TABLE service_history ADD COLUMN rating TINYINT DEFAULT NULL AFTER status",
        'feedback'           => "ALTER TABLE service_history ADD COLUMN feedback TEXT DEFAULT NULL AFTER rating",
        'rated_at'           => "ALTER TABLE service_history ADD COLUMN rated_at DATETIME DEFAULT NULL AFTER feedback",
        'job_notes'          => "ALTER TABLE service_history ADD COLUMN job_notes TEXT",
        'parts_used'         => "ALTER TABLE service_history ADD COLUMN parts_used JSON DEFAULT NULL",
        'parts_total'        => "ALTER TABLE service_history ADD COLUMN parts_total DECIMAL(10,2) NOT NULL DEFAULT 0",
        'labor_total'        => "ALTER TABLE service_history ADD COLUMN labor_total DECIMAL(10,2) NOT NULL DEFAULT 0",
        'sst_amount'         => "ALTER TABLE service_history ADD COLUMN sst_amount DECIMAL(10,2) NOT NULL DEFAULT 0",
    ];
    foreach ($histCols as $col => $sql) {
        try {
            $exists = $db->query("SHOW COLUMNS FROM service_history LIKE " . $db->quote($col))->fetch();
            if (!$exists) {
                $db->exec($sql);
            }
        } catch (Throwable $e) { /* ignore */ }
    }

    // ── notifications: expand type ENUM to include alert ──
    try {
        $typeCol = $db->query("SHOW COLUMNS FROM notifications LIKE 'type'")->fetch();
        if ($typeCol && stripos($typeCol['Type'] ?? '', 'alert') === false) {
            $db->exec("ALTER TABLE notifications MODIFY COLUMN type ENUM('info','success','warning','appointment','receipt','system','alert') NOT NULL DEFAULT 'info'");
        }
    } catch (Throwable $e) {
        // ignore if already migrated
    }

    // ── workshop settings ──
    $settings = [
        'workshop_bank_name'            => 'Maybank',
        'workshop_bank_account'         => '514123456789',
        'workshop_bank_holder'          => 'AutoCare Hub Sdn Bhd',
        'mechanic_commission_percent'   => '15',
        'max_bookings_per_slot'         => '2',
        'service_interval_km'           => '10000',
        'service_interval_months'       => '6',
        'sst_enabled'                   => '0',
        'sst_percent'                   => '6',
        'sms_enabled'                   => '0',
        'mail_driver'                   => 'mail',
    ];
    try {
        $ins = $db->prepare('INSERT IGNORE INTO workshop_settings (setting_key, setting_value) VALUES (?, ?)');
        foreach ($settings as $k => $v) {
            $ins->execute([$k, $v]);
        }
    } catch (Throwable $e) { /* ignore */ }

    // ── Parts & inventory tables (no FK on first create if parent missing — use plain indexes) ──
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS parts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            sku VARCHAR(60) DEFAULT NULL UNIQUE,
            category VARCHAR(60) NOT NULL DEFAULT 'General',
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            cost_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            stock_qty INT NOT NULL DEFAULT 0,
            min_stock INT NOT NULL DEFAULT 5,
            supplier VARCHAR(120) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        // Repair corrupt job_parts (MySQL 1932: exists in dictionary but not in engine)
        try {
            $db->query('SELECT 1 FROM job_parts LIMIT 1');
        } catch (Throwable $e) {
            try { $db->exec('DROP TABLE IF EXISTS job_parts'); } catch (Throwable $e2) { /* ignore */ }
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

        $db->exec("CREATE TABLE IF NOT EXISTS stock_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            part_id INT NOT NULL,
            movement_type ENUM('in','out','adjustment') NOT NULL DEFAULT 'adjustment',
            qty INT NOT NULL,
            balance_after INT NOT NULL DEFAULT 0,
            reason VARCHAR(255) DEFAULT NULL,
            reference_id INT DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_stock_part (part_id)
        ) ENGINE=InnoDB");

        $db->exec("CREATE TABLE IF NOT EXISTS blocked_slots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            block_date DATE NOT NULL,
            time_window VARCHAR(20) DEFAULT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            mechanic_id INT DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_blocked_date (block_date)
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { /* ignore */ }

    // Ensure a Diagnostic Inspection package exists
    try {
        $insp = $db->query("SELECT id FROM service_packages WHERE name LIKE '%Inspection%' OR name LIKE '%Diagnos%' LIMIT 1")->fetchColumn();
        if (!$insp) {
            $db->prepare("INSERT INTO service_packages (name, description, category, price, duration_mins, is_active) VALUES (?,?,?,?,?,1)")
               ->execute([
                   'Diagnostic Inspection',
                   'Full visual & diagnostic check. Quote provided after inspection.',
                   'Inspection',
                   50.00,
                   45,
               ]);
        }
    } catch (Throwable $e) { /* ignore */ }

    // Seed sample parts if empty
    try {
        $partCount = (int) $db->query('SELECT COUNT(*) FROM parts')->fetchColumn();
        if ($partCount === 0) {
            $seedParts = [
                ['Engine Oil 5W-30 (4L)', 'OIL-5W30-4L', 'Lubricants', 95.00, 65.00, 40, 10, 'Petronas'],
                ['Oil Filter', 'FLT-OIL-UNI', 'Filters', 25.00, 12.00, 50, 10, 'Bosch'],
                ['Air Filter', 'FLT-AIR-UNI', 'Filters', 35.00, 18.00, 30, 8, 'Bosch'],
                ['Brake Pad Set (Front)', 'BRK-PAD-F', 'Brakes', 180.00, 110.00, 15, 4, 'Brembo'],
                ['Spark Plug (Iridium)', 'SPK-IRD', 'Ignition', 45.00, 28.00, 40, 8, 'NGK'],
                ['Coolant 1L', 'CLN-1L', 'Fluids', 22.00, 12.00, 25, 6, 'Prestone'],
                ['Wiper Blade 22"', 'WIP-22', 'Accessories', 30.00, 15.00, 20, 5, 'Bosch'],
                ['Battery 60Ah', 'BAT-60', 'Electrical', 320.00, 240.00, 8, 2, 'Century'],
            ];
            $insP = $db->prepare('INSERT INTO parts (name,sku,category,unit_price,cost_price,stock_qty,min_stock,supplier) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($seedParts as $p) {
                $insP->execute($p);
            }
        }
    } catch (Throwable $e) { /* ignore */ }

    // ── uploads dirs (once) ──
    $dirs = [
        __DIR__ . '/../uploads/payment_proofs',
        __DIR__ . '/../uploads/inspection_photos',
        __DIR__ . '/../uploads/job_photos',
        __DIR__ . '/../storage/logs',
    ];
    $htBody = "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar)$\">\nDeny from all\n</FilesMatch>\n";
    foreach ($dirs as $uploadDir) {
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        $htaccess = $uploadDir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, $htBody);
        }
    }

    // Mark schema as current so future page loads skip all of the above
    try {
        $db->prepare('INSERT INTO workshop_settings (setting_key, setting_value) VALUES (?, ?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
           ->execute(['schema_version', $target]);
    } catch (Throwable $e) { /* ignore */ }

    @file_put_contents($flagFile, date('c'));
    if (!empty($lockFp) && is_resource($lockFp)) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}

function paymentMethods(): array
{
    return [
        'online_banking' => 'Online Banking',
        'cash'           => 'Cash',
        'qr_code'        => 'QR Code',
    ];
}

function paymentMethodLabel(?string $method): string
{
    return paymentMethods()[$method] ?? '—';
}

function appointmentIsPaid(array $appt): bool
{
    return !empty($appt['paid_at']);
}

function appointmentDisplayStatus(array $appt): string
{
    $status = $appt['status'] ?? '';
    $quote = $appt['quote_status'] ?? 'none';
    $refund = $appt['refund_status'] ?? 'none';

    if ($status === 'Cancelled') {
        if (in_array($refund, ['requested', 'approved', 'paid'], true)) {
            return match ($refund) {
                'requested' => 'Refund Requested',
                'approved'  => 'Refund Approved',
                'paid'      => 'Refunded',
                default     => 'Cancelled',
            };
        }
        return 'Cancelled';
    }
    if ($status === 'Approved' && !appointmentIsPaid($appt)) {
        if (in_array($quote, ['pending', 'none'], true) || empty($appt['quote_amount'])) {
            return 'Awaiting Quote';
        }
        if ($quote === 'approved' || !empty($appt['quote_amount'])) {
            return 'Quote Ready — Pay';
        }
        return 'Awaiting Payment';
    }
    if ($status === 'Approved' && appointmentIsPaid($appt)) {
        return 'Confirmed';
    }
    return $status;
}

/** Normalise Malaysian phone for tel: / WhatsApp (returns digits with country code, no +). */
function normalizeMyPhone(?string $phone): string
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($digits, '60')) {
        return $digits;
    }
    if (str_starts_with($digits, '0')) {
        return '60' . substr($digits, 1);
    }
    if (str_starts_with($digits, '1') && strlen($digits) >= 9) {
        return '60' . $digits;
    }
    return $digits;
}

function phoneTelHref(?string $phone): string
{
    $n = normalizeMyPhone($phone);
    return $n !== '' ? 'tel:+' . $n : '';
}

function phoneWhatsAppHref(?string $phone, string $prefill = ''): string
{
    $n = normalizeMyPhone($phone);
    if ($n === '') {
        return '';
    }
    $url = 'https://wa.me/' . $n;
    if ($prefill !== '') {
        $url .= '?text=' . rawurlencode($prefill);
    }
    return $url;
}

/** HTML buttons: Call + WhatsApp for a contact number. */
function contactPhoneButtons(?string $phone, string $prefill = '', string $size = 'sm'): string
{
    $tel = phoneTelHref($phone);
    $wa = phoneWhatsAppHref($phone, $prefill);
    if ($tel === '' && $wa === '') {
        return '<span class="text-xs app-muted contact-no-phone">No phone / Tiada telefon</span>';
    }
    $sizeClass = $size === 'sm' ? ' contact-btn-sm' : '';
    $html = '<span class="contact-actions inline-flex flex-wrap gap-1 items-center">';
    if ($tel !== '') {
        $html .= '<a class="contact-btn contact-btn-call' . $sizeClass . '" href="' . e($tel) . '" title="Call ' . e((string) $phone) . '">📞 Call</a>';
    }
    if ($wa !== '') {
        $html .= '<a class="contact-btn contact-btn-wa' . $sizeClass . '" href="' . e($wa) . '" target="_blank" rel="noopener" title="WhatsApp ' . e((string) $phone) . '">WhatsApp</a>';
    }
    $html .= '<span class="contact-phone-label text-xs app-muted">' . e((string) $phone) . '</span></span>';
    return $html;
}

/**
 * Visual workflow progress for a booking (5 steps).
 * Booked → Quote → Paid → In Progress → Done
 */
function workflowProgressHtml(array $appt, string $compact = 'full'): string
{
    $status = $appt['status'] ?? '';
    $hasQuote = !empty($appt['quote_amount']) && (float) $appt['quote_amount'] > 0;
    $paid = appointmentIsPaid($appt);
    $cancelled = $status === 'Cancelled';

    $steps = [
        ['key' => 'booked', 'label' => 'Booked', 'ms' => 'Tempah'],
        ['key' => 'quote',  'label' => 'Quote',  'ms' => 'Sebut harga'],
        ['key' => 'paid',   'label' => 'Paid',   'ms' => 'Bayar'],
        ['key' => 'work',   'label' => 'Working','ms' => 'Kerja'],
        ['key' => 'done',   'label' => 'Done',   'ms' => 'Selesai'],
    ];

    // Active index 0–4
    $active = 0;
    if ($cancelled) {
        $active = -1;
    } elseif ($status === 'Completed') {
        $active = 4;
    } elseif ($status === 'In Progress') {
        $active = 3;
    } elseif ($paid) {
        $active = 2;
    } elseif ($hasQuote) {
        $active = 1;
    } else {
        $active = 0;
    }

    $html = '<div class="workflow-progress' . ($compact === 'compact' ? ' workflow-compact' : '') . ($cancelled ? ' is-cancelled' : '') . '" role="list" aria-label="Booking progress">';
    if ($cancelled) {
        $html .= '<span class="workflow-cancelled-tag">Cancelled</span>';
    }
    foreach ($steps as $i => $s) {
        $cls = 'workflow-step';
        if ($cancelled) {
            $cls .= ' is-muted';
        } elseif ($i < $active) {
            $cls .= ' is-done';
        } elseif ($i === $active) {
            $cls .= ' is-active';
        } else {
            $cls .= ' is-todo';
        }
        $html .= '<div class="' . $cls . '" role="listitem">';
        $html .= '<span class="workflow-dot">' . ($i < $active && !$cancelled ? '✓' : ($i + 1)) . '</span>';
        $html .= '<span class="workflow-label">' . e($s['label']) . '</span>';
        $html .= '</div>';
        if ($i < count($steps) - 1) {
            $lineCls = 'workflow-line' . (($i < $active && !$cancelled) ? ' is-done' : '');
            $html .= '<div class="' . $lineCls . '"></div>';
        }
    }
    $html .= '</div>';
    return $html;
}

/**
 * Pipeline counts for dashboards (admin/mechanic/customer).
 * @return array{booked:int,quote:int,paid:int,working:int,done:int,cancelled:int}
 */
function workflowPipelineStats(?int $userId = null, ?string $role = null): array
{
    $db = getDB();
    $where = '1=1';
    $params = [];
    if ($role === 'customer' && $userId) {
        $where = 'user_id = ?';
        $params[] = $userId;
    }
    // admin + mechanic: workshop-wide pipeline

    $sql = "SELECT
        SUM(CASE WHEN status NOT IN ('Cancelled','Completed') AND (quote_amount IS NULL OR quote_amount=0) AND paid_at IS NULL THEN 1 ELSE 0 END) AS booked,
        SUM(CASE WHEN status='Approved' AND paid_at IS NULL AND quote_amount IS NOT NULL AND quote_amount>0 THEN 1 ELSE 0 END) AS quote_ready,
        SUM(CASE WHEN status='Approved' AND paid_at IS NOT NULL THEN 1 ELSE 0 END) AS paid,
        SUM(CASE WHEN status='In Progress' THEN 1 ELSE 0 END) AS working,
        SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) AS done,
        SUM(CASE WHEN status='Cancelled' THEN 1 ELSE 0 END) AS cancelled
        FROM appointments WHERE $where";
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetch() ?: [];
    } catch (Throwable $e) {
        $r = [];
    }
    return [
        'booked'    => (int) ($r['booked'] ?? 0),
        'quote'     => (int) ($r['quote_ready'] ?? 0),
        'paid'      => (int) ($r['paid'] ?? 0),
        'working'   => (int) ($r['working'] ?? 0),
        'done'      => (int) ($r['done'] ?? 0),
        'cancelled' => (int) ($r['cancelled'] ?? 0),
    ];
}

function workflowPipelineHtml(array $stats, string $title = 'Workflow pipeline'): string
{
    $items = [
        ['Booked', $stats['booked'] ?? 0, 'todo'],
        ['Quote ready', $stats['quote'] ?? 0, 'pending'],
        ['Paid', $stats['paid'] ?? 0, 'ok'],
        ['Working', $stats['working'] ?? 0, 'work'],
        ['Done', $stats['done'] ?? 0, 'done'],
    ];
    $html = '<div class="pipeline-card panel-card p-4 mb-6">';
    $html .= '<h3 class="text-sm font-semibold app-heading mb-3">' . e($title) . '</h3>';
    $html .= '<div class="pipeline-grid">';
    foreach ($items as [$label, $n, $tone]) {
        $html .= '<div class="pipeline-item tone-' . e($tone) . '">';
        $html .= '<p class="pipeline-num">' . (int) $n . '</p>';
        $html .= '<p class="pipeline-lbl">' . e($label) . '</p>';
        $html .= '</div>';
    }
    $html .= '</div></div>';
    return $html;
}

/**
 * Rich progress metrics for admin / mechanic / customer dashboards.
 *
 * @return array<string, mixed>
 */
function getProgressStats(?int $userId = null, ?string $role = null): array
{
    $db = getDB();
    $role = $role ?? 'admin';
    $uid = (int) ($userId ?? 0);
    $month = date('Y-m');
    $today = todayDate();

    $out = [
        'role' => $role,
        'month_label' => date('F Y'),
        'cars_booked_total' => 0,
        'cars_booked_month' => 0,
        'cars_booked_today' => 0,
        'services_done_total' => 0,
        'services_done_month' => 0,
        'services_done_today' => 0,
        'active_jobs' => 0,
        'awaiting_quote' => 0,
        'awaiting_payment' => 0,
        'in_progress' => 0,
        'cancelled_total' => 0,
        'vehicles' => 0,
        'unique_customers' => 0,
        'revenue_total' => 0.0,
        'revenue_month' => 0.0,
        'revenue_today' => 0.0,
        'commission_total' => 0.0,
        'commission_month' => 0.0,
        'parts_revenue_month' => 0.0,
        'parts_cost_month' => 0.0,
        'parts_margin_month' => 0.0,
        'profit_total' => 0.0,
        'profit_month' => 0.0,
        'avg_job_value' => 0.0,
        'avg_rating' => 0.0,
        'rated_jobs' => 0,
        'completion_rate' => 0.0,
        'refunds_pending' => 0,
        'refunds_paid_month' => 0.0,
        'pending_payments_value' => 0.0,
        'mechanic_count' => 0,
        'low_stock' => 0,
    ];

    try {
        // Scoped appointment filter
        $aWhere = '1=1';
        $aParams = [];
        $hWhere = "status='Settled'";
        $hParams = [];

        if ($role === 'customer' && $uid) {
            $aWhere = 'user_id=?';
            $aParams = [$uid];
            $hWhere = "status='Settled' AND user_id=?";
            $hParams = [$uid];
        } elseif ($role === 'mechanic' && $uid) {
            // Workshop-wide queue for booking counts; own jobs for completion/commission
            $aWhere = '1=1';
            $hWhere = "status='Settled' AND mechanic_id=?";
            $hParams = [$uid];
        }

        // Appointments progress
        $sql = "SELECT
            COUNT(*) AS total_booked,
            SUM(CASE WHEN DATE_FORMAT(created_at,'%Y-%m')=? THEN 1 ELSE 0 END) AS booked_month,
            SUM(CASE WHEN appointment_date=? AND status NOT IN ('Cancelled') THEN 1 ELSE 0 END) AS booked_today,
            SUM(CASE WHEN status NOT IN ('Cancelled','Completed') THEN 1 ELSE 0 END) AS active_jobs,
            SUM(CASE WHEN status NOT IN ('Cancelled','Completed') AND paid_at IS NULL AND (quote_amount IS NULL OR quote_amount=0) THEN 1 ELSE 0 END) AS awaiting_quote,
            SUM(CASE WHEN status='Approved' AND paid_at IS NULL AND quote_amount IS NOT NULL AND quote_amount>0 THEN 1 ELSE 0 END) AS awaiting_payment,
            SUM(CASE WHEN status='In Progress' THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) AS completed_appts,
            SUM(CASE WHEN status='Cancelled' THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN paid_at IS NULL AND quote_amount IS NOT NULL AND quote_amount>0 AND status='Approved' THEN quote_amount ELSE 0 END) AS pending_pay_value,
            SUM(CASE WHEN refund_status='requested' THEN 1 ELSE 0 END) AS refunds_pending
            FROM appointments WHERE $aWhere";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge([$month, $today], $aParams));
        $a = $stmt->fetch() ?: [];

        $out['cars_booked_total'] = (int) ($a['total_booked'] ?? 0);
        $out['cars_booked_month'] = (int) ($a['booked_month'] ?? 0);
        $out['cars_booked_today'] = (int) ($a['booked_today'] ?? 0);
        $out['active_jobs'] = (int) ($a['active_jobs'] ?? 0);
        $out['awaiting_quote'] = (int) ($a['awaiting_quote'] ?? 0);
        $out['awaiting_payment'] = (int) ($a['awaiting_payment'] ?? 0);
        $out['in_progress'] = (int) ($a['in_progress'] ?? 0);
        $out['cancelled_total'] = (int) ($a['cancelled'] ?? 0);
        $out['pending_payments_value'] = (float) ($a['pending_pay_value'] ?? 0);
        $out['refunds_pending'] = (int) ($a['refunds_pending'] ?? 0);

        // Service history revenue / done
        $sqlH = "SELECT
            COUNT(*) AS done_total,
            SUM(CASE WHEN DATE_FORMAT(completion_date,'%Y-%m')=? THEN 1 ELSE 0 END) AS done_month,
            SUM(CASE WHEN completion_date=? THEN 1 ELSE 0 END) AS done_today,
            COALESCE(SUM(gross_payment),0) AS rev_total,
            COALESCE(SUM(CASE WHEN DATE_FORMAT(completion_date,'%Y-%m')=? THEN gross_payment ELSE 0 END),0) AS rev_month,
            COALESCE(SUM(CASE WHEN completion_date=? THEN gross_payment ELSE 0 END),0) AS rev_today,
            COALESCE(SUM(commission_amount),0) AS comm_total,
            COALESCE(SUM(CASE WHEN DATE_FORMAT(completion_date,'%Y-%m')=? THEN commission_amount ELSE 0 END),0) AS comm_month,
            COALESCE(AVG(CASE WHEN rating IS NOT NULL THEN rating END),0) AS avg_rating,
            SUM(CASE WHEN rating IS NOT NULL THEN 1 ELSE 0 END) AS rated_jobs
            FROM service_history WHERE $hWhere";
        $stmtH = $db->prepare($sqlH);
        $stmtH->execute(array_merge([$month, $today, $month, $today, $month], $hParams));
        $h = $stmtH->fetch() ?: [];

        $out['services_done_total'] = (int) ($h['done_total'] ?? 0);
        $out['services_done_month'] = (int) ($h['done_month'] ?? 0);
        $out['services_done_today'] = (int) ($h['done_today'] ?? 0);
        $out['revenue_total'] = (float) ($h['rev_total'] ?? 0);
        $out['revenue_month'] = (float) ($h['rev_month'] ?? 0);
        $out['revenue_today'] = (float) ($h['rev_today'] ?? 0);
        $out['commission_total'] = (float) ($h['comm_total'] ?? 0);
        $out['commission_month'] = (float) ($h['comm_month'] ?? 0);
        $out['avg_rating'] = round((float) ($h['avg_rating'] ?? 0), 1);
        $out['rated_jobs'] = (int) ($h['rated_jobs'] ?? 0);
        $out['avg_job_value'] = $out['services_done_total'] > 0
            ? round($out['revenue_total'] / $out['services_done_total'], 2)
            : 0.0;

        $denom = $out['cars_booked_total'] - $out['cancelled_total'];
        if ($denom < 1) {
            $denom = max(1, $out['cars_booked_total']);
        }
        $out['completion_rate'] = $out['cars_booked_total'] > 0
            ? round(($out['services_done_total'] / max(1, $out['cars_booked_total'])) * 100, 1)
            : 0.0;

        // Vehicles / customers
        if ($role === 'customer' && $uid) {
            $st = $db->prepare('SELECT COUNT(*) FROM vehicles WHERE user_id=?');
            $st->execute([$uid]);
            $out['vehicles'] = (int) $st->fetchColumn();
            $out['unique_customers'] = 1;
        } else {
            $out['vehicles'] = (int) $db->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
            $out['unique_customers'] = (int) $db->query("SELECT COUNT(*) FROM users WHERE role='customer' AND is_active=1")->fetchColumn();
            $out['mechanic_count'] = (int) $db->query("SELECT COUNT(*) FROM users WHERE role='mechanic' AND is_active=1")->fetchColumn();
        }

        // Parts + profit (admin primarily; mechanic sees 0 or workshop)
        if ($role === 'admin' || $role === 'mechanic') {
            $parts = InventoryService::partsMarginSummary(date('Y-m-01'), date('Y-m-t'));
            $out['parts_revenue_month'] = (float) ($parts['parts_revenue'] ?? 0);
            $out['parts_cost_month'] = (float) ($parts['parts_cost'] ?? 0);
            $out['parts_margin_month'] = (float) ($parts['parts_margin'] ?? 0);
            try {
                $out['low_stock'] = (int) $db->query('SELECT COUNT(*) FROM parts WHERE is_active=1 AND stock_qty <= min_stock')->fetchColumn();
            } catch (Throwable $e) {
                $out['low_stock'] = 0;
            }
            // Profit ≈ revenue - commissions - parts cost
            $commAllMonth = (float) $db->query("SELECT COALESCE(SUM(commission_amount),0) FROM service_history WHERE status='Settled' AND DATE_FORMAT(completion_date,'%Y-%m')=" . $db->quote($month))->fetchColumn();
            $revMonthAll = (float) $db->query("SELECT COALESCE(SUM(gross_payment),0) FROM service_history WHERE status='Settled' AND DATE_FORMAT(completion_date,'%Y-%m')=" . $db->quote($month))->fetchColumn();
            $revTotalAll = (float) $db->query("SELECT COALESCE(SUM(gross_payment),0) FROM service_history WHERE status='Settled'")->fetchColumn();
            $commAllTotal = (float) $db->query("SELECT COALESCE(SUM(commission_amount),0) FROM service_history WHERE status='Settled'")->fetchColumn();
            $partsTotal = InventoryService::partsMarginSummary();
            if ($role === 'admin') {
                $out['profit_month'] = round($revMonthAll - $commAllMonth - $out['parts_cost_month'], 2);
                $out['profit_total'] = round($revTotalAll - $commAllTotal - (float) ($partsTotal['parts_cost'] ?? 0), 2);
                // For admin, revenue figures are workshop-wide
                $out['revenue_total'] = $revTotalAll;
                $out['revenue_month'] = $revMonthAll;
                $out['commission_total'] = $commAllTotal;
                $out['commission_month'] = $commAllMonth;
                $out['services_done_total'] = (int) $db->query("SELECT COUNT(*) FROM service_history WHERE status='Settled'")->fetchColumn();
                $out['services_done_month'] = (int) $db->query("SELECT COUNT(*) FROM service_history WHERE status='Settled' AND DATE_FORMAT(completion_date,'%Y-%m')=" . $db->quote($month))->fetchColumn();
                $out['services_done_today'] = (int) $db->query("SELECT COUNT(*) FROM service_history WHERE status='Settled' AND completion_date=" . $db->quote($today))->fetchColumn();
                $out['avg_job_value'] = $out['services_done_total'] > 0 ? round($out['revenue_total'] / $out['services_done_total'], 2) : 0.0;
                $avgR = $db->query("SELECT COALESCE(AVG(rating),0), COUNT(rating) FROM service_history WHERE rating IS NOT NULL")->fetch(PDO::FETCH_NUM);
                $out['avg_rating'] = round((float) ($avgR[0] ?? 0), 1);
                $out['rated_jobs'] = (int) ($avgR[1] ?? 0);
            } else {
                // Mechanic: personal profit = own commission
                $out['profit_total'] = $out['commission_total'];
                $out['profit_month'] = $out['commission_month'];
            }

            try {
                $out['refunds_paid_month'] = (float) $db->query("
                    SELECT COALESCE(SUM(COALESCE(quote_amount,0)),0) FROM appointments
                    WHERE refund_status='paid' AND DATE_FORMAT(COALESCE(refund_requested_at, updated_at),'%Y-%m')=" . $db->quote($month)
                )->fetchColumn();
            } catch (Throwable $e) {
                $out['refunds_paid_month'] = 0.0;
            }
        }

        // Customer: "profit" N/A — show savings angle as spent
        if ($role === 'customer') {
            $out['profit_total'] = 0.0;
            $out['profit_month'] = 0.0;
        }
    } catch (Throwable $e) {
        // keep zeros
    }

    return $out;
}

/**
 * Render a grid of progress KPI cards.
 *
 * @param list<array{label:string,value:string|int|float,hint?:string,tone?:string,icon?:string}> $cards
 */
function progressKpiHtml(array $cards, string $title = 'Progress overview'): string
{
    $html = '<div class="panel-card p-4 mb-6 progress-kpi-wrap">';
    $html .= '<h3 class="text-sm font-semibold app-heading mb-3">' . e($title) . '</h3>';
    $html .= '<div class="progress-kpi-grid">';
    foreach ($cards as $c) {
        $tone = e($c['tone'] ?? 'default');
        $html .= '<div class="progress-kpi tone-' . $tone . '">';
        if (!empty($c['icon'])) {
            $html .= '<div class="progress-kpi-icon">' . $c['icon'] . '</div>';
        }
        $html .= '<p class="progress-kpi-label">' . e($c['label']) . '</p>';
        $html .= '<p class="progress-kpi-value">' . e((string) $c['value']) . '</p>';
        if (!empty($c['hint'])) {
            $html .= '<p class="progress-kpi-hint">' . e($c['hint']) . '</p>';
        }
        $html .= '</div>';
    }
    $html .= '</div></div>';
    return $html;
}

/** Build role-specific KPI card list from getProgressStats(). */
function progressKpiCardsForRole(array $p, string $role): array
{
    if ($role === 'admin') {
        // Trimmed to the essentials: booking volume, completion volume, money in, money kept, inventory.
        return [
            ['icon' => '🚗', 'label' => 'Cars booked (all time)', 'value' => (string) $p['cars_booked_total'], 'hint' => $p['cars_booked_month'] . ' this month · ' . $p['cars_booked_today'] . ' today', 'tone' => 'info'],
            ['icon' => '✅', 'label' => 'Services completed', 'value' => (string) $p['services_done_total'], 'hint' => $p['services_done_month'] . ' this month · ' . $p['services_done_today'] . ' today', 'tone' => 'ok'],
            ['icon' => '💰', 'label' => 'Revenue (all time)', 'value' => formatRM($p['revenue_total']), 'hint' => formatRM($p['revenue_month']) . ' in ' . $p['month_label'], 'tone' => 'money'],
            ['icon' => '📈', 'label' => 'Est. profit (all time)', 'value' => formatRM($p['profit_total']), 'hint' => formatRM($p['profit_month']) . ' this month (rev − commission − parts cost)', 'tone' => 'accent'],
            ['icon' => '📦', 'label' => 'Parts / inventory margin (month)', 'value' => formatRM($p['parts_margin_month']), 'hint' => 'Rev ' . formatRM($p['parts_revenue_month']) . ' · cost ' . formatRM($p['parts_cost_month']) . ' · ' . $p['low_stock'] . ' low stock', 'tone' => 'ok'],
        ];
    }

    if ($role === 'mechanic') {
        // Trimmed to what a mechanic actually needs day to day: their completions, earnings, active load.
        return [
            ['icon' => '✅', 'label' => 'Services I completed', 'value' => (string) $p['services_done_total'], 'hint' => $p['services_done_month'] . ' this month · ' . $p['services_done_today'] . ' today', 'tone' => 'ok'],
            ['icon' => '💵', 'label' => 'My commission (total)', 'value' => formatRM($p['commission_total']), 'hint' => formatRM($p['commission_month']) . ' in ' . $p['month_label'], 'tone' => 'money'],
            ['icon' => '🔧', 'label' => 'In progress now', 'value' => (string) $p['in_progress'], 'hint' => $p['active_jobs'] . ' open workshop jobs', 'tone' => 'work'],
            ['icon' => '⭐', 'label' => 'My avg rating', 'value' => $p['avg_rating'] > 0 ? (string) $p['avg_rating'] . ' / 5' : '—', 'hint' => $p['rated_jobs'] . ' ratings received', 'tone' => 'ok'],
        ];
    }

    // customer — trimmed to booking volume, completion, and spend.
    return [
        ['icon' => '🚗', 'label' => 'My bookings (all time)', 'value' => (string) $p['cars_booked_total'], 'hint' => $p['cars_booked_month'] . ' this month · ' . $p['cars_booked_today'] . ' today', 'tone' => 'info'],
        ['icon' => '✅', 'label' => 'Services completed', 'value' => (string) $p['services_done_total'], 'hint' => $p['services_done_month'] . ' this month', 'tone' => 'ok'],
        ['icon' => '💳', 'label' => 'Total spent', 'value' => formatRM($p['revenue_total']), 'hint' => formatRM($p['revenue_month']) . ' in ' . $p['month_label'], 'tone' => 'money'],
        ['icon' => '🔧', 'label' => 'Being serviced', 'value' => (string) $p['in_progress'], 'hint' => $p['active_jobs'] . ' active booking(s)', 'tone' => 'work'],
    ];
}

/**
 * Malaysian-market vehicle catalog (brand → models).
 * @return array<string, list<string>>
 */
function vehicleCatalog(): array
{
    return [
        'Perodua' => ['Axia', 'Bezza', 'Myvi', 'Alza', 'Ativa', 'Aruz'],
        'Proton'  => ['Saga', 'Persona', 'Iriz', 'Exora', 'X50', 'X70', 'X90', 'S70'],
        'Honda'   => ['City', 'Civic', 'Accord', 'Jazz', 'HR-V', 'CR-V', 'BR-V', 'WR-V'],
        'Toyota'  => ['Vios', 'Yaris', 'Corolla', 'Camry', 'Rush', 'Innova', 'Hilux', 'Fortuner', 'Alphard'],
        'Nissan'  => ['Almera', 'Navara', 'X-Trail', 'Serena', 'Leaf'],
        'Mazda'   => ['Mazda2', 'Mazda3', 'CX-3', 'CX-5', 'CX-8', 'CX-30'],
        'Mitsubishi' => ['Triton', 'Xpander', 'ASX', 'Outlander'],
        'Hyundai' => ['Ioniq', 'Tucson', 'Santa Fe', 'Staria', 'Creta'],
        'Kia'     => ['Cerato', 'Sportage', 'Sorento', 'Carnival', 'Picanto'],
        'Mercedes-Benz' => ['A-Class', 'C-Class', 'E-Class', 'GLA', 'GLC', 'GLE'],
        'BMW'     => ['1 Series', '3 Series', '5 Series', 'X1', 'X3', 'X5'],
        'Volkswagen' => ['Polo', 'Golf', 'Passat', 'Tiguan'],
        'Isuzu'   => ['D-Max', 'MU-X'],
        'Ford'    => ['Ranger', 'Everest', 'Territory'],
        'Others'  => ['Other model'],
    ];
}

function vehicleYears(): array
{
    $y = (int) date('Y') + 1;
    $years = [];
    for ($i = $y; $i >= 1995; $i--) {
        $years[] = $i;
    }
    return $years;
}

/**
 * Recommended next-service checklist (bilingual).
 */
function nextServiceChecklist(string $packageName = '', int $mileage = 0): string
{
    $nextKm = $mileage > 0 ? $mileage + getServiceIntervalKm() : getServiceIntervalKm();
    $nextDate = date('Y-m-d', strtotime('+' . getServiceIntervalMonths() . ' months'));
    $lines = [
        'Next service by: ' . number_format($nextKm) . ' km or before ' . $nextDate,
        'Servis seterusnya: ' . number_format($nextKm) . ' km atau sebelum ' . $nextDate,
        'Recommended checks / Pemeriksaan disyorkan:',
        '• Engine oil & filter / Minyak enjin & penapis',
        '• Air filter / Penapis udara',
        '• Brake pads & fluid / Pad brek & minyak brek',
        '• Coolant & battery / Penyejuk & bateri',
        '• Tyre condition & pressure / Keadaan & tekanan tayar',
        '• Wipers & lights / Wiper & lampu',
    ];
    if ($packageName !== '') {
        array_unshift($lines, 'Completed service: ' . $packageName);
    }
    return implode("\n", $lines);
}

function getMechanicCommissionPercent(): float
{
    return (float) getWorkshopSetting('mechanic_commission_percent', '15');
}

function getMaxBookingsPerSlot(): int
{
    return max(1, (int) getWorkshopSetting('max_bookings_per_slot', '2'));
}

function getServiceIntervalKm(): int
{
    return max(1000, (int) getWorkshopSetting('service_interval_km', '10000'));
}

function getServiceIntervalMonths(): int
{
    return max(1, (int) getWorkshopSetting('service_interval_months', '6'));
}

/**
 * Count active (non-cancelled) bookings for a date + time slot.
 * Uses COUNT(*) — never rowCount() on SELECT (unreliable on MySQL PDO).
 */
function getSlotBookingCount(string $date, string $timeWindow, ?int $excludeAppointmentId = null): int
{
    $db = getDB();
    $sql = "SELECT COUNT(*) FROM appointments
            WHERE appointment_date = ?
              AND TRIM(time_window) = TRIM(?)
              AND status NOT IN ('Cancelled')";
    $params = [$date, $timeWindow];
    if ($excludeAppointmentId) {
        $sql .= ' AND id != ?';
        $params[] = $excludeAppointmentId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Bookings occupying a slot (for visual board).
 * @return list<array{id:int,plate_no:string,owner_name:string,pkg_name:string,status:string}>
 */
function getSlotBookings(string $date, string $timeWindow): array
{
    $db = getDB();
    $stmt = $db->prepare("
        SELECT a.id, a.status, a.user_id, v.plate_no, v.owner_name, sp.name AS pkg_name
        FROM appointments a
        JOIN vehicles v ON v.id = a.vehicle_id
        JOIN service_packages sp ON sp.id = a.package_id
        WHERE a.appointment_date = ?
          AND TRIM(a.time_window) = TRIM(?)
          AND a.status NOT IN ('Cancelled')
        ORDER BY a.id
    ");
    $stmt->execute([$date, $timeWindow]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Live availability for all slots on a given date.
 * @return array<int, array{time:string, booked:int, max:int, available:int, full:bool, bookings:array}>
 */
function getSlotAvailability(string $date): array
{
    $max = getMaxBookingsPerSlot();
    $result = [];
    foreach (serviceTimeSlots() as $slot) {
        $bookings = getSlotBookings($date, $slot);
        $booked = count($bookings);
        // Prefer COUNT for consistency if any race
        $booked = max($booked, getSlotBookingCount($date, $slot));
        $available = max(0, $max - $booked);
        $result[] = [
            'time'      => $slot,
            'booked'    => $booked,
            'max'       => $max,
            'available' => $available,
            'full'      => $available <= 0,
            'bookings'  => array_map(static function ($b) {
                return [
                    'id'         => (int) $b['id'],
                    'plate_no'   => $b['plate_no'],
                    'owner_name' => $b['owner_name'],
                    'pkg_name'   => $b['pkg_name'],
                    'status'     => $b['status'],
                ];
            }, $bookings),
        ];
    }
    return $result;
}

function isSlotAvailable(string $date, string $timeWindow): bool
{
    return getSlotBookingCount($date, $timeWindow) < getMaxBookingsPerSlot();
}

function payForAppointment(int $appointmentId, int $userId, string $paymentMethod, ?string $paymentProofPath = null): array
{
    $methods = paymentMethods();
    if (!isset($methods[$paymentMethod])) {
        return ['ok' => false, 'error' => 'Please select a valid payment method. / Sila pilih kaedah bayaran yang sah.'];
    }
    ensureSchemaUpdates();
    $db = getDB();
    $stmt = $db->prepare('
        SELECT a.*, sp.name AS pkg_name, sp.price, v.plate_no
        FROM appointments a
        JOIN service_packages sp ON sp.id = a.package_id
        JOIN vehicles v ON v.id = a.vehicle_id
        WHERE a.id = ? AND a.user_id = ?
    ');
    $stmt->execute([$appointmentId, $userId]);
    $appt = $stmt->fetch();

    if (!$appt) {
        return ['ok' => false, 'error' => 'Appointment not found. / Temujanji tidak dijumpai.'];
    }
    if ($appt['status'] !== 'Approved') {
        return ['ok' => false, 'error' => 'This appointment cannot be paid in its current state. / Temujanji ini tidak boleh dibayar dalam status semasa.'];
    }
    if (appointmentIsPaid($appt)) {
        return ['ok' => false, 'error' => 'This appointment has already been paid. / Temujanji ini sudah dibayar.'];
    }

    // Must have mechanic quote first (real workshop flow)
    $quoteAmt = isset($appt['quote_amount']) ? (float) $appt['quote_amount'] : 0.0;
    if ($quoteAmt <= 0) {
        return ['ok' => false, 'error' => 'Please wait for the workshop quote before paying. / Sila tunggu sebut harga bengkel sebelum bayar.'];
    }

    // Online banking requires payment proof upload
    if ($paymentMethod === 'online_banking' && !$paymentProofPath && empty($appt['payment_proof'])) {
        return ['ok' => false, 'error' => 'Please upload your bank transfer receipt as payment proof. / Sila muat naik resit pindahan bank sebagai bukti bayaran.'];
    }

    // Charge the quoted amount (not fixed package price)
    $price = $quoteAmt > 0 ? $quoteAmt : (float) $appt['price'];
    $db->beginTransaction();
    try {
        $proof = $paymentProofPath ?: ($appt['payment_proof'] ?? null);
        $db->prepare('UPDATE appointments SET paid_at = NOW(), payment_method = ?, payment_proof = COALESCE(?, payment_proof) WHERE id = ?')
           ->execute([$paymentMethod, $proof, $appointmentId]);

        $hist = $db->prepare('SELECT id FROM service_history WHERE appointment_id = ?');
        $hist->execute([$appointmentId]);
        $historyId = (int) $hist->fetchColumn();

        if (!$historyId) {
            $db->prepare('INSERT INTO service_history (appointment_id,user_id,vehicle_id,package_id,mechanic_id,completion_date,gross_payment) VALUES (?,?,?,?,?,?,?)')
               ->execute([$appointmentId, $appt['user_id'], $appt['vehicle_id'], $appt['package_id'], $appt['mechanic_id'], $appt['appointment_date'], $price]);
            $historyId = (int) $db->lastInsertId();
        }

        $receiptNo = genReceiptNo();
        $db->prepare('INSERT INTO receipts (history_id, receipt_no, requested_by) VALUES (?,?,?)')
           ->execute([$historyId, $receiptNo, $userId]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        return ['ok' => false, 'error' => 'Payment could not be processed. Please try again. / Bayaran tidak dapat diproses. Sila cuba lagi.'];
    }

    $vehicle = $appt['plate_no'] . ' — ' . $appt['pkg_name'];
    $methodLabel = paymentMethodLabel($paymentMethod);
    notify($userId, 'Payment Received / Bayaran Diterima', "Your {$methodLabel} payment of " . formatRM($price) . " for {$vehicle} is confirmed. Receipt {$receiptNo} is ready. / Bayaran anda disahkan. Resit {$receiptNo} sedia.", 'receipt', baseUrl('user/receipt.php'));
    notify(1, 'New Paid Booking / Tempahan Dibayar', $vehicle . ' paid ' . formatRM($price) . ' for ' . $appt['appointment_date'] . ($proof ? ' (proof uploaded)' : '') . '.', 'appointment', baseUrl('admin/appointments.php'));

    $mechanics = $db->query("SELECT id FROM users WHERE role='mechanic' AND is_active=1")->fetchAll();
    foreach ($mechanics as $m) {
        notify((int) $m['id'], 'New Job Available / Kerja Baru', $vehicle . ' is ready for service on ' . $appt['appointment_date'] . '.', 'appointment', baseUrl('mechanic/appointments.php'));
    }

    return ['ok' => true, 'receipt_no' => $receiptNo, 'amount' => $price, 'payment_method' => $paymentMethod];
}

/**
 * Handle uploaded payment proof file. Returns relative path or null.
 */
function handlePaymentProofUpload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file uploaded. / Tiada fail dimuat naik.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed. Please try again. / Muat naik gagal. Sila cuba lagi.'];
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'File too large (max 5MB). / Fail terlalu besar (maks 5MB).'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, WEBP or PDF allowed. / Hanya JPG, PNG, WEBP atau PDF dibenarkan.'];
    }

    $ext = $allowed[$mime];
    $name = 'proof_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dir = __DIR__ . '/../uploads/payment_proofs';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'error' => 'Could not save file. / Tidak dapat menyimpan fail.'];
    }

    return ['ok' => true, 'path' => 'uploads/payment_proofs/' . $name];
}

function updateAppointmentWork(int $appointmentId, string $newStatus, int $mechanicId, ?string $workNote = null, ?int $dropoffMileage = null): array
{
    ensureSchemaUpdates();
    $db = getDB();
    $stmt = $db->prepare('
        SELECT a.*, v.plate_no, v.brand, v.model_variant, sp.name AS pkg_name, sp.price, u.email AS customer_email, u.full_name AS customer_name
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
    if (!appointmentIsPaid($appt)) {
        return ['ok' => false, 'error' => 'This job has not been paid yet. / Kerja ini belum dibayar.'];
    }
    if ($appt['status'] === 'Cancelled' || $appt['status'] === 'Completed') {
        return ['ok' => false, 'error' => 'This appointment is already closed. / Temujanji ini sudah ditutup.'];
    }

    $vehicle = $appt['plate_no'] . ' — ' . $appt['pkg_name'];
    $customerId = (int) $appt['user_id'];

    if ($newStatus === 'In Progress') {
        if (!in_array($appt['status'], ['Approved', 'In Progress'], true)) {
            return ['ok' => false, 'error' => 'Cannot start this job. / Tidak boleh mulakan kerja ini.'];
        }
        if ($dropoffMileage === null || $dropoffMileage < 0) {
            return ['ok' => false, 'error' => 'Please record the vehicle mileage (km) at drop-off. / Sila rekod bacaan odometer (km) semasa serahan kereta.'];
        }

        $db->prepare("UPDATE appointments SET status='In Progress', mechanic_id=?, dropoff_mileage=? WHERE id=?")
           ->execute([$mechanicId, $dropoffMileage, $appointmentId]);
        $db->prepare('UPDATE vehicles SET current_mileage=? WHERE id=?')
           ->execute([$dropoffMileage, $appt['vehicle_id']]);

        $mech = $db->prepare('SELECT full_name FROM users WHERE id=?');
        $mech->execute([$mechanicId]);
        $mechName = $mech->fetchColumn() ?: 'Workshop team';
        notify($customerId, 'Work Started / Kerja Dimulakan', "{$mechName} has started work on {$vehicle}. Mileage recorded: " . number_format($dropoffMileage) . " km. / Kerja dimulakan. Bacaan odometer: " . number_format($dropoffMileage) . " km.", 'appointment', baseUrl('user/appointments.php'));
        return ['ok' => true, 'message' => 'Job started. Mileage recorded: ' . number_format($dropoffMileage) . ' km. / Kerja dimulakan. Odometer direkod.'];
    }

    if ($newStatus === 'Completed') {
        if (!in_array($appt['status'], ['Approved', 'In Progress'], true)) {
            return ['ok' => false, 'error' => 'Cannot complete this job. / Tidak boleh selesaikan kerja ini.'];
        }

        // If completing directly from Approved, require mileage too
        $mileage = $dropoffMileage;
        if ($mileage === null && !empty($appt['dropoff_mileage'])) {
            $mileage = (int) $appt['dropoff_mileage'];
        }
        if ($mileage === null || $mileage < 0) {
            return ['ok' => false, 'error' => 'Please record the vehicle mileage (km) before completing. / Sila rekod bacaan odometer sebelum selesai.'];
        }

        $gross = (float) ($appt['price'] ?? 0);
        // Prefer gross from history if already created at payment
        $histStmt = $db->prepare('SELECT id, gross_payment FROM service_history WHERE appointment_id=?');
        $histStmt->execute([$appointmentId]);
        $histRow = $histStmt->fetch();
        if ($histRow) {
            $gross = (float) $histRow['gross_payment'];
        }

        $commPct = getMechanicCommissionPercent();
        $commAmt = round($gross * ($commPct / 100), 2);

        $db->beginTransaction();
        try {
            if ($histRow) {
                $db->prepare('UPDATE service_history SET completion_date=?, mechanic_id=?, commission_amount=?, commission_percent=? WHERE appointment_id=?')
                   ->execute([todayDate(), $mechanicId, $commAmt, $commPct, $appointmentId]);
            } else {
                $db->prepare('INSERT INTO service_history (appointment_id,user_id,vehicle_id,package_id,mechanic_id,completion_date,gross_payment,commission_amount,commission_percent) VALUES (?,?,?,?,?,?,?,?,?)')
                   ->execute([$appointmentId, $appt['user_id'], $appt['vehicle_id'], $appt['package_id'], $mechanicId, todayDate(), $gross, $commAmt, $commPct]);
            }

            $db->prepare("UPDATE appointments SET status='Completed', mechanic_id=?, dropoff_mileage=COALESCE(dropoff_mileage,?) WHERE id=?")
               ->execute([$mechanicId, $mileage, $appointmentId]);

            $db->prepare('UPDATE vehicles SET current_mileage=?, last_service_mileage=?, last_service_date=? WHERE id=?')
               ->execute([$mileage, $mileage, todayDate(), $appt['vehicle_id']]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'Could not complete job. / Tidak dapat selesaikan kerja.'];
        }

        $intervalKm = getServiceIntervalKm();
        $intervalMonths = getServiceIntervalMonths();
        $nextKm = $mileage + $intervalKm;
        $nextDate = date('Y-m-d', strtotime('+' . $intervalMonths . ' months'));

        notify($customerId, 'Service Completed / Servis Selesai', "Work on {$vehicle} is complete. Mileage: " . number_format($mileage) . " km. Next service due at " . number_format($nextKm) . " km or by {$nextDate}. Please rate your service in Service History. / Kerja selesai. Servis seterusnya pada " . number_format($nextKm) . " km atau sebelum {$nextDate}.", 'receipt', baseUrl('user/history.php'));

        // Notify mechanic of commission earned
        notify($mechanicId, 'Commission Earned / Komisen Diperoleh', "You earned " . formatRM($commAmt) . " ({$commPct}%) commission on {$vehicle}. / Anda memperoleh komisen " . formatRM($commAmt) . " ({$commPct}%).", 'success', baseUrl('mechanic/history.php'));

        // Schedule-style reminder note (also check on dashboard for 6-month / km)
        sendServiceReminderEmail(
            (string) $appt['customer_email'],
            (string) $appt['customer_name'],
            (string) $appt['plate_no'],
            $mileage,
            $nextKm,
            $nextDate
        );

        return [
            'ok' => true,
            'message' => 'Job completed. Commission: ' . formatRM($commAmt) . " ({$commPct}%). / Kerja selesai. Komisen: " . formatRM($commAmt),
            'commission' => $commAmt,
        ];
    }

    return ['ok' => false, 'error' => 'Invalid status update. / Kemaskini status tidak sah.'];
}

/**
 * Customer cancels booking (before work starts). Optionally request refund if paid.
 */
function cancelCustomerBooking(int $appointmentId, int $userId, string $reason = '', bool $requestRefund = false): array
{
    ensureSchemaUpdates();
    $db = getDB();
    $stmt = $db->prepare("
        SELECT a.*, v.plate_no, sp.name AS pkg_name
        FROM appointments a
        JOIN vehicles v ON v.id = a.vehicle_id
        JOIN service_packages sp ON sp.id = a.package_id
        WHERE a.id = ? AND a.user_id = ?
    ");
    $stmt->execute([$appointmentId, $userId]);
    $appt = $stmt->fetch();

    if (!$appt) {
        return ['ok' => false, 'error' => 'Appointment not found. / Temujanji tidak dijumpai.'];
    }
    if ($appt['status'] === 'Cancelled') {
        return ['ok' => false, 'error' => 'Already cancelled. / Sudah dibatalkan.'];
    }
    if ($appt['status'] === 'Completed') {
        return ['ok' => false, 'error' => 'Completed jobs cannot be cancelled. / Kerja selesai tidak boleh dibatalkan.'];
    }
    if ($appt['status'] === 'In Progress') {
        return ['ok' => false, 'error' => 'Cannot cancel while work is in progress. Contact the workshop. / Tidak boleh batal semasa kerja berjalan. Hubungi bengkel.'];
    }
    if ($appt['status'] !== 'Approved') {
        return ['ok' => false, 'error' => 'This appointment cannot be cancelled. / Temujanji ini tidak boleh dibatalkan.'];
    }

    $paid = appointmentIsPaid($appt);
    $refundStatus = 'none';
    if ($paid && $requestRefund) {
        $refundStatus = 'requested';
    }

    $db->prepare("
        UPDATE appointments SET status='Cancelled',
            refund_status=?, refund_reason=?, refund_requested_at=IF(?='requested', NOW(), refund_requested_at)
        WHERE id=?
    ")->execute([$refundStatus, $reason !== '' ? $reason : null, $refundStatus, $appointmentId]);

    $label = $appt['plate_no'] . ' — ' . $appt['pkg_name'] . ' (' . $appt['appointment_date'] . ' ' . $appt['time_window'] . ')';
    notifyWithEmail(
        $userId,
        'Booking Cancelled / Tempahan Dibatalkan',
        "You cancelled: {$label}. The time slot is free again. / Tempahan dibatalkan. Slot dibuka semula.",
        'warning',
        baseUrl('user/appointments.php'),
        true
    );
    notify(1, 'Customer Cancelled Booking / Pelanggan Batal', $label . ($paid ? ' (PAID)' : '') . ($requestRefund ? ' — refund requested' : '') . ($reason ? ': ' . $reason : ''), 'alert', baseUrl('admin/appointments.php'));

    // Notify mechanics
    $mechanics = $db->query("SELECT id FROM users WHERE role='mechanic' AND is_active=1")->fetchAll();
    foreach ($mechanics as $m) {
        notify((int) $m['id'], 'Booking Cancelled', $label . ' was cancelled by customer.', 'warning', baseUrl('mechanic/appointments.php'));
    }

    if ($paid && $requestRefund) {
        notifyWithEmail(
            $userId,
            'Refund Requested / Permintaan Bayaran Balik',
            'Your refund request for ' . $label . ' is pending workshop review. / Permintaan bayaran balik sedang diproses.',
            'alert',
            baseUrl('user/appointments.php'),
            true,
            true
        );
        return ['ok' => true, 'message' => 'Booking cancelled and refund requested. / Tempahan dibatalkan & bayaran balik diminta.'];
    }
    if ($paid) {
        notify($userId, 'Paid Booking Cancelled', 'You cancelled a paid booking. You can still request a refund from Appointments. / Anda boleh minta bayaran balik di Temujanji.', 'alert', baseUrl('user/appointments.php'));
    }

    return ['ok' => true, 'message' => 'Booking cancelled successfully. / Tempahan berjaya dibatalkan.'];
}

/** Customer requests refund on a cancelled paid booking. */
function requestRefund(int $appointmentId, int $userId, string $reason = ''): array
{
    ensureSchemaUpdates();
    $db = getDB();
    $stmt = $db->prepare('SELECT a.*, v.plate_no, sp.name AS pkg_name FROM appointments a JOIN vehicles v ON v.id=a.vehicle_id JOIN service_packages sp ON sp.id=a.package_id WHERE a.id=? AND a.user_id=?');
    $stmt->execute([$appointmentId, $userId]);
    $appt = $stmt->fetch();
    if (!$appt) {
        return ['ok' => false, 'error' => 'Appointment not found.'];
    }
    if (!appointmentIsPaid($appt)) {
        return ['ok' => false, 'error' => 'No payment recorded for refund. / Tiada bayaran untuk dipulangkan.'];
    }
    if (!in_array($appt['status'], ['Cancelled', 'Approved'], true)) {
        return ['ok' => false, 'error' => 'Refund not available for this status.'];
    }
    $rs = $appt['refund_status'] ?? 'none';
    if (in_array($rs, ['requested', 'approved', 'paid'], true)) {
        return ['ok' => false, 'error' => 'Refund already in progress. / Bayaran balik sudah dipohon.'];
    }
    $db->prepare("UPDATE appointments SET status=IF(status='Approved','Cancelled',status), refund_status='requested', refund_reason=?, refund_requested_at=NOW() WHERE id=?")
       ->execute([$reason !== '' ? $reason : 'Customer refund request', $appointmentId]);
    $label = $appt['plate_no'] . ' — ' . $appt['pkg_name'];
    notify(1, 'Refund Request / Minta Bayaran Balik', $label . ' — ' . ($reason ?: 'no reason given'), 'alert', baseUrl('admin/appointments.php'));
    notify($userId, 'Refund Requested', 'We received your refund request for ' . $label . '.', 'info', baseUrl('user/appointments.php'));
    return ['ok' => true, 'message' => 'Refund request submitted. / Permintaan bayaran balik dihantar.'];
}

/** Admin processes refund request. */
function processRefund(int $appointmentId, string $decision, int $adminId): array
{
    ensureSchemaUpdates();
    if (!in_array($decision, ['approved', 'rejected', 'paid'], true)) {
        return ['ok' => false, 'error' => 'Invalid decision.'];
    }
    $db = getDB();
    $stmt = $db->prepare('SELECT a.*, v.plate_no, sp.name AS pkg_name FROM appointments a JOIN vehicles v ON v.id=a.vehicle_id JOIN service_packages sp ON sp.id=a.package_id WHERE a.id=?');
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();
    if (!$appt) {
        return ['ok' => false, 'error' => 'Not found.'];
    }
    $db->prepare('UPDATE appointments SET refund_status=? WHERE id=?')->execute([$decision, $appointmentId]);
    $label = $appt['plate_no'] . ' — ' . $appt['pkg_name'];
    $msg = match ($decision) {
        'approved' => 'Your refund was approved. Funds will be returned shortly. / Bayaran balik diluluskan.',
        'paid'     => 'Your refund has been paid. / Bayaran balik telah dibayar.',
        default    => 'Your refund request was rejected. Contact the workshop. / Permintaan ditolak.',
    };
    notifyWithEmail((int) $appt['user_id'], 'Refund Update / Kemaskini Bayaran Balik', $label . ': ' . $msg, $decision === 'rejected' ? 'warning' : 'success', baseUrl('user/appointments.php'), true, true);
    return ['ok' => true, 'message' => 'Refund marked as ' . $decision . '.'];
}

/**
 * Mechanic/admin submits a quote — customer must pay this amount (not fixed package price).
 */
function submitAppointmentQuote(int $appointmentId, float $amount, string $notes, int $staffId): array
{
    ensureSchemaUpdates();
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Quote amount must be greater than 0. / Jumlah sebut harga mesti > 0.'];
    }
    $db = getDB();
    $stmt = $db->prepare('SELECT a.*, v.plate_no, sp.name AS pkg_name FROM appointments a JOIN vehicles v ON v.id=a.vehicle_id JOIN service_packages sp ON sp.id=a.package_id WHERE a.id=?');
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();
    if (!$appt) {
        return ['ok' => false, 'error' => 'Appointment not found.'];
    }
    if ($appt['status'] === 'Cancelled' || $appt['status'] === 'Completed') {
        return ['ok' => false, 'error' => 'Cannot quote a closed appointment.'];
    }
    if (appointmentIsPaid($appt)) {
        return ['ok' => false, 'error' => 'Already paid.'];
    }
    $db->prepare("UPDATE appointments SET quote_amount=?, quote_notes=?, quote_status='approved', booking_type=COALESCE(NULLIF(booking_type,''),'package') WHERE id=?")
       ->execute([round($amount, 2), $notes !== '' ? $notes : null, $appointmentId]);

    $label = $appt['plate_no'] . ' — ' . $appt['pkg_name'];
    notifyWithEmail(
        (int) $appt['user_id'],
        'Quote Ready / Sebut Harga Sedia',
        "Quote for {$label}: " . formatRM($amount) . '. ' . ($notes ? $notes . ' ' : '') . 'Please pay to confirm. / Sila bayar untuk sahkan.',
        'appointment',
        baseUrl('user/payment.php?id=' . $appointmentId),
        true,
        true
    );
    notify(1, 'Quote Sent', "Staff #{$staffId} quoted " . formatRM($amount) . " for {$label}.", 'info', baseUrl('admin/appointments.php'));
    return ['ok' => true, 'message' => 'Quote sent to customer. / Sebut harga dihantar kepada pelanggan.'];
}

/**
 * Submit star rating 1–5 for a completed service history record.
 */
function submitServiceFeedback(int $historyId, int $userId, int $rating, string $feedback = ''): array
{
    ensureSchemaUpdates();
    if ($rating < 1 || $rating > 5) {
        return ['ok' => false, 'error' => 'Rating must be 1 to 5 stars. / Penilaian mesti 1 hingga 5 bintang.'];
    }
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM service_history WHERE id=? AND user_id=?');
    $stmt->execute([$historyId, $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['ok' => false, 'error' => 'Service record not found. / Rekod servis tidak dijumpai.'];
    }
    if ($row['status'] !== 'Settled') {
        return ['ok' => false, 'error' => 'Only settled services can be rated. / Hanya servis selesai boleh dinilai.'];
    }
    if (!empty($row['rating'])) {
        return ['ok' => false, 'error' => 'You already rated this service. / Anda sudah menilai servis ini.'];
    }

    $db->prepare('UPDATE service_history SET rating=?, feedback=?, rated_at=NOW() WHERE id=?')
       ->execute([$rating, trim($feedback) ?: null, $historyId]);

    $stars = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
    notify(1, 'New Customer Feedback / Maklum Balas Baru', "Customer rated a service {$stars} ({$rating}/5)." . ($feedback ? ' Comment: ' . mb_substr(trim($feedback), 0, 100) : ''), 'alert', baseUrl('admin/history.php'));
    if (!empty($row['mechanic_id'])) {
        notify((int) $row['mechanic_id'], 'Customer Feedback / Maklum Balas Pelanggan', "You received a {$rating}-star rating on a completed job. / Anda menerima penilaian {$rating} bintang.", 'success', baseUrl('mechanic/history.php'));
    }

    return ['ok' => true, 'message' => 'Thank you for your feedback! / Terima kasih atas maklum balas anda!'];
}

function deleteNotification(int $notificationId, int $userId): bool
{
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM notifications WHERE id=? AND user_id=?');
    $stmt->execute([$notificationId, $userId]);
    return $stmt->rowCount() > 0;
}

function deleteAllReadNotifications(int $userId): int
{
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM notifications WHERE user_id=? AND is_read=1');
    $stmt->execute([$userId]);
    return $stmt->rowCount();
}

/**
 * Check vehicles for service due (10,000 km interval or 6 months) and notify customer.
 * Throttled: one reminder per vehicle per 7 days.
 */
function checkServiceReminders(int $userId): void
{
    ensureSchemaUpdates();
    $db = getDB();
    $intervalKm = getServiceIntervalKm();
    $intervalMonths = getServiceIntervalMonths();

    $vehicles = $db->prepare('SELECT * FROM vehicles WHERE user_id=?');
    $vehicles->execute([$userId]);
    $user = $db->prepare('SELECT email, full_name FROM users WHERE id=?');
    $user->execute([$userId]);
    $userRow = $user->fetch();
    if (!$userRow) return;

    while ($v = $vehicles->fetch()) {
        $dueReasons = [];
        $lastMileage = $v['last_service_mileage'] !== null ? (int) $v['last_service_mileage'] : null;
        $currentMileage = $v['current_mileage'] !== null ? (int) $v['current_mileage'] : null;
        $lastDate = $v['last_service_date'] ?? null;

        if ($lastMileage !== null && $currentMileage !== null) {
            $nextDueKm = $lastMileage + $intervalKm;
            if ($currentMileage >= $nextDueKm) {
                $dueReasons[] = "mileage reached " . number_format($currentMileage) . " km (next was due at " . number_format($nextDueKm) . " km) / odometer mencapai " . number_format($currentMileage) . " km";
            }
        }

        if ($lastDate) {
            $dueByDate = date('Y-m-d', strtotime($lastDate . ' +' . $intervalMonths . ' months'));
            if (todayDate() >= $dueByDate) {
                $dueReasons[] = "{$intervalMonths} months since last service on {$lastDate} / {$intervalMonths} bulan sejak servis terakhir";
            }
        }

        // Also remind if approaching (within 500km or within 14 days) — soft alert
        $approaching = false;
        if ($lastMileage !== null && $currentMileage !== null) {
            $nextDueKm = $lastMileage + $intervalKm;
            if ($currentMileage >= ($nextDueKm - 500) && $currentMileage < $nextDueKm) {
                $approaching = true;
                $dueReasons[] = "approaching next service at " . number_format($nextDueKm) . " km / hampir ke servis seterusnya pada " . number_format($nextDueKm) . " km";
            }
        }
        if ($lastDate) {
            $dueByDate = date('Y-m-d', strtotime($lastDate . ' +' . $intervalMonths . ' months'));
            $daysLeft = (int) ((strtotime($dueByDate) - strtotime(todayDate())) / 86400);
            if ($daysLeft >= 0 && $daysLeft <= 14 && !$approaching) {
                $dueReasons[] = "service due within {$daysLeft} day(s) by {$dueByDate} / servis dalam {$daysLeft} hari (sebelum {$dueByDate})";
            }
        }

        if (empty($dueReasons)) {
            continue;
        }

        // Throttle: skip if similar alert sent in last 7 days
        $throttle = $db->prepare("
            SELECT id FROM notifications
            WHERE user_id=? AND type='alert' AND title LIKE ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            LIMIT 1
        ");
        $titleHint = '%Service Reminder%' . $v['plate_no'] . '%';
        $throttle->execute([$userId, $titleHint]);
        // Also match plate in message
        $throttle2 = $db->prepare("
            SELECT id FROM notifications
            WHERE user_id=? AND type='alert'
            AND message LIKE ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            LIMIT 1
        ");
        $throttle2->execute([$userId, '%' . $v['plate_no'] . '%']);
        if ($throttle2->fetch()) {
            continue;
        }

        $reason = implode('; ', $dueReasons);
        $title = 'Service Reminder / Peringatan Servis — ' . $v['plate_no'];
        $msg = "Your {$v['brand']} {$v['model_variant']} ({$v['plate_no']}) needs service: {$reason}. Book your next slot now. / Kenderaan anda memerlukan servis. Tempah slot sekarang.";
        notify($userId, $title, $msg, 'alert', baseUrl('user/booking.php'));

        $nextKmHint = $lastMileage !== null ? $lastMileage + $intervalKm : 0;
        $nextDateHint = $lastDate ? date('Y-m-d', strtotime($lastDate . ' +' . $intervalMonths . ' months')) : todayDate();
        sendServiceReminderEmail(
            $userRow['email'],
            $userRow['full_name'],
            $v['plate_no'],
            $currentMileage ?? 0,
            $nextKmHint,
            $nextDateHint,
            true
        );
    }
}

function sendServiceReminderEmail(string $toEmail, string $toName, string $plate, int $currentKm, int $nextDueKm, string $nextDueDate, bool $isDue = false): void
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $subject = $isDue
        ? '[AutoCare Hub] Service Due Reminder / Peringatan Servis — ' . $plate
        : '[AutoCare Hub] Next Service Schedule / Jadual Servis Seterusnya — ' . $plate;

    $body = "Hello {$toName},\n\n";
    if ($isDue) {
        $body .= "This is a reminder that your vehicle {$plate} is due (or nearly due) for service.\n";
        $body .= "Ini adalah peringatan bahawa kenderaan {$plate} anda sudah (atau hampir) tiba masa untuk servis.\n\n";
    } else {
        $body .= "Your recent service for {$plate} is complete.\n";
        $body .= "Servis terkini untuk {$plate} telah selesai.\n\n";
    }
    $body .= "Current / Current mileage: " . number_format($currentKm) . " km\n";
    $body .= "Next service by mileage / Servis seterusnya (km): " . number_format($nextDueKm) . " km\n";
    $body .= "Next service by date / Servis seterusnya (tarikh): {$nextDueDate}\n";
    $body .= "(Every " . getServiceIntervalKm() . " km OR every " . getServiceIntervalMonths() . " months after service)\n\n";
    $body .= "Please log in to AutoCare Hub to book your next service slot.\n";
    $body .= "Sila log masuk AutoCare Hub untuk tempah slot servis seterusnya.\n\n";
    $body .= "— " . APP_NAME . "\n";
    $body .= getWorkshopSetting('workshop_phone', '') . "\n";
    $body .= getWorkshopSetting('workshop_email', 'support@autocarehub.my') . "\n";

    $from = getWorkshopSetting('workshop_email', 'support@autocarehub.my');
    $headers = "From: AutoCare Hub <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    @mail($toEmail, $subject, $body, $headers);
}

function genReceiptNo(): string
{
    return 'ACH-RCP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

function genRefKey(): string
{
    return 'ACH-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

function notify(int $userId, string $title, string $message, string $type = 'info', ?string $link = null): void
{
    $db = getDB();
    $allowed = ['info', 'success', 'warning', 'appointment', 'receipt', 'system', 'alert'];
    if (!in_array($type, $allowed, true)) {
        $type = 'info';
    }
    $db->prepare('INSERT INTO notifications (user_id,title,message,type,link) VALUES (?,?,?,?,?)')
       ->execute([$userId, $title, $message, $type, $link]);
}

/**
 * In-app notification + optional email and SMS.
 */
function notifyWithEmail(
    int $userId,
    string $title,
    string $message,
    string $type = 'info',
    ?string $link = null,
    bool $sendMail = true,
    bool $sendText = false
): void {
    notify($userId, $title, $message, $type, $link);
    if (!$sendMail && !$sendText) {
        return;
    }
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT email, full_name, contact_no FROM users WHERE id=?');
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if (!$u) {
            return;
        }
        if ($sendMail && !empty($u['email'])) {
            sendEmail($u['email'], '[' . APP_NAME . '] ' . $title, $message, (string) ($u['full_name'] ?? ''));
        }
        if ($sendText && !empty($u['contact_no'])) {
            sendSms((string) $u['contact_no'], $title . ': ' . mb_substr(preg_replace('/\s+/', ' ', strip_tags($message)), 0, 140));
        }
    } catch (Throwable $e) {
        // never break primary flow for comms failure
    }
}

function mailConfig(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/../config/mail.php';
        $cfg = is_file($path) ? require $path : [
            'driver' => 'mail',
            'from_email' => 'support@autocarehub.my',
            'from_name' => 'AutoCare Hub',
            'smtp' => [],
        ];
    }
    return $cfg;
}

function smsConfig(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/../config/sms.php';
        $cfg = is_file($path) ? require $path : [
            'driver' => 'log',
            'enabled' => false,
            'sender' => 'AutoCare',
        ];
    }
    return $cfg;
}

/**
 * Production-ready email sender (PHP mail or PHPMailer if available).
 */
function sendEmail(string $to, string $subject, string $body, string $toName = ''): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $cfg = mailConfig();
    $fromEmail = $cfg['from_email'] ?? getWorkshopSetting('workshop_email', 'support@autocarehub.my');
    $fromName = $cfg['from_name'] ?? APP_NAME;
    $driver = $cfg['driver'] ?? 'mail';

    // Prefer PHPMailer when installed via Composer
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if ($driver === 'phpmailer' && is_file($autoload)) {
        require_once $autoload;
        if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $smtp = $cfg['smtp'] ?? [];
                $mail->isSMTP();
                $mail->Host = $smtp['host'] ?? 'localhost';
                $mail->Port = (int) ($smtp['port'] ?? 587);
                $mail->SMTPAuth = !empty($smtp['username']);
                if ($mail->SMTPAuth) {
                    $mail->Username = $smtp['username'];
                    $mail->Password = $smtp['password'] ?? '';
                }
                $enc = $smtp['encryption'] ?? 'tls';
                if ($enc) {
                    $mail->SMTPSecure = $enc;
                }
                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($to, $toName);
                $mail->Subject = $subject;
                $mail->Body = $body;
                $mail->CharSet = 'UTF-8';
                return $mail->send();
            } catch (Throwable $e) {
                // fall through to mail()
            }
        }
    }

    $headers = 'From: ' . $fromName . ' <' . $fromEmail . ">\r\n";
    $headers .= 'Reply-To: ' . $fromEmail . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    return @mail($to, $subject, $body, $headers);
}

/**
 * SMS sender — log driver by default; Twilio / HTTP gateway when configured.
 */
function sendSms(string $phone, string $message): bool
{
    $cfg = smsConfig();
    $phone = preg_replace('/[^\d+]/', '', $phone);
    if ($phone === '' || strlen($phone) < 8) {
        return false;
    }
    // Normalize MY numbers: 01x → +601x
    if (preg_match('/^01\d{8,9}$/', $phone)) {
        $phone = '+6' . $phone;
    } elseif (preg_match('/^1\d{8,9}$/', $phone)) {
        $phone = '+60' . $phone;
    }

    $driver = $cfg['driver'] ?? 'log';
    $enabled = !empty($cfg['enabled']);

    if ($driver === 'log' || !$enabled) {
        $logDir = __DIR__ . '/../storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $line = date('c') . "\t{$phone}\t" . str_replace(["\r", "\n"], ' ', $message) . "\n";
        @file_put_contents($logDir . '/sms.log', $line, FILE_APPEND | LOCK_EX);
        return true;
    }

    if ($driver === 'twilio') {
        $sid = $cfg['twilio']['account_sid'] ?? '';
        $token = $cfg['twilio']['auth_token'] ?? '';
        $from = $cfg['twilio']['from'] ?? '';
        if ($sid === '' || $token === '' || $from === '') {
            return false;
        }
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $post = http_build_query(['To' => $phone, 'From' => $from, 'Body' => $message]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $sid . ':' . $token,
            CURLOPT_TIMEOUT => 15,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    if ($driver === 'http') {
        $url = $cfg['http']['url'] ?? '';
        if ($url === '') {
            return false;
        }
        $payload = [
            'to'      => $phone,
            'message' => $message,
            'sender'  => $cfg['sender'] ?? 'AutoCare',
            'api_key' => $cfg['http']['api_key'] ?? '',
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => strtoupper($cfg['http']['method'] ?? 'POST') === 'POST',
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    return false;
}

/**
 * Secure image upload for job / inspection photos.
 * @return array{ok:bool,error?:string,path?:string}
 */
function handleJobPhotoUpload(array $file, string $subdir = 'job_photos'): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file uploaded. / Tiada fail dimuat naik.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed. / Muat naik gagal.'];
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'File too large (max 5MB). / Fail terlalu besar (maks 5MB).'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG or WEBP allowed. / Hanya JPG, PNG atau WEBP dibenarkan.'];
    }
    $subdir = preg_replace('/[^a-z0-9_]/', '', $subdir) ?: 'job_photos';
    $dir = __DIR__ . '/../uploads/' . $subdir;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $name = 'job_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'error' => 'Could not save file. / Tidak dapat menyimpan fail.'];
    }
    return ['ok' => true, 'path' => 'uploads/' . $subdir . '/' . $name];
}

function getUnreadNotificationCount(int $userId): int
{
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function getUnreadAlertCount(int $userId): int
{
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0 AND type='alert'");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function getUnreadMessageCount(int $userId): int
{
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id=? AND is_read=0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function getWorkshopSetting(string $key, string $default = ''): string
{
    $db = getDB();
    $stmt = $db->prepare('SELECT setting_value FROM workshop_settings WHERE setting_key=?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

function canAddVehicle(int $userId): bool
{
    $max = (int) getWorkshopSetting('max_vehicles_per_customer', '5');
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM vehicles WHERE user_id=?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn() < $max;
}

function canAddBooking(int $userId): bool
{
    $max = (int) getWorkshopSetting('max_bookings_per_month', '10');
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE user_id=? AND status NOT IN ('Cancelled','Completed') AND MONTH(created_at)=MONTH(CURDATE())");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn() < $max;
}

function notificationsPageForRole(string $role): string
{
    return match ($role) {
        'admin'    => baseUrl('admin/notifications.php'),
        'mechanic' => baseUrl('mechanic/notifications.php'),
        default    => baseUrl('user/notifications.php'),
    };
}

function notificationLink(?string $link): string
{
    if (!$link) return '';
    if (preg_match('#^https?://#i', $link)) return $link;
    return baseUrl(ltrim($link, '/'));
}

function notificationTypeBadge(string $type): string
{
    $map = [
        'alert'       => 'badge-cancelled',
        'warning'     => 'badge-pending',
        'success'     => 'badge-approved',
        'appointment' => 'badge-progress',
        'receipt'     => 'badge-completed',
        'system'      => 'badge-settled',
        'info'        => 'badge-settled',
    ];
    $cls = $map[$type] ?? 'badge-settled';
    $label = strtoupper($type === 'alert' ? 'ALERT' : $type);
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

function starRatingHtml(int $rating): string
{
    $html = '<span class="star-rating" aria-label="' . $rating . ' of 5 stars">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= $i <= $rating ? '<span class="star filled">★</span>' : '<span class="star">☆</span>';
    }
    $html .= '</span>';
    return $html;
}

function safeReturnUrl(?string $return, string $fallback): string
{
    if (!$return) return $fallback;
    $return = trim($return);
    if (str_starts_with($return, baseUrl()) || str_starts_with($return, '/')) {
        return $return;
    }
    return $fallback;
}

function getMechanicCommissionTotal(int $mechanicId): float
{
    $db = getDB();
    $stmt = $db->prepare('SELECT COALESCE(SUM(commission_amount),0) FROM service_history WHERE mechanic_id=? AND status="Settled"');
    $stmt->execute([$mechanicId]);
    return (float) $stmt->fetchColumn();
}

function getDashboardStats(?int $userId = null, ?string $role = null): array
{
    $db = getDB();

    $totalClients = (int) $db->query('SELECT COUNT(DISTINCT user_id) FROM vehicles')->fetchColumn();
    if ($role === 'customer') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM vehicles WHERE user_id=?');
        $stmt->execute([$userId]);
        $totalClients = (int) $stmt->fetchColumn();
    }

    $bookingsSql = "SELECT COUNT(*) FROM appointments WHERE status NOT IN ('Cancelled','Completed')";
    if ($role === 'customer') {
        $bookingsSql .= ' AND user_id=' . (int) $userId;
    } elseif ($role === 'mechanic') {
        $bookingsSql .= ' AND paid_at IS NOT NULL';
    }
    $bookings = (int) $db->query($bookingsSql)->fetchColumn();

    $grossIncome = 0.0;
    $commissionTotal = 0.0;
    if ($role === 'customer' && $userId) {
        $incomeSql = 'SELECT COALESCE(SUM(gross_payment),0) FROM service_history WHERE status="Settled" AND user_id=' . (int) $userId;
        $grossIncome = (float) $db->query($incomeSql)->fetchColumn();
    } elseif ($role === 'mechanic' && $userId) {
        $commissionTotal = getMechanicCommissionTotal($userId);
        $incomeSql = 'SELECT COALESCE(SUM(gross_payment),0) FROM service_history WHERE status="Settled" AND mechanic_id=' . (int) $userId;
        $grossIncome = (float) $db->query($incomeSql)->fetchColumn();
    } else {
        $incomeSql = 'SELECT COALESCE(SUM(gross_payment),0) FROM service_history WHERE status="Settled"';
        $grossIncome = (float) $db->query($incomeSql)->fetchColumn();
    }

    $topPkg = $db->query("
        SELECT sp.name FROM service_history sh
        JOIN service_packages sp ON sp.id = sh.package_id
        GROUP BY sh.package_id ORDER BY COUNT(*) DESC LIMIT 1
    ")->fetchColumn() ?: '—';

    $today = todayDate();
    $todaySql = "SELECT COUNT(*) FROM appointments WHERE appointment_date='$today' AND status NOT IN ('Cancelled','Completed')";
    if ($role === 'mechanic') $todaySql .= ' AND paid_at IS NOT NULL';
    $todayAppts = (int) $db->query($todaySql)->fetchColumn();

    $bays = (int) getWorkshopSetting('mechanical_bays', '8');
    $elec = (int) getWorkshopSetting('electrical_units', '4');
    $mechPct = min(100, (int) round(($todayAppts / max(1, $bays)) * 100));

    $elecSql = "SELECT COUNT(*) FROM appointments a JOIN service_packages sp ON sp.id=a.package_id
        WHERE a.appointment_date='$today' AND a.status NOT IN ('Cancelled','Completed')
        AND sp.category='Electrical'";
    if ($role === 'mechanic') $elecSql .= ' AND a.paid_at IS NOT NULL';
    $elecCount = (int) $db->query($elecSql)->fetchColumn();
    $elecPct = min(100, (int) round(($elecCount / max(1, $elec)) * 100));

    // Merge rich progress so callers can use either key set
    $progress = getProgressStats($userId, $role);

    return array_merge(
        compact('totalClients', 'bookings', 'topPkg', 'grossIncome', 'commissionTotal', 'todayAppts', 'mechPct', 'elecPct', 'bays', 'elec'),
        ['progress' => $progress]
    );
}

function baseUrl(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        if (defined('APP_URL') && APP_URL !== '') {
            $base = rtrim(APP_URL, '/');
        } else {
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
            $base = rtrim(dirname($script), '/');
            foreach (['/admin', '/user', '/mechanic', '/auth', '/api', '/exports', '/uploads'] as $sub) {
                if (str_ends_with($base, $sub)) {
                    $base = substr($base, 0, -strlen($sub));
                    break;
                }
            }
        }
    }
    $path = ltrim($path, '/');
    return $path === '' ? $base . '/' : $base . '/' . $path;
}
