<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

// Check if user is member (must run before header.php outputs HTML)
if (!isMember()) {
    redirectTo('/member/login.php');
}

require_once __DIR__ . '/../includes/header.php';

$database = new Database();
$db = $database->getConnection();
$member_id = $_SESSION['user_id'];

// Get member details
$member_query = "SELECT * FROM members WHERE member_id = :member_id";
$member_stmt = $db->prepare($member_query);
$member_stmt->execute([':member_id' => $member_id]);
$member = $member_stmt->fetch();

// Handle case where member record not found
if (!$member) {
    redirectTo('/api/auth.php?action=logout');
}

// Get payment statistics
$current_year = date('Y');
$current_month = date('m');

// Total paid this year
$yearly_query = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions 
                 WHERE member_id = :member_id AND billing_cycle_year = :year
                 AND status != 'void'";
$yearly_stmt = $db->prepare($yearly_query);
$yearly_stmt->execute([':member_id' => $member_id, ':year' => $current_year]);
$yearly_total = $yearly_stmt->fetch()['total'];

// Paid this month
$monthly_query = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions 
                  WHERE member_id = :member_id AND billing_cycle_month = :month AND billing_cycle_year = :year
                  AND status != 'void'";
$monthly_stmt = $db->prepare($monthly_query);
$monthly_stmt->execute([':member_id' => $member_id, ':month' => $current_month, ':year' => $current_year]);
$monthly_total = $monthly_stmt->fetch()['total'];

// Get year-specific settings
$settings = getYearlyTarget($db, $current_year);
$annual_target = $settings['annual_amount'];
$monthly_target = $settings['monthly_amount'];

// Guard against division by zero
if ($annual_target <= 0) $annual_target = 1;
if ($monthly_target <= 0) $monthly_target = 1;

// Calculate progress
$yearly_percentage = ($yearly_total / $annual_target) * 100;
$remaining = max(0.0, $annual_target - $yearly_total);
$year_debt = $remaining;

// Get recent transactions
$recent_query = "SELECT * FROM transactions 
                 WHERE member_id = :member_id AND status != 'void'
                 ORDER BY transaction_date DESC LIMIT 5";
$recent_stmt = $db->prepare($recent_query);
$recent_stmt->execute([':member_id' => $member_id]);
$recent_transactions = $recent_stmt->fetchAll();

// Get months paid this year
$months_query = "SELECT DISTINCT billing_cycle_month FROM transactions 
                 WHERE member_id = :member_id AND billing_cycle_year = :year
                 AND status != 'void'";
$months_stmt = $db->prepare($months_query);
$months_stmt->execute([':member_id' => $member_id, ':year' => $current_year]);
$paid_months = $months_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex flex-column flex-sm-row align-items-center align-items-sm-center text-center text-sm-start mb-4">
            <?php if ($member['passport_photo']): ?>
                <img src="<?php echo displayPhotoUrl($member['passport_photo']); ?>"
                     class="member-photo member-photo-lg me-sm-3 mb-2 mb-sm-0" alt="Profile">
            <?php endif; ?>
            <div>
                <h2 class="mb-0">Welcome, <?php echo htmlspecialchars($member['full_name']); ?></h2>
                <p class="text-muted mb-0">Member ID: <?php echo htmlspecialchars($member['member_id']); ?></p>
                <?php if ($yearly_total >= $annual_target): ?>
                    <span class="badge bg-success mt-1">✅ Annual Target Reached</span>
                <?php else: ?>
                    <span class="badge bg-warning mt-1">⏳ In Progress</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Status & Statistics -->
<div class="row mb-4">
    <div class="col-sm-6 col-md-3 mb-3">
        <div class="stat-card blue">
            <h6>Monthly Payment</h6>
            <h4>GH₵ <?php echo number_format($monthly_total, 2); ?></h4>
            <small>
                <?php if ($monthly_total > 0): ?>
                    <span class="text-success">✅ Paid this month</span>
                <?php else: ?>
                    <span class="text-danger">⚠️ Pending</span>
                <?php endif; ?>
            </small>
        </div>
    </div>
    <div class="col-sm-6 col-md-3 mb-3">
        <div class="stat-card">
            <h4>GH₵ <?php echo number_format($yearly_total, 2); ?></h4>
            <small>Target: GH₵ <?php echo number_format($annual_target, 2); ?></small>
            <?php if ($year_debt > 0.01): ?>
                <br><small class="text-danger fw-bold">Year debt: GH₵ <?php echo number_format($year_debt, 2); ?></small>
            <?php else: ?>
                <br><small class="text-success fw-bold">✓ Target cleared</small>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-sm-6 col-md-3 mb-3">
        <div class="stat-card blue">
            <h6>Progress</h6>
            <h4><?php echo number_format($yearly_percentage, 1); ?>%</h4>
            <div class="progress mt-2" style="height: 10px;">
                <div class="progress-bar bg-success" style="width: <?php echo min($yearly_percentage, 100); ?>%"></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3 mb-3">
        <div class="stat-card">
            <h6>Remaining</h6>
            <h4>GH₵ <?php echo number_format($remaining, 2); ?></h4>
            <small>For <?php echo $current_year; ?></small>
        </div>
    </div>
</div>

<!-- Monthly Payment Calendar -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Payment Calendar - <?php echo $current_year; ?></h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php 
                    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                              'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    foreach ($months as $index => $month): 
                        $month_num = $index + 1;
                        $is_paid = in_array($month_num, $paid_months);
                        $is_current = $month_num == $current_month;
                    ?>
                        <div class="col-4 col-sm-3 col-md-2 col-lg-1 mb-2">
                            <div class="text-center p-2 rounded <?php 
                                echo $is_paid ? 'bg-success text-white' : 
                                    ($is_current ? 'bg-warning' : 'bg-light'); ?>">
                                <small><?php echo $month; ?></small>
                                <br>
                                <?php if ($is_paid): ?>
                                    ✅
                                <?php elseif ($is_current): ?>
                                    ⏳
                                <?php else: ?>
                                    ❌
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Transactions -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                <h5 class="mb-0">Recent Transactions</h5>
                <div class="d-flex flex-wrap gap-2">
                    <a href="transactions.php" class="btn btn-sm btn-light">View All</a>
                    <a href="transactions.php?export=csv" class="btn btn-sm btn-success">📊 Export CSV</a>
                </div>
            </div>
            <div class="card-body">
                <div class="table-scroll-wrapper">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Receipt No</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Billing Period</th>
                                <th>Date</th>
                                <th>Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_transactions as $transaction): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($transaction['receipt_no']); ?></td>
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
                                            echo htmlspecialchars($transaction['billing_cycle_year']);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo !empty($transaction['transaction_date']) ? htmlspecialchars(date('M d, Y', strtotime($transaction['transaction_date']))) : 'N/A'; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" 
                                                data-receipt-no="<?php echo htmlspecialchars($transaction['receipt_no']); ?>">
                                            View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recent_transactions)): ?>
                                <tr>
                                    <td colspan="6" class="text-center">No transactions yet</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
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
            viewReceipt(this.dataset.receiptNo);
        });
    });
    
    const printBtn = document.getElementById('printReceiptBtn');
    if (printBtn) {
        printBtn.addEventListener('click', printReceipt);
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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
