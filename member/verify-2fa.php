<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    redirectTo('/member/dashboard.php');
}

if (!isset($_SESSION['temp_user'])) {
    redirectTo('/member/login.php');
}

$error = '';
$csrf_token = generateCsrfToken();

$user = $_SESSION['temp_user'];
$database = new Database();
$db = $database->getConnection();
$stmt = $db->prepare("SELECT two_fa_secret FROM members WHERE member_id = :member_id");
$stmt->execute([':member_id' => $user['member_id']]);
$dbUser = $stmt->fetch();
$secret = $dbUser ? $dbUser['two_fa_secret'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } elseif (!checkRateLimit($_SESSION['temp_user']['member_id'], 5, 300, '%Failed 2FA%')) {
        $error = 'Too many verification attempts. Please try again later.';
        logAudit($_SESSION['temp_user']['member_id'] ?? 'system', 'Member 2FA rate limit exceeded');
    } else {
        $code = cleanInput($_POST['code']);
        
        if (empty($code)) {
            $error = 'Please enter the verification code.';
        } else {
            $user = $_SESSION['temp_user'];
            $database = new Database();
            $db = $database->getConnection();
            $stmt = $db->prepare("SELECT two_fa_secret, passport_photo FROM members WHERE member_id = :member_id");
            $stmt->execute([':member_id' => $user['member_id']]);
            $dbUser = $stmt->fetch();
            $code = preg_replace('/[^0-9]/', '', $code);
            
            if (verifyTOTP($secret, $code)) {
                $_SESSION['user_id'] = $user['member_id'];
                $_SESSION['user_type'] = 'member';
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['photo'] = $dbUser ? $dbUser['passport_photo'] : '';
                
                unset($_SESSION['temp_user']);
                
                session_regenerate_id(true);
                logAudit($user['member_id'], 'Member 2FA login completed');
                redirectTo('/member/dashboard.php');
            } else {
                $error = 'Invalid verification code.';
                logAudit($user['member_id'], 'Failed 2FA verification attempt');
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
    <title>Two-Factor Authentication - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/bootstrap/css/bootstrap.min.css">
    <script src="<?php echo APP_URL; ?>/assets/js/header-common.js"></script>
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
</head>
<body>
    <div class="bg-slideshow" id="bgSlideshow"></div>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header text-center">
                        <h3>Two-Factor Authentication</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <p class="text-center">Please enter the 6-digit verification code sent to your device.</p>
                        
                        <form method="POST" action="" id="twoFAForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <div class="mb-3">
                                <label for="code" class="form-label">Verification Code</label>
                                <input type="text" class="form-control" id="code" name="code" 
                                       placeholder="000000" maxlength="6" required autocomplete="off">
                            </div>
                            <button type="submit" class="btn btn-primary w-100" id="verifyButton">
                                <span id="verifyText">Verify</span>
                                <span id="verifySpinner" class="spinner-border spinner-border-sm d-none" role="status"></span>
                            </button>
                                                    <div class="text-center mt-3">
                            <div class="qrcode-canvas" data-otpauth="<?php echo htmlspecialchars(getTOTPQRCodeUrl($secret, $_SESSION['temp_user']['email'], 'GYF Welfare', 200)); ?>" style="display:inline-block;width:200px;height:200px;"></div>
                            <br><small class="text-muted">Scan this QR code with Google Authenticator, Authy, or any TOTP app</small>
                        </div>
                            <div class="text-center mt-3">
                                <a href="<?php echo APP_URL; ?>/member/login.php" class="text-muted">&larr; Back to Login</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script nonce="<?php echo CSP_NONCE; ?>">
        const APP_URL = '<?php echo APP_URL; ?>';
    </script>
    <script src="<?php echo APP_URL; ?>/assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/qrcode.min.js"></script>  
     <script src="<?php echo APP_URL; ?>/assets/js/main.js"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/slideshow.js"></script>
    <script nonce="<?php echo CSP_NONCE; ?>">
    document.getElementById('twoFAForm').addEventListener('submit', function(e) {
        const verifyButton = document.getElementById('verifyButton');
        const verifyText = document.getElementById('verifyText');
        const verifySpinner = document.getElementById('verifySpinner');
        const code = document.getElementById('code').value.trim();
        
        if (!code || code.length !== 6) {
            e.preventDefault();
            alert('Please enter a valid 6-digit verification code.');
            return false;
        }
        
        if (!navigator.onLine) {
            e.preventDefault();
            alert('Internet connection required. Please check your connection and try again.');
            return false;
        }
        
        verifyButton.disabled = true;
        verifyText.classList.add('d-none');
        verifySpinner.classList.remove('d-none');
    });

    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('code').focus();
    });
    </script>
</body>
</html>

