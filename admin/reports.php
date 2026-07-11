<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('admin');
$stats = getDashboardStats();
$db = getDB();
ensureSchemaUpdates();
$historyCount = (int) $db->query('SELECT COUNT(*) FROM service_history')->fetchColumn();
$apptCount = (int) $db->query("SELECT COUNT(*) FROM appointments WHERE status NOT IN ('Cancelled','Completed')")->fetchColumn();
$partsMargin = InventoryService::partsMarginSummary();

renderHeader('PDF Reports', $user, 'reports');
?>
<h2 class="section-title">PDF Reports</h2>
<p class="section-subtitle">Generate operation authorization invoice vouchers · Parts margin: <?= formatRM($partsMargin['parts_margin']) ?></p>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <div class="panel-card p-5">
    <h3 class="text-sm font-semibold text-white mb-4">Report Configuration</h3>
    <form action="<?= baseUrl('exports/pdf-report.php') ?>" method="GET" class="space-y-4">
      <input type="hidden" name="return" value="<?= e(baseUrl('admin/reports.php')) ?>">
      <div><label class="text-xs text-slate-400">Report Type</label>
        <select class="form-input" name="type">
          <option value="operations">Operations Income Summary</option>
          <option value="history">Service History Archive</option>
          <option value="appointments">Active Appointment Queue</option>
        </select></div>
      <div><label class="text-xs text-slate-400">Requested By</label><input class="form-input" name="requester" value="SYSTEM ADMINISTRATOR"></div>
      <div><label class="text-xs text-slate-400">Verified Vehicle Client</label>
        <select class="form-input" name="vehicle_id"><option value="">All Clients</option>
        <?php foreach ($db->query('SELECT id, plate_no, owner_name FROM vehicles ORDER BY plate_no') as $v): ?>
        <option value="<?= $v['id'] ?>"><?= e($v['plate_no'].' – '.$v['owner_name']) ?></option>
        <?php endforeach; ?></select></div>
      <button type="submit" class="btn-primary">Generate PDF Report</button>
    </form>
    <div class="mt-4 flex gap-2">
      <a href="<?= baseUrl('exports/csv-sheet.php?type=history') ?>" class="btn-secondary">Download History CSV</a>
      <a href="<?= baseUrl('exports/csv-sheet.php?type=appointments') ?>" class="btn-secondary">Download Appointments CSV</a>
    </div>
  </div>
  <div class="panel-card p-5">
    <h3 class="text-sm font-semibold text-white mb-4">Report Preview Summary</h3>
    <div class="space-y-2 text-sm">
      <div class="flex justify-between py-2 border-b border-slate-700/50"><span class="text-slate-400">Settled Records</span><span class="text-white font-semibold"><?= $historyCount ?></span></div>
      <div class="flex justify-between py-2 border-b border-slate-700/50"><span class="text-slate-400">Active Appointments</span><span class="text-white font-semibold"><?= $apptCount ?></span></div>
      <div class="flex justify-between py-2"><span class="text-slate-400">Total Operations Income</span><span class="text-emerald-400 font-semibold"><?= formatRM($stats['grossIncome']) ?></span></div>
    </div>
  </div>
</div>
<?php renderFooter(); ?>