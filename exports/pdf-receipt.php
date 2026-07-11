<?php
require_once __DIR__ . '/../includes/auth.php';
$user = requireLogin();
$db = getDB();
ensureSchemaUpdates();

$id = (int)($_GET['id'] ?? 0);
$receiptNo = $_GET['receipt'] ?? genReceiptNo();
$returnUrl = safeReturnUrl($_GET['return'] ?? null, baseUrl('user/receipt.php'));

$stmt = $db->prepare("
    SELECT sh.*, v.plate_no, v.owner_name, v.contact_no, v.brand, v.model_variant, sp.name AS pkg_name, u.full_name AS customer_name,
           a.id AS appointment_id, a.job_notes AS appt_notes
    FROM service_history sh
    JOIN vehicles v ON v.id=sh.vehicle_id
    JOIN service_packages sp ON sp.id=sh.package_id
    JOIN users u ON u.id=sh.user_id
    LEFT JOIN appointments a ON a.id = sh.appointment_id
    WHERE sh.id=?
");
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) { http_response_code(404); echo 'Receipt not found'; exit; }
if ($user['role'] === 'customer' && (int)$r['user_id'] !== (int)$user['id']) { http_response_code(403); exit; }

$parts = [];
if (!empty($r['appointment_id'])) {
    $parts = InventoryService::partsForAppointment((int) $r['appointment_id']);
}
$labor = (float) (!empty($r['labor_total']) ? $r['labor_total'] : $r['gross_payment']);
$partsTotal = (float) ($r['parts_total'] ?? 0);
if ($partsTotal <= 0 && !empty($parts)) {
    foreach ($parts as $p) {
        $partsTotal += (float) $p['total'];
    }
}
if ($partsTotal > 0 && abs($labor - (float)$r['gross_payment']) < 0.01) {
    $labor = max(0, (float)$r['gross_payment'] - $partsTotal);
}
$sst = (float) ($r['sst_amount'] ?? 0);
if ($sst <= 0 && class_exists('PaymentService') && PaymentService::sstEnabled()) {
    $calc = PaymentService::calcTotals($labor, $partsTotal);
    $sst = $calc['sst'];
}
$grand = (float) $r['gross_payment'];
if ($sst > 0 && abs($grand - ($labor + $partsTotal)) < 0.02) {
    $grand = $labor + $partsTotal + $sst;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Receipt — <?= e($receiptNo) ?></title>
<style>
  body{font-family:Arial,sans-serif;max-width:700px;margin:40px auto;color:#333}
  .header{background:#0f172a;color:#fff;padding:20px;border-bottom:3px solid #f97316;text-align:center}
  .header h1{color:#f97316;margin:0}
  .box{border:1px solid #ddd;padding:25px;margin-top:20px}
  .row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0;font-size:13px}
  table.parts{width:100%;border-collapse:collapse;margin-top:12px;font-size:12px}
  table.parts th,table.parts td{border-bottom:1px solid #eee;padding:6px 4px;text-align:left}
  table.parts th{background:#f8fafc;color:#64748b;font-size:11px;text-transform:uppercase}
  .total{background:#f97316;color:#fff;padding:15px;text-align:center;font-size:18px;font-weight:bold;margin-top:15px}
  .footer{text-align:center;font-size:10px;color:#888;margin-top:30px}
  .notes{margin-top:12px;padding:10px;background:#f8fafc;border-radius:6px;font-size:12px;color:#475569}
  @media print{.no-print{display:none}body{margin:20px}}
</style></head><body>
<div class="no-print" style="margin-bottom:15px;display:flex;gap:10px;align-items:center;justify-content:center">
  <a href="<?= e($returnUrl) ?>" style="background:#334155;color:#fff;text-decoration:none;padding:10px 20px;border-radius:5px;font-size:14px">← Back</a>
  <button onclick="window.print()" style="background:#f97316;color:#fff;border:none;padding:10px 20px;cursor:pointer;border-radius:5px">Print / Save as PDF</button>
</div>

<div class="header"><h1>AutoCare Hub</h1><p style="font-size:11px;margin:5px 0 0">Official Service Receipt / Resit Servis Rasmi</p></div>

<div class="box">
  <p style="text-align:center;font-weight:bold;font-size:14px">AUTOCARE HUB RECEIPT VOUCHER</p>
  <p style="text-align:center;font-size:11px;color:#666">Receipt No: <?= e($receiptNo) ?> | Date: <?= e($r['completion_date']) ?></p>
  <div class="row"><span>Customer</span><strong><?= e($r['customer_name']) ?></strong></div>
  <div class="row"><span>Contact</span><span><?= e($r['contact_no']) ?></span></div>
  <div class="row"><span>Vehicle</span><span><?= e($r['plate_no']) ?> — <?= e($r['brand'].' '.$r['model_variant']) ?></span></div>
  <div class="row"><span>Service Rendered</span><strong><?= e($r['pkg_name']) ?></strong></div>
  <div class="row"><span>Completion Date</span><span><?= e($r['completion_date']) ?></span></div>
  <div class="row"><span>Status</span><span><?= e($r['status']) ?></span></div>

  <div class="row"><span>Labor / Buruh (package)</span><span>RM <?= number_format($labor, 2) ?></span></div>

  <?php if (!empty($parts)): ?>
  <p style="font-size:12px;font-weight:bold;margin:14px 0 4px">Parts breakdown / Pecahan alat ganti</p>
  <table class="parts">
    <thead><tr><th>Part</th><th>Qty</th><th>Unit</th><th>Total</th></tr></thead>
    <tbody>
    <?php foreach ($parts as $p): ?>
      <tr>
        <td><?= e($p['name']) ?><?php if (!empty($p['sku'])): ?> <span style="color:#94a3b8">(<?= e($p['sku']) ?>)</span><?php endif; ?></td>
        <td><?= (int)$p['qty'] ?></td>
        <td>RM <?= number_format($p['unit_price_at_time'], 2) ?></td>
        <td>RM <?= number_format($p['total'], 2) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="row"><span>Parts total</span><span>RM <?= number_format($partsTotal, 2) ?></span></div>
  <?php elseif ($partsTotal > 0): ?>
  <div class="row"><span>Parts total</span><span>RM <?= number_format($partsTotal, 2) ?></span></div>
  <?php endif; ?>

  <?php if ($sst > 0): ?>
  <div class="row"><span>SST</span><span>RM <?= number_format($sst, 2) ?></span></div>
  <?php endif; ?>

  <?php
    $notes = $r['job_notes'] ?? $r['appt_notes'] ?? '';
    if ($notes):
  ?>
  <div class="notes"><strong>Job notes:</strong><br><?= nl2br(e($notes)) ?></div>
  <?php endif; ?>

  <div class="total">Gross Payment: RM <?= number_format($grand, 2) ?></div>
</div>

<div class="footer">
  <p><?= e(getWorkshopSetting('workshop_name')) ?> | <?= e(getWorkshopSetting('workshop_address')) ?></p>
  <p>Tel: <?= e(getWorkshopSetting('workshop_phone')) ?> | <?= e(getWorkshopSetting('workshop_email')) ?></p>
  <p style="color:#f97316;font-weight:bold;margin-top:10px">AUTO CARE HUB — AUTHORIZED</p>
</div>
</body></html>
