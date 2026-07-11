<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('customer');
$db = getDB();

$records = $db->prepare("
    SELECT sh.*, v.plate_no, v.owner_name, sp.name AS pkg_name, r.receipt_no
    FROM service_history sh
    JOIN vehicles v ON v.id=sh.vehicle_id
    JOIN service_packages sp ON sp.id=sh.package_id
    LEFT JOIN receipts r ON r.history_id=sh.id
    WHERE sh.user_id=?
    ORDER BY sh.completion_date DESC
");
$records->execute([$user['id']]);

renderHeader('Receipts', $user, 'receipt');
?>
<h2 class="section-title">Receipts</h2>
<p class="section-subtitle">View and download receipts for your paid services</p>
<div class="panel-card p-5">
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Date</th><th>Vehicle</th><th>Service</th><th>Amount</th><th>Receipt No</th><th>Action</th></tr></thead>
    <tbody>
    <?php while ($r = $records->fetch()): ?>
    <tr>
      <td><?= e($r['completion_date']) ?></td>
      <td><?= e($r['plate_no']) ?></td>
      <td><?= e($r['pkg_name']) ?></td>
      <td class="text-emerald-400 font-semibold"><?= formatRM($r['gross_payment']) ?></td>
      <td><?= $r['receipt_no'] ? e($r['receipt_no']) : '<span class="text-slate-500">—</span>' ?></td>
      <td>
        <button class="btn-primary" style="font-size:.7rem;padding:.35rem .7rem;width:auto" onclick="requestReceipt(<?= $r['id'] ?>, '<?= rtrim(baseUrl(), '/') ?>/')">Request Receipt</button>
        <?php if ($r['receipt_no']): ?>
        <a href="<?= baseUrl('exports/pdf-receipt.php?id='.$r['id'].'&receipt='.urlencode($r['receipt_no']).'&return='.urlencode(baseUrl('user/receipt.php'))) ?>" class="btn-secondary">View PDF</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
  </table></div>
</div>
<?php renderFooter(); ?>