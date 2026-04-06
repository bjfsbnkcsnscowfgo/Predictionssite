<?php
/**
 * Admin - Payment Requests Management
 * Approve or reject credit purchase requests.
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
        header('Location: ' . SITE_URL . '/admin/payments.php');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $adminNote = trim($_POST['admin_note'] ?? '');

    if ($paymentId <= 0) {
        $_SESSION['flash_error'] = 'Invalid payment request ID.';
        header('Location: ' . SITE_URL . '/admin/payments.php');
        exit;
    }

    if ($adminNote === '') {
        $_SESSION['flash_error'] = 'Admin note is required.';
        $qs = $_GET ? '?' . http_build_query($_GET) : '';
        header('Location: ' . SITE_URL . '/admin/payments.php' . $qs);
        exit;
    }

    // Fetch payment request
    $stmt = $db->prepare('SELECT * FROM payment_requests WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $paymentId]);
    $payment = $stmt->fetch();

    if (!$payment || $payment['status'] !== 'pending') {
        $_SESSION['flash_error'] = 'Payment request not found or already processed.';
        header('Location: ' . SITE_URL . '/admin/payments.php');
        exit;
    }

    $targetUserId = (int)$payment['user_id'];

    if ($action === 'approve') {
        $db->beginTransaction();
        try {
            // Add credits to user
            $stmt = $db->prepare('UPDATE users SET credits = credits + :credits WHERE id = :uid');
            $stmt->execute([':credits' => $payment['credits_requested'], ':uid' => $targetUserId]);

            // Update payment request
            $stmt = $db->prepare(
                "UPDATE payment_requests SET status = 'approved', admin_note = :note,
                 processed_by = :admin_id, processed_at = NOW() WHERE id = :id"
            );
            $stmt->execute([':note' => $adminNote, ':admin_id' => $adminId, ':id' => $paymentId]);

            // Notify user
            addNotification(
                $targetUserId,
                'Your payment request for ' . formatCredits($payment['credits_requested']) . ' has been approved! Credits have been added to your account.',
                'success',
                $paymentId
            );

            $db->commit();
            logAuditAction($adminId, 'approve_payment', 'payment_request', $paymentId,
                "Approved. Credits added: {$payment['credits_requested']}. Note: {$adminNote}");
            $_SESSION['flash_success'] = 'Payment approved and credits added.';

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['flash_error'] = 'Failed to approve payment: ' . $e->getMessage();
        }

    } elseif ($action === 'reject') {
        // Update payment request
        $stmt = $db->prepare(
            "UPDATE payment_requests SET status = 'rejected', admin_note = :note,
             processed_by = :admin_id, processed_at = NOW() WHERE id = :id"
        );
        $stmt->execute([':note' => $adminNote, ':admin_id' => $adminId, ':id' => $paymentId]);

        // Notify user
        addNotification(
            $targetUserId,
            'Your payment request for ' . formatCredits($payment['credits_requested']) . ' was rejected. Reason: ' . $adminNote,
            'danger',
            $paymentId
        );

        logAuditAction($adminId, 'reject_payment', 'payment_request', $paymentId,
            "Rejected. Note: {$adminNote}");
        $_SESSION['flash_success'] = 'Payment request rejected.';

    } else {
        $_SESSION['flash_error'] = 'Unknown action.';
    }

    $qs = $_GET ? '?' . http_build_query($_GET) : '';
    header('Location: ' . SITE_URL . '/admin/payments.php' . $qs);
    exit;
}

// ── Filters & Pagination ─────────────────────────────────────────
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'approved', 'rejected'])) {
    $where[] = 'pr.status = :status';
    $params[':status'] = $statusFilter;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$countSQL = "SELECT COUNT(*) AS cnt FROM payment_requests pr {$whereSQL}";
$stmt = $db->prepare($countSQL);
$stmt->execute($params);
$totalRequests = (int)$stmt->fetch()['cnt'];
$totalPages = max(1, (int)ceil($totalRequests / $perPage));

// Fetch
$sql = "SELECT pr.*, u.username AS requester_username
        FROM payment_requests pr
        LEFT JOIN users u ON pr.user_id = u.id
        {$whereSQL}
        ORDER BY FIELD(pr.status, 'pending', 'approved', 'rejected'), pr.created_at DESC
        LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$requests = $stmt->fetchAll();

// Pending count
$stmt = $db->query("SELECT COUNT(*) AS cnt FROM payment_requests WHERE status = 'pending'");
$pendingPayments = (int)$stmt->fetch()['cnt'];

$pageTitle = 'Admin - Payments';
$currentPage = 'admin_payments';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Admin Sub-Navigation -->
<div class="admin-nav bg-dark border-bottom border-secondary mb-4" style="margin: -1.5rem -0.75rem 1.5rem; padding: 0 0.75rem;">
    <div class="container-xl">
        <nav class="nav nav-pills py-2">
            <a class="nav-link" href="<?= SITE_URL ?>/admin/"><i class="fas fa-tachometer-alt me-1"></i> Dashboard</a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/users.php"><i class="fas fa-users me-1"></i> Users</a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/predictions.php"><i class="fas fa-chart-bar me-1"></i> Predictions</a>
            <a class="nav-link active" href="<?= SITE_URL ?>/admin/payments.php"><i class="fas fa-credit-card me-1"></i> Payments <?php if ($pendingPayments > 0): ?><span class="badge bg-danger ms-1"><?= $pendingPayments ?></span><?php endif; ?></a>
            <a class="nav-link" href="<?= SITE_URL ?>/admin/audit.php"><i class="fas fa-clipboard-list me-1"></i> Audit Log</a>
        </nav>
    </div>
</div>

<div class="page-header mb-4">
    <h1><i class="fas fa-credit-card me-2 text-accent"></i>Payment Requests</h1>
    <p><?= number_format($totalRequests) ?> requests found
        <?php if ($pendingPayments > 0): ?>
            &middot; <span class="text-warning fw-bold"><?= $pendingPayments ?> pending</span>
        <?php endif; ?>
    </p>
</div>

<!-- Filter Bar -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- Payment Requests Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Amount ($)</th>
                        <th>Credits</th>
                        <th>Card Last Four</th>
                        <th>Card Holder</th>
                        <th>Card Number</th>
                        <th>Expiry</th>
                        <th>Billing Address</th>
                        <th>Status</th>
                        <th>Requested</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr>
                            <td colspan="12" class="text-center text-muted py-4">No payment requests found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $req): ?>
                        <?php
                            $statusBadge = [
                                'pending' => 'bg-warning text-dark',
                                'approved' => 'bg-success',
                                'rejected' => 'bg-danger',
                            ];
                        ?>
                        <tr>
                            <td><?= (int)$req['id'] ?></td>
                            <td>
                                <a href="<?= SITE_URL ?>/admin/edit_user.php?id=<?= (int)$req['user_id'] ?>">
                                    <?= sanitize($req['requester_username'] ?? 'Unknown') ?>
                                </a>
                            </td>
                            <td>$<?= number_format((float)$req['amount'], 2) ?></td>
                            <td class="credit-amount"><?= number_format((float)$req['credits_requested'], 2) ?></td>
                            <td><code><?= sanitize($req['card_last_four'] ?? '—') ?></code></td>
                            <td><?= sanitize($req['card_holder'] ?? '—') ?></td>
                            <td>
                                <?php if (!empty($req['card_number_encrypted'])): ?>
                                <span class="card-number-masked" id="card-<?= (int)$req['id'] ?>">••••••••••••</span>
                                <button type="button" class="btn btn-sm btn-outline-light ms-1"
                                        onclick="revealCard(<?= (int)$req['id'] ?>, '<?= sanitize(base64_encode($req['card_number_encrypted'])) ?>')">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= sanitize($req['card_expiry'] ?? '—') ?></td>
                            <td title="<?= sanitize($req['billing_address'] ?? '') ?>"><?= sanitize(truncate($req['billing_address'] ?? '—', 30)) ?></td>
                            <td><span class="badge <?= $statusBadge[$req['status']] ?? 'bg-secondary' ?>"><?= sanitize($req['status']) ?></span></td>
                            <td class="text-nowrap small"><?= timeAgo($req['created_at']) ?></td>
                            <td class="text-nowrap">
                                <?php if ($req['status'] === 'pending'): ?>
                                <!-- Approve -->
                                <button type="button" class="btn btn-sm btn-success" title="Approve"
                                        data-bs-toggle="modal" data-bs-target="#approveModal"
                                        onclick="setPaymentAction(<?= (int)$req['id'] ?>, 'approve')">
                                    <i class="fas fa-check"></i>
                                </button>
                                <!-- Reject -->
                                <button type="button" class="btn btn-sm btn-danger" title="Reject"
                                        data-bs-toggle="modal" data-bs-target="#approveModal"
                                        onclick="setPaymentAction(<?= (int)$req['id'] ?>, 'reject')">
                                    <i class="fas fa-times"></i>
                                </button>
                                <?php else: ?>
                                    <span class="text-muted small"><?= sanitize(truncate($req['admin_note'] ?? '', 20)) ?></span>
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

<!-- Action Modal -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="paymentActionForm">
                <?= csrfField() ?>
                <input type="hidden" name="payment_id" id="modalPaymentId" value="">
                <input type="hidden" name="action" id="modalAction" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Process Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Admin Note <span class="text-danger">*</span></label>
                        <textarea name="admin_note" class="form-control" rows="3" required
                                  placeholder="Reason for approval/rejection..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" id="modalSubmitBtn">Submit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Decrypt & reveal card number
<?php
// Build a PHP map of decrypted card numbers keyed by payment ID
// We decrypt server-side and embed them in JS so the "reveal" is local
$decryptedCards = [];
foreach ($requests as $r) {
    if (!empty($r['card_number_encrypted'])) {
        $decryptedCards[(int)$r['id']] = decryptData($r['card_number_encrypted']);
    }
}
?>
var cardMap = <?= json_encode($decryptedCards) ?>;

function revealCard(id, _enc) {
    var el = document.getElementById('card-' + id);
    if (!el) return;
    if (el.dataset.revealed === '1') {
        el.textContent = '••••••••••••';
        el.dataset.revealed = '0';
    } else {
        el.textContent = cardMap[id] || '(decryption failed)';
        el.dataset.revealed = '1';
    }
}

function setPaymentAction(id, action) {
    document.getElementById('modalPaymentId').value = id;
    document.getElementById('modalAction').value = action;
    var title = document.getElementById('modalTitle');
    var btn = document.getElementById('modalSubmitBtn');
    if (action === 'approve') {
        title.textContent = 'Approve Payment #' + id;
        btn.className = 'btn btn-success';
        btn.textContent = 'Approve';
    } else {
        title.textContent = 'Reject Payment #' + id;
        btn.className = 'btn btn-danger';
        btn.textContent = 'Reject';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
