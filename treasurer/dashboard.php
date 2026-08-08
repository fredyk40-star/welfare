<?php
require_once __DIR__ . '/../includes/header.php';

// Check if user is treasurer
if (!isTreasurer()) {
    redirectTo('/member/login.php');
}

$database = new Database();
$db = $database->getConnection();

// Get current month and year
$current_month = date('m');
$current_year = date('Y');

// Get welfare settings
$settings_query = "SELECT * FROM settings WHERE id = 1";
$settings = $db->query($settings_query)->fetch();
$annual_target = $settings['annual_amount'] ?? 1000;
$monthly_target = $settings['monthly_amount'] ?? 100;

// Get total collected this month
$monthly_query = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions 
                  WHERE billing_cycle_month = :month AND billing_cycle_year = :year";
$monthly_stmt = $db->prepare($monthly_query);
$monthly_stmt->execute([':month' => $current_month, ':year' => $current_year]);
$monthly_total = $monthly_stmt->fetch()['total'];

// Get total collected this year
$yearly_query = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions 
                 WHERE billing_cycle_year = :year";
$yearly_stmt = $db->prepare($yearly_query);
$yearly_stmt->execute([':year' => $current_year]);
$yearly_total = $yearly_stmt->fetch()['total'];

// Get total members
$members_query = "SELECT COUNT(*) as total FROM members WHERE member_id != 'GYF-ADMIN'";
$total_members = $db->query($members_query)->fetch()['total'];

// Get pending members (members who haven't paid this month)
$pending_query = "SELECT COUNT(*) as total FROM members m 
                  WHERE m.member_id != 'GYF-ADMIN' 
                  AND m.member_id NOT IN (
                      SELECT DISTINCT member_id FROM transactions 
                      WHERE billing_cycle_month = :month AND billing_cycle_year = :year
                  )";
$pending_stmt = $db->prepare($pending_query);
$pending_stmt->execute([':month' => $current_month, ':year' => $current_year]);
$pending_members = $pending_stmt->fetch()['total'];

// Get recent transactions
$recent_query = "SELECT t.*, m.full_name, m.passport_photo 
                FROM transactions t 
                JOIN members m ON t.member_id = m.member_id 
                ORDER BY t.transaction_date DESC LIMIT 10";
$recent_transactions = $db->query($recent_query)->fetchAll();
?>

<div class="row">
    <div class="col-12">
        <h2 class="mb-4">Treasurer Dashboard</h2>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="stat-card blue">
            <h5>Monthly Collection</h5>
            <h3>GH₵ <?php echo number_format($monthly_total, 2); ?></h3>
            <small>Current Month</small>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="stat-card">
            <h5>Yearly Collection</h5>
            <h3>GH₵ <?php echo number_format($yearly_total, 2); ?></h3>
            <small>Current Year</small>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="stat-card blue">
            <h5>Total Members</h5>
            <h3><?php echo $total_members; ?></h3>
            <small>Registered Members</small>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="stat-card">
            <h5>Pending Payments</h5>
            <h3><?php echo $pending_members; ?></h3>
            <small>This Month</small>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-2">
                        <button class="btn btn-primary w-100" type="button" onclick="openPaymentModal()">
                            ➕ Record Payment
                        </button>
                    </div>
                    <div class="col-md-4 mb-2">
                        <a href="members.php" class="btn btn-warning w-100">
                            👥 View Members
                        </a>
                    </div>
                    <div class="col-md-4 mb-2">
                        <a href="settings.php" class="btn btn-info w-100 text-white">
                            ⚙️ Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Transactions -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Transactions</h5>
                <a href="transactions.php" class="btn btn-sm btn-light">View All</a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Receipt No</th>
                                <th>Member</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_transactions as $transaction): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($transaction['receipt_no']); ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php if ($transaction['passport_photo']): ?>
                                                <img src="<?php echo APP_URL; ?>/uploads/photos/<?php echo $transaction['passport_photo']; ?>" 
                                                     class="member-photo me-2" alt="Photo">
                                            <?php endif; ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($transaction['full_name']); ?></strong>
                                                <br>
                                                <small><?php echo $transaction['member_id']; ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>GH₵ <?php echo number_format($transaction['amount'], 2); ?></td>
                                    <td><?php echo $transaction['payment_method']; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($transaction['transaction_date'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Payment Modal (mirrors transactions page) -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Record New Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-4">
                    <label class="form-label">Search Member</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="memberSearch"
                               placeholder="Search by name, member ID, or phone...">
                        <button class="btn btn-primary" type="button" onclick="searchMembers()">Search</button>
                    </div>
                    <div id="searchResults" class="mt-2"></div>
                </div>
                <form method="POST" action="<?php echo APP_URL; ?>/treasurer/transactions.php" id="paymentForm">
                    <input type="hidden" name="action" value="record_payment">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="member_id" id="selectedMemberId">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Amount (GH₵) *</label>
                            <input type="number" class="form-control" name="amount" step="0.01" min="0.01" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Payment Method *</label>
                            <select class="form-control" name="payment_method" required>
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
                            <label class="form-label">Billing Month *</label>
                            <select class="form-control" name="billing_month" required>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m == date('m') ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Billing Year *</label>
                            <select class="form-control" name="billing_year" required>
                                <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <strong>Selected Member:</strong> <span id="selectedMemberName">None</span>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Record Payment</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
// Member search for the payment modal (shared with transactions page)
function searchMembers() {
    const term = document.getElementById('memberSearch').value;
    if (term.length < 2) return;
    fetch(`<?php echo APP_URL; ?>/api/members.php?action=search&term=${encodeURIComponent(term)}`)
        .then(r => r.json())
        .then(data => {
            let html = '';
            if (data.success && data.members.length) {
                data.members.forEach(m => {
                    html += `<div class="card mb-2 member-card" style="cursor:pointer" onclick="selectMember('${m.member_id}','${m.full_name}')">
                        <div class="card-body"><div class="d-flex align-items-center">
                        ${m.passport_photo ? `<img src='<?php echo APP_URL; ?>/uploads/photos/${m.passport_photo}' class='member-photo me-2'>` : ''}
                        <div><strong>${m.full_name}</strong><br><small>${m.member_id} | ${m.phone}</small></div></div></div></div>`;
                });
            } else {
                html = '<div class="alert alert-warning">No members found</div>';
            }
            document.getElementById('searchResults').innerHTML = html;
        });
}
function selectMember(id, name) {
    document.getElementById('selectedMemberId').value = id;
    document.getElementById('selectedMemberName').textContent = name;
    document.getElementById('searchResults').innerHTML = '';
    document.getElementById('memberSearch').value = name;
}

let _paymentModalInstance = null;
function openPaymentModal() {
    const el = document.getElementById('paymentModal');
    if (!el) return;
    if (!_paymentModalInstance) {
        _paymentModalInstance = new bootstrap.Modal(el, { backdrop: true, keyboard: true });
    }
    _paymentModalInstance.show();
}

// Auto-open payment modal if action=new is in URL (null-safe)
if (new URLSearchParams(window.location.search).get('action') === 'new') {
    openPaymentModal();
}
</script>