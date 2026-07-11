<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('admin');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('admin/users.php'));
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $db->prepare('INSERT INTO users (username,email,password_hash,role,full_name,contact_no) VALUES (?,?,?,?,?,?)')
           ->execute([trim($_POST['username']), trim($_POST['email']), $hash, $_POST['role'], trim($_POST['full_name']), trim($_POST['contact_no'])]);
        flash('success', 'User created.');
    } elseif ($action === 'toggle') {
        $db->prepare('UPDATE users SET is_active = NOT is_active WHERE id=? AND id!=?')->execute([(int)$_POST['id'], $user['id']]);
        flash('success', 'User status updated.');
    }
    redirect(baseUrl('admin/users.php'));
}

$users = $db->query('SELECT id,username,email,role,full_name,contact_no,is_active,created_at FROM users ORDER BY role, full_name')->fetchAll();
renderHeader('User Management', $user, 'users');
?>
<h2 class="section-title">User Management</h2>
<p class="section-subtitle">Manage admin, mechanic, and customer accounts</p>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="panel-card p-5">
    <h3 class="text-sm font-semibold text-white mb-4">Create User</h3>
    <form method="POST" class="space-y-3">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <div><label class="text-xs text-slate-400">Username</label><input class="form-input" name="username" required></div>
      <div><label class="text-xs text-slate-400">Email</label><input class="form-input" type="email" name="email" required></div>
      <div><label class="text-xs text-slate-400">Password</label><input class="form-input" type="password" name="password" required minlength="6"></div>
      <div><label class="text-xs text-slate-400">Role</label><select class="form-input" name="role"><option value="customer">Customer</option><option value="mechanic">Mechanic</option><option value="admin">Admin</option></select></div>
      <div><label class="text-xs text-slate-400">Full Name</label><input class="form-input" name="full_name" required></div>
      <div><label class="text-xs text-slate-400">Contact</label><input class="form-input" name="contact_no"></div>
      <button type="submit" class="btn-primary">Create Account</button>
    </form>
  </div>
  <div class="lg:col-span-2 panel-card p-5">
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Contact</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td class="text-white"><?= e($u['full_name']) ?></td>
        <td><?= e($u['username']) ?></td>
        <td><span class="badge badge-<?= $u['role']==='admin'?'approved':($u['role']==='mechanic'?'progress':'pending') ?>"><?= e($u['role']) ?></span></td>
        <td><?= e($u['contact_no']) ?></td>
        <td><?= $u['is_active'] ? '<span class="text-emerald-400">Active</span>' : '<span class="text-red-400">Disabled</span>' ?></td>
        <td><?php if ($u['id'] != $user['id']): ?><form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn-secondary"><?= $u['is_active']?'Disable':'Enable' ?></button></form><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php renderFooter(); ?>
