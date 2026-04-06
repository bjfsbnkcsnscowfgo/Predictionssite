<?php
/**
 * Admin - Prediction Management
 * Filter, lock/unlock, cancel, and quick-resolve predictions.
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
        header('Location: ' . SITE_URL . '/admin/predictions.php');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $predId = (int)($_POST['prediction_id'] ?? 0);

    if ($predId <= 0) {
        $_SESSION['flash_error'] = 'Invalid prediction ID.';
        header('Location: ' . SITE_URL . '/admin/predictions.php');
        exit;
    }

    switch ($action) {
        case 'lock':
            $stmt = $db->prepare("UPDATE predictions SET status = 'locked' WHERE id = :id AND status = 'active'");
            $stmt->execute([':id' => $predId]);
            if ($stmt->rowCount()) {
                logAuditAction($adminId, 'lock_prediction', 'prediction', $predId, 'Prediction locked.');
                $_SESSION['flash_success'] = 'Prediction locked.';
            } else {
                $_SESSION['flash_error'] = 'Could not lock prediction (may not be active).';
            }
            break;

        case 'unlock':
            $stmt = $db->prepare("UPDATE predictions SET status = 'active' WHERE id = :id AND status = 'locked'");
            $stmt->execute([':id' => $predId]);
            if ($stmt->rowCount()) {
                logAuditAction($adminId, 'unlock_prediction', 'prediction', $predId, 'Prediction unlocked.');
                $_SESSION['flash_success'] = 'Prediction unlocked.';
            } else {
                $_SESSION['flash_error'] = 'Could not unlock prediction (may not be locked).';
            }
            break;

        case 'cancel':
            $result = resolvePrediction($predId, 'cancel', $adminId, 'Cancelled by admin from predictions list.');
            if ($result) {
                $_SESSION['flash_success'] = 'Prediction cancelled and bets refunded.';
            } else {
                $_SESSION['flash_error'] = 'Could not cancel prediction.';
            }
            break;

        default:
            $_SESSION['flash_error'] = 'Unknown action.';
    }

    $qs = $_GET ? '?' . http_build_query($_GET) : '';
    header('Location: ' . SITE_URL . '/admin/predictions.php' . $qs);
    exit;
}

// ── Filters ──────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$userFilter = trim($_GET['user'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($statusFilter !== '' && in_array($statusFilter, ['active','resolved_yes','resolved_no','locked','cancelled'])) {
    $where[] = 'p.status = :status';
    $params[':status'] = $statusFilter;
}

if ($categoryFilter !== '') {
    $where[] = 'p.category = :category';
    $params[':category'] = $categoryFilter;
}

if ($userFilter !== '') {
    $where[] = 'u.username LIKE :user';
    $params[':user'] = '%' . $userFilter . '%';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$countSQL = "SELECT COUNT(*) AS cnt FROM predictions p LEFT JOIN users u ON p.user_id = u.id {$whereSQL}";
$stmt = $db->prepare($countSQL);
$stmt->execute($params);
$totalPredictions = (int)$stmt->fetch()['cnt'];
$totalPages = max(1, (int)ceil($totalPredictions / $perPage));

// Fetch
// [OPTIMIZED] Replaced correlated subquery with LEFT JOIN for bet_count
$sql = "SELECT p.*, u.username AS creator_username,
        COALESCE(bc.bet_count, 0) AS bet_count
        FROM predictions p
        LEFT JOIN users u ON p.user_id = u.id
        LEFT JOIN (SELECT prediction_id, COUNT(*) AS bet_count FROM bets GROUP BY prediction_id) bc
            ON bc.prediction_id = p.id
        {$whereSQL}
        ORDER BY p.id DESC
        LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$predictions = $stmt->fetchAll();

// Pending payments count for nav
$stmt = $db->query("SELECT COUNT(*) AS cnt FROM payment_requests WHERE status = 'pending'");
$pendingPayments = (int)$stmt->fetch()['cnt'];

$categories = getCategoryList();

$pageTitle = 'Admin - Predictions';
$currentPage = 'admin_predictions';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Admin Sub-Navigation -->
<div class="admin-nav bg-dark border-bottom border-secondary mb-4" style="margin: -1.5rem -0.75rem 1.5rem; padding: 0 0.75rem;">
    <div class="container-xl">
        <nav class="nav nav-pills py-2">
            <a class="nav-link" href="<?= SITE_URL ?>/admin/"><i class="fas fa-tachometer-alt me-1"></i> Dashboard</a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/users.php"><i class="fas fa-users me-1"></i> Users</a>
            <a class="nav-link active" href="<?= SITE_URL ?>/admin/predictions.php"><i class="fas fa-chart-bar me-1"></i> Predictions</a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/payments.php"><i class="fas fa-credit-card me-1"></i> Payments <?php if ($pendingPayments > 0): ?><span class="badge bg-danger ms-1"><?= $pendingPayments ?></span><?php endif; ?></a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/audit.php"><i class="fas fa-clipboard-list me-1"></i> Audit Log</a>
        </nav>
    </div>
</div>

<div class="page-header mb-4">
    <h1><i class="fas fa-chart-bar me-2 text-accent"></i>Prediction Management</h1>
    <p><?= number_format($totalPredictions) ?> predictions found</p>
</div>

<!-- Filter Bar -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="locked" <?= $statusFilter === 'locked' ? 'selected' : '' ?>>Locked</option>
                    <option value="resolved_yes" <?= $statusFilter === 'resolved_yes' ? 'selected' : '' ?>>Resolved Yes</option>
                    <option value="resolved_no" <?= $statusFilter === 'resolved_no' ? 'selected' : '' ?>>Resolved No</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Category</label>
                <select name="category" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= sanitize($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>><?= sanitize($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Creator Username</label>
                <input type="text" name="user" class="form-control" placeholder="Search creator..."
                       value="<?= sanitize($userFilter) ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- Predictions Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Creator</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Stake</th>
                        <th>Pool (For/Against)</th>
                        <th>Bets</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($predictions)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">No predictions found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($predictions as $p): ?>
                        <tr>
                            <td><?= (int)$p['id'] ?></td>
                            <td>
                                <a href="<?= SITE_URL ?>/prediction.php?id=<?= (int)$p['id'] ?>" target="_blank" title="View public page">
                                    <?= sanitize(truncate($p['title'], 45)) ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?= SITE_URL ?>/admin/edit_user.php?id=<?= (int)$p['user_id'] ?>">
                                    <?= sanitize($p['creator_username'] ?? 'Unknown') ?>
                                </a>
                            </td>
                            <td><span class="badge <?= getCategoryBadgeClass($p['category']) ?>"><?= sanitize($p['category']) ?></span></td>
                            <td><span class="badge <?= getStatusBadgeClass($p['status']) ?>"><?= sanitize($p['status']) ?></span></td>
                            <td class="credit-amount"><?= number_format((float)$p['credit_stake'], 2) ?></td>
                            <td>
                                <span class="text-success"><?= number_format((float)$p['total_pool_for'], 2) ?></span>
                                /
                                <span class="text-danger"><?= number_format((float)$p['total_pool_against'], 2) ?></span>
                            </td>
                            <td><?= (int)$p['bet_count'] ?></td>
                            <td class="text-nowrap small"><?= timeAgo($p['created_at']) ?></td>
                            <td class="text-nowrap">
                                <a href="<?= SITE_URL ?>/admin/edit_prediction.php?id=<?= (int)$p['id'] ?>"
                                   class="btn btn-sm btn-outline-light me-1" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>

                                <?php if ($p['status'] === 'active'): ?>
                                    <!-- Lock -->
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Lock this prediction?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="lock">
                                        <input type="hidden" name="prediction_id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-warning" title="Lock"><i class="fas fa-lock"></i></button>
                                    </form>
                                    <!-- Quick Resolve link -->
                                    <a href="<?= SITE_URL ?>/admin/edit_prediction.php?id=<?= (int)$p['id'] ?>#resolve"
                                       class="btn btn-sm btn-info" title="Resolve">
                                        <i class="fas fa-check-double"></i>
                                    </a>
                                <?php elseif ($p['status'] === 'locked'): ?>
                                    <!-- Unlock -->
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="unlock">
                                        <input type="hidden" name="prediction_id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success" title="Unlock"><i class="fas fa-lock-open"></i></button>
                                    </form>
                                    <!-- Quick Resolve link -->
                                    <a href="<?= SITE_URL ?>/admin/edit_prediction.php?id=<?= (int)$p['id'] ?>#resolve"
                                       class="btn btn-sm btn-info" title="Resolve">
                                        <i class="fas fa-check-double"></i>
                                    </a>
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
