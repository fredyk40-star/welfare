<?php
require_once __DIR__ . '/../includes/header.php';

// Check if user is treasurer
if (!isTreasurer()) {
    redirectTo('/member/login.php');
}

$database = new Database();
$db = $database->getConnection();
$treasurer_id = $_SESSION['user_id'];

$success = '';
$error = '';
$csrf_token = generateCsrfToken();
$new_2fa_secret = null;

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } elseif (!checkRateLimit($treasurer_id, 10, 300, '%profile update%')) {
        $error = 'Too many profile update attempts. Please try again later.';
        logAudit($treasurer_id, 'Profile update rate limit exceeded');
    } else {
        // Update Email
        if ($action === 'update_email') {
            $new_email = cleanInput($_POST['email']);
            $password = $_POST['password'];
            
            if (empty($new_email) || empty($password)) {
                $error = 'Please fill in all fields.';
            } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email format.';
            } else {
                $user_query = "SELECT password FROM members WHERE member_id = :member_id";
                $user_stmt = $db->prepare($user_query);
                $user_stmt->execute([':member_id' => $treasurer_id]);
                $user = $user_stmt->fetch();
                
                if (!password_verify($password, $user['password'])) {
                    $error = 'Password is incorrect.';
                } else {
                    $check_query = "SELECT id FROM members WHERE LOWER(email) = LOWER(:email) AND member_id != :member_id";
                    $check_stmt = $db->prepare($check_query);
                    $check_stmt->execute([':email' => $new_email, ':member_id' => $treasurer_id]);
                    
                    if ($check_stmt->rowCount() > 0) {
                        $error = 'Email is already in use by another member.';
                    } else {
                        $update_query = "UPDATE members SET email = :email WHERE member_id = :member_id";
                        $update_stmt = $db->prepare($update_query);
                        
                        try {
                            $update_stmt->execute([
                                ':email' => $new_email,
                                ':member_id' => $treasurer_id
                            ]);
                            
                            $_SESSION['email'] = $new_email;
                            logAudit($treasurer_id, 'Email updated');
                            $success = 'Email updated successfully.';
                        } catch (PDOException $e) {
                            $error = 'Failed to update email.';
                        }
                    }
                }
            }
        }
        
        // Change Password
        if ($action === 'change_password') {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $error = 'Please fill in all fields.';
            } elseif ($new_password !== $confirm_password) {
                $error = 'New passwords do not match.';
            } elseif (($password_validation = validatePassword($new_password)) !== true) {
                $error = $password_validation;
            } else {
                $user_query = "SELECT password FROM members WHERE member_id = :member_id";
                $user_stmt = $db->prepare($user_query);
                $user_stmt->execute([':member_id' => $treasurer_id]);
                $user = $user_stmt->fetch();
                
                if (!password_verify($current_password, $user['password'])) {
                    $error = 'Current password is incorrect.';
                } else {
                    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                    
                    $update_query = "UPDATE members SET password = :password WHERE member_id = :member_id";
                    $update_stmt = $db->prepare($update_query);
                    
                    try {
                        $update_stmt->execute([
                            ':password' => $hashed_password,
                            ':member_id' => $treasurer_id
                        ]);
                        
                        logAudit($treasurer_id, 'Password changed');
                        $success = 'Password changed successfully.';
                    } catch (PDOException $e) {
                        $error = 'Failed to update password.';
                    }
                }
            }
        }
        
        // Update Phone Number
        if ($action === 'update_phone') {
            $country_code = cleanInput($_POST['country_code'] ?? '+233');
            $phone_raw = cleanInput($_POST['phone'] ?? '');

            // Normalize: allow spaces/dashes/parentheses in input, but validate digits only
            $phone_digits = preg_replace('/\D/', '', $phone_raw);

            if ($phone_raw === '') {
                $error = 'Please enter a phone number.';
            } elseif (strpos($phone_raw, '+') !== false) {
                $error = 'Please enter the phone number without the country code.';
            } elseif (strlen($phone_digits) < 7 || strlen($phone_digits) > 15) {
                $error = 'Phone number must be 7 to 15 digits.';
            } elseif (!in_array($country_code, ['+233','+1','+44','+27','+234','+254','+255','+256','+265','+260','+263','+258','+257','+250','+221','+223','+226','+229','+224','+225','+220','+232','+231'], true)) {
                $error = 'Invalid country code selected.';
            } else {
                $new_phone = $country_code . ' ' . $phone_digits;
                $check_query = "SELECT id FROM members WHERE phone = :phone AND member_id != :member_id";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->execute([':phone' => $new_phone, ':member_id' => $treasurer_id]);

                if ($check_stmt->rowCount() > 0) {
                    $error = 'Phone number already registered to another member.';
                } else {
                    $update_query = "UPDATE members SET phone = :phone WHERE member_id = :member_id";
                    $update_stmt = $db->prepare($update_query);
                    try {
                        $update_stmt->execute([
                            ':phone' => $new_phone,
                            ':member_id' => $treasurer_id
                        ]);
                        logAudit($treasurer_id, 'Phone number updated');
                        $success = 'Phone number updated successfully.';
                    } catch (PDOException $e) {
                        $error = 'Failed to update phone number.';
                        error_log('Treasurer phone update error: ' . $e->getMessage());
                    }
                }
            }
        }

        // Toggle 2FA
        if ($action === 'toggle_2fa') {
            $enable_2fa = !empty($_POST['enable_2fa']);
            
            // Get current 2FA status
            $current_query = "SELECT two_fa_secret FROM members WHERE member_id = :member_id";
            $current_stmt = $db->prepare($current_query);
            $current_stmt->execute([':member_id' => $treasurer_id]);
            $current = $current_stmt->fetch();
            $existing_secret = $current ? $current['two_fa_secret'] : null;
            
            // Only generate a new secret when enabling and none exists
            if ($enable_2fa && empty($existing_secret)) {
                $new_2fa_secret = bin2hex(random_bytes(16));
                $two_fa_secret = $new_2fa_secret;
            } elseif (!$enable_2fa) {
                $two_fa_secret = null;
            } else {
                $two_fa_secret = $existing_secret;
            }
            
            $update_query = "UPDATE members SET two_fa_secret = :secret WHERE member_id = :member_id";
            $update_stmt = $db->prepare($update_query);
            
            try {
                $update_stmt->execute([
                    ':secret' => $two_fa_secret,
                    ':member_id' => $treasurer_id
                ]);
                
                logAudit($treasurer_id, $enable_2fa ? '2FA enabled' : '2FA disabled');
                $success = $enable_2fa ? 'Two-factor authentication enabled.' : 'Two-factor authentication disabled.';
            } catch (PDOException $e) {
                $error = 'Failed to update 2FA settings.';
            }
        }
    }
}

