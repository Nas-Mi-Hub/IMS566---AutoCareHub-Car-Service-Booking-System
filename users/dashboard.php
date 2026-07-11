<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('customer');
ensureSchemaUpdates();
checkServiceReminders((int) $user['id']);
$db = getDB();
$pipeline = workflowPipelineStats((int) $user['id'], 'customer');
$progress = getProgressStats((int) $user['id'], 'customer');
$kpiCards = progressKpiCardsForRole($progress, 'customer');
$alertCount = getUnreadAlertCount((int)$user['id']);
$intervalKm = getServiceIntervalKm();

$appts = $db->prepare("
    SELECT a.*, v.plate_no, sp.name AS pkg_name
    FROM appointments a
    JOIN vehicles v ON v.id=a.vehicle_id
    JOIN service_packages sp ON sp.id=a.package_id
    WHERE a.user_id=? AND a.status NOT IN ('Cancelled','Completed')
    ORDER BY a.appointment_date LIMIT 8
");
$appts->execute([$user['id']]);
$appts = $appts->fetchAll();

$awaitingQuote = 0;
$awaitingPay = 0;
foreach ($appts as $a) {
    if (empty($a['paid_at']) && (empty($a['quote_amount']) || (float)$a['quote_amount'] <= 0)) $awaitingQuote++;
    if (empty($a['paid_at']) && !empty($a['quote_amount']) && (float)$a['quote_amount'] > 0) $awaitingPay++;
}

// Vehicles needing service soon
$vehicles = $db->prepare('SELECT * FROM vehicles WHERE user_id=? ORDER BY created_at DESC LIMIT 5');
$vehicles->execute([(int)$user['id']]);
$vehicles = $vehicles->fetchAll();

$chartApi = baseUrl('api/chart-data.php');
$workshopPhone = getWorkshopSetting('workshop_phone', '03-1234 5678');
renderHeader('My Dashboard', $user, 'dashboard');
?>
<div data-chart-api="<?= e($chartApi) ?>">
<h2 class="section-title">Welcome, <?= e(explode(' ', $user['full_name'])[0]) ?></h2>
<p class="section-subtitle">Your vehicle service hub · charts update live</p>

<?= workflowPipelineHtml($pipeline, 'My service progress · Booked → Quote → Paid → Working → Done') ?>
<?= progressKpiHtml($kpiCards, 'My progress · bookings, services done & spending') ?>

<?php if ($alertCount > 0): ?>
<div class="mb-4 p-3 rounded-lg flash-info flash-banner flex flex-wrap items-center justify-between gap-2">
  <span>⚠ You have <?= (int)$alertCount ?> service alert(s)</span>
  <a href="<?= baseUrl('user/notifications.php?filter=alert') ?>" class="btn-secondary" style="padding:.3rem .7rem;font-size:.75rem">View Alerts</a>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
  <div class="stat-card">
    <p class="stat-label">Waiting for quote</p>
    <p class="stat-value text-xl text-accent-val"><?= $awaitingQuote ?></p>
  </div>
  <div class="stat-card">
    <p class="stat-label">Quote ready — pay now</p>
    <p class="stat-value text-xl text-emerald"><?= $awaitingPay ?></p>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
  <div class="panel-card p-5">
    <h3 class="panel-heading mb-3">My Spending Trend (RM)</h3>
    <div class="chart-container"><canvas id="chart-income"></canvas></div>
  </div>
  <div class="panel-card p-5">
    <h3 class="panel-heading mb-3">Appointment Status</h3>
    <div class="chart-container"><canvas id="chart-status"></canvas></div>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
  <div class="panel-card p-5">
    <h3 class="panel-heading mb-3">Services I book most</h3>
    <div class="chart-container"><canvas id="chart-services"></canvas></div>
  </div>
  <div class="lg:col-span-2 panel-card p-5">
    <h3 class="panel-heading mb-3">Upcoming appointments</h3>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Plate</th><th>Service</th><th>Date</th><th>Status</th><th>Progress</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($appts as $a): ?>
      <tr>
        <td><?= e($a['plate_no']) ?></td>
        <td><?= e($a['pkg_name']) ?></td>
        <td><?= e($a['appointment_date']) ?> <span class="text-xs app-muted"><?= e($a['time_window']) ?></span></td>
        <td><?= statusBadge($a['status'], $a) ?></td>
        <td><?= workflowProgressHtml($a, 'compact') ?></td>
        <td>
          <?php if (empty($a['paid_at']) && !empty($a['quote_amount']) && (float)$a['quote_amount'] > 0): ?>
          <a class="btn-primary" style="padding:.25rem .5rem;font-size:.7rem" href="<?= baseUrl('user/payment.php?id=' . (int)$a['id']) ?>">Pay</a>
          <?php else: ?>
          <a class="btn-secondary" style="padding:.25rem .5rem;font-size:.7rem" href="<?= baseUrl('user/appointments.php') ?>">View</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($appts)): ?><tr><td colspan="6" class="empty-state">No upcoming appointments — <a class="text-accent" href="<?= baseUrl('user/booking.php') ?>">book now</a></td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <div class="panel-card p-5 collapsible-panel is-open" data-collapsible="open">
    <div class="flex items-center justify-between mb-3">
      <h3 class="panel-heading">My vehicles</h3>
      <button type="button" class="btn-secondary" data-collapse-toggle style="padding:.25rem .6rem;font-size:.7rem">Collapse</button>
    </div>
    <div data-collapse-body class="collapse-body">
      <?php if (empty($vehicles)): ?>
        <p class="empty-state">No vehicles yet. <a class="text-accent" href="<?= baseUrl('user/vehicles.php') ?>">Register one</a></p>
      <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($vehicles as $v):
          $nextKm = $v['last_service_mileage'] !== null ? ((int)$v['last_service_mileage'] + $intervalKm) : null;
        ?>
        <div class="vehicle-mini-card">
          <div>
            <strong class="app-heading"><?= e($v['plate_no']) ?></strong>
            <span class="text-xs app-muted"> · <?= e($v['brand'] . ' ' . $v['model_variant']) ?></span>
          </div>
          <div class="text-xs app-muted">
            <?= $v['current_mileage'] !== null ? number_format((int)$v['current_mileage']) . ' km' : 'No mileage' ?>
            <?php if ($nextKm): ?> · next ~<?= number_format($nextKm) ?> km<?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <a href="<?= baseUrl('user/vehicles.php') ?>" class="btn-secondary mt-3" style="display:inline-block">Manage vehicles</a>
    </div>
  </div>
  <div class="panel-card p-5">
    <h3 class="panel-heading mb-3">Quick actions</h3>
    <div class="grid grid-cols-2 gap-3">
      <a href="<?= baseUrl('user/booking.php') ?>" class="quick-tile">Book Service</a>
      <a href="<?= baseUrl('user/appointments.php') ?>" class="quick-tile">Appointments</a>
      <a href="<?= baseUrl('user/receipt.php') ?>" class="quick-tile">Receipts</a>
      <a href="<?= baseUrl('user/history.php') ?>" class="quick-tile">Rate Services</a>
      <a href="<?= baseUrl('user/notifications.php') ?>" class="quick-tile">Notifications</a>
      <a href="<?= baseUrl('contact.php') ?>" class="quick-tile">Contact Workshop</a>
    </div>
    <p class="text-xs app-muted mt-4">Service every <?= (int)$intervalKm ?> km or <?= (int)getServiceIntervalMonths() ?> months · Workshop: <?= e($workshopPhone) ?></p>
    <div class="mt-3"><?= contactPhoneButtons($workshopPhone, 'Hi AutoCare Hub, I need help with my booking.') ?></div>
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
