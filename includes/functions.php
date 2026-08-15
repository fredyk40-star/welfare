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
//
// Implemented with a SessionHandlerInterface object (the callback-list form
// of session_set_save_handler() is deprecated as of PHP 8.5).

if (!class_exists('DatabaseSessionHandler')) {
    class DatabaseSessionHandler implements SessionHandlerInterface
    {
        private $pdo = null;
        private $created = false;

        private function ensureTable()
        {
            // Avoid running CREATE TABLE on every request; cache within the process.
            if ($this->created && $this->pdo !== null) {
                return $this->pdo;
            }
            try {
                if ($this->pdo === null) {
                    $database = new Database();
                    $this->pdo = $database->getConnection();
                }
                $this->pdo->exec(
                    "CREATE TABLE IF NOT EXISTS sessions (" .
                    "id VARCHAR(128) NOT NULL PRIMARY KEY, " .
                    "data MEDIUMTEXT, " .
                    "last_activity INT UNSIGNED NOT NULL, " .
                    "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, " .
                    "INDEX idx_sessions_la (last_activity)" .
                    ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                );
                $this->created = true;
                return $this->pdo;
            } catch (Throwable $e) {
                // Never emit output inside a session callback (it would send
                // headers and break the session -> random logouts). Log and fail
                // closed so PHP falls back gracefully.
                error_log('Session DB handler error: ' . $e->getMessage());
                return null;
            }
        }

        public function open($path, $name): bool { return (bool) $this->ensureTable(); }
        public function close(): bool { return true; }
        public function read($id): string|false { $db = $this->ensureTable(); if (!$db) return false; $stmt = $db->prepare("SELECT data FROM sessions WHERE id = :id"); $stmt->execute([':id' => $id]); $row = $stmt->fetch(); return $row ? (string) $row['data'] : ''; }
        public function write($id, $data): bool { $db = $this->ensureTable(); if (!$db) return false; $stmt = $db->prepare("REPLACE INTO sessions (id, data, last_activity) VALUES (:id, :data, :la)"); $ok = $stmt->execute([':id' => $id, ':data' => $data, ':la' => time()]); return $ok; }
        public function destroy($id): bool { $db = $this->ensureTable(); if (!$db) return false; $stmt = $db->prepare("DELETE FROM sessions WHERE id = :id"); $stmt->execute([':id' => $id]); return true; }
        public function gc($maxlifetime): int { $db = $this->ensureTable(); if (!$db) return 0; $stmt = $db->prepare("DELETE FROM sessions WHERE last_activity < :cut"); $stmt->execute([':cut' => time() - (int) $maxlifetime]); return $stmt->rowCount(); }
        // Intentionally return empty string to let PHP generate the session ID.
        // With session.use_strict_mode=1, PHP will create a new valid random ID.
        // We do not need custom ID generation because the DatabaseSessionHandler
        // already persists sessions reliably across serverless instances.
        public function create_sid() { return ''; }
        public function validateId($id) { return true; }
    }
}

