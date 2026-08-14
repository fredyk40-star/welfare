<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

// Check if user is treasurer
if (!isTreasurer()) {
    redirectTo('/member/login.php');
}

require_once __DIR__ . '/../includes/header.php';

$database = new Database();
$db = $database->getConnection();

$member_id = cleanInput($_GET['member_id'] ?? '');
$message = '';
$error = '';

if (empty($member_id)) {
    redirectTo('/treasurer/members.php');
}

// Fetch member
$member_query = "SELECT * FROM members WHERE member_id = :mid";
$member_stmt = $db->prepare($member_query);
$member_stmt->execute([':mid' => $member_id]);
$member = $member_stmt->fetch();

if (!$member) {
    redirectTo('/treasurer/members.php');
}

// Handle payment recording
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } elseif (!checkRateLimit($_SESSION['user_id'] . '_payment', 10, 900, '%payment%')) {
        $error = 'Too many payment attempts. Please try again later.';
    } else {
        $amount_raw = $_POST['amount'] ?? '';
        $payment_method = cleanInput($_POST['payment_method']);
        $billing_month = (int) ($_POST['billing_month'] ?? 0);
        $billing_year = (int) ($_POST['billing_year'] ?? 0);
        $transaction_date = cleanInput($_POST['transaction_date'] ?? date('Y-m-d'));
        $transaction_time = cleanInput($_POST['transaction_time'] ?? date('H:i'));
        $amount = (float) $amount_raw;

        if (empty($amount_raw) || empty($payment_method) || !$billing_month || !$billing_year) {
            $error = 'Please fill in all fields.';
        } elseif (!is_numeric($amount_raw) || $amount <= 0) {
            $error = 'Invalid amount.';
        } else {
            $transaction_datetime = $transaction_date . ' ' . $transaction_time . ':00';
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $transaction_datetime);
            if (!$dt) {
                $error = 'Invalid date or time format.';
            } else {
                $settings = getYearlyTarget($db, date('Y'));
                $annual_limit = $settings['annual_amount'];
                $yearly_total = 0;
                if ($annual_limit > 0) {
                    $yearly_query = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions 
                                   WHERE member_id = :member_id AND billing_cycle_year = :year AND status != 'void'";
                    $yearly_stmt = $db->prepare($yearly_query);
                    $yearly_stmt->execute([':member_id' => $member_id, ':year' => $billing_year]);
                    $yearly_total = $yearly_stmt->fetch()['total'];
                }
                if ($annual_limit > 0 && ($yearly_total + $amount) > $annual_limit) {
                    $error = "Annual limit of GH₵ {$annual_limit} would be exceeded. Current total: GH₵ {$yearly_total}";
                } else {
                    $receipt_no = generateReceiptNumber();
                    $query = "INSERT INTO transactions (receipt_no, member_id, treasurer_id, amount, 
                             payment_method, billing_cycle_month, billing_cycle_year, notes, transaction_date) 
                             VALUES (:receipt_no, :member_id, :treasurer_id, :amount, 
                             :payment_method, :billing_month, :billing_year, :notes, :transaction_date)";
                    $stmt = $db->prepare($query);
                    try {
                        $stmt->execute([
                            ':receipt_no' => $receipt_no,
                            ':member_id' => $member_id,
                            ':treasurer_id' => $_SESSION['user_id'],
                            ':amount' => $amount,
                            ':payment_method' => $payment_method,
                            ':billing_month' => $billing_month,
                            ':billing_year' => $billing_year,
                            ':notes' => cleanInput($_POST['notes'] ?? ''),
                            ':transaction_date' => $transaction_datetime
                        ]);
                        logAudit($_SESSION['user_id'], "Recorded payment of GH₵ {$amount} for member {$member_id}");
                        $message = "Payment recorded successfully! Receipt No: {$receipt_no}";
                        $treasurer_email = '';
                        $treasurer_stmt = $db->prepare("SELECT email FROM members WHERE member_id = :mid");
                        $treasurer_stmt->execute([':mid' => $_SESSION['user_id']]);
                        $treasurer_row = $treasurer_stmt->fetch();
                        if ($treasurer_row) {
                            $treasurer_email = $treasurer_row['email'];
                        }
                        $receipt_data = [
                            'receipt_no' => $receipt_no,
                            'member_name' => $member['full_name'],
                            'member_id' => $member_id,
                            'amount' => $amount,
                            'payment_method' => $payment_method,
                            'billing_period' => date('F Y', mktime(0, 0, 0, $billing_month, 1, $billing_year)),
                            'date' => $transaction_datetime
                        ];
                        sendReceiptEmail($member['email'], $receipt_data, $member['passport_photo'], $treasurer_email);
                    } catch (PDOException $e) {
                        $error_msg = $e->getMessage();
                        if (strpos($error_msg, 'foreign key') !== false || strpos($error_msg, 'Foreign key') !== false) {
                            $error = 'Invalid member reference. Please try again.';
                        } else {
                            $error = 'Transaction failed: ' . $error_msg;
                            error_log("Transaction Error: " . $error_msg);
                        }
                    }
                }
            }
        }
    }
}

