<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/blob_storage.php';
require_once __DIR__ . '/remember_me.php';

// --- Serverless-safe sessions ---
// Default PHP file sessions are stored on the ephemeral, non-shared local
// disk on Vercel serverless. Each request can hit a different instance with
// no session file, so isLoggedIn() returns false and the user is logged out
// at random ("logs them out instances"). Persisting session data in MySQL
// keeps it consistent across every invocation/instance.
if (!function_exists('registerDatabaseSessionHandler')) {
    function registerDatabaseSessionHandler() {
        $pdo = null;
        $ensureTable = function () use (&$pdo) {
            if ($pdo === null) {
                $database = new Database();
                $pdo = $database->getConnection();
            }
            static $created = false;
            if ($created) {
                return $pdo;
            }
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS sessions (" .
                "id VARCHAR(128) NOT NULL PRIMARY KEY, " .
                "data MEDIUMTEXT, " .
                "last_activity INT UNSIGNED NOT NULL, " .
                "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP" .
                ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $created = true;
            return $pdo;
        };

        session_set_save_handler(
            function () use ($ensureTable) { $ensureTable(); return true; }, // open
            function () { return true; },                                    // close
            function ($id) use ($ensureTable) {                              // read
                $db = $ensureTable();
                $stmt = $db->prepare("SELECT data FROM sessions WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();
                return $row ? (string) $row['data'] : '';
            },
            function ($id, $data) use ($ensureTable) {                      // write
                $db = $ensureTable();
                $stmt = $db->prepare(
                    "REPLACE INTO sessions (id, data, last_activity) VALUES (:id, :data, :la)"
                );
                $stmt->execute([':id' => $id, ':data' => $data, ':la' => time()]);
                return true;
            },
            function ($id) use ($ensureTable) {                             // destroy
                $db = $ensureTable();
                $stmt = $db->prepare("DELETE FROM sessions WHERE id = :id");
                $stmt->execute([':id' => $id]);
                return true;
            },
            function ($maxlifetime) use ($ensureTable) {                   // gc
                $db = $ensureTable();
                $stmt = $db->prepare("DELETE FROM sessions WHERE last_activity < :cut");
                $stmt->execute([':cut' => time() - (int) $maxlifetime]);
                return true;
            },
            function () { return true; },                                   // create_sid
            function ($id) use ($ensureTable) {                             // validate_sid
                $db = $ensureTable();
                $stmt = $db->prepare("SELECT 1 FROM sessions WHERE id = :id");
                $stmt->execute([':id' => $id]);
                return (bool) $stmt->fetch();
            }
        );
    }
}

registerDatabaseSessionHandler();

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => SESSION_TIMEOUT,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// Silent re-auth: if the server-side session was lost but a valid remember-me
// cookie exists, restore the session instead of bouncing the user to login.
autoLoginWithRememberMe();

// JSON error response helper
function jsonError($message, $httpCode = 400) {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

// Server-side idle timeout enforcement
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    header('Location: ' . APP_URL . '/member/login.php?timeout=1');
    exit();
}
$_SESSION['last_activity'] = time();

// Security Functions
function cleanInput($data) {
    $data = trim($data);
    return $data;
}

function escapeForHtml($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

function sanitizeInput($data) {
    return cleanInput($data);
}

function generateMemberID() {
    return 'GYF-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

function generateReceiptNumber() {
    return 'RCP-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
}

function validatePassword($password) {
    $min_length = PASSWORD_MIN_LENGTH;
    $max_length = PASSWORD_MAX_LENGTH;
    
    if (strlen($password) < $min_length || strlen($password) > $max_length) {
        return "Password must be between {$min_length} and {$max_length} characters";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        return "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        return "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        return "Password must contain at least one number";
    }
    
    if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
        return "Password must contain at least one special character";
    }
    
    return true;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_type']);
}

function isTreasurer() {
    return isLoggedIn() && $_SESSION['user_type'] === 'treasurer';
}

function isMember() {
    return isLoggedIn() && $_SESSION['user_type'] === 'member';
}

function checkOnlineStatus() {
    $connected = @fsockopen("www.google.com", 80, $errno, $errstr, 3);
    if ($connected) {
        fclose($connected);
        return true;
    }
    return false;
}

// Login history tracking
function recordLoginAttempt($user_id, $user_type, $status, $ip_address = null, $user_agent = null) {
    if (!$ip_address) {
        $ip_address = getClientIp();
    }
    if (!$user_agent) {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "INSERT INTO login_history (user_id, user_type, ip_address, user_agent, login_time, status) 
              VALUES (:user_id, :user_type, :ip_address, :user_agent, NOW(), :status)";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':user_id' => $user_id,
        ':user_type' => $user_type,
        ':ip_address' => $ip_address,
        ':user_agent' => $user_agent,
        ':status' => $status
    ]);
}

function recordLogout($user_id) {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "UPDATE login_history SET logout_time = NOW() 
              WHERE user_id = :user_id AND logout_time IS NULL 
              ORDER BY login_time DESC LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute([':user_id' => $user_id]);
}

function getLoginHistory($user_id, $limit = 10) {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT * FROM login_history 
              WHERE user_id = :user_id 
              ORDER BY login_time DESC 
              LIMIT :limit";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $user_id);
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll();
}

// Security tips for users
function getSecurityTips() {
    return [
        'Enable 2FA' => 'Two-factor authentication adds an extra layer of security to your account.',
        'Strong Password' => 'Use a mix of uppercase, lowercase, numbers, and special characters.',
        'Unique Password' => 'Never reuse passwords across different websites or services.',
        'Regular Updates' => 'Update your password every 3-6 months for better security.',
        'Secure Connection' => 'Always ensure you\'re on the correct website before entering credentials.',
        'Logout' => 'Always logout when using shared or public computers.',
        'Report Suspicious Activity' => 'Contact support immediately if you notice unusual account activity.',
        'Backup Codes' => 'Save your 2FA backup codes in a secure location.'
    ];
}

function logAudit($user_id, $action, $db = null) {
    if (!$db) {
        $database = new Database();
        $db = $database->getConnection();
    }
    
    $ip_address = getClientIp();
    
    $query = "INSERT INTO audit_logs (user_id, action, ip_address) VALUES (:user_id, :action, :ip_address)";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':user_id' => $user_id,
        ':action' => $action,
        ':ip_address' => $ip_address
    ]);
}

