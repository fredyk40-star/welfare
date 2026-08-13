<?php
require_once __DIR__ . '/../includes/header.php';

// Check if user is treasurer
if (!isTreasurer()) {
    redirectTo('/member/login.php');
}

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';
$csrf_token = generateCsrfToken();

// Treasurer's own email (for CC on receipts)
$treasurer_email = '';
$treasurer_stmt = $db->prepare("SELECT email FROM members WHERE member_id = :mid");
$treasurer_stmt->execute([':mid' => $_SESSION['user_id']]);
$treasurer_row = $treasurer_stmt->fetch();
if ($treasurer_row) {
    $treasurer_email = $treasurer_row['email'];
}

// Handle new transaction submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $member_id = cleanInput($_POST['member_id']);
        $amount_raw = $_POST['amount'] ?? '';
        $payment_method = cleanInput($_POST['payment_method']);
        $billing_month = (int) ($_POST['billing_month'] ?? 0);
        $billing_year = (int) ($_POST['billing_year'] ?? 0);
        $transaction_date = cleanInput($_POST['transaction_date'] ?? date('Y-m-d'));
        $transaction_time = cleanInput($_POST['transaction_time'] ?? date('H:i'));
        $amount = (float) $amount_raw;
        
        // Validate inputs
        if (empty($member_id) || empty($amount_raw) || empty($payment_method) || !$billing_month || !$billing_year) {
            $error = 'Please fill in all fields.';
        } elseif (!is_numeric($amount_raw) || $amount <= 0) {
            $error = 'Invalid amount.';
        } elseif (empty($transaction_date) || empty($transaction_time)) {
            $error = 'Please provide transaction date and time.';
        } else {
            // Combine date and time into timestamp
            $transaction_datetime = $transaction_date . ' ' . $transaction_time . ':00';
            
            // Validate datetime
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $transaction_datetime);
            if (!$dt) {
                $error = 'Invalid date or time format.';
            } else {
                // Check if member exists
                $member_query = "SELECT * FROM members WHERE member_id = :member_id";
                $member_stmt = $db->prepare($member_query);
                $member_stmt->execute([':member_id' => $member_id]);
                $member = $member_stmt->fetch();
                
                if (!$member) {
                    $error = 'Member not found.';
                } else {
                    // Check annual limit (exclude voided transactions)
                    $settings = getWelfareSettings($db);
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
                        // Generate receipt number
                        $receipt_no = generateReceiptNumber();
                        
                        // Insert transaction with exact datetime
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
                            
                             logAudit($_SESSION['user_id'], "Recorded payment of GH₵ {$amount} for member {$member_id} on {$transaction_datetime}");
                            $success = "Payment recorded successfully! Receipt No: {$receipt_no}";
                            
                            // Store receipt for display
                            $_SESSION['last_receipt'] = [
                                'receipt_no' => $receipt_no,
                                'member_name' => $member['full_name'],
                                'member_id' => $member_id,
                                'amount' => $amount,
                                'payment_method' => $payment_method,
                                'billing_period' => date('F Y', mktime(0, 0, 0, $billing_month, 1, $billing_year)),
                                'date' => $transaction_datetime
                            ];
                        } catch (PDOException $e) {
                            if (strpos($e->getMessage(), 'unique_member_billing') !== false) {
                                $error = 'Payment already recorded for this billing cycle.';
                            } else {
                                $error = 'Transaction failed. Please try again.';
                                error_log("Transaction Error: " . $e->getMessage());
                            }
                        }
                    }
                    // Send receipt email outside try-catch so email failure doesn't roll back the payment
                    if ($success) {
                        try {
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
                        } catch (Exception $e) {
                            error_log("Receipt Email Error: " . $e->getMessage());
                        }
                    }
                }
            }
        }
    }
}
            

// Search members for transaction
$search_results = [];
if (isset($_GET['search'])) {
    $search_term = cleanInput($_GET['search']);
    $search_query = "SELECT member_id, full_name, passport_photo, phone 
                    FROM members 
                    WHERE (member_id LIKE :search1 OR full_name LIKE :search2 OR phone LIKE :search3)
                    AND member_id != :treasurer_id";
    $search_stmt = $db->prepare($search_query);
    $search_param = "%{$search_term}%";
    $search_stmt->execute([
        ':search1' => $search_param,
        ':search2' => $search_param,
        ':search3' => $search_param,
        ':treasurer_id' => TREASURER_MEMBER_ID
    ]);
    $search_results = $search_stmt->fetchAll();
}

