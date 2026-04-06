<?php
/**
 * Rate Limiting
 */

require_once __DIR__ . '/db.php';

/**
 * Get the real client IP address, accounting for proxies.
 *
 * @return string Client IP address.
 */
function getClientIP(): string
{
    $headers = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR',
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            // HTTP_X_FORWARDED_FOR can contain multiple IPs; take the first
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '0.0.0.0';
}

/**
 * Check if the current IP is within the rate limit for a given action.
 *
 * @param string $action       The action identifier (e.g., 'login', 'register').
 * @param int    $maxAttempts   Maximum allowed attempts within the window.
 * @param int    $windowMinutes Time window in minutes.
 * @return bool True if within limit (action allowed), false if limit exceeded.
 */
function checkRateLimit(string $action, int $maxAttempts, int $windowMinutes): bool
{
    // Randomly clean up old records (1% chance)
    if (mt_rand(1, 100) === 1) {
        cleanupRateLimits();
    }

    $db = getDB();
    $ip = getClientIP();
    $windowStart = date('Y-m-d H:i:s', strtotime("-{$windowMinutes} minutes"));

    $stmt = $db->prepare(
        'SELECT SUM(attempts) AS total_attempts
         FROM rate_limits
         WHERE ip_address = :ip
           AND action_type = :action
           AND window_start >= :window_start'
    );
    $stmt->execute([
        ':ip'           => $ip,
        ':action'       => $action,
        ':window_start' => $windowStart,
    ]);

    $row = $stmt->fetch();
    $totalAttempts = (int)($row['total_attempts'] ?? 0);

    return $totalAttempts < $maxAttempts;
}

/**
 * Record a rate-limit attempt for the current IP and action.
 *
 * @param string $action The action identifier.
 */
function recordRateLimit(string $action): void
{
    $db = getDB();
    $ip = getClientIP();
    $now = date('Y-m-d H:i:s');

    // Try to update an existing record within the last minute
    $stmt = $db->prepare(
        'UPDATE rate_limits
         SET attempts = attempts + 1
         WHERE ip_address = :ip
           AND action_type = :action
           AND window_start >= :cutoff'
    );
    $stmt->execute([
        ':ip'     => $ip,
        ':action' => $action,
        ':cutoff' => date('Y-m-d H:i:s', strtotime('-1 minute')),
    ]);

    if ($stmt->rowCount() === 0) {
        // No recent record found; insert a new one
        $stmt = $db->prepare(
            'INSERT INTO rate_limits (ip_address, action_type, attempts, window_start)
             VALUES (:ip, :action, 1, :now)'
        );
        $stmt->execute([
            ':ip'     => $ip,
            ':action' => $action,
            ':now'    => $now,
        ]);
    }
}

/**
 * Remove rate-limit records older than 24 hours.
 */
function cleanupRateLimits(): void
{
    $db = getDB();
    $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));

    $stmt = $db->prepare('DELETE FROM rate_limits WHERE window_start < :cutoff');
    $stmt->execute([':cutoff' => $cutoff]);
}
