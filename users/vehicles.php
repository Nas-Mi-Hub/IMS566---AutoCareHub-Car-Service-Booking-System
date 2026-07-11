<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('customer');
$db = getDB();
ensureSchemaUpdates();

$catalog = vehicleCatalog();
$years = vehicleYears();
$types = vehicleTypes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('user/vehicles.php'));
    $action = $_POST['action'] ?? 'add';
    if ($action === 'add') {
        if (!canAddVehicle($user['id'])) {
            flash('error', 'Vehicle limit reached. Contact the workshop. / Had kenderaan tercapai.');
        } else {
            $plate = strtoupper(trim($_POST['plate_no'] ?? ''));
            $type = trim($_POST['vehicle_type'] ?? 'Sedan');
            if (!in_array($type, $types, true)) {
                $type = 'Sedan';
            }
            $brand = trim($_POST['brand'] ?? '');
            $model = trim($_POST['model_variant'] ?? '');
            if ($model === 'Other model' || $model === '__other__') {
                $model = trim($_POST['model_other'] ?? 'Other');
            }
            $year = isset($_POST['year']) && $_POST['year'] !== '' ? (int) $_POST['year'] : null;
            if ($year !== null && ($year < 1990 || $year > (int) date('Y') + 1)) {
                $year = null;
            }
            $mileage = isset($_POST['current_mileage']) && $_POST['current_mileage'] !== ''
                ? max(0, (int) $_POST['current_mileage']) : null;
            $contact = trim($_POST['contact_no'] ?? '') ?: ($user['contact_no'] ?? '');

            $check = $db->prepare('SELECT id FROM vehicles WHERE plate_no=?');
            $check->execute([$plate]);
            if ($check->fetch()) {
                flash('error', 'Plate number already registered. / Nombor plat sudah didaftarkan.');
            } elseif ($brand === '' || $model === '') {
                flash('error', 'Brand and model are required. / Jenama dan model diperlukan.');
            } else {
                $db->prepare('INSERT INTO vehicles (user_id,owner_name,contact_no,plate_no,brand,model_variant,year,vehicle_type,current_mileage) VALUES (?,?,?,?,?,?,?,?,?)')
                   ->execute([
                       $user['id'],
                       trim($_POST['owner_name']),
                       $contact,
                       $plate,
                       $brand,
                       $model,
                       $year,
                       $type,
                       $mileage,
                   ]);
                // Keep user contact in sync if provided
                if ($contact !== '' && empty($user['contact_no'])) {
                    $db->prepare('UPDATE users SET contact_no=? WHERE id=?')->execute([$contact, $user['id']]);
                }
                flash('success', 'Vehicle registered. / Kenderaan didaftarkan.');
            }
        }
    } elseif ($action === 'update_mileage') {
        $vid = (int) ($_POST['id'] ?? 0);
        $mileage = max(0, (int) ($_POST['current_mileage'] ?? 0));
        $db->prepare('UPDATE vehicles SET current_mileage=? WHERE id=? AND user_id=?')
           ->execute([$mileage, $vid, $user['id']]);
        flash('success', 'Mileage updated. Service reminders use this reading. / Odometer dikemaskini.');
        checkServiceReminders((int) $user['id']);
    }
    redirect(baseUrl('user/vehicles.php'));
}

$vehicles = $db->prepare('SELECT * FROM vehicles WHERE user_id=? ORDER BY created_at DESC');
$vehicles->execute([$user['id']]);
$intervalKm = getServiceIntervalKm();
$intervalMonths = getServiceIntervalMonths();

