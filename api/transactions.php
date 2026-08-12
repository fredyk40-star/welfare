<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();

$action = isset($_GET['action']) ? cleanInput($_GET['action']) : '';

switch ($action) {
    case 'check_duplicate':
        if (!isTreasurer()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        if (!checkRateLimit($_SESSION['user_id'] ?? getClientIp(), 10, 60, '%check_duplicate%')) {
            echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
            exit();
        }
        $member_id = cleanInput($_GET['member_id'] ?? '');
        $month = (int) ($_GET['month'] ?? 0);
        $year = (int) ($_GET['year'] ?? 0);

        if (!$member_id || !$month || !$year) {
            echo json_encode(['success' => false, 'message' => 'Missing required parameters', 'exists' => false]);
            exit();
        }

        $stmt = $db->prepare("SELECT receipt_no FROM transactions WHERE member_id = :mid AND billing_cycle_month = :m AND billing_cycle_year = :y AND status != 'void' LIMIT 1");
        $stmt->execute([':mid' => $member_id, ':m' => $month, ':y' => $year]);
        $row = $stmt->fetch();

        if ($row) {
            echo json_encode([
                'success' => true,
                'exists' => true,
                'receipt_no' => $row['receipt_no'],
                'month_name' => date('F', mktime(0, 0, 0, $month, 1)),
                'year' => $year
            ]);
        } else {
            echo json_encode(['success' => true, 'exists' => false]);
        }
        exit();

    case 'details':
        if (!isLoggedIn()) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit();
        }
        $transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$transaction_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid transaction ID']);
            exit();
        }
        $query = "SELECT t.id, t.receipt_no, t.member_id, t.treasurer_id, t.amount, t.payment_method, t.billing_cycle_month, t.billing_cycle_year, t.notes, t.status, t.transaction_date, m.full_name, m.member_id as m_id 
                  FROM transactions t 
                  JOIN members m ON t.member_id = m.member_id 
                  WHERE t.id = :id";
        $stmt = $db->prepare($query);
        $stmt->execute([':id' => $transaction_id]);
        $transaction = $stmt->fetch();
        if ($transaction) {
            if ($_SESSION['user_type'] !== 'treasurer' && $_SESSION['user_id'] !== $transaction['m_id']) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit();
            }
            // Whitelist response fields to avoid leaking internal data
            $allowed = ['id', 'receipt_no', 'member_id', 'amount', 'payment_method', 'billing_cycle_month', 'billing_cycle_year', 'notes', 'status', 'transaction_date', 'full_name'];
            $safe = array_intersect_key($transaction, array_flip($allowed));
            echo json_encode(['success' => true, 'transaction' => $safe]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Transaction not found']);
        }
        exit();

    case 'receipt':
        if (!isLoggedIn()) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit();
        }
        // NOTE: intentionally NOT requiring a CSRF token. This is a read-only GET
        // that only renders a receipt (no state change) and is already authz-gated
        // below. Requiring CSRF here broke every receipt View/Print/Download caller
        // (none of them send a token). CSRF is enforced only on state-changing POSTs.
        
        $transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        $query = "SELECT t.*, m.full_name, m.member_id as m_id 
                  FROM transactions t 
                  JOIN members m ON t.member_id = m.member_id 
                  WHERE t.id = :id";
        $stmt = $db->prepare($query);
        $stmt->execute([':id' => $transaction_id]);
        $transaction = $stmt->fetch();
        
        if (!$transaction) {
            echo json_encode(['success' => false, 'message' => 'Transaction not found']);
            exit();
        }
        
        // Only allow viewing if treasurer or transaction owner
        if ($_SESSION['user_type'] !== 'treasurer' && $_SESSION['user_id'] !== $transaction['m_id']) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        
        renderReceipt($transaction, true, true);
        exit();
        
    case 'member_receipt':
        if (!isMember()) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit();
        }
        // NOTE: intentionally NOT requiring a CSRF token. Read-only GET that renders
        // a receipt and is already authz-gated (member_id pinned to the session).
        // Requiring CSRF here broke every member receipt View/Print/Download caller.
        
        $receipt_no = cleanInput($_GET['receipt_no']);
        
        $query = "SELECT t.*, m.full_name 
                  FROM transactions t 
                  JOIN members m ON t.member_id = m.member_id 
                  WHERE t.receipt_no = :receipt_no AND t.member_id = :member_id";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':receipt_no' => $receipt_no,
            ':member_id' => $_SESSION['user_id']
        ]);
        $transaction = $stmt->fetch();

        if (!$transaction) {
            echo json_encode(['success' => false, 'message' => 'Receipt not found']);
            exit();
        }
        
        if (isset($_GET['download'])) {
            // Sanitize filename for header
            $safe_filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $receipt_no);
            header('Content-Disposition: attachment; filename="receipt_' . $safe_filename . '.html"');
        }
        renderReceipt($transaction, false, false);
        exit();
        
    case 'export_csv':
        if (!isLoggedIn()) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit();
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid request method']);
            exit();
        }
        if (!checkRateLimit($_SESSION['user_id'] ?? getClientIp(), 5, 60, '%export_csv%')) {
            echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please try again later.']);
            exit();
        }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit();
        }
        
        // Set headers for CSV download - must be before any output
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="transactions_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add CSV headers
        fputcsv($output, ['Receipt No', 'Member', 'Amount', 'Method', 'Billing Period', 'Date']);
        
        $stmt = buildTransactionFilter($db);
        
        while ($row = $stmt->fetch()) {
            fputcsv($output, [
                $row['receipt_no'],
                $row['full_name'],
                $row['amount'],
                $row['payment_method'],
                $row['billing_cycle_month'] ? formatBillingPeriod($row['billing_cycle_month'], $row['billing_cycle_year']) : $row['billing_cycle_year'],
                $row['transaction_date']
            ]);
        }
        
        fclose($output);
        exit();
        
    case 'export_pdf':
        if (!isLoggedIn()) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit();
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid request method']);
            exit();
        }
        if (!checkRateLimit($_SESSION['user_id'] ?? getClientIp(), 5, 60, '%export_pdf%')) {
            echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please try again later.']);
            exit();
        }
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit();
        }
        
        $stmt = buildTransactionFilter($db);
        $transactions = $stmt->fetchAll();
        $title = ($_SESSION['user_type'] ?? 'member') === 'treasurer' ? 'All Transactions - GYF Welfare' : 'My Transactions - GYF Welfare';
        
        // Generate print-friendly HTML for PDF export
        header('Content-Type: text/html; charset=UTF-8');
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title><?php echo htmlspecialchars($title); ?></title>
            <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/bootstrap/css/bootstrap.min.css">
            <style>
                body { padding: 20px; }
                @media print {
                    .no-print { display: none; }
                    body { padding: 0; }
                }
                .receipt {
                    background: #f8f9fa;
                    border: 1px solid #dee2e6;
                    border-radius: 8px;
                    padding: 15px;
                    margin-bottom: 15px;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><?php echo htmlspecialchars($title); ?></h2>
                    <button class="btn btn-primary no-print" id="printBtn">🖨️ Print / Save as PDF</button>
                </div>
                
                <?php if (empty($transactions)): ?>
                    <div class="alert alert-info">No transactions found.</div>
                <?php else: ?>
                    <?php foreach ($transactions as $row): ?>
                        <div class="receipt">
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>Receipt No:</strong> <?php echo htmlspecialchars($row['receipt_no']); ?><br>
                                    <strong>Member:</strong> <?php echo htmlspecialchars($row['full_name']); ?> (<?php echo htmlspecialchars($row['member_id'] ?? $row['m_id']); ?>)<br>
                                    <strong>Amount:</strong> <span class="text-success fw-bold">GH₵ <?php echo number_format($row['amount'], 2); ?></span>
                                </div>
                                <div class="col-md-6 text-md-end">
                                    <strong>Payment Method:</strong> <?php echo htmlspecialchars($row['payment_method']); ?><br>
                                    <strong>Billing Period:</strong> 
                                        <?php echo $row['billing_cycle_month'] ? formatBillingPeriod($row['billing_cycle_month'], $row['billing_cycle_year']) : htmlspecialchars($row['billing_cycle_year']); ?><br>
                                    <strong>Date:</strong> <?php echo !empty($row['transaction_date']) ? htmlspecialchars(date('F j, Y g:i A', strtotime($row['transaction_date']))) : 'N/A'; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <div class="mt-4 text-muted text-center no-print">
                    <small>Generated on <?php echo date('F j, Y g:i A'); ?> | GYF Welfare Management System</small>
                </div>
            </div>
            
            <script nonce="<?php echo CSP_NONCE; ?>">
                <?php if (isset($_GET['print'])): ?>
                document.addEventListener('DOMContentLoaded', function() {
                    window.print();
                });
                <?php endif; ?>
                
                var printBtn = document.getElementById('printBtn');
                if (printBtn) {
                    printBtn.addEventListener('click', window.print);
                }
            </script>
        </body>
        </html>
        <?php
        exit();
        
    case 'void':
        if (!isLoggedIn() || !isTreasurer()) {
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
        if (!checkRateLimit($_SESSION['user_id'] ?? getClientIp(), 10, 60, '%void%')) {
            echo json_encode(['success' => false, 'message' => 'Rate limit exceeded. Please try again later.']);
            exit();
        }
        $transaction_id = isset($_POST['transaction_id']) ? intval($_POST['transaction_id']) : 0;
        $reason = cleanInput($_POST['reason'] ?? '');
        if (!$transaction_id || empty($reason)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit();
        }
        $stmt = $db->prepare("UPDATE transactions SET status = 'void', notes = :reason WHERE id = :id AND status != 'void'");
        $result = $stmt->execute([':reason' => $reason, ':id' => $transaction_id]);
        if ($result && $stmt->rowCount() > 0) {
            logAudit($_SESSION['user_id'], "Voided transaction ID {$transaction_id}: {$reason}");
            echo json_encode(['success' => true, 'message' => 'Transaction voided']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to void transaction or already voided']);
        }
        exit();
        
    case 'member_history':
        if (!isLoggedIn() || !isTreasurer()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        $member_id = cleanInput($_GET['member_id'] ?? '');
        if (!$member_id) {
            echo json_encode(['success' => false, 'message' => 'Member ID required']);
            exit();
        }
        $stmt = $db->prepare("SELECT t.id, t.receipt_no, t.member_id, t.amount, t.payment_method, t.billing_cycle_month, t.billing_cycle_year, t.notes, t.status, t.transaction_date, m.full_name FROM transactions t JOIN members m ON t.member_id = m.member_id WHERE t.member_id = :mid AND t.status != 'void' ORDER BY t.transaction_date DESC LIMIT 50");
        $stmt->execute([':mid' => $member_id]);
        $history = $stmt->fetchAll();
        
        // Whitelist response fields
        $allowed = ['id', 'receipt_no', 'member_id', 'amount', 'payment_method', 'billing_cycle_month', 'billing_cycle_year', 'notes', 'status', 'transaction_date', 'full_name'];
        $safe_history = [];
        foreach ($history as $row) {
            $safe_history[] = array_intersect_key($row, array_flip($allowed));
        }
        
        echo json_encode(['success' => true, 'history' => $safe_history]);
        exit();

    case 'batch_payment':
        if (!isLoggedIn() || !isTreasurer()) {
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
        $member_ids_raw = $_POST['member_ids'] ?? [];
        if (is_string($member_ids_raw)) {
            $member_ids = json_decode($member_ids_raw, true);
            if (!is_array($member_ids)) {
                echo json_encode(['success' => false, 'message' => 'Invalid member IDs']);
                exit();
            }
        } else {
            $member_ids = $member_ids_raw;
        }
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
        $payment_method = cleanInput($_POST['payment_method'] ?? '');
        $billing_month = (int)$_POST['billing_month'] ?? 0;
        $billing_year = (int)$_POST['billing_year'] ?? 0;
        $notes = cleanInput($_POST['notes'] ?? '');
        $transaction_date = cleanInput($_POST['transaction_date'] ?? '');
        if (empty($transaction_date) || DateTime::createFromFormat('Y-m-d', $transaction_date) === false) {
            $transaction_date = date('Y-m-d');
        }
        $transaction_time = cleanInput($_POST['transaction_time'] ?? '');
        if (empty($transaction_time) || DateTime::createFromFormat('H:i', $transaction_time) === false) {
            $transaction_time = date('H:i');
        }
        $transaction_datetime = $transaction_date . ' ' . $transaction_time . ':00';
        
        // Validate amount upper bound
        if ($amount <= 0 || $amount > 999999.99) {
            echo json_encode(['success' => false, 'message' => 'Invalid amount. Must be between 0.01 and 999,999.99']);
            exit();
        }
        
        if (empty($member_ids) || !is_array($member_ids) || empty($payment_method) || empty($billing_month) || empty($billing_year)) {
            echo json_encode(['success' => false, 'message' => 'All fields required']);
            exit();
        }
        if (count($member_ids) > 100) {
            echo json_encode(['success' => false, 'message' => 'Maximum 100 members per batch']);
            exit();
        }
        
        $success_count = 0;
        $fail_count = 0;
        
        // CRITICAL FIX: Wrap batch payment in transaction for atomicity
        $receipt_jobs = []; // emails queued during the transaction, sent after commit
        try {
            $db->beginTransaction();
            
            foreach ($member_ids as $mid) {
                $mid = cleanInput($mid);
                if (empty($mid)) continue;
                $member_check = $db->prepare("SELECT id FROM members WHERE member_id = :mid");
                $member_check->execute([':mid' => $mid]);
                if (!$member_check->fetch()) { $fail_count++; continue; }
                $dup_check = $db->prepare("SELECT id FROM transactions WHERE member_id = :mid AND billing_cycle_month = :m AND billing_cycle_year = :y AND status != 'void'");
                $dup_check->execute([':mid' => $mid, ':m' => $billing_month, ':y' => $billing_year]);
                if ($dup_check->fetch()) { $fail_count++; continue; }
                
                // Annual limit check per member
                $yearly_stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE member_id = :mid AND billing_cycle_year = :y AND status != 'void'");
                $yearly_stmt->execute([':mid' => $mid, ':y' => $billing_year]);
                $yearly_total = $yearly_stmt->fetch()['total'];
                $settings_stmt = $db->prepare("SELECT annual_amount FROM settings WHERE id = 1");
                $settings_stmt->execute();
                $settings = $settings_stmt->fetch();
                $annual_limit = $settings ? $settings['annual_amount'] : 0;
                if (($yearly_total + $amount) > $annual_limit) { $fail_count++; continue; }
                
                $receipt_no = generateReceiptNumber();
                $insert = $db->prepare("INSERT INTO transactions (receipt_no, member_id, treasurer_id, amount, payment_method, billing_cycle_month, billing_cycle_year, notes, transaction_date) VALUES (:receipt_no, :member_id, :treasurer_id, :amount, :payment_method, :billing_month, :billing_year, :notes, :transaction_date)");
                $result = $insert->execute([
                    ':receipt_no' => $receipt_no,
                    ':member_id' => $mid,
                    ':treasurer_id' => $_SESSION['user_id'],
                    ':amount' => $amount,
                    ':payment_method' => $payment_method,
                    ':billing_month' => $billing_month,
                    ':billing_year' => $billing_year,
                    ':notes' => $notes,
                    ':transaction_date' => $transaction_datetime
                ]);
                if ($result) {
                    $success_count++;
                    logAudit($_SESSION['user_id'], "Batch payment: GH₵ {$amount} for {$mid}");
                    // Queue receipt email (sent after commit so a mail failure
                    // never rolls back the payment and the DB tx stays short).
                    $receipt_jobs[] = ['mid' => $mid, 'receipt_no' => $receipt_no];
                } else {
                    $fail_count++;
                }
            }
            
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            error_log("Batch Transaction Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Batch processing failed. Please try again.']);
            exit();
        }

        // Send receipt emails AFTER the transaction is committed.
        $treasurer_email = '';
        $t_stmt = $db->prepare("SELECT email FROM members WHERE member_id = :mid");
        $t_stmt->execute([':mid' => $_SESSION['user_id']]);
        $t = $t_stmt->fetch();
        if ($t) { $treasurer_email = $t['email']; }
        foreach ($receipt_jobs as $job) {
            try {
                $mid = $job['mid'];
                $m_stmt = $db->prepare("SELECT email, full_name, passport_photo FROM members WHERE member_id = :mid");
                $m_stmt->execute([':mid' => $mid]);
                $m = $m_stmt->fetch();
                if (!$m) continue;
                $receipt_data = [
                    'receipt_no'      => $job['receipt_no'],
                    'member_name'     => $m['full_name'],
                    'member_id'       => $mid,
                    'amount'          => $amount,
                    'payment_method'  => $payment_method,
                    'billing_period'  => date('F Y', mktime(0, 0, 0, $billing_month, 1, $billing_year)),
                    'date'            => $transaction_datetime
                ];
                sendReceiptEmail($m['email'], $receipt_data, $m['passport_photo'], $treasurer_email);
            } catch (Exception $e) {
                error_log('Batch receipt email error: ' . $e->getMessage());
            }
        }
        
        echo json_encode(['success' => true, 'message' => "Batch complete: {$success_count} recorded, {$fail_count} failed", 'success_count' => $success_count, 'fail_count' => $fail_count]);
        exit();

    case 'recurring_late':
        if (!isLoggedIn() || !isTreasurer()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        $late_payers = getRecurringLatePayers($db);
        echo json_encode(['success' => true, 'late_payers' => $late_payers]);
        exit();
        
    case 'get_last':
        if (!isLoggedIn() || !isTreasurer()) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit();
        }
        $stmt = $db->query("SELECT t.id, t.receipt_no, t.member_id, t.amount, t.payment_method, t.billing_cycle_month, t.billing_cycle_year, t.transaction_date, m.full_name FROM transactions t JOIN members m ON t.member_id = m.member_id WHERE t.status != 'void' ORDER BY t.id DESC LIMIT 1");
        $last = $stmt->fetch();
        if ($last) {
            $allowed = ['id', 'receipt_no', 'member_id', 'amount', 'payment_method', 'billing_cycle_month', 'billing_cycle_year', 'transaction_date', 'full_name'];
            echo json_encode(['success' => true, 'transaction' => array_intersect_key($last, array_flip($allowed))]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No transactions found']);
        }
        exit();

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit();
}
?>