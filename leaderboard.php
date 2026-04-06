<?php
/**
 * Leaderboard Page
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
startSecureSession();

$db = getDB();
$currentUser = getCurrentUser();
$currentUserId = getCurrentUserId();

$tab = trim($_GET['tab'] ?? 'accuracy');
if (!in_array($tab, ['accuracy', 'credits', 'predictions'])) {
    $tab = 'accuracy';
}

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$leaderboard = [];
$totalResults = 0;

switch ($tab) {
    case 'accuracy':
        // Min 5 predictions for accuracy ranking
        $stmtC = $db->query("SELECT COUNT(*) AS total FROM users WHERE total_predictions >= 5 AND is_banned = 0");
        $totalResults = (int)$stmtC->fetch()['total'];

        $stmt = $db->prepare("
            SELECT id, username, total_predictions, correct_predictions, credits,
                   ROUND((correct_predictions / total_predictions) * 100, 1) AS accuracy
            FROM users
            WHERE total_predictions >= 5 AND is_banned = 0
            ORDER BY accuracy DESC, total_predictions DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $leaderboard = $stmt->fetchAll();
        break;

    case 'credits':
        $stmtC = $db->query("SELECT COUNT(*) AS total FROM users WHERE is_banned = 0");
        $totalResults = (int)$stmtC->fetch()['total'];

        $stmt = $db->prepare("
            SELECT id, username, total_predictions, correct_predictions, credits,
                   CASE WHEN total_predictions > 0
                        THEN ROUND((correct_predictions / total_predictions) * 100, 1)
                        ELSE 0 END AS accuracy
            FROM users
            WHERE is_banned = 0
            ORDER BY credits DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $leaderboard = $stmt->fetchAll();
        break;

    case 'predictions':
        $stmtC = $db->query("SELECT COUNT(*) AS total FROM users WHERE total_predictions > 0 AND is_banned = 0");
        $totalResults = (int)$stmtC->fetch()['total'];

        $stmt = $db->prepare("
            SELECT id, username, total_predictions, correct_predictions, credits,
                   CASE WHEN total_predictions > 0
                        THEN ROUND((correct_predictions / total_predictions) * 100, 1)
                        ELSE 0 END AS accuracy
            FROM users
            WHERE total_predictions > 0 AND is_banned = 0
            ORDER BY total_predictions DESC, accuracy DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $leaderboard = $stmt->fetchAll();
        break;
}

$totalPages = max(1, ceil($totalResults / $perPage));

$rankIcons = ['🥇', '🥈', '🥉'];

$pageTitle = 'Leaderboard';
$currentPage = 'leaderboard';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header text-center">
    <h1><i class="fas fa-trophy text-warning me-2"></i>Leaderboard</h1>
    <p>Top forecasters on the platform</p>
</div>

<!-- Tabs -->
<ul class="nav nav-pills justify-content-center mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'accuracy' ? 'active' : '' ?>"
           href="?tab=accuracy">
            <i class="fas fa-bullseye me-1"></i> By Accuracy
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'credits' ? 'active' : '' ?>"
           href="?tab=credits">
            <i class="fas fa-coins me-1"></i> By Credits
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'predictions' ? 'active' : '' ?>"
           href="?tab=predictions">
            <i class="fas fa-chart-line me-1"></i> By Predictions
        </a>
    </li>
</ul>

<?php if ($tab === 'accuracy'): ?>
    <p class="text-center text-muted small mb-4">
        <i class="fas fa-info-circle me-1"></i>Minimum 5 predictions required to qualify
    </p>
<?php endif; ?>

<?php if (empty($leaderboard)): ?>
    <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-trophy"></i></div>
        <div class="empty-state-title">No rankings yet</div>
        <div class="empty-state-text">
            <?php if ($tab === 'accuracy'): ?>
                No users have enough predictions yet to be ranked by accuracy.
            <?php else: ?>
                Start making predictions to appear on the leaderboard!
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <!-- Top 3 Podium -->
    <?php if ($page === 1 && count($leaderboard) >= 3): ?>
        <div class="row g-3 mb-4 justify-content-center">
            <?php
            $podiumOrder = [1, 0, 2]; // Silver, Gold, Bronze
            foreach ($podiumOrder as $idx):
                if (!isset($leaderboard[$idx])) continue;
                $entry = $leaderboard[$idx];
                $rank = $idx + 1;
                $firstLetter = strtoupper(mb_substr($entry['username'], 0, 1));
                $isMe = ($currentUserId && (int)$entry['id'] === $currentUserId);
                $podiumSizes = [1 => 'col-md-4', 0 => 'col-md-4', 2 => 'col-md-4'];
                $medalColors = [1 => '#ffd700', 2 => '#c0c0c0', 3 => '#cd7f32'];
            ?>
                <div class="<?= $podiumSizes[$idx] ?? 'col-md-4' ?>">
                    <div class="card text-center h-100 <?= $isMe ? 'border-accent' : '' ?>"
                         style="<?= $rank === 1 ? 'border-color: rgba(255,215,0,0.3); background: linear-gradient(135deg, rgba(255,215,0,0.06) 0%, var(--bg-secondary) 100%);' : ($rank === 2 ? 'border-color: rgba(192,192,192,0.2);' : 'border-color: rgba(205,127,50,0.2);') ?>">
                        <div class="card-body py-4">
                            <div class="mb-2" style="font-size: <?= $rank === 1 ? '3rem' : '2.5rem' ?>;">
                                <?= $rankIcons[$rank - 1] ?>
                            </div>
                            <div class="profile-avatar mx-auto mb-2" style="width:<?= $rank === 1 ? '70px' : '60px' ?>;height:<?= $rank === 1 ? '70px' : '60px' ?>;font-size:<?= $rank === 1 ? '1.8rem' : '1.5rem' ?>;border-color: <?= $medalColors[$rank] ?>;">
                                <?= $firstLetter ?>
                            </div>
                            <h5 class="fw-bold mb-1">
                                <a href="<?= SITE_URL ?>/profile.php?id=<?= (int)$entry['id'] ?>"><?= sanitize($entry['username']) ?></a>
                                <?php if ($isMe): ?>
                                    <span class="badge bg-primary ms-1">You</span>
                                <?php endif; ?>
                            </h5>
                            <div class="d-flex justify-content-center gap-3 mt-2">
                                <div>
                                    <div class="fw-800 <?= $tab === 'accuracy' ? 'text-accent' : '' ?>"><?= $entry['accuracy'] ?? 0 ?>%</div>
                                    <div class="small text-muted">Accuracy</div>
                                </div>
                                <div>
                                    <div class="fw-800"><?= $entry['total_predictions'] ?></div>
                                    <div class="small text-muted">Predictions</div>
                                </div>
                                <div>
                                    <div class="fw-800 text-credits"><?= number_format($entry['credits'], 0) ?></div>
                                    <div class="small text-muted">Credits</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Full Leaderboard Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="leaderboard-list">
                <?php foreach ($leaderboard as $idx => $entry):
                    $rank = $offset + $idx + 1;
                    $isMe = ($currentUserId && (int)$entry['id'] === $currentUserId);
                    $firstLetter = strtoupper(mb_substr($entry['username'], 0, 1));
                ?>
                    <div class="leaderboard-item <?= $isMe ? 'border-start border-3' : '' ?>"
                         style="<?= $isMe ? 'border-left-color: var(--accent) !important; background: rgba(99,102,241,0.05);' : '' ?>">
                        <div class="leaderboard-rank">
                            <?php if ($rank <= 3 && $page === 1): ?>
                                <?= $rankIcons[$rank - 1] ?>
                            <?php else: ?>
                                #<?= $rank ?>
                            <?php endif; ?>
                        </div>
                        <div class="profile-avatar me-3" style="width:40px;height:40px;font-size:1rem;">
                            <?= $firstLetter ?>
                        </div>
                        <div class="leaderboard-username">
                            <a href="<?= SITE_URL ?>/profile.php?id=<?= (int)$entry['id'] ?>"><?= sanitize($entry['username']) ?></a>
                            <?php if ($isMe): ?>
                                <span class="badge bg-primary ms-1 small">You</span>
                            <?php endif; ?>
                        </div>
                        <div class="d-none d-md-flex gap-4 me-3 text-center">
                            <div>
                                <div class="fw-bold small"><?= $entry['accuracy'] ?? 0 ?>%</div>
                                <div class="text-muted" style="font-size:0.7rem;">ACCURACY</div>
                            </div>
                            <div>
                                <div class="fw-bold small"><?= $entry['total_predictions'] ?></div>
                                <div class="text-muted" style="font-size:0.7rem;">PREDICTIONS</div>
                            </div>
                        </div>
                        <div class="leaderboard-score">
                            <i class="fas fa-coins me-1"></i><?= number_format($entry['credits'], 0) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?tab=<?= $tab ?>&page=<?= $page - 1 ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?tab=<?= $tab ?>&page=<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?tab=<?= $tab ?>&page=<?= $page + 1 ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
