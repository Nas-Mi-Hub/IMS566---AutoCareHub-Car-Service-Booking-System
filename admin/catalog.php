<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('admin');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('admin/catalog.php'));
    $action = $_POST['action'] ?? 'add';
    if ($action === 'add') {
        $db->prepare('INSERT INTO service_packages (name,description,category,price,duration_mins) VALUES (?,?,?,?,?)')
           ->execute([trim($_POST['name']), trim($_POST['description']), trim($_POST['category']), (float)$_POST['price'], (int)$_POST['duration_mins']]);
        flash('success', 'Package added.');
    } elseif ($action === 'edit') {
        $db->prepare('UPDATE service_packages SET price=? WHERE id=?')->execute([(float)$_POST['price'], (int)$_POST['id']]);
        flash('success', 'Price updated.');
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $used = $db->prepare('SELECT COUNT(*) FROM service_history WHERE package_id=?'); $used->execute([$id]);
        if ((int)$used->fetchColumn() > 0) flash('error', 'Cannot delete — used in service history.');
        else { $db->prepare('DELETE FROM service_packages WHERE id=?')->execute([$id]); flash('success', 'Package deleted.'); }
    }
    redirect(baseUrl('admin/catalog.php'));
}

$packages = $db->query('SELECT * FROM service_packages ORDER BY category, name')->fetchAll();
renderHeader('Service Catalog', $user, 'catalog');
?>
<h2 class="section-title">Service Catalog</h2>
<p class="section-subtitle">Manage workshop service packages and pricing</p>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="panel-card p-5">
    <h3 class="text-sm font-semibold text-white mb-4">Add Package</h3>
    <form method="POST" class="space-y-3">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <div><label class="text-xs text-slate-400">Name</label><input class="form-input" name="name" required></div>
      <div><label class="text-xs text-slate-400">Description</label><textarea class="form-input" name="description" rows="2"></textarea></div>
      <div><label class="text-xs text-slate-400">Category</label><select class="form-input" name="category"><option>Maintenance</option><option>Safety</option><option>Electrical</option><option>Tyres</option><option>Mechanical</option><option>General</option></select></div>
      <div><label class="text-xs text-slate-400">Price (RM)</label><input class="form-input" type="number" name="price" step="0.01" required></div>
      <div><label class="text-xs text-slate-400">Duration (mins)</label><input class="form-input" type="number" name="duration_mins" value="60"></div>
      <button type="submit" class="btn-primary">Add Package</button>
    </form>
  </div>
  <div class="lg:col-span-2 panel-card p-5">
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Name</th><th>Category</th><th>Price</th><th>Duration</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($packages as $p): ?>
      <tr>
        <td class="text-white"><?= e($p['name']) ?><br><span class="text-xs text-slate-500"><?= e($p['description']) ?></span></td>
        <td><?= e($p['category']) ?></td>
        <td class="text-emerald-400 font-semibold"><?= formatRM($p['price']) ?></td>
        <td><?= (int)$p['duration_mins'] ?> min</td>
        <td class="whitespace-nowrap">
          <form method="POST" class="inline-flex gap-1 items-center">
      <?= csrfField() ?>
            <input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= $p['id'] ?>">
            <input class="form-input" name="price" type="number" step="0.01" value="<?= $p['price'] ?>" style="width:80px;font-size:.75rem;padding:.3rem">
            <button class="btn-secondary">Save</button>
          </form>
          <form method="POST" class="inline" onsubmit="return confirm('Delete package?')">
      <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn-danger">Del</button></form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php renderFooter(); ?>
