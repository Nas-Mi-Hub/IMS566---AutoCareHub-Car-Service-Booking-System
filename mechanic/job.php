<?php
/**
 * Digital Job Card — notes, photos, parts, labor hours
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('mechanic', 'admin');
$db = getDB();
ensureSchemaUpdates();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $db->prepare('
    SELECT a.*, v.plate_no, v.owner_name, v.brand, v.model_variant, v.vehicle_type, v.current_mileage, v.contact_no AS vehicle_phone,
           sp.name AS pkg_name, sp.price, u.full_name AS customer_name, u.contact_no AS customer_phone
    FROM appointments a
    JOIN vehicles v ON v.id = a.vehicle_id
    JOIN service_packages sp ON sp.id = a.package_id
    JOIN users u ON u.id = a.user_id
    WHERE a.id = ?
');
$stmt->execute([$id]);
$appt = $stmt->fetch();
$customerPhone = $appt['vehicle_phone'] ?? $appt['customer_phone'] ?? '';

if (!$appt) {
    flash('error', 'Job not found. / Kerja tidak dijumpai.');
    redirect(baseUrl($user['role'] === 'admin' ? 'admin/appointments.php' : 'mechanic/appointments.php'));
}

$partsCatalog = InventoryService::listParts(true);
$jobParts = InventoryService::partsForAppointment($id);
$existingPhotos = [];
if (!empty($appt['inspection_photos'])) {
    $decoded = json_decode((string) $appt['inspection_photos'], true);
    if (is_array($decoded)) {
        $existingPhotos = $decoded;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('mechanic/job.php?id=' . $id));
    $action = $_POST['action'] ?? '';
    $mileage = isset($_POST['dropoff_mileage']) && $_POST['dropoff_mileage'] !== ''
        ? (int) $_POST['dropoff_mileage'] : null;
    $notes = trim($_POST['job_notes'] ?? '');
    $laborHours = isset($_POST['labor_hours']) && $_POST['labor_hours'] !== ''
        ? (float) $_POST['labor_hours'] : null;

    $photoPaths = [];
    if (!empty($_FILES['photos']['name'][0])) {
        $count = count($_FILES['photos']['name']);
        for ($i = 0; $i < $count && $i < 5; $i++) {
            if (($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $file = [
                'name'     => $_FILES['photos']['name'][$i],
                'type'     => $_FILES['photos']['type'][$i],
                'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                'error'    => $_FILES['photos']['error'][$i],
                'size'     => $_FILES['photos']['size'][$i],
            ];
            $up = handleJobPhotoUpload($file, 'job_photos');
            if ($up['ok']) {
                $photoPaths[] = $up['path'];
            }
        }
    }

    $partsUsed = [];
    $partIds = $_POST['part_id'] ?? [];
    $partQtys = $_POST['part_qty'] ?? [];
    if (is_array($partIds)) {
        foreach ($partIds as $idx => $pid) {
            $pid = (int) $pid;
            $qty = (int) ($partQtys[$idx] ?? 0);
            if ($pid > 0 && $qty > 0) {
                $partsUsed[] = ['part_id' => $pid, 'qty' => $qty];
            }
        }
    }

    if ($action === 'send_quote') {
        $amount = (float) ($_POST['quote_amount'] ?? 0);
        $qnotes = trim($_POST['quote_notes'] ?? '');
        $result = submitAppointmentQuote($id, $amount, $qnotes, (int) $user['id']);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
        redirect(baseUrl('mechanic/job.php?id=' . $id));
    }

    // Save notes only
    if ($action === 'save_notes') {
        $sql = 'UPDATE appointments SET job_notes=?';
        $params = [$notes !== '' ? $notes : null];
        if ($laborHours !== null) {
            $sql .= ', labor_hours=?';
            $params[] = $laborHours;
        }
        if (!empty($photoPaths)) {
            $merged = array_values(array_merge($existingPhotos, $photoPaths));
            $sql .= ', inspection_photos=?';
            $params[] = json_encode($merged, JSON_UNESCAPED_SLASHES);
        }
        $sql .= ' WHERE id=?';
        $params[] = $id;
        $db->prepare($sql)->execute($params);
        flash('success', 'Job card saved. / Kad kerja disimpan.');
        redirect(baseUrl('mechanic/job.php?id=' . $id));
    }

    $status = $action === 'start' ? 'In Progress' : ($action === 'complete' ? 'Completed' : '');
    if ($status === '') {
        flash('error', 'Invalid action.');
        redirect(baseUrl('mechanic/job.php?id=' . $id));
    }

    $result = AppointmentService::updateWork(
        $id,
        $status,
        (int) $user['id'],
        $notes !== '' ? $notes : null,
        $mileage,
        $action === 'complete' ? $partsUsed : [],
        $photoPaths,
        $laborHours
    );
    flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    if ($result['ok'] && $action === 'complete') {
        redirect(baseUrl($user['role'] === 'admin' ? 'admin/history.php' : 'mechanic/history.php'));
    }
    redirect(baseUrl('mechanic/job.php?id=' . $id));
}

$back = $user['role'] === 'admin' ? baseUrl('admin/appointments.php') : baseUrl('mechanic/appointments.php');
renderHeader('Job Card #' . $id, $user, 'appointments');
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
  <div>
    <h2 class="section-title">Digital Job Card / Kad Kerja #<?= (int)$id ?></h2>
    <p class="section-subtitle"><?= e($appt['plate_no']) ?> · <?= e($appt['pkg_name']) ?> · <?= statusBadge($appt['status'], $appt) ?></p>
  </div>
  <a href="<?= e($back) ?>" class="btn-secondary">← Back to queue</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="panel-card p-5 space-y-2 text-sm">
    <h3 class="font-semibold mb-2">Vehicle / Kenderaan</h3>
    <div class="payment-summary-row"><span class="app-muted">Plate</span><strong><?= e($appt['plate_no']) ?></strong></div>
    <div class="payment-summary-row"><span class="app-muted">Vehicle</span><span><?= e($appt['brand'] . ' ' . $appt['model_variant']) ?></span></div>
    <div class="payment-summary-row"><span class="app-muted">Type</span><span><?= e($appt['vehicle_type'] ?? '—') ?></span></div>
    <div class="payment-summary-row"><span class="app-muted">Customer</span><span><?= e($appt['customer_name']) ?></span></div>
    <div class="payment-summary-row"><span class="app-muted">Phone</span><span><?= contactPhoneButtons($customerPhone, 'Hi ' . $appt['customer_name'] . ', regarding ' . $appt['plate_no'] . ' service at AutoCare Hub') ?></span></div>
    <div class="payment-summary-row"><span class="app-muted">Quote</span><span><?= !empty($appt['quote_amount']) ? formatRM($appt['quote_amount']) : 'Not sent' ?></span></div>
    <div class="payment-summary-row"><span class="app-muted">Date / Time</span><span><?= e($appt['appointment_date'] . ' ' . $appt['time_window']) ?></span></div>
    <div class="payment-summary-row"><span class="app-muted">Package</span><span><?= e($appt['pkg_name']) ?> (<?= formatRM($appt['price']) ?>)</span></div>
    <div class="payment-summary-row"><span class="app-muted">Booking type</span><span><?= e($appt['booking_type'] ?? 'package') ?></span></div>
    <div class="payment-summary-row"><span class="app-muted">Payment</span><span><?= appointmentIsPaid($appt) ? e(paymentMethodLabel($appt['payment_method'] ?? null)) : 'Unpaid' ?></span></div>
    <div class="mt-3 pt-3" style="border-top:1px solid var(--border)">
      <p class="text-xs app-muted mb-2">Job progress</p>
      <?= workflowProgressHtml($appt) ?>
    </div>
  </div>

  <div class="panel-card p-5 lg:col-span-2">
    <?php if ($appt['status'] === 'Approved' && !appointmentIsPaid($appt)): ?>
    <div class="mb-6 p-4 rounded-lg border border-accent/40 bg-orange-500/10 space-y-3">
      <h3 class="font-semibold text-white">Send Quote to Customer / Hantar Sebut Harga</h3>
      <p class="text-xs text-slate-400">Customer cannot pay until you send a quote. Guide package price: <?= formatRM($appt['price']) ?></p>
      <form method="POST" class="space-y-3">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="text-xs text-slate-400">Quote amount (RM) *</label>
            <input class="form-input" type="number" name="quote_amount" step="0.01" min="1" required
                   value="<?= e((string)($appt['quote_amount'] ?? $appt['price'] ?? '')) ?>">
          </div>
          <div>
            <label class="text-xs text-slate-400">Quote notes / breakdown</label>
            <input class="form-input" name="quote_notes" placeholder="Labour + oil + filter…" value="<?= e($appt['quote_notes'] ?? '') ?>">
          </div>
        </div>
        <button type="submit" name="action" value="send_quote" class="btn-primary">Send Quote / Hantar Sebut Harga</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if (in_array($appt['status'], ['Approved', 'In Progress'], true)): ?>
    <form method="POST" enctype="multipart/form-data" class="space-y-4" id="job-form">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int)$id ?>">

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="text-xs text-slate-400">Drop-off mileage (km) *</label>
          <input class="form-input" type="number" name="dropoff_mileage" min="0" step="1"
                 value="<?= e((string)($appt['dropoff_mileage'] ?? $appt['current_mileage'] ?? '')) ?>"
                 <?= $appt['status'] === 'Approved' ? 'required' : '' ?>>
        </div>
        <div>
          <label class="text-xs text-slate-400">Labor hours / Jam buruh</label>
          <input class="form-input" type="number" name="labor_hours" min="0" step="0.25"
                 value="<?= e((string)($appt['labor_hours'] ?? '')) ?>" placeholder="e.g. 1.5">
        </div>
      </div>

      <div>
        <label class="text-xs text-slate-400">Job notes / Nota kerja</label>
        <textarea class="form-input" name="job_notes" rows="4" placeholder="Findings, work done, recommendations…"><?= e($appt['job_notes'] ?? '') ?></textarea>
      </div>

      <div>
        <label class="text-xs text-slate-400">Photos (before/after/damage) — max 5, 5MB each</label>
        <input class="form-input" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
      </div>

      <?php if (!empty($existingPhotos)): ?>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($existingPhotos as $ph): ?>
          <a href="<?= e(baseUrl($ph)) ?>" target="_blank" class="block w-20 h-20 rounded border border-slate-600 overflow-hidden">
            <img src="<?= e(baseUrl($ph)) ?>" alt="Job photo" class="w-full h-full object-cover">
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($appt['status'] === 'In Progress'): ?>
      <div>
        <div class="flex justify-between items-center mb-2">
          <label class="text-xs text-slate-400">Parts used / Alat ganti digunakan</label>
          <button type="button" class="btn-secondary text-xs" onclick="addPartRow()">+ Add part</button>
        </div>
        <div id="parts-rows" class="space-y-2">
          <div class="flex gap-2 part-row">
            <select class="form-input flex-1" name="part_id[]">
              <option value="">— Select part —</option>
              <?php foreach ($partsCatalog as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= formatRM($p['unit_price']) ?>, stock <?= (int)$p['stock_qty'] ?>)</option>
              <?php endforeach; ?>
            </select>
            <input class="form-input" style="width:90px" type="number" name="part_qty[]" min="1" placeholder="Qty" value="1">
          </div>
        </div>
        <p class="text-[10px] text-slate-500 mt-1">Stock is deducted when you mark complete. / Stok ditolak apabila kerja ditanda selesai.</p>
      </div>
      <?php endif; ?>

      <div class="flex flex-wrap gap-2 pt-2">
        <button type="submit" name="action" value="save_notes" class="btn-secondary">Save card / Simpan</button>
        <?php if ($appt['status'] === 'Approved'): ?>
          <?php if (appointmentIsPaid($appt)): ?>
          <button type="submit" name="action" value="start" class="btn-warning">Start work / Mula kerja</button>
          <?php elseif (!empty($appt['quote_amount'])): ?>
          <span class="text-xs text-amber-400 self-center">Quote sent — awaiting customer payment</span>
          <?php else: ?>
          <span class="text-xs text-amber-400 self-center">Send quote first, then wait for payment</span>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($appt['status'] === 'In Progress'): ?>
        <button type="submit" name="action" value="complete" class="btn-success" onclick="return confirm('Complete this job and deduct parts stock?')">Mark complete / Selesai</button>
        <?php endif; ?>
      </div>
    </form>
    <?php else: ?>
      <p class="text-slate-400">This job is <?= e($appt['status']) ?> and cannot be edited.</p>
      <?php if (!empty($appt['job_notes'])): ?>
        <div class="mt-3 p-3 rounded bg-slate-800/50 text-sm whitespace-pre-wrap"><?= e($appt['job_notes']) ?></div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($jobParts)): ?>
    <div class="mt-6">
      <h4 class="text-sm font-semibold mb-2">Parts already recorded</h4>
      <table class="data-table">
        <thead><tr><th>Part</th><th>Qty</th><th>Unit</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($jobParts as $jp): ?>
          <tr>
            <td><?= e($jp['name']) ?></td>
            <td><?= (int)$jp['qty'] ?></td>
            <td><?= formatRM($jp['unit_price_at_time']) ?></td>
            <td><?= formatRM($jp['total']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
function addPartRow() {
  const rows = document.getElementById('parts-rows');
  const first = rows.querySelector('.part-row');
  if (!first) return;
  const clone = first.cloneNode(true);
  clone.querySelectorAll('input').forEach(i => { if (i.type === 'number') i.value = '1'; });
  clone.querySelectorAll('select').forEach(s => { s.selectedIndex = 0; });
  rows.appendChild(clone);
}
</script>
<?php renderFooter(); ?>
