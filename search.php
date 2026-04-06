<?php
/**
 * Search Page — Search predictions and users.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
startSecureSession();

$db = getDB();
$currentUser = getCurrentUser();
$currentUserId = getCurrentUserId();
$categories = getCategoryList();

$query = trim($_GET['q'] ?? '');
$tab = trim($_GET['tab'] ?? 'predictions');
$category = trim($_GET['category'] ?? '');
$status = trim($_GET['status'] ?? '');
$sort = trim($_GET['sort'] ?? 'relevance');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$predictions = [];
$users = [];
$totalResults = 0;
$totalPages = 1;

if (!empty($query)) {
    $searchTerm = '%' . $query . '%';

    if ($tab === 'users') {
        // User search
        $countSQL = "SELECT COUNT(*) AS total FROM users WHERE username LIKE :q AND is_banned = 0";
        $stmtC = $db->prepare($countSQL);
        $stmtC->execute([':q' => $searchTerm]);
        $totalResults = (int)$stmtC->fetch()['total'];
        $totalPages = max(1, ceil($totalResults / $perPage));

        $sql = "SELECT id, username, bio, created_at, total_predictions, correct_predictions
                FROM users
                WHERE username LIKE :q AND is_banned = 0
                ORDER BY total_predictions DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':q', $searchTerm);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll();

    } else {
        // Prediction search
        $where = ["(p.title LIKE :q OR p.description LIKE :q2)"];
        $params = [':q' => $searchTerm, ':q2' => $searchTerm];

        // Shadow ban filter
        if ($currentUser && (int)$currentUser['is_shadow_banned'] === 1) {
            $where[] = '(u.is_shadow_banned = 0 OR p.user_id = :sbuid)';
            $params[':sbuid'] = $currentUserId;
        } else {
            $where[] = 'u.is_shadow_banned = 0';
        }

        if (!empty($category)) {
            $where[] = 'p.category = :cat';
            $params[':cat'] = $category;
        }
        if (!empty($status)) {
            $where[] = 'p.status = :status';
            $params[':status'] = $status;
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);

        // Order
        switch ($sort) {
            case 'newest':
                $orderSQL = 'ORDER BY p.created_at DESC';
                break;
            case 'popular':
                $orderSQL = 'ORDER BY bet_count DESC, p.created_at DESC';
                break;
            default: // relevance
                $orderSQL = 'ORDER BY CASE WHEN p.title LIKE :q3 THEN 0 ELSE 1 END, p.created_at DESC';
                $params[':q3'] = $searchTerm;
                break;
        }

        // Count
        $countSQL = "SELECT COUNT(*) AS total FROM predictions p JOIN users u ON p.user_id = u.id $whereSQL";
        $stmtC = $db->prepare($countSQL);
        // Only bind non-q3 params for count
        $countParams = $params;
        unset($countParams[':q3']);
        $stmtC->execute($countParams);
        $totalResults = (int)$stmtC->fetch()['total'];
        $totalPages = max(1, ceil($totalResults / $perPage));

        // [OPTIMIZED] Replaced correlated subquery with LEFT JOIN for bet_count
        $sql = "SELECT p.*, u.username,
                       COALESCE(bc.bet_count, 0) AS bet_count
                FROM predictions p
                JOIN users u ON p.user_id = u.id
                LEFT JOIN (SELECT prediction_id, COUNT(*) AS bet_count FROM bets GROUP BY prediction_id) bc
                    ON bc.prediction_id = p.id
                $whereSQL
                $orderSQL
                LIMIT :limit OFFSET :offset";
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $predictions = $stmt->fetchAll();
    }
}

$pageTitle = 'Search';
$currentPage = 'search';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header text-center">
    <h1><i class="fas fa-search text-accent me-2"></i>Search</h1>
    <p>Find predictions and users across the platform</p>
</div>

<!-- Search Bar -->
<div class="search-bar mb-4">
    <form method="GET" action="">
        <input type="hidden" name="tab" value="<?= sanitize($tab) ?>">
        <i class="fas fa-search search-icon"></i>
        <input type="text" class="form-control" name="q"
               placeholder="Search predictions or users..."
               value="<?= sanitize($query) ?>" autofocus>
    </form>
</div>

<!-- Tabs -->
<ul class="nav nav-pills justify-content-center mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'predictions' ? 'active' : '' ?>"
           href="?<?= http_build_query(array_merge($_GET, ['tab' => 'predictions', 'page' => 1])) ?>">
            <i class="fas fa-chart-line me-1"></i> Predictions
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'users' ? 'active' : '' ?>"
           href="?<?= http_build_query(array_merge($_GET, ['tab' => 'users', 'page' => 1])) ?>">
            <i class="fas fa-users me-1"></i> Users
        </a>
    </li>
</ul>

<?php if ($tab === 'predictions' && !empty($query)): ?>
    <!-- Prediction Filters -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="q" value="<?= sanitize($query) ?>">
                <input type="hidden" name="tab" value="predictions">
                <div class="col-md-3 col-6">
                    <label class="form-label small mb-1"><i class="fas fa-folder me-1"></i>Category</label>
                    <select name="category" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= sanitize($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= sanitize($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-6">
                    <label class="form-label small mb-1"><i class="fas fa-filter me-1"></i>Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="resolved_yes" <?= $status === 'resolved_yes' ? 'selected' : '' ?>>Resolved Yes</option>
                        <option value="resolved_no" <?= $status === 'resolved_no' ? 'selected' : '' ?>>Resolved No</option>
                        <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3 col-6">
                    <label class="form-label small mb-1"><i class="fas fa-sort me-1"></i>Sort</label>
                    <select name="sort" class="form-select form-select-sm">
                        <option value="relevance" <?= $sort === 'relevance' ? 'selected' : '' ?>>Relevance</option>
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
                        <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>Most Popular</option>
                    </select>
                </div>
                <div class="col-md-3 col-6">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($query)): ?>
    <!-- No query yet -->
    <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-search"></i></div>
        <div class="empty-state-title">Start Searching</div>
        <div class="empty-state-text">Enter a keyword above to search for predictions or users.</div>
    </div>
<?php elseif ($tab === 'predictions'): ?>
    <!-- Prediction Results -->
    <p class="text-secondary mb-3 small">
        Found <?= number_format($totalResults) ?> prediction<?= $totalResults !== 1 ? 's' : '' ?>
        for "<strong><?= sanitize($query) ?></strong>"
    </p>

    <?php if (empty($predictions)): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-search"></i></div>
            <div class="empty-state-title">No predictions found</div>
            <div class="empty-state-text">Try different keywords or adjust your filters.</div>
        </div>
    <?php else: ?>
        <div class="prediction-grid">
            <?php foreach ($predictions as $pred): ?>
                <?php
                    $prob = (int)$pred['probability'];
                    $probLevel = $prob < 33 ? 'low' : ($prob < 67 ? 'medium' : 'high');
                    $totalPoolPred = (float)$pred['total_pool_for'] + (float)$pred['total_pool_against'];
                    $statusLabel = str_replace('_', ' ', $pred['status']);
                ?>
                <div class="prediction-card card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="badge <?= getCategoryBadgeClass($pred['category']) ?>"><?= sanitize($pred['category']) ?></span>
                            <span class="badge <?= getStatusBadgeClass($pred['status']) ?>"><?= sanitize(ucwords($statusLabel)) ?></span>
                        </div>
                        <h5 class="prediction-title">
                            <a href="<?= SITE_URL ?>/prediction.php?id=<?= (int)$pred['id'] ?>"><?= sanitize($pred['title']) ?></a>
                        </h5>
                        <p class="prediction-description"><?= sanitize(truncate($pred['description'], 100)) ?></p>
                        <div class="probability-bar-wrapper">
                            <div class="probability-label">
                                <span>Probability</span>
                                <span class="probability-value"><?= $prob ?>%</span>
                            </div>
                            <div class="probability-bar">
                                <div class="probability-bar-fill" style="width:<?= $prob ?>%" data-prob="<?= $probLevel ?>"></div>
                            </div>
                        </div>
                        <div class="prediction-meta">
                            <span><i class="fas fa-user"></i> <?= sanitize($pred['username']) ?></span>
                            <span><i class="fas fa-clock"></i> <?= timeAgo($pred['created_at']) ?></span>
                        </div>
                        <div class="prediction-stats">
                            <div class="stat-item">
                                <div class="stat-value credit-amount"><i class="fas fa-coins fa-sm"></i> <?= number_format($pred['credit_stake'], 0) ?></div>
                                <div class="stat-label">Stake</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?= number_format($totalPoolPred, 0) ?></div>
                                <div class="stat-label">Pool</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?= (int)$pred['bet_count'] ?></div>
                                <div class="stat-label">Bets</div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php elseif ($tab === 'users'): ?>
    <!-- User Results -->
    <p class="text-secondary mb-3 small">
        Found <?= number_format($totalResults) ?> user<?= $totalResults !== 1 ? 's' : '' ?>
        for "<strong><?= sanitize($query) ?></strong>"
    </p>

    <?php if (empty($users)): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-users"></i></div>
            <div class="empty-state-title">No users found</div>
            <div class="empty-state-text">Try a different username or check your spelling.</div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($users as $u):
                $uTotal = (int)$u['total_predictions'];
                $uCorrect = (int)$u['correct_predictions'];
                $uAccuracy = $uTotal > 0 ? round(($uCorrect / $uTotal) * 100, 1) : 0;
                $firstLetter = strtoupper(mb_substr($u['username'], 0, 1));
            ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <div class="profile-avatar mx-auto mb-3" style="width:60px;height:60px;font-size:1.5rem;">
                                <?= $firstLetter ?>
                            </div>
                            <h6 class="fw-bold mb-1">
                                <a href="<?= SITE_URL ?>/profile.php?id=<?= (int)$u['id'] ?>"><?= sanitize($u['username']) ?></a>
                            </h6>
                            <div class="text-muted small mb-2">
                                <i class="fas fa-calendar me-1"></i>Joined <?= timeAgo($u['created_at']) ?>
                            </div>
                            <div class="d-flex justify-content-center gap-3 small">
                                <span><strong><?= $uTotal ?></strong> Predictions</span>
                                <span><strong><?= $uAccuracy ?>%</strong> Accuracy</span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Pagination -->
<?php if ($totalPages > 1 && !empty($query)): ?>
    <nav class="mt-4">
        <ul class="pagination justify-content-center">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
            </li>
            <?php
                $startP = max(1, $page - 2);
                $endP = min($totalPages, $page + 2);
                if ($startP > 1): ?>
                    <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a></li>
                    <?php if ($startP > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <?php endif;
                for ($i = $startP; $i <= $endP; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                    </li>
                <?php endfor;
                if ($endP < $totalPages): ?>
                    <?php if ($endP < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>"><?= $totalPages ?></a></li>
                <?php endif; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </li>
        </ul>
    </nav>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
