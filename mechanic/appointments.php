<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('mechanic');
$db = getDB();
ensureSchemaUpdates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('mechanic/appointments.php'));
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $mileage = isset($_POST['dropoff_mileage']) && $_POST['dropoff_mileage'] !== ''
        ? (int) $_POST['dropoff_mileage'] : null;

    if ($action === 'start') {
        $result = AppointmentService::updateWork($id, 'In Progress', (int) $user['id'], null, $mileage);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    } elseif ($action === 'send_quote') {
        $amount = (float) ($_POST['quote_amount'] ?? 0);
        $notes = trim($_POST['quote_notes'] ?? '');
        $result = submitAppointmentQuote($id, $amount, $notes, (int) $user['id']);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    }
    redirect(baseUrl('mechanic/appointments.php'));
}

// Show: unpaid awaiting quote, paid awaiting work, in progress
$appts = $db->query("
    SELECT a.*, v.plate_no, v.owner_name, v.contact_no AS vehicle_phone, v.vehicle_type, v.current_mileage,
           sp.name AS pkg_name, sp.price, u.full_name AS mechanic_name, cu.contact_no AS user_phone, cu.full_name AS customer_name
    FROM appointments a
    JOIN vehicles v ON v.id=a.vehicle_id
    JOIN service_packages sp ON sp.id=a.package_id
    JOIN users cu ON cu.id=a.user_id
    LEFT JOIN users u ON u.id=a.mechanic_id
    WHERE a.status NOT IN ('Cancelled','Completed')
    ORDER BY
      CASE WHEN a.paid_at IS NULL THEN 0 ELSE 1 END,
      a.appointment_date, a.time_window
")->fetchAll();

$commPct = getMechanicCommissionPercent();

renderHeader('Workshop Jobs', $user, 'appointments');
?>
<h2 class="section-title">Workshop Jobs / Kerja Bengkel</h2>
<p class="section-subtitle">1) Send quote → 2) Customer pays → 3) Start job. Open Job Card for notes, photos &amp; parts.</p>
<div class="panel-card p-5">
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Vehicle / Contact</th><th>Service</th><th>Date</th><th>Quote</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($appts as $a):
      $phone = $a['vehicle_phone'] ?: $a['user_phone'];
      $hasQuote = !empty($a['quote_amount']) && (float)$a['quote_amount'] > 0;
      $paid = appointmentIsPaid($a);
    ?>
    <tr>
      <td>
        <strong class="text-white"><?= e($a['plate_no']) ?></strong>
        <br><span class="text-xs text-slate-500"><?= e($a['owner_name'] ?: $a['customer_name']) ?></span>
        <div class="mt-1"><?= contactPhoneButtons($phone, 'Hi, regarding your booking for ' . $a['plate_no'] . ' at AutoCare Hub') ?></div>
      </td>
      <td><?= e($a['pkg_name']) ?><br><span class="text-xs text-slate-500">Guide ~<?= formatRM($a['price']) ?></span></td>
      <td><?= e($a['appointment_date']) ?><br><span class="text-xs"><?= e($a['time_window']) ?></span></td>
      <td>
        <?php if ($hasQuote): ?>
          <strong class="text-accent"><?= formatRM($a['quote_amount']) ?></strong>
          <?php if (!empty($a['quote_notes'])): ?><br><span class="text-xs text-slate-400"><?= e(mb_substr($a['quote_notes'],0,40)) ?></span><?php endif; ?>
        <?php else: ?>
          <span class="text-amber-400 text-xs">Need quote</span>
        <?php endif; ?>
        <br><?= $paid ? '<span class="text-emerald-400 text-xs">Paid</span>' : '<span class="text-xs text-slate-500">Unpaid</span>' ?>
      </td>
      <td>
        <?= statusBadge($a['status'], $a) ?>
        <?= workflowProgressHtml($a, 'compact') ?>
      </td>
      <td class="space-y-1">
        <a href="<?= baseUrl('mechanic/job.php?id=' . (int)$a['id']) ?>" class="btn-primary" style="display:inline-block;padding:.35rem .6rem;font-size:.75rem">Job Card</a>
        <?php if (!$hasQuote && $a['status'] === 'Approved'): ?>
        <form method="POST" class="space-y-1 mt-1">
          <?= csrfField() ?>
          <input type="hidden" name="id" value="<?= $a['id'] ?>">
          <input class="form-input" style="width:110px;padding:.3rem;font-size:.75rem" type="number" step="0.01" min="1" name="quote_amount" placeholder="RM quote" required value="<?= e((string)$a['price']) ?>">
          <input class="form-input" style="width:140px;padding:.3rem;font-size:.75rem" name="quote_notes" placeholder="Notes">
          <button name="action" value="send_quote" class="btn-warning">Send Quote</button>
        </form>
        <?php endif; ?>
        <?php if ($a['status'] === 'Approved' && $paid): ?>
        <form method="POST" class="space-y-1 mt-1">
          <?= csrfField() ?>
          <input type="hidden" name="id" value="<?= $a['id'] ?>">
          <input class="form-input" style="width:120px;padding:.3rem;font-size:.75rem" type="number" name="dropoff_mileage" min="0" placeholder="Drop-off km *" required value="<?= e((string)($a['current_mileage'] ?? '')) ?>">
          <button name="action" value="start" class="btn-success">Start</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($appts)): ?><tr><td colspan="6" class="empty-state">No open jobs</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php renderFooter(); ?>
