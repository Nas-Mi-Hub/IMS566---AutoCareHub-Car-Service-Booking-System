<?php
/**
 * Live service slot availability API
 * GET ?date=YYYY-MM-DD
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$user = currentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$date = trim($_GET['date'] ?? todayDate());
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date']);
    exit;
}

ensureSchemaUpdates();
$slots = getSlotAvailability($date);
$max = getMaxBookingsPerSlot();

echo json_encode([
    'date'        => $date,
    'max_per_slot'=> $max,
    'slots'       => $slots,
    'updated_at'  => date('c'),
]);
