<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if user is member
if (!isMember()) {
    redirectTo('/member/login.php');
}

require_once __DIR__ . '/../includes/header.php';

$database = new Database();
$db = $database->getConnection();
$member_id = $_SESSION['user_id'];

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="my_transactions_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Receipt No', 'Amount', 'Payment Method', 'Billing Period', 'Date']);
    
    $query = "SELECT * FROM transactions WHERE member_id = :member_id AND status != 'void' ORDER BY transaction_date DESC";
    $stmt = $db->prepare($query);
    $stmt->execute([':member_id' => $member_id]);
    
    while ($row = $stmt->fetch()) {
        $billing_period = $row['billing_cycle_month'] ? formatBillingPeriod($row['billing_cycle_month'], $row['billing_cycle_year']) : $row['billing_cycle_year'];
            
        fputcsv($output, [
            $row['receipt_no'],
            $row['amount'],
            $row['payment_method'],
            $billing_period,
            $row['transaction_date']
        ]);
    }
    
    fclose($output);
    exit();
}

// Handle PDF export (print-friendly HTML that can be saved as PDF)
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    // Set headers for HTML print view
    header('Content-Type: text/html; charset=UTF-8');
    
    // Get transactions
    $query = "SELECT t.*, m.full_name 
              FROM transactions t 
              JOIN members m ON t.member_id = m.member_id 
              WHERE t.member_id = :member_id 
              ORDER BY t.transaction_date DESC";
    $stmt = $db->prepare($query);
    $stmt->execute([':member_id' => $member_id]);
    $transactions = $stmt->fetchAll();
    
    // Get member details for header
    $member_query = "SELECT * FROM members WHERE member_id = :member_id";
    $member_stmt = $db->prepare($member_query);
    $member_stmt->execute([':member_id' => $member_id]);
    $member = $member_stmt->fetch();
    
    // Get yearly statistics
    $current_year = date('Y');
    $yearly_query = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions 
                     WHERE member_id = :member_id AND billing_cycle_year = :year";
    $yearly_stmt = $db->prepare($yearly_query);
    $yearly_stmt->execute([':member_id' => $member_id, ':year' => $current_year]);
    $yearly_total = $yearly_stmt->fetch()['total'];
    
    $settings = getYearlyTarget($db, $current_year);
    $annual_target = $settings['annual_amount'];
    if ($annual_target <= 0) $annual_target = 1;
    
    // Create simple HTML for PDF
    $html = ';
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Transaction History - ' . $member['member_id'] . '</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #1976d2; padding-bottom: 20px; }
            .header h2 { color: #1976d2; margin: 0; }
            .header h4 { color: #666; margin: 5px 0; }
            .summary { margin: 20px 0; padding: 15px; background: #f5f5f5; border-radius: 5px; }
            .summary table { width: 100%; }
            .summary td { padding: 5px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th { background: #1976d2; color: white; padding: 10px; text-align: left; }
            td { padding: 10px; border-bottom: 1px solid #ddd; }
            tr:nth-child(even) { background: #f9f9f9; }
            .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #999; }
        </style>
    </head>
    <body>
        <div class="header">
            <h2>GYF Welfare Management System</h2>
            <h4>Transaction History Report</h4>
            <p>Member: ' . htmlspecialchars($member['full_name']) . ' (' . htmlspecialchars($member['member_id']) . ')</p>
            <p>Generated: ' . date('F j, Y g:i A') . '</p>
        </div>
        
        <div class="summary">
            <table>
                <tr>
                    <td><strong>Yearly Contribution:</strong></td>
                    <td>GH₵ ' . number_format($yearly_total, 2) . '</td>
                    <td><strong>Annual Target:</strong></td>
                    <td>GH₵ ' . number_format($annual_target, 2) . '</td>
                </tr>
                <tr>
                    <td><strong>Progress:</strong></td>
                    <td>' . number_format($annual_target > 0 ? ($yearly_total / $annual_target) * 100 : 0, 1) . '%</td>
                    <td><strong>Remaining:</strong></td>
                    <td>GH₵ ' . number_format(max($annual_target - $yearly_total, 0), 2) . '</td>
                </tr>
            </table>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Receipt No</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Billing Period</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($transactions as $transaction) {
        $billing_period = $transaction['billing_cycle_month'] ? formatBillingPeriod($transaction['billing_cycle_month'], $transaction['billing_cycle_year']) : $transaction['billing_cycle_year'];
            
        $html .= '
                <tr>
                    <td>' . htmlspecialchars($transaction['receipt_no']) . '</td>
                    <td>GH₵ ' . number_format($transaction['amount'], 2) . '</td>
                    <td>' . htmlspecialchars($transaction['payment_method']) . '</td>
                    <td>' . $billing_period . '</td>
                    <td>' . date('M d, Y', strtotime($transaction['transaction_date'])) . '</td>
                </tr>';
    }
    
    $html .= ';
            </tbody>
        </table>
        
        <div class="footer">
            <p>This is a computer-generated report from GYF Welfare Management System</p>
            <p>© ' . date('Y') . ' GYF Ministry & Prayer Camp. All rights reserved.</p>
        </div>
    </body>
    </html>';
    
    echo $html;
    exit();
}

// Get filter parameters
$filter_month = isset($_GET['month']) ? intval($_GET['month']) : 0;
$filter_year = isset($_GET['year']) ? intval($_GET['year']) : 0;
$filter_method = isset($_GET['method']) ? cleanInput($_GET['method']) : '';

// Build query based on filters
$where_clause = "WHERE t.member_id = :member_id";
$params = [':member_id' => $member_id];

if ($filter_year > 0) {
    $where_clause .= " AND t.billing_cycle_year = :year";
    $params[':year'] = $filter_year;
}

if ($filter_month > 0) {
    $where_clause .= " AND t.billing_cycle_month = :month";
    $params[':month'] = $filter_month;
}

if (!empty($filter_method)) {
    $where_clause .= " AND t.payment_method = :method";
    $params[':method'] = $filter_method;
}

// Get all transactions with filters
$transactions_query = "SELECT t.*, m.full_name 
                      FROM transactions t 
                      JOIN members m ON t.member_id = m.member_id 
                      {$where_clause} 
                      ORDER BY t.transaction_date DESC";
$transactions_stmt = $db->prepare($transactions_query);
$transactions_stmt->execute($params);
$transactions = $transactions_stmt->fetchAll();

// Get statistics
$total_query = "SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as count 
                FROM transactions t {$where_clause}";
$total_stmt = $db->prepare($total_query);
$total_stmt->execute($params);
$stats = $total_stmt->fetch();

// Get years for filter
$years_query = "SELECT DISTINCT billing_cycle_year FROM transactions 
                WHERE member_id = :member_id ORDER BY billing_cycle_year DESC";
$years_stmt = $db->prepare($years_query);
$years_stmt->execute([':member_id' => $member_id]);
$available_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get current year statistics
$current_year = date('Y');
$yearly_query = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions 
                 WHERE member_id = :member_id AND billing_cycle_year = :year";
$yearly_stmt = $db->prepare($yearly_query);
$yearly_stmt->execute([':member_id' => $member_id, ':year' => $current_year]);
$yearly_total = $yearly_stmt->fetch()['total'];

$settings = getYearlyTarget($db, $current_year);
$annual_target = $settings['annual_amount'];
$monthly_target = $settings['monthly_amount'];

// Guard against division by zero
if ($annual_target <= 0) $annual_target = 1;
if ($monthly_target <= 0) $monthly_target = 1;
?>

<div class="row">
    <div class="col-12">
        <h2 class="mb-4">My Transactions</h2>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-sm-6 col-md-4 mb-3">
        <div class="stat-card blue">
            <h6>Filtered Total</h6>
            <h4>GH₵ <?php echo number_format($stats['total'], 2); ?></h4>
            <small><?php echo $stats['count']; ?> transactions</small>
        </div>
    </div>
    <div class="col-sm-6 col-md-4 mb-3">
        <div class="stat-card">
            <h6>Yearly Progress</h6>
            <h4>GH₵ <?php echo number_format($yearly_total, 2); ?></h4>
            <small>of GH₵ <?php echo number_format($annual_target, 2); ?> (<?php echo number_format(($yearly_total / $annual_target) * 100, 1); ?>%)</small>
        </div>
    </div>
    <div class="col-sm-6 col-md-4 mb-3">
        <div class="stat-card blue">
            <h6>Monthly Target</h6>
            <h4>GH₵ <?php echo number_format($monthly_target, 2); ?></h4>
            <small>Per member contribution</small>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <form method="GET" action="" class="row g-3 align-items-end">
                    <div class="col-sm-6 col-md-3">
                        <label for="year" class="form-label">Year</label>
                        <select class="form-control" id="year" name="year">
                            <option value="">All Years</option>
                            <?php foreach ($available_years as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo $filter_year == $year ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if (empty($available_years)): ?>
                                <option value="<?php echo date('Y'); ?>"><?php echo date('Y'); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label for="month" class="form-label">Month</label>
                        <select class="form-control" id="month" name="month">
                            <option value="">All Months</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $filter_month == $m ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label for="method" class="form-label">Payment Method</label>
                        <select class="form-control" id="method" name="method">
                            <option value="">All Methods</option>
                            <option value="Cash" <?php echo $filter_method == 'Cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="Mobile Money" <?php echo $filter_method == 'Mobile Money' ? 'selected' : ''; ?>>Mobile Money</option>
                            <option value="Bank Transfer" <?php echo $filter_method == 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="Card" <?php echo $filter_method == 'Card' ? 'selected' : ''; ?>>Card</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <button type="submit" class="btn btn-primary w-100">🔍 Apply Filters</button>
                        <?php if ($filter_year || $filter_month || $filter_method): ?>
                            <a href="transactions.php" class="btn btn-outline-secondary w-100 mt-1">Clear Filters</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Export Buttons -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-grid d-sm-flex gap-2">
            <a href="?export=csv<?php echo $filter_year ? '&year='.$filter_year : ''; ?><?php echo $filter_month ? '&month='.$filter_month : ''; ?><?php echo $filter_method ? '&method='.urlencode($filter_method) : ''; ?>" 
               class="btn btn-success">
                📊 Export to CSV
            </a>
            <a href="?export=pdf<?php echo $filter_year ? '&year='.$filter_year : ''; ?><?php echo $filter_month ? '&month='.$filter_month : ''; ?><?php echo $filter_method ? '&method='.urlencode($filter_method) : ''; ?>" 
               class="btn btn-danger">
                📄 Export to PDF
            </a>
            <button class="btn btn-info" id="printBtn">
                🖨️ Print
            </button>
        </div>
    </div>
</div>

<!-- Transactions Table -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                <h5 class="mb-0">Transaction History</h5>
                <span class="badge bg-primary"><?php echo $stats['count']; ?> Records</span>
            </div>
            <div class="card-body">
                <?php if (empty($transactions)): ?>
                    <div class="text-center py-5">
                        <h4 class="text-muted">No Transactions Found</h4>
                        <p class="text-muted">
                            <?php if ($filter_year || $filter_month || $filter_method): ?>
                                No transactions match your filter criteria. Try adjusting your filters.
                            <?php else: ?>
                                You haven't made any welfare contributions yet.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="table-scroll-wrapper">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Receipt No</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Billing Period</th>
                                    <th>Transaction Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $index => $transaction): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($transaction['receipt_no']); ?></strong>
                                        </td>
                                        <td>
                                            <span class="fw-bold">GH₵ <?php echo number_format($transaction['amount'], 2); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $transaction['payment_method'] == 'Cash' ? 'success' : 
                                                    ($transaction['payment_method'] == 'Mobile Money' ? 'warning' : 
                                                    ($transaction['payment_method'] == 'Bank Transfer' ? 'info' : 'primary')); 
                                             ?>">
                                                 <?php echo htmlspecialchars($transaction['payment_method']); ?>
                                            </span>
                                        </td>
                                        <td>
                                             <?php 
                                             if ($transaction['billing_cycle_month']) {
                                                 echo formatBillingPeriod($transaction['billing_cycle_month'], $transaction['billing_cycle_year']);
                                             } else {
                                                 echo 'Year ' . htmlspecialchars($transaction['billing_cycle_year']);
                                             }
                                             ?>
                                        </td>
                                        <td><?php echo !empty($transaction['transaction_date']) ? htmlspecialchars(date('M d, Y g:i A', strtotime($transaction['transaction_date']))) : 'N/A'; ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-info" 
                                                        data-receipt-no="<?php echo htmlspecialchars($transaction['receipt_no']); ?>"
                                                        title="View Receipt">
                                                    View
                                                </button>
                                                <button class="btn btn-success"
                                                        data-receipt-no="<?php echo htmlspecialchars($transaction['receipt_no']); ?>"
                                                        data-action="print"
                                                        title="Print Receipt">
                                                    Print
                                                </button>
                                                <button class="btn btn-primary"
                                                        data-receipt-no="<?php echo htmlspecialchars($transaction['receipt_no']); ?>"
                                                        data-action="download"
                                                        title="Download Receipt">
                                                    Download
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-active fw-bold">
                                    <td colspan="2">Total</td>
                                    <td>GH₵ <?php echo number_format($stats['total'], 2); ?></td>
                                    <td colspan="4"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Yearly Progress Visualization -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Yearly Contribution Progress - <?php echo $current_year; ?></h5>
            </div>
            <div class="card-body">
                <?php
                // Get monthly breakdown
                $monthly_breakdown_query = "SELECT billing_cycle_month, COALESCE(SUM(amount), 0) as total 
                                           FROM transactions 
                                           WHERE member_id = :member_id AND billing_cycle_year = :year 
                                           GROUP BY billing_cycle_month 
                                           ORDER BY billing_cycle_month";
                $monthly_stmt = $db->prepare($monthly_breakdown_query);
                $monthly_stmt->execute([':member_id' => $member_id, ':year' => $current_year]);
                $monthly_data = $monthly_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                ?>
                
                <div class="row">
                    <?php
                    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                              'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    foreach ($months as $index => $month_name):
                        $month_num = $index + 1;
                        $amount = isset($monthly_data[$month_num]) ? $monthly_data[$month_num] : 0;
                        $percentage = $monthly_target > 0 ? ($amount / $monthly_target) * 100 : 0;
                        $is_current = $month_num == date('m');
                        $is_future = $month_num > date('m');
                    ?>
                        <div class="col-4 col-sm-3 col-md-2 col-lg-1 mb-3">
                            <div class="text-center">
                                <div class="position-relative" style="height: 100px;">
                                    <div class="progress" style="height: 100px; width: 20px; margin: 0 auto; transform: rotate(180deg);">
                                        <div class="progress-bar <?php 
                                            echo $percentage >= 100 ? 'bg-success' : ($percentage > 0 ? 'bg-warning' : ($is_future ? '' : 'bg-danger')); 
                                        ?>" 
                                             role="progressbar" 
                                             style="height: <?php echo min($percentage, 100); ?>%;"
                                             aria-valuenow="<?php echo $percentage; ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="100">
                                        </div>
                                    </div>
                                </div>
                                <small class="d-block mt-2 <?php echo $is_current ? 'fw-bold' : ''; ?>">
                                    <?php echo $month_name; ?>
                                </small>
                                <small class="text-muted">
                                    <?php echo $percentage > 0 ? number_format($percentage, 0) . '%' : '-'; ?>
                                </small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="alert alert-info mt-3">
                    <strong>💡 Monthly Target:</strong> GH₵ <?php echo number_format($monthly_target, 2); ?> | 
                    <strong>Annual Target:</strong> GH₵ <?php echo number_format($annual_target, 2); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Payment Methods Distribution -->
<div class="row mt-4">
    <div class="col-md-6 mb-3">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Payment Methods Used</h5>
            </div>
            <div class="card-body">
                <?php
                $methods_query = "SELECT payment_method, COUNT(*) as count, COALESCE(SUM(amount), 0) as total 
                                 FROM transactions 
                                 WHERE member_id = :member_id 
                                 GROUP BY payment_method";
                $methods_stmt = $db->prepare($methods_query);
                $methods_stmt->execute([':member_id' => $member_id]);
                $payment_methods = $methods_stmt->fetchAll();
                
                if (!empty($payment_methods)):
                ?>
                    <div class="list-group">
                        <?php foreach ($payment_methods as $method): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0"><?php echo htmlspecialchars($method['payment_method']); ?></h6>
                                    <small><?php echo $method['count']; ?> transaction(s)</small>
                                </div>
                                <span class="badge bg-primary rounded-pill">
                                    GH₵ <?php echo number_format($method['total'], 2); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center">No payment data available</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 mb-3">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Quick Summary</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <tr>
                            <td>First Payment:</td>
                            <td>
                                <?php
                                $first_query = "SELECT MIN(transaction_date) as first_date FROM transactions WHERE member_id = :member_id";
                                $first_stmt = $db->prepare($first_query);
                                $first_stmt->execute([':member_id' => $member_id]);
                                $first_date = $first_stmt->fetch()['first_date'];
                                echo $first_date ? date('F j, Y', strtotime($first_date)) : 'N/A';
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Last Payment:</td>
                            <td>
                                <?php
                                $last_query = "SELECT MAX(transaction_date) as last_date FROM transactions WHERE member_id = :member_id";
                                $last_stmt = $db->prepare($last_query);
                                $last_stmt->execute([':member_id' => $member_id]);
                                $last_date = $last_stmt->fetch()['last_date'];
                                echo $last_date ? date('F j, Y', strtotime($last_date)) : 'N/A';
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Average Payment:</td>
                            <td>
                                <?php
                                $avg_query = "SELECT AVG(amount) as avg_amount FROM transactions WHERE member_id = :member_id";
                                $avg_stmt = $db->prepare($avg_query);
                                $avg_stmt->execute([':member_id' => $member_id]);
                                $avg_amount = $avg_stmt->fetch()['avg_amount'];
                                echo $avg_amount ? 'GH₵ ' . number_format($avg_amount, 2) : 'N/A';
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Largest Payment:</td>
                            <td>
                                <?php
                                $max_query = "SELECT MAX(amount) as max_amount FROM transactions WHERE member_id = :member_id";
                                $max_stmt = $db->prepare($max_query);
                                $max_stmt->execute([':member_id' => $member_id]);
                                $max_amount = $max_stmt->fetch()['max_amount'];
                                echo $max_amount ? 'GH₵ ' . number_format($max_amount, 2) : 'N/A';
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Total Transactions:</td>
                            <td><strong><?php echo $stats['count']; ?></strong></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Payment Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="receiptContent">
                <!-- Loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="printReceiptBtn">Print Receipt</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo CSP_NONCE; ?>">
let currentReceiptNo = null;

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-receipt-no]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const receiptNo = this.dataset.receiptNo;
            const action = this.dataset.action;
            if (action === 'print') {
                printReceipt(receiptNo);
            } else if (action === 'download') {
                downloadReceipt(receiptNo);
            } else {
                viewReceipt(receiptNo);
            }
        });
    });
    
    const printBtn = document.getElementById('printReceiptBtn');
    if (printBtn) {
        printBtn.addEventListener('click', printReceipt);
    }
    
    const printPageBtn = document.getElementById('printBtn');
    if (printBtn) {
        printPageBtn.addEventListener('click', function () { window.print(); });
    }
});

