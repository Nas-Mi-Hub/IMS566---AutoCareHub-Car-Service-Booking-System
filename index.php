<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = currentUser();
$db = getDB();
$pkgCount = (int) $db->query('SELECT COUNT(*) FROM service_packages WHERE is_active=1')->fetchColumn();
$clientCount = (int) $db->query('SELECT COUNT(DISTINCT user_id) FROM vehicles')->fetchColumn();

renderHeader('Home', $user, 'home');
?>
<section class="hero-gradient rounded-xl p-8 lg:p-12 mb-8 border border-slate-700/50">
  <div class="max-w-2xl">
    <h1 class="text-3xl lg:text-4xl font-bold text-white mb-3">Your Trusted <span class="text-accent">Vehicle Service</span> Partner</h1>
    <p class="text-slate-400 mb-6 leading-relaxed">AutoCare Hub streamlines workshop operations — from booking and scheduling to receipts, reports, and real-time workshop diagnostics. Built for Malaysian SMEs.</p>
    <div class="flex flex-wrap gap-3">
      <?php if ($user): ?>
        <a href="<?= dashboardForRole($user['role']) ?>" class="btn-primary" style="width:auto">Go to Dashboard</a>
      <?php else: ?>
        <a href="<?= baseUrl('auth/login.php') ?>" class="btn-primary" style="width:auto">Sign In</a>
        <a href="<?= baseUrl('auth/register.php') ?>" class="btn-secondary" style="padding:.7rem 1.5rem;font-size:.875rem">Create Account</a>
      <?php endif; ?>
      <a href="<?= baseUrl('user/booking.php') ?>" class="btn-secondary" style="padding:.7rem 1.5rem;font-size:.875rem">Book a Service</a>
      <a href="<?= baseUrl('services.php') ?>" class="btn-secondary" style="padding:.7rem 1.5rem;font-size:.875rem">View Services</a>
    </div>
  </div>
</section>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
  <div class="stat-card text-center"><p class="text-3xl font-bold text-accent"><?= $clientCount ?></p><p class="text-xs text-slate-400 mt-1">Active Clients</p></div>
  <div class="stat-card text-center"><p class="text-3xl font-bold text-emerald-400"><?= $pkgCount ?></p><p class="text-xs text-slate-400 mt-1">Services Available</p></div>
  <div class="stat-card text-center"><p class="text-3xl font-bold text-sky-400">24/7</p><p class="text-xs text-slate-400 mt-1">Online Booking</p></div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
  <?php
  $features = [
    ['Online Booking', 'Schedule service slots for your vehicle in minutes.', 'user/booking.php'],
    ['Service Catalog', 'Browse maintenance, safety, and electrical packages.', 'services.php'],
    ['Receipts & Reports', 'Download PDF invoices and operation reports.', 'user/receipt.php'],
    ['My Appointments', 'Book, pay, and track your service progress.', 'user/appointments.php'],
    ['Contact Us', 'Reach our Petaling Jaya workshop team.', 'contact.php'],
    ['Workshop Dashboard', 'Real-time capacity and appointment tracking.', 'auth/login.php'],
  ];
  foreach ($features as [$title, $desc, $link]):
  ?>
  <a href="<?= baseUrl($link) ?>" class="panel-card p-5 hover:border-accent/50 transition block">
    <h3 class="font-semibold text-white mb-1"><?= e($title) ?></h3>
    <p class="text-sm text-slate-400"><?= e($desc) ?></p>
  </a>
  <?php endforeach; ?>
</div>
<?php renderFooter(); ?>