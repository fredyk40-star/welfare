<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

if (isLoggedIn()) {
    redirectTo('/member/dashboard.php');
}

$error = '';
$success = '';
$csrf_token = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $input = strtolower(trim(sanitizeInput($_POST['identifier'] ?? $_POST['member_id'] ?? '')));
        
        if (empty($input)) {
            $error = 'Please enter your Member ID or Email address.';
        } else {
            $database = new Database();
            $db = $database->getConnection();
            
            // Determine if input is email or member ID
            if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
                // Treasurer flow: lookup by email (treasurer account)
                $query = "SELECT member_id, full_name, email FROM members 
                          WHERE LOWER(email) = :email AND member_id = 'GYF-ADMIN'";
                $stmt = $db->prepare($query);
                $stmt->execute([':email' => $input]);
            } else {
                // Member flow: lookup by member ID
                $query = "SELECT member_id, full_name, email FROM members 
                          WHERE member_id = :member_id AND member_id != 'GYF-ADMIN'";
                $stmt = $db->prepare($query);
                $stmt->execute([':member_id' => strtoupper($input)]);
            }
            
            $user = $stmt->fetch();
            
            if ($user) {
                // Check if there's already a valid token
                $check_query = "SELECT id FROM password_resets 
                                WHERE member_id = :member_id AND expires_at > NOW()";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->execute([':member_id' => $user['member_id']]);
                
                if ($check_stmt->rowCount() > 0) {
                    $error = 'A password reset link has already been sent. Please check your email or wait for the current link to expire.';
                } else {
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', time() + 3600);
                    
                    $token_query = "INSERT INTO password_resets (member_id, token, expires_at) 
                                    VALUES (:member_id, :token, :expires)";
                    $token_stmt = $db->prepare($token_query);
                    $token_stmt->execute([
                        ':member_id' => $user['member_id'],
                        ':token' => $token,
                        ':expires' => $expires
                    ]);
                    
                    logAudit($user['member_id'], 'Password reset requested');
                    
                    $reset_link = APP_URL . '/member/reset-password.php?token=' . $token;
                    $subject = 'Password Reset - ' . APP_NAME;
                    $safe_full_name = sanitizeEmailValue($user['full_name']);
                    $message = "
                    <html>
                    <head>
                        <title>Password Reset</title>
                    </head>
                    <body>
                        <p>Hello " . htmlspecialchars($safe_full_name) . ",</p>
                        <p>You requested a password reset for your " . APP_NAME . " account.</p>
                        <p>Click the link below to reset your password:</p>
                        <p><a href='$reset_link'>$reset_link</a></p>
                        <p>This link will expire in 1 hour.</p>
                        <p>If you did not request this reset, please ignore this email.</p>
                        <p>Regards,<br>" . APP_NAME . "</p>
                    </body>
                    </html>
                    ";
                    
                    if (sendEmail($user['email'], $subject, $message)) {
                        $success = 'Password reset link has been sent to your email address. Please check your inbox.';
                    } else {
                        $success = "Password reset link generated. <br><br>";
                        $success .= "<strong>Note:</strong> Email could not be sent. <a href='$reset_link'>Click here to reset your password</a>";
                    }
                }
            } else {
                if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
                    $error = 'No treasurer account found with that email address.';
                } else {
                    $error = 'No member found with that Member ID.';
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
    <title>Forgot Password - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header text-center">
                        <h3>Forgot Password</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <?php echo $success; ?>
                                <br>
                                <a href="login.php" class="btn btn-primary mt-3">Back to Login</a>
                            </div>
                        <?php else: ?>
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <div class="mb-3">
                                    <label for="identifier" class="form-label">Member ID or Email Address</label>
                                    <input type="text" class="form-control" id="identifier" name="identifier" 
                                           placeholder="Enter your Member ID (e.g., GYF-123456) or Email" required>
                                    <small class="text-muted">
                                        Members: Enter your Member ID<br>
                                        Treasurer: Enter your registered email address
                                    </small>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Send Reset Link</button>
                            </form>
                            <div class="mt-3 text-center">
                                <a href="login.php" class="text-decoration-none">Back to Login</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="<?php echo APP_URL; ?>/assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
