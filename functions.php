<?php
/**
 * Shared Utility Functions
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

/**
 * Sanitize a string for safe HTML output.
 *
 * @param string $str
 * @return string
 */
function sanitize(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Format a datetime string for display.
 *
 * @param string $datetime
 * @return string e.g. "Apr 5, 2026 9:33 AM"
 */
function formatDate(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return $datetime;
    }
    return date('M j, Y g:i A', $ts);
}

/**
 * Format a credit amount for display.
 *
 * @param float $amount
 * @return string e.g. "1,250.00 credits"
 */
function formatCredits($amount): string
{
    return number_format((float)$amount, 2) . ' credits';
}

/**
 * Get the list of prediction categories.
 *
 * @return array
 */
function getCategoryList(): array
{
    return CATEGORIES;
}

/**
 * Get statistics for a user.
 * [OPTIMIZED] Combined 2 queries into 1 using subquery.
 */
function getUserStats(int $userId): array
{
    $db = getDB();

    $stmt = $db->prepare(
        'SELECT u.total_predictions, u.correct_predictions, u.credits,
                (SELECT COUNT(*) FROM bets WHERE user_id = u.id) AS total_bets
         FROM users u WHERE u.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        return [
            'total_predictions'   => 0,
            'correct_predictions' => 0,
            'accuracy'            => 0,
            'total_bets'          => 0,
            'credits'             => 0,
        ];
    }

    $totalPredictions = (int)$user['total_predictions'];
    $correctPredictions = (int)$user['correct_predictions'];
    $accuracy = $totalPredictions > 0
        ? round(($correctPredictions / $totalPredictions) * 100, 1)
        : 0;

    return [
        'total_predictions'   => $totalPredictions,
        'correct_predictions' => $correctPredictions,
        'accuracy'            => $accuracy,
        'total_bets'          => (int)$user['total_bets'],
        'credits'             => (float)$user['credits'],
    ];
}

/**
 * Get the count of unread notifications for a user.
 *
 * @param int $userId
 * @return int
 */
function getNotificationCount(int $userId): int
{
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = :id AND is_read = 0'
    );
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    return (int)$row['cnt'];
}

/**
 * Get notification count with session caching (5-minute TTL).
 * [OPTIMIZED] Prevents a DB query on every single page load.
 * The header template uses this instead of getNotificationCount().
 */
function getNotificationCountCached(int $userId): int
{
    $cacheKey = '_notif_count';
    $cacheTimeKey = '_notif_count_time';
    $cacheTTL = 300; // 5 minutes

    if (
        isset($_SESSION[$cacheKey], $_SESSION[$cacheTimeKey]) &&
        (time() - $_SESSION[$cacheTimeKey]) < $cacheTTL
    ) {
        return (int)$_SESSION[$cacheKey];
    }

    $count = getNotificationCount($userId);
    $_SESSION[$cacheKey] = $count;
    $_SESSION[$cacheTimeKey] = time();
    return $count;
}

/**
 * Invalidate the cached notification count.
 * Call this after marking notifications as read or adding new ones.
 */
function invalidateNotificationCache(): void
{
    unset($_SESSION['_notif_count'], $_SESSION['_notif_count_time']);
}

/**
 * Add a notification for a user.
 * [OPTIMIZED] Removed per-call shadow ban DB check. Caller is
 * responsible for filtering shadow-banned users before calling.
 *
 * @param int      $userId
 * @param string   $message
 * @param string   $type
 * @param int|null $relatedId
 * @param bool     $skipShadowCheck  If true, skip the DB check (caller already verified).
 */