renderHeader('My Vehicles', $user, 'vehicles');
?>
<h2 class="section-title">My Vehicles / Kenderaan Saya</h2>
<p class="section-subtitle">Register with brand, model, year &amp; mileage — contact number used for Call / WhatsApp by workshop</p>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="panel-card p-5">
    <h3 class="text-sm font-semibold text-white mb-4">Register Vehicle / Daftar Kenderaan</h3>
    <form method="POST" class="space-y-3" id="vehicle-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <div><label class="text-xs text-slate-400">Owner Name</label><input class="form-input" name="owner_name" required value="<?= e($user['full_name']) ?>"></div>
      <div><label class="text-xs text-slate-400">Contact / WhatsApp No</label>
        <input class="form-input" name="contact_no" required placeholder="e.g. 0123456789" value="<?= e($user['contact_no'] ?? '') ?>">
        <p class="text-[10px] text-slate-500 mt-1">Workshop can call / WhatsApp this number.</p>
      </div>
      <div><label class="text-xs text-slate-400">Plate Reg No</label><input class="form-input" name="plate_no" required style="text-transform:uppercase"></div>
      <div><label class="text-xs text-slate-400">Vehicle Type</label>
        <select class="form-input" name="vehicle_type" required>
          <?php foreach ($types as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label class="text-xs text-slate-400">Brand / Jenama</label>
        <select class="form-input" name="brand" id="brand-select" required>
          <option value="">— Select brand —</option>
          <?php foreach (array_keys($catalog) as $b): ?>
          <option value="<?= e($b) ?>"><?= e($b) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="text-xs text-slate-400">Model</label>
        <select class="form-input" name="model_variant" id="model-select" required>
          <option value="">— Select model —</option>
        </select>
        <input class="form-input mt-1 hidden" name="model_other" id="model-other" placeholder="Specify model">
      </div>
      <div><label class="text-xs text-slate-400">Year / Tahun</label>
        <select class="form-input" name="year">
          <option value="">— Optional —</option>
          <?php foreach ($years as $y): ?><option value="<?= $y ?>"><?= $y ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label class="text-xs text-slate-400">Current Mileage (km)</label>
        <input class="form-input" type="number" name="current_mileage" min="0" step="1" placeholder="e.g. 45000"></div>
      <button type="submit" class="btn-primary">Register Vehicle</button>
    </form>
  </div>
  <div class="lg:col-span-2 panel-card p-5">
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Plate</th><th>Type</th><th>Details</th><th>Contact</th><th>Mileage</th><th>Last / Next</th><th>Update km</th></tr></thead>
      <tbody>
      <?php while ($v = $vehicles->fetch()):
        $nextKm = $v['last_service_mileage'] !== null ? ((int)$v['last_service_mileage'] + $intervalKm) : null;
        $nextDate = $v['last_service_date'] ? date('Y-m-d', strtotime($v['last_service_date'] . ' +' . $intervalMonths . ' months')) : null;
      ?>
      <tr>
        <td class="text-accent font-semibold"><?= e($v['plate_no']) ?></td>
        <td><span class="badge badge-settled"><?= e($v['vehicle_type'] ?? 'Sedan') ?></span></td>
        <td><?= e($v['brand'].' '.$v['model_variant']) ?>
          <?php if (!empty($v['year'])): ?> <span class="text-xs text-slate-500">(<?= (int)$v['year'] ?>)</span><?php endif; ?>
          <br><span class="text-xs text-slate-500"><?= e($v['owner_name']) ?></span>
        </td>
        <td><?= contactPhoneButtons($v['contact_no'] ?? '', 'Hi, regarding vehicle ' . $v['plate_no']) ?></td>
        <td><?= $v['current_mileage'] !== null ? number_format((int)$v['current_mileage']) . ' km' : '—' ?></td>
        <td class="text-xs">
          <?php if ($v['last_service_date']): ?>
            Last: <?= e($v['last_service_date']) ?>
            <?php if ($nextKm || $nextDate): ?>
            <br><span class="text-accent">Next: <?= $nextKm ? number_format($nextKm).' km' : '' ?><?= ($nextKm && $nextDate) ? ' / ' : '' ?><?= $nextDate ? e($nextDate) : '' ?></span>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-slate-500">No service yet</span>
          <?php endif; ?>
        </td>
        <td>
          <form method="POST" class="flex gap-1 items-center">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_mileage">
            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
            <input class="form-input" style="width:100px;padding:.3rem" type="number" name="current_mileage" min="0" value="<?= e((string)($v['current_mileage'] ?? '')) ?>" required>
            <button class="btn-secondary" style="padding:.3rem .5rem;font-size:.7rem">Save</button>
          </form>
        </td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table></div>
  </div>
</div>
<script>
const CATALOG = <?= json_encode($catalog, JSON_UNESCAPED_UNICODE) ?>;
const brandSel = document.getElementById('brand-select');
const modelSel = document.getElementById('model-select');
const modelOther = document.getElementById('model-other');
function fillModels() {
  const brand = brandSel.value;
  modelSel.innerHTML = '<option value="">— Select model —</option>';
  modelOther.classList.add('hidden');
  modelOther.required = false;
  (CATALOG[brand] || []).forEach(function (m) {
    const o = document.createElement('option');
    o.value = m;
    o.textContent = m;
    modelSel.appendChild(o);
  });
}
brandSel.addEventListener('change', fillModels);
modelSel.addEventListener('change', function () {
  if (modelSel.value === 'Other model') {
    modelOther.classList.remove('hidden');
    modelOther.required = true;
  } else {
    modelOther.classList.add('hidden');
    modelOther.required = false;
  }
});
</script>
<?php renderFooter(); ?>
