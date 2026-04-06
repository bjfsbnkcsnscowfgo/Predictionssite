<?php
/**
 * Admin - Resolve Prediction (POST-only endpoint)
 * Resolves a prediction as yes/no/cancel, distributes payouts, and redirects back.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
startSecureSession();
requireAdmin();

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . SITE_URL . '/admin/predictions.php');
    exit;
}

// Validate CSRF
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = 'Invalid or expired CSRF token.';
    header('Location: ' . SITE_URL . '/admin/predictions.php');
    exit;
}

$predictionId = (int)($_POST['prediction_id'] ?? 0);
$outcome = $_POST['outcome'] ?? '';
$note = trim($_POST['note'] ?? '');

// Validate inputs
if ($predictionId <= 0) {
    $_SESSION['flash_error'] = 'Invalid prediction ID.';
    header('Location: ' . SITE_URL . '/admin/predictions.php');
    exit;
}

if (!in_array($outcome, ['yes', 'no', 'cancel'])) {
    $_SESSION['flash_error'] = 'Invalid outcome. Must be yes, no, or cancel.';
    header('Location: ' . SITE_URL . '/admin/edit_prediction.php?id=' . $predictionId);
    exit;
}

if ($note === '') {
    $_SESSION['flash_error'] = 'A resolution note is required.';
    header('Location: ' . SITE_URL . '/admin/edit_prediction.php?id=' . $predictionId);
    exit;
}

$adminUser = getCurrentUser();
$adminId = (int)$adminUser['id'];

// Resolve the prediction
$result = resolvePrediction($predictionId, $outcome, $adminId, $note);

if ($result) {
    $outcomeLabel = $outcome === 'yes' ? 'YES' : ($outcome === 'no' ? 'NO' : 'CANCELLED');
    $_SESSION['flash_success'] = "Prediction resolved as {$outcomeLabel} successfully.";
} else {
    $_SESSION['flash_error'] = 'Failed to resolve prediction. It may already be resolved or not exist.';
}

header('Location: ' . SITE_URL . '/admin/edit_prediction.php?id=' . $predictionId);
exit;
