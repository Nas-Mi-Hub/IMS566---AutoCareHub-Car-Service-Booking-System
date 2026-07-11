<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('mechanic');
$db = getDB();
ensureSchemaUpdates();

$commPct = getMechanicCommissionPercent();
$uid = (int) $user['id'];

$totalComm = getMechanicCommissionTotal($uid);
$jobCount = (int) $db->prepare('SELECT COUNT(*) FROM service_history WHERE mechanic_id=? AND status="Settled"')->execute([$uid]) ?: 0;
$stmt = $db->prepare('SELECT COUNT(*) FROM service_history WHERE mechanic_id=? AND status="Settled"');
$stmt->execute([$uid]);
$jobCount = (int) $stmt->fetchColumn();

$monthComm = $db->prepare("SELECT COALESCE(SUM(commission_amount),0) FROM service_history WHERE mechanic_id=? AND status='Settled' AND DATE_FORMAT(completion_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')");
$monthComm->execute([$uid]);
$monthComm = (float) $monthComm->fetchColumn();

$jobs = $db->prepare("
    SELECT sh.*, v.plate_no, v.owner_name, sp.name AS pkg_name
    FROM service_history sh
    JOIN vehicles v ON v.id = sh.vehicle_id
    JOIN service_packages sp ON sp.id = sh.package_id
    WHERE sh.mechanic_id = ? AND sh.status = 'Settled'
    ORDER BY sh.completion_date DESC
    LIMIT 50
");
$jobs->execute([$uid]);
$jobs = $jobs->fetchAll();

// Monthly breakdown last 6 months
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $label = date('M Y', strtotime("-$i months"));
    $s = $db->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(commission_amount),0) AS comm, COALESCE(SUM(gross_payment),0) AS gross
                       FROM service_history WHERE mechanic_id=? AND status='Settled' AND DATE_FORMAT(completion_date,'%Y-%m')=?");
    $s->execute([$uid, $m]);
    $row = $s->fetch() ?: ['c' => 0, 'comm' => 0, 'gross' => 0];
    $months[] = ['label' => $label, 'jobs' => (int)$row['c'], 'commission' => (float)$row['comm'], 'gross' => (float)$row['gross']];
}

renderHeader('My Commission', $user, 'commission');
?>
<h2 class="section-title">Commission / Komisen</h2>
<p class="section-subtitle">Rate: <?= e((string)$commPct) ?>% of each completed job · <?= e($user['full_name']) ?></p>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
  <div class="stat-card">
    <p class="text-xs text-slate-400 uppercase">Cars serviced</p>
    <p class="text-3xl font-bold text-white"><?= $jobCount ?></p>
  </div>
  <div class="stat-card">
    <p class="text-xs text-slate-400 uppercase">Total commission earned</p>
    <p class="text-3xl font-bold text-emerald-400"><?= formatRM($totalComm) ?></p>
  </div>
  <div class="stat-card">
    <p class="text-xs text-slate-400 uppercase">This month</p>
    <p class="text-3xl font-bold text-accent"><?= formatRM($monthComm) ?></p>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
  <div class="panel-card p-5">
    <h3 class="text-sm font-semibold mb-3">Monthly breakdown</h3>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Month</th><th>Jobs</th><th>Job value</th><th>Your commission</th></tr></thead>
      <tbody>
      <?php foreach ($months as $m): ?>
        <tr>
          <td><?= e($m['label']) ?></td>
          <td><?= (int)$m['jobs'] ?></td>
          <td><?= formatRM($m['gross']) ?></td>
          <td class="text-emerald-400"><?= formatRM($m['commission']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="panel-card p-5">
    <h3 class="text-sm font-semibold mb-3">Commission trend</h3>
    <div class="chart-container"><canvas id="chart-income"></canvas></div>
  </div>
</div>

<div class="panel-card p-5">
  <h3 class="text-sm font-semibold mb-3">Completed jobs (latest 50)</h3>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Date</th><th>Plate</th><th>Service</th><th>Payment</th><th>Commission</th><th>Rating</th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j): ?>
    <tr>
      <td><?= e($j['completion_date']) ?></td>
      <td><?= e($j['plate_no']) ?></td>
      <td><?= e($j['pkg_name']) ?></td>
      <td><?= formatRM($j['gross_payment']) ?></td>
      <td class="text-emerald-400 font-semibold"><?= formatRM($j['commission_amount']) ?> <span class="text-xs text-slate-500">(<?= e((string)$j['commission_percent']) ?>%)</span></td>
      <td><?= !empty($j['rating']) ? starRatingHtml((int)$j['rating']) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($jobs)): ?><tr><td colspan="6" class="empty-state">No completed jobs yet</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<script>document.addEventListener('DOMContentLoaded',()=>initDashboardCharts('<?= baseUrl('api/chart-data.php') ?>'));</script>
<?php renderFooter(true); ?>
