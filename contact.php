<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = currentUser();
$db = getDB();
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('contact.php'));
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    if ($name && $email && $subject && $message) {
        $db->prepare('INSERT INTO contact_submissions (name,email,phone,subject,message) VALUES (?,?,?,?,?)')
           ->execute([$name, $email, $phone, $subject, $message]);
        notify(1, 'New Contact Inquiry', "$name submitted: $subject", 'info', baseUrl('admin/contact.php'));
        flash('success', 'Your message has been sent. We will respond within 24 hours.');
        redirect(baseUrl('contact.php'));
    } else {
        flash('error', 'Please fill in all required fields.');
    }
}

renderHeader('Contact Us', $user, 'contact');
?>
<h2 class="section-title">Contact Us</h2>
<p class="section-subtitle">Get in touch with our workshop team</p>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <div class="panel-card p-6">
    <h3 class="font-semibold text-white mb-4">Workshop Details</h3>
    <div class="space-y-3 text-sm">
      <p><span class="text-slate-500">Name:</span> <?= e(getWorkshopSetting('workshop_name')) ?></p>
      <p><span class="text-slate-500">Address:</span> <?= e(getWorkshopSetting('workshop_address')) ?></p>
      <p><span class="text-slate-500">Phone:</span> <?= e(getWorkshopSetting('workshop_phone')) ?></p>
      <p><span class="text-slate-500">Email:</span> <?= e(getWorkshopSetting('workshop_email')) ?></p>
      <p><span class="text-slate-500">Hours:</span> Mon–Sat 8:30 AM – 6:00 PM</p>
    </div>
  </div>
  <div class="panel-card p-6">
    <h3 class="font-semibold text-white mb-4">Send a Message</h3>
    <form method="POST" class="space-y-3">
      <?= csrfField() ?>
      <div><label class="block text-xs text-slate-400 mb-1">Name *</label><input class="form-input" name="name" required value="<?= e($user['full_name'] ?? '') ?>"></div>
      <div><label class="block text-xs text-slate-400 mb-1">Email *</label><input class="form-input" type="email" name="email" required value="<?= e($user['email'] ?? '') ?>"></div>
      <div><label class="block text-xs text-slate-400 mb-1">Phone</label><input class="form-input" name="phone" value="<?= e($user['contact_no'] ?? '') ?>"></div>
      <div><label class="block text-xs text-slate-400 mb-1">Subject *</label><input class="form-input" name="subject" required></div>
      <div><label class="block text-xs text-slate-400 mb-1">Message *</label><textarea class="form-input" name="message" rows="4" required></textarea></div>
      <button type="submit" class="btn-primary">Send Message</button>
    </form>
  </div>
</div>
<?php renderFooter(); ?>
