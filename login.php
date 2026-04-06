<?php
/**
 * Login Page
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rate_limit.php';
startSecureSession();

// Already logged in? Redirect to home
if (isLoggedIn()) {
    header('Location: ' . SITE_URL . '/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        // Rate limiting: max 5 attempts per 15 minutes
        if (!checkRateLimit('login', 5, 15)) {
            $error = 'Too many login attempts. Please wait 15 minutes before trying again.';
        } else {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $remember = isset($_POST['remember']);

            if (empty($username) || empty($password)) {
                $error = 'Please enter both username and password.';
                recordRateLimit('login');
            } else {
                $result = loginUser($username, $password);
                recordRateLimit('login');

                if ($result['success']) {
                    // Handle "Remember Me" - extend session cookie lifetime
                    if ($remember) {
                        $params = session_get_cookie_params();
                        setcookie(
                            session_name(),
                            session_id(),
                            time() + (30 * 24 * 60 * 60), // 30 days
                            $params['path'],
                            $params['domain'],
                            $params['secure'],
                            $params['httponly']
                        );
                    }

                    $_SESSION['flash_success'] = 'Welcome back, ' . $username . '!';
                    header('Location: ' . SITE_URL . '/index.php');
                    exit;
                } else {
                    $error = $result['error'];
                }
            }
        }
    }
}

$pageTitle = 'Login';
$currentPage = 'login';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <!-- Logo / Header -->
        <div class="text-center mb-3">
            <i class="fas fa-chart-line fa-2x text-accent mb-2"></i>
        </div>
        <h2><i class="fas fa-sign-in-alt me-2"></i>Welcome Back</h2>
        <p class="auth-subtitle">Sign in to your account to continue</p>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?= sanitize($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" novalidate>
            <?= csrfField() ?>

            <div class="mb-3">
                <label for="username" class="form-label">
                    <i class="fas fa-user me-1"></i> Username
                </label>
                <input type="text" class="form-control" id="username" name="username"
                       placeholder="Enter your username"
                       value="<?= sanitize($_POST['username'] ?? '') ?>"
                       required autofocus>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">
                    <i class="fas fa-lock me-1"></i> Password
                </label>
                <input type="password" class="form-control" id="password" name="password"
                       placeholder="Enter your password" required>
            </div>

            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
                    <label class="form-check-label" for="remember">
                        <i class="fas fa-clock me-1"></i> Remember me for 30 days
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 btn-lg mb-3">
                <i class="fas fa-sign-in-alt me-2"></i>Sign In
            </button>
        </form>

        <div class="auth-footer">
            Don't have an account?
            <a href="<?= SITE_URL ?>/register.php"><i class="fas fa-user-plus me-1"></i>Create one now</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