function addNotification(int $userId, string $message, string $type = 'info', ?int $relatedId = null, bool $skipShadowCheck = false): void
{
    $db = getDB();

    if (!$skipShadowCheck) {
        $stmt = $db->prepare('SELECT is_shadow_banned FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if ($user && (int)$user['is_shadow_banned'] === 1) {
            return;
        }
    }

    $stmt = $db->prepare(
        'INSERT INTO notifications (user_id, message, type, related_id, is_read, created_at)
         VALUES (:user_id, :message, :type, :related_id, 0, NOW())'
    );
    $stmt->execute([
        ':user_id'    => $userId,
        ':message'    => $message,
        ':type'       => $type,
        ':related_id' => $relatedId,
    ]);
}

/**
 * Encrypt data using AES-256-CBC.
 *
 * @param string $data
 * @return string Base64-encoded (IV + ciphertext).
 */
function encryptData(string $data): string
{
    $key = ENCRYPTION_KEY;
    $cipher = 'AES-256-CBC';
    $ivLength = openssl_cipher_iv_length($cipher);
    $iv = openssl_random_pseudo_bytes($ivLength);
    $encrypted = openssl_encrypt($data, $cipher, $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt data encrypted with encryptData().
 *
 * @param string $encrypted Base64-encoded (IV + ciphertext).
 * @return string Decrypted plaintext, or empty string on failure.
 */
function decryptData(string $encrypted): string
{
    $key = ENCRYPTION_KEY;
    $cipher = 'AES-256-CBC';
    $ivLength = openssl_cipher_iv_length($cipher);

    $raw = base64_decode($encrypted, true);
    if ($raw === false || strlen($raw) < $ivLength) {
        return '';
    }

    $iv = substr($raw, 0, $ivLength);
    $ciphertext = substr($raw, $ivLength);
    $decrypted = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv);

    return $decrypted !== false ? $decrypted : '';
}

/**
 * Get the country from request headers.
 * InfinityFree uses Cloudflare, which sets CF-IPCountry header.
 * Falls back to 'Unknown' — NO external API calls.
 *
 * [OPTIMIZED] Removed external HTTP call to ip-api.com that would
 * fail or timeout on InfinityFree.
 */
function getCountryFromIP(string $ip): string
{
    // Cloudflare sets this header automatically
    if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
        $code = strtoupper($_SERVER['HTTP_CF_IPCOUNTRY']);
        if ($code !== 'XX' && $code !== 'T1' && strlen($code) === 2) {
            return $code;
        }
    }
    return 'Unknown';
}

/**
 * Log an admin audit action.
 *
 * @param int         $adminId
 * @param string      $action
 * @param string      $targetType
 * @param int|null    $targetId
 * @param string      $details
 */
function logAuditAction(int $adminId, string $action, string $targetType, ?int $targetId, string $details): void
{
    $db = getDB();
    $ip = getClientIP();

    $stmt = $db->prepare(
        'INSERT INTO audit_logs (admin_id, action, target_type, target_id, details, ip_address, created_at)
         VALUES (:admin_id, :action, :target_type, :target_id, :details, :ip, NOW())'
    );
    $stmt->execute([
        ':admin_id'    => $adminId,
        ':action'      => $action,
        ':target_type' => $targetType,
        ':target_id'   => $targetId,
        ':details'     => $details,
        ':ip'          => $ip,
    ]);
}

/**
 * Convert a datetime to a human-readable "time ago" string.
 *
 * @param string $datetime
 * @return string e.g. "5 minutes ago", "2 hours ago"
 */
function timeAgo(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return $datetime;
    }

    $diff = time() - $ts;

    if ($diff < 0) {
        return 'just now';
    }

    if ($diff < 60) {
        return 'just now';
    }

    $minutes = (int)floor($diff / 60);
    if ($minutes < 60) {
        return $minutes === 1 ? '1 minute ago' : $minutes . ' minutes ago';
    }

    $hours = (int)floor($diff / 3600);
    if ($hours < 24) {
        return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
    }

    $days = (int)floor($diff / 86400);
    if ($days < 30) {
        return $days === 1 ? '1 day ago' : $days . ' days ago';
    }

    $months = (int)floor($days / 30);
    if ($months < 12) {
        return $months === 1 ? '1 month ago' : $months . ' months ago';
    }

    $years = (int)floor($days / 365);
    return $years === 1 ? '1 year ago' : $years . ' years ago';
}

