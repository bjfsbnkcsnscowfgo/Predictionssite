<?php
/**
 * Admin - Edit Prediction
 * Edit details, view bets, resolve or cancel a prediction.
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

$predId = (int)($_GET['id'] ?? 0);
if ($predId <= 0) {
    $_SESSION['flash_error'] = 'Invalid prediction ID.';
    header('Location: ' . SITE_URL . '/admin/predictions.php');
    exit;
}

// ── Handle POST (edit details) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Invalid or expired CSRF token.';
        header('Location: ' . SITE_URL . '/admin/edit_prediction.php?id=' . $predId);
        exit;
    }

    // Fetch old values for audit diff
    $stmt = $db->prepare('SELECT * FROM predictions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $predId]);
    $oldPred = $stmt->fetch();

    if (!$oldPred) {
        $_SESSION['flash_error'] = 'Prediction not found.';
        header('Location: ' . SITE_URL . '/admin/predictions.php');
        exit;
    }

    $newTitle = trim($_POST['title'] ?? $oldPred['title']);
    $newDescription = trim($_POST['description'] ?? $oldPred['description']);
    $newProbability = max(0, min(100, (int)($_POST['probability'] ?? $oldPred['probability'])));
    $newCategory = $_POST['category'] ?? $oldPred['category'];
    $newStatus = $_POST['status'] ?? $oldPred['status'];
    $newResolutionNote = trim($_POST['resolution_note'] ?? '');
    $newExpiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

    // Validate status (only allow certain transitions for edit — resolve is done via resolve.php)
    $allowedStatuses = ['active', 'locked', 'cancelled'];
    if (!in_array($newStatus, $allowedStatuses)) {
        $newStatus = $oldPred['status'];
    }

    // If changing to cancelled from active/locked, use resolvePrediction for proper refunds
    if ($newStatus === 'cancelled' && in_array($oldPred['status'], ['active', 'locked'])) {
        $note = $newResolutionNote ?: 'Cancelled by admin via edit form.';
        $result = resolvePrediction($predId, 'cancel', $adminId, $note);
        if ($result) {
            $_SESSION['flash_success'] = 'Prediction cancelled and bets refunded.';
        } else {
            $_SESSION['flash_error'] = 'Failed to cancel prediction.';
        }
        header('Location: ' . SITE_URL . '/admin/edit_prediction.php?id=' . $predId);
        exit;
    }

    // Build change details for audit
    $changes = [];
    if ($newTitle !== $oldPred['title']) $changes[] = "title changed";
    if ($newDescription !== $oldPred['description']) $changes[] = "description changed";
    if ($newProbability !== (int)$oldPred['probability']) $changes[] = "probability: {$oldPred['probability']} → {$newProbability}";
    if ($newCategory !== $oldPred['category']) $changes[] = "category: {$oldPred['category']} → {$newCategory}";
    if ($newStatus !== $oldPred['status']) $changes[] = "status: {$oldPred['status']} → {$newStatus}";

    $stmt = $db->prepare(
        'UPDATE predictions SET title = :title, description = :description, probability = :probability,
         category = :category, status = :status, resolution_note = :note, expires_at = :expires_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':title' => $newTitle,
        ':description' => $newDescription,
        ':probability' => $newProbability,
        ':category' => $newCategory,
        ':status' => $newStatus,
        ':note' => $newResolutionNote,
        ':expires_at' => $newExpiresAt,
        ':id' => $predId,
    ]);

    $details = $changes ? implode('; ', $changes) : 'No changes detected.';
    logAuditAction($adminId, 'edit_prediction', 'prediction', $predId, $details);
    $_SESSION['flash_success'] = 'Prediction updated.';
    header('Location: ' . SITE_URL . '/admin/edit_prediction.php?id=' . $predId);
    exit;
}

// ── Fetch Prediction ─────────────────────────────────────────────
$stmt = $db->prepare(
    'SELECT p.*, u.username AS creator_username, u.ip_address AS creator_ip
     FROM predictions p
     LEFT JOIN users u ON p.user_id = u.id
     WHERE p.id = :id LIMIT 1'
);
$stmt->execute([':id' => $predId]);
$prediction = $stmt->fetch();

if (!$prediction) {
    $_SESSION['flash_error'] = 'Prediction not found.';
    header('Location: ' . SITE_URL . '/admin/predictions.php');
    exit;
}

// Fetch bets for this prediction
$stmt = $db->prepare(
    'SELECT b.*, u.username AS bettor_username
     FROM bets b
     LEFT JOIN users u ON b.user_id = u.id
     WHERE b.prediction_id = :id
     ORDER BY b.created_at DESC'
);
$stmt->execute([':id' => $predId]);
$bets = $stmt->fetchAll();

// Calculate resolution preview
$forBettors = array_filter($bets, fn($b) => $b['position'] === 'for');
$againstBettors = array_filter($bets, fn($b) => $b['position'] === 'against');

// Pending payments count
$stmt = $db->query("SELECT COUNT(*) AS cnt FROM payment_requests WHERE status = 'pending'");
$pendingPayments = (int)$stmt->fetch()['cnt'];

$categories = getCategoryList();
$isResolvable = in_array($prediction['status'], ['active', 'locked']);

$pageTitle = 'Admin - Edit Prediction';
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

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="page-header mb-0">
        <h1><i class="fas fa-edit me-2 text-accent"></i>Edit Prediction #<?= $predId ?></h1>
        <p>Created by <strong><?= sanitize($prediction['creator_username'] ?? 'Unknown') ?></strong> &middot;
           <span class="badge <?= getStatusBadgeClass($prediction['status']) ?>"><?= sanitize($prediction['status']) ?></span></p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= SITE_URL ?>/prediction.php?id=<?= $predId ?>" class="btn btn-outline-light" target="_blank">
            <i class="fas fa-external-link-alt me-1"></i> View Public
        </a>
        <a href="<?= SITE_URL ?>/admin/predictions.php" class="btn btn-outline-light">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- Left: Prediction Details Form -->
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-info-circle me-2"></i>Prediction Details</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>

                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control" value="<?= sanitize($prediction['title']) ?>" required maxlength="200">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="4" required><?= sanitize($prediction['description']) ?></textarea>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Probability (%)</label>
                            <input type="number" name="probability" class="form-control" min="0" max="100"
                                   value="<?= (int)$prediction['probability'] ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select">
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= sanitize($cat) ?>" <?= $prediction['category'] === $cat ? 'selected' : '' ?>><?= sanitize($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?= $prediction['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="locked" <?= $prediction['status'] === 'locked' ? 'selected' : '' ?>>Locked</option>
                                <option value="cancelled" <?= $prediction['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                            <div class="form-text">Setting to Cancelled will refund all bets.</div>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label">Resolution Note</label>
                            <textarea name="resolution_note" class="form-control" rows="2" placeholder="Optional note..."><?= sanitize($prediction['resolution_note'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expires At</label>
                            <input type="datetime-local" name="expires_at" class="form-control"
                                   value="<?= $prediction['expires_at'] ? date('Y-m-d\TH:i', strtotime($prediction['expires_at'])) : '' ?>">
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bets Table -->
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="fas fa-gavel me-2"></i>Bets (<?= count($bets) ?>)</span>
                <span>
                    Pool: <span class="text-success fw-bold"><?= number_format((float)$prediction['total_pool_for'], 2) ?></span> For /
                    <span class="text-danger fw-bold"><?= number_format((float)$prediction['total_pool_against'], 2) ?></span> Against
                </span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($bets)): ?>
                    <div class="p-4 text-center text-muted">No bets placed yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Amount</th>
                                <th>Position</th>
                                <th>Payout</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bets as $bet): ?>
                            <tr>
                                <td><?= (int)$bet['id'] ?></td>
                                <td>
                                    <a href="<?= SITE_URL ?>/admin/edit_user.php?id=<?= (int)$bet['user_id'] ?>">
                                        <?= sanitize($bet['bettor_username'] ?? 'Unknown') ?>
                                    </a>
                                </td>
                                <td class="credit-amount"><?= number_format((float)$bet['amount'], 2) ?></td>
                                <td>
                                    <span class="badge <?= $bet['position'] === 'for' ? 'bg-success' : 'bg-danger' ?>">
                                        <?= sanitize($bet['position']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($bet['payout'] !== null): ?>
                                        <span class="<?= (float)$bet['payout'] > 0 ? 'text-success' : 'text-danger' ?> fw-bold">
                                            <?= number_format((float)$bet['payout'], 2) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap small"><?= timeAgo($bet['created_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right: Resolve Section & Metadata -->
    <div class="col-lg-5">
        <?php if ($isResolvable): ?>
        <!-- Resolve Section -->
        <div class="card mb-4 border-info" id="resolve">
            <div class="card-header text-info"><i class="fas fa-check-double me-2"></i>Resolve Prediction</div>
            <div class="card-body">
                <div class="mb-3 p-3 rounded" style="background: rgba(255,255,255,0.03);">
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="fw-bold text-success"><?= count($forBettors) ?></div>
                            <small class="text-muted">For bettors</small>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold text-danger"><?= count($againstBettors) ?></div>
                            <small class="text-muted">Against bettors</small>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold credit-amount"><?= number_format((float)$prediction['total_pool_for'] + (float)$prediction['total_pool_against'], 2) ?></div>
                            <small class="text-muted">Total pool</small>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Resolution Note <span class="text-danger">*</span></label>
                    <textarea id="resolveNote" class="form-control" rows="2" placeholder="Explain the resolution..." required></textarea>
                </div>

                <div class="d-grid gap-2">
                    <form method="POST" action="<?= SITE_URL ?>/admin/resolve.php" class="d-inline"
                          onsubmit="return confirmResolve('YES');">
                        <?= csrfField() ?>
                        <input type="hidden" name="prediction_id" value="<?= $predId ?>">
                        <input type="hidden" name="outcome" value="yes">
                        <input type="hidden" name="note" id="noteYes" value="">
                        <button type="submit" class="btn btn-success w-100">
                            <i class="fas fa-check me-1"></i> Resolve YES
                            <small class="d-block"><?= count($forBettors) ?> winners, <?= count($againstBettors) ?> losers</small>
                        </button>
                    </form>

                    <form method="POST" action="<?= SITE_URL ?>/admin/resolve.php" class="d-inline"
                          onsubmit="return confirmResolve('NO');">
                        <?= csrfField() ?>
                        <input type="hidden" name="prediction_id" value="<?= $predId ?>">
                        <input type="hidden" name="outcome" value="no">
                        <input type="hidden" name="note" id="noteNo" value="">
                        <button type="submit" class="btn btn-danger w-100">
                            <i class="fas fa-times me-1"></i> Resolve NO
                            <small class="d-block"><?= count($againstBettors) ?> winners, <?= count($forBettors) ?> losers</small>
                        </button>
                    </form>

                    <form method="POST" action="<?= SITE_URL ?>/admin/resolve.php" class="d-inline"
                          onsubmit="return confirmResolve('CANCEL');">
                        <?= csrfField() ?>
                        <input type="hidden" name="prediction_id" value="<?= $predId ?>">
                        <input type="hidden" name="outcome" value="cancel">
                        <input type="hidden" name="note" id="noteCancel" value="">
                        <button type="submit" class="btn btn-secondary w-100">
                            <i class="fas fa-ban me-1"></i> Cancel Prediction
                            <small class="d-block">Refund all <?= count($bets) ?> bets</small>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <script>
        function confirmResolve(outcome) {
            var note = document.getElementById('resolveNote').value.trim();
            if (!note) {
                alert('Please enter a resolution note.');
                return false;
            }
            document.getElementById('noteYes').value = note;
            document.getElementById('noteNo').value = note;
            document.getElementById('noteCancel').value = note;
            return confirm('Are you sure you want to resolve this prediction as ' + outcome + '?');
        }
        </script>
        <?php endif; ?>

        <!-- Prediction Metadata -->
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-info me-2"></i>Metadata</div>
            <div class="card-body">
                <div class="mb-3">
                    <small class="text-muted text-uppercase">Creator</small>
                    <div>
                        <a href="<?= SITE_URL ?>/admin/edit_user.php?id=<?= (int)$prediction['user_id'] ?>">
                            <?= sanitize($prediction['creator_username'] ?? 'Unknown') ?>
                        </a>
                    </div>
                </div>
                <div class="mb-3">
                    <small class="text-muted text-uppercase">Creator IP</small>
                    <div><code><?= sanitize($prediction['creator_ip'] ?? '—') ?></code></div>
                </div>
                <div class="mb-3">
                    <small class="text-muted text-uppercase">Credit Stake</small>
                    <div class="credit-amount"><?= number_format((float)$prediction['credit_stake'], 2) ?> credits</div>
                </div>
                <div class="mb-3">
                    <small class="text-muted text-uppercase">Created</small>
                    <div><?= formatDate($prediction['created_at']) ?></div>
                </div>
                <?php if ($prediction['resolved_at']): ?>
                <div class="mb-3">
                    <small class="text-muted text-uppercase">Resolved At</small>
                    <div><?= formatDate($prediction['resolved_at']) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($prediction['expires_at']): ?>
                <div class="mb-3">
                    <small class="text-muted text-uppercase">Expires At</small>
                    <div><?= formatDate($prediction['expires_at']) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($prediction['resolution_note']): ?>
                <div>
                    <small class="text-muted text-uppercase">Resolution Note</small>
                    <div class="text-secondary"><?= sanitize($prediction['resolution_note']) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pool Visualization -->
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-pie me-2"></i>Pool Distribution</div>
            <div class="card-body">
                <?php
                $totalPool = (float)$prediction['total_pool_for'] + (float)$prediction['total_pool_against'];
                $forPct = $totalPool > 0 ? round((float)$prediction['total_pool_for'] / $totalPool * 100, 1) : 50;
                $againstPct = $totalPool > 0 ? round((float)$prediction['total_pool_against'] / $totalPool * 100, 1) : 50;
                ?>
                <div class="pool-display">
                    <div class="pool-for">
                        <span class="pool-label">For</span>
                        <span class="pool-value"><?= number_format((float)$prediction['total_pool_for'], 2) ?></span>
                        <div class="small"><?= $forPct ?>%</div>
                    </div>
                    <div class="pool-against">
                        <span class="pool-label">Against</span>
                        <span class="pool-value"><?= number_format((float)$prediction['total_pool_against'], 2) ?></span>
                        <div class="small"><?= $againstPct ?>%</div>
                    </div>
                </div>
                <div class="probability-bar">
                    <div class="probability-bar-fill" style="width: <?= $forPct ?>%; background: var(--success);"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
