<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('admin');
$stats = getDashboardStats();
$db = getDB();
ensureSchemaUpdates();
$lowStock = InventoryService::lowStockParts();
$pipeline = workflowPipelineStats(null, 'admin');
$progress = getProgressStats(null, 'admin');
$kpiCards = progressKpiCardsForRole($progress, 'admin');

$intake = $db->query("
    SELECT a.*, v.plate_no, v.owner_name, v.contact_no, sp.name AS pkg_name
    FROM appointments a
    JOIN vehicles v ON v.id = a.vehicle_id
    JOIN service_packages sp ON sp.id = a.package_id
    WHERE a.status NOT IN ('Cancelled','Completed')
    ORDER BY a.appointment_date, a.time_window LIMIT 10
")->fetchAll();

$newInquiries = 0;
try {
    $newInquiries = (int) $db->query("SELECT COUNT(*) FROM contact_submissions WHERE status='new'")->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

$refundPending = 0;
try {
    $refundPending = (int) $db->query("SELECT COUNT(*) FROM appointments WHERE refund_status='requested'")->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

$chartApi = baseUrl('api/chart-data.php');
renderHeader('Admin Dashboard', $user, 'dashboard');
?>
<div data-chart-api="<?= e($chartApi) ?>">
<h2 class="section-title">Operations Dashboard</h2>
<p class="section-subtitle">Live workshop overview · charts auto-refresh every 8s · toggle theme anytime</p>

<?= workflowPipelineHtml($pipeline, 'Booking pipeline · Booked → Quote → Paid → Working → Done') ?>
<?= progressKpiHtml($kpiCards, 'Workshop progress · cars booked, services done, revenue & profit') ?>

<?php if (!empty($lowStock) || $newInquiries || $refundPending): ?>
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
  <?php if (!empty($lowStock)): ?>
  <a href="<?= baseUrl('admin/inventory.php') ?>" class="alert-chip flash-error">
    <strong><?= count($lowStock) ?> low-stock</strong>
    <span><?= e(implode(', ', array_map(fn($p) => $p['name'], array_slice($lowStock, 0, 3)))) ?></span>
  </a>
  <?php endif; ?>
  <?php if ($newInquiries): ?>
  <a href="<?= baseUrl('admin/contact.php?filter=new') ?>" class="alert-chip flash-info">
    <strong><?= $newInquiries ?> new inquiries</strong>
    <span>Contact form messages waiting</span>
  </a>
  <?php endif; ?>
  <?php if ($refundPending): ?>
  <a href="<?= baseUrl('admin/appointments.php?status=Refund') ?>" class="alert-chip flash-error">
    <strong><?= $refundPending ?> refund request(s)</strong>
    <span>Review in Appointments</span>
  </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
  <div class="xl:col-span-2 panel-card p-5 collapsible-panel is-open" data-collapsible="open">
    <div class="flex items-center justify-between mb-2">
      <h3 class="panel-heading">Earnings vs Costs / Profit (P&amp;L)</h3>
      <button type="button" class="btn-secondary" data-collapse-toggle style="padding:.25rem .6rem;font-size:.7rem" aria-expanded="true">Collapse</button>
    </div>
    <p class="text-xs app-muted mb-2">Revenue − (parts cost + commissions + refunds)</p>
    <div data-collapse-body class="collapse-body">
      <div class="chart-container chart-tall"><canvas id="chart-pnl"></canvas></div>
    </div>
  </div>
  <div class="panel-card p-5">
    <h3 class="panel-heading mb-3">Appointment Status</h3>
    <div class="chart-container"><canvas id="chart-status"></canvas></div>
  </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
  <div class="panel-card p-5">
    <h3 class="panel-heading mb-3">Monthly Revenue Trend</h3>
    <div class="chart-container"><canvas id="chart-income"></canvas></div>
  </div>
  <div class="panel-card p-5">
    <h3 class="panel-heading mb-3">Top Services</h3>
    <div class="chart-container"><canvas id="chart-services"></canvas></div>
  </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
  <div class="xl:col-span-2 panel-card p-5">
    <h3 class="panel-heading mb-3">Active Service Intake</h3>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Plate</th><th>Client</th><th>Service</th><th>Status</th><th>Progress</th></tr></thead>
      <tbody>
      <?php foreach ($intake as $r): ?>
        <tr>
          <td class="font-semibold app-heading"><?= e($r['plate_no']) ?></td>
          <td><?= e($r['owner_name']) ?><br><span class="text-xs app-muted"><?= e($r['contact_no']) ?></span>
            <div class="mt-1"><?= contactPhoneButtons($r['contact_no'] ?? '', 'Hi ' . $r['owner_name'] . ', AutoCare Hub regarding ' . $r['plate_no']) ?></div>
          </td>
          <td><?= e($r['pkg_name']) ?></td>
          <td><?= statusBadge($r['status'], $r) ?></td>
          <td><?= workflowProgressHtml($r, 'compact') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($intake)): ?><tr><td colspan="5" class="empty-state">No active intake</td></tr><?php endif; ?>
      </tbody>
    </table></div>
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
    <div class="mt-5 grid grid-cols-2 gap-2">
      <a href="<?= baseUrl('admin/appointments.php') ?>" class="quick-tile">Appointments</a>
      <a href="<?= baseUrl('admin/inventory.php') ?>" class="quick-tile">Inventory</a>
      <a href="<?= baseUrl('admin/contact.php') ?>" class="quick-tile">Inquiries</a>
      <a href="<?= baseUrl('admin/reports.php') ?>" class="quick-tile">Reports</a>
    </div>
  </div>
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