// Strip characters used for email header/body injection (CRLF, bare LF/CR, NUL)
function sanitizeEmailValue($value) {
    if (!is_string($value)) {
        $value = (string) $value;
    }
    return str_replace(["\r", "\n", "\0"], '', $value);
}

function sendEmail($to, $subject, $message, $cc = null) {
    // Prevent header injection: recipient and subject must never contain CRLF/NUL
    $to = sanitizeEmailValue($to);
    $subject = sanitizeEmailValue($subject);

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('sendEmail aborted: invalid recipient "' . $to . '"');
        return false;
    }

    // Validate and sanitize any CC recipient(s)
    $cc_list = [];
    if ($cc !== null) {
        foreach ((array) $cc as $cc_addr) {
            $cc_addr = sanitizeEmailValue($cc_addr);
            if (filter_var($cc_addr, FILTER_VALIDATE_EMAIL)) {
                $cc_list[] = $cc_addr;
            }
        }
    }

    $api_key = getenv('RESEND_API_KEY');
    if (!$api_key) {
        error_log('sendEmail aborted: RESEND_API_KEY is not configured');
        return false;
    }

    $from_email = getenv('RESEND_FROM_EMAIL') ?: 'noreply@gyf.org';
    $from_name = sanitizeEmailValue(APP_NAME);
    $from = $from_name . ' <' . $from_email . '>';

    $payload = [
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'html' => $message
    ];
    if (!empty($cc_list)) {
        $payload['cc'] = $cc_list;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log('sendEmail cURL error: ' . $curl_error);
        return false;
    }

    if ($http_code >= 200 && $http_code < 300) {
        return true;
    }

    $error_message = 'sendEmail API error (HTTP ' . $http_code . '): ' . $response;
    if (strpos($response, 'domain is not verified') !== false) {
        $error_message .= ' | ACTION REQUIRED: Verify your sender domain in Resend dashboard (https://resend.com/domains)';
    } elseif (strpos($response, 'quota') !== false || strpos($response, 'rate limit') !== false) {
        $error_message .= ' | ACTION REQUIRED: Check your Resend account quota and billing';
    }
    error_log($error_message);
    return false;
}

