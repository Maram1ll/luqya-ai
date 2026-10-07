<?php
// نقطة النهاية لإشعارات المستخدمين
require_once __DIR__ . '/../config/db.php';

$pdo = getDBConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (!isLoggedIn()) {
    jsonResponse([
        'unread_count' => 0,
        'notifications' => []
    ]);
}

$currentUser = getCurrentUser();

// تعليم كل الإشعارات كمقروءة (عند فتح قائمة الجرس أو تبويب الإشعارات)
if ($action === 'mark_read') {
    requireCsrf();
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$currentUser['id']]);
    jsonResponse(['success' => true, 'unread_count' => 0]);
}

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$currentUser['id']]);
$notifications = $stmt->fetchAll();

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmtCount->execute([$currentUser['id']]);
$unreadCount = $stmtCount->fetchColumn();

jsonResponse([
    'unread_count' => intval($unreadCount),
    'notifications' => $notifications
]);
