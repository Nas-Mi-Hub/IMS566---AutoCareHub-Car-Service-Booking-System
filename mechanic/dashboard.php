<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('mechanic');
ensureSchemaUpdates();
$stats = getDashboardStats($user['id'], 'mechanic');
$db = getDB();
$commPct = getMechanicCommissionPercent();
$pipeline = workflowPipelineStats(null, 'mechanic');
$progress = getProgressStats((int) $user['id'], 'mechanic');
$kpiCards = progressKpiCardsForRole($progress, 'mechanic');

$jobs = $db->query("
    SELECT a.*, v.plate_no, v.owner_name, v.contact_no AS vehicle_phone, v.brand, v.model_variant, sp.name AS pkg_name, sp.price
    FROM appointments a
    JOIN vehicles v ON v.id=a.vehicle_id
    JOIN service_packages sp ON sp.id=a.package_id
    WHERE a.status NOT IN ('Cancelled','Completed')
    ORDER BY
      CASE WHEN a.paid_at IS NULL THEN 0 ELSE 1 END,
      a.appointment_date, a.time_window
    LIMIT 10
")->fetchAll();

$needQuote = 0;
$readyWork = 0;
foreach ($jobs as $j) {
    if (empty($j['quote_amount']) || (float)$j['quote_amount'] <= 0) $needQuote++;
    if (!empty($j['paid_at']) && $j['status'] !== 'Completed') $readyWork++;
}

$chartApi = baseUrl('api/chart-data.php');
renderHeader('Mechanic Dashboard', $user, 'dashboard');
?>
<div data-chart-api="<?= e($chartApi) ?>">
<h2 class="section-title">Mechanic Dashboard</h2>
<p class="section-subtitle"><?= e($user['full_name']) ?> · Commission <?= e((string)$commPct) ?>% · live charts</p>

<?= workflowPipelineHtml($pipeline, 'Workshop pipeline · Booked → Quote → Paid → Working → Done') ?>
<?= progressKpiHtml($kpiCards, 'My progress · cars booked, services done & commission earned') ?>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
  <div class="stat-card">
    <p class="stat-label">Awaiting your quote</p>
    <p class="stat-value text-xl text-accent-val"><?= $needQuote ?></p>
  </div>
  <div class="stat-card">
    <p class="stat-label">Paid — ready to work</p>
    <p class="stat-value text-xl text-emerald"><?= $readyWork ?></p>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
  <div class="panel-card p-5">
    <h3 class="panel-heading mb-3">Job Value & Commission Trend</h3>
    <div class="chart-container"><canvas id="chart-income"></canvas></div>
  </div>
  <div class="panel-card p-5">
    <h3 class="panel-heading mb-3">Job Status Breakdown</h3>
    <div class="chart-container"><canvas id="chart-status"></canvas></div>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
  <div class="panel-card p-5">
    <h3 class="panel-heading mb-3">Top Services (workshop)</h3>
    <div class="chart-container"><canvas id="chart-services"></canvas></div>
  </div>
  <div class="panel-card p-5">
    <h3 class="panel-heading mb-4">Workshop Capacity</h3>
    <div class="space-y-4">
      <div>
        <div class="flex justify-between text-xs mb-1"><span class="app-muted">Mechanical Bays</span><span class="text-accent font-semibold" id="live-mech-pct"><?= (int)$stats['mechPct'] ?>%</span></div>
        <div class="progress-bar-track"><div class="progress-bar-fill progress-accent" id="live-mech-bar" style="width:<?= (int)$stats['mechPct'] ?>%"></div></div>
      </div>
      <div>
        <div class="flex justify-between text-xs mb-1"><span class="app-muted">Electrical Units</span><span class="text-sky font-semibold" id="live-elec-pct"><?= (int)$stats['elecPct'] ?>%</span></div>
        <div class="progress-bar-track"><div class="progress-bar-fill progress-sky" id="live-elec-bar" style="width:<?= (int)$stats['elecPct'] ?>%"></div></div>
      </div>
    </div>
    <p class="text-sm app-muted mt-4">You earn <strong class="text-emerald"><?= e((string)$commPct) ?>%</strong> of each completed job. Example: RM 200 → <?= formatRM(200 * $commPct / 100) ?></p>
    <div class="mt-4 grid grid-cols-2 gap-2">
      <a href="<?= baseUrl('mechanic/appointments.php') ?>" class="quick-tile">Workshop Jobs</a>
      <a href="<?= baseUrl('mechanic/commission.php') ?>" class="quick-tile">Commission</a>
    </div>
  </div>
</div>

<div class="panel-card p-5">
  <h3 class="panel-heading mb-3">Queue — quotes & paid jobs</h3>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Plate</th><th>Client / Contact</th><th>Service</th><th>Est. Commission</th><th>Progress</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j):
      $phone = $j['vehicle_phone'] ?? '';
      $base = !empty($j['quote_amount']) ? (float)$j['quote_amount'] : (float)$j['price'];
    ?>
    <tr>
      <td class="app-heading font-semibold"><?= e($j['plate_no']) ?></td>
      <td>
        <?= e($j['owner_name']) ?>
        <div class="mt-1"><?= contactPhoneButtons($phone, 'Hi ' . $j['owner_name'] . ', AutoCare Hub regarding ' . $j['plate_no']) ?></div>
      </td>
      <td><?= e($j['pkg_name']) ?></td>
      <td class="text-emerald text-sm"><?= formatRM($base * $commPct / 100) ?></td>
      <td><?= workflowProgressHtml($j, 'compact') ?></td>
      <td><a class="btn-primary" style="padding:.3rem .55rem;font-size:.7rem" href="<?= baseUrl('mechanic/job.php?id=' . (int)$j['id']) ?>">Job Card</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($jobs)): ?><tr><td colspan="6" class="empty-state">No open jobs</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof initDashboardCharts === 'function') {
    initDashboardCharts(<?= json_encode($chartApi) ?>);
  }
});
</script>
<?php renderFooter(true); ?>
