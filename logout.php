<?php
/**
 * Logout Page — Destroys session and redirects to login.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
startSecureSession();

logoutUser();
// logoutUser() calls exit after redirect, but just in case:
header('Location: ' . SITE_URL . '/login.php');
exit;
