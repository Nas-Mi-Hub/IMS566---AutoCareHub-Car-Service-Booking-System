<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('customer');
$db = getDB();
ensureSchemaUpdates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('user/appointments.php'));
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($action === 'cancel') {
        $requestRefund = !empty($_POST['request_refund']);
        $result = cancelCustomerBooking($id, (int) $user['id'], $reason, $requestRefund);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    } elseif ($action === 'refund') {
        $result = requestRefund($id, (int) $user['id'], $reason);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    }
    redirect(baseUrl('user/appointments.php'));
}

$filter = $_GET['status'] ?? 'All';
$sql = "SELECT a.*, v.plate_no, v.owner_name, sp.name AS pkg_name, sp.price FROM appointments a JOIN vehicles v ON v.id=a.vehicle_id JOIN service_packages sp ON sp.id=a.package_id WHERE a.user_id=?";
$params = [$user['id']];
if ($filter !== 'All') {
    if ($filter === 'Awaiting Quote') {
        $sql .= " AND a.status='Approved' AND a.paid_at IS NULL AND (a.quote_amount IS NULL OR a.quote_amount=0)";
    } elseif ($filter === 'Awaiting Payment') {
        $sql .= " AND a.status='Approved' AND a.paid_at IS NULL AND a.quote_amount IS NOT NULL AND a.quote_amount>0";
    } elseif ($filter === 'Confirmed') {
        $sql .= " AND a.status='Approved' AND a.paid_at IS NOT NULL";
    } elseif ($filter === 'Refund') {
        $sql .= " AND a.refund_status IN ('requested','approved','paid')";
    } else {
        $sql .= ' AND a.status=?';
        $params[] = $filter;
    }
}
$sql .= ' ORDER BY a.appointment_date DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$appts = $stmt->fetchAll();
$pipeline = workflowPipelineStats((int) $user['id'], 'customer');

renderHeader('My Appointments', $user, 'appointments');
?>
<h2 class="section-title">My Appointments / Temujanji Saya</h2>
<p class="section-subtitle">Wait for quote → pay → service. Cancel anytime before work starts; request refund if already paid.</p>
<?= workflowPipelineHtml($pipeline, 'Your bookings at a glance') ?>
<div class="panel-card p-5">
  <form method="GET" class="mb-4"><select name="status" class="form-input" style="width:auto" onchange="this.form.submit()">
    <?php foreach (['All','Awaiting Quote','Awaiting Payment','Confirmed','In Progress','Completed','Cancelled','Refund'] as $s): ?>
      <option value="<?= $s ?>" <?= $filter===$s?'selected':'' ?>><?= $s==='All'?'All Statuses':$s ?></option>
    <?php endforeach; ?>
  </select></form>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Vehicle</th><th>Service</th><th>Date / Time</th><th>Quote / Pay</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($appts as $a):
      $canCancel = ($a['status'] === 'Approved');
      $hasQuote = !empty($a['quote_amount']) && (float)$a['quote_amount'] > 0;
      $canPay = $a['status'] === 'Approved' && !appointmentIsPaid($a) && $hasQuote;
      $canRefund = appointmentIsPaid($a) && in_array($a['status'], ['Cancelled', 'Approved'], true)
          && !in_array($a['refund_status'] ?? 'none', ['requested','approved','paid'], true);
    ?>
    <tr>
      <td><?= e($a['plate_no']) ?></td>
      <td><?= e($a['pkg_name']) ?>
        <br><span class="text-xs text-slate-500">Guide ~<?= formatRM($a['price']) ?></span>
      </td>
      <td><?= e($a['appointment_date']) ?><br><span class="text-xs"><?= e($a['time_window']) ?></span></td>
      <td>
        <?php if (appointmentIsPaid($a)): ?>
          <span class="text-emerald-400"><?= e(paymentMethodLabel($a['payment_method'] ?? null)) ?></span>
          <br><span class="text-xs"><?= formatRM($a['quote_amount'] ?: $a['price']) ?></span>
        <?php elseif ($hasQuote): ?>
          <strong class="text-accent"><?= formatRM($a['quote_amount']) ?></strong>
          <?php if (!empty($a['quote_notes'])): ?><br><span class="text-xs text-slate-400"><?= e(mb_substr($a['quote_notes'], 0, 80)) ?></span><?php endif; ?>
        <?php else: ?>
          <span class="text-amber-400 text-xs">Waiting for mechanic quote…</span>
        <?php endif; ?>
      </td>
      <td>
        <?= statusBadge($a['status'], $a) ?>
        <?php if (($a['refund_status'] ?? 'none') !== 'none'): ?>
          <br><span class="badge badge-pending" style="font-size:.65rem">Refund: <?= e($a['refund_status']) ?></span>
        <?php endif; ?>
        <?= workflowProgressHtml($a) ?>
      </td>
      <td class="space-y-1">
        <?php if ($canPay): ?>
        <a href="<?= baseUrl('user/payment.php?id=' . $a['id']) ?>" class="btn-primary" style="font-size:.7rem;padding:.35rem .7rem;width:auto;display:inline-block">Pay Quote</a>
        <?php elseif ($a['status'] === 'Approved' && !appointmentIsPaid($a)): ?>
        <span class="text-xs app-muted">No quote yet</span>
        <?php elseif ($a['status'] === 'In Progress'): ?>
        <span class="text-xs text-accent">Work in progress</span>
        <?php elseif ($a['status'] === 'Completed'): ?>
        <a href="<?= baseUrl('user/history.php') ?>" class="text-xs text-accent">Rate service →</a>
        <?php endif; ?>

        <?php if ($canCancel): ?>
        <form method="POST" class="mt-1 space-y-1" onsubmit="return confirm('Cancel this booking?')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="cancel">
          <input type="hidden" name="id" value="<?= $a['id'] ?>">
          <input class="form-input" style="font-size:.7rem;padding:.25rem" name="reason" placeholder="Reason (optional)">
          <?php if (appointmentIsPaid($a)): ?>
          <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="request_refund" value="1" checked> Request refund</label>
          <?php endif; ?>
          <button class="btn-danger" style="font-size:.7rem">Cancel / Batal</button>
        </form>
        <?php endif; ?>

        <?php if ($canRefund && $a['status'] === 'Cancelled'): ?>
        <form method="POST" class="mt-1">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="refund">
          <input type="hidden" name="id" value="<?= $a['id'] ?>">
          <input class="form-input" style="font-size:.7rem;padding:.25rem" name="reason" placeholder="Refund reason" required>
          <button class="btn-warning" style="font-size:.7rem">Request refund</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($appts)): ?><tr><td colspan="6" class="empty-state">No appointments</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <a href="<?= baseUrl('user/booking.php') ?>" class="btn-primary mt-4" style="width:auto;display:inline-block">New Booking</a>
</div>
<?php renderFooter(); ?>