// Register the handler BEFORE any output. If headers were already sent (e.g. the
// router echoed a 404 before including this file), registration is skipped to
// avoid the deprecated/headers-sent warnings rather than crashing the request.
if (!headers_sent()) {
    $handler = new DatabaseSessionHandler();
    session_set_save_handler($handler, true);
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
if (!headers_sent()) {
    session_name('GYF_SESSION_ID');
    session_set_cookie_params([
        'lifetime' => SESSION_TIMEOUT,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

function generateReceiptNumber() {
    return 'RCP-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
}

// Generate a unique member ID of the form GYF-XXXXXX (alphanumeric, no ambiguous chars).
function generateMemberId($db) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $suffix = '';
        for ($i = 0; $i < 6; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $candidate = 'GYF-' . $suffix;
        $stmt = $db->prepare("SELECT 1 FROM members WHERE member_id = :mid");
        $stmt->execute([':mid' => $candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
    }
    // Extremely unlikely fallback
    return 'GYF-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
}

// Send a friendly payment reminder to a member who has not paid a billing cycle.
function sendReminderEmail($email, $full_name, $month_name, $year, $amount_due) {
    $email = sanitizeEmailValue($email);
    $full_name = sanitizeEmailValue($full_name);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $subject = 'Payment Reminder - ' . APP_NAME;
    $safe_name = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');
    $safe_month = htmlspecialchars($month_name, ENT_QUOTES, 'UTF-8');
    $safe_amount = htmlspecialchars(number_format((float) $amount_due, 2), ENT_QUOTES, 'UTF-8');
    $sign_off = $safe_name ? htmlspecialchars($safe_name, ENT_QUOTES, 'UTF-8') : APP_NAME;
    $message = <<<HTML
<html><body style="font-family:Arial,sans-serif;color:#333;">
<div style="max-width:600px;margin:0 auto;padding:20px;">
  <h2 style="color:#1976d2;">{$safe_month} {$year} Contribution Reminder</h2>
  <p>Dear {$safe_name},</p>
  <p>This is a gentle reminder that your welfare contribution for <strong>{$safe_month} {$year}</strong>
     (GH₵ {$safe_amount}) has not yet been recorded. Please make your payment at your earliest convenience.</p>
  <p>Thank you for your continued support of the GYF Welfare community.</p>
  <p style="color:#666;font-size:0.9em;">— {$sign_off} Treasurer</p>
</div>
</body></html>
HTML;
    return sendEmail($email, $subject, $message);
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

    $smtp_host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $smtp_port = (int)(getenv('SMTP_PORT') ?: 465);
    $smtp_username = getenv('SMTP_USERNAME') ?: 'resend';
    $smtp_password = getenv('SMTP_PASSWORD');
    if (empty($smtp_password)) {
        error_log('sendEmail aborted: SMTP_PASSWORD is not configured');
        return false;
    }

    $from_email = getenv('RESEND_FROM_EMAIL') ?: 'noreply@gyf.org';
    $from_name = sanitizeEmailValue(APP_NAME);
    $from_display = $from_name . ' <' . $from_email . '>';

    $recipients = array_filter(array_map('sanitizeEmailValue', (array)$to));
    if (empty($recipients)) {
        return false;
    }
    $to = $recipients[0];

    $conn = @stream_socket_client(
        'ssl://' . $smtp_host . ':' . $smtp_port,
        $errno, $errstr, 30
    );

    if (!$conn) {
        error_log('SMTP connection failed (' . $smtp_host . ':' . $smtp_port . '): ' . $errstr);
        return false;
    }

    // Read SMTP response lines. Multi-line responses end with "code " (space).
    $readResponse = function($conn, $expectCode = null) {
        $response = '';
        while ($line = fgets($conn, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int)($response[0] . $response[1] . $response[2] ?? '000');
        if ($expectCode !== null && $code !== $expectCode) {
            error_log('SMTP unexpected response (expected ' . $expectCode . '): ' . trim($response));
            fclose($conn);
            return false;
        }
        return $response;
    };

    // Send a raw SMTP command and read the response.
    $sendCmd = function($conn, $cmd, $expectCode = null) use ($readResponse) {
        fwrite($conn, $cmd . "\r\n");
        return $readResponse($conn, $expectCode);
    };

    // Greeting
    if ($readResponse($conn, 220) === false) { fclose($conn); return false; }

    // EHLO
    if ($sendCmd($conn, 'EHLO ' . gethostname(), 250) === false) {
        // Some servers only support HELO
        if ($sendCmd($conn, 'HELO ' . gethostname(), 250) === false) {
            fclose($conn);
            return false;
        }
    }

    // AUTH PLAIN
    $auth_string = base64_encode("\0" . $smtp_username . "\0" . $smtp_password);
    if ($sendCmd($conn, 'AUTH PLAIN ' . $auth_string, 235) === false) {
        fclose($conn);
        return false;
    }

    // MAIL FROM
    if ($sendCmd($conn, 'MAIL FROM:<' . $from_email . '>', 250) === false) {
        fclose($conn);
        return false;
    }

    // RCPT TO (primary recipient)
    if ($sendCmd($conn, 'RCPT TO:<' . $to . '>', 250) === false) {
        fclose($conn);
        return false;
    }

    // RCPT TO (CC recipients)
    foreach ($cc_list as $cc_addr) {
        if ($sendCmd($conn, 'RCPT TO:<' . $cc_addr . '>', 250) === false) {
            fclose($conn);
            return false;
        }
    }

    // DATA
    if ($sendCmd($conn, 'DATA', 354) === false) {
        fclose($conn);
        return false;
    }

    // Build headers
    $headers = [];
    $headers[] = 'From: ' . $from_display;
    $headers[] = 'To: ' . $to;
    if (!empty($cc_list)) {
        $headers[] = 'Cc: ' . implode(', ', $cc_list);
    }
    $headers[] = 'Subject: ' . $subject;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'Message-ID: <' . uniqid() . '@' . $smtp_host . '>';

    $data = implode("\r\n", $headers) . "\r\n\r\n" . $message . "\r\n.\r\n";
    fwrite($conn, $data);

    // Read DATA response
    $readResponse($conn, 250);

    // QUIT
    $sendCmd($conn, 'QUIT', 221);
    fclose($conn);

    return true;
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
    $message = '
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
    
    $stmt = $db->prepare("INSERT INTO settings (id, annual_amount, monthly_amount) VALUES (1, :annual, :monthly) ON DUPLICATE KEY UPDATE annual_amount = :annual2, monthly_amount = :monthly2");
    return $stmt->execute([':annual' => $annual, ':monthly' => $monthly, ':annual2' => $annual, ':monthly2' => $monthly]);
}

/**
 * Get the welfare target for a specific calendar year.
 * Falls back to the global settings row if the year has no explicit target.
 *
 * @return array{annual_amount: float, monthly_amount: float}
 */
function getYearlyTarget($db, $year) {
    $year = (int) $year;
    $stmt = $db->prepare("SELECT annual_amount, monthly_amount FROM yearly_targets WHERE year = :yr");
    $stmt->execute([':yr' => $year]);
    $row = $stmt->fetch();
    if ($row) {
        return [
            'annual_amount' => (float) ($row['annual_amount'] ?? 240.00),
            'monthly_amount' => (float) ($row['monthly_amount'] ?? 20.00)
        ];
    }
    // Fallback to global settings (legacy/default)
    $settings = getWelfareSettings($db);
    return [
        'annual_amount' => $settings['annual_amount'],
        'monthly_amount' => $settings['monthly_amount']
    ];
}

/**
 * Get a member's payment stats for a specific year.
 *
 * @return array{
 *   paid: float,
 *   target: float,
 *   monthly_target: float,
 *   debt: float,
 *   pct: float,
 *   tx_count: int
 * }
 */
function getMemberYearStats($db, $member_id, $year) {
    $year = (int) $year;
    $target = getYearlyTarget($db, $year);

    $ytd = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as tx_count FROM transactions WHERE member_id = :mid AND billing_cycle_year = :yr AND status != 'void'");
    $ytd->execute([':mid' => $member_id, ':yr' => $year]);
    $row = $ytd->fetch();

    $paid = (float) ($row['tx_count'] ? $row['total'] : 0);
    $debt = max(0.0, $target['annual_amount'] - $paid);
    $pct = $target['annual_amount'] > 0 ? min(100.0, round(($paid / $target['annual_amount']) * 100, 1)) : 0.0;

    return [
        'paid' => $paid,
        'target' => $target['annual_amount'],
        'monthly_target' => $target['monthly_amount'],
        'debt' => $debt,
        'pct' => $pct,
        'tx_count' => (int) ($row['tx_count'] ?? 0)
    ];
}

/**
 * Get all years in which a member has any transaction, plus their stats.
 *
 * @return array<int, array{year:int, paid:float, target:float, debt:float, pct:float, tx_count:int}>
 */
function getMemberYearlyHistory($db, $member_id) {
    $stmt = $db->prepare("SELECT DISTINCT billing_cycle_year FROM transactions WHERE member_id = :mid AND status != 'void' ORDER BY billing_cycle_year DESC");
    $stmt->execute([':mid' => $member_id]);
    $years = array_column($stmt->fetchAll(), 'billing_cycle_year');

    $history = [];
    foreach ($years as $yr) {
        $history[] = getMemberYearStats($db, $member_id, $yr);
    }
    return $history;
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

/**
 * Normalize phone number to a canonical digit string (no +, no spaces).
 *
 * Strategy:
 *  - Strip everything that is not a digit.
 *  - A leading "00" international prefix is dropped (00XXX -> XXX).
 *  - A Ghana local number (10 digits starting with 0, e.g. 0595360050) is
 *    rewritten to its international form 233595360050.
 *  - Otherwise the digits are returned as-is (already international, e.g. 233...,
 *    27..., 1..., etc.).
 *
 * Input : 0595360050 | +233 595360050 | 233595360050 | 00233595360050
 * Output: 233595360050
 */
function normalizePhoneNumber($phone) {
    $phone = trim((string) $phone);
    if ($phone === '') {
        return '';
    }

    $digits = preg_replace('/\D/', '', $phone);
    if ($digits === '') {
        return '';
    }

    // Drop a leading "00" international prefix (00XXX -> XXX)
    if (substr($digits, 0, 2) === '00') {
        $digits = substr($digits, 2);
    }

    // Ghana local format: 0xxxxxxxxxx (10 digits) -> 233xxxxxxxxxx
    if (substr($digits, 0, 1) === '0' && strlen($digits) === 10) {
        return '233' . substr($digits, 1);
    }

    return $digits;
}

/**
 * Build the set of phone-like search variants for a free-text search term so a
 * member can be found whether the treasurer types the local number
 * (0595360050), the international number (233595360050), or just the local
 * digits (595360050). Returns an empty array when the term is not phone-like
 * (contains letters), so name/ID matching can take over.
 *
 * @return array<int,string> list of bare digit strings to match against
 */
function getPhoneSearchVariants($term) {
    $term = trim((string) $term);
    if ($term === '' || !preg_match('/^[+\d\s\-().]+$/', $term)) {
        return [];
    }

    $digits = preg_replace('/\D/', '', $term);
    if (strlen($digits) < 7) {
        return [];
    }

    $variants = [$digits];

    // Ghana local (0 + 9 digits) -> also try the 233 international form
    if (substr($digits, 0, 1) === '0' && strlen($digits) === 10) {
        $variants[] = '233' . substr($digits, 1);
    }
    // International 233 form -> also try the leading-0 local form + bare local
    if (substr($digits, 0, 3) === '233') {
        $local = substr($digits, 3);
        $variants[] = $local;
        $variants[] = '0' . $local;
    }

    // De-duplicate while preserving order
    return array_values(array_unique($variants));
}

/**
 * Get member status display badge
 */
function getMemberStatusBadge($status) {
    $badges = [
        'active' => '<span class="badge bg-success">Active</span>',
        'suspended' => '<span class="badge bg-warning text-dark">Suspended</span>',
        'deactivated' => '<span class="badge bg-secondary">Deactivated</span>',
        'deleted' => '<span class="badge bg-danger">Deleted</span>',
    ];
    return $badges[$status] ?? '<span class="badge bg-light text-dark">Unknown</span>';
}

/**
 * Check if member can login (active only)
 */
function canMemberLogin($status) {
    return $status === 'active';
}

/**
 * Get member status actions for treasurer
 */
function getMemberStatusActions($member_id, $current_status, $current_user_id, $deleted_at = '', $deletion_count = 0) {
    $actions = [];
    $csrf = generateCsrfToken();
    $id = htmlspecialchars($member_id);
    
    switch ($current_status) {
        case 'active':
            $actions[] = '<button class="btn btn-sm btn-warning" data-action="update_status" data-status="suspended" data-member-id="' . $id . '" data-csrf="' . $csrf . '">Suspend</button>';
            $actions[] = '<button class="btn btn-sm btn-secondary" data-action="update_status" data-status="deactivated" data-member-id="' . $id . '" data-csrf="' . $csrf . '">Deactivate</button>';
            $actions[] = '<button class="btn btn-sm btn-danger" data-action="update_status" data-status="deleted" data-member-id="' . $id . '" data-csrf="' . $csrf . '">Delete</button>';
            break;
        case 'suspended':
            $actions[] = '<button class="btn btn-sm btn-success" data-action="update_status" data-status="active" data-member-id="' . $id . '" data-csrf="' . $csrf . '">Unsuspend</button>';
            $actions[] = '<button class="btn btn-sm btn-secondary" data-action="update_status" data-status="deactivated" data-member-id="' . $id . '" data-csrf="' . $csrf . '">Deactivate</button>';
            $actions[] = '<button class="btn btn-sm btn-danger" data-action="update_status" data-status="deleted" data-member-id="' . $id . '" data-csrf="' . $csrf . '">Delete</button>';
            break;
        case 'deactivated':
            $actions[] = '<button class="btn btn-sm btn-success" data-action="update_status" data-status="active" data-member-id="' . $id . '" data-csrf="' . $csrf . '">Activate</button>';
            $actions[] = '<button class="btn btn-sm btn-danger" data-action="update_status" data-status="deleted" data-member-id="' . $id . '" data-csrf="' . $csrf . '">Delete</button>';
            break;
        case 'deleted':
            if ($deletion_count >= 3) {
                $actions[] = '<span class="text-danger small fw-bold" title="Permanently banned after 3 deletions">Banned (permanent)</span>';
            } else {
                $actions[] = '<button class="btn btn-sm btn-success" data-action="update_status" data-status="active" data-member-id="' . $id . '" data-csrf="' . $csrf . '">Reactivate</button>';
                $actions[] = '<span class="text-muted small">Deleted ' . ($deleted_at ? htmlspecialchars(date('M j, Y', strtotime($deleted_at))) : '') . '</span>';
            }
            break;
    }
    return implode(' ', $actions);
}

/**
 * Check if member has been deleted 3+ times
 */
function isMemberPermanentlyBanned($member_id, $db) {
    $stmt = $db->prepare("SELECT deletion_count FROM members WHERE member_id = :mid");
    $stmt->execute([':mid' => $member_id]);
    $row = $stmt->fetch();
    return $row && $row['deletion_count'] >= 3;
}

/**
 * Update member status
 */
function updateMemberStatus($db, $member_id, $new_status, $treasurer_id) {
    $allowed_statuses = ['active', 'suspended', 'deactivated', 'deleted'];
    if (!in_array($new_status, $allowed_statuses)) {
        return ['success' => false, 'message' => 'Invalid status'];
    }
    
    $now = date('Y-m-d H:i:s');
    $updates = [];
    $params = [':mid' => $member_id, ':treasurer_id' => $treasurer_id, ':now' => $now];
    
    $updates[] = "status = :new_status";
    $params[':new_status'] = $new_status;
    
    if ($new_status === 'suspended') {
        $updates[] = "suspended_at = :now";
        $updates[] = "suspended_by = :treasurer_id";
    } elseif ($new_status === 'deleted') {
        $updates[] = "deleted_at = :now";
        $updates[] = "deleted_by = :treasurer_id";
        $updates[] = "deletion_count = deletion_count + 1";
    } elseif ($new_status === 'active') {
        // Clear suspension/deactivation timestamps when reactivating
        $updates[] = "suspended_at = NULL";
        $updates[] = "suspended_by = NULL";
    } elseif ($new_status === 'deactivated') {
        // Keep track of deactivation
    }
    
    $query = "UPDATE members SET " . implode(', ', $updates) . " WHERE member_id = :mid";
    $stmt = $db->prepare($query);
    $result = $stmt->execute($params);
    
    if ($result) {
        $action_names = [
            'active' => 'Activated',
            'suspended' => 'Suspended',
            'deactivated' => 'Deactivated',
            'deleted' => 'Deleted',
        ];
        logAudit($treasurer_id, $action_names[$new_status] . " member {$member_id}");
        return ['success' => true, 'message' => 'Member status updated'];
    }
    return ['success' => false, 'message' => 'Failed to update status'];
}

/**
 * Delete member completely (admin only)
 */
function deleteMemberPermanently($db, $member_id, $treasurer_id) {
    // Delete transactions first (foreign key constraint)
    $stmt = $db->prepare("DELETE FROM transactions WHERE member_id = :mid");
    $stmt->execute([':mid' => $member_id]);
    
    // Delete member
    $stmt = $db->prepare("DELETE FROM members WHERE member_id = :mid AND member_id != :treasurer_id");
    $stmt->execute([':mid' => $member_id, ':treasurer_id' => $treasurer_id]);
    
    if ($stmt->rowCount() > 0) {
        logAudit($treasurer_id, "Permanently deleted member {$member_id}");
        return ['success' => true, 'message' => 'Member permanently deleted'];
    }
    return ['success' => false, 'message' => 'Member not found or cannot be deleted'];
}

/**
 * Database reset - danger zone
 */
function resetDatabase($db, $treasurer_id, $options = []) {
    $results = [];
    
    $default_options = [
        'transactions' => true,
        'audit_logs' => true,
        'password_resets' => true,
        'members' => true, // exclude treasurer
    ];
    
    $options = array_merge($default_options, $options);
    
    $db->beginTransaction();
    try {
        if ($options['transactions']) {
            $db->exec("DELETE FROM transactions");
            $results[] = 'All transactions deleted';
        }
        
        if ($options['audit_logs']) {
            $db->exec("DELETE FROM audit_logs");
            $results[] = 'Audit logs cleared';
        }
        
        if ($options['password_resets']) {
            $db->exec("DELETE FROM password_resets");
            $results[] = 'Password resets cleared';
        }
        
        if ($options['members']) {
            // Avoid orphaned transactions: always remove non-treasurer
            // transactions before removing the members themselves.
            if (!$options['transactions']) {
                $stmt = $db->prepare("DELETE FROM transactions WHERE member_id != :treasurer_id");
                $stmt->execute([':treasurer_id' => $treasurer_id]);
            }
            // Keep treasurer account
            $stmt = $db->prepare("DELETE FROM members WHERE member_id != :treasurer_id");
            $stmt->execute([':treasurer_id' => $treasurer_id]);
            $results[] = 'Non-treasurer members deleted (' . $stmt->rowCount() . ')';
        }
        
        // Reset settings to defaults
        $stmt = $db->prepare("UPDATE settings SET annual_amount = 240.00, monthly_amount = 20.00 WHERE id = 1");
        $stmt->execute();
        $results[] = 'Settings reset to defaults';
        
        $db->commit();
        logAudit($treasurer_id, "Database reset performed: " . implode(', ', $results));
        return ['success' => true, 'message' => 'Database reset completed', 'details' => $results];
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Database reset error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Reset failed: ' . $e->getMessage()];
    }
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