// Get treasurer details
$treasurer_query = "SELECT * FROM members WHERE member_id = :member_id";
$treasurer_stmt = $db->prepare($treasurer_query);
$treasurer_stmt->execute([':member_id' => $treasurer_id]);
$treasurer = $treasurer_stmt->fetch();

// Split stored phone ("+233 123456789") into country code + raw digits for the form
$current_phone = $treasurer['phone'] ?? '';
$current_country_code = '+233';
$current_phone_raw = $current_phone;
if (preg_match('/^(\+\d+)\s+(.*)$/', $current_phone, $m)) {
    $current_country_code = $m[1];
    $current_phone_raw = $m[2];
}

// Get activity statistics
$activity_query = "SELECT COUNT(*) as total_logs FROM audit_logs WHERE user_id = :user_id";
$activity_stmt = $db->prepare($activity_query);
$activity_stmt->execute([':user_id' => $treasurer_id]);
$total_activities = $activity_stmt->fetch()['total_logs'];

$today_query = "SELECT COUNT(*) as today_logs FROM audit_logs 
                WHERE user_id = :user_id AND DATE(timestamp) = CURDATE()";
$today_stmt = $db->prepare($today_query);
$today_stmt->execute([':user_id' => $treasurer_id]);
$today_activities = $today_stmt->fetch()['today_logs'];

// Get recent audit logs
$logs_query = "SELECT * FROM audit_logs WHERE user_id = :user_id ORDER BY timestamp DESC LIMIT 10";
$logs_stmt = $db->prepare($logs_query);
$logs_stmt->execute([':user_id' => $treasurer_id]);
$recent_logs = $logs_stmt->fetchAll();
?>

