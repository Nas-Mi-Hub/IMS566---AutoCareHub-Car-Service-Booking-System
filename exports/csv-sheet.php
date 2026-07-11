<?php
require_once __DIR__ . '/../includes/auth.php';
$user = requireLogin();
if (!in_array($user['role'], ['admin', 'customer'], true)) {
    http_response_code(403); exit;
}

$type = $_GET['type'] ?? 'history';
$db = getDB();
$filename = 'AutoCareHub_' . $type . '_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

if ($type === 'appointments') {
    fputcsv($out, ['Date', 'Plate No', 'Owner', 'Service', 'Time Window', 'Status', 'Price (RM)']);
    $sql = "SELECT a.*, v.plate_no, v.owner_name, sp.name AS pkg_name, sp.price FROM appointments a JOIN vehicles v ON v.id=a.vehicle_id JOIN service_packages sp ON sp.id=a.package_id WHERE a.status != 'Cancelled'";
    if ($user['role'] === 'customer') $sql .= ' AND a.user_id=' . (int)$user['id'];
    foreach ($db->query($sql) as $r) {
        fputcsv($out, [$r['appointment_date'], $r['plate_no'], $r['owner_name'], $r['pkg_name'], $r['time_window'], $r['status'], $r['price']]);
    }
} else {
    fputcsv($out, ['Completion Date', 'Plate No', 'Owner', 'Service', 'Gross Payment (RM)', 'Status']);
    $sql = "SELECT sh.*, v.plate_no, v.owner_name, sp.name AS pkg_name FROM service_history sh JOIN vehicles v ON v.id=sh.vehicle_id JOIN service_packages sp ON sp.id=sh.package_id WHERE sh.status='Settled'";
    if ($user['role'] === 'customer') $sql .= ' AND sh.user_id=' . (int)$user['id'];
    $sql .= ' ORDER BY sh.completion_date DESC';
    foreach ($db->query($sql) as $r) {
        fputcsv($out, [$r['completion_date'], $r['plate_no'], $r['owner_name'], $r['pkg_name'], $r['gross_payment'], $r['status']]);
    }
}
fclose($out);
exit;