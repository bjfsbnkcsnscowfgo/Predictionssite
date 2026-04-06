<?php
/**
 * Predictions Platform — Installer
 *
 * Creates all database tables, indexes, and the default admin account.
 * DELETE THIS FILE after a successful installation.
 */

require_once __DIR__ . '/config.php';

$messages = [];
$errors = [];
$alreadyInstalled = false;
$setupRun = false;

// Check if setup should run (POST only for safety)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $setupRun = true;

    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // ── Create Tables ───────────────────────────────────────────

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                credits DECIMAL(12,2) DEFAULT 50.00,
                bio TEXT DEFAULT NULL,
                avatar_url VARCHAR(500) DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                country VARCHAR(100) DEFAULT NULL,
                device_info TEXT DEFAULT NULL,
                is_admin TINYINT(1) DEFAULT 0,
                is_banned TINYINT(1) DEFAULT 0,
                is_shadow_banned TINYINT(1) DEFAULT 0,
                ban_reason TEXT DEFAULT NULL,
                total_predictions INT DEFAULT 0,
                correct_predictions INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_login DATETIME DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = 'Table <code>users</code> created.';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS predictions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                title VARCHAR(200) NOT NULL,
                description TEXT NOT NULL,
                probability INT DEFAULT 50,
                category VARCHAR(50) NOT NULL,
                credit_stake DECIMAL(12,2) NOT NULL,
                status ENUM('active','resolved_yes','resolved_no','locked','cancelled') DEFAULT 'active',
                resolution_note TEXT DEFAULT NULL,
                resolved_by INT DEFAULT NULL,
                total_pool_for DECIMAL(12,2) DEFAULT 0,
                total_pool_against DECIMAL(12,2) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME DEFAULT NULL,
                expires_at DATETIME DEFAULT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (resolved_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = 'Table <code>predictions</code> created.';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS bets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                prediction_id INT NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                position ENUM('for','against') NOT NULL,
                payout DECIMAL(12,2) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (prediction_id) REFERENCES predictions(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = 'Table <code>bets</code> created.';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                message TEXT NOT NULL,
                type VARCHAR(50) DEFAULT 'info',
                related_id INT DEFAULT NULL,
                is_read TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = 'Table <code>notifications</code> created.';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_id INT NOT NULL,
                action VARCHAR(100) NOT NULL,
                target_type VARCHAR(50) DEFAULT NULL,
                target_id INT DEFAULT NULL,
                details TEXT DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (admin_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = 'Table <code>audit_logs</code> created.';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                action_type VARCHAR(50) NOT NULL,
                attempts INT DEFAULT 1,
                window_start DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = 'Table <code>rate_limits</code> created.';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS payment_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                credits_requested DECIMAL(12,2) NOT NULL,
                card_holder VARCHAR(200) DEFAULT NULL,
                card_number_encrypted TEXT DEFAULT NULL,
                card_last_four VARCHAR(4) DEFAULT NULL,
                card_expiry VARCHAR(7) DEFAULT NULL,
                billing_address TEXT DEFAULT NULL,
                status ENUM('pending','approved','rejected') DEFAULT 'pending',
                admin_note TEXT DEFAULT NULL,
                processed_by INT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME DEFAULT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (processed_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = 'Table <code>payment_requests</code> created.';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ip_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                user_id INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = 'Table <code>ip_accounts</code> created.';

        // ── Indexes ─────────────────────────────────────────────────
        // [OPTIMIZED] Added critical indexes for InfinityFree performance

        // Helper to add index only if it doesn't exist
        $addIndex = function(string $table, string $indexName, string $columns) use ($pdo) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND INDEX_NAME = :idx"
            );
            $stmt->execute([':db' => DB_NAME, ':tbl' => $table, ':idx' => $indexName]);
            $row = $stmt->fetch();
            if ((int)$row['cnt'] === 0) {
                $pdo->exec("CREATE INDEX {$indexName} ON {$table} ({$columns})");
            }
        };

        // predictions table
        $addIndex('predictions', 'idx_predictions_status', 'status');
        $addIndex('predictions', 'idx_predictions_user_id', 'user_id');
        $addIndex('predictions', 'idx_predictions_category', 'category');
        $addIndex('predictions', 'idx_predictions_created_at', 'created_at');
        $addIndex('predictions', 'idx_predictions_expires_at', 'expires_at');

        // bets table
        $addIndex('bets', 'idx_bets_user_id', 'user_id');
        $addIndex('bets', 'idx_bets_prediction_id', 'prediction_id');

        // notifications table
        $addIndex('notifications', 'idx_notifications_user_read', 'user_id, is_read');

        // rate_limits table
        $addIndex('rate_limits', 'idx_rate_limits_ip_action', 'ip_address, action_type');
        $addIndex('rate_limits', 'idx_rate_limits_lookup', 'ip_address, action_type, window_start');

        // audit_logs table
        $addIndex('audit_logs', 'idx_audit_logs_admin_id', 'admin_id');
        $addIndex('audit_logs', 'idx_audit_logs_created_at', 'created_at');
        $addIndex('audit_logs', 'idx_audit_logs_action', 'action');

        // ip_accounts table
        $addIndex('ip_accounts', 'idx_ip_accounts_ip', 'ip_address');

        // payment_requests table
        $addIndex('payment_requests', 'idx_payment_requests_user_id', 'user_id');
        $addIndex('payment_requests', 'idx_payment_requests_status', 'status');

        // users table (username and email already covered by UNIQUE constraints)
        $addIndex('users', 'idx_users_is_banned', 'is_banned');

        $messages[] = 'Indexes created.';

        // ── Admin Account ───────────────────────────────────────────

        $adminPassword = password_hash('johnmccena', PASSWORD_BCRYPT);

        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => 'Dev1']);
        $existingAdmin = $stmt->fetch();

        if (!$existingAdmin) {
            $stmt = $pdo->prepare(
                "INSERT INTO users (username, email, password_hash, credits, is_admin, ip_address, country, created_at, last_login)
                 VALUES ('Dev1', 'dev@predictions.local', :pw, 999999.00, 1, '0.0.0.0', 'System', NOW(), NOW())"
            );
            $stmt->execute([':pw' => $adminPassword]);
            $adminId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO ip_accounts (ip_address, user_id, created_at) VALUES ('0.0.0.0', :uid, NOW())"
            );
            $stmt->execute([':uid' => $adminId]);

            $messages[] = 'Admin account <strong>Dev1</strong> created.';
        } else {
            $messages[] = 'Admin account <strong>Dev1</strong> already exists — skipped.';
        }

        $messages[] = '<strong>Installation completed successfully!</strong>';

    } catch (PDOException $e) {
        $errors[] = 'Database error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    } catch (Exception $e) {
        $errors[] = 'Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }

} else {
    // GET request — check if already installed
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $tables = ['users', 'predictions', 'bets', 'notifications', 'audit_logs', 'rate_limits', 'payment_requests', 'ip_accounts'];
        $allExist = true;
        foreach ($tables as $t) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl"
            );
            $stmt->execute([':db' => DB_NAME, ':tbl' => $t]);
            $row = $stmt->fetch();
            if ((int)$row['cnt'] === 0) {
                $allExist = false;
                break;
            }
        }

        if ($allExist) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u AND is_admin = 1 LIMIT 1');
            $stmt->execute([':u' => 'Dev1']);
            if ($stmt->fetch()) {
                $alreadyInstalled = true;
            }
        }
    } catch (Exception $e) {
        // Can't connect — that's fine, user will see the setup form
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install | Predictions Platform</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YcnS/1WR6zNn36Dg6013oZu47rE6YDstIpKb" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
          crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg-primary: #0f172a; --bg-secondary: #1e293b; --accent: #6366f1; --text-primary: #f1f5f9; }
        body { background: var(--bg-primary); color: var(--text-primary); font-family: 'Inter', sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .install-card { background: var(--bg-secondary); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; max-width: 640px; width: 100%; padding: 2.5rem; }
        .install-card h1 { font-weight: 700; }
        .step-msg { padding: 0.4rem 0; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.95rem; }
        .step-msg:last-child { border: none; }
    </style>
</head>
<body>
    <div class="install-card mx-3">
        <div class="text-center mb-4">
            <i class="fas fa-chart-line fa-3x mb-3" style="color: var(--accent);"></i>
            <h1>Predictions Installer</h1>
            <p class="text-secondary">Set up your database tables and admin account.</p>
        </div>

        <?php if ($alreadyInstalled && !$setupRun): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Already installed.</strong> All tables exist and the admin account is present. If you need to reinstall, drop the tables first.
            </div>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Security:</strong> Delete this file (<code>install.php</code>) from your server now.
            </div>
            <a href="index.php" class="btn btn-primary w-100 mt-2">
                <i class="fas fa-home me-2"></i> Go to Homepage
            </a>

        <?php elseif ($setupRun): ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-times-circle me-2"></i><strong>Errors occurred:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $err): ?>
                            <li><?= $err ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($messages)): ?>
                <div class="mb-3">
                    <?php foreach ($messages as $msg): ?>
                        <div class="step-msg">
                            <i class="fas fa-check text-success me-2"></i> <?= $msg ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($errors)): ?>
                <div class="alert alert-success mt-3">
                    <i class="fas fa-check-circle me-2"></i>
                    Installation complete! You can log in with:<br>
                    <strong>Username:</strong> Dev1 &nbsp;|&nbsp; <strong>Password:</strong> johnmccena
                </div>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>IMPORTANT:</strong> Delete <code>install.php</code> from your server immediately for security.
                </div>
                <a href="login.php" class="btn btn-primary w-100 mt-2">
                    <i class="fas fa-sign-in-alt me-2"></i> Go to Login
                </a>
            <?php else: ?>
                <p class="text-secondary mt-3">Fix the errors above, then try again.</p>
                <form method="POST" action="install.php">
                    <button type="submit" class="btn btn-primary w-100 mt-2">
                        <i class="fas fa-redo me-2"></i> Retry Setup
                    </button>
                </form>
            <?php endif; ?>

        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Make sure you have updated <code>config.php</code> with your database credentials before running the installer.
            </div>
            <p class="text-secondary">This will create all required database tables and a default admin account.</p>
            <ul class="text-secondary small mb-4">
                <li>8 database tables (users, predictions, bets, notifications, audit_logs, rate_limits, payment_requests, ip_accounts)</li>
                <li>Database indexes for performance</li>
                <li>Admin account: <strong>Dev1</strong> / <strong>johnmccena</strong></li>
            </ul>
            <form method="POST" action="install.php">
                <button type="submit" class="btn btn-primary w-100 btn-lg">
                    <i class="fas fa-database me-2"></i> Run Setup
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
            crossorigin="anonymous"></script>
</body>
</html>
