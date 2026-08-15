<?php
// ensures session is started and functions.php is loaded


// Content Security Policy with nonce for inline scripts
$nonce = base64_encode(random_bytes(16));
    $csp = "default-src 'self'; " .
       "script-src 'self' 'nonce-" . $nonce . "'; " .
       "style-src 'self' 'unsafe-inline'; " .
       "font-src 'self' data:; " .
       "img-src 'self' data: blob: https://*.blob.vercel-storage.com; " .
       "connect-src 'self'; " .
       "form-action 'self'; " .
       "frame-ancestors 'none';";
header("Content-Security-Policy: " . $csp);

define('CSP_NONCE', $nonce);

header_remove('X-Powered-By');
// Prevent mobile proxies / bfcache from serving stale Bootstrap JS or HTML,
// which is what broke the navbar toggler until a hard refresh.
header('Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}

// CSRF Token Generation
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get client IP address with proper proxy handling
 * @return string
 */
function getClientIp() {
    $direct_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $trusted_proxies = array_filter(array_map('trim', explode(',', getenv('TRUSTED_PROXIES') ?: '')));
    $is_trusted_proxy = in_array($direct_ip, $trusted_proxies, true);
    
    if ($is_trusted_proxy) {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'];
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    }
    
    if (filter_var($direct_ip, FILTER_VALIDATE_IP)) {
        return $direct_ip;
    }
    
    return 'unknown';
}

// Session fingerprint: bind session to client IP + user-agent to prevent
// session hijacking across devices/users.
function getSessionFingerprint() {
    $ip = getClientIp();
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    return hash('sha256', $ip . '|' . $ua);
}

function validateSessionFingerprint() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return true;
    }
    $current = getSessionFingerprint();
    if (isset($_SESSION['_fingerprint']) && $_SESSION['_fingerprint'] !== $current) {
        // Fingerprint mismatch: session is being used from a different device/IP.
        // Destroy the session to prevent cross-user session leaks.
        destroySession();
        error_log('Session fingerprint mismatch: session destroyed for security');
        return false;
    }
    // Set/update fingerprint on each request
    $_SESSION['_fingerprint'] = $current;
    return true;
}

// Rate Limiting
function checkRateLimit($identifier, $max_attempts = 5, $timeframe = 900, $action_pattern = '%login attempt%') {
    $database = new Database();
    $db = $database->getConnection();
    
    $ip_address = getClientIp();
    
    // Determine the rate-limit key:
    // - Numeric identifier → use as user_id directly
    // - Non-empty string identifier → use as user_id (supports member IDs like "GYF-12345",
    //   hashed emails, etc.)
    // - Empty/null identifier → fall back to IP address
    $is_numeric_id = isset($identifier) && ctype_digit((string)$identifier);
    $is_string_id = isset($identifier) && is_string($identifier) && $identifier !== '';
    
    if ($is_numeric_id || $is_string_id) {
        $where_clause = 'user_id = :identifier';
        $bind_value = (string)$identifier;
    } else {
        $where_clause = 'ip_address = :identifier';
        $bind_value = $ip_address;
    }
    
    $query = "SELECT COUNT(*) as attempts, MAX(timestamp) as last_attempt
              FROM audit_logs 
              WHERE action LIKE :action_pattern 
              AND $where_clause
              AND timestamp >= DATE_SUB(NOW(), INTERVAL :timeframe SECOND)";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':action_pattern' => $action_pattern,
        ':identifier' => $bind_value,
        ':timeframe' => $timeframe
    ]);
    
    $result = $stmt->fetch();
    
    if ($result && $result['attempts'] >= $max_attempts) {
        $last_attempt = strtotime($result['last_attempt']);
        if ($last_attempt !== false && (time() - $last_attempt) < $timeframe) {
            // Log the rate-limit event for audit trail
            if (function_exists('logAudit')) {
                logAudit($is_numeric_id ? $bind_value : null, "RATE_LIMIT_EXCEEDED: $action_pattern");
            }
            return false;
        }
    }
    
    return true;
}

function checkAccountLockout($identifier, $max_attempts = 5, $lockout_timeframes = [900, 3600, 86400]) {
    if (!class_exists('Database')) {
        require_once __DIR__ . '/../config/database.php';
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    $ip_address = getClientIp();
    $bind_value = is_string($identifier) && $identifier !== '' ? (string)$identifier : $ip_address;
    $where_clause = is_string($identifier) && $identifier !== '' ? 'user_id = :identifier' : 'ip_address = :identifier';
    
    $lockout_level = 0;
    $locked_until = 0;
    
    foreach ($lockout_timeframes as $index => $timeframe) {
        $query = "SELECT COUNT(*) as attempts, MAX(timestamp) as last_attempt
                  FROM audit_logs 
                  WHERE action LIKE '%login%' AND action NOT LIKE '%success%'
                  AND $where_clause
                  AND timestamp >= DATE_SUB(NOW(), INTERVAL :timeframe SECOND)";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':identifier' => $bind_value,
            ':timeframe' => $timeframe
        ]);
        
        $result = $stmt->fetch();
        
        if ($result && $result['attempts'] >= $max_attempts) {
            $lockout_level = $index + 1;
            $last_attempt = strtotime($result['last_attempt']);
            if ($last_attempt !== false) {
                $locked_until = max($locked_until, $last_attempt + $timeframe);
            }
        }
    }
    
    if ($lockout_level > 0 && time() < $locked_until) {
        return [
            'locked' => true,
            'level' => $lockout_level,
            'remaining' => $locked_until - time()
        ];
    }
    
    return ['locked' => false];
}

// Session Security
function regenerateSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function destroySession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }
}

function destroyAllUserSessions($user_id) {
    $database = new Database();
    $db = $database->getConnection();
    $search = '"user_id";s:' . strlen((string)$user_id) . ':"' . $user_id . '"';
    $stmt = $db->prepare("DELETE FROM sessions WHERE data LIKE :uid");
    $stmt->execute([':uid' => '%' . $search . '%']);
    clearRememberMeToken($user_id);
}

function logout($redirect = '../member/login.php') {
    if (isset($_SESSION['user_id'])) {
        clearRememberMeToken($_SESSION['user_id']);
        destroyAllUserSessions($_SESSION['user_id']);
    }
    destroySession();
    // Validate redirect URL against allowlist
    $safe_redirects = ['/index.html', '/member/login.php', '/treasurer/login.php'];
    if (!in_array($redirect, $safe_redirects)) {
        $redirect = '/index.html'; // Default to safe redirect;
    }
    if (!headers_sent()) {
        header('Location: ' . APP_URL . $redirect);
        exit();
    }
    // Fallback when output already started: redirect via JavaScript.
    $nonceAttr = defined('CSP_NONCE') ? ' nonce="' . CSP_NONCE . '"' : '';
    echo '<script' . $nonceAttr . '>window.location.href = ' . json_encode(APP_URL . $redirect) . ';</script>';
    exit();
}

// Validate session fingerprint after all includes so $_SESSION is available.
if (session_status() === PHP_SESSION_ACTIVE && function_exists('validateSessionFingerprint')) {
    validateSessionFingerprint();
}


