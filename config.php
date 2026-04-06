<?php
/**
 * Predictions Platform - Configuration
 *
 * IMPORTANT: Update all 'CHANGE_ME' values before deploying.
 * Database credentials can be found in your InfinityFree control panel
 * under "MySQL Databases". The host is typically sql.freedb.tech or
 * similar — check your panel for the exact hostname.
 */

// --- Database Configuration ---
// Get these from your InfinityFree panel → MySQL Databases
define('DB_HOST', 'sql.freedb.tech');   // InfinityFree MySQL host (check panel)
define('DB_NAME', 'CHANGE_ME');         // Your database name (e.g., freedb_predictions)
define('DB_USER', 'CHANGE_ME');         // Your database username
define('DB_PASS', 'CHANGE_ME');         // Your database password

// --- Site Configuration ---
define('SITE_NAME', 'Predictions');
define('SITE_URL', '');                 // Full URL without trailing slash, e.g. https://yoursite.epizy.com

// --- Security ---
// CHANGE THIS to a unique random 64-character hex string (generate at: https://randomkeygen.com/)
define('CSRF_SECRET', 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2');

// CHANGE THIS to a unique 32-character key for AES-256-CBC encryption
define('ENCRYPTION_KEY', 'CHANGE_ME_TO_32_CHAR_SECRET_KEY!');

// --- Categories ---
define('CATEGORIES', [
    'Technology',
    'Politics',
    'Sports',
    'Entertainment',
    'Finance',
    'Science',
    'World Events',
    'Health',
    'Other'
]);

// --- Timezone ---
date_default_timezone_set('UTC');

// --- Error Reporting (Production) ---
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// error_log uses PHP default on InfinityFree
