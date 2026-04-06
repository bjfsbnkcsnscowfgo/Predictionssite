<?php
/**
 * Header Template - HTML head, navbar, and opening content wrapper.
 *
 * Variables expected before including:
 *   $pageTitle   (string) - Page title
 *   $currentPage (string) - Current page identifier for nav highlighting
 */

require_once __DIR__ . '/functions.php';

$_user = isLoggedIn() ? getCurrentUser() : null;
// [OPTIMIZED] Use cached notification count (5-min TTL) to avoid a DB query on every page load
$_notifCount = $_user ? getNotificationCountCached((int)$_user['id']) : 0;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle ?? 'Predictions') ?> | <?= sanitize(SITE_NAME) ?></title>

    <!-- Bootstrap 5.3.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YcnS/1WR6zNn36Dg6013oZu47rE6YDstIpKb" crossorigin="anonymous">

    <!-- Font Awesome 6.5.1 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
          integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
          crossorigin="anonymous" referrerpolicy="no-referrer">

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Custom Stylesheet -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css">
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark sticky-top" id="mainNavbar">
    <div class="container-xl">
        <!-- Brand -->
        <a class="navbar-brand fw-bold" href="<?= SITE_URL ?>/index.php">
            <i class="fas fa-chart-line me-2"></i><?= sanitize(SITE_NAME) ?>
        </a>

        <!-- Mobile Toggle -->
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse"
                data-bs-target="#navbarMain" aria-controls="navbarMain" aria-expanded="false"
                aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Collapsible Content -->
        <div class="collapse navbar-collapse" id="navbarMain">
            <!-- Left Nav -->
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link<?= ($currentPage ?? '') === 'home' ? ' active' : '' ?>"
                       href="<?= SITE_URL ?>/index.php">
                        <i class="fas fa-home me-1"></i> Home
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?= ($currentPage ?? '') === 'leaderboard' ? ' active' : '' ?>"
                       href="<?= SITE_URL ?>/leaderboard.php">
                        <i class="fas fa-trophy me-1"></i> Leaderboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?= ($currentPage ?? '') === 'search' ? ' active' : '' ?>"
                       href="<?= SITE_URL ?>/search.php">
                        <i class="fas fa-search me-1"></i> Search
                    </a>
                </li>
            </ul>

            <!-- Right Nav -->
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
                <?php if ($_user): ?>
                    <!-- Create Prediction -->
                    <li class="nav-item me-lg-2">
                        <a class="btn btn-primary btn-sm px-3<?= ($currentPage ?? '') === 'create' ? ' active' : '' ?>"
                           href="<?= SITE_URL ?>/create.php">
                            <i class="fas fa-plus me-1"></i> Create
                        </a>
                    </li>

                    <!-- Notifications Bell -->
                    <li class="nav-item me-lg-2">
                        <a class="nav-link position-relative<?= ($currentPage ?? '') === 'notifications' ? ' active' : '' ?>"
                           href="<?= SITE_URL ?>/notifications.php" title="Notifications">
                            <i class="fas fa-bell"></i>
                            <?php if ($_notifCount > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notification-badge">
                                    <?= $_notifCount > 99 ? '99+' : $_notifCount ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <!-- Credits Display -->
                    <li class="nav-item me-lg-2">
                        <span class="nav-link credits-display" title="Your Credits">
                            <i class="fas fa-coins text-warning me-1"></i>
                            <span class="credits-amount"><?= number_format((float)$_user['credits'], 2) ?></span>
                        </span>
                    </li>

                    <!-- User Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button"
                           data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user-circle me-1"></i>
                            <?= sanitize($_user['username']) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/profile.php?id=<?= (int)$_user['id'] ?>">
                                    <i class="fas fa-user me-2"></i> Profile
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/edit_profile.php">
                                    <i class="fas fa-cog me-2"></i> Edit Profile
                                </a>
                            </li>
                            <?php if (isAdmin()): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="<?= SITE_URL ?>/admin/">
                                        <i class="fas fa-shield-halved me-2"></i> Admin Dashboard
                                    </a>
                                </li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="<?= SITE_URL ?>/logout.php">
                                    <i class="fas fa-sign-out-alt me-2"></i> Logout
                                </a>
                            </li>
                        </ul>
                    </li>
                <?php else: ?>
                    <!-- Login / Register -->
                    <li class="nav-item me-2">
                        <a class="btn btn-outline-light btn-sm" href="<?= SITE_URL ?>/login.php">
                            <i class="fas fa-sign-in-alt me-1"></i> Login
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-primary btn-sm" href="<?= SITE_URL ?>/register.php">
                            <i class="fas fa-user-plus me-1"></i> Register
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Flash Messages -->
<?php if (!empty($_SESSION['flash_success'])): ?>
<div class="container-xl mt-3">
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?= sanitize($_SESSION['flash_success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
</div>
<?php unset($_SESSION['flash_success']); endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="container-xl mt-3">
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?= sanitize($_SESSION['flash_error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
</div>
<?php unset($_SESSION['flash_error']); endif; ?>

<!-- Main Content -->
<main class="container-xl py-4">
