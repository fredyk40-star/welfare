<?php
require_once __DIR__ . '/../includes/header.php';

// Check if user is logged in
if (!isLoggedIn()) {
    redirectTo('/member/login.php');
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

$success = '';
$error = '';
$csrf_token = generateCsrfToken();
$new_2fa_secret = null;

// Get current user settings early so POST handlers can reference current state
$user_query = "SELECT email, two_fa_secret FROM members WHERE member_id = :member_id";
$user_stmt = $db->prepare($user_query);
$user_stmt->execute([':member_id' => $user_id]);
$user = $user_stmt->fetch();

// Change Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Get current password hash
        $user_query = "SELECT password FROM members WHERE member_id = :member_id";
        $user_stmt = $db->prepare($user_query);
        $user_stmt->execute([':member_id' => $user_id]);
        $user = $user_stmt->fetch();
        
        if (!password_verify($current_password, $user['password'])) {
            $error = 'Current password is incorrect.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match.';
        } elseif (($password_validation = validatePassword($new_password)) !== true) {
            $error = $password_validation;
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
            
            $update_query = "UPDATE members SET password = :password WHERE member_id = :member_id";
            $update_stmt = $db->prepare($update_query);
            
            try {
                $update_stmt->execute([
                    ':password' => $hashed_password,
                    ':member_id' => $user_id
                ]);
                
                logAudit($user_id, 'Password changed');
                $success = 'Password changed successfully.';
            } catch (PDOException $e) {
                $error = 'Failed to update password.';
            }
        }
    }
}

// Toggle 2FA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_2fa') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $enable_2fa = isset($_POST['enable_2fa']);
        
        if ($enable_2fa) {
            $current_secret = $user['two_fa_secret'] ?? null;
            $two_fa_secret = empty($current_secret) ? bin2hex(random_bytes(16)) : $current_secret;
        } else {
            $two_fa_secret = null;
        }
        
        $update_query = "UPDATE members SET two_fa_secret = :secret WHERE member_id = :member_id";
        $update_stmt = $db->prepare($update_query);
        
        try {
            $update_stmt->execute([
                ':secret' => $two_fa_secret,
                ':member_id' => $user_id
            ]);
            
            logAudit($user_id, $enable_2fa ? '2FA enabled' : '2FA disabled');
            $success = $enable_2fa ? 'Two-factor authentication enabled.' : 'Two-factor authentication disabled.';
            $new_2fa_secret = $enable_2fa ? $two_fa_secret : null;
        } catch (PDOException $e) {
            $error = 'Failed to update 2FA settings.';
        }
    }
}

?>

<div class="row">
    <div class="col-md-8 mx-auto">
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Change Password -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Change Password</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="passwordForm">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" 
                               placeholder="8-255 characters, include uppercase, lowercase, number, and special character" required>
                        <small class="text-muted">Password must be 8-255 characters with uppercase, lowercase, number, and special character</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </form>
            </div>
        </div>
        
        <!-- Two-Factor Authentication -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Two-Factor Authentication (2FA)</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="toggle_2fa">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="enable_2fa" name="enable_2fa" 
                                   <?php echo !empty($user['two_fa_secret']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="enable_2fa">
                                Enable Two-Factor Authentication
                            </label>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <strong>Current Status:</strong> 
                        <?php echo !empty($user['two_fa_secret']) ? 
                            '<span class="text-success">Enabled</span>' : 
                            '<span class="text-danger">Disabled</span>'; ?>
                    </div>
                    
                    <?php if (!empty($new_2fa_secret)): ?>
                    <div class="alert alert-success">
                        <strong>Your 2FA Secret (save this now):</strong>
                        <div class="input-group mt-2">
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($new_2fa_secret); ?>" readonly id="twoFASecret">
                            <button class="btn btn-outline-secondary" type="button" id="copy2FASecretBtn">Copy</button>
                        </div>
                        <div class="text-center mt-3">
                            <div class="qrcode-canvas" data-otpauth="<?php echo htmlspecialchars(getTOTPQRCodeUrl($new_2fa_secret, $user['email'], 'GYF Welfare', 200)); ?>" style="display:inline-block;width:200px;height:200px;"></div>
                            <br><small class="text-muted">Scan this QR code with Google Authenticator, Authy, or any TOTP app</small>
                        </div>
                        <small class="text-muted">Enter this secret in your authenticator app. This will only be shown once.</small>
                    </div>
                    <?php elseif (!empty($user['two_fa_secret'])): ?>
                    <div class="alert alert-info">
                        <strong>2FA is enabled.</strong>
                        <div class="text-center mt-2">
                            <div class="qrcode-canvas" data-otpauth="<?php echo htmlspecialchars(getTOTPQRCodeUrl($user['two_fa_secret'], $user['email'], 'GYF Welfare', 200)); ?>" style="display:inline-block;width:200px;height:200px;"></div>
                            <br><small class="text-muted">Scan this QR code to set up a new device</small>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <button type="submit" class="btn btn-warning">Update 2FA Settings</button>
                </form>
            </div>
        </div>
        
        <!-- Account Information -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Account Information</h5>
            </div>
            <div class="card-body">
                <table class="table">
                    <tr>
                        <td><strong>Member ID:</strong></td>
                        <td><?php echo htmlspecialchars($_SESSION['user_id']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Email:</strong></td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Account Type:</strong></td>
                        <td><?php echo htmlspecialchars(ucfirst($_SESSION['user_type'])); ?></td>
                    </tr>
                </table>
                
                <?php if (isMember()): ?>
                    <form method="POST" action="<?php echo APP_URL; ?>/member/forgot-password.php" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                        <input type="hidden" name="member_id" value="<?php echo htmlspecialchars($_SESSION['user_id']); ?>">
                        <button type="submit" class="btn btn-outline-warning">
                            🔑 Send Password Reset Email
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo CSP_NONCE; ?>">
function copy2FASecret() {
    const input = document.getElementById('twoFASecret');
    if (!input) return;
    input.select();
    navigator.clipboard.writeText(input.value).then(() => {
        showToast('2FA secret copied to clipboard', 'success');
    }).catch(() => {
        document.execCommand('copy');
        showToast('2FA secret copied to clipboard', 'success');
    });
}

document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
    const newPwd = document.getElementById('new_password');
    const confirmPwd = document.getElementById('confirm_password');
    if (newPwd && confirmPwd && newPwd.value !== confirmPwd.value) {
        e.preventDefault();
        showToast('New passwords do not match.', 'warning');
    }
});

document.addEventListener('DOMContentLoaded', function() {
    var copyBtn = document.getElementById('copy2FASecretBtn');
    if (copyBtn) {
        copyBtn.addEventListener('click', copy2FASecret);
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

