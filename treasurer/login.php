<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isTreasurer()) {
        redirectTo('/treasurer/dashboard.php');
    } else {
        redirectTo('/member/dashboard.php');
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = strtolower(sanitizeInput($_POST['email']));
        $password = $_POST['password'];
        
        if (empty($email) || empty($password)) {
            $error = 'Please fill in all fields.';
        } elseif (!checkRateLimit($email)) {
            $error = 'Too many failed login attempts. Please try again later.';
        } else {
            $database = new Database();
            $db = $database->getConnection();
            
            $query = "SELECT * FROM members WHERE LOWER(email) = :email AND member_id = 'GYF-ADMIN'";
            $stmt = $db->prepare($query);
            $stmt->execute([':email' => $email]);
            
            if ($member = $stmt->fetch()) {
                if (password_verify($password, $member['password'])) {
                    // Check if 2FA is enabled
                    if (!empty($member['two_fa_secret'])) {
                        $_SESSION['temp_user'] = $member;
                        $_SESSION['temp_user_type'] = 'treasurer';
                        redirectTo('/treasurer/verify-2fa.php');
                    }
                    
                    // Set session for treasurer
                    $_SESSION['user_id'] = $member['member_id'];
                    $_SESSION['user_type'] = 'treasurer';
                    $_SESSION['full_name'] = $member['full_name'];
                    $_SESSION['email'] = $member['email'];
                    
                    logAudit($member['member_id'], 'Treasurer login successful');
                    
                    // Regenerate session ID for security
                    session_regenerate_id(true);
                    
                    redirectTo('/treasurer/dashboard.php');
                } else {
                    $error = 'Invalid credentials.';
                    logAudit($email, 'Failed treasurer login attempt - wrong password');
                }
            } else {
                $error = 'Invalid treasurer credentials.';
                logAudit($email, 'Failed treasurer login attempt - account not found');
            }
        }
    }
}

