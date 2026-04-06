<?php
/**
 * Registration Page
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

$errors = [];
$old = ['username' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        // Honeypot anti-spam check
        if (!empty($_POST['website_url'])) {
            // Bot detected — silently pretend success
            $_SESSION['flash_success'] = 'Registration successful! Welcome aboard.';
            header('Location: ' . SITE_URL . '/index.php');
            exit;
        }

        // Rate limiting: max 3 registrations per hour per IP
        if (!checkRateLimit('register', 3, 60)) {
            $errors[] = 'Too many registration attempts. Please try again later.';
        } else {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            $old['username'] = $username;
            $old['email'] = $email;

            // Client-side-like validation on server
            if (empty($username)) {
                $errors[] = 'Username is required.';
            }
            if (empty($email)) {
                $errors[] = 'Email is required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }
            if (empty($password)) {
                $errors[] = 'Password is required.';
            } elseif (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters long.';
            }
            if ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match.';
            }

            if (empty($errors)) {
                $ip = getClientIP();
                $deviceInfo = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

                $result = registerUser($username, $email, $password, $ip, $deviceInfo);
                recordRateLimit('register');

                if ($result['success']) {
                    // Auto-login after registration
                    $loginResult = loginUser($username, $password);
                    if ($loginResult['success']) {
                        $_SESSION['flash_success'] = 'Welcome to ' . SITE_NAME . '! You\'ve received 50 free credits to get started.';
                        header('Location: ' . SITE_URL . '/index.php');
                        exit;
                    } else {
                        $_SESSION['flash_success'] = 'Registration successful! Please log in.';
                        header('Location: ' . SITE_URL . '/login.php');
                        exit;
                    }
                } else {
                    $errors[] = $result['error'];
                }
            }
        }
    }
}

$pageTitle = 'Register';
$currentPage = 'register';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <!-- Logo / Header -->
        <div class="text-center mb-3">
            <i class="fas fa-chart-line fa-2x text-accent mb-2"></i>
        </div>
        <h2><i class="fas fa-user-plus me-2"></i>Create Account</h2>
        <p class="auth-subtitle">Join the prediction community and start forecasting</p>

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

        <form method="POST" action="" novalidate id="registerForm">
            <?= csrfField() ?>

            <!-- Honeypot field — hidden from humans -->
            <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">
                <label for="website_url">Leave this empty</label>
                <input type="text" name="website_url" id="website_url" value="" tabindex="-1" autocomplete="off">
            </div>

            <div class="mb-3">
                <label for="username" class="form-label">
                    <i class="fas fa-user me-1"></i> Username
                </label>
                <input type="text" class="form-control" id="username" name="username"
                       placeholder="Choose a unique username (3-30 chars)"
                       value="<?= sanitize($old['username']) ?>"
                       required autofocus minlength="3" maxlength="30"
                       pattern="[a-zA-Z0-9_]+">
                <div class="form-text">Letters, numbers, and underscores only (3-30 characters).</div>
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">
                    <i class="fas fa-envelope me-1"></i> Email Address
                </label>
                <input type="email" class="form-control" id="email" name="email"
                       placeholder="you@example.com"
                       value="<?= sanitize($old['email']) ?>"
                       required>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">
                    <i class="fas fa-lock me-1"></i> Password
                </label>
                <input type="password" class="form-control" id="password" name="password"
                       placeholder="Minimum 8 characters" required minlength="8">
                <div class="form-text" id="passwordStrength"></div>
            </div>

            <div class="mb-4">
                <label for="confirm_password" class="form-label">
                    <i class="fas fa-lock me-1"></i> Confirm Password
                </label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                       placeholder="Re-enter your password" required>
                <div class="form-text" id="passwordMatch"></div>
            </div>

            <button type="submit" class="btn btn-primary w-100 btn-lg mb-3">
                <i class="fas fa-user-plus me-2"></i>Create Account
            </button>

            <div class="text-center mb-2">
                <small class="text-secondary">
                    <i class="fas fa-gift me-1"></i> You'll receive <strong class="text-credits">50 free credits</strong> to get started!
                </small>
            </div>
        </form>

        <div class="auth-footer">
            Already have an account?
            <a href="<?= SITE_URL ?>/login.php"><i class="fas fa-sign-in-alt me-1"></i>Sign in</a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const strengthEl = document.getElementById('passwordStrength');
    const matchEl = document.getElementById('passwordMatch');

    password.addEventListener('input', function() {
        const val = this.value;
        if (val.length === 0) {
            strengthEl.textContent = '';
            return;
        }
        if (val.length < 8) {
            strengthEl.innerHTML = '<span style="color:var(--danger)"><i class="fas fa-times-circle me-1"></i>Too short — minimum 8 characters</span>';
        } else if (val.length < 12) {
            strengthEl.innerHTML = '<span style="color:var(--warning)"><i class="fas fa-exclamation-circle me-1"></i>Fair — consider a longer password</span>';
        } else {
            strengthEl.innerHTML = '<span style="color:var(--success)"><i class="fas fa-check-circle me-1"></i>Strong password</span>';
        }
        checkMatch();
    });

    confirmPassword.addEventListener('input', checkMatch);

    function checkMatch() {
        if (confirmPassword.value.length === 0) {
            matchEl.textContent = '';
            return;
        }
        if (password.value === confirmPassword.value) {
            matchEl.innerHTML = '<span style="color:var(--success)"><i class="fas fa-check-circle me-1"></i>Passwords match</span>';
        } else {
            matchEl.innerHTML = '<span style="color:var(--danger)"><i class="fas fa-times-circle me-1"></i>Passwords do not match</span>';
        }
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