function sendReceiptEmail($member_email, $receipt_data, $member_photo = null, $treasurer_email = null) {
    // Sanitize all recipient/header/body inputs to block injection
    $member_email = sanitizeEmailValue($member_email);
    $safe = [];
    foreach ($receipt_data as $key => $value) {
        $safe[$key] = sanitizeEmailValue($value);
    }
    // Re-apply HTML escaping for body safety
    $safe = array_map('htmlspecialchars', $safe);

    // Render the member's passport photo in the receipt (if available)
    $photo_html = '';
    if ($member_photo) {
        $photo_url = displayPhotoUrl($member_photo);
        if ($photo_url !== '') {
            $photo_html = '<img src="' . htmlspecialchars($photo_url, ENT_QUOTES, 'UTF-8') .
                '" alt="Member Photo" style="width:90px;height:90px;border-radius:50%;object-fit:cover;margin-bottom:10px;">';
        }
    }

    $subject = 'Payment Receipt - ' . APP_NAME;
    $message = ';
    <html>
    <head>
        <title>Payment Receipt</title>
        <style>
            body { font-family: Arial, sans-serif; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { text-align: center; padding: 20px; background: #1976d2; color: white; border-radius: 10px 10px 0 0; }
            .content { padding: 20px; background: #f9f9f9; border-radius: 0 0 10px 10px; }
            .receipt-details { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; }
            .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
            .total { font-size: 1.2em; font-weight: bold; color: #1976d2; }
            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 0.9em; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>GYF Welfare Management System</h2>
                <p>Payment Receipt Confirmation</p>
            </div>
            <div class="content">
                <div style="text-align: center;">
                    ' . $photo_html . '
                    <h3>Thank you for your payment!</h3>
                </div>
                
                <div class="receipt-details">
                    <div class="row">
                        <span>Receipt No:</span>
                        <strong>' . $safe['receipt_no'] . '</strong>
                    </div>
                    <div class="row">
                        <span>Member Name:</span>
                        <strong>' . $safe['member_name'] . '</strong>
                    </div>
                    <div class="row">
                        <span>Member ID:</span>
                        <strong>' . $safe['member_id'] . '</strong>
                    </div>
                    <div class="row">
                        <span>Amount:</span>
                        <strong class="total">GH₵ ' . number_format($receipt_data['amount'], 2) . '</strong>
                    </div>
                    <div class="row">
                        <span>Payment Method:</span>
                        <strong>' . $safe['payment_method'] . '</strong>
                    </div>
                    <div class="row">
                        <span>Billing Period:</span>
                        <strong>' . $safe['billing_period'] . '</strong>
                    </div>
                    <div class="row">
                        <span>Date:</span>
                        <strong>' . date('F j, Y g:i A', strtotime($receipt_data['date'])) . '</strong>
                    </div>
                </div>
                
                <div class="footer">
                    <p>This is an automated receipt. Please keep it for your records.</p>
                    <p>&copy; ' . date('Y') . ' GYF Ministry &amp; Prayer Camp. All rights reserved.</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ';

    return sendEmail($member_email, $subject, $message, $treasurer_email);
}

function redirectTo($url) {
    $target = APP_URL . $url;
    if (!headers_sent()) {
        header('Location: ' . $target);
        exit();
    }
    // Fallback when output already started: redirect via JavaScript.
    $nonceAttr = defined('CSP_NONCE') ? ' nonce="' . CSP_NONCE . '"' : '';
    echo '<script' . $nonceAttr . '>window.location.href = ' . json_encode($target) . ';</script>';
    exit();
}

function uploadPhoto($file) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload failed. Error code: ' . ($file['error'] ?? 'unknown')];
    }

    // Check file size (before any disk I/O)
    if ($file["size"] > MAX_UPLOAD_SIZE) {
        return ['success' => false, 'message' => 'File is too large. Maximum size is 5MB.'];
    }

    // Verify MIME type from file content, not just extension
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($file["tmp_name"]);

    $allowed_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];

    if (!isset($allowed_mimes[$mime_type])) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, JPEG, PNG & GIF files are allowed.'];
    }

    $file_extension = $allowed_mimes[$mime_type];
    $new_filename = bin2hex(random_bytes(16)) . '.' . $file_extension;

    // When Vercel Blob is configured, upload straight to object storage over
    // HTTP and store the public Blob URL. This avoids the read-only local
    // filesystem entirely (no mkdir/move_uploaded_file on /var/task).
    if (blobIsEnabled()) {
        $blob = blobUploadFile($file["tmp_name"], $new_filename, $mime_type);
        if ($blob['success']) {
            return ['success' => true, 'filename' => $blob['url']];
        }
        // On Blob failure, fall through to local-disk attempt only if the
        // filesystem is writable (local dev); on Vercel this will still warn,
        // but the error is surfaced clearly instead of silently losing the file.
        error_log('Blob upload failed: ' . $blob['message']);
        return ['success' => false, 'message' => 'Could not store upload. Please try again.'];
    }

    // Local development fallback (writable disk)
    $target_dir = UPLOAD_DIR . 'photos/';
    if (!file_exists($target_dir)) {
        if (!@mkdir($target_dir, 0755, true) && !is_dir($target_dir)) {
            return ['success' => false, 'message' => 'Server upload directory is not writable.'];
        }
    }

    $target_file = $target_dir . $new_filename;

    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return ['success' => true, 'filename' => $new_filename];
    }

    return ['success' => false, 'message' => 'Error uploading file.'];
}

