<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

$user = requireLogin();
$input = json_decode(file_get_contents('php://input'), true);
$historyId = (int) ($input['history_id'] ?? 0);

$db = getDB();
$stmt = $db->prepare("
    SELECT sh.*, v.plate_no, v.owner_name, v.brand, v.model_variant, sp.name AS service_name
    FROM service_history sh
    JOIN vehicles v ON v.id = sh.vehicle_id
    JOIN service_packages sp ON sp.id = sh.package_id
    WHERE sh.id = ?
");
$stmt->execute([$historyId]);
$record = $stmt->fetch();

if (!$record) {
    echo json_encode(['success' => false, 'message' => 'Record not found']);
    exit;
}

if ($user['role'] === 'customer' && (int)$record['user_id'] !== (int)$user['id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$receiptNo = genReceiptNo();
$db->prepare('INSERT INTO receipts (history_id, receipt_no, requested_by) VALUES (?,?,?)')
   ->execute([$historyId, $receiptNo, $user['id']]);

notify((int)$record['user_id'], 'Receipt Generated', "Receipt $receiptNo is ready for download.", 'receipt', baseUrl('user/receipt.php'));

echo json_encode([
    'success'      => true,
    'receipt_no'   => $receiptNo,
    'vehicle'      => $record['plate_no'] . ' – ' . $record['owner_name'],
    'service'      => $record['service_name'],
    'amount'       => formatRM($record['gross_payment']),
    'date'         => $record['completion_date'],
    'download_url' => baseUrl('exports/pdf-receipt.php?id=' . $historyId . '&receipt=' . urlencode($receiptNo) . '&return=' . urlencode(baseUrl('user/receipt.php'))),
]);