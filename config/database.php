<?php
// Load .env file for local development (Vercel and other platforms inject env vars directly)
$env_file = __DIR__ . '/../.env';
if (file_exists($env_file) && is_readable($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        // Remove optional quotes
        if (preg_match('/^"(.*)"$/', $value, $m) || preg_match("/^'(.*)'$/", $value, $m)) {
            $value = $m[1];
        }
        // Only set if not already defined in the environment (Vercel/platform takes precedence)
        if (!isset($_ENV[$key]) && !isset($_SERVER[$key])) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// Database Configuration
// Production platforms inject env vars directly; local .env is loaded above.
// Fail closed if required DB credentials are missing.
$db_host = getenv('DB_HOST');
$db_name = getenv('DB_NAME');
$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASS');
$db_port = getenv('DB_PORT') ?: '3306';
$db_charset = getenv('DB_CHARSET') ?: 'utf8mb4';

if (!$db_host || !$db_name || !$db_user) {
    error_log('Database configuration error: DB_HOST, DB_NAME, and DB_USER are required.');
    die('Application configuration error. Please contact support.');
}

if ($db_host !== 'localhost' && ($db_pass === '' || $db_pass === null)) {
    error_log('Database configuration error: DB_PASS is required for non-localhost environments.');
    die('Application configuration error. Please contact support.');
}

define('DB_HOST', $db_host);
define('DB_NAME', $db_name);
define('DB_USER', $db_user);
define('DB_PASS', $db_pass);
define('DB_PORT', $db_port);
define('DB_CHARSET', $db_charset);

// Application Configuration
$app_name = getenv('APP_NAME') ?: 'GYF Welfare Management System';
$app_url = getenv('APP_URL') ?: 'http://localhost/welfare';
define('APP_NAME', $app_name);
define('APP_URL', $app_url);
$upload_dir = getenv('UPLOAD_DIR');
define('UPLOAD_DIR', $upload_dir ? rtrim($upload_dir, '/') . '/' : __DIR__ . '/../uploads/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB

// Security Configuration
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_MAX_LENGTH', 255);
define('SESSION_TIMEOUT', 3600); // 1 hour
define('REMEMBER_ME_TIMEOUT', 30 * 24 * 3600); // 30 days

// Treasurer account identifier (single-row treasurer account)
// Override via TREASURER_MEMBER_ID env var if needed.
$tid = getenv('TREASURER_MEMBER_ID');
if (empty($tid)) {
    $tid = 'GYF-ADMIN';
}
define('TREASURER_MEMBER_ID', $tid);

class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $charset = DB_CHARSET;
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $dsn = "mysql:host=" . $this->host . ";port=" . DB_PORT . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
        } catch(PDOException $e) {
            error_log("Connection Error: " . $e->getMessage());
            die("Database connection failed. Please try again later.");
        }
        return $this->conn;
    }
}