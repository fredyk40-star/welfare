<?php
// Content Security Policy
$csp = "default-src 'self'; " .
       "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " .
       "style-src 'self' 'unsafe-inline'; " .
       "font-src 'self' data:; " .
       "img-src 'self' data:; " .
       "connect-src 'self'; " .
       "form-action 'self'; " .
       "frame-ancestors 'none';";
header("Content-Security-Policy: " . $csp);

header_remove('X-Powered-By');

if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
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

// Rate Limiting for Login
function checkRateLimit($identifier, $max_attempts = 5, $timeframe = 900) {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT COUNT(*) as attempts, MAX(timestamp) as last_attempt 
              FROM audit_logs 
              WHERE action LIKE :action_pattern 
              AND ip_address = :ip 
              AND timestamp >= DATE_SUB(NOW(), INTERVAL :timeframe SECOND)";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':action_pattern' => '%login attempt%',
        ':ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'],
        ':timeframe' => $timeframe
    ]);
    
    $result = $stmt->fetch();
    
    if ($result['attempts'] >= $max_attempts) {
        $time_since_last = time() - strtotime($result['last_attempt']);
        if ($time_since_last < $timeframe) {
            return false;
        }
    }
    
    return true;
}
