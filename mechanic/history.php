<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('mechanic');
$db = getDB();
ensureSchemaUpdates();

$records = $db->prepare("
    SELECT sh.*, v.plate_no, v.owner_name, v.contact_no AS vehicle_phone, sp.name AS pkg_name, u.contact_no AS user_phone
    FROM service_history sh
    JOIN vehicles v ON v.id=sh.vehicle_id
    JOIN service_packages sp ON sp.id=sh.package_id
    LEFT JOIN users u ON u.id=sh.user_id
    WHERE sh.mechanic_id=? ORDER BY sh.completion_date DESC
");
$records->execute([$user['id']]);
$rows = $records->fetchAll();
$totalComm = getMechanicCommissionTotal((int)$user['id']);

renderHeader('Completed Jobs', $user, 'history');
?>
<h2 class="section-title">Completed Jobs / Kerja Selesai</h2>
<p class="section-subtitle">Your service history & commission · Call / WhatsApp customers for follow-up · Total: <strong class="text-emerald-400"><?= formatRM($totalComm) ?></strong></p>
<div class="panel-card p-5">
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Date</th><th>Vehicle</th><th>Contact</th><th>Service</th><th>Job Payment</th><th>Commission</th><th>Rating</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $phone = $r['vehicle_phone'] ?: ($r['user_phone'] ?? '');
      $wa = 'Hi ' . $r['owner_name'] . ', this is AutoCare Hub following up on your ' . $r['pkg_name'] . ' service for ' . $r['plate_no'] . '.';
    ?>
    <tr>
      <td><?= e($r['completion_date']) ?></td>
      <td><?= e($r['plate_no'].' – '.$r['owner_name']) ?></td>
      <td><?= contactPhoneButtons($phone, $wa) ?></td>
      <td><?= e($r['pkg_name']) ?></td>
      <td class="text-slate-300"><?= formatRM($r['gross_payment']) ?></td>
      <td class="text-emerald-400 font-semibold">
        <?= formatRM($r['commission_amount'] ?? 0) ?>
        <span class="text-xs text-slate-500">(<?= e((string)($r['commission_percent'] ?? 0)) ?>%)</span>
      </td>
      <td>
        <?php if (!empty($r['rating'])): ?>
          <?= starRatingHtml((int)$r['rating']) ?>
          <?php if (!empty($r['feedback'])): ?><p class="text-xs text-slate-500"><?= e($r['feedback']) ?></p><?php endif; ?>
        <?php else: ?>
          <span class="text-xs text-slate-500">Not rated yet</span>
        <?php endif; ?>
      </td>
      <td><?= statusBadge($r['status']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?><tr><td colspan="8" class="empty-state">No completed jobs yet</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php renderFooter(); ?>
