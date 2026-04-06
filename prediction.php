<?php
/**
 * Prediction Detail Page
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
startSecureSession();

$db = getDB();
$currentUser = getCurrentUser();
$currentUserId = getCurrentUserId();

$predId = intval($_GET['id'] ?? 0);
if ($predId <= 0) {
    $_SESSION['flash_error'] = 'Invalid prediction ID.';
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}

// Fetch prediction with creator info
$stmt = $db->prepare("
    SELECT p.*, u.username, u.is_shadow_banned
    FROM predictions p
    JOIN users u ON p.user_id = u.id
    WHERE p.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $predId]);
$prediction = $stmt->fetch();

if (!$prediction) {
    $pageTitle = 'Prediction Not Found';
    $currentPage = 'prediction';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-ghost"></i></div>
        <div class="empty-state-title">Prediction Not Found</div>
        <div class="empty-state-text">The prediction you're looking for doesn't exist or has been removed.</div>
        <a href="<?= SITE_URL ?>/index.php" class="btn btn-primary"><i class="fas fa-home me-1"></i>Back to Home</a>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Shadow ban check: don't show shadow-banned users' predictions to others
if ((int)$prediction['is_shadow_banned'] === 1 && $currentUserId !== (int)$prediction['user_id']) {
    $pageTitle = 'Prediction Not Found';
    $currentPage = 'prediction';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-ghost"></i></div>
        <div class="empty-state-title">Prediction Not Found</div>
        <div class="empty-state-text">The prediction you're looking for doesn't exist or has been removed.</div>
        <a href="<?= SITE_URL ?>/index.php" class="btn btn-primary"><i class="fas fa-home me-1"></i>Back to Home</a>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Fetch bets
$stmtBets = $db->prepare("
    SELECT b.*, u.username
    FROM bets b
    JOIN users u ON b.user_id = u.id
    WHERE b.prediction_id = :pid
    ORDER BY b.created_at DESC
");
$stmtBets->execute([':pid' => $predId]);
$bets = $stmtBets->fetchAll();

// Count bets by position
$betsFor = 0;
$betsAgainst = 0;
foreach ($bets as $b) {
    if ($b['position'] === 'for') $betsFor++;
    else $betsAgainst++;
}

// Check if current user already has a bet
$userBet = null;
if ($currentUserId) {
    foreach ($bets as $b) {
        if ((int)$b['user_id'] === $currentUserId) {
            $userBet = $b;
            break;
        }
    }
}

$prob = (int)$prediction['probability'];
$probLevel = $prob < 33 ? 'low' : ($prob < 67 ? 'medium' : 'high');
$totalFor = (float)$prediction['total_pool_for'];
$totalAgainst = (float)$prediction['total_pool_against'];
$totalPool = $totalFor + $totalAgainst;
$statusLabel = str_replace('_', ' ', $prediction['status']);
$isActive = $prediction['status'] === 'active';
$isResolved = in_array($prediction['status'], ['resolved_yes', 'resolved_no']);

// Resolver info
$resolver = null;
if ($prediction['resolved_by']) {
    $stmtR = $db->prepare("SELECT username FROM users WHERE id = :id LIMIT 1");
    $stmtR->execute([':id' => $prediction['resolved_by']]);
    $resolver = $stmtR->fetch();
}

$userCredits = $currentUser ? (float)$currentUser['credits'] : 0;

$pageTitle = $prediction['title'];
$currentPage = 'prediction';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Back Link -->
<div class="mb-3">
    <a href="<?= SITE_URL ?>/index.php" class="text-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to Home
    </a>
</div>

<div class="row g-4">
    <!-- Left Column: Prediction Details -->
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-body">
                <!-- Header: Category + Status -->
                <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
                    <span class="badge <?= getCategoryBadgeClass($prediction['category']) ?> fs-6">
                        <?= sanitize($prediction['category']) ?>
                    </span>
                    <span class="badge <?= getStatusBadgeClass($prediction['status']) ?> fs-6">
                        <?= sanitize(ucwords($statusLabel)) ?>
                    </span>
                </div>

                <!-- Title -->
                <h2 class="fw-bold mb-3"><?= sanitize($prediction['title']) ?></h2>

                <!-- Creator Info -->
                <div class="d-flex align-items-center gap-3 mb-3 text-secondary">
                    <span>
                        <i class="fas fa-user me-1"></i>
                        <a href="<?= SITE_URL ?>/profile.php?id=<?= (int)$prediction['user_id'] ?>">
                            <?= sanitize($prediction['username']) ?>
                        </a>
                    </span>
                    <span><i class="fas fa-coins text-warning me-1"></i><?= number_format($prediction['credit_stake'], 2) ?> stake</span>
                    <span><i class="fas fa-calendar me-1"></i><?= formatDate($prediction['created_at']) ?></span>
                </div>

                <?php if ($prediction['expires_at']): ?>
                    <div class="mb-3">
                        <span class="text-secondary">
                            <i class="fas fa-hourglass-half me-1"></i>
                            Expires: <strong><?= formatDate($prediction['expires_at']) ?></strong>
                            (<?= timeAgo($prediction['expires_at']) ?>)
                        </span>
                    </div>
                <?php endif; ?>

                <!-- Description -->
                <div class="mb-4 p-3 rounded" style="background: var(--bg-tertiary);">
                    <p class="mb-0" style="white-space: pre-line;"><?= sanitize($prediction['description']) ?></p>
                </div>

                <!-- Probability -->
                <div class="mb-4">
                    <h5 class="fw-bold mb-3"><i class="fas fa-chart-bar me-2"></i>Probability Estimate</h5>
                    <div class="text-center mb-2">
                        <span class="display-4 fw-800 text-accent"><?= $prob ?>%</span>
                        <div class="text-secondary small">Creator's estimated probability</div>
                    </div>
                    <div class="probability-bar" style="height: 12px;">
                        <div class="probability-bar-fill" style="width:<?= $prob ?>%" data-prob="<?= $probLevel ?>"></div>
                    </div>
                </div>

                <!-- Pool Statistics -->
                <div class="mb-4">
                    <h5 class="fw-bold mb-3"><i class="fas fa-coins me-2"></i>Pool Statistics</h5>
                    <div class="pool-display">
                        <div class="pool-for">
                            <span class="pool-label"><i class="fas fa-thumbs-up me-1"></i>For Pool</span>
                            <span class="pool-value"><?= number_format($totalFor, 2) ?></span>
                            <div class="small mt-1"><?= $betsFor ?> bet<?= $betsFor !== 1 ? 's' : '' ?></div>
                        </div>
                        <div class="pool-against">
                            <span class="pool-label"><i class="fas fa-thumbs-down me-1"></i>Against Pool</span>
                            <span class="pool-value"><?= number_format($totalAgainst, 2) ?></span>
                            <div class="small mt-1"><?= $betsAgainst ?> bet<?= $betsAgainst !== 1 ? 's' : '' ?></div>
                        </div>
                    </div>
                    <div class="text-center text-secondary small">
                        <i class="fas fa-coins text-warning me-1"></i>Total Pool: <strong><?= number_format($totalPool, 2) ?> credits</strong>
                    </div>
                </div>

                <!-- Resolution Info -->
                <?php if ($isResolved): ?>
                    <div class="alert <?= $prediction['status'] === 'resolved_yes' ? 'alert-success' : 'alert-danger' ?>">
                        <h6 class="fw-bold mb-2">
                            <i class="fas fa-gavel me-2"></i>Prediction Resolved:
                            <?= $prediction['status'] === 'resolved_yes' ? 'YES ✓' : 'NO ✗' ?>
                        </h6>
                        <?php if ($prediction['resolution_note']): ?>
                            <p class="mb-1"><?= sanitize($prediction['resolution_note']) ?></p>
                        <?php endif; ?>
                        <small class="opacity-75">
                            Resolved <?= timeAgo($prediction['resolved_at']) ?>
                            <?php if ($resolver): ?>
                                by <?= sanitize($resolver['username']) ?>
                            <?php endif; ?>
                        </small>
                    </div>
                <?php elseif ($prediction['status'] === 'cancelled'): ?>
                    <div class="alert alert-warning">
                        <h6 class="fw-bold mb-1"><i class="fas fa-ban me-2"></i>Prediction Cancelled</h6>
                        <?php if ($prediction['resolution_note']): ?>
                            <p class="mb-0"><?= sanitize($prediction['resolution_note']) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bet History -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center py-3">
                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Bet History</h5>
                <span class="badge bg-secondary"><?= count($bets) ?> bet<?= count($bets) !== 1 ? 's' : '' ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($bets)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                        No bets placed yet. Be the first!
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Position</th>
                                    <th>Amount</th>
                                    <?php if ($isResolved): ?><th>Payout</th><?php endif; ?>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bets as $bet):
                                    $isCurrentUserBet = ($currentUserId && (int)$bet['user_id'] === $currentUserId);
                                    // Anonymize other users' names
                                    if ($isCurrentUserBet) {
                                        $displayName = sanitize($bet['username']) . ' (you)';
                                    } else {
                                        $un = $bet['username'];
                                        $displayName = sanitize(substr($un, 0, 2) . str_repeat('*', max(1, strlen($un) - 2)));
                                    }
                                ?>
                                    <tr class="<?= $isCurrentUserBet ? 'table-active' : '' ?>">
                                        <td><?= $displayName ?></td>
                                        <td>
                                            <?php if ($bet['position'] === 'for'): ?>
                                                <span class="badge bg-success"><i class="fas fa-thumbs-up me-1"></i>For</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger"><i class="fas fa-thumbs-down me-1"></i>Against</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="credit-amount"><?= number_format($bet['amount'], 2) ?></span></td>
                                        <?php if ($isResolved): ?>
                                            <td>
                                                <?php if ($bet['payout'] !== null): ?>
                                                    <?php if ((float)$bet['payout'] > 0): ?>
                                                        <span class="text-success fw-bold">+<?= number_format($bet['payout'], 2) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-danger fw-bold">0.00</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                        <td class="text-muted small"><?= timeAgo($bet['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Bet Interface -->
    <div class="col-lg-4">
        <?php if ($isActive): ?>
            <div class="bet-section mb-4">
                <h5><i class="fas fa-gavel me-2"></i>Place Your Bet</h5>

                <?php if (!isLoggedIn()): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-lock fa-2x text-muted mb-3 d-block"></i>
                        <p class="text-secondary mb-3">You must be logged in to place a bet.</p>
                        <a href="<?= SITE_URL ?>/login.php" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt me-1"></i>Log In to Bet
                        </a>
                    </div>
                <?php elseif ($userBet): ?>
                    <div class="alert <?= $userBet['position'] === 'for' ? 'alert-success' : 'alert-danger' ?> mb-3">
                        <h6 class="fw-bold mb-1">
                            <i class="fas fa-check-circle me-1"></i>You've already bet on this prediction
                        </h6>
                        <p class="mb-0">
                            You bet <strong><?= number_format($userBet['amount'], 2) ?> credits</strong>
                            <span class="badge <?= $userBet['position'] === 'for' ? 'bg-success' : 'bg-danger' ?>">
                                <?= strtoupper($userBet['position']) ?>
                            </span>
                        </p>
                        <small class="opacity-75"><?= timeAgo($userBet['created_at']) ?></small>
                    </div>
                <?php else: ?>
                    <form method="POST" action="<?= SITE_URL ?>/api/bet.php" id="betForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="prediction_id" value="<?= (int)$prediction['id'] ?>">

                        <div class="mb-3">
                            <label class="form-label">Your Credits</label>
                            <div class="credit-amount fs-5">
                                <i class="fas fa-coins me-1"></i><?= number_format($userCredits, 2) ?>
                            </div>
                        </div>

                        <!-- Position Selection -->
                        <div class="mb-3">
                            <label class="form-label">Your Position</label>
                            <div class="d-grid gap-2 d-grid-cols-2">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <input type="radio" class="btn-check" name="position" id="pos_for" value="for" checked>
                                        <label class="btn btn-outline-success w-100" for="pos_for">
                                            <i class="fas fa-thumbs-up me-1"></i>FOR
                                        </label>
                                    </div>
                                    <div class="col-6">
                                        <input type="radio" class="btn-check" name="position" id="pos_against" value="against">
                                        <label class="btn btn-outline-danger w-100" for="pos_against">
                                            <i class="fas fa-thumbs-down me-1"></i>AGAINST
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Amount -->
                        <div class="mb-3">
                            <label for="betAmount" class="form-label">Bet Amount</label>
                            <input type="number" class="form-control" id="betAmount" name="amount"
                                   min="1" max="<?= floor($userCredits) ?>" value="1" step="1" required>
                            <input type="range" class="form-range bet-slider mt-2" id="betSlider"
                                   min="1" max="<?= max(1, floor($userCredits)) ?>" value="1">
                        </div>

                        <!-- Potential Payout Calculator -->
                        <div class="p-3 rounded mb-3" style="background: var(--bg-tertiary);">
                            <div class="small text-secondary mb-1">Potential Payout</div>
                            <div class="fs-5 fw-bold text-credits" id="potentialPayout">—</div>
                            <div class="small text-muted" id="payoutExplainer"></div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 btn-lg">
                            <i class="fas fa-check me-2"></i>Place Bet
                        </button>
                    </form>

                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const amountInput = document.getElementById('betAmount');
                        const slider = document.getElementById('betSlider');
                        const payoutEl = document.getElementById('potentialPayout');
                        const explainerEl = document.getElementById('payoutExplainer');
                        const posFor = document.getElementById('pos_for');
                        const posAgainst = document.getElementById('pos_against');

                        const poolFor = <?= $totalFor ?>;
                        const poolAgainst = <?= $totalAgainst ?>;

                        function updatePayout() {
                            const amount = parseFloat(amountInput.value) || 0;
                            const isFor = posFor.checked;

                            let payout = 0;
                            if (isFor) {
                                const newForPool = poolFor + amount;
                                payout = amount + (amount / newForPool) * poolAgainst;
                            } else {
                                const newAgainstPool = poolAgainst + amount;
                                payout = amount + (amount / newAgainstPool) * poolFor;
                            }

                            payoutEl.textContent = payout.toFixed(2) + ' credits';
                            const profit = payout - amount;
                            explainerEl.textContent = 'Profit: +' + profit.toFixed(2) + ' credits if you win';
                        }

                        amountInput.addEventListener('input', function() {
                            slider.value = this.value;
                            updatePayout();
                        });

                        slider.addEventListener('input', function() {
                            amountInput.value = this.value;
                            updatePayout();
                        });

                        posFor.addEventListener('change', updatePayout);
                        posAgainst.addEventListener('change', updatePayout);

                        updatePayout();
                    });
                    </script>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Prediction Summary Sidebar Card -->
        <div class="card">
            <div class="card-header py-3">
                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Summary</h6>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <li class="d-flex justify-content-between py-2 border-bottom" style="border-color: var(--border-subtle) !important;">
                        <span class="text-secondary"><i class="fas fa-tag me-1"></i>Category</span>
                        <span class="badge <?= getCategoryBadgeClass($prediction['category']) ?>"><?= sanitize($prediction['category']) ?></span>
                    </li>
                    <li class="d-flex justify-content-between py-2 border-bottom" style="border-color: var(--border-subtle) !important;">
                        <span class="text-secondary"><i class="fas fa-signal me-1"></i>Status</span>
                        <span class="badge <?= getStatusBadgeClass($prediction['status']) ?>"><?= sanitize(ucwords($statusLabel)) ?></span>
                    </li>
                    <li class="d-flex justify-content-between py-2 border-bottom" style="border-color: var(--border-subtle) !important;">
                        <span class="text-secondary"><i class="fas fa-percent me-1"></i>Probability</span>
                        <span class="fw-bold"><?= $prob ?>%</span>
                    </li>
                    <li class="d-flex justify-content-between py-2 border-bottom" style="border-color: var(--border-subtle) !important;">
                        <span class="text-secondary"><i class="fas fa-coins me-1"></i>Total Pool</span>
                        <span class="credit-amount"><?= number_format($totalPool, 2) ?></span>
                    </li>
                    <li class="d-flex justify-content-between py-2 border-bottom" style="border-color: var(--border-subtle) !important;">
                        <span class="text-secondary"><i class="fas fa-users me-1"></i>Total Bets</span>
                        <span class="fw-bold"><?= count($bets) ?></span>
                    </li>
                    <li class="d-flex justify-content-between py-2">
                        <span class="text-secondary"><i class="fas fa-calendar me-1"></i>Created</span>
                        <span><?= timeAgo($prediction['created_at']) ?></span>
                    </li>
                    <?php if ($prediction['expires_at']): ?>
                    <li class="d-flex justify-content-between py-2 border-top" style="border-color: var(--border-subtle) !important;">
                        <span class="text-secondary"><i class="fas fa-hourglass-half me-1"></i>Expires</span>
                        <span><?= formatDate($prediction['expires_at']) ?></span>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
