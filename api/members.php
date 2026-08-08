<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

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

        $year = (int) date('Y');
        $member_stmt = $db->prepare("SELECT * FROM members WHERE member_id = :member_id");
        $member_stmt->execute([':member_id' => $member_id]);
        $member = $member_stmt->fetch();

        if ($member) {
            unset($member['password']); // Remove sensitive data

            // Annual target from settings
            $settings = $db->query("SELECT annual_amount FROM settings WHERE id = 1")->fetch();
            $member['annual_target'] = $settings ? (float) $settings['annual_amount'] : 0;

            // Year-to-date paid
            $ytd = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE member_id = :mid AND billing_cycle_year = :yr");
            $ytd->execute([':mid' => $member_id, ':yr' => $year]);
            $member['ytd_paid'] = (float) $ytd->fetch()['total'];

            echo json_encode(['success' => true, 'member' => $member]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Member not found']);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>