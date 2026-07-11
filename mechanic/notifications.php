<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('mechanic');
$db = getDB();
ensureSchemaUpdates();

if (isset($_GET['read'])) {
    $db->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([(int)$_GET['read'], $user['id']]);
    redirect(baseUrl('mechanic/notifications.php'));
}
if (isset($_GET['read_all'])) {
    $db->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$user['id']]);
    redirect(baseUrl('mechanic/notifications.php'));
}
if (isset($_GET['delete'])) {
    deleteNotification((int)$_GET['delete'], (int)$user['id']);
    flash('success', 'Notification deleted.');
    redirect(baseUrl('mechanic/notifications.php'));
}
if (isset($_GET['delete_read'])) {
    $n = deleteAllReadNotifications((int)$user['id']);
    flash('success', "Deleted {$n} read notification(s).");
    redirect(baseUrl('mechanic/notifications.php'));
}

$filter = $_GET['filter'] ?? 'all';
$sql = 'SELECT * FROM notifications WHERE user_id=?';
$params = [$user['id']];
if ($filter === 'alert') $sql .= " AND type='alert'";
elseif ($filter === 'unread') $sql .= ' AND is_read=0';
$sql .= ' ORDER BY created_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$notifList = $stmt->fetchAll();

renderHeader('Notifications', $user, 'notifications');
?>
<h2 class="section-title">Notifications</h2>
<p class="section-subtitle">Job assignments, commission alerts, and workshop updates</p>
<div class="flex flex-wrap gap-2 mb-4">
  <a href="?filter=all" class="btn-secondary">All</a>
  <a href="?filter=unread" class="btn-secondary">Unread</a>
  <a href="?filter=alert" class="btn-secondary">Alerts</a>
  <a href="?read_all=1" class="btn-secondary">Mark All Read</a>
  <a href="?delete_read=1" class="btn-danger" onclick="return confirm('Delete all read notifications?')">Delete Old (Read)</a>
</div>
<div class="space-y-2">
<?php foreach ($notifList as $n): ?>
<div class="panel-card p-4 <?= $n['is_read'] ? 'opacity-60' : '' ?> <?= $n['type']==='alert'?'notif-alert':'' ?>">
  <div class="flex items-start justify-between gap-3">
    <div class="flex-1">
      <div class="flex flex-wrap items-center gap-2 mb-1">
        <p class="font-semibold text-white text-sm"><?= e($n['title']) ?></p>
        <?= notificationTypeBadge($n['type']) ?>
      </div>
      <p class="text-sm text-slate-400"><?= e($n['message']) ?></p>
      <div class="flex gap-2 mt-2">
        <span class="text-xs text-slate-500"><?= e($n['created_at']) ?></span>
        <?php if ($n['link']): ?><a href="<?= e(notificationLink($n['link'])) ?>" class="text-xs text-accent">View →</a><?php endif; ?>
        <?php if (!$n['is_read']): ?><a href="?read=<?= $n['id'] ?>" class="text-xs text-slate-400">Mark read</a><?php endif; ?>
      </div>
    </div>
    <a href="?delete=<?= (int)$n['id'] ?>" class="btn-danger" style="padding:.3rem .55rem;font-size:.7rem" onclick="return confirm('Delete?')">✕</a>
  </div>
</div>
<?php endforeach; ?>
<?php if (empty($notifList)): ?>
<div class="panel-card p-6 text-center text-slate-400 text-sm">No notifications yet.</div>
<?php endif; ?>
</div>
<?php renderFooter(); ?>
