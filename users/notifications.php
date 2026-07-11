<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
$user = requireRole('customer');
$db = getDB();
ensureSchemaUpdates();
checkServiceReminders((int) $user['id']);

if (isset($_GET['read'])) {
    $db->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([(int)$_GET['read'], $user['id']]);
    redirect(baseUrl('user/notifications.php'));
}
if (isset($_GET['read_all'])) {
    $db->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$user['id']]);
    redirect(baseUrl('user/notifications.php'));
}
if (isset($_GET['delete'])) {
    deleteNotification((int)$_GET['delete'], (int)$user['id']);
    flash('success', 'Notification deleted. / Notifikasi dipadam.');
    redirect(baseUrl('user/notifications.php'));
}
if (isset($_GET['delete_read'])) {
    $n = deleteAllReadNotifications((int)$user['id']);
    flash('success', "Deleted {$n} old notification(s). / Memadam {$n} notifikasi lama.");
    redirect(baseUrl('user/notifications.php'));
}

$filter = $_GET['filter'] ?? 'all';
$sql = 'SELECT * FROM notifications WHERE user_id=?';
$params = [$user['id']];
if ($filter === 'alert') {
    $sql .= " AND type='alert'";
} elseif ($filter === 'unread') {
    $sql .= ' AND is_read=0';
}
$sql .= ' ORDER BY created_at DESC';
$notifs = $db->prepare($sql);
$notifs->execute($params);
$list = $notifs->fetchAll();
$alertCount = getUnreadAlertCount((int)$user['id']);

renderHeader('Notifications', $user, 'notifications');
?>
<h2 class="section-title">Notifications / Notifikasi</h2>
<p class="section-subtitle">Appointment updates, receipts, and service alerts — delete old ones anytime / Padam notifikasi lama bila-bila masa</p>

<div class="flex flex-wrap gap-2 mb-4 items-center">
  <a href="?filter=all" class="btn-secondary <?= $filter==='all'?'ring-1 ring-accent':'' ?>">All</a>
  <a href="?filter=unread" class="btn-secondary <?= $filter==='unread'?'ring-1 ring-accent':'' ?>">Unread</a>
  <a href="?filter=alert" class="btn-secondary <?= $filter==='alert'?'ring-1 ring-accent':'' ?>">
    Alerts / Amaran<?= $alertCount ? ' ('.$alertCount.')' : '' ?>
  </a>
  <a href="?read_all=1" class="btn-secondary">Mark All Read</a>
  <a href="?delete_read=1" class="btn-danger" onclick="return confirm('Delete all read notifications? / Padam semua notifikasi yang sudah dibaca?')">Delete Old (Read)</a>
</div>

<div class="space-y-2">
<?php foreach ($list as $n): ?>
<div class="panel-card p-4 <?= $n['is_read']?'opacity-60':'' ?> <?= $n['type']==='alert'?'notif-alert':'' ?>">
  <div class="flex items-start justify-between gap-3">
    <div class="flex-1">
      <div class="flex flex-wrap items-center gap-2 mb-1">
        <p class="font-semibold text-white text-sm"><?= e($n['title']) ?></p>
        <?= notificationTypeBadge($n['type']) ?>
      </div>
      <p class="text-sm text-slate-400"><?= e($n['message']) ?></p>
      <div class="flex flex-wrap gap-2 mt-2">
        <span class="text-xs text-slate-500"><?= e($n['created_at']) ?></span>
        <?php if ($n['link']): ?><a href="<?= e(notificationLink($n['link'])) ?>" class="text-xs text-accent">View →</a><?php endif; ?>
        <?php if (!$n['is_read']): ?><a href="?read=<?= $n['id'] ?>" class="text-xs text-slate-400">Mark read</a><?php endif; ?>
      </div>
    </div>
    <a href="?delete=<?= (int)$n['id'] ?>" class="btn-danger" style="padding:.3rem .55rem;font-size:.7rem" title="Delete" onclick="return confirm('Delete this notification? / Padam notifikasi ini?')">✕</a>
  </div>
</div>
<?php endforeach; ?>
<?php if (empty($list)): ?>
<div class="panel-card p-6 text-center text-slate-400 text-sm">No notifications. / Tiada notifikasi.</div>
<?php endif; ?>
</div>
<?php renderFooter(); ?>