// Get settings for current calendar year
$current_year = date('Y');
$settings = getYearlyTarget($db, $current_year);
$annual_target = $settings['annual_amount'];
$monthly_target = $settings['monthly_amount'];
if ($annual_target <= 0) $annual_target = 1;
if ($monthly_target <= 0) $monthly_target = 1;

// Yearly total for current year
$current_year = date('Y');
$year_stats = getMemberYearStats($db, $member_id, $current_year);
$yearly_total = $year_stats['paid'];
$annual_target = $year_stats['target'];
$monthly_target = $year_stats['monthly_target'];
$year_debt = $year_stats['debt'];

// Monthly breakdown for current year
$monthly_breakdown_query = "SELECT billing_cycle_month, COALESCE(SUM(amount), 0) as total 
                           FROM transactions 
                           WHERE member_id = :member_id AND billing_cycle_year = :year 
                           GROUP BY billing_cycle_month 
                           ORDER BY billing_cycle_month";
$monthly_stmt = $db->prepare($monthly_breakdown_query);
$monthly_stmt->execute([':member_id' => $member_id, ':year' => $current_year]);
$monthly_data = $monthly_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Quick stats
$first_query = "SELECT MIN(transaction_date) as first_date FROM transactions WHERE member_id = :member_id AND status != 'void'";
$first_stmt = $db->prepare($first_query);
$first_stmt->execute([':member_id' => $member_id]);
$first_date = $first_stmt->fetch()['first_date'];

$last_query = "SELECT MAX(transaction_date) as last_date FROM transactions WHERE member_id = :member_id AND status != 'void'";
$last_stmt = $db->prepare($last_query);
$last_stmt->execute([':member_id' => $member_id]);
$last_date = $last_stmt->fetch()['last_date'];

$avg_query = "SELECT AVG(amount) as avg_amount FROM transactions WHERE member_id = :member_id AND status != 'void'";
$avg_stmt = $db->prepare($avg_query);
$avg_stmt->execute([':member_id' => $member_id]);
$avg_amount = $avg_stmt->fetch()['avg_amount'];

$max_query = "SELECT MAX(amount) as max_amount FROM transactions WHERE member_id = :member_id AND status != 'void'";
$max_stmt = $db->prepare($max_query);
$max_stmt->execute([':member_id' => $member_id]);
$max_amount = $max_stmt->fetch()['max_amount'];

$total_count_query = "SELECT COUNT(*) as count FROM transactions WHERE member_id = :member_id AND status != 'void'";
$total_count_stmt = $db->prepare($total_count_query);
$total_count_stmt->execute([':member_id' => $member_id]);
$total_count = $total_count_stmt->fetch()['count'];

// Payment methods
$methods_query = "SELECT payment_method, COUNT(*) as count, COALESCE(SUM(amount), 0) as total 
                 FROM transactions 
                 WHERE member_id = :member_id AND status != 'void'
                 GROUP BY payment_method";
$methods_stmt = $db->prepare($methods_query);
$methods_stmt->execute([':member_id' => $member_id]);
$payment_methods = $methods_stmt->fetchAll();

// Transaction history with filters
$filter_month = isset($_GET['month']) ? (int) $_GET['month'] : 0;
$filter_year = isset($_GET['year']) ? (int) $_GET['year'] : 0;
$filter_method = isset($_GET['method']) ? cleanInput($_GET['method']) : '';

$where_clause = "WHERE t.member_id = :member_id AND t.status != 'void'";
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

$transactions_query = "SELECT t.*, m.full_name 
                       FROM transactions t 
                       JOIN members m ON t.member_id = m.member_id 
                       {$where_clause} 
                       ORDER BY t.transaction_date DESC";
$transactions_stmt = $db->prepare($transactions_query);
$transactions_stmt->execute($params);
$transactions = $transactions_stmt->fetchAll();

$total_query = "SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as count 
                FROM transactions t {$where_clause}";
$total_stmt = $db->prepare($total_query);
$total_stmt->execute($params);
$stats = $total_stmt->fetch();

