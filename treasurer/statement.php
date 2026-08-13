<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

// Check if user is logged in (treasurer or the member themselves)
if (!isLoggedIn()) {
    redirectTo('/member/login.php');
}

require_once __DIR__ . '/../includes/header.php';

$database = new Database();
$db = $database->getConnection();

$member_id = cleanInput($_GET['member_id'] ?? '');

// Authorization: treasurer can view any, member can only view own
if (!isTreasurer() && $_SESSION['user_id'] !== $member_id) {
    redirectTo('/member/dashboard.php');
}

$member = null;
$transactions = [];
$settings = [];
$ytd_paid = 0;
$annual_target = 0;

try {
    // Get member details
    $stmt = $db->prepare("SELECT * FROM members WHERE member_id = :mid");
    $stmt->execute([':mid' => $member_id]);
    $member = $stmt->fetch();
    
    if (!$member) {
        throw new Exception('Member not found');
    }

    // Get settings
    $settings = getWelfareSettings($db);
    $annual_target = $settings['annual_amount'];

    // Get all transactions for this member
    $stmt = $db->prepare("SELECT * FROM transactions WHERE member_id = :mid AND status != 'void' ORDER BY transaction_date DESC");
    $stmt->execute([':mid' => $member_id]);
    $transactions = $stmt->fetchAll();

    // Year-to-date total
    $ytd = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE member_id = :mid AND billing_cycle_year = :yr AND status != 'void'");
    $ytd->execute([':mid' => $member_id, ':yr' => date('Y')]);
    $ytd_paid = (float) $ytd->fetch()['total'];
} catch (Exception $e) {
    $member = null;
    error_log('Statement error: ' . $e->getMessage());
}
?>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Contribution Statement</h2>
            <button class="btn btn-primary" onclick="window.print()">🖨️ Print / Save as PDF</button>
        </div>
    </div>
</div>

<?php if (!$member): ?>
    <div class="alert alert-danger">Member not found or access denied.</div>
<?php else: ?>
    <div class="row mb-4">
        <div class="col-md-4 text-center">
            <?php if ($member['passport_photo']): ?>
                <img src="<?php echo displayPhotoUrl($member['passport_photo']); ?>"
                     class="img-fluid rounded mb-3" style="max-width: 200px;" alt="Photo">
            <?php else: ?>
                <div class="bg-secondary text-white rounded p-5 mb-3">
                    <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                </div>
            <?php endif; ?>
            <h4><?php echo htmlspecialchars($member['full_name']); ?></h4>
            <p class="text-muted"><?php echo htmlspecialchars($member['member_id']); ?></p>
        </div>
        <div class="col-md-8">
            <table class="table table-borderless">
                <tr><td><strong>Email:</strong></td><td><?php echo htmlspecialchars($member['email']); ?></td></tr>
                <tr><td><strong>Phone:</strong></td><td><?php echo htmlspecialchars($member['phone']); ?></td></tr>
                <tr><td><strong>Member Since:</strong></td><td><?php echo date('F j, Y', strtotime($member['created_at'])); ?></td></tr>
            </table>
        </div>
    </div>

    <!-- Annual Progress -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Annual Contribution Progress (<?php echo date('Y'); ?>)</h5>
        </div>
        <div class="card-body">
            <?php 
            $pct = $annual_target > 0 ? min(100, round(($ytd_paid / $annual_target) * 100)) : 0;
            $bar_color = $pct >= 100 ? 'success' : ($pct >= 50 ? 'warning' : 'danger');
            ?>
            <div class="progress" style="height: 30px;">
                <div class="progress-bar bg-<?php echo $bar_color; ?>" role="progressbar" 
                     style="width: <?php echo $pct; ?>%"><?php echo $pct; ?>%</div>
            </div>
            <div class="mt-2 text-center">
                <strong>GH₵ <?php echo number_format($ytd_paid, 2); ?></strong> of <strong>GH₵ <?php echo number_format($annual_target, 2); ?></strong>
            </div>
        </div>
    </div>

    <!-- Transaction History -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Transaction History</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Receipt No</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Billing Period</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No transactions found</td></tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $tx): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($tx['receipt_no']); ?></td>
                                    <td class="text-success fw-bold">GH₵ <?php echo number_format($tx['amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($tx['payment_method']); ?></td>
                                    <td>
                                        <?php 
                                        if ($tx['billing_cycle_month']) {
                                            echo date('F Y', mktime(0, 0, 0, $tx['billing_cycle_month'], 1, $tx['billing_cycle_year']));
                                        } else {
                                            echo htmlspecialchars($tx['billing_cycle_year']);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($tx['transaction_date'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $tx['status'] === 'void' ? 'danger' : 'success'; ?>">
                                            <?php echo ucfirst($tx['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Summary -->
    <div class="card mt-4">
        <div class="card-body text-center">
            <h5>Summary</h5>
            <p class="mb-1"><strong>Total Transactions:</strong> <?php echo count($transactions); ?></p>
            <p class="mb-1"><strong>Total Paid (<?php echo date('Y'); ?>):</strong> GH₵ <?php echo number_format($ytd_paid, 2); ?></p>
            <p class="mb-0"><strong>Annual Target:</strong> GH₵ <?php echo number_format($annual_target, 2); ?></p>
        </div>
    </div>

    <div class="text-center mt-4 text-muted small">
        Generated on <?php echo date('F j, Y g:i A'); ?> | <?php echo APP_NAME; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>