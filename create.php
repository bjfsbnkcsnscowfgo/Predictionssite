<?php
/**
 * Create Prediction Page
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
$userCredits = (float)$currentUser['credits'];
$categories = getCategoryList();
$errors = [];
$old = [
    'title' => '',
    'description' => '',
    'probability' => 50,
    'category' => '',
    'credit_stake' => 1,
    'expires_at' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        // Rate limit: max 10 predictions per hour
        if (!checkRateLimit('create_prediction', 10, 60)) {
            $errors[] = 'You\'ve created too many predictions recently. Please wait before creating another.';
        } else {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $probability = intval($_POST['probability'] ?? 50);
            $category = trim($_POST['category'] ?? '');
            $creditStake = floatval($_POST['credit_stake'] ?? 0);
            $expiresAt = trim($_POST['expires_at'] ?? '');

            $old = compact('title', 'description', 'probability', 'category', 'credit_stake', 'expires_at');
            $old['credit_stake'] = $creditStake;
            $old['expires_at'] = $expiresAt;

            // Validation
            if (empty($title)) {
                $errors[] = 'Title is required.';
            } elseif (mb_strlen($title) > 200) {
                $errors[] = 'Title must be 200 characters or fewer.';
            }

            if (empty($description)) {
                $errors[] = 'Description is required.';
            } elseif (mb_strlen($description) > 2000) {
                $errors[] = 'Description must be 2000 characters or fewer.';
            }

            if ($probability < 1 || $probability > 99) {
                $errors[] = 'Probability must be between 1% and 99%.';
            }

            if (empty($category) || !in_array($category, $categories)) {
                $errors[] = 'Please select a valid category.';
            }

            if ($creditStake < 1) {
                $errors[] = 'Stake must be at least 1 credit.';
            }

            // Re-fetch user to get fresh credits
            $stmtU = $db->prepare("SELECT credits FROM users WHERE id = :id LIMIT 1");
            $stmtU->execute([':id' => $currentUser['id']]);
            $freshUser = $stmtU->fetch();
            $freshCredits = (float)$freshUser['credits'];

            if ($creditStake > $freshCredits) {
                $errors[] = 'You don\'t have enough credits. Your balance is ' . number_format($freshCredits, 2) . ' credits.';
            }

            if (!empty($expiresAt)) {
                $expiresTimestamp = strtotime($expiresAt);
                if ($expiresTimestamp === false || $expiresTimestamp <= time()) {
                    $errors[] = 'Expiry date must be in the future.';
                }
            }

            if (empty($errors)) {
                try {
                    $db->beginTransaction();

                    // Deduct credits from user
                    $stmt = $db->prepare("UPDATE users SET credits = credits - :stake WHERE id = :uid AND credits >= :stake2");
                    $stmt->execute([':stake' => $creditStake, ':uid' => $currentUser['id'], ':stake2' => $creditStake]);

                    if ($stmt->rowCount() === 0) {
                        $db->rollBack();
                        $errors[] = 'Insufficient credits. Please try again.';
                    } else {
                        // Create prediction
                        $expiresValue = !empty($expiresAt) ? date('Y-m-d H:i:s', strtotime($expiresAt)) : null;
                        $stmt = $db->prepare("
                            INSERT INTO predictions (user_id, title, description, probability, category, credit_stake, status, total_pool_for, total_pool_against, created_at, expires_at)
                            VALUES (:uid, :title, :desc, :prob, :cat, :stake, 'active', :pool_for, 0, NOW(), :expires)
                        ");
                        $stmt->execute([
                            ':uid' => $currentUser['id'],
                            ':title' => $title,
                            ':desc' => $description,
                            ':prob' => $probability,
                            ':cat' => $category,
                            ':stake' => $creditStake,
                            ':pool_for' => $creditStake,
                            ':expires' => $expiresValue,
                        ]);
                        $newPredId = (int)$db->lastInsertId();

                        // Create initial "for" bet for creator's stake
                        $stmt = $db->prepare("
                            INSERT INTO bets (user_id, prediction_id, amount, position, created_at)
                            VALUES (:uid, :pid, :amount, 'for', NOW())
                        ");
                        $stmt->execute([
                            ':uid' => $currentUser['id'],
                            ':pid' => $newPredId,
                            ':amount' => $creditStake,
                        ]);

                        // Increment user's total_predictions count
                        $stmt = $db->prepare("UPDATE users SET total_predictions = total_predictions + 1 WHERE id = :uid");
                        $stmt->execute([':uid' => $currentUser['id']]);

                        $db->commit();
                        recordRateLimit('create_prediction');

                        $_SESSION['flash_success'] = 'Prediction created successfully!';
                        header('Location: ' . SITE_URL . '/prediction.php?id=' . $newPredId);
                        exit;
                    }
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log('Create prediction error: ' . $e->getMessage());
                    $errors[] = 'An error occurred. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'Create Prediction';
$currentPage = 'create';
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="page-header">
            <h1><i class="fas fa-plus-circle text-accent me-2"></i>Create Prediction</h1>
            <p>Share your forecast with the community and stake credits on your conviction.</p>
        </div>

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

        <div class="card">
            <div class="card-body p-4">
                <form method="POST" action="" id="createForm" novalidate>
                    <?= csrfField() ?>

                    <!-- Title -->
                    <div class="mb-4">
                        <label for="title" class="form-label"><i class="fas fa-heading me-1"></i> Title</label>
                        <input type="text" class="form-control" id="title" name="title"
                               maxlength="200" placeholder="e.g., AI will pass the Turing test by 2028"
                               value="<?= sanitize($old['title']) ?>" required>
                        <div class="char-counter"><span id="titleCount">0</span>/200</div>
                    </div>

                    <!-- Description -->
                    <div class="mb-4">
                        <label for="description" class="form-label"><i class="fas fa-align-left me-1"></i> Description</label>
                        <textarea class="form-control" id="description" name="description" rows="5"
                                  maxlength="2000" placeholder="Describe your prediction in detail. What exactly do you predict will happen? What counts as resolution?"
                                  required><?= sanitize($old['description']) ?></textarea>
                        <div class="char-counter"><span id="descCount">0</span>/2000</div>
                    </div>

                    <!-- Probability -->
                    <div class="mb-4">
                        <label for="probability" class="form-label">
                            <i class="fas fa-chart-pie me-1"></i> Probability Estimate:
                            <span class="badge bg-primary" id="probDisplay"><?= (int)$old['probability'] ?>%</span>
                        </label>
                        <input type="range" class="form-range" id="probability" name="probability"
                               min="1" max="99" value="<?= (int)$old['probability'] ?>">
                        <div class="d-flex justify-content-between text-muted small">
                            <span>1% Very Unlikely</span>
                            <span>50% Coin Flip</span>
                            <span>99% Very Likely</span>
                        </div>
                    </div>

                    <!-- Category -->
                    <div class="mb-4">
                        <label for="category" class="form-label"><i class="fas fa-folder me-1"></i> Category</label>
                        <select class="form-select" id="category" name="category" required>
                            <option value="">Select a category...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= sanitize($cat) ?>" <?= $old['category'] === $cat ? 'selected' : '' ?>>
                                    <?= sanitize($cat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Credit Stake -->
                    <div class="mb-4">
                        <label for="credit_stake" class="form-label">
                            <i class="fas fa-coins me-1"></i> Credit Stake
                        </label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="credit_stake" name="credit_stake"
                                   min="1" max="<?= floor($userCredits) ?>" step="1"
                                   value="<?= max(1, (int)$old['credit_stake']) ?>" required>
                            <span class="input-group-text">credits</span>
                        </div>
                        <div class="form-text">
                            Your balance: <strong class="text-credits"><?= number_format($userCredits, 2) ?> credits</strong>.
                            This amount will be your initial "For" bet.
                        </div>
                    </div>

                    <!-- Expiry Date -->
                    <div class="mb-4">
                        <label for="expires_at" class="form-label">
                            <i class="fas fa-calendar-alt me-1"></i> Expiry Date <span class="text-muted">(optional)</span>
                        </label>
                        <input type="datetime-local" class="form-control" id="expires_at" name="expires_at"
                               min="<?= date('Y-m-d\TH:i', time() + 3600) ?>"
                               value="<?= sanitize($old['expires_at']) ?>">
                        <div class="form-text">Leave blank for no expiry.</div>
                    </div>

                    <!-- Preview Section -->
                    <div class="mb-4">
                        <h6 class="fw-bold mb-3"><i class="fas fa-eye me-2"></i>Preview</h6>
                        <div class="card prediction-card" id="previewCard" style="pointer-events:none;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="badge" id="previewCategory">Category</span>
                                    <span class="badge bg-success">Active</span>
                                </div>
                                <h5 class="prediction-title" id="previewTitle">Your prediction title...</h5>
                                <p class="prediction-description" id="previewDesc">Your description will appear here...</p>
                                <div class="probability-bar-wrapper">
                                    <div class="probability-label">
                                        <span>Probability</span>
                                        <span class="probability-value" id="previewProb">50%</span>
                                    </div>
                                    <div class="probability-bar">
                                        <div class="probability-bar-fill" id="previewBar" style="width:50%"></div>
                                    </div>
                                </div>
                                <div class="prediction-stats mt-3">
                                    <div class="stat-item">
                                        <div class="stat-value credit-amount" id="previewStake">1</div>
                                        <div class="stat-label">Stake</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value" id="previewPool">1</div>
                                        <div class="stat-label">Total Pool</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value">1</div>
                                        <div class="stat-label">Bets</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="fas fa-rocket me-2"></i>Publish Prediction
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const titleEl = document.getElementById('title');
    const descEl = document.getElementById('description');
    const probEl = document.getElementById('probability');
    const catEl = document.getElementById('category');
    const stakeEl = document.getElementById('credit_stake');

    const titleCount = document.getElementById('titleCount');
    const descCount = document.getElementById('descCount');
    const probDisplay = document.getElementById('probDisplay');

    // Preview elements
    const prevTitle = document.getElementById('previewTitle');
    const prevDesc = document.getElementById('previewDesc');
    const prevProb = document.getElementById('previewProb');
    const prevBar = document.getElementById('previewBar');
    const prevCat = document.getElementById('previewCategory');
    const prevStake = document.getElementById('previewStake');
    const prevPool = document.getElementById('previewPool');

    const catColors = {
        'Technology':'badge-technology','Politics':'badge-politics','Sports':'badge-sports',
        'Entertainment':'badge-entertainment','Finance':'badge-finance','Science':'badge-science',
        'World Events':'badge-world-events','Health':'badge-health','Other':'badge-other'
    };

    function updatePreview() {
        prevTitle.textContent = titleEl.value || 'Your prediction title...';
        prevDesc.textContent = descEl.value ? descEl.value.substring(0, 100) + (descEl.value.length > 100 ? '...' : '') : 'Your description will appear here...';

        const p = probEl.value;
        probDisplay.textContent = p + '%';
        prevProb.textContent = p + '%';
        prevBar.style.width = p + '%';

        const cat = catEl.value;
        prevCat.textContent = cat || 'Category';
        prevCat.className = 'badge ' + (catColors[cat] || 'bg-secondary');

        const stake = stakeEl.value || 1;
        prevStake.textContent = stake;
        prevPool.textContent = stake;

        titleCount.textContent = titleEl.value.length;
        if (titleEl.value.length > 200) titleCount.parentElement.classList.add('over-limit');
        else titleCount.parentElement.classList.remove('over-limit');

        descCount.textContent = descEl.value.length;
        if (descEl.value.length > 2000) descCount.parentElement.classList.add('over-limit');
        else descCount.parentElement.classList.remove('over-limit');
    }

    titleEl.addEventListener('input', updatePreview);
    descEl.addEventListener('input', updatePreview);
    probEl.addEventListener('input', updatePreview);
    catEl.addEventListener('change', updatePreview);
    stakeEl.addEventListener('input', updatePreview);
    updatePreview();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
