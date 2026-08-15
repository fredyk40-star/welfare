<?php
header('Referrer-Policy: no-referrer');
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';

$error = '';
$success = '';
$token = isset($_GET['token']) ? ($_GET['token'] ?? '') : '';
$csrf_token = generateCsrfToken();

if (empty($token)) {
    $error = 'Invalid or expired reset link.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($token)) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (empty($password) || empty($confirm_password)) {
            $error = 'Please fill in all fields.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } elseif (($password_validation = validatePassword($password)) !== true) {
            $error = $password_validation;
        } else {
            $database = new Database();
            $db = $database->getConnection();
            
            // Verify token
            $query = "SELECT * FROM password_resets WHERE token = :token AND expires_at > NOW()";
            $stmt = $db->prepare($query);
            $stmt->execute([':token' => $token]);
            $reset = $stmt->fetch();
            
            if ($reset) {
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                
                $update_query = "UPDATE members SET password = :password WHERE member_id = :member_id";
                $update_stmt = $db->prepare($update_query);
                
                try {
                    $update_stmt->execute([
                        ':password' => $hashed_password,
                        ':member_id' => $reset['member_id']
                    ]);
                    
                    // Delete used token
                    $delete_query = "DELETE FROM password_resets WHERE token = :token";
                    $delete_stmt = $db->prepare($delete_query);
                    $delete_stmt->execute([':token' => $token]);
                    
                    logAudit($reset['member_id'], 'Password reset completed');
                    $success = 'Password reset successful! You can now login with your new password.';
                } catch (PDOException $e) {
                    $error = 'Failed to reset password. Please try again.';
                    error_log("Password Reset Error: " . $e->getMessage());
                }
            } else {
                $error = 'Invalid or expired reset link.';
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
    <title>Reset Password - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/bootstrap/css/bootstrap.min.css">
    <script src="<?php echo APP_URL; ?>/assets/js/header-common.js"></script>
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header text-center">
                        <h3>Reset Password</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                            <div class="text-center">
                                <a href="forgot-password.php" class="btn btn-primary">Request New Reset Link</a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <?php echo $success; ?>
                                <br>
                                <a href="login.php" class="btn btn-primary mt-3">Go to Login</a>
                            </div>
                        <?php else: ?>
                            <?php if (empty($token)) return; ?>
                            <form method="POST" action="" id="resetPasswordForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                                <div class="mb-3">
                                    <label for="password" class="form-label">New Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="password" name="password" 
                                               placeholder="8-255 characters, include uppercase, lowercase, number, and special character" required>
                                        <button class="btn btn-outline-secondary" type="button" data-toggle-password="password" data-toggle-icon="toggleIcon1">
                                            <span id="toggleIcon1">👁️</span>
                                        </button>
                                    </div>
                                    <small class="text-muted">Password must be 8-255 characters with uppercase, lowercase, number, and special character</small>
                                </div>
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                        <button class="btn btn-outline-secondary" type="button" data-toggle-password="confirm_password" data-toggle-icon="toggleIcon2">
                                            <span id="toggleIcon2">👁️</span>
                                        </button>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary w-100" id="resetButton">
                                    <span id="resetText">Reset Password</span>
                                    <span id="resetSpinner" class="spinner-border spinner-border-sm d-none" role="status"></span>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="<?php echo APP_URL; ?>/assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/main.js"></script>

    <script src="<?php echo APP_URL; ?>/assets/js/slideshow.js"></script>
    <script nonce="<?php echo CSP_NONCE; ?>">
    function togglePassword(fieldId, iconId) {
        const field = document.getElementById(fieldId);
        const icon = document.getElementById(iconId);
        if (!field || !icon) return;
        if (field.type === 'password') {
            field.type = 'text';
            icon.textContent = '🙈';
        } else {
            field.type = 'password';
            icon.textContent = '👁️';
        }
    }

    document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
        const resetButton = document.getElementById('resetButton');
        const resetText = document.getElementById('resetText');
        const resetSpinner = document.getElementById('resetSpinner');
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        
        if (password !== confirmPassword) {
            e.preventDefault();
            alert('Passwords do not match.');
            return false;
        }
        
        if (!navigator.onLine) {
            e.preventDefault();
            alert('Internet connection required. Please check your connection and try again.');
            return false;
        }
        
        resetButton.disabled = true;
        resetText.classList.add('d-none');
        resetSpinner.classList.remove('d-none');
    });

    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('password').focus();
        
        document.querySelectorAll('[data-toggle-password]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var fieldId = this.dataset.togglePassword;
                var iconId = this.dataset.toggleIcon;
                var field = document.getElementById(fieldId);
                var icon = document.getElementById(iconId);
                if (!field || !icon) return;
                var type = field.getAttribute('type') === 'password' ? 'text' : 'password';
                field.setAttribute('type', type);
            });
        });
    });
    </script>
</body>
</html>


