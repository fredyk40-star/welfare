<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

$database = new Database();
$db = $database->getConnection();

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'receipt':
        if (!isLoggedIn()) {
            die('Unauthorized');
        }
        
        $transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        $query = "SELECT t.*, m.full_name, m.member_id as m_id 
                  FROM transactions t 
                  JOIN members m ON t.member_id = m.member_id 
                  WHERE t.id = :id";
        $stmt = $db->prepare($query);
        $stmt->execute([':id' => $transaction_id]);
        $transaction = $stmt->fetch();
        
        if ($transaction) {
            // Only allow viewing if treasurer or transaction owner
            if ($_SESSION['user_type'] !== 'treasurer' && $_SESSION['user_id'] !== $transaction['m_id']) {
                die('Unauthorized');
            }
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <title>Receipt <?php echo $transaction['receipt_no']; ?></title>
                <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/bootstrap/css/bootstrap.min.css">
                <style>
                    body { padding: 20px; }
                    @media print {
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="receipt">
                        <div class="text-center mb-4">
                            <h3>GYF Welfare Management System</h3>
                            <h4>Payment Receipt</h4>
                        </div>
                        
                        <table class="table table-bordered">
                            <tr>
                                <td><strong>Receipt No:</strong></td>
                                <td><?php echo htmlspecialchars($transaction['receipt_no']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Member:</strong></td>
                                <td><?php echo htmlspecialchars($transaction['full_name']); ?> (<?php echo $transaction['m_id']; ?>)</td>
                            </tr>
                            <tr>
                                <td><strong>Amount:</strong></td>
                                <td>GH₵ <?php echo number_format($transaction['amount'], 2); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Payment Method:</strong></td>
                                <td><?php echo $transaction['payment_method']; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Billing Period:</strong></td>
                                <td>
                                    <?php 
                                    if ($transaction['billing_cycle_month']) {
                                        echo date('F Y', mktime(0, 0, 0, $transaction['billing_cycle_month'], 1, $transaction['billing_cycle_year']));
                                    } else {
                                        echo $transaction['billing_cycle_year'];
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Date:</strong></td>
                                <td><?php echo date('F j, Y g:i A', strtotime($transaction['transaction_date'])); ?></td>
                            </tr>
                        </table>
                        
                        <div class="text-center mt-4">
                            <button class="btn btn-primary no-print" onclick="window.print()">Print Receipt</button>
                        </div>
                    </div>
                </div>
            </body>
            </html>
            <?php
        }
        break;
        
    case 'member_receipt':
        if (!isMember()) {
            die('Unauthorized');
        }
        
        $receipt_no = sanitizeInput($_GET['receipt_no']);
        
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

        if ($transaction) {
            if (isset($_GET['download'])) {
                header('Content-Disposition: attachment; filename="receipt_' . $receipt_no . '.html"');
            }
            // Display receipt (same as above)
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <title>Receipt <?php echo htmlspecialchars($receipt_no); ?></title>
                <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/bootstrap/css/bootstrap.min.css">
                <style>
                    body { padding: 20px; }
                    @media print {
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="receipt">
                        <div class="text-center mb-4">
                            <h3>GYF Welfare Management System</h3>
                            <h4>Payment Receipt</h4>
                        </div>
                        
                        <table class="table table-bordered">
                            <tr>
                                <td><strong>Receipt No:</strong></td>
                                <td><?php echo htmlspecialchars($transaction['receipt_no']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Member:</strong></td>
                                <td><?php echo htmlspecialchars($transaction['full_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Amount:</strong></td>
                                <td>GH₵ <?php echo number_format($transaction['amount'], 2); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Payment Method:</strong></td>
                                <td><?php echo $transaction['payment_method']; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Date:</strong></td>
                                <td><?php echo date('F j, Y g:i A', strtotime($transaction['transaction_date'])); ?></td>
                            </tr>
                        </table>
                        
                        <div class="text-center mt-4">
                            <button class="btn btn-primary no-print" onclick="window.print()">Print Receipt</button>
                        </div>
                    </div>
                </div>
            </body>
            </html>
            <?php
        }
        break;
        
    case 'export_csv':
        if (!isLoggedIn()) {
            die('Unauthorized');
        }
        
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="transactions_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add CSV headers
        fputcsv($output, ['Receipt No', 'Member', 'Amount', 'Method', 'Billing Period', 'Date']);
        
        // Get transactions based on user type
        if ($_SESSION['user_type'] === 'treasurer') {
            $query = "SELECT t.*, m.full_name 
                      FROM transactions t 
                      JOIN members m ON t.member_id = m.member_id 
                      ORDER BY t.transaction_date DESC";
            $stmt = $db->query($query);
        } else {
            $query = "SELECT t.*, m.full_name 
                      FROM transactions t 
                      JOIN members m ON t.member_id = m.member_id 
                      WHERE t.member_id = :member_id 
                      ORDER BY t.transaction_date DESC";
            $stmt = $db->prepare($query);
            $stmt->execute([':member_id' => $_SESSION['user_id']]);
        }
        
        while ($row = $stmt->fetch()) {
            fputcsv($output, [
                $row['receipt_no'],
                $row['full_name'],
                $row['amount'],
                $row['payment_method'],
                $row['billing_cycle_month'] ? date('M Y', mktime(0, 0, 0, $row['billing_cycle_month'], 1, $row['billing_cycle_year'])) : $row['billing_cycle_year'],
                $row['transaction_date']
            ]);
        }
        
        fclose($output);
        exit();
        
    case 'export_pdf':
        if (!isLoggedIn()) {
            die('Unauthorized');
        }
        
        // Get transactions based on user type
        if ($_SESSION['user_type'] === 'treasurer') {
            $query = "SELECT t.*, m.full_name, m.member_id as m_id 
                      FROM transactions t 
                      JOIN members m ON t.member_id = m.member_id 
                      ORDER BY t.transaction_date DESC";
            $stmt = $db->query($query);
            $transactions = $stmt->fetchAll();
            $title = 'All Transactions - GYF Welfare';
        } else {
            $query = "SELECT t.*, m.full_name 
                      FROM transactions t 
                      JOIN members m ON t.member_id = m.member_id 
                      WHERE t.member_id = :member_id 
                      ORDER BY t.transaction_date DESC";
            $stmt = $db->prepare($query);
            $stmt->execute([':member_id' => $_SESSION['user_id']]);
            $transactions = $stmt->fetchAll();
            $title = 'My Transactions - GYF Welfare';
        }
        
        // Generate print-friendly HTML for PDF export
        header('Content-Type: text/html; charset=UTF-8');
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title><?php echo $title; ?></title>
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
                    <h2><?php echo $title; ?></h2>
                    <button class="btn btn-primary no-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
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
                                        <?php echo $row['billing_cycle_month'] ? date('F Y', mktime(0, 0, 0, $row['billing_cycle_month'], 1, $row['billing_cycle_year'])) : $row['billing_cycle_year']; ?><br>
                                    <strong>Date:</strong> <?php echo date('F j, Y g:i A', strtotime($row['transaction_date'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <div class="mt-4 text-muted text-center no-print">
                    <small>Generated on <?php echo date('F j, Y g:i A'); ?> | GYF Welfare Management System</small>
                </div>
            </div>
            
            <script>
                // Auto-trigger print dialog
                window.onload = function() {
                    window.print();
                };
            </script>
        </body>
        </html>
        <?php
        exit();
        
    default:
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>