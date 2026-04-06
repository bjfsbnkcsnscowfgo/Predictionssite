<?php
/**
 * Admin - Edit User
 * View / edit a single user's details, history, and status.
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

$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) {
    $_SESSION['flash_error'] = 'Invalid user ID.';
    header('Location: ' . SITE_URL . '/admin/users.php');
    exit;
}

// ── Handle POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Invalid or expired CSRF token.';
        header('Location: ' . SITE_URL . '/admin/edit_user.php?id=' . $userId);
        exit;
    }

    $action = $_POST['action'] ?? 'update';

    if ($action === 'delete') {
        // Delete user account
        // Delete related data first (bets, notifications, payment_requests, predictions, ip_accounts, audit_logs referencing user)
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM notifications WHERE user_id = :id')->execute([':id' => $userId]);
            $db->prepare('DELETE FROM bets WHERE user_id = :id')->execute([':id' => $userId]);
            $db->prepare('DELETE FROM payment_requests WHERE user_id = :id')->execute([':id' => $userId]);
            $db->prepare('DELETE FROM ip_accounts WHERE user_id = :id')->execute([':id' => $userId]);
            // Set predictions to a system state - don't delete them (keep data integrity)
            // Nullify resolved_by if this user resolved predictions
            $db->prepare('UPDATE predictions SET resolved_by = NULL WHERE resolved_by = :id')->execute([':id' => $userId]);
            $db->prepare('DELETE FROM predictions WHERE user_id = :id')->execute([':id' => $userId]);
            $db->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $userId]);
            $db->commit();

            logAuditAction($adminId, 'delete_user', 'user', $userId, 'User account deleted.');
            $_SESSION['flash_success'] = 'User account deleted.';
            header('Location: ' . SITE_URL . '/admin/users.php');
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['flash_error'] = 'Failed to delete user: ' . $e->getMessage();
            header('Location: ' . SITE_URL . '/admin/edit_user.php?id=' . $userId);
            exit;
        }
    }

    if ($action === 'reset_password') {
        $newPassword = trim($_POST['new_password'] ?? '');
        if (strlen($newPassword) < 6) {
            $_SESSION['flash_error'] = 'Password must be at least 6 characters.';
            header('Location: ' . SITE_URL . '/admin/edit_user.php?id=' . $userId);
            exit;
        }
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([':hash' => $hash, ':id' => $userId]);
        logAuditAction($adminId, 'reset_password', 'user', $userId, 'Password reset by admin.');
        $_SESSION['flash_success'] = 'Password has been reset.';
        header('Location: ' . SITE_URL . '/admin/edit_user.php?id=' . $userId);
        exit;
    }

    // Default: Update user details
    // Fetch old values for audit diff
    $stmt = $db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $oldUser = $stmt->fetch();

    if (!$oldUser) {
        $_SESSION['flash_error'] = 'User not found.';
        header('Location: ' . SITE_URL . '/admin/users.php');
        exit;
    }

    $newUsername = trim($_POST['username'] ?? $oldUser['username']);
    $newEmail = trim($_POST['email'] ?? $oldUser['email']);
    $newCredits = (float)($_POST['credits'] ?? $oldUser['credits']);
    $newBio = trim($_POST['bio'] ?? '');
    $newIsAdmin = isset($_POST['is_admin']) ? 1 : 0;
    $newIsBanned = isset($_POST['is_banned']) ? 1 : 0;
    $newIsShadowBanned = isset($_POST['is_shadow_banned']) ? 1 : 0;
    $newBanReason = trim($_POST['ban_reason'] ?? '');

    // Build change details for audit log
    $changes = [];
    if ($newUsername !== $oldUser['username']) $changes[] = "username: '{$oldUser['username']}' → '{$newUsername}'";
    if ($newEmail !== $oldUser['email']) $changes[] = "email: '{$oldUser['email']}' → '{$newEmail}'";
    if (abs($newCredits - (float)$oldUser['credits']) > 0.001) $changes[] = "credits: {$oldUser['credits']} → {$newCredits}";
    if ($newIsAdmin !== (int)$oldUser['is_admin']) $changes[] = "is_admin: {$oldUser['is_admin']} → {$newIsAdmin}";
    if ($newIsBanned !== (int)$oldUser['is_banned']) $changes[] = "is_banned: {$oldUser['is_banned']} → {$newIsBanned}";
    if ($newIsShadowBanned !== (int)$oldUser['is_shadow_banned']) $changes[] = "is_shadow_banned: {$oldUser['is_shadow_banned']} → {$newIsShadowBanned}";

    $stmt = $db->prepare(
        'UPDATE users SET username = :username, email = :email, credits = :credits, bio = :bio,
         is_admin = :is_admin, is_banned = :is_banned, is_shadow_banned = :is_shadow_banned,
         ban_reason = :ban_reason
         WHERE id = :id'
    );
    $stmt->execute([
        ':username' => $newUsername,
        ':email' => $newEmail,
        ':credits' => $newCredits,
        ':bio' => $newBio,
        ':is_admin' => $newIsAdmin,
        ':is_banned' => $newIsBanned,
        ':is_shadow_banned' => $newIsShadowBanned,
        ':ban_reason' => $newBanReason,
        ':id' => $userId,
    ]);

    $details = $changes ? implode('; ', $changes) : 'No changes detected.';
    logAuditAction($adminId, 'edit_user', 'user', $userId, $details);
    $_SESSION['flash_success'] = 'User updated successfully.';
    header('Location: ' . SITE_URL . '/admin/edit_user.php?id=' . $userId);
    exit;
}

// ── Fetch User ───────────────────────────────────────────────────
$stmt = $db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION['flash_error'] = 'User not found.';
    header('Location: ' . SITE_URL . '/admin/users.php');
    exit;
}

// User's predictions
$stmt = $db->prepare(
    'SELECT id, title, status, created_at FROM predictions WHERE user_id = :id ORDER BY created_at DESC LIMIT 20'
);
$stmt->execute([':id' => $userId]);
$userPredictions = $stmt->fetchAll();

// User's bets
$stmt = $db->prepare(
    'SELECT b.*, p.title AS prediction_title
     FROM bets b
     LEFT JOIN predictions p ON b.prediction_id = p.id
     WHERE b.user_id = :id
     ORDER BY b.created_at DESC LIMIT 20'
);
$stmt->execute([':id' => $userId]);
$userBets = $stmt->fetchAll();

// Payment history
$stmt = $db->prepare(
    'SELECT * FROM payment_requests WHERE user_id = :id ORDER BY created_at DESC LIMIT 20'
);
$stmt->execute([':id' => $userId]);
$userPayments = $stmt->fetchAll();

// Pending payments count for nav badge
$stmt = $db->query("SELECT COUNT(*) AS cnt FROM payment_requests WHERE status = 'pending'");
$pendingPayments = (int)$stmt->fetch()['cnt'];

$pageTitle = 'Admin - Edit User: ' . $user['username'];
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

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="page-header mb-0">
        <h1><i class="fas fa-user-edit me-2 text-accent"></i>Edit User: <?= sanitize($user['username']) ?></h1>
        <p>User ID: <?= (int)$user['id'] ?></p>
    </div>
    <a href="<?= SITE_URL ?>/admin/users.php" class="btn btn-outline-light">
        <i class="fas fa-arrow-left me-1"></i> Back to Users
    </a>
</div>

<div class="row g-4">
    <!-- Left Column: User Details Form -->
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-id-card me-2"></i>User Details</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control"
                                   value="<?= sanitize($user['username']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= sanitize($user['email']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Credits Balance</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-coins text-warning"></i></span>
                                <input type="number" name="credits" class="form-control" step="0.01"
                                       value="<?= number_format((float)$user['credits'], 2, '.', '') ?>">
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Bio</label>
                            <textarea name="bio" class="form-control" rows="3"><?= sanitize($user['bio'] ?? '') ?></textarea>
                        </div>

                        <div class="col-12">
                            <hr class="border-secondary">
                            <h6 class="text-secondary mb-3">Permissions & Status</h6>
                        </div>

                        <div class="col-md-4">
                            <div class="form-check">
                                <input type="checkbox" name="is_admin" class="form-check-input" id="isAdmin"
                                       <?= (int)$user['is_admin'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="isAdmin">
                                    <i class="fas fa-shield-halved me-1 text-primary"></i> Admin
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input type="checkbox" name="is_banned" class="form-check-input" id="isBanned"
                                       <?= (int)$user['is_banned'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="isBanned">
                                    <i class="fas fa-ban me-1 text-danger"></i> Banned
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input type="checkbox" name="is_shadow_banned" class="form-check-input" id="isShadowBanned"
                                       <?= (int)$user['is_shadow_banned'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="isShadowBanned">
                                    <i class="fas fa-ghost me-1 text-warning"></i> Shadow Banned
                                </label>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Ban Reason</label>
                            <input type="text" name="ban_reason" class="form-control"
                                   value="<?= sanitize($user['ban_reason'] ?? '') ?>"
                                   placeholder="Reason for ban (visible to user on login)">
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Reset Password -->
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-key me-2"></i>Reset Password</div>
            <div class="card-body">
                <form method="POST" onsubmit="return confirm('Are you sure you want to reset this user\'s password?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label">New Password</label>
                            <input type="text" name="new_password" class="form-control" placeholder="Min. 6 characters" required minlength="6">
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="fas fa-key me-1"></i> Reset Password
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Account -->
        <div class="card border-danger">
            <div class="card-header text-danger"><i class="fas fa-trash-alt me-2"></i>Danger Zone</div>
            <div class="card-body">
                <p class="text-secondary mb-3">Permanently delete this user account and all associated data. This action cannot be undone.</p>
                <form method="POST" onsubmit="return confirm('PERMANENTLY DELETE this user account? This cannot be undone!');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash-alt me-1"></i> Delete Account
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Sensitive Info & History -->
    <div class="col-lg-5">
        <!-- Sensitive Info -->
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-lock me-2"></i>Sensitive Information</div>
            <div class="card-body">
                <div class="mb-3">
                    <small class="text-muted text-uppercase">IP Address</small>
                    <div><code><?= sanitize($user['ip_address'] ?? '—') ?></code></div>
                </div>
                <div class="mb-3">
                    <small class="text-muted text-uppercase">Country</small>
                    <div><?= sanitize($user['country'] ?? '—') ?></div>
                </div>
                <div class="mb-3">
                    <small class="text-muted text-uppercase">Device Info</small>
                    <div class="small text-secondary"><?= sanitize(truncate($user['device_info'] ?? '—', 200)) ?></div>
                </div>
                <div class="mb-3">
                    <small class="text-muted text-uppercase">Account Created</small>
                    <div><?= formatDate($user['created_at']) ?></div>
                </div>
                <div>
                    <small class="text-muted text-uppercase">Last Login</small>
                    <div><?= $user['last_login'] ? formatDate($user['last_login']) : '—' ?></div>
                </div>
            </div>
        </div>

        <!-- User's Predictions -->
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="fas fa-chart-line me-2"></i>Predictions (<?= count($userPredictions) ?>)</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($userPredictions)): ?>
                    <div class="p-3 text-center text-muted">No predictions yet.</div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($userPredictions as $p): ?>
                    <a href="<?= SITE_URL ?>/admin/edit_prediction.php?id=<?= (int)$p['id'] ?>"
                       class="list-group-item list-group-item-action bg-transparent text-light border-secondary">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small"><?= sanitize(truncate($p['title'], 50)) ?></span>
                            <span class="badge <?= getStatusBadgeClass($p['status']) ?>"><?= sanitize($p['status']) ?></span>
                        </div>
                        <small class="text-muted"><?= timeAgo($p['created_at']) ?></small>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- User's Bets -->
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-gavel me-2"></i>Bets (<?= count($userBets) ?>)</div>
            <div class="card-body p-0">
                <?php if (empty($userBets)): ?>
                    <div class="p-3 text-center text-muted">No bets yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0 small">
                        <thead>
                            <tr>
                                <th>Prediction</th>
                                <th>Amount</th>
                                <th>Position</th>
                                <th>Payout</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($userBets as $bet): ?>
                            <tr>
                                <td>
                                    <a href="<?= SITE_URL ?>/admin/edit_prediction.php?id=<?= (int)$bet['prediction_id'] ?>">
                                        <?= sanitize(truncate($bet['prediction_title'] ?? 'Unknown', 30)) ?>
                                    </a>
                                </td>
                                <td class="credit-amount"><?= number_format((float)$bet['amount'], 2) ?></td>
                                <td>
                                    <span class="badge <?= $bet['position'] === 'for' ? 'bg-success' : 'bg-danger' ?>">
                                        <?= sanitize($bet['position']) ?>
                                    </span>
                                </td>
                                <td><?= $bet['payout'] !== null ? number_format((float)$bet['payout'], 2) : '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Requests -->
        <div class="card">
            <div class="card-header"><i class="fas fa-credit-card me-2"></i>Payment Requests (<?= count($userPayments) ?>)</div>
            <div class="card-body p-0">
                <?php if (empty($userPayments)): ?>
                    <div class="p-3 text-center text-muted">No payment requests.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0 small">
                        <thead>
                            <tr>
                                <th>Amount</th>
                                <th>Credits</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($userPayments as $pay): ?>
                            <tr>
                                <td>$<?= number_format((float)$pay['amount'], 2) ?></td>
                                <td class="credit-amount"><?= number_format((float)$pay['credits_requested'], 2) ?></td>
                                <td>
                                    <?php
                                    $pBadge = ['pending' => 'bg-warning text-dark', 'approved' => 'bg-success', 'rejected' => 'bg-danger'];
                                    ?>
                                    <span class="badge <?= $pBadge[$pay['status']] ?? 'bg-secondary' ?>"><?= sanitize($pay['status']) ?></span>
                                </td>
                                <td class="text-nowrap"><?= timeAgo($pay['created_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
