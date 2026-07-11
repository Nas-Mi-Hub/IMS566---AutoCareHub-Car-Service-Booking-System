<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('customer');
$db = getDB();
ensureSchemaUpdates();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'feedback') {
    validateCsrf(baseUrl('user/history.php'));
    $hid = (int) ($_POST['history_id'] ?? 0);
    // Accept rating from hidden field (star JS) or select fallback
    $rating = (int) ($_POST['rating'] ?? $_POST['rating_value'] ?? 0);
    $feedback = trim($_POST['feedback'] ?? '');
    $result = submitServiceFeedback($hid, (int) $user['id'], $rating, $feedback);
    flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    redirect(baseUrl('user/history.php'));
}

$filter = trim($_GET['q'] ?? '');
$sql = "SELECT sh.*, v.plate_no, v.owner_name, v.current_mileage, v.last_service_mileage, v.last_service_date,
               sp.name AS pkg_name
        FROM service_history sh
        JOIN vehicles v ON v.id=sh.vehicle_id
        JOIN service_packages sp ON sp.id=sh.package_id
        WHERE sh.user_id=?";
$params = [$user['id']];
if ($filter) { $sql .= ' AND (v.plate_no LIKE ? OR sp.name LIKE ?)'; $q="%$filter%"; $params[]=$q; $params[]=$q; }
$sql .= ' ORDER BY sh.completion_date DESC';
$stmt = $db->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();

renderHeader('Service History', $user, 'history');
?>
<h2 class="section-title">Service History / Sejarah Servis</h2>
<p class="section-subtitle">Rate completed jobs 1–5 stars · next service notes included / Nilai servis &amp; nota servis seterusnya</p>
<div class="panel-card p-5">
  <form method="GET" class="mb-4"><input class="form-input" name="q" placeholder="Filter by plate or package…" value="<?= e($filter) ?>"></form>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Date</th><th>Vehicle</th><th>Service</th><th>Payment</th><th>Next service</th><th>Your Rating</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $nextKm = $r['last_service_mileage'] !== null ? ((int)$r['last_service_mileage'] + getServiceIntervalKm()) : null;
      $nextDate = $r['last_service_date'] ? date('Y-m-d', strtotime($r['last_service_date'] . ' +' . getServiceIntervalMonths() . ' months')) : null;
    ?>
    <tr>
      <td><?= e($r['completion_date']) ?></td>
      <td><?= e($r['plate_no'].' – '.$r['owner_name']) ?>
        <?php if ($r['last_service_mileage'] !== null): ?>
          <br><span class="text-xs text-slate-500"><?= number_format((int)$r['last_service_mileage']) ?> km at service</span>
        <?php endif; ?>
      </td>
      <td><?= e($r['pkg_name']) ?></td>
      <td class="text-emerald-400 font-semibold"><?= formatRM($r['gross_payment']) ?></td>
      <td class="text-xs">
        <?php if ($nextKm || $nextDate): ?>
          <span class="text-accent"><?= $nextKm ? number_format($nextKm).' km' : '' ?><?= ($nextKm && $nextDate) ? ' / ' : '' ?><?= $nextDate ? e($nextDate) : '' ?></span>
        <?php endif; ?>
        <?php if (!empty($r['next_service_notes'])): ?>
          <details class="mt-1"><summary class="cursor-pointer text-slate-400">Checklist</summary>
            <pre class="whitespace-pre-wrap text-[10px] text-slate-400 mt-1"><?= e($r['next_service_notes']) ?></pre>
          </details>
        <?php endif; ?>
      </td>
      <td>
        <?php if (!empty($r['rating'])): ?>
          <?= starRatingHtml((int)$r['rating']) ?>
          <?php if (!empty($r['feedback'])): ?>
            <p class="text-xs text-slate-400 mt-1"><?= e($r['feedback']) ?></p>
          <?php endif; ?>
        <?php elseif ($r['status'] === 'Settled'): ?>
          <form method="POST" class="feedback-form space-y-2" data-history-id="<?= (int)$r['id'] ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="feedback">
            <input type="hidden" name="history_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="rating" id="rating-val-<?= (int)$r['id'] ?>" value="" required>
            <div class="star-picker" data-target="rating-val-<?= (int)$r['id'] ?>">
              <?php for ($i = 1; $i <= 5; $i++): ?>
              <button type="button" class="star-btn" data-value="<?= $i ?>" aria-label="<?= $i ?> stars">★</button>
              <?php endfor; ?>
            </div>
            <select class="form-input" style="font-size:.75rem;padding:.3rem" name="rating_value" id="rating-sel-<?= (int)$r['id'] ?>">
              <option value="">Or select stars…</option>
              <?php for ($i = 1; $i <= 5; $i++): ?>
              <option value="<?= $i ?>"><?= $i ?> star<?= $i > 1 ? 's' : '' ?></option>
              <?php endfor; ?>
            </select>
            <input class="form-input" style="font-size:.75rem;padding:.3rem .5rem" name="feedback" placeholder="Optional comment" maxlength="500">
            <button type="submit" class="btn-primary" style="font-size:.7rem;padding:.3rem .6rem;width:auto">Submit / Hantar</button>
          </form>
        <?php else: ?>
          <span class="app-muted">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?><tr><td colspan="6" class="empty-state">No service history yet</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<script>
document.querySelectorAll('.star-picker').forEach(function (picker) {
  const targetId = picker.getAttribute('data-target');
  const hidden = document.getElementById(targetId);
  const form = picker.closest('form');
  const sel = form ? form.querySelector('select[name="rating_value"]') : null;
  const buttons = picker.querySelectorAll('.star-btn');

  function setRating(n) {
    n = parseInt(n, 10) || 0;
    if (hidden) hidden.value = n > 0 ? String(n) : '';
    if (sel && n > 0) sel.value = String(n);
    buttons.forEach(function (btn) {
      const v = parseInt(btn.getAttribute('data-value'), 10);
      btn.classList.toggle('active', v <= n && n > 0);
    });
  }

  buttons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      setRating(btn.getAttribute('data-value'));
    });
  });
  if (sel) {
    sel.addEventListener('change', function () { setRating(sel.value); });
  }
  if (form) {
    form.addEventListener('submit', function (e) {
      let n = parseInt(hidden && hidden.value ? hidden.value : '0', 10);
      if (!n && sel) n = parseInt(sel.value || '0', 10);
      if (n < 1 || n > 5) {
        e.preventDefault();
        alert('Please select 1–5 stars. / Sila pilih 1–5 bintang.');
        return false;
      }
      if (hidden) hidden.value = String(n);
    });
  }
});
</script>
<?php renderFooter(); ?>
