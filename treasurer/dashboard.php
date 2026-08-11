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

$dashboard_error = '';

try {
    // Get welfare settings
    $settings = getWelfareSettings($db);
    $annual_target = $settings['annual_amount'];
    $monthly_target = $settings['monthly_amount'];

    // Get total collected this month
    $monthly_query = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions 
                      WHERE billing_cycle_month = :month AND billing_cycle_year = :year
                      AND status != 'void'";
    $monthly_stmt = $db->prepare($monthly_query);
    $monthly_stmt->execute([':month' => $current_month, ':year' => $current_year]);
    $monthly_total = $monthly_stmt->fetch()['total'];

    // Get total collected this year
    $yearly_query = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions 
                     WHERE billing_cycle_year = :year
                     AND status != 'void'";
    $yearly_stmt = $db->prepare($yearly_query);
    $yearly_stmt->execute([':year' => $current_year]);
    $yearly_total = $yearly_stmt->fetch()['total'];

    // Get total members
    $members_query = "SELECT COUNT(*) as total FROM members WHERE member_id != :treasurer_id";
    $members_stmt = $db->prepare($members_query);
    $members_stmt->execute([':treasurer_id' => TREASURER_MEMBER_ID]);
    $total_members = $members_stmt->fetch()['total'];

    // Get pending members (members who haven't paid this month)
    $pending_query = "SELECT COUNT(*) as total FROM members m 
                      WHERE m.member_id != :treasurer_id 
                      AND m.member_id NOT IN (
                          SELECT DISTINCT member_id FROM transactions 
                          WHERE billing_cycle_month = :month AND billing_cycle_year = :year
                          AND status != 'void'
                      )";
    $pending_stmt = $db->prepare($pending_query);
    $pending_stmt->execute([':month' => $current_month, ':year' => $current_year, ':treasurer_id' => TREASURER_MEMBER_ID]);
    $pending_members = $pending_stmt->fetch()['total'];

    // Get recent transactions
    $recent_query = "SELECT t.id, t.receipt_no, t.member_id, t.amount, t.payment_method, t.billing_cycle_month, t.billing_cycle_year, t.transaction_date, t.status, m.full_name, m.passport_photo 
                    FROM transactions t 
                    JOIN members m ON t.member_id = m.member_id 
                    WHERE t.status != 'void'
                    ORDER BY t.transaction_date DESC LIMIT 10";
    $recent_transactions = $db->query($recent_query)->fetchAll();
} catch (Exception $e) {
    $dashboard_error = 'Unable to load dashboard data. Please contact support.';
    error_log('Dashboard error: ' . $e->getMessage());
    $monthly_total = 0;
    $yearly_total = 0;
    $total_members = 0;
    $pending_members = 0;
    $recent_transactions = [];
}
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

<?php if ($dashboard_error): ?>
    <div class="alert alert-danger"><?php echo $dashboard_error; ?></div>
<?php endif; ?>

<!-- Recent Transactions -->
<div class="row mb-4">
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
                                    <td><?php echo htmlspecialchars($transaction['payment_method']); ?></td>
                                    <td><?php echo !empty($transaction['transaction_date']) ? htmlspecialchars(date('M d, Y', strtotime($transaction['transaction_date']))) : 'N/A'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions (moved below Recent Transactions so nothing blocks the navbar / search) -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-2">
                        <button class="btn btn-primary w-100" type="button" id="openPaymentModalBtn">
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
                        <button class="btn btn-primary" type="button" id="searchMembersBtn">Search</button>
                    </div>
                    <div id="searchResults" class="mt-2"></div>
                </div>
                <form method="POST" action="<?php echo APP_URL; ?>/treasurer/transactions.php" id="paymentForm">
                    <input type="hidden" name="action" value="record_payment">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <input type="hidden" name="member_id" id="selectedMemberId">
                    <input type="hidden" name="transaction_date" value="<?php echo date('Y-m-d'); ?>">
                    <input type="hidden" name="transaction_time" value="<?php echo date('H:i'); ?>">
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

<script nonce="<?php echo CSP_NONCE; ?>">
// Global function to open the payment modal safely and prevent stuck backdrops
function openPaymentModal() {
    const modalEl = document.getElementById('paymentModal');
    if (!modalEl) return;
    
    // Force-remove any lingering backdrops from previous interrupted actions
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
    
    // Initialize and display the Bootstrap modal
    const paymentModal = new bootstrap.Modal(modalEl);
    paymentModal.show();
}

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
                    const safeName = m.full_name.replace(/[&<>'"]/g, tag => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[tag] || tag));
                    const safeId = String(m.member_id).replace(/[&<>'"]/g, tag => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[tag] || tag));
                    const safePhone = String(m.phone).replace(/[&<>'"]/g, tag => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[tag] || tag));
                    const safePhoto = m.passport_photo ? String(m.passport_photo).replace(/[&<>'"]/g, tag => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[tag] || tag)) : '';
                    const photoUrl = (p) => { if (!p) return ''; p = String(p); return p.indexOf('http') === 0 ? p : '<?php echo APP_URL; ?>/uploads/photos/' + p; };
                    html += `<div class="card mb-2 member-card" style="cursor:pointer" data-member-id="${safeId}" data-member-name="${safeName}">
                        <div class="card-body"><div class="d-flex align-items-center">
                        ${safePhoto ? `<img src='${photoUrl(safePhoto)}' class='member-photo me-2'>` : ''}
                        <div><strong>${safeName}</strong><br><small>${safeId} | ${safePhone}</small></div></div></div></div>`;
                });
            } else {
                html = '<div class="alert alert-warning">No members found</div>';
            }
            document.getElementById('searchResults').innerHTML = html;
        });
}

function selectMember(id, name) {
    const idEl = document.getElementById('selectedMemberId');
    const nameEl = document.getElementById('selectedMemberName');
    const resultsEl = document.getElementById('searchResults');
    const searchEl = document.getElementById('memberSearch');
    if (!idEl || !nameEl) return;
    idEl.value = id;
    nameEl.textContent = name;
    if (resultsEl) resultsEl.innerHTML = '';
    if (searchEl) searchEl.value = name;
}

document.addEventListener('click', function (e) {
    const card = e.target.closest ? e.target.closest('.member-card[data-member-id]') : null;
    if (!card) return;
    selectMember(card.getAttribute('data-member-id'), card.getAttribute('data-member-name'));
});

// Auto-open payment modal if action=new is in URL
if (new URLSearchParams(window.location.search).get('action') === 'new') {
    openPaymentModal();
}

// Global failsafe listener to clean up body scroll locks if a modal closes abnormally
document.addEventListener('DOMContentLoaded', function () {
    const paymentModalEl = document.getElementById('paymentModal');
    if (paymentModalEl) {
        paymentModalEl.addEventListener('hidden.bs.modal', function () {
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
    }
});

document.addEventListener('DOMContentLoaded', function() {
    var paymentBtn = document.getElementById('openPaymentModalBtn');
    if (paymentBtn) { paymentBtn.addEventListener('click', openPaymentModal); }
    
    var searchBtn = document.getElementById('searchMembersBtn');
    if (searchBtn) { searchBtn.addEventListener('click', searchMembers); }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