$years_query = "SELECT DISTINCT billing_cycle_year FROM transactions 
                WHERE member_id = :member_id AND status != 'void' ORDER BY billing_cycle_year DESC";
$years_stmt = $db->prepare($years_query);
$years_stmt->execute([':member_id' => $member_id]);
$available_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

$yearly_history = getMemberYearlyHistory($db, $member_id);

$treasurer_email = '';
$treasurer_stmt = $db->prepare("SELECT email FROM members WHERE member_id = :mid");
$treasurer_stmt->execute([':mid' => $_SESSION['user_id']]);
$treasurer_row = $treasurer_stmt->fetch();
if ($treasurer_row) {
    $treasurer_email = $treasurer_row['email'];
}
?>

<div class="row">
    <div class="col-12">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/treasurer/dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="/treasurer/members.php">Members</a></li>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($member['full_name']); ?></li>
            </ol>
        </nav>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Member Info Header -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-2 text-center">
                        <?php if ($member['passport_photo']): ?>
                            <img src="<?php echo displayPhotoUrl($member['passport_photo']); ?>" 
                                 class="img-fluid rounded" style="max-width: 150px;" alt="Photo">
                        <?php else: ?>
                            <div class="bg-secondary text-white rounded p-4" style="width: 150px; height: 150px; display: flex; align-items: center; justify-content: center; font-size: 3rem; margin: 0 auto;">
                                <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-7">
                        <h2><?php echo htmlspecialchars($member['full_name']); ?></h2>
                        <p class="text-muted mb-1"><?php echo htmlspecialchars($member['member_id']); ?></p>
                        <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars($member['email']); ?></p>
                        <p class="mb-1"><strong>Phone:</strong> <?php echo htmlspecialchars($member['phone']); ?></p>
                        <p class="mb-0"><strong>Registered:</strong> <?php echo date('F j, Y', strtotime($member['created_at'])); ?></p>
                    </div>
<div class="col-md-3 text-md-end mt-3 mt-md-0">
                        <a href="/treasurer/members.php" class="btn btn-secondary mb-2">&larr; Back to Members</a>
                        <br>
                        <button class="btn btn-success mb-2" data-bs-toggle="modal" data-bs-target="#statementModal">���� Statement</button>
                        <br>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">���� Record Payment</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-sm-6 col-md-4 mb-3">
        <div class="stat-card blue">
            <h6>Yearly Total</h6>
            <h4>GH₵ <?php echo number_format($yearly_total, 2); ?></h4>
            <small>of GH₵ <?php echo number_format($annual_target, 2); ?> target</small>
            <?php if ($year_debt > 0.01): ?>
                <br><small class="text-danger fw-bold">Year debt: GH₵ <?php echo number_format($year_debt, 2); ?></small>
            <?php else: ?>
                <br><small class="text-success fw-bold">✓ Cleared</small>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-sm-6 col-md-4 mb-3">
        <div class="stat-card">
            <h6>Yearly Progress</h6>
            <h4><?php echo number_format(($yearly_total / $annual_target) * 100, 1); ?>%</h4>
            <small>of annual target</small>
        </div>
    </div>
    <div class="col-sm-6 col-md-4 mb-3">
        <div class="stat-card blue">
            <h6>Monthly Target</h6>
            <h4>GH₵ <?php echo number_format($monthly_target, 2); ?></h4>
            <small>Per member per month</small>
        </div>
    </div>
</div>

<!-- Monthly Breakdown & Payment Methods -->
<div class="row mt-4">
    <div class="col-md-8 mb-3">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Monthly Contributions - <?php echo $current_year; ?></h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php
                    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    foreach ($months as $index => $month_name):
                        $month_num = $index + 1;
                        $amount = isset($monthly_data[$month_num]) ? $monthly_data[$month_num] : 0;
                        $percentage = $monthly_target > 0 ? ($amount / $monthly_target) * 100 : 0;
                        $is_current = $month_num == date('m');
                    ?>
                        <div class="col-4 col-sm-3 col-md-2 col-lg-1 mb-3">
                            <div class="text-center">
                                <div class="position-relative" style="height: 100px;">
                                    <div class="progress" style="height: 100px; width: 20px; margin: 0 auto; transform: rotate(180deg);">
                                        <div class="progress-bar <?php echo $percentage >= 100 ? 'bg-success' : ($percentage > 0 ? 'bg-warning' : 'bg-danger'); ?>" 
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
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Payment Methods</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($payment_methods)): ?>
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
</div>

