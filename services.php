<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = currentUser();
$db = getDB();
$packages = $db->query('SELECT * FROM service_packages WHERE is_active=1 ORDER BY category, name')->fetchAll();
$byCategory = [];
foreach ($packages as $p) $byCategory[$p['category']][] = $p;

renderHeader('Services Available', $user, 'services');
?>
<h2 class="section-title">Services Available</h2>
<p class="section-subtitle">Professional vehicle care packages — prices in Malaysian Ringgit (RM)</p>

<?php foreach ($byCategory as $cat => $items): ?>
<div class="mb-8">
  <h3 class="text-sm font-semibold text-accent uppercase tracking-wider mb-3"><?= e($cat) ?></h3>
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($items as $p): ?>
    <div class="panel-card p-5">
      <h4 class="font-semibold text-white mb-1"><?= e($p['name']) ?></h4>
      <p class="text-sm text-slate-400 mb-3"><?= e($p['description']) ?></p>
      <div class="flex items-center justify-between">
        <span class="text-lg font-bold text-emerald-400"><?= formatRM($p['price']) ?></span>
        <span class="text-xs text-slate-500">~<?= (int)$p['duration_mins'] ?> min</span>
      </div>
      <?php if ($user && $user['role'] === 'customer'): ?>
      <a href="<?= baseUrl('user/booking.php?package=' . $p['id']) ?>" class="btn-primary mt-3" style="font-size:.75rem;padding:.5rem">Book Now</a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<div class="panel-card p-6 text-center mt-4">
  <p class="text-slate-400 mb-3">Need a custom service or fleet package?</p>
  <a href="<?= baseUrl('contact.php') ?>" class="btn-primary" style="width:auto;display:inline-block">Contact Us</a>
</div>
<?php renderFooter(); ?>