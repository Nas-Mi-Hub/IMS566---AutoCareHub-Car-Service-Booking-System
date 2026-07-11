<?php
require_once __DIR__ . '/../includes/auth.php';
$user = requireRole('admin');

$type = $_GET['type'] ?? 'operations';
$requester = $_GET['requester'] ?? 'SYSTEM ADMINISTRATOR';
$vehicleId = (int)($_GET['vehicle_id'] ?? 0);
$returnUrl = safeReturnUrl($_GET['return'] ?? null, baseUrl('admin/reports.php'));
$db = getDB();
$refKey = genRefKey();
$now = date('d F Y, H:i');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>AutoCare Hub Report — <?= e($refKey) ?></title>
<style>
  body{font-family:Arial,sans-serif;margin:40px;color:#333}
  .header{background:#0f172a;color:#fff;padding:20px 30px;border-bottom:3px solid #f97316}
  .header h1{color:#f97316;margin:0;font-size:24px}
  .header p{margin:5px 0 0;font-size:11px;color:#ccc}
  .title{margin:25px 0 10px;font-size:16px;font-weight:bold}
  .subtitle{color:#666;font-size:12px;margin-bottom:20px}
  .meta{font-size:11px;margin-bottom:20px;display:grid;grid-template-columns:1fr 1fr;gap:5px}
  table{width:100%;border-collapse:collapse;font-size:11px;margin:15px 0}
  th{background:#0f172a;color:#f97316;padding:8px;text-align:left}
  td{padding:7px 8px;border-bottom:1px solid #eee}
  tr:nth-child(even){background:#f8fafc}
  .total{background:#f97316;color:#fff;padding:12px 15px;font-weight:bold;font-size:14px;margin-top:15px}
  .footer{margin-top:40px;border-top:1px solid #ddd;padding-top:15px;font-size:10px;color:#888}
  .footer .cert{color:#f97316;font-weight:bold;margin-top:10px}
  @media print{body{margin:20px}.no-print{display:none}}
</style></head><body>
<div class="no-print" style="margin-bottom:15px;display:flex;gap:10px;align-items:center">
  <a href="<?= e($returnUrl) ?>" style="background:#334155;color:#fff;text-decoration:none;padding:10px 20px;border-radius:5px;font-size:14px">← Back</a>
  <button onclick="window.print()" style="background:#f97316;color:#fff;border:none;padding:10px 20px;cursor:pointer;border-radius:5px">Print / Save as PDF</button>
</div>

<div class="header">
  <h1>AutoCare Hub</h1>
  <p>Vehicle Service Appointment & Customer Management System</p>
</div>

<p class="title">AUTOCARE HUB REPORT</p>
<p class="subtitle">System Operation Authorization Invoice Voucher</p>

<div class="meta">
  <div><strong>Date:</strong> <?= e($now) ?></div>
  <div><strong>Requested by:</strong> <?= e($requester) ?></div>
  <div><strong>Document Ref:</strong> <?= e($refKey) ?></div>
  <div><strong>Verified Vehicle Client:</strong> <?php
    if ($vehicleId) { $v = $db->prepare('SELECT plate_no, owner_name FROM vehicles WHERE id=?'); $v->execute([$vehicleId]); $v=$v->fetch(); echo e($v['plate_no'].' – '.$v['owner_name']); }
    else echo 'All Clients';
  ?></div>
</div>

<table>
<thead><tr>
<?php if ($type === 'appointments'): ?>
  <th>Date</th><th>Vehicle</th><th>Service</th><th>Time</th><th>Status</th>
<?php else: ?>
  <th>Completion Date</th><th>Vehicle</th><th>Service Rendered</th><th>Gross Payment (RM)</th><th>Status</th>
<?php endif; ?>
</tr></thead>
<tbody>
<?php
$total = 0;
if ($type === 'appointments') {
    $sql = "SELECT a.*, v.plate_no, sp.name AS pkg_name, sp.price FROM appointments a JOIN vehicles v ON v.id=a.vehicle_id JOIN service_packages sp ON sp.id=a.package_id WHERE a.status NOT IN ('Cancelled','Completed')";
    if ($vehicleId) $sql .= " AND a.vehicle_id=$vehicleId";
    foreach ($db->query($sql) as $r) {
        $total += $r['price'];
        echo '<tr><td>'.e($r['appointment_date']).'</td><td>'.e($r['plate_no']).'</td><td>'.e($r['pkg_name']).'</td><td>'.e($r['time_window']).'</td><td>'.e($r['status']).'</td></tr>';
    }
} else {
    $sql = "SELECT sh.*, v.plate_no, v.owner_name, sp.name AS pkg_name FROM service_history sh JOIN vehicles v ON v.id=sh.vehicle_id JOIN service_packages sp ON sp.id=sh.package_id WHERE sh.status='Settled'";
    if ($vehicleId) $sql .= " AND sh.vehicle_id=$vehicleId";
    $sql .= ' ORDER BY sh.completion_date DESC';
    foreach ($db->query($sql) as $r) {
        $total += $r['gross_payment'];
        echo '<tr><td>'.e($r['completion_date']).'</td><td>'.e($r['plate_no'].' – '.$r['owner_name']).'</td><td>'.e($r['pkg_name']).'</td><td>'.number_format($r['gross_payment'],2).'</td><td>'.e($r['status']).'</td></tr>';
    }
}
if ($total == 0) echo '<tr><td colspan="5" style="text-align:center;color:#999">No records found</td></tr>';
?>
</tbody>
</table>

<div class="total"><?= $type==='appointments'?'Total Projected Income':'Total Operations Income' ?>: RM <?= number_format($total, 2) ?></div>

<div class="footer">
  <p><em>System Endorsement Certification</em></p>
  <p>This document is electronically generated and authorized by the AutoCare Hub Management System.</p>
  <p>Verified and certified for operational records. No physical signature required.</p>
  <p class="cert">AUTO CARE HUB — AUTHORIZED</p>
</div>
</body></html>