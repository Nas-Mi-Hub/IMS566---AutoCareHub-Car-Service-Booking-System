<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('admin');
$db = getDB();
ensureSchemaUpdates();

if (isset($_GET['read'])) {
    $db->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([(int)$_GET['read'], $user['id']]);
    redirect(baseUrl('admin/notifications.php'));
}
if (isset($_GET['read_all'])) {
    $db->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$user['id']]);
    redirect(baseUrl('admin/notifications.php'));
}
if (isset($_GET['delete'])) {
    deleteNotification((int)$_GET['delete'], (int)$user['id']);
    flash('success', 'Notification deleted.');
    redirect(baseUrl('admin/notifications.php'));
}
if (isset($_GET['delete_read'])) {
    $n = deleteAllReadNotifications((int)$user['id']);
    flash('success', "Deleted {$n} read notification(s).");
    redirect(baseUrl('admin/notifications.php'));
}

$filter = $_GET['filter'] ?? 'all';
$sql = 'SELECT * FROM notifications WHERE user_id=?';
$params = [$user['id']];
if ($filter === 'alert') $sql .= " AND type='alert'";
elseif ($filter === 'unread') $sql .= ' AND is_read=0';
$sql .= ' ORDER BY created_at DESC';
$notifs = $db->prepare($sql);
$notifs->execute($params);

renderHeader('Notifications', $user, 'notifications');
?>
<h2 class="section-title">Notifications</h2>
<p class="section-subtitle">System alerts and operation messages — delete old notifications anytime</p>
<div class="flex flex-wrap gap-2 mb-4">
  <a href="?filter=all" class="btn-secondary">All</a>
  <a href="?filter=unread" class="btn-secondary">Unread</a>
  <a href="?filter=alert" class="btn-secondary">Alerts</a>
  <a href="?read_all=1" class="btn-secondary">Mark All Read</a>
  <a href="?delete_read=1" class="btn-danger" onclick="return confirm('Delete all read notifications?')">Delete Old (Read)</a>
</div>
<div class="space-y-2">
<?php while ($n = $notifs->fetch()): ?>
<div class="panel-card p-4 flex items-start gap-3 <?= $n['is_read']?'opacity-60':'' ?> <?= $n['type']==='alert'?'notif-alert':'' ?>">
  <div class="w-2 h-2 rounded-full mt-2 <?= $n['is_read']?'bg-slate-600':'bg-accent' ?>"></div>
  <div class="flex-1">
    <div class="flex flex-wrap items-center gap-2">
      <p class="font-semibold text-white text-sm"><?= e($n['title']) ?></p>
      <?= notificationTypeBadge($n['type']) ?>
    </div>
    <p class="text-sm text-slate-400"><?= e($n['message']) ?></p>
    <p class="text-xs text-slate-500 mt-1"><?= e($n['created_at']) ?></p>
    <?php if ($n['link']): ?><a href="<?= e(notificationLink($n['link'])) ?>" class="text-xs text-accent mt-1 inline-block">View →</a><?php endif; ?>
  </div>
  <div class="flex flex-col gap-1">
    <?php if (!$n['is_read']): ?><a href="?read=<?= $n['id'] ?>" class="btn-secondary text-xs">Mark Read</a><?php endif; ?>
    <a href="?delete=<?= (int)$n['id'] ?>" class="btn-danger text-xs" onclick="return confirm('Delete?')">Delete</a>
  </div>
</div>
<?php endwhile; ?>
</div>
<?php renderFooter(); ?>
