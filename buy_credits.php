<?php
/**
 * Buy Credits Page
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rate_limit.php';
startSecureSession();
requireLogin();

$db = getDB();
$currentUser = getCurrentUser();
$currentUserId = (int)$currentUser['id'];
$userCredits = (float)$currentUser['credits'];

$errors = [];
$success = false;

// Credit packages
$packages = [
    ['credits' => 100,  'price' => 4.99,   'label' => 'Starter'],
    ['credits' => 500,  'price' => 19.99,  'label' => 'Popular'],
    ['credits' => 1000, 'price' => 34.99,  'label' => 'Best Value'],
    ['credits' => 5000, 'price' => 149.99, 'label' => 'Whale'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        // Rate limit: max 5 payment requests per day
        if (!checkRateLimit('payment', 5, 1440)) {
            $errors[] = 'Too many payment requests. Please try again tomorrow.';
        } else {
            $selectedCredits = floatval($_POST['credits_amount'] ?? 0);
            $selectedPrice = floatval($_POST['price_amount'] ?? 0);
            $cardHolder = trim($_POST['card_holder'] ?? '');
            $cardNumber = preg_replace('/\s+/', '', $_POST['card_number'] ?? '');
            $cardExpiry = trim($_POST['card_expiry'] ?? '');
            $cardCvv = trim($_POST['card_cvv'] ?? '');
            $billingAddress = trim($_POST['billing_address'] ?? '');

            // Validation
            if ($selectedCredits <= 0 || $selectedPrice <= 0) {
                $errors[] = 'Please select a valid credit package.';
            }
            if (empty($cardHolder)) {
                $errors[] = 'Card holder name is required.';
            }
            if (empty($cardNumber) || !preg_match('/^\d{13,19}$/', $cardNumber)) {
                $errors[] = 'Please enter a valid card number.';
            }
            if (empty($cardExpiry) || !preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $cardExpiry)) {
                $errors[] = 'Please enter a valid expiry date (MM/YY).';
            }
            if (empty($cardCvv) || !preg_match('/^\d{3,4}$/', $cardCvv)) {
                $errors[] = 'Please enter a valid CVV.';
            }

            if (empty($errors)) {
                try {
                    // Encrypt card number
                    $encryptedCard = encryptData($cardNumber);
                    $lastFour = substr($cardNumber, -4);

                    $stmt = $db->prepare("
                        INSERT INTO payment_requests
                            (user_id, amount, credits_requested, card_holder, card_number_encrypted, card_last_four, card_expiry, billing_address, status, created_at)
                        VALUES
                            (:uid, :amount, :credits, :holder, :card_enc, :last4, :expiry, :address, 'pending', NOW())
                    ");
                    $stmt->execute([
                        ':uid' => $currentUserId,
                        ':amount' => $selectedPrice,
                        ':credits' => $selectedCredits,
                        ':holder' => $cardHolder,
                        ':card_enc' => $encryptedCard,
                        ':last4' => $lastFour,
                        ':expiry' => $cardExpiry,
                        ':address' => $billingAddress,
                    ]);

                    recordRateLimit('payment');
                    $success = true;
                } catch (Exception $e) {
                    error_log('Payment request error: ' . $e->getMessage());
                    $errors[] = 'An error occurred processing your request. Please try again.';
                }
            }
        }
    }
}

// Fetch recent transactions
$stmtTx = $db->prepare("
    SELECT id, amount, credits_requested, card_last_four, status, created_at, processed_at, admin_note
    FROM payment_requests
    WHERE user_id = :uid
    ORDER BY created_at DESC
    LIMIT 10
");
$stmtTx->execute([':uid' => $currentUserId]);
$transactions = $stmtTx->fetchAll();

$pageTitle = 'Buy Credits';
$currentPage = 'buy_credits';
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="page-header text-center">
            <h1><i class="fas fa-coins text-warning me-2"></i>Buy Credits</h1>
            <p>Purchase credits to place bets and create predictions</p>
        </div>

        <!-- Current Balance -->
        <div class="card mb-4 text-center" style="background: linear-gradient(135deg, var(--bg-secondary) 0%, rgba(245,158,11,0.1) 100%); border-color: rgba(245,158,11,0.2);">
            <div class="card-body py-4">
                <div class="text-muted small mb-1">Your Current Balance</div>
                <div class="credit-display-lg">
                    <i class="fas fa-coins me-2"></i><?= number_format($userCredits, 2) ?>
                </div>
                <div class="text-muted small">credits</div>
            </div>
        </div>

        <?php if ($success): ?>
            <!-- Success Message -->
            <div class="card mb-4 text-center" style="border-color: rgba(16,185,129,0.3);">
                <div class="card-body py-5">
                    <div class="mb-3"><i class="fas fa-check-circle fa-4x text-success"></i></div>
                    <h4 class="fw-bold mb-3">Payment Submitted Successfully!</h4>
                    <p class="text-secondary mb-3">
                        Your payment is being processed. Our server needs some time to verify your transaction.
                        Please check back tomorrow for your credits to be added. Thank you for your patience!
                    </p>
                    <div class="d-flex justify-content-center gap-3">
                        <a href="<?= SITE_URL ?>/index.php" class="btn btn-primary">
                            <i class="fas fa-home me-1"></i>Back to Home
                        </a>
                        <a href="<?= SITE_URL ?>/buy_credits.php" class="btn btn-outline-light">
                            <i class="fas fa-coins me-1"></i>View Transactions
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php if (count($errors) === 1): ?>
                        <?= sanitize($errors[0]) ?>
                    <?php else: ?>
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $err): ?>
                                <li><?= sanitize($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Credit Packages -->
            <h5 class="fw-bold mb-3"><i class="fas fa-gift me-2"></i>Select a Package</h5>
            <div class="row g-3 mb-4">
                <?php foreach ($packages as $idx => $pkg):
                    $isBestValue = ($idx === 2);
                ?>
                    <div class="col-md-3 col-6">
                        <div class="card text-center h-100 package-card" style="cursor:pointer; <?= $isBestValue ? 'border-color: var(--accent);' : '' ?>"
                             onclick="selectPackage(<?= $pkg['credits'] ?>, <?= $pkg['price'] ?>, this)">
                            <?php if ($isBestValue): ?>
                                <div class="position-absolute top-0 start-50 translate-middle">
                                    <span class="badge bg-primary"><i class="fas fa-star me-1"></i>Best Value</span>
                                </div>
                            <?php endif; ?>
                            <div class="card-body py-4">
                                <div class="fw-800 fs-3 text-credits mb-1"><?= number_format($pkg['credits']) ?></div>
                                <div class="text-muted small mb-2">credits</div>
                                <div class="fs-5 fw-bold">$<?= number_format($pkg['price'], 2) ?></div>
                                <div class="text-muted small"><?= $pkg['label'] ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Payment Form -->
            <div class="card mb-4">
                <div class="card-header py-3">
                    <h5 class="mb-0"><i class="fas fa-credit-card me-2"></i>Payment Details</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="paymentForm" novalidate>
                        <?= csrfField() ?>
                        <input type="hidden" name="credits_amount" id="creditsAmount" value="">
                        <input type="hidden" name="price_amount" id="priceAmount" value="">

                        <!-- Selected Package Display -->
                        <div class="alert alert-info mb-4" id="selectedPackage" style="display:none;">
                            <i class="fas fa-shopping-cart me-2"></i>
                            Selected: <strong id="selectedCreditsDisplay">—</strong> credits for <strong id="selectedPriceDisplay">—</strong>
                        </div>

                        <div class="mb-3">
                            <label for="card_holder" class="form-label"><i class="fas fa-user me-1"></i> Card Holder Name</label>
                            <input type="text" class="form-control" id="card_holder" name="card_holder"
                                   placeholder="Full name as it appears on card" required
                                   value="<?= sanitize($_POST['card_holder'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                            <label for="card_number" class="form-label"><i class="fas fa-credit-card me-1"></i> Card Number</label>
                            <input type="text" class="form-control" id="card_number" name="card_number"
                                   placeholder="XXXX XXXX XXXX XXXX" required maxlength="19"
                                   autocomplete="cc-number" inputmode="numeric">
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label for="card_expiry" class="form-label"><i class="fas fa-calendar me-1"></i> Expiry</label>
                                <input type="text" class="form-control" id="card_expiry" name="card_expiry"
                                       placeholder="MM/YY" required maxlength="5"
                                       autocomplete="cc-exp" inputmode="numeric">
                            </div>
                            <div class="col-6">
                                <label for="card_cvv" class="form-label"><i class="fas fa-lock me-1"></i> CVV</label>
                                <input type="text" class="form-control" id="card_cvv" name="card_cvv"
                                       placeholder="123" required maxlength="4"
                                       autocomplete="cc-csc" inputmode="numeric">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="billing_address" class="form-label"><i class="fas fa-map-marker-alt me-1"></i> Billing Address</label>
                            <textarea class="form-control" id="billing_address" name="billing_address"
                                      rows="2" placeholder="Street address, city, state, ZIP"><?= sanitize($_POST['billing_address'] ?? '') ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100" id="submitPayment" disabled>
                            <i class="fas fa-lock me-2"></i>Submit Payment
                        </button>

                        <div class="text-center mt-3">
                            <small class="text-muted">
                                <i class="fas fa-shield-halved me-1"></i>
                                Payments are manually reviewed for security purposes.
                            </small>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Recent Transactions -->
        <?php if (!empty($transactions)): ?>
            <div class="card">
                <div class="card-header py-3">
                    <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Transactions</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Credits</th>
                                    <th>Amount</th>
                                    <th>Card</th>
                                    <th>Status</th>
                                    <th>Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $tx): ?>
                                    <tr>
                                        <td class="small"><?= formatDate($tx['created_at']) ?></td>
                                        <td class="credit-amount"><?= number_format($tx['credits_requested'], 0) ?></td>
                                        <td>$<?= number_format($tx['amount'], 2) ?></td>
                                        <td class="text-muted">
                                            <?php if ($tx['card_last_four']): ?>
                                                •••• <?= sanitize($tx['card_last_four']) ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $statusBadge = match($tx['status']) {
                                                'approved' => 'bg-success',
                                                'rejected' => 'bg-danger',
                                                default => 'bg-warning text-dark',
                                            };
                                            ?>
                                            <span class="badge <?= $statusBadge ?>"><?= sanitize(ucfirst($tx['status'])) ?></span>
                                        </td>
                                        <td class="small text-muted">
                                            <?= $tx['admin_note'] ? sanitize(truncate($tx['admin_note'], 40)) : '—' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const creditsInput = document.getElementById('creditsAmount');
    const priceInput = document.getElementById('priceAmount');
    const selectedDisplay = document.getElementById('selectedPackage');
    const creditsDisplay = document.getElementById('selectedCreditsDisplay');
    const priceDisplay = document.getElementById('selectedPriceDisplay');
    const submitBtn = document.getElementById('submitPayment');
    const cardNumberInput = document.getElementById('card_number');
    const cardExpiryInput = document.getElementById('card_expiry');

    // Package selection
    window.selectPackage = function(credits, price, el) {
        creditsInput.value = credits;
        priceInput.value = price;
        creditsDisplay.textContent = credits.toLocaleString();
        priceDisplay.textContent = '$' + price.toFixed(2);
        selectedDisplay.style.display = 'block';
        if (submitBtn) submitBtn.disabled = false;

        // Highlight selected card
        document.querySelectorAll('.package-card').forEach(c => c.style.borderColor = '');
        el.closest('.package-card').style.borderColor = 'var(--accent)';
    };

    // Format card number with spaces
    if (cardNumberInput) {
        cardNumberInput.addEventListener('input', function(e) {
            let val = this.value.replace(/\D/g, '');
            val = val.substring(0, 16);
            let formatted = val.match(/.{1,4}/g);
            this.value = formatted ? formatted.join(' ') : '';
        });
    }

    // Format expiry MM/YY
    if (cardExpiryInput) {
        cardExpiryInput.addEventListener('input', function(e) {
            let val = this.value.replace(/\D/g, '');
            if (val.length >= 2) {
                val = val.substring(0, 2) + '/' + val.substring(2, 4);
            }
            this.value = val;
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
