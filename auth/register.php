<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

if (currentUser()) redirect(dashboardForRole(currentUser()['role']));

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('auth/register.php'));
    $result = registerUser(
        $_POST['username'] ?? '',
        $_POST['email'] ?? '',
        $_POST['password'] ?? '',
        $_POST['full_name'] ?? '',
        $_POST['contact_no'] ?? ''
    );
    if ($result['ok']) {
        attemptLogin(trim($_POST['username'] ?? ''), $_POST['password'] ?? '');
        $user = currentUser();
        flash('success', 'Account created successfully. Welcome, ' . $user['full_name'] . '!');
        redirect(dashboardForRole($user['role']));
    } else {
        $error = $result['error'];
    }
}

renderHeader('Register', null, 'register');
?>
<div class="flex items-center justify-center min-h-[70vh]">
  <div class="panel-card p-8 w-full max-w-md">
    <div class="text-center mb-6">
      <img src="<?= baseUrl('assets/images/logo.png') ?>" alt="AutoCare Hub" class="mx-auto mb-3 h-16 w-auto object-contain">
      <h2 class="text-xl font-bold text-white">Create Customer Account</h2>
      <p class="text-sm text-slate-400 mt-1">Sign up to book services and manage your vehicles</p>
    </div>
    <?php if ($error): ?><div class="mb-4 p-3 rounded-lg flash-error text-sm"><?= e($error) ?></div><?php endif; ?>
    <form method="POST" class="space-y-4">
      <?= csrfField() ?>
      <div>
        <label class="block text-xs text-slate-400 mb-1">Full Name</label>
        <input class="form-input" name="full_name" required autofocus placeholder="Ahmad Razak bin Ismail" value="<?= e($_POST['full_name'] ?? '') ?>">
      </div>
      <div>
        <label class="block text-xs text-slate-400 mb-1">Username</label>
        <input class="form-input" name="username" required placeholder="ahmad" pattern="[a-zA-Z0-9_]{3,50}" value="<?= e($_POST['username'] ?? '') ?>">
      </div>
      <div>
        <label class="block text-xs text-slate-400 mb-1">Email</label>
        <input class="form-input" type="email" name="email" required placeholder="you@email.com" value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div>
        <label class="block text-xs text-slate-400 mb-1">Contact Number</label>
        <input class="form-input" name="contact_no" placeholder="012-3456789" value="<?= e($_POST['contact_no'] ?? '') ?>">
      </div>
      <div>
        <label class="block text-xs text-slate-400 mb-1">Password</label>
        <input class="form-input" type="password" name="password" required minlength="6" placeholder="Min. 6 characters">
      </div>
      <button type="submit" class="btn-primary w-full">Create Account</button>
    </form>
    <p class="text-center text-sm text-slate-500 mt-4">
      Already have an account? <a href="<?= baseUrl('auth/login.php') ?>" class="text-accent hover:underline">Sign In</a>
    </p>
    <p class="text-center text-sm text-slate-500 mt-2"><a href="<?= baseUrl('index.php') ?>" class="text-slate-400 hover:underline">← Back to Home</a></p>
  </div>
</div>
<?php renderFooter(); ?>
