<?php
/**
 * API - Place a Bet
 * POST endpoint. Accepts JSON or form data.
 * Returns JSON response.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';
startSecureSession();

header('Content-Type: application/json; charset=utf-8');

// ── Must be POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Must be logged in ────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You must be logged in to place a bet.']);
    exit;
}

// ── Validate CSRF ────────────────────────────────────────────────
$csrfToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!validateCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
    exit;
}

// ── Rate Limit: 30 bets per hour ─────────────────────────────────
if (!checkRateLimit('bet', 30, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Rate limit exceeded. You can place up to 30 bets per hour.']);
    exit;
}

$db = getDB();
$user = getCurrentUser();
$userId = (int)$user['id'];

// ── Check if user is banned ──────────────────────────────────────
if ((int)$user['is_banned'] === 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your account has been banned.']);
    exit;
}

// ── Parse inputs ─────────────────────────────────────────────────
$predictionId = (int)($_POST['prediction_id'] ?? 0);
$amount = (float)($_POST['amount'] ?? 0);
$position = $_POST['position'] ?? '';

// ── Validate inputs ──────────────────────────────────────────────
if ($predictionId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid prediction ID.']);
    exit;
}

if (!in_array($position, ['for', 'against'])) {
    echo json_encode(['success' => false, 'error' => 'Position must be "for" or "against".']);
    exit;
}

if ($amount < 1) {
    echo json_encode(['success' => false, 'error' => 'Minimum bet is 1 credit.']);
    exit;
}

if ($amount > (float)$user['credits']) {
    echo json_encode(['success' => false, 'error' => 'Insufficient credits. You have ' . number_format((float)$user['credits'], 2) . ' credits.']);
    exit;
}

// ── Fetch prediction ─────────────────────────────────────────────
$stmt = $db->prepare('SELECT * FROM predictions WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $predictionId]);
$prediction = $stmt->fetch();

if (!$prediction) {
    echo json_encode(['success' => false, 'error' => 'Prediction not found.']);
    exit;
}

if ($prediction['status'] !== 'active') {
    echo json_encode(['success' => false, 'error' => 'This prediction is not accepting bets (status: ' . $prediction['status'] . ').']);
    exit;
}

// ── Check: user hasn't already bet on this prediction ────────────
$stmt = $db->prepare('SELECT id FROM bets WHERE user_id = :uid AND prediction_id = :pid LIMIT 1');
$stmt->execute([':uid' => $userId, ':pid' => $predictionId]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'You have already placed a bet on this prediction.']);
    exit;
}

// ── Check: creator can't bet against their own prediction ────────
if ((int)$prediction['user_id'] === $userId && $position === 'against') {
    echo json_encode(['success' => false, 'error' => 'You cannot bet against your own prediction. Your stake is already a "for" bet.']);
    exit;
}

// ── Check for suspicious behavior (flag only, don't block) ──────
$stmt = $db->prepare(
    'SELECT COUNT(*) AS cnt FROM bets WHERE user_id = :uid AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)'
);
$stmt->execute([':uid' => $userId]);
$recentBets = (int)$stmt->fetch()['cnt'];
$isSuspicious = $recentBets > 20;

// ── Process bet atomically ───────────────────────────────────────
$db->beginTransaction();
try {
    // Deduct credits from user
    $stmt = $db->prepare('UPDATE users SET credits = credits - :amount WHERE id = :uid AND credits >= :amount2');
    $stmt->execute([':amount' => $amount, ':uid' => $userId, ':amount2' => $amount]);

    if ($stmt->rowCount() === 0) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Insufficient credits (concurrent transaction).']);
        exit;
    }

    // Insert bet record
    $stmt = $db->prepare(
        'INSERT INTO bets (user_id, prediction_id, amount, position, created_at)
         VALUES (:uid, :pid, :amount, :position, NOW())'
    );
    $stmt->execute([
        ':uid' => $userId,
        ':pid' => $predictionId,
        ':amount' => $amount,
        ':position' => $position,
    ]);

    // Update prediction pool
    $poolColumn = $position === 'for' ? 'total_pool_for' : 'total_pool_against';
    $stmt = $db->prepare(
        "UPDATE predictions SET {$poolColumn} = {$poolColumn} + :amount WHERE id = :pid"
    );
    $stmt->execute([':amount' => $amount, ':pid' => $predictionId]);

    // Notify prediction creator (only if the bettor is not the creator)
    if ((int)$prediction['user_id'] !== $userId) {
        $posLabel = $position === 'for' ? 'for' : 'against';
        addNotification(
            (int)$prediction['user_id'],
            sanitize($user['username']) . ' bet ' . formatCredits($amount) . ' ' . $posLabel . ' your prediction "' . truncate($prediction['title'], 50) . '".',
            'info',
            $predictionId
        );
    }

    $db->commit();

    // Record rate limit attempt
    recordRateLimit('bet');

    // Log suspicious activity
    if ($isSuspicious) {
        // Log to error log for admin review
        error_log("SUSPICIOUS: User #{$userId} ({$user['username']}) placed {$recentBets}+ bets in last hour. IP: " . getClientIP());
    }

    // Fetch updated balance
    $stmt = $db->prepare('SELECT credits FROM users WHERE id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $newBalance = (float)$stmt->fetch()['credits'];

    echo json_encode([
        'success' => true,
        'message' => 'Bet placed successfully!',
        'newBalance' => $newBalance,
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Bet API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An error occurred. Please try again.']);
}
