<?php
/**
 * CSRF Protection
 */

/**
 * Generate a CSRF token and store it in the session.
 *
 * @return string The generated token.
 */
function generateCSRFToken(): string
{
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    return $token;
}

/**
 * Validate a CSRF token against the session-stored token.
 *
 * @param string $token The token to validate.
 * @return bool True if valid, false otherwise.
 */
function validateCSRFToken(string $token): bool
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }

    $valid = hash_equals($_SESSION['csrf_token'], $token);

    // Regenerate token after validation to prevent reuse
    generateCSRFToken();

    return $valid;
}

/**
 * Return an HTML hidden input field containing the current CSRF token.
 *
 * @return string HTML input element.
 */
function csrfField(): string
{
    if (empty($_SESSION['csrf_token'])) {
        generateCSRFToken();
    }
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">';
}
