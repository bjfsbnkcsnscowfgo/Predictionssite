<?php
/**
 * Notifications Page
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
startSecureSession();
requireLogin();

$db = getDB();
$currentUser = getCurrentUser();
$currentUserId = (int)$currentUser['id'];

// Handle "Mark all as read" via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'mark_all_read') {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0");
            $stmt->execute([':uid' => $currentUserId]);
            // [OPTIMIZED] Invalidate notification cache after marking all as read
            invalidateNotificationCache();
            $_SESSION['flash_success'] = 'All notifications marked as read.';
        } elseif ($action === 'mark_read') {
            $notifId = intval($_POST['notification_id'] ?? 0);
            if ($notifId > 0) {
                $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid");
                $stmt->execute([':id' => $notifId, ':uid' => $currentUserId]);
                // [OPTIMIZED] Invalidate notification cache after marking as read
                invalidateNotificationCache();
            }
            // Redirect to related prediction if available
            $redirectId = intval($_POST['related_id'] ?? 0);
            if ($redirectId > 0) {
                header('Location: ' . SITE_URL . '/prediction.php?id=' . $redirectId);
                exit;
            }
        }
    }
    header('Location: ' . SITE_URL . '/notifications.php');
    exit;
}

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Count total notifications
$stmtCount = $db->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id = :uid");
$stmtCount->execute([':uid' => $currentUserId]);
$totalNotifs = (int)$stmtCount->fetch()['total'];
$totalPages = max(1, ceil($totalNotifs / $perPage));

// Count unread
$stmtUnread = $db->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id = :uid AND is_read = 0");
$stmtUnread->execute([':uid' => $currentUserId]);
$unreadCount = (int)$stmtUnread->fetch()['total'];

// Fetch notifications
$stmt = $db->prepare("
    SELECT * FROM notifications
    WHERE user_id = :uid
    ORDER BY created_at DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':uid', $currentUserId, PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$notifications = $stmt->fetchAll();

// Type to icon mapping
$typeIcons = [
    'info'    => 'fas fa-info-circle',
    'success' => 'fas fa-check-circle',
    'warning' => 'fas fa-exclamation-triangle',
    'danger'  => 'fas fa-times-circle',
];

$pageTitle = 'Notifications';
$currentPage = 'notifications';
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="page-header mb-0" style="margin-bottom:0;">
                    <i class="fas fa-bell text-accent me-2"></i>Notifications
                </h1>
                <p class="text-secondary mb-0">
                    <?= $unreadCount ?> unread · <?= $totalNotifs ?> total
                </p>
            </div>
            <?php if ($unreadCount > 0): ?>
                <form method="POST" action="" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-check-double me-1"></i>Mark All Read
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-bell-slash"></i></div>
                <div class="empty-state-title">No notifications</div>
                <div class="empty-state-text">You're all caught up! Notifications will appear here when there's activity on your predictions and bets.</div>
                <a href="<?= SITE_URL ?>/index.php" class="btn btn-primary">
                    <i class="fas fa-home me-1"></i>Browse Predictions
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notif):
                $isUnread = !(int)$notif['is_read'];
                $iconClass = $typeIcons[$notif['type']] ?? 'fas fa-info-circle';
                $typeClass = 'type-' . ($notif['type'] ?: 'info');
            ?>
                <form method="POST" action="" class="d-block">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="mark_read">
                    <input type="hidden" name="notification_id" value="<?= (int)$notif['id'] ?>">
                    <input type="hidden" name="related_id" value="<?= (int)$notif['related_id'] ?>">
                    <button type="submit" class="notification-item w-100 text-start border-0 <?= $isUnread ? 'unread' : '' ?>"
                            style="background: <?= $isUnread ? 'rgba(99,102,241,0.04)' : 'var(--bg-secondary)' ?>;">
                        <div class="notification-icon <?= $typeClass ?>">
                            <i class="<?= $iconClass ?>"></i>
                        </div>
                        <div class="notification-content">
                            <div class="notification-message"><?= sanitize($notif['message']) ?></div>
                            <div class="notification-time">
                                <i class="fas fa-clock me-1"></i><?= timeAgo($notif['created_at']) ?>
                                <?php if ($notif['related_id']): ?>
                                    · <i class="fas fa-external-link-alt me-1"></i>View prediction
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($isUnread): ?>
                            <span class="badge bg-primary rounded-pill ms-2">New</span>
                        <?php endif; ?>
                    </button>
                </form>
            <?php endforeach; ?>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
                        </li>
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