// Check for password reset success
if (isset($_GET['reset']) && $_GET['reset'] == 'success') {
    $success = 'Password reset successful! Please login with your new password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1976d2">
    <title>Treasurer Login - <?php echo APP_NAME; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/bootstrap/css/bootstrap.min.css">
    <!-- Fallback to CDN if local file not found -->
    <script>
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
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="192x192" href="<?php echo APP_URL; ?>/assets/icons/icon-192x192.png">
    
    <style>
        .login-container {
            min-height: 100vh;
        }

        /* Glass override for the login header band (kept subtle, transparent) */
        .login-header {
            background: rgba(25, 118, 210, 0.25);
            padding: 30px;
            text-align: center;
            color: #fff;
            border-bottom: 1px solid var(--glass-border);
        }

        .login-header .treasurer-icon {
            font-size: 60px;
            margin-bottom: 15px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .login-body {
            padding: 40px;
        }

        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 2px solid var(--glass-border);
            background: var(--glass-bg);
            color: var(--text-primary);
            transition: all 0.3s ease;
        }

        .form-control::placeholder {
            color: var(--text-muted);
        }

        .form-control:focus {
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 0.2rem var(--accent-glow);
            background: var(--glass-bg-strong);
        }

        .input-group-text {
            border-radius: 10px 0 0 10px;
            border: 2px solid var(--glass-border);
            border-right: none;
            background: var(--glass-bg);
            color: var(--text-secondary);
        }

        .input-group .form-control {
            border-radius: 0 10px 10px 0;
        }

        /* Password visibility toggle */
        .password-toggle {
            cursor: pointer;
            padding: 10px 14px;
            border: 2px solid var(--glass-border);
            border-left: none;
            border-radius: 0 10px 10px 0;
            background: var(--glass-bg);
            color: var(--text-secondary);
            transition: all 0.3s ease;
        }

        .password-toggle:hover {
            background: var(--glass-bg-strong);
        }

        /* Transparent glass buttons */
        .btn-login {
            border-radius: 12px;
            padding: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
            background: rgba(25, 118, 210, 0.35);
            border: 1px solid var(--glass-border);
            -webkit-backdrop-filter: blur(10px);
            backdrop-filter: blur(10px);
            color: #fff;
            transition: all 0.3s ease;
        }

        .btn-login:hover {
            background: rgba(25, 118, 210, 0.55);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px var(--accent-glow);
        }

        .security-badge {
            background: rgba(255, 152, 0, 0.25);
            border: 1px solid var(--glass-border);
            color: #fff;
            padding: 10px;
            border-radius: 10px;
            font-size: 14px;
            text-align: center;
            margin-bottom: 20px;
        }

        .back-link {
            color: var(--accent-blue);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .back-link:hover {
            color: #fff;
            text-decoration: underline;
        }

        .features-list {
            list-style: none;
            padding: 0;
            margin-top: 15px;
        }

        .features-list li {
            padding: 5px 0;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .features-list li:before {
            content: "✓ ";
            color: #4caf50;
            font-weight: bold;
            margin-right: 8px;
        }

        .login-card .card-header,
        .login-card .card-body {
            background: transparent;
        }
    </style>
</head>
<body>
    <!-- Background slideshow (self-contained for this standalone login page) -->
    <div class="bg-slideshow" id="bgSlideshow"></div>
    <div class="container login-container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-5 col-lg-4">
                <!-- Logo/Brand -->
                <div class="text-center mb-4">
                    <img src="<?php echo APP_URL; ?>/uploads/photos/logo.png" alt="GYF Welfare" class="app-logo-large">
                    <h2 class="text-white fw-bold mt-3">
                        GYF Welfare
                    </h2>
                    <p class="text-white-50">Treasurer Access Portal</p>
                </div>
                
                <!-- Login Card -->
                <div class="card login-card glass-strong">
                    <div class="login-header">
                        <div class="treasurer-icon">💼</div>
                        <h4 class="mb-0">Treasurer Login</h4>
                        <small>Secure Access Only</small>
                    </div>
                    
                    <div class="login-body">
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <strong>✅ Success!</strong> <?php echo $success; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <strong>⚠️ Error!</strong> <?php echo $error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($_GET['timeout'])): ?>
                            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                <strong>⏰ Session Expired!</strong> Your session has expired. Please login again.
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($_GET['logout'])): ?>
                            <div class="alert alert-info alert-dismissible fade show" role="alert">
                                <strong>👋 Logged Out!</strong> You have been successfully logged out.
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Security Notice -->
                        <div class="security-badge">
                            🔒 <strong>Secure Login</strong> - This area is restricted to authorized treasurers only
                        </div>
                        
                        <form method="POST" action="" id="loginForm" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                            <div class="mb-3">
                                <label for="email" class="form-label fw-bold">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text">📧</span>
                                    <input type="email" 
                                           class="form-control" 
                                           id="email" 
                                           name="email" 
                                           placeholder="Enter your registered email" 
                                           required
                                           autocomplete="off">
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="password" class="form-label fw-bold">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text">🔑</span>
                                    <input type="password" 
                                           class="form-control" 
                                           id="password" 
                                           name="password" 
                                           placeholder="Enter your password" 
                                           required
                                           autocomplete="off">
                                    <span class="password-toggle" onclick="togglePassword()" id="toggleIcon">
                                        👁️
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="remember" name="remember">
                                <label class="form-check-label" for="remember">Remember this device</label>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-login w-100 mb-3" id="loginButton">
                                <span id="loginText">🔐 Login to Dashboard</span>
                                <span id="loginSpinner" class="spinner-border spinner-border-sm d-none" role="status"></span>
                            </button>
                        </form>
                        
                        <div class="text-center">
                            <a href="<?php echo APP_URL; ?>/member/forgot-password.php" class="back-link">
                                🔑 Forgot Password?
                            </a>
                        </div>
                        
                        <hr class="my-4">
                        
                        <div class="text-center">
                            <a href="<?php echo APP_URL; ?>/index.html" class="back-link">
                                ← Back to Home
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="<?php echo APP_URL; ?>/assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
    
    </script>
    
    <script>
        // Password visibility toggle
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.textContent = '🙈';
            } else {
                passwordField.type = 'password';
                toggleIcon.textContent = '👁️';
            }
        }
        
        // Form submission with loading state
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const loginButton = document.getElementById('loginButton');
            const loginText = document.getElementById('loginText');
            const loginSpinner = document.getElementById('loginSpinner');
            
            // Check online status
            if (!navigator.onLine) {
                e.preventDefault();
                alert('Internet connection required. Please check your connection and try again.');
                return false;
            }
            
            // Show loading state
            loginButton.disabled = true;
            loginText.classList.add('d-none');
            loginSpinner.classList.remove('d-none');
            
            // Validate inputs
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            
            if (!email || !password) {
                e.preventDefault();
                loginButton.disabled = false;
                loginText.classList.remove('d-none');
                loginSpinner.classList.add('d-none');
                return false;
            }
            
            return true;
        });
        
        // Auto-focus on Email field
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('email').focus();
        });
        
        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
        
        // Clear form on page load (security measure)
        window.addEventListener('pageshow', function() {
            document.getElementById('loginForm').reset();
        });
        
        // Keyboard shortcut: Enter to submit
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && document.activeElement.tagName !== 'BUTTON') {
                const loginButton = document.getElementById('loginButton');
                if (loginButton && !loginButton.disabled) {
                    loginButton.click();
                }
            }
        });
    </script>

    <!-- Background slideshow: cycle uploads images behind the dark overlay -->
    <script>
    (function () {
        var container = document.getElementById('bgSlideshow');
        if (!container) return;
        var base = '';
        var images = [];
        for (var n = 1; n <= 24; n++) {
            images.push(base + 'uploads/' + n + '.jpg');
        }
        images.push(base + 'uploads/glassmorphism-background.jpg');
        var slides = [];
        images.forEach(function (src, idx) {
            var div = document.createElement('div');
            div.className = 'slide' + (idx === 0 ? ' active' : '');
            div.style.backgroundImage = 'url(' + src + ')';
            container.appendChild(div);
            slides.push(div);
        });
        var current = 0;
        setInterval(function () {
            if (slides.length < 2) return;
            slides[current].classList.remove('active');
            current = (current + 1) % slides.length;
            slides[current].classList.add('active');
        }, 5000);
    })();
    </script>
</body>
</html>


