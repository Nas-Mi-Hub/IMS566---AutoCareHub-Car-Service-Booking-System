<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('customer');
$db = getDB();
ensureSchemaUpdates();
checkServiceReminders((int) $user['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('user/booking.php'));
    $date = $_POST['date'] ?? '';
    $timeWindow = trim($_POST['time_window'] ?? '');
    $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
    $packageId = (int) ($_POST['package_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    $result = AppointmentService::createBooking(
        (int) $user['id'],
        $vehicleId,
        $packageId,
        $date,
        $timeWindow,
        'package',
        $notes !== '' ? $notes : null
    );

    if (!$result['ok']) {
        flash('error', $result['error']);
        redirect(baseUrl('user/booking.php'));
    }

    flash('success', 'Booking received! A mechanic will inspect and send a quote — pay only after you accept the quote. / Tempahan diterima! Mekanik akan hantar sebut harga sebelum bayaran.');
    redirect(baseUrl('user/appointments.php'));
}

$vehicles = $db->prepare('SELECT * FROM vehicles WHERE user_id=?');
$vehicles->execute([$user['id']]);
$vehicles = $vehicles->fetchAll();
$packages = $db->query('SELECT * FROM service_packages WHERE is_active=1 ORDER BY name')->fetchAll();
$preselect = (int) ($_GET['package'] ?? 0);
$selectedDate = todayDate();
$slots = getSlotAvailability($selectedDate);
$maxPerSlot = getMaxBookingsPerSlot();

renderHeader('Book Service', $user, 'booking');
?>
<h2 class="section-title">Book Service Slot / Tempah Slot Servis</h2>
<p class="section-subtitle">Real workshop flow: book slot → mechanic inspects → quote → you pay. Prices are not fixed until quoted. / Tempah → periksa → sebut harga → bayar</p>

<?php if (empty($vehicles)): ?>
<div class="panel-card p-6 text-center">
  <p class="text-slate-400 mb-3">You need to register a vehicle first. / Anda perlu daftar kenderaan dahulu.</p>
  <a href="<?= baseUrl('user/vehicles.php') ?>" class="btn-primary" style="width:auto;display:inline-block">Add Vehicle / Tambah Kenderaan</a>
</div>
<?php else: ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <div class="panel-card p-5">
    <form method="POST" class="space-y-4" id="booking-form">
      <?= csrfField() ?>

      <div class="p-3 rounded-lg border border-amber-600/40 bg-amber-500/10 text-sm text-amber-100">
        <strong>No immediate payment.</strong> After booking, wait for the workshop quote (parts + labour). You only pay once the quote is ready.
        <br><span class="text-xs opacity-80">Tiada bayaran serta-merta — tunggu sebut harga bengkel dahulu.</span>
      </div>

      <div>
        <label class="text-xs text-slate-400">Target Vehicle / Kenderaan</label>
        <select class="form-input" name="vehicle_id" required>
          <?php foreach ($vehicles as $v): ?>
            <option value="<?= $v['id'] ?>"><?= e(vehicleLabel($v)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="text-xs text-slate-400">Service requested (estimate only) / Servis diminta (anggaran)</label>
        <select class="form-input" name="package_id" required>
          <?php foreach ($packages as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $preselect === (int)$p['id'] ? 'selected' : '' ?>>
              <?= e($p['name']) ?> — guide ~<?= formatRM($p['price']) ?> (final quote may differ)
            </option>
          <?php endforeach; ?>
        </select>
        <p class="text-[10px] text-slate-500 mt-1">Listed prices are guides only. Final amount comes after inspection. / Harga panduan sahaja.</p>
      </div>

      <div>
        <label class="text-xs text-slate-400">Target Date / Tarikh</label>
        <input class="form-input" type="date" name="date" id="booking-date" required min="<?= todayDate() ?>" value="<?= e($selectedDate) ?>">
      </div>

      <div>
        <div class="flex justify-between items-center mb-2">
          <label class="text-xs text-slate-400">Live Time Slots / Slot Masa</label>
          <span class="text-[10px] text-slate-500" id="slot-refresh-hint">Max <?= (int)$maxPerSlot ?> cars · live</span>
        </div>
        <div id="slot-grid" class="slot-grid">
          <?php foreach ($slots as $s): ?>
          <label class="slot-option <?= $s['full'] ? 'slot-full' : '' ?>">
            <input type="radio" name="time_window" value="<?= e($s['time']) ?>" <?= $s['full'] ? 'disabled' : '' ?> required>
            <div class="slot-card">
              <span class="slot-time"><?= e($s['time']) ?></span>
              <span class="slot-meta">
                <?php if ($s['full']): ?>
                  <span class="text-red-400">Full · <?= (int)$s['booked'] ?>/<?= (int)$s['max'] ?></span>
                <?php else: ?>
                  <span class="text-emerald-400"><?= (int)$s['available'] ?> left · <?= (int)$s['booked'] ?>/<?= (int)$s['max'] ?> booked</span>
                <?php endif; ?>
              </span>
              <span class="slot-bar"><span style="width:<?= min(100, (int)round(($s['booked'] / max(1,$s['max'])) * 100)) ?>%"></span></span>
              <?php if (!empty($s['bookings'])): ?>
              <span class="slot-booked-list">
                <?php foreach ($s['bookings'] as $b): ?>
                  <span class="slot-chip" title="<?= e($b['pkg_name']) ?>"><?= e($b['plate_no']) ?></span>
                <?php endforeach; ?>
              </span>
              <?php endif; ?>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div>
        <label class="text-xs text-slate-400">Symptoms / Notes (optional)</label>
        <input class="form-input" name="notes" maxlength="255" placeholder="e.g. engine noise, AC not cold…">
      </div>

      <button type="submit" class="btn-primary w-full">Request Booking / Mohon Tempahan</button>
    </form>
  </div>

  <div class="panel-card p-5 space-y-4">
    <h3 class="text-sm font-semibold text-white">How it works / Cara kerja</h3>
    <ol class="text-sm text-slate-400 space-y-2 list-decimal list-inside">
      <li>Pick an open slot (shared workshop capacity)</li>
      <li>Describe the issue / choose a service interest</li>
      <li>Mechanic inspects &amp; sends a <strong class="text-white">quote</strong></li>
      <li>You pay the quoted amount (bank/cash/QR)</li>
      <li>Work starts after payment</li>
    </ol>
    <div id="day-board" class="slot-day-board">
      <h4 class="text-xs font-semibold text-slate-300 mb-2">Today’s workshop board / Papan slot</h4>
      <div id="day-board-body" class="space-y-2 text-xs"></div>
    </div>
  </div>
</div>

<script>
(function () {
  const apiUrl = <?= json_encode(baseUrl('api/slots.php')) ?>;
  const dateInput = document.getElementById('booking-date');
  const grid = document.getElementById('slot-grid');
  const board = document.getElementById('day-board-body');
  let selected = '';

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function renderSlots(data) {
    if (!data || !data.slots) return;
    const prev = document.querySelector('input[name="time_window"]:checked');
    if (prev && !prev.disabled) selected = prev.value;
    grid.innerHTML = '';
    let boardHtml = '';
    data.slots.forEach(function (s) {
      const label = document.createElement('label');
      label.className = 'slot-option' + (s.full ? ' slot-full' : '');
      const checked = (!s.full && selected === s.time) ? ' checked' : '';
      const disabled = s.full ? ' disabled' : '';
      const meta = s.full
        ? '<span class="text-red-400">Full · ' + s.booked + '/' + s.max + '</span>'
        : '<span class="text-emerald-400">' + s.available + ' left · ' + s.booked + '/' + s.max + ' booked</span>';
      const pct = Math.min(100, Math.round((s.booked / Math.max(1, s.max)) * 100));
      let chips = '';
      (s.bookings || []).forEach(function (b) {
        chips += '<span class="slot-chip" title="' + esc(b.pkg_name) + '">' + esc(b.plate_no) + '</span>';
      });
      label.innerHTML =
        '<input type="radio" name="time_window" value="' + esc(s.time) + '"' + disabled + checked + ' required>' +
        '<div class="slot-card"><span class="slot-time">' + esc(s.time) + '</span><span class="slot-meta">' + meta + '</span>' +
        '<span class="slot-bar"><span style="width:' + pct + '%"></span></span>' +
        (chips ? '<span class="slot-booked-list">' + chips + '</span>' : '') +
        '</div>';
      grid.appendChild(label);

      boardHtml += '<div class="slot-board-row' + (s.full ? ' is-full' : '') + '">' +
        '<strong>' + esc(s.time) + '</strong> ' +
        '<span class="app-muted">' + s.booked + '/' + s.max + '</span> ' +
        (chips || '<span class="text-slate-500">— open</span>') +
        '</div>';
    });
    if (board) board.innerHTML = boardHtml || '<p class="text-slate-500">No data</p>';
    const hint = document.getElementById('slot-refresh-hint');
    if (hint) hint.textContent = 'Max ' + data.max_per_slot + ' · updated ' + new Date().toLocaleTimeString();
  }

  async function loadSlots() {
    const date = dateInput.value;
    if (!date) return;
    try {
      const res = await fetch(apiUrl + '?date=' + encodeURIComponent(date) + '&_=' + Date.now(), { cache: 'no-store' });
      const data = await res.json();
      renderSlots(data);
    } catch (e) {
      console.warn('Slot refresh failed', e);
    }
  }

  dateInput.addEventListener('change', function () { selected = ''; loadSlots(); });
  loadSlots();
  setInterval(loadSlots, 8000);
})();
</script>
<?php endif; ?>
<?php renderFooter(); ?>