// Get transaction history with filters
$filter = buildTransactionFilterClause();
$where_clause = $filter['where'];
$params = $filter['params'];
$allowedSort = ['t.transaction_date DESC','t.transaction_date ASC','t.amount DESC','t.amount ASC','t.receipt_no ASC','m.full_name ASC','t.payment_method ASC'];
$sort = 't.transaction_date DESC';
if (isset($_GET['sort']) && !empty($_GET['sort'])) {
    $rawSort = cleanInput($_GET['sort']);
    if (in_array($rawSort, $allowedSort)) { $sort = $rawSort; }
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Count total for pagination
$countQuery = "SELECT COUNT(*) as total FROM transactions t JOIN members m ON t.member_id = m.member_id {$where_clause}";
$countStmt = $db->prepare($countQuery);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetch()['total'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

// Stats for the filtered set (before LIMIT)
$statsQuery = "SELECT COUNT(*) as cnt, COALESCE(SUM(amount),0) as total FROM transactions t {$where_clause}";
$statsStmt = $db->prepare($statsQuery);
$statsStmt->execute($params);
$stats = $statsStmt->fetch();
$displayedCount = (int)$stats['cnt'];
$displayedTotal = (float)$stats['total'];

$transactions_query = "SELECT t.*, m.full_name, m.passport_photo
                      FROM transactions t
                      JOIN members m ON t.member_id = m.member_id
                      {$where_clause}
                      ORDER BY {$sort}
                      LIMIT :limit OFFSET :offset";

$transactions_stmt = $db->prepare($transactions_query);
foreach ($params as $k => $v) { $transactions_stmt->bindValue($k, $v); }
$transactions_stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$transactions_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$transactions_stmt->execute();
$transactions = $transactions_stmt->fetchAll();

$currentSort = $_GET['sort'] ?? 't.transaction_date DESC';
$sortParts = explode(' ', $currentSort, 2);
$sortField = $sortParts[0];
$sortDir = isset($sortParts[1]) ? strtoupper($sortParts[1]) : 'DESC';
function buildSortUrl($field, $currentField, $currentDir) {
    $p = $_GET;
    $nextDir = ($field === $currentField && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $p['sort'] = $field . ' ' . $nextDir;
    $p['page'] = 1;
    return '?' . http_build_query($p);
}
function sortIcon($field, $currentField, $currentDir) {
    if ($field === $currentField) {
        return $currentDir === 'ASC' ? ' &uarr;' : ' &darr;';
    }
    return '';
}
?>

<div class="row">
    <div class="col-12">
        <h2 class="mb-4">Transaction Management</h2>
    </div>
</div>

<div class="row mb-3" id="statsBar">
    <div class="col-md-4">
        <div class="card border-0 bg-transparent"><div class="card-body py-2">
            <small class="text-muted">Showing</small> <strong><?php echo $displayedCount; ?></strong> <small class="text-muted">of <?php echo $totalRows; ?> transactions</small>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 bg-transparent"><div class="card-body py-2">
            <small class="text-muted">Total:</small> <strong class="text-success">GH₵ <?php echo number_format($displayedTotal, 2); ?></strong>
        </div></div>
    </div>
    <div class="col-md-4 text-end">
        <div class="card border-0 bg-transparent"><div class="card-body py-2">
            <?php if ($totalPages > 1): ?>
                <small class="text-muted">Page <?php echo $page; ?> of <?php echo $totalPages; ?></small>
            <?php endif; ?>
        </div></div>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo $success; ?>
        <?php if (isset($_SESSION['last_receipt'])): ?>
            <button class="btn btn-sm btn-success ms-3" id="printReceiptBtn">
                🖨️ Print Receipt
            </button>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    
    <?php if (isset($_SESSION['last_receipt'])): ?>
        <div class="receipt mb-4" id="printableReceipt">
            <div class="text-center mb-3">
                <h4>GYF Welfare Management System</h4>
                <h5>Payment Receipt</h5>
            </div>
            <div class="table-responsive">
            <table class="table table-bordered">
                <tr>
                    <td><strong>Receipt No:</strong></td>
                    <td><?php echo htmlspecialchars($_SESSION['last_receipt']['receipt_no']); ?></td>
                </tr>
                <tr>
                    <td><strong>Member:</strong></td>
                    <td><?php echo htmlspecialchars($_SESSION['last_receipt']['member_name']); ?> (<?php echo htmlspecialchars($_SESSION['last_receipt']['member_id']); ?>)</td>
                </tr>
                <tr>
                    <td><strong>Amount:</strong></td>
                    <td>GH₵ <?php echo number_format($_SESSION['last_receipt']['amount'], 2); ?></td>
                </tr>
                <tr>
                    <td><strong>Payment Method:</strong></td>
                    <td><?php echo htmlspecialchars($_SESSION['last_receipt']['payment_method']); ?></td>
                </tr>
                <tr>
                    <td><strong>Billing Period:</strong></td>
                    <td><?php echo htmlspecialchars($_SESSION['last_receipt']['billing_period']); ?></td>
                </tr>
                <tr>
                    <td><strong>Date:</strong></td>
                    <td><?php echo htmlspecialchars(date('F j, Y g:i A', strtotime($_SESSION['last_receipt']['date']))); ?></td>
                </tr>
            </table>
            </div>
            <div class="text-center mt-3">
                <small>This is a computer-generated receipt</small>
            </div>
        </div>
        <?php unset($_SESSION['last_receipt']); ?>
    <?php endif; ?>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Record Payment Button -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-grid gap-2 d-md-flex align-items-center flex-wrap">
            <button class="btn btn-primary btn-lg" type="button" id="openPaymentModalBtn">
                ➕ Record New Payment
            </button>
            <button class="btn btn-info btn-lg text-white" type="button" id="openBrowseMembersBtn">
                👥 Browse Members
            </button>
            <button class="btn btn-outline-warning btn-lg" type="button" id="openBatchModalBtn">
                📦 Batch Record
            </button>
            <button class="btn btn-outline-danger btn-lg" type="button" id="undoLastTransactionBtn">
                ↩️ Undo Last
            </button>
            <form id="exportCsvForm" method="POST" action="<?php echo APP_URL; ?>/api/transactions.php?action=export_csv" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <?php foreach (array_filter($_GET, function($k){return $k!=="action"&&$k!=="export";}, ARRAY_FILTER_USE_KEY) as $k => $v): ?>
                <input type="hidden" name="<?php echo htmlspecialchars($k); ?>" value="<?php echo htmlspecialchars($v); ?>">
                <?php endforeach; ?>
                <button type="submit" class="btn btn-success btn-lg">📊 Export CSV</button>
            </form>
            <form id="exportPdfForm" method="POST" action="<?php echo APP_URL; ?>/api/transactions.php?action=export_pdf" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <?php foreach (array_filter($_GET, function($k){return $k!=="action"&&$k!=="export";}, ARRAY_FILTER_USE_KEY) as $k => $v): ?>
                <input type="hidden" name="<?php echo htmlspecialchars($k); ?>" value="<?php echo htmlspecialchars($v); ?>">
                <?php endforeach; ?>
                <button type="submit" class="btn btn-danger btn-lg">📄 Export PDF</button>
            </form>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Record New Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Member Search -->
                <div class="mb-4">
                    <label class="form-label">Search Member</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="memberSearch" 
                               placeholder="Search by name, member ID, or phone...">
                        <button class="btn btn-primary" type="button" id="searchMembersBtn">Search</button>
                    </div>
                    <div id="searchResults" class="mt-2"></div>
                </div>
                 <!-- Selected Member Display -->
                <div id="selectedMemberInfo" class="alert alert-success py-2 mb-3" style="display:none;">
                    <div class="d-flex align-items-center">
                        <img id="selectedMemberPhoto" src="" alt="" class="member-photo me-2" style="width:40px;height:40px;object-fit:cover;border-radius:50%;display:none;">
                        <div class="flex-grow-1">
                            <strong id="selectedMemberNameDisplay">No member selected</strong>
                            <div class="small text-muted">
                                <span id="selectedMemberIdDisplay"></span> | 
                                <span id="selectedMemberContactDisplay"></span>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="clearMemberBtn">Change</button>
                    </div>
                </div>

                
                 <!-- Payment Form -->
                <form method="POST" action="" id="paymentForm">
                    <input type="hidden" name="action" value="record_payment">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="member_id" id="selectedMemberId">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="amount" class="form-label">Amount (GH₵) *</label>
                            <input type="number" class="form-control" id="amount" name="amount" 
                                   step="0.01" min="0.01" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="payment_method" class="form-label">Payment Method *</label>
                            <select class="form-control" id="payment_method" name="payment_method" required>
                                <option value="">Select Method</option>
                                <option value="Cash">Cash</option>
                                <option value="Mobile Money">Mobile Money</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Card">Card</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="billing_month" class="form-label">Billing Month *</label>
                            <select class="form-control" id="billing_month" name="billing_month" required>
                                <option value="">Select Month</option>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m == date('m') ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="billing_year" class="form-label">Billing Year *</label>
                            <select class="form-control" id="billing_year" name="billing_year" required>
                                <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="transaction_date" class="form-label">Transaction Date *</label>
                            <input type="date" class="form-control" id="transaction_date" name="transaction_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="transaction_time" class="form-label">Transaction Time *</label>
                            <input type="time" class="form-control" id="transaction_time" name="transaction_time" 
                                   value="<?php echo date('H:i'); ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label for="notes" class="form-label">Notes (optional)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Any remarks about this payment..."></textarea>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <div><strong>Selected Member:</strong> <span class="selected-dot" id="selectedDot"></span><span id="selectedMemberName">None</span></div>
                        <div id="memberProgress" class="mt-2" style="display:none;">
                            <small class="text-muted">Annual progress:</small>
                            <div class="progress mt-1" style="height:8px;">
                                <div class="progress-bar bg-success" id="memberProgressBar" role="progressbar" style="width:0%;"></div>
                            </div>
                            <small id="memberProgressText" class="text-muted"></small>
                        </div>
                        <div id="dupWarning" class="mt-2" style="display:none;"></div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100" id="submitPayment" disabled>
                        Record Payment
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Batch Payment Modal -->
<div class="modal fade" id="batchModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Batch Record Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="batchPaymentForm">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Amount (GH₵) *</label>
                            <input type="number" class="form-control" id="batchAmount" step="0.01" min="0.01" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Payment Method *</label>
                            <select class="form-control" id="batchMethod" required>
                                <option value="">Select Method</option>
                                <option value="Cash">Cash</option>
                                <option value="Mobile Money">Mobile Money</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Card">Card</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Billing Month *</label>
                            <select class="form-control" id="batchMonth" required>
                                <option value="">Select Month</option>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m == date('m') ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Billing Year *</label>
                            <select class="form-control" id="batchYear" required>
                                <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Transaction Date *</label>
                            <input type="date" class="form-control" id="batchDate" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Transaction Time *</label>
                            <input type="time" class="form-control" id="batchTime" value="<?php echo date('H:i'); ?>" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label">Member IDs (comma-separated) *</label>
                            <textarea class="form-control" id="batchMemberIds" rows="2" placeholder="GYF-001, GYF-002, GYF-003" required></textarea>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes (optional)</label>
                        <textarea class="form-control" id="batchNotes" rows="2" placeholder="Batch payment notes..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-success w-100">Record Batch Payment</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Browse Members Modal -->
<div class="modal fade" id="browseMembersModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Browse Members</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="input-group">
                        <input type="text" class="form-control" id="browseMemberSearch"
                               placeholder="Search by name, member ID, or phone...">
                        <button class="btn btn-primary" type="button" id="browseMemberSearchBtn">Search</button>
                    </div>
                    <div class="form-text">Tap a member to open the payment form for them.</div>
                </div>
                <div id="browseMembersLoading" class="text-center py-4" style="display:none;">
                    <div class="spinner-border text-primary"></div>
                </div>
                <div class="row g-3" id="browseMembersGrid"></div>
                <div id="browseMembersEmpty" class="alert alert-warning" style="display:none;">No members found.</div>
            </div>
        </div>
    </div>
</div>

<!-- Undo Last Transaction Modal -->
<div class="modal fade" id="undoModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">Undo Last Transaction</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to undo the last transaction? This cannot be undone.</p>
                <div class="mb-3">
                    <label class="form-label">Reason for undo *</label>
                    <textarea class="form-control" id="undoReason" rows="3" required placeholder="Enter reason..."></textarea>
                </div>
                <input type="hidden" id="undoTransactionId" value="">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="confirmUndoBtn">Yes, Undo</button>
            </div>
        </div>
    </div>
</div>

<!-- Recurring Late Payers Alert -->
<?php
$late_payers = [];
try {
    $late_payers = getRecurringLatePayers($db);
} catch (Exception $e) {}
if (!empty($late_payers)):
?>
<div class="row mb-3">
    <div class="col-12">
        <div class="alert alert-warning">
            <strong>⚠️ Recurring Late Payers (Last Month):</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($late_payers as $lp): ?>
                    <li><?php echo htmlspecialchars($lp['full_name']); ?> (<?php echo htmlspecialchars($lp['member_id']); ?>) - <?php echo $lp['late_count']; ?> late payment(s)</li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Transaction History -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Transaction History</h5>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" action="" class="row g-2" id="filterForm">
                    <div class="col-md-2">
                        <input type="text" class="form-control" name="filter_receipt" placeholder="Receipt #" value="<?php echo htmlspecialchars($_GET['filter_receipt'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <input type="text" class="form-control" name="filter_member" placeholder="Member ID" value="<?php echo htmlspecialchars($_GET['filter_member'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <input type="date" class="form-control" name="filter_date_from" placeholder="From" value="<?php echo htmlspecialchars($_GET['filter_date_from'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <input type="date" class="form-control" name="filter_date_to" placeholder="To" value="<?php echo htmlspecialchars($_GET['filter_date_to'] ?? ''); ?>">
                    </div>
                    <div class="col-md-1">
                        <input type="number" step="0.01" class="form-control" name="filter_amount_min" placeholder="Min" value="<?php echo htmlspecialchars($_GET['filter_amount_min'] ?? ''); ?>">
                    </div>
                    <div class="col-md-1">
                        <input type="number" step="0.01" class="form-control" name="filter_amount_max" placeholder="Max" value="<?php echo htmlspecialchars($_GET['filter_amount_max'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <select class="form-control" name="filter_method">
                            <option value="">All Methods</option>
                            <option value="Cash" <?php echo ($_GET['filter_method'] ?? '') === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="Mobile Money" <?php echo ($_GET['filter_method'] ?? '') === 'Mobile Money' ? 'selected' : ''; ?>>Mobile Money</option>
                            <option value="Bank Transfer" <?php echo ($_GET['filter_method'] ?? '') === 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="Card" <?php echo ($_GET['filter_method'] ?? '') === 'Card' ? 'selected' : ''; ?>>Card</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100">🔍 Filter</button>
                    </div>
                    <div class="col-md-4">
                        <a href="<?php echo APP_URL; ?>/treasurer/transactions.php" class="btn btn-outline-secondary w-100">🔄 Reset</a>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-success w-100" id="bulkExportCsv">📊 Export CSV</button>
                    </div>
                </form>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAll" title="Select all"></th>
                                <th><a href="<?php echo buildSortUrl('t.receipt_no', $sortField, $sortDir); ?>" class="sort-link">Receipt #<?php echo sortIcon('t.receipt_no', $sortField, $sortDir); ?></a></th>
                                <th><a href="<?php echo buildSortUrl('m.full_name', $sortField, $sortDir); ?>" class="sort-link">Member<?php echo sortIcon('m.full_name', $sortField, $sortDir); ?></a></th>
                                <th><a href="<?php echo buildSortUrl('t.amount', $sortField, $sortDir); ?>" class="sort-link">Amount<?php echo sortIcon('t.amount', $sortField, $sortDir); ?></a></th>
                                <th><a href="<?php echo buildSortUrl('t.payment_method', $sortField, $sortDir); ?>" class="sort-link">Method<?php echo sortIcon('t.payment_method', $sortField, $sortDir); ?></a></th>
                                <th><a href="<?php echo buildSortUrl('t.transaction_date', $sortField, $sortDir); ?>" class="sort-link">Date<?php echo sortIcon('t.transaction_date', $sortField, $sortDir); ?></a></th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td><input type="checkbox" class="bulk-check" value="<?php echo (int)$transaction['id']; ?>"></td>
                                    <td><?php echo htmlspecialchars($transaction['receipt_no']); ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php if ($transaction['passport_photo']): ?>
                                                <img src="<?php echo displayPhotoUrl($transaction['passport_photo']); ?>"
                                                     class="member-photo me-2" alt="Photo">
                                            <?php endif; ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($transaction['full_name']); ?></strong>
                                                <br>
                                                <small><?php echo htmlspecialchars($transaction['member_id']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>GH₵ <?php echo number_format($transaction['amount'], 2); ?></td>
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
                                            echo $transaction['billing_cycle_year'];
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($transaction['transaction_date'] ? date('M d, Y', strtotime($transaction['transaction_date'])) : 'N/A'); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" data-transaction-id="<?php echo (int)$transaction['id']; ?>" data-action="view">
                                            👁️ View
                                        </button>
                                         <button class="btn btn-sm btn-success" data-transaction-id="<?php echo (int)$transaction['id']; ?>" data-action="print">
                                            🖨️ Print
                                        </button>
                                        <button class="btn btn-sm btn-secondary" data-transaction-id="<?php echo (int)$transaction['id']; ?>" data-action="details">
                                            📋 Details
                                        </button>
                                        <button class="btn btn-sm btn-danger" data-transaction-id="<?php echo (int)$transaction['id']; ?>" data-action="void">
                                            🚫 Void
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="row mt-3">
                        <div class="col-12 d-flex justify-content-between align-items-center">
                            <div>
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="btn btn-sm btn-outline-primary">&larr; Previous</a>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted">Page <?php echo $page; ?> of <?php echo $totalPages; ?></small>
                            <div>
                                <?php if ($page < $totalPages): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="btn btn-sm btn-outline-primary">Next &rarr;</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo CSP_NONCE; ?>">
// API base derived from the origin that actually served this page, combined with
// the sub-directory path configured in APP_URL. This keeps AJAX calls same-origin
// in every context (local XAMPP in /welfare, Vercel root, preview deploys, Capacitor
// webview) and avoids ERR_NAME_NOT_RESOLVED when APP_URL points at a different host.
const APP_BASE = (function () {
    var path = '';
    try {
        var a = document.createElement('a');
        a.href = '<?php echo APP_URL; ?>';
        path = (a.pathname || '/').replace(/\/+$/, '');
    } catch (e) {}
    return window.location.origin + path;
})();

function searchMembers() {
    const searchTerm = document.getElementById('memberSearch').value;
    if (searchTerm.length < 2) {
        showToast('Please enter at least 2 characters to search', 'warning');
        return;
    }

    const escapeHtml = (str) => {
        if (!str && str !== '') return '';
        return String(str).replace(/[&<>'"]/g, tag =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
        );
    };

    fetch(`${APP_BASE}/api/members.php?action=search&term=${encodeURIComponent(searchTerm)}`)
        .then(response => response.json())
        .then(data => {
            let html = '';
            if (data.success && data.members.length > 0) {
                data.members.forEach(member => {
                    const safeName = escapeHtml(member.full_name);
                    const safeId = escapeHtml(member.member_id);
                    const safeEmail = escapeHtml(member.email || '');
                    const safePhone = escapeHtml(member.phone);
                    const safePhoto = member.passport_photo ? escapeHtml(String(member.passport_photo)) : '';
                    const photoUrl = (p) => { if (!p) return ''; p = String(p); return p.indexOf('http') === 0 ? p : APP_BASE + '/uploads/photos/' + p; };
                    html += `
                        <div class="card mb-2 member-card" style="cursor: pointer;"
                             data-member-id="${safeId}" data-member-name="${safeName}" data-member-email="${safeEmail}" data-member-phone="${safePhone}" data-member-photo="${safePhoto}">
                            <span class="selected-dot"></span>
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    ${safePhoto ?
                                        `<img src="${photoUrl(safePhoto)}"
                                              class="member-photo me-3" onerror="this.style.display='none'">` : ''}
                                    <div>
                                        <strong>${safeName}</strong><br>
                                        <small>${safeId} | ${safePhone}</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
            } else {
                html = '<div class="alert alert-warning">No members found</div>';
            }
            document.getElementById('searchResults').innerHTML = html;
            const memberIdEl = document.getElementById('selectedMemberId');
            const curId = memberIdEl ? memberIdEl.value : '';
            if (curId) {
                const cards = document.querySelectorAll('#searchResults .member-card');
                cards.forEach(c => {
                    if (c.getAttribute('data-member-id') === curId) {
                        c.classList.add('selected');
                    } else {
                        c.style.pointerEvents = 'none';
                        c.style.opacity = '0.5';
                    }
                });
            }
        });
}

function selectMember(memberId, memberName, memberEmail, memberPhone, memberPhoto) {
    const idEl = document.getElementById('selectedMemberId');
    const nameEl = document.getElementById('selectedMemberName');
    const resultsEl = document.getElementById('searchResults');
    const searchEl = document.getElementById('memberSearch');
    const submitEl = document.getElementById('submitPayment');
    const dotEl = document.getElementById('selectedDot');
    const infoEl = document.getElementById('selectedMemberInfo');
    const infoName = document.getElementById('selectedMemberNameDisplay');
    const infoId = document.getElementById('selectedMemberIdDisplay');
    const infoContact = document.getElementById('selectedMemberContactDisplay');
    const infoPhoto = document.getElementById('selectedMemberPhoto');
    if (!idEl || !nameEl) return;

    idEl.value = memberId;
    nameEl.textContent = memberName;
    nameEl.classList.add('is-selected');
    if (dotEl) dotEl.style.display = 'inline-block';

    // Show selected member info banner
    if (infoEl) {
        infoEl.style.display = 'block';
        if (infoName) infoName.textContent = memberName;
        if (infoId) infoId.textContent = memberId;
        if (infoContact) infoContact.textContent = [memberEmail, memberPhone].filter(Boolean).join(' | ') || 'No contact info';
        if (infoPhoto && memberPhoto) {
            infoPhoto.src = memberPhoto;
            infoPhoto.style.display = 'inline-block';
        } else if (infoPhoto) {
            infoPhoto.style.display = 'none';
        }
    }

    if (resultsEl) {
        const cards = resultsEl.querySelectorAll('.member-card');
        cards.forEach(c => {
            if (c.getAttribute('data-member-id') === memberId) {
                c.classList.add('selected');
                c.style.pointerEvents = 'none';
                c.style.opacity = '1';
            } else {
                c.classList.remove('selected');
                c.style.pointerEvents = 'none';
                c.style.opacity = '0.5';
            }
        });
    }
    if (searchEl) searchEl.value = memberName;
    if (submitEl) { submitEl.disabled = false; validateForm(); }
}

function clearMemberSelection() {
    const idEl = document.getElementById('selectedMemberId');
    const nameEl = document.getElementById('selectedMemberName');
    const resultsEl = document.getElementById('searchResults');
    const searchEl = document.getElementById('memberSearch');
    const submitEl = document.getElementById('submitPayment');
    const dotEl = document.getElementById('selectedDot');
    const infoEl = document.getElementById('selectedMemberInfo');
    const progressEl = document.getElementById('memberProgress');
    const dupWarningEl = document.getElementById('dupWarning');
    if (idEl) idEl.value = '';
    if (nameEl) { nameEl.textContent = 'None'; nameEl.classList.remove('is-selected'); }
    if (dotEl) dotEl.style.display = 'none';
    if (infoEl) infoEl.style.display = 'none';
    if (resultsEl) {
        const cards = resultsEl.querySelectorAll('.member-card');
        cards.forEach(c => {
            c.classList.remove('selected');
            c.style.pointerEvents = 'auto';
            c.style.opacity = '1';
        });
    }
    if (searchEl) searchEl.value = '';
    if (submitEl) submitEl.disabled = true;
    if (progressEl) progressEl.style.display = 'none';
    if (dupWarningEl) dupWarningEl.style.display = 'none';
}

function validateForm() {
    const submitEl = document.getElementById('submitPayment');
    if (!submitEl) return;
    
    const memberIdEl = document.getElementById('selectedMemberId');
    const amountEl = document.getElementById('amount');
    const methodEl = document.getElementById('payment_method');
    const monthEl = document.getElementById('billing_month');
    const yearEl = document.getElementById('billing_year');
    const txDateEl = document.getElementById('transaction_date');
    const txTimeEl = document.getElementById('transaction_time');
    
    const memberId = memberIdEl ? memberIdEl.value : '';
    const amount = amountEl ? amountEl.value : '';
    const method = methodEl ? methodEl.value : '';
    const month = monthEl ? monthEl.value : '';
    const year = yearEl ? yearEl.value : '';
    const txDate = txDateEl ? txDateEl.value : '';
    const txTime = txTimeEl ? txTimeEl.value : '';
    
    const ready = memberId && amount && method && month && year && txDate && txTime;
    submitEl.disabled = !ready;
    submitEl.classList.toggle('ready', ready);
}

['amount', 'payment_method', 'billing_month', 'billing_year', 'transaction_date', 'transaction_time'].forEach(function (id) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', validateForm);
});

var clearBtn = document.getElementById('clearMemberBtn');
    if (clearBtn) {
        clearBtn.addEventListener('click', clearMemberSelection);
    }

    document.getElementById('paymentForm').addEventListener('submit', function (e) {
    const memberIdEl = document.getElementById('selectedMemberId');
    const memberId = memberIdEl ? memberIdEl.value : '';
    if (!memberId) {
        e.preventDefault();
        showToast('Please select a member first.', 'danger');
        return;
    }
    validateForm();
    if (document.getElementById('submitPayment').disabled) {
        e.preventDefault();
        showToast('Please fill in all required payment details.', 'warning');
    }
});

function viewReceipt(transactionId) {
    window.open(`${APP_BASE}/api/transactions.php?action=receipt&id=${transactionId}`,
                'Receipt', 'width=600,height=400');
}

function printReceipt(transactionId) {
    window.open(`${APP_BASE}/api/transactions.php?action=receipt&id=${transactionId}&print=1`,
        'Receipt', 'width=600,height=400');
}

// Pre-select member when redirected from members page with ?member_id=...&member_name=...
(function() {
    var params = new URLSearchParams(window.location.search);
    var memberId = params.get('member_id');
    var memberName = params.get('member_name');
    if (memberId && memberName) {
        var trySelect = function() {
            var idField = document.getElementById('selectedMemberId');
            var nameField = document.getElementById('selectedMemberName');
            var searchField = document.getElementById('memberSearch');
            if (idField && nameField) {
                idField.value = memberId;
                nameField.textContent = decodeURIComponent(memberName);
                if (searchField) searchField.value = decodeURIComponent(memberName);
                var submitBtn = document.getElementById('submitPayment');
                if (submitBtn) submitBtn.disabled = false;
                document.getElementById('searchResults').innerHTML = '';
                var dn = document.getElementById('selectedDot');
                if (dn) dn.style.display = 'inline-block';
                var nn = document.getElementById('selectedMemberName');
                if (nn) nn.classList.add('is-selected');
                validateForm();
                return;
            }
            setTimeout(trySelect, 200);
        };
        trySelect();
    }
})();

// Event delegation for member result cards (avoids inline-onclick quoting bugs)
document.addEventListener('click', function (e) {
    var card = e.target.closest ? e.target.closest('.member-card[data-member-id]') : null;
    if (!card) return;
    selectMember(card.getAttribute('data-member-id'), card.getAttribute('data-member-name'));
});

// Debounced search-as-you-type
let _searchTimer = null;
document.getElementById('memberSearch').addEventListener('input', function () {
    clearTimeout(_searchTimer);
    const v = this.value;
    if (v.length < 2) { document.getElementById('searchResults').innerHTML = ''; return; }
    _searchTimer = setTimeout(searchMembers, 350);
});

// Member annual progress + duplicate-cycle warning
function refreshMemberContext(memberId) {
    if (!memberId) {
        const progressEl = document.getElementById('memberProgress');
        const dupWarningEl = document.getElementById('dupWarning');
        if (progressEl) progressEl.style.display = 'none';
        if (dupWarningEl) dupWarningEl.style.display = 'none';
        return;
    }
    const month = document.getElementById('billing_month').value;
    const year = document.getElementById('billing_year').value;
    const base = APP_BASE + '/api/members.php';
    fetch(base + '?action=details&member_id=' + encodeURIComponent(memberId))
        .then(r => r.json())
        .then(d => {
            if (!d.success) return;
            const m = d.member;
            const ytd = parseFloat(m.ytd_paid || 0);
            const target = parseFloat(m.annual_target || 0);
            const pct = target > 0 ? Math.min(100, Math.round((ytd / target) * 100)) : 0;
            const el = document.getElementById('memberProgress');
            if (el) el.style.display = 'block';
            const bar = document.getElementById('memberProgressBar');
            if (bar) bar.style.width = pct + '%';
            const txt = document.getElementById('memberProgressText');
            if (txt) txt.textContent =
                'GH₵ ' + ytd.toFixed(2) + ' of GH₵ ' + target.toFixed(2) + ' (' + pct + '%)';
        })
        .catch(() => {});
    checkDuplicate(memberId, month, year);
}

function checkDuplicate(memberId, month, year) {
    const box = document.getElementById('dupWarning');
    if (!box) return;
    if (!memberId || !month || !year) { box.style.display = 'none'; return; }
    fetch(APP_BASE + '/api/transactions.php?action=check_duplicate&member_id=' +
        encodeURIComponent(memberId) + '&month=' + encodeURIComponent(month) + '&year=' + encodeURIComponent(year))
        .then(r => r.json())
        .then(d => {
            if (d.success && d.exists) {
                box.style.display = 'block';
                box.className = 'mt-2 alert alert-warning py-2 mb-0';
                box.innerHTML = '⚠️ This member already has a payment recorded for ' +
                    d.month_name + ' ' + d.year + ' (Receipt ' + d.receipt_no + '). Recording again will be blocked.';
            } else {
                box.style.display = 'none';
            }
        })
        .catch(() => { box.style.display = 'none'; });
}

['billing_month', 'billing_year'].forEach(function (id) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', function () {
        checkDuplicate(document.getElementById('selectedMemberId').value,
            document.getElementById('billing_month').value,
            document.getElementById('billing_year').value);
    });
});

const _origSelect = selectMember;
selectMember = function (memberId, memberName) {
    _origSelect(memberId, memberName);
    refreshMemberContext(memberId);
};

// Keyboard shortcut: Ctrl+K to focus search
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const searchInput = document.getElementById('memberSearch');
        if (searchInput) { searchInput.focus(); searchInput.select(); }
    }
});

// Transaction details modal
function showTxDetail(transactionId) {
    const modalEl = document.getElementById('txDetailModal');
    const body = document.getElementById('txDetailBody');
    if (!modalEl || !body) return;
    body.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';
    const bsModal = new bootstrap.Modal(modalEl);
    bsModal.show();
    fetch(`${APP_BASE}/api/transactions.php?action=details&id=${transactionId}`)
        .then(r => r.json()).then(d => {
            if (!d.success) { body.innerHTML = '<div class="alert alert-danger">Failed to load details.</div>'; return; }
            const t = d.transaction;
            const esc = (s) => { if (!s && s !== '') return ''; return String(s).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c] || c)); };
            let rows = '<tr><td><strong>Receipt No</strong></td><td>' + esc(t.receipt_no) + '</td></tr>' +
                '<tr><td><strong>Member</strong></td><td>' + esc(t.full_name) + ' (' + esc(t.member_id) + ')</td></tr>' +
                '<tr><td><strong>Amount</strong></td><td class="text-success fw-bold">GH₵ ' + parseFloat(t.amount).toFixed(2) + '</td></tr>' +
                '<tr><td><strong>Method</strong></td><td>' + esc(t.payment_method) + '</td></tr>' +
                '<tr><td><strong>Billing Period</strong></td><td>' + esc(t.billing_period) + '</td></tr>' +
                '<tr><td><strong>Date</strong></td><td>' + esc(t.transaction_date) + '</td></tr>' +
                '<tr><td><strong>Treasurer</strong></td><td>' + esc(t.treasurer_id || 'N/A') + '</td></tr>';
            if (t.notes) {
                rows += '<tr><td><strong>Notes</strong></td><td>' + esc(t.notes) + '</td></tr>';
            }
            body.innerHTML = '<div class="table-responsive"><table class="table table-bordered">' + rows + '</table></div>' +
                '<div class="mt-3 text-center">' +
                '<button class="btn btn-primary me-2" data-transaction-id="' + transactionId + '" data-action="print">🖨️ Print Receipt</button>' +
                '<button class="btn btn-outline-primary" data-member-id="' + esc(t.member_id) + '" data-action="history">📋 Member History</button>' +
                '</div>' +
                '<div id="memberHistoryContainer" class="mt-3" style="display:none;"></div>';
        }).catch(() => { body.innerHTML = '<div class="alert alert-danger">Failed to load details.</div>'; });
}

function loadMemberHistory(memberId) {
    const container = document.getElementById('memberHistoryContainer');
    if (!container) return;
    container.style.display = 'block';
    container.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary"></div></div>';
    fetch(`${APP_BASE}/api/transactions.php?action=member_history&member_id=${memberId}`)
        .then(r => r.json()).then(d => {
            if (!d.success || !d.history.length) { container.innerHTML = '<div class="alert alert-info">No payment history found.</div>'; return; }
            const esc = (s) => { if (!s && s !== '') return ''; return String(s).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c] || c)); };
            let html = '<h6>Payment History for ' + esc(memberId) + '</h6><div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Receipt</th><th>Amount</th><th>Method</th><th>Period</th><th>Date</th></tr></thead><tbody>';
            d.history.forEach(h => {
                html += '<tr><td>' + esc(h.receipt_no) + '</td><td class="text-success">GH₵ ' + parseFloat(h.amount).toFixed(2) + '</td><td>' + esc(h.payment_method) + '</td><td>' + esc(h.billing_period || 'N/A') + '</td><td>' + esc(h.transaction_date) + '</td></tr>';
            });
            html += '</tbody></table></div>';
            container.innerHTML = html;
        }).catch(() => { container.innerHTML = '<div class="alert alert-danger">Failed to load history.</div>'; });
}

// Bulk actions
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.bulk-check').forEach(cb => cb.checked = this.checked);
});

document.getElementById('bulkExportCsv')?.addEventListener('click', function() {
    const ids = Array.from(document.querySelectorAll('.bulk-check:checked')).map(cb => cb.value);
    if (!ids.length) { showToast('Please select at least one transaction.', 'warning'); return; }
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = APP_BASE + '/api/transactions.php?action=export_csv';
    const csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = 'csrf_token';
    csrf.value = '<?php echo $csrf_token; ?>';
    form.appendChild(csrf);
    const idsInput = document.createElement('input');
    idsInput.type = 'hidden';
    idsInput.name = 'ids';
    idsInput.value = ids.join(',');
    form.appendChild(idsInput);
    document.body.appendChild(form);
    form.submit();
});

// Void transaction
function voidTransaction(transactionId) {
    document.getElementById('voidTransactionId').value = transactionId;
    document.getElementById('voidReason').value = '';
    new bootstrap.Modal(document.getElementById('voidModal')).show();
}

function confirmVoid() {
    const transactionId = document.getElementById('voidTransactionId').value;
    const reason = document.getElementById('voidReason').value.trim();
    if (!reason) { showToast('Please enter a reason for voiding.', 'warning'); return; }
    fetch(APP_BASE + '/api/transactions.php?action=void', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'transaction_id=' + encodeURIComponent(transactionId) + '&reason=' + encodeURIComponent(reason) + '&csrf_token=<?php echo $csrf_token; ?>'
    })
    .then(r => r.json()).then(d => {
        if (d.success) { showToast('Transaction voided successfully.', 'success'); setTimeout(() => location.reload(), 1000); }
        else { showToast(d.message || 'Failed to void transaction.', 'danger'); }
    }).catch(() => showToast('Error voiding transaction.', 'danger'));
}

// Undo last transaction
function undoLastTransaction() {
    fetch(APP_BASE + '/api/transactions.php?action=get_last')
        .then(r => r.json()).then(d => {
            if (!d.success || !d.transaction) { showToast('No recent transaction found.', 'warning'); return; }
            document.getElementById('undoTransactionId').value = d.transaction.id;
            document.getElementById('undoReason').value = '';
            new bootstrap.Modal(document.getElementById('undoModal')).show();
        }).catch(() => showToast('Error loading last transaction.', 'danger'));
}

function confirmUndo() {
    const transactionId = document.getElementById('undoTransactionId').value;
    const reason = document.getElementById('undoReason').value.trim();
    if (!reason) { showToast('Please enter a reason for undo.', 'warning'); return; }
    fetch(APP_BASE + '/api/transactions.php?action=void', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'transaction_id=' + encodeURIComponent(transactionId) + '&reason=' + encodeURIComponent('UNDO: ' + reason) + '&csrf_token=<?php echo $csrf_token; ?>'
    })
    .then(r => r.json()).then(d => {
        if (d.success) { showToast('Transaction undone successfully.', 'success'); setTimeout(() => location.reload(), 1000); }
        else { showToast(d.message || 'Failed to undo transaction.', 'danger'); }
    }).catch(() => showToast('Error undoing transaction.', 'danger'));
}

// Open payment modal
// `preserve` keeps the currently-selected member (used when a member is picked
// from the Browse Members modal so their selection survives opening the form).
function openPaymentModal(preserve) {
    // Reset form
    const form = document.getElementById('paymentForm');
    const memberIdEl = document.getElementById('selectedMemberId');
    const nameEl = document.getElementById('selectedMemberName');
    const dotEl = document.getElementById('selectedDot');
    const infoEl = document.getElementById('selectedMemberInfo');
    const submitEl = document.getElementById('submitPayment');
    const resultsEl = document.getElementById('searchResults');
    const progressEl = document.getElementById('memberProgress');
    const dupWarningEl = document.getElementById('dupWarning');
    const searchEl = document.getElementById('memberSearch');
    
    if (form) form.reset();
    if (!preserve) {
        if (memberIdEl) memberIdEl.value = '';
        if (nameEl) {
            nameEl.textContent = 'None';
            nameEl.classList.remove('is-selected');
        }
        if (dotEl) dotEl.style.display = 'none';
        if (infoEl) infoEl.style.display = 'none';
    if (submitEl) { submitEl.disabled = true; submitEl.classList.remove('ready'); }
    }
    if (resultsEl) resultsEl.innerHTML = '';
    if (progressEl) progressEl.style.display = 'none';
    if (dupWarningEl) dupWarningEl.style.display = 'none';
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
    modal.show();
    
    // Focus on search input
    setTimeout(function() {
        if (searchEl) searchEl.focus();
    }, 300);
}

// Batch payment modal
function openBatchModal() {
    document.getElementById('batchAmount').value = '';
    document.getElementById('batchMethod').value = '';
    document.getElementById('batchMonth').value = '';
    document.getElementById('batchYear').value = '';
    document.getElementById('batchMemberIds').value = '';
    document.getElementById('batchNotes').value = '';
    new bootstrap.Modal(document.getElementById('batchModal')).show();
}

// Browse Members modal
function openBrowseMembers() {
    const modal = new bootstrap.Modal(document.getElementById('browseMembersModal'));
    modal.show();
    loadBrowseMembers('');
}

function loadBrowseMembers(term) {
    const grid = document.getElementById('browseMembersGrid');
    const loading = document.getElementById('browseMembersLoading');
    const empty = document.getElementById('browseMembersEmpty');
    if (!grid) return;
    if (loading) loading.style.display = 'block';
    if (empty) empty.style.display = 'none';
    grid.innerHTML = '';
    const url = APP_BASE + '/api/members.php?action=list' +
        (term ? '&term=' + encodeURIComponent(term) : '');
    fetch(url)
        .then(r => r.json())
        .then(d => {
            loading.style.display = 'none';
            if (!d.success || !d.members.length) {
                empty.style.display = 'block';
                return;
            }
            const base = APP_BASE + '/uploads/photos/';
            const photoUrl = (p) => { if (!p) return ''; p = String(p); return p.indexOf('http') === 0 ? p : base + p; };
            const esc = (s) => { if (!s && s !== '') return ''; return String(s).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c] || c)); };
            d.members.forEach(m => {
                const photo = m.passport_photo ? photoUrl(esc(m.passport_photo)) : '';
                const card = document.createElement('div');
                card.className = 'col-6 col-sm-4 col-md-3 col-lg-2';
                card.innerHTML = `
                    <div class="card h-100 member-browse-card" style="cursor:pointer;" data-member-id="${esc(m.member_id)}" data-member-name="${esc(m.full_name)}" data-member-phone="${esc(m.phone ?? '')}" data-member-photo="${photo}">
                        <div class="card-body text-center p-2">
                            ${photo
                                ? `<img src="${photo}" class="member-browse-photo mb-2" alt="Photo" onerror="this.style.display='none'">`
                                : `<div class="member-browse-photo-placeholder mb-2"><span>${esc(m.full_name).charAt(0).toUpperCase()}</span></div>`}
                            <div class="fw-bold small text-truncate">${esc(m.full_name)}</div>
                            <div class="small text-muted text-truncate">${esc(m.member_id)}</div>
                        </div>
                    </div>`;
                grid.appendChild(card);
            });
        })
        .catch(() => {
            loading.style.display = 'none';
            empty.style.display = 'block';
            empty.textContent = 'Failed to load members.';
        });
}

function pickBrowseMember(memberId, memberName, memberPhone, memberPhoto) {
    // Close browse modal, then open payment modal prefilled for this member.
    // openPaymentModal(true) shows + resets the form WITHOUT clearing the
    // selected member, so the subsequent selectMember() sticks. (Previously
    // openPaymentModal() ran after selectMember() and wiped the selection.)
    const browseModal = bootstrap.Modal.getInstance(document.getElementById('browseMembersModal'));
    if (browseModal) browseModal.hide();
    const pm = document.getElementById('paymentModal');
    if (pm && typeof selectMember === 'function') {
        openPaymentModal(true);
        // Pass phone + photo so the selected-member banner is complete
        // (the Browse grid now carries data-member-phone / data-member-photo).
        selectMember(memberId, memberName, undefined, memberPhone, memberPhoto);
    }
}

document.getElementById('batchPaymentForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const memberIdsText = document.getElementById('batchMemberIds').value;
    const memberIds = memberIdsText.split(',').map(s => s.trim()).filter(s => s.length > 0);
    if (!memberIds.length) { showToast('Please enter at least one member ID.', 'warning'); return; }
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.textContent = 'Processing...';
    const formData = new FormData();
    formData.append('action', 'batch_payment');
    formData.append('csrf_token', '<?php echo $csrf_token; ?>');
    formData.append('member_ids', JSON.stringify(memberIds));
    formData.append('amount', document.getElementById('batchAmount').value);
    formData.append('payment_method', document.getElementById('batchMethod').value);
    formData.append('billing_month', document.getElementById('batchMonth').value);
    formData.append('billing_year', document.getElementById('batchYear').value);
    formData.append('notes', document.getElementById('batchNotes').value);
    formData.append('transaction_date', document.getElementById('batchDate').value);
    formData.append('transaction_time', document.getElementById('batchTime').value);
    fetch(APP_BASE + '/api/transactions.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json()).then(d => {
        if (d.success) {
            let msg = d.message + ' (Success: ' + d.success_count + ', Failed: ' + d.fail_count + ')';
            if (d.failures && d.failures.length) {
                msg += ' — ' + d.failures.map(f => f.member_id + ': ' + f.reason).join(' | ');
            }
            showToast(msg, d.fail_count > 0 ? 'warning' : 'success');
            bootstrap.Modal.getInstance(document.getElementById('batchModal'))?.hide();
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(d.message || 'Batch payment failed.', 'danger');
        }
        btn.disabled = false;
        btn.textContent = 'Record Batch Payment';
    }).catch(() => { showToast('Error processing batch payment.', 'danger'); btn.disabled = false; btn.textContent = 'Record Batch Payment'; });
});
</script>

<style>
.member-card {
    transition: all 0.3s ease;
    border: 2px solid transparent;
}
.member-card:hover {
    border-color: var(--dark-blue);
    background-color: var(--light-blue);
}
.member-card.selected {
    border-color: #28a745 !important;
    background-color: rgba(40, 167, 69, 0.15) !important;
    box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.35);
}
.member-card.selected .selected-dot {
    display: inline-block;
}
.selected-dot {
    display: none;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #28a745;
    box-shadow: 0 0 6px rgba(40, 167, 69, 0.8);
    margin-right: 8px;
    vertical-align: middle;
}
#selectedMemberName.is-selected {
    color: #28a745;
}

/* Sort links */
.sort-link { color: var(--text-primary); text-decoration: none; }
.sort-link:hover { color: var(--accent-blue); }

/* Stats bar */
#statsBar .card { background: transparent; }

/* Toast overrides for glassmorphism */
.toast { backdrop-filter: blur(10px); background: rgba(0,0,0,0.85) !important; }

/* Bulk check column */
.bulk-check { cursor: pointer; transform: scale(1.1); }

/* Mobile responsiveness */
@media (max-width: 768px) {
    .table-responsive { font-size: 0.85rem; }
    .btn-sm { padding: 0.2rem 0.4rem; font-size: 0.75rem; }
}
</style>

<!-- Transaction Details Modal -->
<div class="modal fade" id="txDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Transaction Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="txDetailBody">
                <div class="text-center py-4"><div class="spinner-border text-primary"></div></div>
            </div>
        </div>
    </div>
</div>

<!-- Void Transaction Modal -->
<div class="modal fade" id="voidModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Void Transaction</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to void this transaction? This cannot be undone.</p>
                <div class="mb-3">
                    <label class="form-label">Reason for voiding *</label>
                    <textarea class="form-control" id="voidReason" rows="3" required placeholder="Enter reason..."></textarea>
                </div>
                <input type="hidden" id="voidTransactionId" value="">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmVoidBtn">Yes, Void</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>

<script nonce="<?php echo CSP_NONCE; ?>">
document.addEventListener('DOMContentLoaded', function() {
    // Keep export-form submissions same-origin (mirrors APP_BASE logic) so they
    // don't fail DNS when APP_URL points at a different host than the page origin.
    var csvForm = document.getElementById('exportCsvForm');
    if (csvForm) { csvForm.action = APP_BASE + '/api/transactions.php?action=export_csv'; }
    var pdfForm = document.getElementById('exportPdfForm');
    if (pdfForm) { pdfForm.action = APP_BASE + '/api/transactions.php?action=export_pdf'; }

    var printBtn = document.getElementById('printReceiptBtn');
    if (printBtn) { printBtn.addEventListener('click', window.print); }
    
    var searchBtn = document.getElementById('searchMembersBtn');
    if (searchBtn) { searchBtn.addEventListener('click', searchMembers); }
    
    var paymentBtn = document.getElementById('openPaymentModalBtn');
    if (paymentBtn) { paymentBtn.addEventListener('click', openPaymentModal); }
    
    var batchBtn = document.getElementById('openBatchModalBtn');
    if (batchBtn) { batchBtn.addEventListener('click', openBatchModal); }

    var browseBtn = document.getElementById('openBrowseMembersBtn');
    if (browseBtn) { browseBtn.addEventListener('click', openBrowseMembers); }

    var browseSearchBtn = document.getElementById('browseMemberSearchBtn');
    if (browseSearchBtn) { browseSearchBtn.addEventListener('click', function () { loadBrowseMembers(document.getElementById('browseMemberSearch').value.trim()); }); }

    var browseSearchInput = document.getElementById('browseMemberSearch');
    if (browseSearchInput) {
        let _bt = null;
        browseSearchInput.addEventListener('input', function () {
            clearTimeout(_bt);
            const v = this.value.trim();
            _bt = setTimeout(() => loadBrowseMembers(v), 350);
        });
    }

    // Event delegation for browse member cards
    document.addEventListener('click', function (e) {
        var card = e.target.closest ? e.target.closest('.member-browse-card[data-member-id]') : null;
        if (!card) return;
        pickBrowseMember(
            card.getAttribute('data-member-id'),
            card.getAttribute('data-member-name'),
            card.getAttribute('data-member-phone'),
            card.getAttribute('data-member-photo')
        );
    });
    
    var undoBtn = document.getElementById('undoLastTransactionBtn');
    if (undoBtn) { undoBtn.addEventListener('click', undoLastTransaction); }
    
    var confirmUndoBtn = document.getElementById('confirmUndoBtn');
    if (confirmUndoBtn) { confirmUndoBtn.addEventListener('click', confirmUndo); }
    
    var confirmVoidBtn = document.getElementById('confirmVoidBtn');
    if (confirmVoidBtn) { confirmVoidBtn.addEventListener('click', confirmVoid); }
    
    // Single document-level delegation for all [data-action] buttons. This covers
    // both the static row buttons (View/Print/Void/Details) AND buttons injected
    // later into the Transaction Details modal (Print/History), which the old
    // DOMContentLoaded querySelectorAll could never reach.
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-action]') : null;
        if (!b) return;
        var a = b.getAttribute('data-action');
        if (a === 'view') {
            viewReceipt(b.dataset.transactionId);
        } else if (a === 'print') {
            printReceipt(b.dataset.transactionId);
        } else if (a === 'void') {
            voidTransaction(b.dataset.transactionId);
        } else if (a === 'details') {
            showTxDetail(b.dataset.transactionId);
        } else if (a === 'history') {
            loadMemberHistory(b.dataset.memberId);
        }
    });
});
</script>

<style>
/* Browse Members modal - responsive photo grid */
.member-browse-card {
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    border: 1px solid rgba(0,0,0,0.1);
}
.member-browse-card:hover,
.member-browse-card:active {
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(0,0,0,0.18);
    border-color: #0d6efd;
}
.member-browse-photo {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    object-fit: cover;
    display: block;
    margin: 0 auto;
    background: #e9ecef;
}
.member-browse-photo-placeholder {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0d6efd, #6610f2);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
    font-weight: 700;
    margin: 0 auto;
}
@media (max-width: 575.98px) {
    .member-browse-photo,
    .member-browse-photo-placeholder {
        width: 52px;
        height: 52px;
        font-size: 1.3rem;
    }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?> 
