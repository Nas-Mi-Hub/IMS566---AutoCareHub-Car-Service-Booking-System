<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('customer');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('user/messages.php'));
    $admin = $db->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch();
    $db->prepare('INSERT INTO messages (sender_id,receiver_id,subject,body) VALUES (?,?,?,?)')
       ->execute([$user['id'], $admin['id'], trim($_POST['subject']), trim($_POST['body'])]);
    notify($admin['id'], 'Customer Message', trim($_POST['subject']), 'info', baseUrl('admin/messages.php'));
    flash('success', 'Message sent to workshop.');
    redirect(baseUrl('user/messages.php'));
}
if (isset($_GET['read'])) $db->prepare('UPDATE messages SET is_read=1 WHERE id=? AND receiver_id=?')->execute([(int)$_GET['read'],$user['id']]);

$inbox = $db->prepare('SELECT m.*, u.full_name AS sender_name FROM messages m JOIN users u ON u.id=m.sender_id WHERE m.receiver_id=? OR m.sender_id=? ORDER BY m.created_at DESC');
$inbox->execute([$user['id'], $user['id']]);

renderHeader('Messages', $user, 'messages');
?>
<h2 class="section-title">Messages</h2>
<p class="section-subtitle">Communicate with the AutoCare Hub workshop team</p>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="panel-card p-5">
    <form method="POST" class="space-y-3">
      <?= csrfField() ?>
      <div><label class="text-xs text-slate-400">Subject</label><input class="form-input" name="subject" required></div>
      <div><label class="text-xs text-slate-400">Message</label><textarea class="form-input" name="body" rows="4" required></textarea></div>
      <button type="submit" class="btn-primary">Send to Workshop</button>
    </form>
  </div>
  <div class="lg:col-span-2 panel-card p-5 space-y-2">
  <?php while ($m = $inbox->fetch()): ?>
  <div class="p-3 rounded-lg border border-slate-700/50">
    <p class="font-semibold text-white text-sm"><?= e($m['subject']) ?></p>
    <p class="text-xs text-slate-500"><?= (int)$m['sender_id']===$user['id']?'You → Workshop':'Workshop → You' ?> — <?= e($m['created_at']) ?></p>
    <p class="text-sm text-slate-400 mt-1"><?= e($m['body']) ?></p>
  </div>
  <?php endwhile; ?>
  </div>
</div>
<?php renderFooter(); ?>
