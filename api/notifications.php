<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

$user = requireLogin();
$db = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($action === 'mark_read' && isset($_GET['id'])) {
    $db->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([(int)$_GET['id'], $user['id']]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'mark_all') {
    $db->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$user['id']]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete' && isset($_REQUEST['id'])) {
    $ok = deleteNotification((int) $_REQUEST['id'], (int) $user['id']);
    echo json_encode(['success' => $ok]);
    exit;
}

if ($action === 'delete_read') {
    $count = deleteAllReadNotifications((int) $user['id']);
    echo json_encode(['success' => true, 'deleted' => $count]);
    exit;
}

$stmt = $db->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 20');
$stmt->execute([$user['id']]);
echo json_encode([
    'notifications' => $stmt->fetchAll(),
    'unread'        => getUnreadNotificationCount($user['id']),
    'alerts'        => getUnreadAlertCount($user['id']),
]);