function viewReceipt(receiptNo) {
    currentReceiptNo = receiptNo;
    document.getElementById('receiptContent').innerHTML = '<div class="text-center"><div class="spinner-border text-primary"></div><p>Loading receipt...</p></div>';

    const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
    receiptModal.show();

    fetch(`<?php echo APP_URL; ?>/api/transactions.php?action=member_receipt&receipt_no=${encodeURIComponent(receiptNo)}`)
        .then(response => response.text())
        .then(html => {
            // The API returns a full HTML page; extract just the receipt content.
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const receiptBody = doc.querySelector('.receipt');
            document.getElementById('receiptContent').innerHTML = receiptBody ? receiptBody.outerHTML : html;
        })
        .catch(() => {
            document.getElementById('receiptContent').innerHTML = '<div class="alert alert-danger">Error loading receipt</div>';
        });
}

function printReceipt() {
    if (!currentReceiptNo) return;
    const printWindow = window.open(
        `<?php echo APP_URL; ?>/api/transactions.php?action=member_receipt&receipt_no=${encodeURIComponent(currentReceiptNo)}`,
        'PrintReceipt',
        'width=800,height=600'
    );
    
    if (printWindow) {
        printWindow.onload = function() {
            printWindow.print();
        };
    }
}

function downloadReceipt(receiptNo) {
    window.location.href = `<?php echo APP_URL; ?>/api/transactions.php?action=member_receipt&receipt_no=${encodeURIComponent(receiptNo)}&download=1`;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

