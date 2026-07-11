<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('customer');
$db = getDB();
ensureSchemaUpdates();

$apptId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $db->prepare('
    SELECT a.*, v.plate_no, v.owner_name, v.brand, v.model_variant, sp.name AS pkg_name, sp.price
    FROM appointments a
    JOIN vehicles v ON v.id = a.vehicle_id
    JOIN service_packages sp ON sp.id = a.package_id
    WHERE a.id = ? AND a.user_id = ?
');
$stmt->execute([$apptId, $user['id']]);
$appt = $stmt->fetch();

if (!$appt) {
    flash('error', 'Appointment not found. / Temujanji tidak dijumpai.');
    redirect(baseUrl('user/appointments.php'));
}

if (appointmentIsPaid($appt)) {
    flash('info', 'This appointment has already been paid. / Temujanji ini sudah dibayar.');
    redirect(baseUrl('user/appointments.php'));
}

if ($appt['status'] !== 'Approved') {
    flash('error', 'This appointment cannot be paid in its current state. / Tidak boleh bayar dalam status semasa.');
    redirect(baseUrl('user/appointments.php'));
}

// Real workshop: pay only after mechanic quote
$quoteAmount = !empty($appt['quote_amount']) ? (float) $appt['quote_amount'] : 0.0;
if ($quoteAmount <= 0) {
    flash('info', 'Please wait for the workshop quote before paying. / Sila tunggu sebut harga bengkel sebelum bayar.');
    redirect(baseUrl('user/appointments.php'));
}
$payableAmount = $quoteAmount;
$totals = PaymentService::calcTotals($payableAmount, 0);
$isInspection = ($appt['booking_type'] ?? 'package') === 'inspection';

$methods = paymentMethods();
$selectedMethod = $_POST['payment_method'] ?? 'online_banking';
if (!isset($methods[$selectedMethod])) {
    $selectedMethod = 'online_banking';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('user/payment.php?id=' . $apptId));
    $proofPath = null;
    if ($selectedMethod === 'online_banking') {
        if (empty($_FILES['payment_proof']) || ($_FILES['payment_proof']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('error', 'Please upload your bank transfer receipt as payment proof. / Sila muat naik resit pindahan bank sebagai bukti bayaran.');
            redirect(baseUrl('user/payment.php?id=' . $apptId));
        }
        $upload = handlePaymentProofUpload($_FILES['payment_proof']);
        if (!$upload['ok']) {
            flash('error', $upload['error']);
            redirect(baseUrl('user/payment.php?id=' . $apptId));
        }
        $proofPath = $upload['path'];
    }

    $result = PaymentService::pay($apptId, $user['id'], $selectedMethod, $proofPath);
    if ($result['ok']) {
        flash('success', 'Payment successful via ' . paymentMethodLabel($selectedMethod) . '! Receipt ' . $result['receipt_no'] . ' is ready. / Bayaran berjaya. Resit sedia.');
        redirect(baseUrl('user/appointments.php'));
    }
    flash('error', $result['error']);
    redirect(baseUrl('user/payment.php?id=' . $apptId));
}

$bankName = getWorkshopSetting('workshop_bank_name', 'Maybank');
$bankAccount = getWorkshopSetting('workshop_bank_account', '514123456789');
$bankHolder = getWorkshopSetting('workshop_bank_holder', 'AutoCare Hub Sdn Bhd');
$qrRef = 'ACH-PAY-' . str_pad((string) $apptId, 6, '0', STR_PAD_LEFT);

renderHeader('Payment', $user, 'appointments');
?>
<h2 class="section-title">Complete Payment / Lengkapkan Bayaran</h2>
<p class="section-subtitle">Choose your payment method. Online banking requires uploading your transfer receipt. / Online banking memerlukan muat naik resit pindahan.</p>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="panel-card p-5 lg:col-span-1">
    <h3 class="text-sm font-semibold mb-4 app-heading">Booking Summary / Ringkasan</h3>
    <div class="space-y-2 text-sm">
      <div class="payment-summary-row"><span class="app-muted">Vehicle</span><span><?= e($appt['plate_no']) ?></span></div>
      <div class="payment-summary-row"><span class="app-muted">Owner</span><span><?= e($appt['owner_name']) ?></span></div>
      <div class="payment-summary-row"><span class="app-muted">Service</span><span><?= e($appt['pkg_name']) ?></span></div>
      <div class="payment-summary-row"><span class="app-muted">Quote notes</span><span class="text-xs"><?= e($appt['quote_notes'] ?? '—') ?></span></div>
      <div class="payment-summary-row"><span class="app-muted">Date</span><span><?= e($appt['appointment_date']) ?></span></div>
      <div class="payment-summary-row"><span class="app-muted">Time</span><span><?= e($appt['time_window']) ?></span></div>
      <div class="payment-summary-row"><span class="app-muted">Subtotal</span><span><?= formatRM($totals['subtotal']) ?></span></div>
      <?php if ($totals['sst'] > 0): ?>
      <div class="payment-summary-row"><span class="app-muted">SST (<?= e((string)$totals['sst_percent']) ?>%)</span><span><?= formatRM($totals['sst']) ?></span></div>
      <?php endif; ?>
      <div class="payment-summary-row"><span class="app-muted">Total</span><span class="text-emerald-400 font-semibold"><?= formatRM($totals['total']) ?></span></div>
    </div>
    <?php if ($isInspection): ?>
    <p class="text-xs text-amber-400 mt-3">Inspection booking — pay the inspection/quote amount shown. / Tempahan pemeriksaan — bayar jumlah sebut harga dipaparkan.</p>
    <?php endif; ?>
    <?php if (PaymentService::isGatewayEnabled()): ?>
    <p class="text-xs text-sky-400 mt-2">Instant gateway payment is available in config (Billplz/SenangPay). Manual proof upload remains supported.</p>
    <?php endif; ?>
    <p class="text-xs text-slate-500 mt-4">Note: The <strong>Receipts</strong> menu is for workshop receipts issued to you after payment. Payment proof below is your bank slip to the workshop. / Menu Resit = resit kedai kepada anda. Bukti bayaran = slip bank anda.</p>
  </div>

  <div class="panel-card p-5 lg:col-span-2">
    <form method="POST" id="payment-form" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= $apptId ?>">

      <h3 class="text-sm font-semibold mb-3 app-heading">Payment Method / Kaedah Bayaran</h3>
      <div class="payment-methods mb-5">
        <?php foreach ($methods as $key => $label):
          $icons = ['online_banking' => '🏦', 'cash' => '💵', 'qr_code' => '📱'];
        ?>
        <label class="payment-method-option relative">
          <input type="radio" name="payment_method" value="<?= e($key) ?>" <?= $selectedMethod === $key ? 'checked' : '' ?> onchange="showPaymentDetail('<?= e($key) ?>')">
          <div class="payment-method-card">
            <div class="payment-method-icon"><?= $icons[$key] ?? '💳' ?></div>
            <div class="payment-method-label"><?= e($label) ?></div>
          </div>
        </label>
        <?php endforeach; ?>
      </div>

      <div id="detail-online_banking" class="payment-detail-panel mb-5 <?= $selectedMethod !== 'online_banking' ? 'hidden' : '' ?>">
        <h4 class="text-sm font-semibold mb-2 app-heading">Online Banking Transfer / Pindahan Bank</h4>
        <p class="text-sm app-muted mb-3">Transfer the exact amount, then upload your bank receipt as proof. / Pindah jumlah tepat, kemudian muat naik resit bank sebagai bukti.</p>
        <div class="space-y-1 text-sm mb-4">
          <p><span class="app-muted">Bank:</span> <strong class="app-heading"><?= e($bankName) ?></strong></p>
          <p><span class="app-muted">Account No:</span> <strong class="app-heading"><?= e($bankAccount) ?></strong></p>
          <p><span class="app-muted">Account Name:</span> <strong class="app-heading"><?= e($bankHolder) ?></strong></p>
          <p><span class="app-muted">Reference:</span> <strong class="text-accent"><?= e($appt['plate_no']) ?></strong></p>
          <p><span class="app-muted">Amount:</span> <strong class="text-emerald-400"><?= formatRM($appt['price']) ?></strong></p>
        </div>
        <div class="payment-proof-box">
          <label class="text-xs text-slate-400 block mb-1">Upload Payment Proof / Muat Naik Bukti Bayaran <span class="text-red-400">*</span></label>
          <input type="file" name="payment_proof" id="payment_proof" class="form-input" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*,application/pdf">
          <p class="text-xs text-slate-500 mt-1">JPG, PNG, WEBP or PDF · max 5MB. This is your transfer slip, not the shop receipt. / Ini slip pindahan anda, bukan resit kedai.</p>
        </div>
      </div>

      <div id="detail-cash" class="payment-detail-panel mb-5 <?= $selectedMethod !== 'cash' ? 'hidden' : '' ?>">
        <h4 class="text-sm font-semibold mb-2 app-heading">Cash Payment / Bayaran Tunai</h4>
        <p class="text-sm app-muted">Pay in cash at the workshop counter on your appointment date. / Bayar tunai di kaunter bengkel pada tarikh temujanji.</p>
        <p class="text-sm mt-2"><span class="app-muted">Workshop:</span> <?= e(getWorkshopSetting('workshop_name')) ?></p>
        <p class="text-sm"><span class="app-muted">Address:</span> <?= e(getWorkshopSetting('workshop_address')) ?></p>
        <p class="text-sm mt-2"><span class="app-muted">Amount due:</span> <strong class="text-emerald-400"><?= formatRM($appt['price']) ?></strong></p>
      </div>

      <div id="detail-qr_code" class="payment-detail-panel mb-5 <?= $selectedMethod !== 'qr_code' ? 'hidden' : '' ?>">
        <h4 class="text-sm font-semibold mb-2 app-heading">QR Code Payment / Bayaran Kod QR</h4>
        <p class="text-sm app-muted mb-4 text-center">Scan with your banking or e-wallet app / Imbas dengan app bank atau e-wallet</p>
        <div class="payment-qr-box">
          <svg viewBox="0 0 100 100" width="120" height="120" aria-hidden="true">
            <rect width="100" height="100" fill="var(--bg-page)"/>
            <rect x="8" y="8" width="28" height="28" fill="none" stroke="var(--text-heading)" stroke-width="4"/>
            <rect x="64" y="8" width="28" height="28" fill="none" stroke="var(--text-heading)" stroke-width="4"/>
            <rect x="8" y="64" width="28" height="28" fill="none" stroke="var(--text-heading)" stroke-width="4"/>
            <rect x="16" y="16" width="12" height="12" fill="var(--text-heading)"/>
            <rect x="72" y="16" width="12" height="12" fill="var(--text-heading)"/>
            <rect x="16" y="72" width="12" height="12" fill="var(--text-heading)"/>
            <rect x="44" y="44" width="8" height="8" fill="var(--text-heading)"/>
            <rect x="56" y="44" width="8" height="8" fill="var(--text-heading)"/>
            <rect x="44" y="56" width="8" height="8" fill="var(--text-heading)"/>
            <rect x="68" y="56" width="8" height="8" fill="var(--text-heading)"/>
            <rect x="56" y="68" width="8" height="8" fill="var(--text-heading)"/>
          </svg>
          <strong class="app-heading"><?= e($qrRef) ?></strong>
          <span><?= formatRM($appt['price']) ?></span>
        </div>
      </div>

      <div class="flex flex-wrap gap-3">
        <button type="submit" class="btn-primary" style="width:auto" onclick="return confirmPayment()">
          Confirm Payment / Sahkan Bayaran
        </button>
        <a href="<?= baseUrl('user/appointments.php') ?>" class="btn-secondary">Back / Kembali</a>
      </div>
    </form>
  </div>
</div>

<script>
function showPaymentDetail(method) {
  ['online_banking', 'cash', 'qr_code'].forEach(function (key) {
    var panel = document.getElementById('detail-' + key);
    if (panel) panel.classList.toggle('hidden', key !== method);
  });
  var file = document.getElementById('payment_proof');
  if (file) file.required = (method === 'online_banking');
}
function confirmPayment() {
  var method = document.querySelector('input[name=payment_method]:checked');
  var label = method ? method.nextElementSibling.querySelector('.payment-method-label').textContent : '';
  if (method && method.value === 'online_banking') {
    var f = document.getElementById('payment_proof');
    if (!f || !f.files || !f.files.length) {
      alert('Please upload your bank transfer receipt. / Sila muat naik resit pindahan bank.');
      return false;
    }
  }
  return confirm('Confirm payment of <?= e(formatRM($appt['price'])) ?> via ' + label + '?');
}
showPaymentDetail(document.querySelector('input[name=payment_method]:checked').value);
</script>
<?php renderFooter(); ?>
