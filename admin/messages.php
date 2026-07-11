<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('admin');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf(baseUrl('admin/messages.php'));
    $db->prepare('INSERT INTO messages (sender_id,receiver_id,subject,body) VALUES (?,?,?,?)')
       ->execute([$user['id'], (int)$_POST['receiver_id'], trim($_POST['subject']), trim($_POST['body'])]);
    notify((int)$_POST['receiver_id'], 'New Message', trim($_POST['subject']), 'info', baseUrl('user/messages.php'));
    flash('success', 'Message sent.');
    redirect(baseUrl('admin/messages.php'));
}
if (isset($_GET['read'])) {
    $db->prepare('UPDATE messages SET is_read=1 WHERE id=? AND receiver_id=?')->execute([(int)$_GET['read'], $user['id']]);
}

$inbox = $db->prepare('SELECT m.*, u.full_name AS sender_name FROM messages m JOIN users u ON u.id=m.sender_id WHERE m.receiver_id=? ORDER BY m.created_at DESC');
$inbox->execute([$user['id']]);
$recipients = $db->query("SELECT id, full_name, role FROM users WHERE id != {$user['id']} AND is_active=1 ORDER BY role, full_name")->fetchAll();

renderHeader('Messages', $user, 'messages');
?>
<h2 class="section-title">Messages</h2>
<p class="section-subtitle">Internal communication with staff and customers</p>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="panel-card p-5">
    <h3 class="text-sm font-semibold text-white mb-4">Compose Message</h3>
    <form method="POST" class="space-y-3">
      <?= csrfField() ?>
      <div><label class="text-xs text-slate-400">To</label><select class="form-input" name="receiver_id" required><?php foreach ($recipients as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['full_name']) ?> (<?= e($r['role']) ?>)</option><?php endforeach; ?></select></div>
      <div><label class="text-xs text-slate-400">Subject</label><input class="form-input" name="subject" required></div>
      <div><label class="text-xs text-slate-400">Message</label><textarea class="form-input" name="body" rows="4" required></textarea></div>
      <button type="submit" class="btn-primary">Send</button>
    </form>
  </div>
  <div class="lg:col-span-2 panel-card p-5">
    <h3 class="text-sm font-semibold text-white mb-4">Inbox</h3>
    <div class="space-y-2">
    <?php while ($m = $inbox->fetch()): ?>
    <div class="p-3 rounded-lg border border-slate-700/50 <?= $m['is_read']?'':'bg-slate-800/50' ?>">
      <div class="flex justify-between"><p class="font-semibold text-white text-sm"><?= e($m['subject']) ?></p><?php if (!$m['is_read']): ?><a href="?read=<?= $m['id'] ?>" class="text-xs text-accent">Mark read</a><?php endif; ?></div>
      <p class="text-xs text-slate-500">From: <?= e($m['sender_name']) ?> — <?= e($m['created_at']) ?></p>
      <p class="text-sm text-slate-400 mt-1"><?= e($m['body']) ?></p>
    </div>
    <?php endwhile; ?>
    </div>
  </div>
</div>
<?php renderFooter(); ?>