<div class="row">
    <div class="col-md-8 mx-auto">
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Profile Overview -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Treasurer Profile</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 text-center">
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" 
                             style="width: 100px; height: 100px; font-size: 40px;">
                            👤
                        </div>
                        <h5><?php echo htmlspecialchars($treasurer['full_name']); ?></h5>
                        <p class="text-muted">System Treasurer</p>
                        <span class="badge bg-success">Active</span>
                    </div>
                    <div class="col-md-8">
                        <table class="table">
                            <tr>
                                <td><strong>Member ID:</strong></td>
                                <td><?php echo htmlspecialchars($treasurer['member_id']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Email:</strong></td>
                                <td><?php echo htmlspecialchars($treasurer['email']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Phone:</strong></td>
                                <td><?php echo htmlspecialchars($treasurer['phone']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Account Created:</strong></td>
                                <td><?php echo !empty($treasurer['created_at']) ? htmlspecialchars(date('F j, Y', strtotime($treasurer['created_at']))) : 'N/A'; ?></td>
                            </tr>
                            <tr>
                                <td><strong>2FA Status:</strong></td>
                                <td>
                                    <?php if (!empty($treasurer['two_fa_secret'])): ?>
                                        <span class="badge bg-success">Enabled</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Disabled</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Last Login:</strong></td>
                                <td>
                                    <?php
                                    $last_login_query = "SELECT timestamp FROM audit_logs 
                                                        WHERE user_id = :user_id AND action LIKE '%login%success%' 
                                                        ORDER BY timestamp DESC LIMIT 1";
                                    $last_login_stmt = $db->prepare($last_login_query);
                                    $last_login_stmt->execute([':user_id' => $treasurer_id]);
                                    $last_login = $last_login_stmt->fetch();
                                    echo $last_login && !empty($last_login['timestamp']) ? date('F j, Y g:i A', strtotime($last_login['timestamp'])) : 'First login';
                                    ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Activity Statistics -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="stat-card blue text-center">
                    <h6>Total Activities</h6>
                    <h3><?php echo $total_activities; ?></h3>
                    <small>All time actions</small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stat-card text-center">
                    <h6>Today's Activities</h6>
                    <h3><?php echo $today_activities; ?></h3>
                    <small>Actions performed today</small>
                </div>
            </div>
        </div>
        
        <!-- Update Email -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Update Email Address</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_email">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">New Email Address</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?php echo htmlspecialchars($treasurer['email']); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Enter your password to confirm" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Update Email</button>
                </form>
            </div>
        </div>
        
        <!-- Update Phone Number -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Update Phone Number</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_phone">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="country_code" class="form-label">Country Code</label>
                            <select class="form-control" id="country_code" name="country_code" required>
                                <option value="+233" <?php echo $current_country_code === '+233' ? 'selected' : ''; ?>>Ghana (+233)</option>
                                <option value="+1" <?php echo $current_country_code === '+1' ? 'selected' : ''; ?>>USA (+1)</option>
                                <option value="+44" <?php echo $current_country_code === '+44' ? 'selected' : ''; ?>>UK (+44)</option>
                                <option value="+27" <?php echo $current_country_code === '+27' ? 'selected' : ''; ?>>South Africa (+27)</option>
                                <option value="+234" <?php echo $current_country_code === '+234' ? 'selected' : ''; ?>>Nigeria (+234)</option>
                                <option value="+254" <?php echo $current_country_code === '+254' ? 'selected' : ''; ?>>Kenya (+254)</option>
                                <option value="+255" <?php echo $current_country_code === '+255' ? 'selected' : ''; ?>>Tanzania (+255)</option>
                                <option value="+256" <?php echo $current_country_code === '+256' ? 'selected' : ''; ?>>Uganda (+256)</option>
                                <option value="+265" <?php echo $current_country_code === '+265' ? 'selected' : ''; ?>>Malawi (+265)</option>
                                <option value="+260" <?php echo $current_country_code === '+260' ? 'selected' : ''; ?>>Zambia (+260)</option>
                                <option value="+263" <?php echo $current_country_code === '+263' ? 'selected' : ''; ?>>Zimbabwe (+263)</option>
                                <option value="+258" <?php echo $current_country_code === '+258' ? 'selected' : ''; ?>>Mozambique (+258)</option>
                                <option value="+257" <?php echo $current_country_code === '+257' ? 'selected' : ''; ?>>Burundi (+257)</option>
                                <option value="+250" <?php echo $current_country_code === '+250' ? 'selected' : ''; ?>>Rwanda (+250)</option>
                                <option value="+221" <?php echo $current_country_code === '+221' ? 'selected' : ''; ?>>Senegal (+221)</option>
                                <option value="+223" <?php echo $current_country_code === '+223' ? 'selected' : ''; ?>>Mali (+223)</option>
                                <option value="+226" <?php echo $current_country_code === '+226' ? 'selected' : ''; ?>>Burkina Faso (+226)</option>
                                <option value="+229" <?php echo $current_country_code === '+229' ? 'selected' : ''; ?>>Benin (+229)</option>
                                <option value="+224" <?php echo $current_country_code === '+224' ? 'selected' : ''; ?>>Guinea (+224)</option>
                                <option value="+225" <?php echo $current_country_code === '+225' ? 'selected' : ''; ?>>Ivory Coast (+225)</option>
                                <option value="+220" <?php echo $current_country_code === '+220' ? 'selected' : ''; ?>>Gambia (+220)</option>
                                <option value="+232" <?php echo $current_country_code === '+232' ? 'selected' : ''; ?>>Sierra Leone (+232)</option>
                                <option value="+231" <?php echo $current_country_code === '+231' ? 'selected' : ''; ?>>Liberia (+231)</option>
                            </select>
                        </div>
                        <div class="col-md-9 mb-3">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="phone" name="phone"
                                   value="<?php echo htmlspecialchars($current_phone_raw, ENT_QUOTES, 'UTF-8'); ?>" placeholder="XX XXX XXXX" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Update Phone</button>
                </form>
            </div>
        </div>
        
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
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <button type="submit" class="btn btn-warning">Change Password</button>
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
                                   <?php echo !empty($treasurer['two_fa_secret']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="enable_2fa">
                                Enable Two-Factor Authentication
                            </label>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <strong>Current Status:</strong> 
                        <?php echo !empty($treasurer['two_fa_secret']) ? 
                            '<span class="text-success">Enabled - Your account is protected with 2FA</span>' : 
                            '<span class="text-danger">Disabled - Enable 2FA for enhanced security</span>'; ?>
                    </div>
                    
                    <?php if (!empty($new_2fa_secret)): ?>
                    <div class="alert alert-success">
                        <strong>Your 2FA Secret (save this now):</strong>
                        <div class="input-group mt-2">
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($new_2fa_secret); ?>" readonly id="twoFASecret">
                            <button class="btn btn-outline-secondary" type="button" id="copy2FASecretBtn">Copy</button>
                        </div>
                        <div class="text-center mt-3">
                            <div class="qrcode-canvas" data-otpauth="<?php echo htmlspecialchars(getTOTPQRCodeUrl($new_2fa_secret, $treasurer['email'], 'GYF Welfare', 200)); ?>" style="display:inline-block;width:200px;height:200px;"></div>
                            <br><small class="text-muted">Scan this QR code with Google Authenticator, Authy, or any TOTP app</small>
                        </div>
                        <small class="text-muted">Enter this secret in your authenticator app. This will only be shown once.</small>
                    </div>
                    <?php elseif (!empty($treasurer['two_fa_secret'])): ?>
                    <div class="alert alert-info">
                        <strong>2FA is enabled.</strong>
                        <div class="text-center mt-2">
                            <div class="qrcode-canvas" data-otpauth="<?php echo htmlspecialchars(getTOTPQRCodeUrl($treasurer['two_fa_secret'], $treasurer['email'], 'GYF Welfare', 200)); ?>" style="display:inline-block;width:200px;height:200px;"></div>
                            <br><small class="text-muted">Scan this QR code to set up a new device</small>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <button type="submit" class="btn btn-info text-white">Update 2FA Settings</button>
                </form>
            </div>
        </div>
        
        <!-- Recent Activity Log -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Recent Activity Log</h5>
            </div>
            <div class="card-body">
                <div class="table-scroll-wrapper">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Action</th>
                                <th>IP Address</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_logs as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['action']); ?></td>
                                    <td><small><?php echo htmlspecialchars($log['ip_address']); ?></small></td>
                                    <td><small><?php echo !empty($log['timestamp']) ? htmlspecialchars(date('M d, Y g:i A', strtotime($log['timestamp']))) : 'N/A'; ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recent_logs)): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted">No recent activity</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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

