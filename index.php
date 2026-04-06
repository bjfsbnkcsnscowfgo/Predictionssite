<?php
/**
 * Admin Dashboard - Home Page
 * Overview of platform stats, recent activity, and quick actions.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
startSecureSession();
requireAdmin();

$db = getDB();
$admin = getCurrentUser();

// --- Gather Stats ---
// [OPTIMIZED] Combined 6 stat queries into 1 using subqueries
$stmt = $db->query("
    SELECT
        (SELECT COUNT(*) FROM users) AS total_users,
        (SELECT COUNT(*) FROM predictions) AS total_predictions,
        (SELECT COUNT(*) FROM predictions WHERE status = 'active') AS active_predictions,
        (SELECT COALESCE(SUM(credits), 0) FROM users) AS total_credits,
        (SELECT COUNT(*) FROM payment_requests WHERE status = 'pending') AS pending_payments,
        (SELECT COUNT(*) FROM bets) AS total_bets
");
$mainStats = $stmt->fetch();
$totalUsers = (int)$mainStats['total_users'];
$totalPredictions = (int)$mainStats['total_predictions'];
$activePredictions = (int)$mainStats['active_predictions'];
$totalCredits = (float)$mainStats['total_credits'];
$pendingPayments = (int)$mainStats['pending_payments'];
$totalBets = (int)$mainStats['total_bets'];

// --- Recent Activity (Last 10 Audit Log Entries) ---
$stmt = $db->query(
    'SELECT a.*, u.username AS admin_username
     FROM audit_logs a
     LEFT JOIN users u ON a.admin_id = u.id
     ORDER BY a.created_at DESC
     LIMIT 10'
);
$recentLogs = $stmt->fetchAll();

// --- System Health ---
// Flagged accounts (multiple IPs with >2 accounts)
$stmt = $db->query(
    'SELECT ip_address, COUNT(DISTINCT user_id) AS account_count
     FROM ip_accounts
     GROUP BY ip_address
     HAVING account_count > 2
     ORDER BY account_count DESC
     LIMIT 5'
);
$flaggedIPs = $stmt->fetchAll();

// [OPTIMIZED] Combined 3 weekly stat queries into 1 using subqueries
$stmt = $db->query("
    SELECT
        (SELECT COUNT(*) FROM predictions WHERE resolved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status IN ('resolved_yes', 'resolved_no')) AS resolved_this_week,
        (SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS new_users_this_week,
        (SELECT COUNT(*) FROM bets WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS bets_this_week
");
$weeklyStats = $stmt->fetch();
$resolvedThisWeek = (int)$weeklyStats['resolved_this_week'];
$newUsersThisWeek = (int)$weeklyStats['new_users_this_week'];
$betsThisWeek = (int)$weeklyStats['bets_this_week'];

$pageTitle = 'Admin Dashboard';
$currentPage = 'admin_dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Admin Sub-Navigation -->
<div class="admin-nav bg-dark border-bottom border-secondary mb-4" style="margin: -1.5rem -0.75rem 1.5rem; padding: 0 0.75rem;">
    <div class="container-xl">
        <nav class="nav nav-pills py-2">
            <a class="nav-link active" href="<?= SITE_URL ?>/admin/"><i class="fas fa-tachometer-alt me-1"></i> Dashboard</a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/users.php"><i class="fas fa-users me-1"></i> Users</a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/predictions.php"><i class="fas fa-chart-bar me-1"></i> Predictions</a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/payments.php"><i class="fas fa-credit-card me-1"></i> Payments <?php if ($pendingPayments > 0): ?><span class="badge bg-danger ms-1"><?= $pendingPayments ?></span><?php endif; ?></a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/audit.php"><i class="fas fa-clipboard-list me-1"></i> Audit Log</a>
        </nav>
    </div>
</div>

<!-- Welcome -->
<div class="page-header mb-4">
    <h1><i class="fas fa-shield-halved text-accent me-2"></i>Admin Dashboard</h1>
    <p>Welcome back, <strong><?= sanitize($admin['username']) ?></strong>. Here's your platform overview.</p>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="admin-stat-card">
            <div class="admin-stat-icon text-info"><i class="fas fa-users"></i></div>
            <div class="admin-stat-value"><?= number_format($totalUsers) ?></div>
            <div class="admin-stat-label">Total Users</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="admin-stat-card">
            <div class="admin-stat-icon text-accent"><i class="fas fa-chart-line"></i></div>
            <div class="admin-stat-value"><?= number_format($totalPredictions) ?></div>
            <div class="admin-stat-label">Total Predictions</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="admin-stat-card">
            <div class="admin-stat-icon" style="color: var(--success);"><i class="fas fa-bolt"></i></div>
            <div class="admin-stat-value"><?= number_format($activePredictions) ?></div>
            <div class="admin-stat-label">Active Predictions</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="admin-stat-card">
            <div class="admin-stat-icon" style="color: var(--credits-color);"><i class="fas fa-coins"></i></div>
            <div class="admin-stat-value"><?= number_format($totalCredits, 0) ?></div>
            <div class="admin-stat-label">Credits in Circulation</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="admin-stat-card">
            <div class="admin-stat-icon" style="color: var(--warning);"><i class="fas fa-clock"></i></div>
            <div class="admin-stat-value"><?= number_format($pendingPayments) ?></div>
            <div class="admin-stat-label">Pending Payments</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="admin-stat-card">
            <div class="admin-stat-icon" style="color: var(--danger);"><i class="fas fa-gavel"></i></div>
            <div class="admin-stat-value"><?= number_format($totalBets) ?></div>
            <div class="admin-stat-label">Total Bets</div>
        </div>
    </div>
</div>

<!-- Weekly Stats & Quick Actions -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center">
                <i class="fas fa-chart-area me-2 text-accent"></i> This Week's Activity
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3 p-3 rounded" style="background: rgba(255,255,255,0.03);">
                    <span class="text-secondary"><i class="fas fa-check-circle me-2 text-success"></i>Predictions Resolved</span>
                    <span class="fw-bold"><?= $resolvedThisWeek ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3 p-3 rounded" style="background: rgba(255,255,255,0.03);">
                    <span class="text-secondary"><i class="fas fa-user-plus me-2 text-info"></i>New Users</span>
                    <span class="fw-bold"><?= $newUsersThisWeek ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center p-3 rounded" style="background: rgba(255,255,255,0.03);">
                    <span class="text-secondary"><i class="fas fa-gavel me-2 text-warning"></i>Bets Placed</span>
                    <span class="fw-bold"><?= $betsThisWeek ?></span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center">
                <i class="fas fa-bolt me-2 text-warning"></i> Quick Actions
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="<?= SITE_URL ?>/admin/users.php" class="btn btn-outline-light text-start">
                        <i class="fas fa-users me-2"></i> Manage Users
                    </a>
                    <a href="<?= SITE_URL ?>/admin/predictions.php" class="btn btn-outline-light text-start">
                        <i class="fas fa-chart-bar me-2"></i> Manage Predictions
                    </a>
                    <a href="<?= SITE_URL ?>/admin/payments.php" class="btn btn-outline-light text-start">
                        <i class="fas fa-credit-card me-2"></i> View Payments
                        <?php if ($pendingPayments > 0): ?>
                            <span class="badge bg-danger float-end"><?= $pendingPayments ?> pending</span>
                        <?php endif; ?>
                    </a>
                    <a href="<?= SITE_URL ?>/admin/audit.php" class="btn btn-outline-light text-start">
                        <i class="fas fa-clipboard-list me-2"></i> View Audit Log
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- System Health -->
<?php if (!empty($flaggedIPs)): ?>
<div class="card mb-4 border-warning">
    <div class="card-header text-warning d-flex align-items-center">
        <i class="fas fa-exclamation-triangle me-2"></i> Flagged IP Addresses (Multiple Accounts)
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr>
                        <th>IP Address</th>
                        <th>Accounts</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($flaggedIPs as $flagged): ?>
                    <tr>
                        <td><code><?= sanitize($flagged['ip_address']) ?></code></td>
                        <td><span class="badge bg-danger"><?= (int)$flagged['account_count'] ?> accounts</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Recent Activity -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fas fa-history me-2 text-accent"></i> Recent Activity</span>
        <a href="<?= SITE_URL ?>/admin/audit.php" class="btn btn-sm btn-outline-light">View All</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentLogs)): ?>
            <div class="empty-state py-4">
                <div class="empty-state-icon"><i class="fas fa-clipboard-check"></i></div>
                <div class="empty-state-title">No Activity Yet</div>
                <div class="empty-state-text">Audit log entries will appear here.</div>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr>
                        <th>Admin</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>Details</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td>
                            <span class="badge bg-primary"><?= sanitize($log['admin_username'] ?? 'Unknown') ?></span>
                        </td>
                        <td><code><?= sanitize($log['action']) ?></code></td>
                        <td>
                            <?php if ($log['target_type']): ?>
                                <?= sanitize($log['target_type']) ?>
                                <?php if ($log['target_id']): ?>#<?= (int)$log['target_id'] ?><?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td title="<?= sanitize($log['details'] ?? '') ?>"><?= sanitize(truncate($log['details'] ?? '', 60)) ?></td>
                        <td class="text-nowrap"><?= timeAgo($log['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