<!-- Quick Stats -->
<div class="row mt-4">
    <div class="col-md-6 mb-3">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Quick Summary</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <tr><td>First Payment:</td><td><?php echo $first_date ? date('F j, Y', strtotime($first_date)) : 'N/A'; ?></td></tr>
                        <tr><td>Last Payment:</td><td><?php echo $last_date ? date('F j, Y', strtotime($last_date)) : 'N/A'; ?></td></tr>
                        <tr><td>Average Payment:</td><td><?php echo $avg_amount ? 'GH₵ ' . number_format($avg_amount, 2) : 'N/A'; ?></td></tr>
                        <tr><td>Largest Payment:</td><td><?php echo $max_amount ? 'GH₵ ' . number_format($max_amount, 2) : 'N/A'; ?></td></tr>
                        <tr><td>Total Transactions:</td><td><strong><?php echo $total_count; ?></strong></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="/treasurer/statement.php?member_id=<?php echo urlencode($member_id); ?>&export=pdf" target="_blank" class="btn btn-danger">📄 Export Statement PDF</a>
                    <a href="/treasurer/statement.php?member_id=<?php echo urlencode($member_id); ?>&export=csv" class="btn btn-success">📊 Export Statement CSV</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Year-by-Year History -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">📊 Year-by-Year Progress</h5>
            </div>
            <div class="card-body">
                <?php if (empty($yearly_history)): ?>
                    <p class="text-muted text-center">No transaction history yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Year</th>
                                    <th>Annual Target</th>
                                    <th>Total Paid</th>
                                    <th>Progress</th>
                                    <th>Year Debt</th>
                                    <th>Transactions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($yearly_history as $hist): ?>
                                    <tr>
                                        <td><strong><?php echo date('Y', mktime(0,0,0,1,1,(int)($hist['year'] ?? date('Y')))); ?></strong></td>
                                        <td>GH₵ <?php echo number_format($hist['target'], 2); ?></td>
                                        <td class="text-success fw-bold">GH₵ <?php echo number_format($hist['paid'], 2); ?></td>
                                        <td>
                                            <div class="progress" style="height: 10px; min-width: 120px;">
                                                <div class="progress-bar bg-<?php echo $hist['pct'] >= 100 ? 'success' : ($hist['pct'] >= 50 ? 'warning' : 'danger'); ?>" style="width: <?php echo min($hist['pct'], 100); ?>%;"></div>
                                            </div>
                                            <small><?php echo number_format($hist['pct'], 1); ?>%</small>
                                        </td>
                                        <td>
                                            <?php if ($hist['debt'] > 0.01): ?>
                                                <span class="text-danger fw-bold">GH₵ <?php echo number_format($hist['debt'], 2); ?></span>
                                            <?php else: ?>
                                                <span class="text-success">✓ Cleared</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo (int)($hist['tx_count'] ?? 0); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Record Payment for <?php echo htmlspecialchars($member['full_name']); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="record_payment">
                    <input type="hidden" name="member_id" value="<?php echo htmlspecialchars($member_id); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Amount (GH₵)</label>
                        <input type="number" step="0.01" class="form-control" name="amount" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select class="form-control" name="payment_method" required>
                            <option value="Cash">Cash</option>
                            <option value="Mobile Money">Mobile Money</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Card">Card</option>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Billing Month</label>
                            <select class="form-control" name="billing_month" required>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m == date('m') ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Billing Year</label>
                            <select class="form-control" name="billing_year" required>
                                <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-control" name="transaction_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Time</label>
                            <input type="time" class="form-control" name="transaction_time" value="<?php echo date('H:i'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes (optional)</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Record Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Transaction History -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                <h5 class="mb-0">Transaction History</h5>
                <span class="badge bg-primary"><?php echo $stats['count']; ?> Records</span>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" action="" class="row g-3 mb-3">
                    <input type="hidden" name="member_id" value="<?php echo htmlspecialchars($member_id); ?>">
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label">Year</label>
                        <select class="form-control" name="year">
                            <option value="">All Years</option>
                            <?php foreach ($available_years as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo $filter_year == $year ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label">Month</label>
                        <select class="form-control" name="month">
                            <option value="">All Months</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $filter_month == $m ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <label class="form-label">Method</label>
                        <select class="form-control" name="method">
                            <option value="">All Methods</option>
                            <option value="Cash" <?php echo $filter_method == 'Cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="Mobile Money" <?php echo $filter_method == 'Mobile Money' ? 'selected' : ''; ?>>Mobile Money</option>
                            <option value="Bank Transfer" <?php echo $filter_method == 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="Card" <?php echo $filter_method == 'Card' ? 'selected' : ''; ?>>Card</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-md-3">
                        <button type="submit" class="btn btn-primary w-100">🔍 Filter</button>
                        <?php if ($filter_year || $filter_month || $filter_method): ?>
                            <a href="?member_id=<?php echo urlencode($member_id); ?>" class="btn btn-outline-secondary w-100 mt-1">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if (empty($transactions)): ?>
                    <div class="text-center py-5">
                        <h4 class="text-muted">No Transactions Found</h4>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Receipt No</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Period</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $index => $transaction): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><strong><?php echo htmlspecialchars($transaction['receipt_no']); ?></strong></td>
                                        <td><span class="fw-bold">GH₵ <?php echo number_format($transaction['amount'], 2); ?></span></td>
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
                                            <?php echo $transaction['billing_cycle_month'] ? formatBillingPeriod($transaction['billing_cycle_month'], $transaction['billing_cycle_year']) : 'Year ' . htmlspecialchars($transaction['billing_cycle_year']); ?>
                                        </td>
                                        <td><?php echo !empty($transaction['transaction_date']) ? htmlspecialchars(date('M d, Y g:i A', strtotime($transaction['transaction_date']))) : 'N/A'; ?></td>
