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
$csrf_token = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $member_id = sanitizeInput($_POST['member_id']);
        $password = $_POST['password'];
        
        if (empty($member_id) || empty($password)) {
            $error = 'Please fill in all fields.';
        } else {
            $database = new Database();
            $db = $database->getConnection();
            
            if (!checkRateLimit($member_id)) {
                $error = 'Too many failed login attempts. Please try again later.';
            } else {
                $query = "SELECT * FROM members WHERE member_id = :member_id";
                $stmt = $db->prepare($query);
                $stmt->execute([':member_id' => $member_id]);
                
                if ($member = $stmt->fetch()) {
                    if (password_verify($password, $member['password'])) {
                        // Check if 2FA is enabled
                        if (!empty($member['two_fa_secret'])) {
                            $_SESSION['temp_user'] = $member;
                            redirectTo('/member/verify-2fa.php');
                        }
                        
                        // Set session
                        $_SESSION['user_id'] = $member['member_id'];
                        $_SESSION['user_type'] = 'member';
                        $_SESSION['full_name'] = $member['full_name'];
                        $_SESSION['email'] = $member['email'];
                        $_SESSION['photo'] = $member['passport_photo'];
                        
                        session_regenerate_id(true);
                        
                        logAudit($member['member_id'], 'Member login successful');
                        redirectTo('/member/dashboard.php');
                    } else {
                        $error = 'Invalid credentials.';
                        logAudit($member_id, 'Failed login attempt');
                    }
                } else {
                    $error = 'Member not found.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Login - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
</head>
<body>
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-5">
                <div class="mb-3">
                    <a href="<?php echo APP_URL; ?>/" class="btn btn-outline-light btn-sm back-link">
                        ← Back
                    </a>
                </div>
                <div class="card glass-strong">
                    <div class="card-body text-center">
                        <img src="<?php echo APP_URL; ?>/uploads/photos/logo.png" alt="GYF Welfare" class="app-logo-large mb-3">
                        <h3>Member Login</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($_GET['registered'])): ?>
                            <div class="alert alert-success">Registration successful! Please login.</div>
                        <?php endif; ?>
                        
                        <?php if (isset($_GET['reset'])): ?>
                            <div class="alert alert-success">Password reset successful! Please login with your new password.</div>
                        <?php endif; ?>
                        
                        <form method="POST" action="" id="loginForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <div class="mb-3">
                                <label for="member_id" class="form-label">Member ID</label>
                                <input type="text" class="form-control" id="member_id" name="member_id" 
                                       placeholder="Enter your Member ID (e.g., GYF-546578)" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" 
                                       placeholder="Enter your password" required>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">Login</button>
                        </form>
                        
                        <div class="mt-3 text-center">
                            <a href="forgot-password.php" class="text-decoration-none">Forgot Password?</a>
                            <br>
                            <a href="register.php" class="text-decoration-none">Don't have an account? Register here</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="<?php echo APP_URL; ?>/assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/validation.js"></script>
</body>
</html>


