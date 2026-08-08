<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';

// Check online status
if (!checkOnlineStatus()) {
    die('<div class="alert alert-danger text-center mt-5">Internet connection required. Please check your connection and try again.</div>');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1976d2">
    <meta name="description" content="GYF Welfare Management System">
    <title><?php echo APP_NAME; ?></title>
    
    <!-- Bootstrap 5 CSS (Local) -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/bootstrap/css/bootstrap.min.css">
    
    <script>
    // Fallback to CDN if local Bootstrap CSS fails to load
    (function() {
        var css = document.querySelector('link[href*="bootstrap.min.css"]');
        if (css && !css.sheet) {
            var fallback = document.createElement('link');
            fallback.rel = 'stylesheet';
            fallback.href = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
            document.head.appendChild(fallback);
        }
    })();
    </script>

    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
    
    <!-- Custom Icons -->
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo APP_URL; ?>/assets/icons/icon-192x192.png">
    <link rel="apple-touch-icon" href="<?php echo APP_URL; ?>/assets/icons/icon-192x192.png">
</head>
<body>
    <!-- Background slideshow (global, initialized in footer.php) -->
    <div class="bg-slideshow" id="bgSlideshow"></div>
    <!-- Remove any previously registered service worker + purge its caches (offline cache fully disabled) -->
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistrations().then(function(regs) {
                regs.forEach(function(r) { r.unregister(); });
            });
        }
        if ('caches' in window) {
            caches.keys().then(function(names) {
                names.forEach(function(n) { caches.delete(n); });
            });
        }
    </script>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg sticky-top">
        <div class="container-fluid px-3">
            <a class="navbar-brand" href="<?php echo APP_URL; ?>">
                <img src="<?php echo APP_URL; ?>/uploads/photos/logo.png" alt="GYF Welfare" class="app-logo">
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
                                <a class="nav-link" href="<?php echo APP_URL; ?>/treasurer/settings.php">Settings</a>
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
                                <?php if (isMember() && isset($_SESSION['photo'])): ?>
                                    <img src="<?php echo APP_URL; ?>/uploads/photos/<?php echo $_SESSION['photo']; ?>" 
                                         class="member-photo" alt="Profile">
                                <?php endif; ?>
                                <span class="d-none d-md-inline"><?php echo $_SESSION['user_id']; ?></span>
                                <span class="d-inline d-md-none">👤</span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if (isTreasurer()): ?>
                                    <li><a class="dropdown-item" href="<?php echo APP_URL; ?>/treasurer/profile.php">Profile</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?php echo APP_URL; ?>/api/auth.php?action=logout">Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo APP_URL; ?>/member/login.php">Member Login</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo APP_URL; ?>/member/register.php">Register</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo APP_URL; ?>/treasurer/login.php">Treasurer Login</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    
    <!-- Main Content Container -->
    <div class="container mt-4">
