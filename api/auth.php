<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

$action = isset($_GET['action']) ? cleanInput($_GET['action']) : '';
$ip_address = getClientIp();

switch ($action) {
    case 'logout':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . (APP_URL ?? '/') . '/index.html');
            exit();
        }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit();
        }
        if (!checkRateLimit($ip_address, 10, 60, '%logout%')) {
            echo json_encode(['success' => false, 'message' => 'Too many logout attempts. Please try again later.']);
            exit();
        }
        
        if (isset($_SESSION['user_id'])) {
            logAudit($_SESSION['user_id'], 'User logged out');
        }
        
        // Use proper session destruction helper
        destroySession();
        
        echo json_encode(['success' => true, 'message' => 'Logged out successfully', 'redirect' => APP_URL . '/index.html']);
        exit();
        
    case 'check_session':
        header('Content-Type: application/json');
        
        if (!checkRateLimit($ip_address, 60, 60, '%check_session%')) {
            echo json_encode(['success' => false, 'message' => 'Rate limit exceeded']);
            exit();
        }
        
        if (isLoggedIn()) {
            echo json_encode([
                'success' => true,
                'user_id' => $_SESSION['user_id'],
                'user_type' => $_SESSION['user_type'],
                'full_name' => $_SESSION['full_name'] ?? null
            ]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit();
        
    default:
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit();
}
?>