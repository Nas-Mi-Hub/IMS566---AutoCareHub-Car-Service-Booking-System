<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('admin');
$db = getDB();
ensureSchemaUpdates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('admin/appointments.php'));
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $mileage = isset($_POST['dropoff_mileage']) && $_POST['dropoff_mileage'] !== ''
        ? (int) $_POST['dropoff_mileage'] : null;

    if ($action === 'start') {
        $result = AppointmentService::updateWork($id, 'In Progress', (int) $user['id'], null, $mileage);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    } elseif ($action === 'complete') {
        $result = AppointmentService::updateWork($id, 'Completed', (int) $user['id'], null, $mileage);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    } elseif ($action === 'send_quote') {
        $result = submitAppointmentQuote($id, (float) ($_POST['quote_amount'] ?? 0), trim($_POST['quote_notes'] ?? ''), (int) $user['id']);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    } elseif ($action === 'refund') {
        $result = processRefund($id, $_POST['decision'] ?? 'approved', (int) $user['id']);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    } elseif ($action === 'cancel') {
        $appt = $db->prepare('SELECT * FROM appointments WHERE id=?');
        $appt->execute([$id]);
        $appt = $appt->fetch();
        if ($appt && $appt['status'] !== 'Cancelled' && $appt['status'] !== 'Completed') {
            $db->prepare("UPDATE appointments SET status='Cancelled' WHERE id=?")->execute([$id]);
            notifyWithEmail((int) $appt['user_id'], 'Appointment Cancelled', 'Your appointment has been cancelled by the workshop.', 'warning', baseUrl('user/appointments.php'), true, true);
            flash('success', 'Appointment cancelled.');
        }
    }
    redirect(baseUrl('admin/appointments.php'));
}

$filter = $_GET['status'] ?? 'All';
$stmtSql = "SELECT a.*, v.plate_no, v.owner_name, v.contact_no AS vehicle_phone, v.brand, v.model_variant, v.vehicle_type, v.current_mileage,
       sp.name AS pkg_name, sp.price, u.full_name AS mechanic_name, cu.contact_no AS user_phone
    FROM appointments a
    JOIN vehicles v ON v.id=a.vehicle_id
    JOIN service_packages sp ON sp.id=a.package_id
    JOIN users cu ON cu.id=a.user_id
    LEFT JOIN users u ON u.id=a.mechanic_id
    WHERE a.status != 'Completed'";
$params = [];
if ($filter !== 'All') {
    if ($filter === 'Awaiting Quote') {
        $stmtSql .= " AND a.status='Approved' AND a.paid_at IS NULL AND (a.quote_amount IS NULL OR a.quote_amount=0)";
    } elseif ($filter === 'Awaiting Payment') {
        $stmtSql .= " AND a.status='Approved' AND a.paid_at IS NULL AND a.quote_amount IS NOT NULL AND a.quote_amount>0";
    } elseif ($filter === 'Confirmed') {
        $stmtSql .= " AND a.status='Approved' AND a.paid_at IS NOT NULL";
    } elseif ($filter === 'Refund') {
        $stmtSql .= " AND a.refund_status IN ('requested','approved')";
    } else {
        $stmtSql .= ' AND a.status=?';
        $params[] = $filter;
    }
}
$stmtSql .= ' ORDER BY a.appointment_date, a.time_window';
$stmt = $db->prepare($stmtSql);
$stmt->execute($params);
$appts = $stmt->fetchAll();
$commPct = getMechanicCommissionPercent();

renderHeader('Appointments', $user, 'appointments');
?>
<h2 class="section-title">Appointment Queue</h2>
<p class="section-subtitle">Quote → pay → service · Refunds · Contact customers via Call / WhatsApp</p>

