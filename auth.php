<?php
/**
 * Authentication & Authorization
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rate_limit.php';

/**
 * Start a secure session with hardened configuration.
 */
function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $lifetime = 0; // Session cookie (expires when browser closes)

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly'  => true,
        'samesite' => 'Strict',
    ]);

    session_name('PRED_SESSID');
    session_start();

    // Regenerate session ID every 30 minutes to prevent fixation
    if (!isset($_SESSION['_last_regenerated'])) {
        $_SESSION['_last_regenerated'] = time();
    } elseif (time() - $_SESSION['_last_regenerated'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_last_regenerated'] = time();
    }
}

/**
 * Check if a user is currently logged in.
 *
 * @return bool
 */
function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

/**
 * Check if the current user is an admin.
 * Caches the result in a static variable for the duration of the request.
 *
 * @return bool
 */
function isAdmin(): bool
{
    static $isAdminCached = null;

    if (!isLoggedIn()) {
        return false;
    }

    if ($isAdminCached !== null) {
        return $isAdminCached;
    }

    // First check session flag
    if (isset($_SESSION['is_admin'])) {
        $isAdminCached = (bool)$_SESSION['is_admin'];
        return $isAdminCached;
    }

    // Fall back to DB lookup
    $user = getCurrentUser();
    $isAdminCached = $user !== null && (int)$user['is_admin'] === 1;
    $_SESSION['is_admin'] = $isAdminCached ? 1 : 0;

    return $isAdminCached;
}

/**
 * Get the full user row for the currently logged-in user.
 * Caches the result in a static variable for the duration of the request.
 *
 * @return array|null User row or null if not logged in.
 */
function getCurrentUser(): ?array
{
    static $currentUser = null;
    static $fetched = false;

    if ($fetched) {
        return $currentUser;
    }

    if (!isLoggedIn()) {
        $fetched = true;
        return null;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $currentUser = $stmt->fetch() ?: null;
    $fetched = true;

    return $currentUser;
}

/**
 * Get the current user's ID from the session.
 *
 * @return int|null
 */
function getCurrentUserId(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Require the user to be logged in; redirect to login page otherwise.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

/**
 * Require the user to be an admin; redirect otherwise.
 */
function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . '/index.php');
        exit;
    }
}

/**
 * Attempt to log in a user with username and password.
 *
 * @param string $username
 * @param string $password
 * @return array ['success' => bool, 'error' => string]
 */
function loginUser(string $username, string $password): array
{
    $db = getDB();

    $stmt = $db->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'error' => 'Invalid username or password.'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'error' => 'Invalid username or password.'];
    }

    if ((int)$user['is_banned'] === 1) {
        $reason = !empty($user['ban_reason']) ? $user['ban_reason'] : 'No reason provided.';
        return ['success' => false, 'error' => 'Your account has been banned. Reason: ' . $reason];
    }

    // Set session variables
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['is_admin'] = (int)$user['is_admin'];

    // Update last_login and IP
    $ip = getClientIP();
    $stmt = $db->prepare(
        'UPDATE users SET last_login = NOW(), ip_address = :ip WHERE id = :id'
    );
    $stmt->execute([':ip' => $ip, ':id' => $user['id']]);

    // Regenerate session ID on login
    session_regenerate_id(true);
    $_SESSION['_last_regenerated'] = time();

    return ['success' => true, 'error' => ''];
}

/**
 * Register a new user account.
 *
 * @param string $username
 * @param string $email
 * @param string $password
 * @param string $ip       Client IP address.
 * @param string $deviceInfo Device/user-agent info.
 * @return array ['success' => bool, 'error' => string]
 */
function registerUser(string $username, string $email, string $password, string $ip, string $deviceInfo): array
{
    $db = getDB();

    // Validate username: 3-30 chars, alphanumeric + underscore only
    if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        return ['success' => false, 'error' => 'Username must be 3-30 characters and contain only letters, numbers, and underscores.'];
    }

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid email address.'];
    }

    // Validate password length
    if (strlen($password) < 6) {
        return ['success' => false, 'error' => 'Password must be at least 6 characters long.'];
    }

    // [OPTIMIZED] Combined username + email uniqueness check into 1 query
    $stmt = $db->prepare('SELECT username, email FROM users WHERE username = :username OR email = :email LIMIT 1');
    $stmt->execute([':username' => $username, ':email' => $email]);
    $existing = $stmt->fetch();
    if ($existing) {
        if ($existing['username'] === $username) {
            return ['success' => false, 'error' => 'Username is already taken.'];
        }
        return ['success' => false, 'error' => 'Email address is already registered.'];
    }

    // Check IP account limit (max 2 per IP)
    $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM ip_accounts WHERE ip_address = :ip');
    $stmt->execute([':ip' => $ip]);
    $row = $stmt->fetch();
    if ((int)$row['cnt'] >= 2) {
        return ['success' => false, 'error' => 'Maximum number of accounts from this network has been reached.'];
    }

    // Hash password
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    // Get country from IP
    $country = 'Unknown';
    if (function_exists('getCountryFromIP')) {
        $country = getCountryFromIP($ip);
    }

    // Insert user with 50 starting credits
    $stmt = $db->prepare(
        'INSERT INTO users (username, email, password_hash, credits, ip_address, country, device_info, created_at, last_login)
         VALUES (:username, :email, :password_hash, 50.00, :ip, :country, :device_info, NOW(), NOW())'
    );
    $stmt->execute([
        ':username'      => $username,
        ':email'         => $email,
        ':password_hash' => $passwordHash,
        ':ip'            => $ip,
        ':country'       => $country,
        ':device_info'   => $deviceInfo,
    ]);

    $userId = (int)$db->lastInsertId();

    // Record IP-account association
    $stmt = $db->prepare(
        'INSERT INTO ip_accounts (ip_address, user_id, created_at) VALUES (:ip, :user_id, NOW())'
    );
    $stmt->execute([':ip' => $ip, ':user_id' => $userId]);

    return ['success' => true, 'error' => ''];
}

/**
 * Log out the current user, destroy session, and redirect to login.
 */
function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}
