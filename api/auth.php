<?php
require_once __DIR__ . '/../includes/functions.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'logout':
        // Log the logout action
        if (isset($_SESSION['user_id'])) {
            logAudit($_SESSION['user_id'], 'User logged out');
        }
        
        // Destroy session
        session_destroy();
        redirectTo('/index.html');
        break;
        
    case 'check_session':
        header('Content-Type: application/json');
        
        if (isLoggedIn()) {
            echo json_encode([
                'success' => true,
                'user_id' => $_SESSION['user_id'],
                'user_type' => $_SESSION['user_type'],
                'full_name' => $_SESSION['full_name']
            ]);
        } else {
            echo json_encode(['success' => false]);
        }
        break;
        
    default:
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>