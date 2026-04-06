<?php
/**
 * Admin - User Management
 * Search, filter, ban/unban, shadow ban users.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
startSecureSession();
requireAdmin();

$db = getDB();
$adminUser = getCurrentUser();
$adminId = (int)$adminUser['id'];

// ── Handle POST Actions ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Invalid or expired CSRF token.';
        header('Location: ' . SITE_URL . '/admin/users.php');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $targetUserId = (int)($_POST['user_id'] ?? 0);

    if ($targetUserId <= 0) {
        $_SESSION['flash_error'] = 'Invalid user ID.';
        header('Location: ' . SITE_URL . '/admin/users.php');
        exit;
    }

    switch ($action) {
        case 'ban':
            $banReason = trim($_POST['ban_reason'] ?? 'Banned by admin.');
            $stmt = $db->prepare('UPDATE users SET is_banned = 1, ban_reason = :reason WHERE id = :id');
            $stmt->execute([':reason' => $banReason, ':id' => $targetUserId]);
            logAuditAction($adminId, 'ban_user', 'user', $targetUserId, 'Reason: ' . $banReason);
            $_SESSION['flash_success'] = 'User has been banned.';
            break;

        case 'unban':
            $stmt = $db->prepare('UPDATE users SET is_banned = 0, ban_reason = NULL WHERE id = :id');
            $stmt->execute([':id' => $targetUserId]);
            logAuditAction($adminId, 'unban_user', 'user', $targetUserId, 'User unbanned.');
            $_SESSION['flash_success'] = 'User has been unbanned.';
            break;

        case 'shadow_ban':
            $stmt = $db->prepare('UPDATE users SET is_shadow_banned = 1 WHERE id = :id');
            $stmt->execute([':id' => $targetUserId]);
            logAuditAction($adminId, 'shadow_ban_user', 'user', $targetUserId, 'User shadow banned.');
            $_SESSION['flash_success'] = 'User has been shadow banned.';
            break;

        case 'unshadow_ban':
            $stmt = $db->prepare('UPDATE users SET is_shadow_banned = 0 WHERE id = :id');
            $stmt->execute([':id' => $targetUserId]);
            logAuditAction($adminId, 'unshadow_ban_user', 'user', $targetUserId, 'User shadow ban removed.');
            $_SESSION['flash_success'] = 'Shadow ban removed.';
            break;

        default:
            $_SESSION['flash_error'] = 'Unknown action.';
    }

    // Preserve query string on redirect
    $qs = $_GET ? '?' . http_build_query($_GET) : '';
    header('Location: ' . SITE_URL . '/admin/users.php' . $qs);
    exit;
}

// ── Filters & Search ─────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(u.username LIKE :search OR u.email LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($statusFilter === 'active') {
    $where[] = 'u.is_banned = 0 AND u.is_shadow_banned = 0';
} elseif ($statusFilter === 'banned') {
    $where[] = 'u.is_banned = 1';
} elseif ($statusFilter === 'shadow_banned') {
    $where[] = 'u.is_shadow_banned = 1';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total count
$countSQL = "SELECT COUNT(*) AS cnt FROM users u {$whereSQL}";
$stmt = $db->prepare($countSQL);
$stmt->execute($params);
$totalUsers = (int)$stmt->fetch()['cnt'];
$totalPages = max(1, (int)ceil($totalUsers / $perPage));

// Fetch users
$sql = "SELECT u.*,
        (SELECT COUNT(*) FROM predictions WHERE user_id = u.id) AS predictions_count,
        (SELECT COUNT(*) FROM bets WHERE user_id = u.id) AS bets_count
        FROM users u
        {$whereSQL}
        ORDER BY u.id DESC
        LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

// Pending payments count (for nav badge)
$stmt = $db->query("SELECT COUNT(*) AS cnt FROM payment_requests WHERE status = 'pending'");
$pendingPayments = (int)$stmt->fetch()['cnt'];

$pageTitle = 'Admin - Users';
$currentPage = 'admin_users';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Admin Sub-Navigation -->
<div class="admin-nav bg-dark border-bottom border-secondary mb-4" style="margin: -1.5rem -0.75rem 1.5rem; padding: 0 0.75rem;">
    <div class="container-xl">
        <nav class="nav nav-pills py-2">
            <a class="nav-link" href="<?= SITE_URL ?>/admin/"><i class="fas fa-tachometer-alt me-1"></i> Dashboard</a>
            <a class="nav-link active" href="<?= SITE_URL ?>/admin/users.php"><i class="fas fa-users me-1"></i> Users</a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/predictions.php"><i class="fas fa-chart-bar me-1"></i> Predictions</a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/payments.php"><i class="fas fa-credit-card me-1"></i> Payments <?php if ($pendingPayments > 0): ?><span class="badge bg-danger ms-1"><?= $pendingPayments ?></span><?php endif; ?></a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/audit.php"><i class="fas fa-clipboard-list me-1"></i> Audit Log</a>
        </nav>
    </div>
</div>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h1><i class="fas fa-users me-2 text-accent"></i>User Management</h1>
        <p><?= number_format($totalUsers) ?> total users</p>
    </div>
</div>

<!-- Search & Filter Bar -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-6">
                <label class="form-label">Search Users</label>
                <input type="text" name="search" class="form-control" placeholder="Search by username or email..."
                       value="<?= sanitize($search) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="banned" <?= $statusFilter === 'banned' ? 'selected' : '' ?>>Banned</option>
                    <option value="shadow_banned" <?= $statusFilter === 'shadow_banned' ? 'selected' : '' ?>>Shadow Banned</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i> Search</button>
            </div>
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Credits</th>
                        <th>IP Address</th>
                        <th>Country</th>
                        <th>Status</th>
                        <th>Predictions</th>
                        <th>Accuracy</th>
                        <th>Joined</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="12" class="text-center text-muted py-4">No users found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                        <?php
                            $totalPred = (int)$u['total_predictions'];
                            $correctPred = (int)$u['correct_predictions'];
                            $accuracy = $totalPred > 0 ? round(($correctPred / $totalPred) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><?= (int)$u['id'] ?></td>
                            <td>
                                <a href="<?= SITE_URL ?>/admin/edit_user.php?id=<?= (int)$u['id'] ?>" class="fw-bold">
                                    <?= sanitize($u['username']) ?>
                                </a>
                                <?php if ((int)$u['is_admin']): ?>
                                    <span class="badge bg-primary ms-1">Admin</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-secondary"><?= sanitize($u['email']) ?></td>
                            <td class="credit-amount"><?= number_format((float)$u['credits'], 2) ?></td>
                            <td><code class="small"><?= sanitize($u['ip_address'] ?? '—') ?></code></td>
                            <td><?= sanitize($u['country'] ?? '—') ?></td>
                            <td>
                                <?php if ((int)$u['is_banned']): ?>
                                    <span class="badge bg-danger">Banned</span>
                                <?php elseif ((int)$u['is_shadow_banned']): ?>
                                    <span class="badge bg-warning text-dark">Shadow Banned</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Active</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$u['predictions_count'] ?></td>
                            <td><?= $accuracy ?>%</td>
                            <td class="text-nowrap small"><?= formatDate($u['created_at']) ?></td>
                            <td class="text-nowrap small"><?= $u['last_login'] ? timeAgo($u['last_login']) : '—' ?></td>
                            <td class="text-nowrap">
                                <a href="<?= SITE_URL ?>/admin/edit_user.php?id=<?= (int)$u['id'] ?>"
                                   class="btn btn-sm btn-outline-light me-1" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>

                                <?php if (!(int)$u['is_banned']): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Ban this user?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="ban">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <input type="hidden" name="ban_reason" value="Banned by admin.">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Ban"><i class="fas fa-ban"></i></button>
                                </form>
                                <?php else: ?>
                                <form method="POST" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="unban">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-success" title="Unban"><i class="fas fa-check"></i></button>
                                </form>
                                <?php endif; ?>

                                <?php if (!(int)$u['is_shadow_banned']): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Shadow ban this user?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="shadow_ban">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-warning" title="Shadow Ban"><i class="fas fa-ghost"></i></button>
                                </form>
                                <?php else: ?>
                                <form method="POST" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="unshadow_ban">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-light" title="Remove Shadow Ban"><i class="fas fa-ghost"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<nav class="mt-4 d-flex justify-content-center">
    <ul class="pagination">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&laquo;</a>
        </li>
        <?php
        $start = max(1, $page - 3);
        $end = min($totalPages, $page + 3);
        for ($i = $start; $i <= $end; $i++):
        ?>
        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
        </li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">&raquo;</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
