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

$action = isset($_GET['action']) ? cleanInput($_GET['action']) : '';
$ip_address = getClientIp();

switch ($action) {
    case 'search':
        if (!isTreasurer()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        
        if (!checkRateLimit($ip_address, 30, 60, '%members%')) {
            echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please try again later.']);
            exit();
        }
        
        $search_term = cleanInput($_GET['term']);
        $query = "SELECT member_id, full_name, passport_photo, phone, email 
                  FROM members 
                  WHERE (member_id LIKE :search1 OR full_name LIKE :search2 OR phone LIKE :search3)
                  AND member_id != :treasurer_id
                  LIMIT 10";
        $stmt = $db->prepare($query);
        $search_param = "%{$search_term}%";
        $stmt->execute([
            ':search1' => $search_param,
            ':search2' => $search_param,
            ':search3' => $search_param,
            ':treasurer_id' => TREASURER_MEMBER_ID
        ]);
        $members = $stmt->fetchAll();
        foreach ($members as &$m) {
            if (!empty($m['passport_photo'])) {
                $m['passport_photo'] = photoValueForApi($m['passport_photo']);
            }
        }
        unset($m);
        
        logAudit($_SESSION['user_id'], "API: Member search for '{$search_term}'");
        
        echo json_encode(['success' => true, 'members' => $members]);
        break;
        
    case 'details':
        if (!isTreasurer()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        
        if (!checkRateLimit($ip_address, 30, 60, '%members%')) {
            echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please try again later.']);
            exit();
        }
        
        $member_id = cleanInput($_GET['member_id']);

        $year = (int) date('Y');
        $member_stmt = $db->prepare("SELECT * FROM members WHERE member_id = :member_id");
        $member_stmt->execute([':member_id' => $member_id]);
        $member = $member_stmt->fetch();

        if ($member) {
            // Whitelist safe fields to prevent accidental exposure of sensitive columns
            $allowed_fields = [
                'member_id', 'full_name', 'dob', 'gender', 'email', 'phone',
                'address', 'occupation', 'emergency_contact_name',
                'emergency_contact_relationship', 'emergency_contact_phone',
                'passport_photo', 'created_at'
            ];
            $safe_member = [];
            foreach ($allowed_fields as $field) {
                if (isset($member[$field])) {
                    $safe_member[$field] = $field === 'passport_photo' ? photoValueForApi($member[$field]) : $member[$field];
                }
            }

            // Annual target from settings
            $settings = getWelfareSettings($db);
            $safe_member['annual_target'] = $settings['annual_amount'];

            // Year-to-date paid (excluding void transactions)
            $ytd = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE member_id = :mid AND billing_cycle_year = :yr AND status != 'void'");
            $ytd->execute([':mid' => $member_id, ':yr' => $year]);
            $safe_member['ytd_paid'] = (float) $ytd->fetch()['total'];

            logAudit($_SESSION['user_id'], "API: Viewed member details for {$member_id}");
            
            echo json_encode(['success' => true, 'member' => $safe_member]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Member not found']);
        }
        break;
        
    case 'list':
        if (!isTreasurer()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }

        if (!checkRateLimit($ip_address, 30, 60, '%members%')) {
            echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please try again later.']);
            exit();
        }

        // Optional search term (reuse same matching as search)
        $term = cleanInput($_GET['term'] ?? '');
        if ($term !== '') {
            $query = "SELECT member_id, full_name, passport_photo, phone
                      FROM members
                      WHERE (member_id LIKE :s1 OR full_name LIKE :s2 OR phone LIKE :s3)
                      AND member_id != :treasurer_id
                      ORDER BY full_name ASC
                      LIMIT 200";
            $stmt = $db->prepare($query);
            $sp = "%{$term}%";
            $stmt->execute([
                ':s1' => $sp, ':s2' => $sp, ':s3' => $sp,
                ':treasurer_id' => TREASURER_MEMBER_ID
            ]);
        } else {
            $query = "SELECT member_id, full_name, passport_photo, phone
                      FROM members
                      WHERE member_id != :treasurer_id
                      ORDER BY full_name ASC
                      LIMIT 200";
            $stmt = $db->prepare($query);
            $stmt->execute([':treasurer_id' => TREASURER_MEMBER_ID]);
        }
        $members = $stmt->fetchAll();
        foreach ($members as &$m) {
            if (!empty($m['passport_photo'])) {
                $m['passport_photo'] = photoValueForApi($m['passport_photo']);
            }
        }
        unset($m);

        echo json_encode(['success' => true, 'members' => $members]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit();
}
?>