// TOTP / 2FA helpers (RFC 6238, HMAC-SHA1)
function generateTOTP($secret, $time = null, $timeSlice = 30) {
    $secretBytes = hex2bin($secret);
    if ($secretBytes === false) return false;

    if ($time === null) $time = time();
    $counter = (int) floor($time / $timeSlice);

    if (PHP_INT_SIZE >= 8) {
        $counterBytes = pack('J', $counter);
    } else {
        $counterBytes = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
    }

    $hash = hash_hmac('sha1', $counterBytes, $secretBytes, true);

    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $code = (
        (ord($hash[$offset]) & 0x7F) << 24 |
        (ord($hash[$offset + 1]) & 0xFF) << 16 |
        (ord($hash[$offset + 2]) & 0xFF) << 8 |
        (ord($hash[$offset + 3]) & 0xFF)
    );

    $otp = $code % 1000000;
    return str_pad($otp, 6, '0', STR_PAD_LEFT);
}

function verifyTOTP($secret, $code, $window = 1) {
    if (empty($secret) || empty($code)) return false;

    $code = preg_replace('/[^0-9]/', '', $code);
    if (strlen($code) !== 6) return false;

    $codeInt = (string) $code;

    for ($i = -$window; $i <= $window; $i++) {
        $expected = (string) generateTOTP($secret, time() + $i * 30);
        if (hash_equals($expected, $codeInt)) {
            return true;
        }
    }

    return false;
}

function generateBase32($hexSecret) {
    $bytes = hex2bin($hexSecret);
    if ($bytes === false) return '';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $output = '';
    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    for ($i = 0; $i < strlen($bits); $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_LEFT);
        $output .= $alphabet[bindec($chunk)];
    }
    return $output;
}

function getTOTPUri($secret, $account, $issuer = 'GYF Welfare') {
    $base32 = generateBase32($secret);
    $params = http_build_query([
        'secret' => $base32,
        'issuer' => $issuer,
        'period' => 30,
    ]);
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account) . '?' . $params;
}

function getTOTPQRCodeUrl($secret, $account, $issuer = 'GYF Welfare', $size = 200) {
    return getTOTPUri($secret, $account, $issuer);
}

function getWelfareSettings($db) {
    $settings = $db->query("SELECT * FROM settings WHERE id = 1")->fetch();
    if (!$settings) {
        return [
            'annual_amount' => 1000.00,
            'monthly_amount' => 100.00
        ];
    }
    return [
        'annual_amount' => (float) ($settings['annual_amount'] ?? 1000.00),
        'monthly_amount' => (float) ($settings['monthly_amount'] ?? 100.00)
    ];
}

function updateWelfareSettings($db, $annual_amount, $monthly_amount) {
    $annual = (float) $annual_amount;
    $monthly = (float) $monthly_amount;
    
    $stmt = $db->prepare("INSERT INTO settings (id, annual_amount, monthly_amount) VALUES (1, :annual, :monthly) ON DUPLICATE KEY UPDATE annual_amount = :annual, monthly_amount = :monthly");
    return $stmt->execute([':annual' => $annual, ':monthly' => $monthly]);
}

function getRecurringLatePayers($db) {
    $stmt = $db->query("SELECT t.member_id, m.full_name, COUNT(*) as late_count, MAX(t.transaction_date) as last_payment FROM transactions t JOIN members m ON t.member_id = m.member_id WHERE DAY(t.transaction_date) > 15 AND t.billing_cycle_month = MONTH(CURRENT_DATE - INTERVAL 1 MONTH) AND t.billing_cycle_year = YEAR(CURRENT_DATE - INTERVAL 1 MONTH) GROUP BY t.member_id, m.full_name HAVING late_count >= 2 ORDER BY late_count DESC");
    return $stmt->fetchAll();
}

