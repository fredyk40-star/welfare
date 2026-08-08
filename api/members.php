<?php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'search':
        if (!isTreasurer()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        
        $search_term = sanitizeInput($_GET['term']);
        $query = "SELECT member_id, full_name, passport_photo, phone, email 
                  FROM members 
                  WHERE (member_id LIKE :search1 OR full_name LIKE :search2 OR phone LIKE :search3)
                  AND member_id != 'GYF-ADMIN'
                  LIMIT 10";
        $stmt = $db->prepare($query);
        $search_param = "%{$search_term}%";
        $stmt->execute([
            ':search1' => $search_param,
            ':search2' => $search_param,
            ':search3' => $search_param
        ]);
        $members = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'members' => $members]);
        break;
        
    case 'details':
        if (!isTreasurer()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        
        $member_id = sanitizeInput($_GET['member_id']);
        $query = "SELECT * FROM members WHERE member_id = :member_id";
        $stmt = $db->prepare($query);
        $stmt->execute([':member_id' => $member_id]);
        $member = $stmt->fetch();
        
        if ($member) {
            unset($member['password']); // Remove sensitive data
            echo json_encode(['success' => true, 'member' => $member]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Member not found']);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>