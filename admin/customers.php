<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('admin');
$db = getDB();
ensureSchemaUpdates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('admin/customers.php'));
    $action = $_POST['action'] ?? 'add';
    if ($action === 'add') {
        $plate = strtoupper(trim($_POST['plate_no'] ?? ''));
        $type = trim($_POST['vehicle_type'] ?? 'Sedan');
        if (!in_array($type, vehicleTypes(), true)) $type = 'Sedan';
        $mileage = isset($_POST['current_mileage']) && $_POST['current_mileage'] !== ''
            ? max(0, (int)$_POST['current_mileage']) : null;
        $check = $db->prepare('SELECT id FROM vehicles WHERE plate_no=?'); $check->execute([$plate]);
        if ($check->fetch()) { flash('error', 'Duplicate plate number.'); }
        else {
            $ownerId = (int) ($_POST['user_id'] ?? 0);
            $db->prepare('INSERT INTO vehicles (user_id,owner_name,contact_no,plate_no,brand,model_variant,vehicle_type,current_mileage) VALUES (?,?,?,?,?,?,?,?)')
               ->execute([$ownerId, trim($_POST['owner_name']), trim($_POST['contact_no']), $plate, trim($_POST['brand']), trim($_POST['model_variant']), $type, $mileage]);
            flash('success', 'Vehicle committed to directory.');
        }
    } elseif ($action === 'delete') {
        $vid = (int) $_POST['id'];
        $active = $db->prepare("SELECT COUNT(*) FROM appointments WHERE vehicle_id=? AND status NOT IN ('Cancelled','Completed')");
        $active->execute([$vid]);
        if ((int)$active->fetchColumn() > 0) flash('error', 'Vehicle has active appointments — cancel them first.');
        else { $db->prepare('DELETE FROM vehicles WHERE id=?')->execute([$vid]); flash('success', 'Vehicle deleted.'); }
    }
    redirect(baseUrl('admin/customers.php'));
}

$search = trim($_GET['q'] ?? '');
$sql = 'SELECT v.*, u.username, u.contact_no AS user_phone, u.full_name AS customer_name FROM vehicles v JOIN users u ON u.id=v.user_id';
$params = [];
if ($search) { $sql .= ' WHERE v.plate_no LIKE ? OR v.owner_name LIKE ? OR v.brand LIKE ? OR v.contact_no LIKE ?'; $q = "%$search%"; $params = [$q,$q,$q,$q]; }
$sql .= ' ORDER BY v.created_at DESC';
$stmt = $db->prepare($sql); $stmt->execute($params);
$vehicles = $stmt->fetchAll();
$customers = $db->query("SELECT id, full_name, username, contact_no FROM users WHERE role='customer'")->fetchAll();
$types = vehicleTypes();

renderHeader('Customers & Cars', $user, 'customers');
?>
<h2 class="section-title">Customers & Cars</h2>
<p class="section-subtitle">Master registry — Call / WhatsApp customers · vehicle type, mileage & onboarding</p>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="panel-card p-5">
    <h3 class="text-sm font-semibold text-white mb-4">Onboard Vehicle</h3>
    <form method="POST" class="space-y-3">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <div><label class="text-xs text-slate-400">Customer Account</label>
        <select class="form-input" name="user_id" required><?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['full_name']) ?></option><?php endforeach; ?></select></div>
      <div><label class="text-xs text-slate-400">Owner Name</label><input class="form-input" name="owner_name" required></div>
      <div><label class="text-xs text-slate-400">Contact No</label><input class="form-input" name="contact_no" required></div>
      <div><label class="text-xs text-slate-400">Plate Reg No</label><input class="form-input" name="plate_no" required style="text-transform:uppercase"></div>
      <div><label class="text-xs text-slate-400">Vehicle Type</label>
        <select class="form-input" name="vehicle_type" required>
          <?php foreach ($types as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label class="text-xs text-slate-400">Brand / Make</label><input class="form-input" name="brand" required></div>
      <div><label class="text-xs text-slate-400">Model Variant</label><input class="form-input" name="model_variant" required></div>
      <div><label class="text-xs text-slate-400">Current Mileage (km)</label><input class="form-input" type="number" name="current_mileage" min="0" placeholder="Optional"></div>
      <button type="submit" class="btn-primary">Commit Directory Records</button>
    </form>
  </div>
  <div class="lg:col-span-2 panel-card p-5">
    <form method="GET" class="mb-4"><input class="form-input" name="q" placeholder="Search plate or names…" value="<?= e($search) ?>"></form>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Owner</th><th>Plate</th><th>Type</th><th>Car Details</th><th>Contact</th><th>Mileage</th><th>Account</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($vehicles as $v):
        $phone = $v['contact_no'] ?: ($v['user_phone'] ?? '');
        $wa = 'Hi ' . ($v['owner_name'] ?: $v['customer_name']) . ', this is AutoCare Hub regarding your vehicle ' . $v['plate_no'] . '.';
      ?>
      <tr>
        <td class="text-white"><?= e($v['owner_name']) ?></td>
        <td><span class="text-accent font-semibold"><?= e($v['plate_no']) ?></span></td>
        <td><span class="badge badge-settled"><?= e($v['vehicle_type'] ?? 'Sedan') ?></span></td>
        <td><?= e($v['brand'].' '.$v['model_variant']) ?></td>
        <td><?= contactPhoneButtons($phone, $wa) ?></td>
        <td class="text-sm"><?= $v['current_mileage'] !== null ? number_format((int)$v['current_mileage']).' km' : '—' ?></td>
        <td class="text-xs"><?= e($v['username']) ?></td>
        <td><form method="POST" onsubmit="return confirm('Delete this vehicle?')">
      <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $v['id'] ?>"><button class="btn-danger">Delete</button></form></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php renderFooter(); ?>
