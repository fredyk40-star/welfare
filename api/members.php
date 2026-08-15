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
        $search_param = "%{$search_term}%";

        // Build phone-matching clauses that tolerate country-code differences
        // (e.g. 0595360050 vs 233595360050 vs +233 595360050).
        $phone_where = "phone LIKE :search3";
        $phone_params = [];
        $variants = getPhoneSearchVariants($search_term);
        if ($variants) {
            $clauses = [];
            foreach ($variants as $i => $v) {
                $key = ":phone_v{$i}";
                $clauses[] = "REPLACE(REPLACE(REPLACE(phone,' ',''),'+',''),'-','') LIKE {$key}";
                $phone_params[$key] = "%{$v}%";
            }
            $phone_where = "(" . implode(' OR ', $clauses) . ")";
        }

        $query = "SELECT member_id, full_name, passport_photo, phone, email 
                  FROM members 
                  WHERE (member_id LIKE :search1 OR full_name LIKE :search2 OR {$phone_where})
                  AND member_id != :treasurer_id
                  LIMIT 10";
        $stmt = $db->prepare($query);
        $stmt->execute(array_merge([
            ':search1' => $search_param,
            ':search2' => $search_param,
            ':search3' => $search_param,
            ':treasurer_id' => TREASURER_MEMBER_ID
        ], $phone_params));
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

            // Annual target from current calendar year
            $settings = getYearlyTarget($db, date('Y'));
            $safe_member['annual_target'] = $settings['annual_amount'];
            $safe_member['monthly_target'] = $settings['monthly_amount'];

            // Year-to-date paid (excluding void transactions)
            $ytd = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE member_id = :mid AND billing_cycle_year = :yr AND status != 'void'");
            $ytd->execute([':mid' => $member_id, ':yr' => $year]);
            $safe_member['ytd_paid'] = (float) $ytd->fetch()['total'];
            $safe_member['year_debt'] = max(0.0, $safe_member['annual_target'] - $safe_member['ytd_paid']);
            $safe_member['year_pct'] = $safe_member['annual_target'] > 0 ? min(100.0, round(($safe_member['ytd_paid'] / $safe_member['annual_target']) * 100, 1)) : 0.0;

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
            $phone_where = "phone LIKE :s3";
            $phone_params = [];
            $variants = getPhoneSearchVariants($term);
            if ($variants) {
                $clauses = [];
                foreach ($variants as $i => $v) {
                    $key = ":phone_l{$i}";
                    $clauses[] = "REPLACE(REPLACE(REPLACE(phone,' ',''),'+',''),'-','') LIKE {$key}";
                    $phone_params[$key] = "%{$v}%";
                }
                $phone_where = "(" . implode(' OR ', $clauses) . ")";
            }
            $query = "SELECT member_id, full_name, passport_photo, phone
                      FROM members
                      WHERE (member_id LIKE :s1 OR full_name LIKE :s2 OR {$phone_where})
                      AND member_id != :treasurer_id
                      ORDER BY full_name ASC
                      LIMIT 200";
            $stmt = $db->prepare($query);
            $stmt->execute(array_merge([
                ':s1' => "%{$term}%", ':s2' => "%{$term}%", ':s3' => "%{$term}%",
                ':treasurer_id' => TREASURER_MEMBER_ID
            ], $phone_params));
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

    case 'defaulters':
        if (!isTreasurer()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        if (!checkRateLimit($ip_address, 20, 60, '%defaulters%')) {
            echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please try again later.']);
            exit();
        }
        $month = (int) ($_GET['month'] ?? date('m'));
        $year = (int) ($_GET['year'] ?? date('Y'));
        $stmt = $db->prepare("
            SELECT m.member_id, m.full_name, m.email, m.phone,
                   (SELECT COALESCE(SUM(amount), 0)
                    FROM transactions t
                    WHERE t.member_id = m.member_id AND t.billing_cycle_year = :y AND t.status != 'void') AS ytd
            FROM members m
            WHERE m.member_id != :treasurer_id
              AND m.member_id NOT IN (
                  SELECT DISTINCT member_id FROM transactions
                  WHERE billing_cycle_month = :m AND billing_cycle_year = :y2 AND status != 'void'
              )
            ORDER BY m.full_name ASC
        ");
        $stmt->execute([':y' => $year, ':treasurer_id' => TREASURER_MEMBER_ID, ':m' => $month, ':y2' => $year]);
        $defaulters = $stmt->fetchAll();
        echo json_encode(['success' => true, 'defaulters' => $defaulters, 'month' => $month, 'year' => $year]);
        break;

    case 'send_reminder':
    case 'send_reminder_all':
        if (!isTreasurer()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid request method']);
            exit();
        }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit();
        }
        if (!checkRateLimit($ip_address, 10, 60, '%remind%')) {
            echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please try again later.']);
            exit();
        }
        $month = (int) ($_POST['month'] ?? date('m'));
        $year = (int) ($_POST['year'] ?? date('Y'));
        $month_name = date('F', mktime(0, 0, 0, $month, 1));
        $settings = getYearlyTarget($db, $year);
        $amount_due = (float) ($settings['monthly_amount'] ?? $settings['annual_amount'] / 12 ?? 0);

        if ($action === 'send_reminder_all') {
            // Send to every defaulter for the cycle (capped for safety)
            $list = $db->prepare("
                SELECT m.member_id, m.full_name, m.email
                FROM members m
                WHERE m.member_id != :treasurer_id
                  AND m.member_id NOT IN (
                      SELECT DISTINCT member_id FROM transactions
                      WHERE billing_cycle_month = :m AND billing_cycle_year = :y AND status != 'void'
                  )
                ORDER BY m.full_name ASC LIMIT 200
            ");
            $list->execute([':treasurer_id' => TREASURER_MEMBER_ID, ':m' => $month, ':y' => $year]);
            $targets = $list->fetchAll();
        } else {
            $member_id = cleanInput($_POST['member_id'] ?? '');
            $single = $db->prepare("SELECT member_id, full_name, email FROM members WHERE member_id = :mid");
            $single->execute([':mid' => $member_id]);
            $t = $single->fetch();
            if (!$t) {
                echo json_encode(['success' => false, 'message' => 'Member not found']);
                exit();
            }
            $targets = [$t];
        }

        $sent = 0;
        $failed = 0;
        foreach ($targets as $t) {
            if (sendReminderEmail($t['email'], $t['full_name'], $month_name, $year, $amount_due)) {
                $sent++;
                logAudit($_SESSION['user_id'], "Sent payment reminder to {$t['member_id']} for {$month_name} {$year}");
            } else {
                $failed++;
            }
        }
        echo json_encode([
            'success' => $sent > 0,
            'message' => "Reminders sent: {$sent}, failed: {$failed}",
            'sent' => $sent,
            'failed' => $failed
        ]);
        break;

    case 'import_csv':
        if (!isTreasurer()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid request method']);
            exit();
        }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit();
        }
        if (!checkRateLimit($ip_address, 5, 600, '%import%')) {
            echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please try again later.']);
            exit();
        }
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded']);
            exit();
        }
        if (!is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid upload']);
            exit();
        }
        if ($_FILES['csv_file']['size'] > 2 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'File too large (max 2MB)']);
            exit();
        }


        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['csv_file']['tmp_name']);
        $allowed = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
        if (!in_array($mime, $allowed, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Please upload a CSV file.']);
            exit();
        }

        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if ($handle === false) {
            echo json_encode(['success' => false, 'message' => 'Could not read file']);
            exit();
        }

        // Read header row and map columns by name (case-insensitive)
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            echo json_encode(['success' => false, 'message' => 'Empty or invalid CSV']);
            exit();
        }
        $col_map = [];
        foreach ($header as $i => $name) {
            $col_map[strtolower(trim($name))] = $i;
        }
        $idx = function ($key, $required = false) use ($col_map, $header) {
            if (array_key_exists($key, $col_map)) {
                return $col_map[$key];
            }
            // Allow positional fallback for minimal files (full_name,email,phone)
            return null;
        };

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $generated = []; // member_id => plain temp password (for treasurer to share)

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 3 || (implode('', $row) === '')) {
                continue; // skip blank lines
            }
            $get = function ($key, $default = '') use ($col_map, $row) {
                if (!array_key_exists($key, $col_map)) {
                    return $default;
                }
                $v = $row[$col_map[$key]] ?? '';
                return is_string($v) ? trim($v) : $v;
            };
            $full_name = $get('full_name');
            $email = $get('email');
            $phone_raw = $get('phone');
            $phone = preg_replace('/\D/', '', (string) $phone_raw);

            if ($full_name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($phone) < 7 || strlen($phone) > 15) {
                $skipped++;
                $errors[] = "Skipped: " . ($full_name ?: 'row') . " (invalid name/email/phone)";
                continue;
            }

            // Duplicate checks (email/phone only; member_id is generated fresh)
            $dup2 = $db->prepare("SELECT 1 FROM members WHERE email = :e OR phone = :p");
            $dup2->execute([':e' => $email, ':p' => $phone_raw]);
            if ($dup2->fetch()) {
                $skipped++;
                $errors[] = "Skipped: {$full_name} ({$email}) already exists";
                continue;
            }

            $member_id = generateMemberId($db);
            $temp_password = 'Welf@' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            $hashed = password_hash($temp_password, PASSWORD_BCRYPT);

            $dob = $get('dob') ?: '2000-01-01';
            $gender = in_array($get('gender'), ['Male', 'Female', 'Other'], true) ? $get('gender') : 'Other';
            $address = $get('address') ?: 'N/A';
            $occupation = $get('occupation') ?: null;
            $ec_name = $get('emergency_contact_name') ?: 'N/A';
            $ec_rel = $get('emergency_contact_relationship') ?: 'N/A';
            $ec_phone = preg_replace('/\D/', '', (string) $get('emergency_contact_phone')) ?: $phone;

            $ins = $db->prepare("
                INSERT INTO members
                    (member_id, full_name, dob, gender, email, phone, address, occupation,
                     emergency_contact_name, emergency_contact_relationship, emergency_contact_phone, password, created_at)
                VALUES
                    (:member_id, :full_name, :dob, :gender, :email, :phone, :address, :occupation,
                     :ec_name, :ec_rel, :ec_phone, :password, NOW())
            ");
            $ok = $ins->execute([
                ':member_id' => $member_id,
                ':full_name' => $full_name,
                ':dob' => $dob,
                ':gender' => $gender,
                ':email' => $email,
                ':phone' => $phone_raw,
                ':address' => $address,
                ':occupation' => $occupation,
                ':ec_name' => $ec_name,
                ':ec_rel' => $ec_rel,
                ':ec_phone' => $ec_phone,
                ':password' => $hashed
            ]);
            if ($ok) {
                $imported++;
                $generated[$member_id] = $temp_password;
                logAudit($_SESSION['user_id'], "Imported member {$member_id} ({$full_name}) via CSV");
            } else {
                $skipped++;
                $errors[] = "Failed to insert: {$full_name}";
            }
        }
        fclose($handle);

        echo json_encode([
            'success' => true,
            'message' => "Imported {$imported} member(s), skipped {$skipped}.",
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'generated' => $generated
        ]);
        break;

    case 'update_status':
        if (!isTreasurer()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid request method']);
            exit();
        }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit();
        }
        if (!checkRateLimit($ip_address, 20, 300, '%status%')) {
            echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please try again later.']);
            exit();
        }

        $member_id = cleanInput($_POST['member_id'] ?? '');
        $new_status = cleanInput($_POST['status'] ?? '');

        if ($member_id === '' || !in_array($new_status, ['active', 'suspended', 'deactivated', 'deleted'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid member or status.']);
            exit();
        }
        if ($member_id === TREASURER_MEMBER_ID) {
            echo json_encode(['success' => false, 'message' => 'The treasurer account cannot be modified.']);
            exit();
        }

        // Guard against deleting a member who is already permanently banned (3 strikes).
        if ($new_status === 'deleted' && isMemberPermanentlyBanned($member_id, $db)) {
            echo json_encode([
                'success' => false,
                'message' => 'This member is permanently banned and cannot be deleted again (3 deletions reached).',
                'banned' => true
            ]);
            exit();
        }

        $result = updateMemberStatus($db, $member_id, $new_status, $_SESSION['user_id']);

        // Notify the member by email about the status change (best-effort).
        if ($result['success']) {
            $stmt = $db->prepare("SELECT full_name, email FROM members WHERE member_id = :mid");
            $stmt->execute([':mid' => $member_id]);
            $member_row = $stmt->fetch();
            if ($member_row && !empty($member_row['email'])) {
                $status_labels = [
                    'active' => 'reactivated',
                    'suspended' => 'suspended',
                    'deactivated' => 'deactivated',
                    'deleted' => 'deleted',
                ];
                $verb = $status_labels[$new_status] ?? $new_status;
                $subject = 'Your ' . APP_NAME . ' account has been ' . $verb;
                $message = '<p>Dear ' . htmlspecialchars($member_row['full_name']) . ',</p>';
                $message .= '<p>Your welfare account status has been updated to: <strong>' . htmlspecialchars($verb) . '</strong>.</p>';
                if ($new_status === 'suspended' || $new_status === 'deactivated' || $new_status === 'deleted') {
                    $message .= '<p>You will not be able to log in while your account is ' . htmlspecialchars($verb) . '. Please contact the treasurer if you believe this is a mistake.</p>';
                } elseif ($new_status === 'active') {
                    $message .= '<p>You can now log in to your account.</p>';
                }
                $message .= '<p>— ' . htmlspecialchars(APP_NAME) . ' Team</p>';
                @sendEmail($member_row['email'], $subject, $message);
            }
        }

        echo json_encode($result);
        break;

    case 'reset_database':
        if (!isTreasurer()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid request method']);
            exit();
        }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit();
        }
        if (!checkRateLimit($ip_address, 3, 3600, '%reset%')) {
            echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please try again later.']);
            exit();
        }
        // Require an explicit typed confirmation to prevent accidental wipes.
        if (cleanInput($_POST['confirm'] ?? '') !== 'RESET') {
            echo json_encode(['success' => false, 'message' => 'Type RESET to confirm the database reset.']);
            exit();
        }

        $options = [
            'transactions' => isset($_POST['reset_transactions']),
            'audit_logs' => isset($_POST['reset_audit_logs']),
            'password_resets' => isset($_POST['reset_password_resets']),
            'members' => isset($_POST['reset_members']),
        ];
        if (!array_filter($options)) {
            echo json_encode(['success' => false, 'message' => 'Select at least one table to reset.']);
            exit();
        }

        // Never reset the treasurer account or system settings row.
        $result = resetDatabase($db, TREASURER_MEMBER_ID, $options);
        echo json_encode($result);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit();
}
?>