function buildTransactionFilterClause($source = 'api') {
    $where = [];
    $params = [];
    $user_type = $_SESSION['user_type'] ?? 'member';
    
    $filter_receipt = $_GET['filter_receipt'] ?? $_POST['filter_receipt'] ?? '';
    $filter_member = $_GET['filter_member'] ?? $_POST['filter_member'] ?? '';
    $filter_date_from = $_GET['filter_date_from'] ?? $_POST['filter_date_from'] ?? '';
    $filter_date_to = $_GET['filter_date_to'] ?? $_POST['filter_date_to'] ?? '';
    $filter_amount_min = $_GET['filter_amount_min'] ?? $_POST['filter_amount_min'] ?? '';
    $filter_amount_max = $_GET['filter_amount_max'] ?? $_POST['filter_amount_max'] ?? '';
    $filter_method = $_GET['filter_method'] ?? $_POST['filter_method'] ?? '';
    $filter_month = $_GET['filter_month'] ?? $_POST['filter_month'] ?? '';
    $filter_year = $_GET['filter_year'] ?? $_POST['filter_year'] ?? '';
    
    if (!empty($filter_receipt)) {
        $where[] = "t.receipt_no LIKE :filter_receipt";
        $params[':filter_receipt'] = '%' . cleanInput($filter_receipt) . '%';
    }
    if (!empty($filter_member)) {
        $where[] = "t.member_id = :filter_member";
        $params[':filter_member'] = cleanInput($filter_member);
    }
    if (!empty($filter_date_from)) {
        $where[] = "DATE(t.transaction_date) >= :filter_date_from";
        $params[':filter_date_from'] = cleanInput($filter_date_from);
    }
    if (!empty($filter_date_to)) {
        $where[] = "DATE(t.transaction_date) <= :filter_date_to";
        $params[':filter_date_to'] = cleanInput($filter_date_to);
    }
    if ($filter_amount_min !== '') {
        $where[] = "t.amount >= :filter_amount_min";
        $params[':filter_amount_min'] = (float)$filter_amount_min;
    }
    if ($filter_amount_max !== '') {
        $where[] = "t.amount <= :filter_amount_max";
        $params[':filter_amount_max'] = (float)$filter_amount_max;
    }
    if (!empty($filter_method)) {
        $where[] = "t.payment_method = :filter_method";
        $params[':filter_method'] = cleanInput($filter_method);
    }
    if (!empty($filter_month)) {
        $where[] = "t.billing_cycle_month = :filter_month";
        $params[':filter_month'] = (int)$filter_month;
    }
    if (!empty($filter_year)) {
        $where[] = "t.billing_cycle_year = :filter_year";
        $params[':filter_year'] = (int)$filter_year;
    }
    
    $where[] = "t.status != 'void'";
    
    if ($user_type === 'member') {
        $where[] = "t.member_id = :member_id";
        $params[':member_id'] = $_SESSION['user_id'];
    }
    
    $whereClause = '';
    if (!empty($where)) {
        $whereClause = 'WHERE ' . implode(' AND ', $where);
    }
    
    return ['where' => $whereClause, 'params' => $params];
}

function buildTransactionFilter($db, $source = 'api') {
    $filter = buildTransactionFilterClause($source);
    $whereClause = $filter['where'];
    $params = $filter['params'];
    
    $query = "SELECT t.id, t.receipt_no, t.member_id, t.amount, t.payment_method, t.billing_cycle_month, t.billing_cycle_year, t.transaction_date, t.status, m.full_name FROM transactions t JOIN members m ON t.member_id = m.member_id {$whereClause} ORDER BY t.transaction_date DESC";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    return $stmt;
}

function formatBillingPeriod($month, $year) {
    $month = max(1, min(12, (int)$month));
    $year = (int)$year;
    if ($year <= 0) $year = (int)date('Y');
    return date('F Y', mktime(0, 0, 0, $month, 1, $year));
}

function renderReceipt($transaction, $show_billing_period = true, $show_member_id = true) {
    $member_label = htmlspecialchars($transaction['full_name']);
    if ($show_member_id && !empty($transaction['member_id'])) {
        $member_label .= ' (' . htmlspecialchars($transaction['member_id']) . ')';
    }
    $billing_period = '';
    if ($show_billing_period) {
        if (!empty($transaction['billing_cycle_month'])) {
            $billing_period = formatBillingPeriod($transaction['billing_cycle_month'], $transaction['billing_cycle_year'] ?? date('Y'));
        } elseif (!empty($transaction['billing_cycle_year'])) {
            $billing_period = htmlspecialchars($transaction['billing_cycle_year']);
        }
    }
    $ts = strtotime($transaction['transaction_date']);
    $date = $ts ? htmlspecialchars(date('F j, Y g:i A', $ts)) : 'N/A';
    include __DIR__ . '/../templates/receipt.php';
}
?>