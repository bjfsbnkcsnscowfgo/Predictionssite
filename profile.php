<?php
/**
 * Public User Profile Page
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
startSecureSession();

$db = getDB();
$currentUser = getCurrentUser();
$currentUserId = getCurrentUserId();

$profileId = intval($_GET['id'] ?? 0);

// If no ID provided and logged in, show own profile
if ($profileId <= 0 && $currentUserId) {
    $profileId = $currentUserId;
}

if ($profileId <= 0) {
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

// Fetch user profile (exclude sensitive fields)
$stmt = $db->prepare("
    SELECT id, username, bio, avatar_url, credits, total_predictions, correct_predictions, created_at, is_banned, is_shadow_banned
    FROM users WHERE id = :id LIMIT 1
");
$stmt->execute([':id' => $profileId]);
$profile = $stmt->fetch();

if (!$profile || (int)$profile['is_banned'] === 1) {
    $pageTitle = 'User Not Found';
    $currentPage = 'profile';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-user-slash"></i></div>
        <div class="empty-state-title">User Not Found</div>
        <div class="empty-state-text">This user doesn't exist or has been removed.</div>
        <a href="<?= SITE_URL ?>/index.php" class="btn btn-primary"><i class="fas fa-home me-1"></i>Back to Home</a>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$isOwnProfile = ($currentUserId && $currentUserId === $profileId);
$stats = getUserStats($profileId);
$firstLetter = strtoupper(mb_substr($profile['username'], 0, 1));

// Pagination for predictions
$predPage = max(1, intval($_GET['ppage'] ?? 1));
$predPerPage = 10;
$predOffset = ($predPage - 1) * $predPerPage;

// Fetch user's predictions
$stmtCount = $db->prepare("SELECT COUNT(*) AS total FROM predictions WHERE user_id = :uid");
$stmtCount->execute([':uid' => $profileId]);
$totalPredictions = (int)$stmtCount->fetch()['total'];
$totalPredPages = max(1, ceil($totalPredictions / $predPerPage));

// [OPTIMIZED] Replaced correlated subquery with LEFT JOIN for bet_count
$stmtPreds = $db->prepare("
    SELECT p.*, COALESCE(bc.bet_count, 0) AS bet_count
    FROM predictions p
    LEFT JOIN (SELECT prediction_id, COUNT(*) AS bet_count FROM bets GROUP BY prediction_id) bc
        ON bc.prediction_id = p.id
    WHERE p.user_id = :uid
    ORDER BY p.created_at DESC
    LIMIT :limit OFFSET :offset
");
$stmtPreds->bindValue(':uid', $profileId, PDO::PARAM_INT);
$stmtPreds->bindValue(':limit', $predPerPage, PDO::PARAM_INT);
$stmtPreds->bindValue(':offset', $predOffset, PDO::PARAM_INT);
$stmtPreds->execute();
$userPredictions = $stmtPreds->fetchAll();

// Fetch user's bets (only for own profile)
$userBets = [];
$totalBets = 0;
$totalBetPages = 1;
if ($isOwnProfile) {
    $betPage = max(1, intval($_GET['bpage'] ?? 1));
    $betPerPage = 10;
    $betOffset = ($betPage - 1) * $betPerPage;

    $stmtBCount = $db->prepare("SELECT COUNT(*) AS total FROM bets WHERE user_id = :uid");
    $stmtBCount->execute([':uid' => $profileId]);
    $totalBets = (int)$stmtBCount->fetch()['total'];
    $totalBetPages = max(1, ceil($totalBets / $betPerPage));

    $stmtBets = $db->prepare("
        SELECT b.*, p.title AS prediction_title, p.status AS prediction_status
        FROM bets b
        JOIN predictions p ON b.prediction_id = p.id
        WHERE b.user_id = :uid
        ORDER BY b.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmtBets->bindValue(':uid', $profileId, PDO::PARAM_INT);
    $stmtBets->bindValue(':limit', $betPerPage, PDO::PARAM_INT);
    $stmtBets->bindValue(':offset', $betOffset, PDO::PARAM_INT);
    $stmtBets->execute();
    $userBets = $stmtBets->fetchAll();
}

// Active tab
$activeTab = $_GET['tab'] ?? 'predictions';
if (!in_array($activeTab, ['predictions', 'bets', 'activity'])) {
    $activeTab = 'predictions';
}

$pageTitle = $profile['username'] . '\'s Profile';
$currentPage = 'profile';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Profile Header -->
<div class="profile-header">
    <div class="d-flex flex-column flex-md-row align-items-center gap-3">
        <div class="profile-avatar">
            <?= $firstLetter ?>
        </div>
        <div class="profile-info flex-grow-1 text-center text-md-start">
            <h2><?= sanitize($profile['username']) ?></h2>
            <?php if (!empty($profile['bio'])): ?>
                <p class="bio mb-1"><?= sanitize($profile['bio']) ?></p>
            <?php endif; ?>
            <div class="text-muted small">
                <i class="fas fa-calendar me-1"></i>Member since <?= formatDate($profile['created_at']) ?>
            </div>
            <?php if ($isOwnProfile): ?>
                <a href="<?= SITE_URL ?>/edit_profile.php" class="btn btn-outline-light btn-sm mt-2">
                    <i class="fas fa-edit me-1"></i>Edit Profile
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stats -->
    <div class="profile-stats">
        <div class="profile-stat-card">
            <div class="profile-stat-value"><?= $stats['total_predictions'] ?></div>
            <div class="profile-stat-label">Predictions</div>
        </div>
        <div class="profile-stat-card">
            <div class="profile-stat-value"><?= $stats['correct_predictions'] ?></div>
            <div class="profile-stat-label">Correct</div>
        </div>
        <div class="profile-stat-card">
            <div class="profile-stat-value"><?= $stats['accuracy'] ?>%</div>
            <div class="profile-stat-label">Accuracy</div>
        </div>
        <?php if ($isOwnProfile): ?>
            <div class="profile-stat-card">
                <div class="profile-stat-value text-credits"><?= number_format($stats['credits'], 0) ?></div>
                <div class="profile-stat-label">Credits</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-pills mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'predictions' ? 'active' : '' ?>"
           href="?id=<?= $profileId ?>&tab=predictions">
            <i class="fas fa-chart-line me-1"></i> Predictions
            <span class="badge bg-secondary ms-1"><?= $totalPredictions ?></span>
        </a>
    </li>
    <?php if ($isOwnProfile): ?>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'bets' ? 'active' : '' ?>"
               href="?id=<?= $profileId ?>&tab=bets">
                <i class="fas fa-gavel me-1"></i> My Bets
                <span class="badge bg-secondary ms-1"><?= $totalBets ?></span>
            </a>
        </li>
    <?php endif; ?>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'activity' ? 'active' : '' ?>"
           href="?id=<?= $profileId ?>&tab=activity">
            <i class="fas fa-history me-1"></i> Activity
        </a>
    </li>
</ul>

<!-- Tab Content -->
<?php if ($activeTab === 'predictions'): ?>
    <?php if (empty($userPredictions)): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-crystal-ball"></i></div>
            <div class="empty-state-title">No predictions yet</div>
            <div class="empty-state-text">
                <?= $isOwnProfile ? 'You haven\'t created any predictions yet.' : 'This user hasn\'t created any predictions yet.' ?>
            </div>
            <?php if ($isOwnProfile): ?>
                <a href="<?= SITE_URL ?>/create.php" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i>Create Prediction
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="prediction-grid">
            <?php foreach ($userPredictions as $pred): ?>
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
                        <div class="prediction-stats mt-3">
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

        <!-- Prediction Pagination -->
        <?php if ($totalPredPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $totalPredPages; $i++): ?>
                        <li class="page-item <?= $i === $predPage ? 'active' : '' ?>">
                            <a class="page-link" href="?id=<?= $profileId ?>&tab=predictions&ppage=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

<?php elseif ($activeTab === 'bets' && $isOwnProfile): ?>
    <?php if (empty($userBets)): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-gavel"></i></div>
            <div class="empty-state-title">No bets yet</div>
            <div class="empty-state-text">You haven't placed any bets yet. Browse predictions to get started!</div>
            <a href="<?= SITE_URL ?>/index.php" class="btn btn-primary">
                <i class="fas fa-search me-1"></i>Browse Predictions
            </a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Prediction</th>
                        <th>Position</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Payout</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($userBets as $bet): ?>
                        <tr>
                            <td>
                                <a href="<?= SITE_URL ?>/prediction.php?id=<?= (int)$bet['prediction_id'] ?>">
                                    <?= sanitize(truncate($bet['prediction_title'], 50)) ?>
                                </a>
                            </td>
                            <td>
                                <?php if ($bet['position'] === 'for'): ?>
                                    <span class="badge bg-success"><i class="fas fa-thumbs-up me-1"></i>For</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="fas fa-thumbs-down me-1"></i>Against</span>
                                <?php endif; ?>
                            </td>
                            <td class="credit-amount"><?= number_format($bet['amount'], 2) ?></td>
                            <td>
                                <span class="badge <?= getStatusBadgeClass($bet['prediction_status']) ?>">
                                    <?= sanitize(ucwords(str_replace('_', ' ', $bet['prediction_status']))) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($bet['payout'] !== null): ?>
                                    <?php if ((float)$bet['payout'] > (float)$bet['amount']): ?>
                                        <span class="text-success fw-bold">+<?= number_format($bet['payout'], 2) ?></span>
                                    <?php elseif ((float)$bet['payout'] > 0): ?>
                                        <span class="text-warning"><?= number_format($bet['payout'], 2) ?></span>
                                    <?php else: ?>
                                        <span class="text-danger">0.00</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= timeAgo($bet['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalBetPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $totalBetPages; $i++): ?>
                        <li class="page-item <?= $i === (int)($betPage ?? 1) ? 'active' : '' ?>">
                            <a class="page-link" href="?id=<?= $profileId ?>&tab=bets&bpage=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

<?php elseif ($activeTab === 'activity'): ?>
    <?php
    // Recent activity: latest predictions + bets
    $stmtActivity = $db->prepare("
        (SELECT 'prediction' AS type, p.id, p.title AS detail, p.created_at
         FROM predictions p WHERE p.user_id = :uid1 ORDER BY p.created_at DESC LIMIT 10)
        UNION ALL
        (SELECT 'bet' AS type, b.prediction_id AS id, p.title AS detail, b.created_at
         FROM bets b JOIN predictions p ON b.prediction_id = p.id WHERE b.user_id = :uid2 ORDER BY b.created_at DESC LIMIT 10)
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmtActivity->execute([':uid1' => $profileId, ':uid2' => $profileId]);
    $activities = $stmtActivity->fetchAll();
    ?>

    <?php if (empty($activities)): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-history"></i></div>
            <div class="empty-state-title">No activity yet</div>
            <div class="empty-state-text">Activity will appear here as predictions are created and bets are placed.</div>
        </div>
    <?php else: ?>
        <div class="list-group">
            <?php foreach ($activities as $act): ?>
                <div class="list-group-item" style="background: var(--bg-secondary); border-color: var(--border-subtle);">
                    <div class="d-flex align-items-center gap-3">
                        <?php if ($act['type'] === 'prediction'): ?>
                            <div class="notification-icon type-info">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="flex-grow-1">
                                <span class="text-secondary">Created prediction</span>
                                <a href="<?= SITE_URL ?>/prediction.php?id=<?= (int)$act['id'] ?>" class="fw-bold d-block">
                                    <?= sanitize(truncate($act['detail'], 80)) ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="notification-icon type-success">
                                <i class="fas fa-gavel"></i>
                            </div>
                            <div class="flex-grow-1">
                                <span class="text-secondary">Placed a bet on</span>
                                <a href="<?= SITE_URL ?>/prediction.php?id=<?= (int)$act['id'] ?>" class="fw-bold d-block">
                                    <?= sanitize(truncate($act['detail'], 80)) ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        <div class="text-muted small text-nowrap"><?= timeAgo($act['created_at']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