<div class="panel-card p-5">
  <div class="flex justify-between mb-4">
    <form method="GET" class="flex gap-2">
      <select name="status" class="form-input" style="width:auto" onchange="this.form.submit()">
        <?php foreach (['All','Awaiting Quote','Awaiting Payment','Confirmed','In Progress','Cancelled','Refund'] as $s): ?>
        <option value="<?= $s ?>" <?= $filter===$s?'selected':'' ?>><?= $s === 'All' ? 'All Statuses' : $s ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Vehicle / Contact</th><th>Package</th><th>Date</th><th>Quote / Pay</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($appts as $a):
      $phone = $a['vehicle_phone'] ?: $a['user_phone'];
      $hasQuote = !empty($a['quote_amount']) && (float)$a['quote_amount'] > 0;
    ?>
    <tr>
      <td>
        <strong class="text-white"><?= e($a['plate_no']) ?></strong>
        <br><span class="text-xs text-slate-500"><?= e($a['owner_name']) ?></span>
        <div class="mt-1"><?= contactPhoneButtons($phone, 'Hi ' . $a['owner_name'] . ', AutoCare Hub regarding ' . $a['plate_no']) ?></div>
      </td>
      <td><?= e($a['pkg_name']) ?><br><span class="text-xs text-slate-500">Guide <?= formatRM($a['price']) ?></span></td>
      <td><?= e($a['appointment_date']) ?><br><span class="text-xs"><?= e($a['time_window']) ?></span></td>
      <td>
        <?php if (appointmentIsPaid($a)): ?>
          <?= e(paymentMethodLabel($a['payment_method'] ?? null)) ?>
          <br><span class="text-emerald-400 text-xs"><?= formatRM($a['quote_amount'] ?: $a['price']) ?></span>
        <?php elseif ($hasQuote): ?>
          <strong class="text-accent"><?= formatRM($a['quote_amount']) ?></strong>
        <?php else: ?>
          <span class="text-amber-400 text-xs">No quote</span>
        <?php endif; ?>
        <?php if (($a['refund_status'] ?? 'none') !== 'none'): ?>
          <br><span class="badge badge-pending" style="font-size:.65rem">Refund: <?= e($a['refund_status']) ?></span>
          <?php if (!empty($a['refund_reason'])): ?><br><span class="text-[10px] text-slate-500"><?= e($a['refund_reason']) ?></span><?php endif; ?>
        <?php endif; ?>
      </td>
      <td>
        <?= statusBadge($a['status'], $a) ?>
        <?= workflowProgressHtml($a, 'compact') ?>
      </td>
      <td class="whitespace-nowrap space-y-1">
        <a href="<?= baseUrl('mechanic/job.php?id=' . (int)$a['id']) ?>" class="btn-primary" style="padding:.25rem .5rem;font-size:.7rem;display:inline-block">Job Card</a>

        <?php if ($a['status'] === 'Approved' && !appointmentIsPaid($a)): ?>
        <form method="POST" class="inline-flex flex-wrap gap-1 items-end mt-1">
          <?= csrfField() ?>
          <input type="hidden" name="id" value="<?= $a['id'] ?>">
          <input class="form-input" style="width:90px;padding:.25rem;font-size:.7rem" type="number" step="0.01" min="1" name="quote_amount" placeholder="Quote RM" value="<?= e((string)($a['quote_amount'] ?? $a['price'])) ?>" required>
          <input class="form-input" style="width:100px;padding:.25rem;font-size:.7rem" name="quote_notes" placeholder="Notes" value="<?= e($a['quote_notes'] ?? '') ?>">
          <button name="action" value="send_quote" class="btn-warning">Quote</button>
        </form>
        <?php endif; ?>

        <?php if ($a['status'] === 'Approved' && appointmentIsPaid($a)): ?>
        <form method="POST" class="inline-flex flex-wrap gap-1 items-end mt-1">
          <?= csrfField() ?>
          <input type="hidden" name="id" value="<?= $a['id'] ?>">
          <input class="form-input" style="width:100px;padding:.25rem;font-size:.7rem" type="number" name="dropoff_mileage" min="0" placeholder="km *" required value="<?= e((string)($a['current_mileage'] ?? '')) ?>">
          <button name="action" value="start" class="btn-warning">Start</button>
        </form>
        <?php endif; ?>

        <?php if ($a['status'] === 'In Progress'): ?>
        <form method="POST" class="inline mt-1">
          <?= csrfField() ?>
          <input type="hidden" name="id" value="<?= $a['id'] ?>">
          <?php if (empty($a['dropoff_mileage'])): ?>
          <input class="form-input" style="width:100px;padding:.25rem;font-size:.7rem" type="number" name="dropoff_mileage" min="0" placeholder="km *" required>
          <?php endif; ?>
          <button name="action" value="complete" class="btn-success">Complete</button>
        </form>
        <?php endif; ?>

        <?php if (($a['refund_status'] ?? '') === 'requested'): ?>
        <form method="POST" class="inline-flex gap-1 mt-1">
          <?= csrfField() ?>
          <input type="hidden" name="id" value="<?= $a['id'] ?>">
          <input type="hidden" name="action" value="refund">
          <button name="decision" value="approved" class="btn-success" style="font-size:.7rem">Approve refund</button>
          <button name="decision" value="paid" class="btn-primary" style="font-size:.7rem">Mark paid</button>
          <button name="decision" value="rejected" class="btn-danger" style="font-size:.7rem">Reject</button>
        </form>
        <?php elseif (($a['refund_status'] ?? '') === 'approved'): ?>
        <form method="POST" class="inline mt-1">
          <?= csrfField() ?>
          <input type="hidden" name="id" value="<?= $a['id'] ?>">
          <input type="hidden" name="action" value="refund">
          <button name="decision" value="paid" class="btn-primary" style="font-size:.7rem">Mark refund paid</button>
        </form>
        <?php endif; ?>

        <?php if ($a['status'] !== 'Cancelled'): ?>
        <form method="POST" class="inline mt-1">
          <?= csrfField() ?>
          <input type="hidden" name="id" value="<?= $a['id'] ?>">
          <button name="action" value="cancel" class="btn-danger" style="font-size:.7rem" onclick="return confirm('Cancel?')">Cancel</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($appts)): ?><tr><td colspan="6" class="empty-state">No appointments</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php renderFooter(); ?>
