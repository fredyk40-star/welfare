<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1976d2">
    <meta name="description" content="GYF Welfare Management System">
    <title><?php echo APP_NAME; ?></title>
    
    <!-- PWA: manifest + theme -->
    <link rel="manifest" href="<?php echo APP_URL; ?>/manifest.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="GYF Welfare">
    
    <!-- Bootstrap 5 CSS (Local) -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/bootstrap/css/bootstrap.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
    
    <!-- Custom Icons -->
    <link rel="icon" type="image/png" href="<?php echo APP_URL; ?>/assets/images/favicon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo APP_URL; ?>/assets/icons/icon-192x192.png">
    <link rel="apple-touch-icon" href="<?php echo APP_URL; ?>/assets/icons/icon-192x192.png">
    <script src="<?php echo APP_URL; ?>/assets/js/header-common.js"></script>
</head>
<body>
    <!-- App splash (PWA startup flash guard) -->
    <div id="appSplash" aria-hidden="true">
        <img src="<?php echo APP_URL; ?>/assets/images/logo.png" alt="GYF Welfare" class="app-splash-logo">
        <p class="app-splash-text">GYF Welfare</p>
    </div>

    <!-- Global page transition loader -->
    <div id="pageLoader" class="page-loader" aria-hidden="true">
        <div class="page-loader-backdrop"></div>
        <div class="page-loader-content">
            <div class="spinner-border text-primary" role="status" aria-label="Loading"></div>
            <p class="page-loader-text mt-3">Loading...</p>
        </div>
    </div>
    <!-- Background slideshow (global, initialized in footer.php) -->
    <div class="bg-slideshow" id="bgSlideshow"></div>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg sticky-top">
        <div class="container-fluid px-3">
            <a class="navbar-brand" href="<?php echo APP_URL; ?>">
                <img src="<?php echo APP_URL; ?>/assets/images/logo.png" alt="GYF Welfare" class="app-logo">
                <span class="d-none d-sm-inline">GYF Welfare</span>
                <span class="d-inline d-sm-none">GYF</span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <?php if (isLoggedIn()): ?>
                        <?php if (isTreasurer()): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo APP_URL; ?>/treasurer/dashboard.php">Dashboard</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo APP_URL; ?>/treasurer/transactions.php">Transactions</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo APP_URL; ?>/treasurer/members.php">Members</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo APP_URL; ?>/treasurer/audit-logs.php">Audit Logs</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo APP_URL; ?>/treasurer/settings.php">Settings</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo APP_URL; ?>/treasurer/help.php">Help</a>
                            </li>
                        <?php elseif (isMember()): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo APP_URL; ?>/member/dashboard.php">Dashboard</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo APP_URL; ?>/member/transactions.php">My Transactions</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo APP_URL; ?>/member/profile.php">Profile</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo APP_URL; ?>/member/settings.php">Settings</a>
                            </li>
                        <?php endif; ?>
                        
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="profileDropdown" role="button" data-bs-toggle="dropdown">
                                <?php if (isMember() && isset($_SESSION['photo']) && !empty($_SESSION['photo'])): ?>
                                    <img src="<?php echo displayPhotoUrl($_SESSION['photo']); ?>"
                                         class="member-photo" alt="Profile">
                                <?php endif; ?>
                                <span class="d-none d-md-inline"><?php echo htmlspecialchars($_SESSION['user_id'] ?? ''); ?></span>
                                <span class="d-inline d-md-none">👤</span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if (isTreasurer()): ?>
                                    <li><a class="dropdown-item" href="<?php echo APP_URL; ?>/treasurer/profile.php">Profile</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <button class="dropdown-item" id="logoutBtn" style="background: none; border: none; cursor: pointer; text-align: left; width: 100%;">Logout</button>
                                    </li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo APP_URL; ?>/member/login.php">Member Login</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo APP_URL; ?>/member/register.php">Register</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    
    <!-- Main Content Container -->
    <div class="container mt-4">
