<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

if (!isLoggedIn() || !isset($_SESSION['temp_user']) || ($_SESSION['temp_user_type'] ?? '') !== 'treasurer') {
    redirectTo('/treasurer/login.php');
}

$error = '';
$success = '';
$csrf_token = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $code = sanitizeInput($_POST['code']);
        
        if (empty($code)) {
            $error = 'Please enter the verification code.';
        } else {
            $user = $_SESSION['temp_user'];
            $secret = $user['two_fa_secret'];
            
            if (strlen($code) === 6 && ctype_digit($code)) {
                $_SESSION['user_id'] = $user['member_id'];
                $_SESSION['user_type'] = 'treasurer';
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                
                unset($_SESSION['temp_user'], $_SESSION['temp_user_type']);
                
                session_regenerate_id(true);
                logAudit($user['member_id'], 'Treasurer 2FA verification successful');
                redirectTo('/treasurer/dashboard.php');
            } else {
                $error = 'Invalid verification code.';
                logAudit($user['member_id'], 'Failed treasurer 2FA verification attempt');
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
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
</head>
<body class="bg-light">
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
                        
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <div class="mb-3">
                                <label for="code" class="form-label">Verification Code</label>
                                <input type="text" class="form-control" id="code" name="code" 
                                       placeholder="000000" maxlength="6" required autocomplete="off">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Verify</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="<?php echo APP_URL; ?>/assets/bootstrap/js/bootstrap.bundle.min.js"></script>

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
</body></body>
</html>
