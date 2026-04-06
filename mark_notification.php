<?php
/**
 * API - Mark Notification(s) as Read
 * POST endpoint. Marks a single notification or all notifications as read.
 * Returns JSON response.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
startSecureSession();

header('Content-Type: application/json; charset=utf-8');

// ── Must be POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Must be logged in ────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You must be logged in.']);
    exit;
}

$db = getDB();
$userId = getCurrentUserId();
$notificationId = $_POST['notification_id'] ?? '';

if ($notificationId === 'all') {
    // Mark all notifications as read for this user
    $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0');
    $stmt->execute([':uid' => $userId]);
} else {
    $notifId = (int)$notificationId;
    if ($notifId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid notification ID.']);
        exit;
    }

    // Validate that the notification belongs to the current user
    $stmt = $db->prepare('SELECT id FROM notifications WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute([':id' => $notifId, ':uid' => $userId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Notification not found.']);
        exit;
    }

    // Mark as read
    $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $notifId, ':uid' => $userId]);
}

// [OPTIMIZED] Invalidate notification cache after marking as read
invalidateNotificationCache();
// Get updated unread count
$unreadCount = getNotificationCount($userId);

echo json_encode([
    'success' => true,
    'unreadCount' => $unreadCount,
]);