<td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-info" 
                                                        data-receipt-no="<?php echo htmlspecialchars($transaction['receipt_no']); ?>"
                                                        data-action="view-receipt">View</button>
                                                <button class="btn btn-success"
                                                        data-receipt-no="<?php echo htmlspecialchars($transaction['receipt_no']); ?>"
                                                        onclick="printReceipt(this.dataset.receiptNo)">Print</button>
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

<!-- Statement Modal -->
<div class="modal fade" id="statementModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header" style="background:#0f172a; color:#fff;">
                <h5 class="modal-title">📄 Member Statement</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="statementContent" style="background:#f4f6f9;">
                <!-- Loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printStatement()">🖨️ Print / Save as PDF</button>
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
            if (action === 'view-receipt') {
                viewReceipt(receiptNo);
            }
        });
    });

    const printReceiptBtn = document.getElementById('printReceiptBtn');
    if (printReceiptBtn) {
        printReceiptBtn.addEventListener('click', function() {
            if (currentReceiptNo) printReceipt(currentReceiptNo);
        });
    }
    
    // Load statement when modal is shown
    const statementModalEl = document.getElementById('statementModal');
    if (statementModalEl) {
        statementModalEl.addEventListener('show.bs.modal', function() {
            loadStatement();
        });
    }
});

function viewReceipt(receiptNo) {
    currentReceiptNo = receiptNo;
    document.getElementById('receiptContent').innerHTML = '<div class="text-center"><div class="spinner-border text-primary"></div><p>Loading receipt...</p></div>';
    const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
    receiptModal.show();
    
    // Pass member_id for treasurer access
    fetch(`<?php echo APP_URL; ?>/api/transactions.php?action=member_receipt&receipt_no=${encodeURIComponent(receiptNo)}&member_id=${encodeURIComponent('<?php echo htmlspecialchars($member_id); ?>')}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('receiptContent').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('receiptContent').innerHTML = '<div class="alert alert-danger">Error loading receipt</div>';
        });
}

function printReceipt(receiptNo) {
    if (!receiptNo) return;
    const printWindow = window.open(
        `<?php echo APP_URL; ?>/api/transactions.php?action=member_receipt&receipt_no=${encodeURIComponent(receiptNo)}&member_id=${encodeURIComponent('<?php echo htmlspecialchars($member_id); ?>')}`,
        'PrintReceipt',
        'width=800,height=600'
    );
    if (printWindow) {
        printWindow.onload = function() {
            printWindow.print();
        };
    }
}

function loadStatement() {
    const contentEl = document.getElementById('statementContent');
    contentEl.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-success"></div></div>';

    fetch(`<?php echo APP_URL; ?>/treasurer/statement.php?embed=1&member_id=${encodeURIComponent('<?php echo htmlspecialchars($member_id); ?>')}`)
        .then(response => response.text())
        .then(html => {
            contentEl.innerHTML = html;
        })
        .catch(() => {
            contentEl.innerHTML = '<div class="alert alert-danger">Failed to load statement.</div>';
        });
}

function printStatement() {
    const printWindow = window.open(
        `<?php echo APP_URL; ?>/treasurer/statement.php?embed=1&member_id=${encodeURIComponent('<?php echo htmlspecialchars($member_id); ?>')}`,
        'PrintStatement',
        'width=1000,height=800'
    );
    if (printWindow) {
        printWindow.onload = function() {
            printWindow.print();
        };
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

