<?php
/**
 * Admin - Audit Log Viewer
 * Browse, filter, and export admin audit log entries.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
startSecureSession();
requireAdmin();

$db = getDB();

// ── Filters ──────────────────────────────────────────────────────
$adminFilter = trim($_GET['admin'] ?? '');
$actionFilter = trim($_GET['action'] ?? '');
$targetFilter = trim($_GET['target_type'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($adminFilter !== '') {
    $where[] = 'u.username LIKE :admin';
    $params[':admin'] = '%' . $adminFilter . '%';
}

if ($actionFilter !== '') {
    $where[] = 'a.action LIKE :action';
    $params[':action'] = '%' . $actionFilter . '%';
}

if ($targetFilter !== '') {
    $where[] = 'a.target_type = :target_type';
    $params[':target_type'] = $targetFilter;
}

if ($dateFrom !== '') {
    $where[] = 'a.created_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $where[] = 'a.created_at <= :date_to';
    $params[':date_to'] = $dateTo . ' 23:59:59';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── CSV Export ───────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sql = "SELECT a.id, u.username AS admin_username, a.action, a.target_type, a.target_id,
                   a.details, a.ip_address, a.created_at
            FROM audit_logs a
            LEFT JOIN users u ON a.admin_id = u.id
            {$whereSQL}
            ORDER BY a.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=audit_log_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Admin', 'Action', 'Target Type', 'Target ID', 'Details', 'IP Address', 'Date/Time']);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['id'],
            $row['admin_username'] ?? 'Unknown',
            $row['action'],
            $row['target_type'],
            $row['target_id'],
            $row['details'],
            $row['ip_address'],
            $row['created_at'],
        ]);
    }
    fclose($output);
    exit;
}

// ── Count & Fetch ────────────────────────────────────────────────
$countSQL = "SELECT COUNT(*) AS cnt FROM audit_logs a LEFT JOIN users u ON a.admin_id = u.id {$whereSQL}";
$stmt = $db->prepare($countSQL);
$stmt->execute($params);
$totalLogs = (int)$stmt->fetch()['cnt'];
$totalPages = max(1, (int)ceil($totalLogs / $perPage));

$sql = "SELECT a.*, u.username AS admin_username
        FROM audit_logs a
        LEFT JOIN users u ON a.admin_id = u.id
        {$whereSQL}
        ORDER BY a.created_at DESC
        LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

// Get distinct action types and target types for filters
$stmt = $db->query('SELECT DISTINCT action FROM audit_logs ORDER BY action');
$actionTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $db->query('SELECT DISTINCT target_type FROM audit_logs WHERE target_type IS NOT NULL ORDER BY target_type');
$targetTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Pending payments count for nav
$stmt = $db->query("SELECT COUNT(*) AS cnt FROM payment_requests WHERE status = 'pending'");
$pendingPayments = (int)$stmt->fetch()['cnt'];

$pageTitle = 'Admin - Audit Log';
$currentPage = 'admin_audit';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Admin Sub-Navigation -->
<div class="admin-nav bg-dark border-bottom border-secondary mb-4" style="margin: -1.5rem -0.75rem 1.5rem; padding: 0 0.75rem;">
    <div class="container-xl">
        <nav class="nav nav-pills py-2">
            <a class="nav-link" href="<?= SITE_URL ?>/admin/"><i class="fas fa-tachometer-alt me-1"></i> Dashboard</a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/users.php"><i class="fas fa-users me-1"></i> Users</a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/predictions.php"><i class="fas fa-chart-bar me-1"></i> Predictions</a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/payments.php"><i class="fas fa-credit-card me-1"></i> Payments <?php if ($pendingPayments > 0): ?><span class="badge bg-danger ms-1"><?= $pendingPayments ?></span><?php endif; ?></a>
            <a class="nav-link active" href="<?= SITE_URL ?>/admin/audit.php"><i class="fas fa-clipboard-list me-1"></i> Audit Log</a>
        </nav>
    </div>
</div>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="page-header mb-0">
        <h1><i class="fas fa-clipboard-list me-2 text-accent"></i>Audit Log</h1>
        <p><?= number_format($totalLogs) ?> entries</p>
    </div>
    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-outline-light">
        <i class="fas fa-download me-1"></i> Export CSV
    </a>
</div>

<!-- Filter Bar -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label">Admin User</label>
                <input type="text" name="admin" class="form-control" placeholder="Username..."
                       value="<?= sanitize($adminFilter) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Action</label>
                <select name="action" class="form-select">
                    <option value="">All Actions</option>
                    <?php foreach ($actionTypes as $at): ?>
                    <option value="<?= sanitize($at) ?>" <?= $actionFilter === $at ? 'selected' : '' ?>><?= sanitize($at) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Target Type</label>
                <select name="target_type" class="form-select">
                    <option value="">All Targets</option>
                    <?php foreach ($targetTypes as $tt): ?>
                    <option value="<?= sanitize($tt) ?>" <?= $targetFilter === $tt ? 'selected' : '' ?>><?= sanitize($tt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Date From</label>
                <input type="date" name="date_from" class="form-control" value="<?= sanitize($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Date To</label>
                <input type="date" name="date_to" class="form-control" value="<?= sanitize($dateTo) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- Audit Log Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Admin</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>Details</th>
                        <th>IP Address</th>
                        <th>Date/Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No audit log entries found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= (int)$log['id'] ?></td>
                            <td><span class="badge bg-primary"><?= sanitize($log['admin_username'] ?? 'Unknown') ?></span></td>
                            <td><code><?= sanitize($log['action']) ?></code></td>
                            <td>
                                <?php if ($log['target_type'] && $log['target_id']): ?>
                                    <?php
                                    $targetLink = '#';
                                    if ($log['target_type'] === 'user') {
                                        $targetLink = SITE_URL . '/admin/edit_user.php?id=' . (int)$log['target_id'];
                                    } elseif ($log['target_type'] === 'prediction') {
                                        $targetLink = SITE_URL . '/admin/edit_prediction.php?id=' . (int)$log['target_id'];
                                    } elseif ($log['target_type'] === 'payment_request') {
                                        $targetLink = SITE_URL . '/admin/payments.php';
                                    }
                                    ?>
                                    <a href="<?= $targetLink ?>">
                                        <?= sanitize($log['target_type']) ?> #<?= (int)$log['target_id'] ?>
                                    </a>
                                <?php elseif ($log['target_type']): ?>
                                    <?= sanitize($log['target_type']) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $details = $log['details'] ?? '';
                                $shortDetails = truncate($details, 80);
                                ?>
                                <?php if (strlen($details) > 80): ?>
                                    <span title="<?= sanitize($details) ?>"><?= sanitize($shortDetails) ?></span>
                                    <button type="button" class="btn btn-sm btn-link text-accent p-0 ms-1"
                                            data-bs-toggle="tooltip" data-bs-placement="left"
                                            title="<?= sanitize($details) ?>">
                                        <i class="fas fa-expand-alt"></i>
                                    </button>
                                <?php else: ?>
                                    <?= sanitize($shortDetails ?: '—') ?>
                                <?php endif; ?>
                            </td>
                            <td><code class="small"><?= sanitize($log['ip_address'] ?? '—') ?></code></td>
                            <td class="text-nowrap small"><?= formatDate($log['created_at']) ?></td>
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

<script>
// Initialize Bootstrap tooltips for detail expansion
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (el) {
        return new bootstrap.Tooltip(el);
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
