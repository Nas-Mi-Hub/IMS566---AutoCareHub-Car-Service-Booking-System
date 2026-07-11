<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

if (currentUser()) redirect(dashboardForRole(currentUser()['role']));

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('auth/login.php'));
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } elseif (attemptLogin($username, $password)) {
        $user = currentUser();
        flash('success', 'Welcome back, ' . $user['full_name'] . '!');
        redirect(dashboardForRole($user['role']));
    } else {
        $error = 'Invalid username or password.';
    }
}

renderHeader('Login', null, 'login');
?>
<div class="flex items-center justify-center min-h-[70vh]">
  <div class="panel-card p-8 w-full max-w-md">
    <div class="text-center mb-6">
      <img src="<?= baseUrl('assets/images/logo.png') ?>" alt="AutoCare Hub" class="mx-auto mb-3 h-16 w-auto object-contain">
      <h2 class="text-xl font-bold text-white">Sign In to AutoCare Hub</h2>
      <p class="text-sm text-slate-400 mt-1">Vehicle Service Management System</p>
    </div>
    <?php if ($error): ?><div class="mb-4 p-3 rounded-lg flash-error text-sm"><?= e($error) ?></div><?php endif; ?>
    <form method="POST" class="space-y-4">
      <?= csrfField() ?>
      <div>
        <label class="block text-xs text-slate-400 mb-1">Username or Email</label>
        <input class="form-input" name="username" required autofocus placeholder="admin" value="<?= e($_POST['username'] ?? '') ?>">
      </div>
      <div>
        <label class="block text-xs text-slate-400 mb-1">Password</label>
        <input class="form-input" type="password" name="password" required placeholder="••••••••">
      </div>
      <button type="submit" class="btn-primary w-full">Sign In</button>
    </form>
    <p class="text-center text-sm text-slate-400 mt-4">
      New here? <a href="<?= baseUrl('auth/register.php') ?>" class="text-accent hover:underline font-medium">Create an account</a>
    </p>
    <div class="mt-5 p-3 bg-navy rounded-lg text-xs text-slate-500">
      <p class="font-semibold text-slate-400 mb-1">Demo Accounts:</p>
      <p>Admin: <code>admin</code> / <code>Admin@123</code></p>
      <p>Mechanic: <code>mechanic1</code> or <code>mechanic2</code> / <code>Mechanic@123</code></p>
      <p>Customer: <code>ahmad</code>, <code>siti</code>, or <code>tanwm</code> / <code>Customer@123</code></p>
    </div>
    <p class="text-center text-sm text-slate-500 mt-4"><a href="<?= baseUrl('index.php') ?>" class="text-accent hover:underline">← Back to Home</a></p>
  </div>
</div>
<?php renderFooter(); ?>