/**
 * Truncate a string to a maximum length, appending "..." if truncated.
 *
 * @param string $str
 * @param int    $length
 * @return string
 */
function truncate(string $str, int $length = 100): string
{
    if (mb_strlen($str, 'UTF-8') <= $length) {
        return $str;
    }
    return mb_substr($str, 0, $length, 'UTF-8') . '...';
}

/**
 * Resolve a prediction: distribute payouts or refund bets.
 * [OPTIMIZED] Reduced from ~4N queries to ~N+10 queries.
 * - Pre-fetches shadow ban status for all bettors in 1 query
 * - Batch-updates losing bets in 1 query
 * - Passes skipShadowCheck=true to addNotification
 */
function resolvePrediction(int $predictionId, string $outcome, int $adminId, string $note): bool
{
    $db = getDB();

    try {
        $db->beginTransaction();

        // Fetch prediction
        $stmt = $db->prepare('SELECT * FROM predictions WHERE id = :id AND status IN ("active","locked") LIMIT 1');
        $stmt->execute([':id' => $predictionId]);
        $prediction = $stmt->fetch();

        if (!$prediction) {
            $db->rollBack();
            return false;
        }

        $creatorId = (int)$prediction['user_id'];
        $totalPoolFor = (float)$prediction['total_pool_for'];
        $totalPoolAgainst = (float)$prediction['total_pool_against'];

        // Fetch all bets
        $stmt = $db->prepare('SELECT * FROM bets WHERE prediction_id = :id');
        $stmt->execute([':id' => $predictionId]);
        $bets = $stmt->fetchAll();

        // Pre-fetch shadow ban status for all bettors in ONE query
        $userIds = array_unique(array_map(fn($b) => (int)$b['user_id'], $bets));
        $shadowBannedIds = [];
        if (!empty($userIds)) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $db->prepare("SELECT id FROM users WHERE id IN ($placeholders) AND is_shadow_banned = 1");
            $stmt->execute(array_values($userIds));
            $shadowBannedIds = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $predTitle = truncate($prediction['title'], 60);

        if ($outcome === 'cancel') {
            // Refund all bets
            foreach ($bets as $bet) {
                $stmt = $db->prepare('UPDATE users SET credits = credits + :amount WHERE id = :uid');
                $stmt->execute([':amount' => $bet['amount'], ':uid' => $bet['user_id']]);

                $stmt = $db->prepare('UPDATE bets SET payout = :payout WHERE id = :id');
                $stmt->execute([':payout' => $bet['amount'], ':id' => $bet['id']]);

                if (!isset($shadowBannedIds[(int)$bet['user_id']])) {
                    addNotification(
                        (int)$bet['user_id'],
                        'Prediction "' . $predTitle . '" was cancelled. Your bet of ' . formatCredits($bet['amount']) . ' has been refunded.',
                        'warning',
                        $predictionId,
                        true // skip shadow check — already verified
                    );
                }
            }
            $newStatus = 'cancelled';

        } elseif ($outcome === 'yes' || $outcome === 'no') {
            $winPosition = ($outcome === 'yes') ? 'for' : 'against';
            $winningPool = ($outcome === 'yes') ? $totalPoolFor : $totalPoolAgainst;
            $losingPool = ($outcome === 'yes') ? $totalPoolAgainst : $totalPoolFor;
            $outcomeUpper = strtoupper($outcome);

            // Batch set all losing bets payout to 0
            $losePosition = ($winPosition === 'for') ? 'against' : 'for';
            $stmt = $db->prepare('UPDATE bets SET payout = 0.00 WHERE prediction_id = :pid AND position = :pos');
            $stmt->execute([':pid' => $predictionId, ':pos' => $losePosition]);

            foreach ($bets as $bet) {
                if ($bet['position'] === $winPosition) {
                    // Winner
                    if ($winningPool > 0) {
                        $payout = (float)$bet['amount'] + ((float)$bet['amount'] / $winningPool) * $losingPool;
                    } else {
                        $payout = (float)$bet['amount'];
                    }
                    $payout = round($payout, 2);

                    $stmt = $db->prepare('UPDATE users SET credits = credits + :payout WHERE id = :uid');
                    $stmt->execute([':payout' => $payout, ':uid' => $bet['user_id']]);

                    $stmt = $db->prepare('UPDATE bets SET payout = :payout WHERE id = :id');
                    $stmt->execute([':payout' => $payout, ':id' => $bet['id']]);

                    if (!isset($shadowBannedIds[(int)$bet['user_id']])) {
                        addNotification(
                            (int)$bet['user_id'],
                            'You won ' . formatCredits($payout) . ' on "' . $predTitle . '"! The prediction resolved ' . $outcomeUpper . '.',
                            'success',
                            $predictionId,
                            true
                        );
                    }
                } else {
                    // Loser — payout already batch-set to 0
                    if (!isset($shadowBannedIds[(int)$bet['user_id']])) {
                        addNotification(
                            (int)$bet['user_id'],
                            'You lost your bet of ' . formatCredits($bet['amount']) . ' on "' . $predTitle . '". The prediction resolved ' . $outcomeUpper . '.',
                            'danger',
                            $predictionId,
                            true
                        );
                    }
                }
            }

            $newStatus = ($outcome === 'yes') ? 'resolved_yes' : 'resolved_no';

        } else {
            $db->rollBack();
            return false;
        }

        // Update prediction record
        $stmt = $db->prepare(
            'UPDATE predictions
             SET status = :status, resolution_note = :note, resolved_by = :admin_id, resolved_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            ':status'   => $newStatus,
            ':note'     => $note,
            ':admin_id' => $adminId,
            ':id'       => $predictionId,
        ]);

        // Update creator stats
        if ($outcome === 'yes' || $outcome === 'no') {
            if ($outcome === 'yes') {
                $stmt = $db->prepare(
                    'UPDATE users SET total_predictions = total_predictions + 1,
                     correct_predictions = correct_predictions + 1 WHERE id = :id'
                );
            } else {
                $stmt = $db->prepare(
                    'UPDATE users SET total_predictions = total_predictions + 1 WHERE id = :id'
                );
            }
            $stmt->execute([':id' => $creatorId]);
        }

        // Audit
        logAuditAction($adminId, 'resolve_prediction', 'prediction', $predictionId, "Resolved as '{$outcome}'. Note: {$note}");

        $db->commit();

        // Invalidate notification cache for affected users
        invalidateNotificationCache();

        return true;

    } catch (\Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('resolvePrediction error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get a Bootstrap badge class for a prediction category.
 *
 * @param string $category
 * @return string CSS class string.
 */
function getCategoryBadgeClass(string $category): string
{
    $map = [
        'Technology'   => 'badge-technology',
        'Politics'     => 'badge-politics',
        'Sports'       => 'badge-sports',
        'Entertainment'=> 'badge-entertainment',
        'Finance'      => 'badge-finance',
        'Science'      => 'badge-science',
        'World Events' => 'badge-world-events',
        'Health'       => 'badge-health',
        'Other'        => 'badge-other',
    ];

    return $map[$category] ?? 'badge-other';
}

/**
 * Get a Bootstrap badge class for a prediction status.
 *
 * @param string $status
 * @return string CSS class string.
 */
function getStatusBadgeClass(string $status): string
{
    $map = [
        'active'       => 'bg-success',
        'resolved_yes' => 'bg-info',
        'resolved_no'  => 'bg-danger',
        'locked'       => 'bg-warning text-dark',
        'cancelled'    => 'bg-secondary',
    ];

    return $map[$status] ?? 'bg-secondary';
}
