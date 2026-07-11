<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('admin');
$db = getDB();
ensureSchemaUpdates();

$filter = trim($_GET['q'] ?? '');
$sql = "SELECT sh.*, v.plate_no, v.owner_name, v.brand, v.model_variant, v.contact_no AS vehicle_phone,
               sp.name AS pkg_name, m.full_name AS mechanic_name, cu.contact_no AS user_phone
    FROM service_history sh
    JOIN vehicles v ON v.id=sh.vehicle_id
    JOIN service_packages sp ON sp.id=sh.package_id
    LEFT JOIN users m ON m.id=sh.mechanic_id
    LEFT JOIN users cu ON cu.id=sh.user_id
    WHERE 1=1";
$params = [];
if ($filter) { $sql .= ' AND (v.plate_no LIKE ? OR sp.name LIKE ? OR v.owner_name LIKE ?)'; $q = "%$filter%"; $params = [$q,$q,$q]; }
$sql .= ' ORDER BY sh.completion_date DESC';
$stmt = $db->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();

renderHeader('Service History', $user, 'history');
?>
<h2 class="section-title">Service History</h2>
<p class="section-subtitle">Archived services — commissions, ratings · Call / WhatsApp customers for follow-up</p>
<div class="panel-card p-5">
  <form method="GET" class="mb-4"><input class="form-input" name="q" placeholder="Filter by plate, owner or package…" value="<?= e($filter) ?>"></form>
  <div class="flex gap-2 mb-4">
    <a href="<?= baseUrl('exports/pdf-report.php?type=history&return='.urlencode(baseUrl('admin/history.php'))) ?>" class="btn-primary" style="width:auto;font-size:.8rem;padding:.5rem 1rem">View PDF</a>
    <a href="<?= baseUrl('exports/csv-sheet.php?type=history') ?>" class="btn-secondary">Download CSV Sheet</a>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Date</th><th>Vehicle</th><th>Contact</th><th>Service</th><th>Payment</th><th>Commission</th><th>Mechanic</th><th>Rating</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $phone = $r['vehicle_phone'] ?: ($r['user_phone'] ?? '');
      $wa = 'Hi ' . $r['owner_name'] . ', this is AutoCare Hub regarding your completed ' . $r['pkg_name'] . ' for ' . $r['plate_no'] . '.';
    ?>
    <tr>
      <td><?= e($r['completion_date']) ?></td>
      <td><?= e($r['plate_no'].' – '.$r['owner_name']) ?></td>
      <td><?= contactPhoneButtons($phone, $wa) ?></td>
      <td><?= e($r['pkg_name']) ?></td>
      <td class="text-emerald-400 font-semibold"><?= formatRM($r['gross_payment']) ?></td>
      <td class="text-sm"><?= formatRM($r['commission_amount'] ?? 0) ?> <span class="text-xs text-slate-500">(<?= e((string)($r['commission_percent'] ?? 0)) ?>%)</span></td>
      <td class="text-sm"><?= e($r['mechanic_name'] ?? '—') ?></td>
      <td>
        <?php if (!empty($r['rating'])): ?>
          <?= starRatingHtml((int)$r['rating']) ?>
          <?php if (!empty($r['feedback'])): ?><p class="text-xs text-slate-500"><?= e($r['feedback']) ?></p><?php endif; ?>
        <?php else: ?><span class="text-xs text-slate-500">—</span><?php endif; ?>
      </td>
      <td><?= statusBadge($r['status']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?><tr><td colspan="9" class="empty-state">No history yet</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php renderFooter(); ?>
