<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if user is treasurer
if (!isTreasurer()) {
    redirectTo('/member/login.php');
}

// Handle AJAX search request
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['search'])) {
    $database = new Database();
    $db = $database->getConnection();
    
    $search_term = cleanInput($_GET['search']);
    $escaped_search = str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $search_term); $search_param = "%{$escaped_search}%";
    $phone_where = "phone LIKE :search3";
    $phone_params = [];
    $variants = getPhoneSearchVariants($search_term);
    if ($variants) {
        $clauses = [];
        foreach ($variants as $i => $v) {
            $key = ":phone_d{$i}";
            $clauses[] = "REPLACE(REPLACE(REPLACE(phone,' ',''),'+',''),'-','') LIKE {$key}";
            $phone_params[$key] = "%{$v}%";
        }
        $phone_where = "(" . implode(' OR ', $clauses) . ")";
    }
    $search_query = "SELECT member_id, full_name, passport_photo, phone, email 
                    FROM members 
                    WHERE (member_id LIKE :search1 OR full_name LIKE :search2 OR {$phone_where})
                    AND member_id != :treasurer_id
                    ORDER BY full_name ASC
                    LIMIT 20";
    $search_stmt = $db->prepare($search_query);
    $search_stmt->execute(array_merge([
        ':search1' => $search_param,
        ':search2' => $search_param,
        ':search3' => $search_param,
        ':treasurer_id' => TREASURER_MEMBER_ID
    ], $phone_params));
    $search_results = $search_stmt->fetchAll();
    
    if (empty($search_results)) {
        echo '<div class="text-center py-4 text-muted">No members found matching "' . htmlspecialchars($search_term) . '"</div>';
    } else {
        echo '<div class="row g-3">';
        foreach ($search_results as $member) {
            $photo_html = '';
            if ($member['passport_photo']) {
                $photo_url = displayPhotoUrl($member['passport_photo']);
                $photo_html = '<img src="' . htmlspecialchars($photo_url) . '" class="member-photo me-3" alt="Photo" style="width: 50px; height: 50px; object-fit: cover;">';
            } else {
                $photo_html = '<div class="member-photo bg-secondary d-flex align-items-center justify-content-center text-white me-3" style="width: 50px; height: 50px; font-size: 1.2rem;">' . strtoupper(substr($member['full_name'], 0, 1)) . '</div>';
            }
            echo '<div class="col-12 col-sm-6 col-md-4">';
            echo '<a href="' . APP_URL . '/treasurer/member_detail.php?member_id=' . urlencode($member['member_id']) . '" class="member-result-card card h-100 text-decoration-none" style="transition: transform 0.2s, box-shadow 0.2s;">';
            echo '<div class="card-body d-flex align-items-center p-3">';
            echo $photo_html;
            echo '<div>';
            echo '<h6 class="mb-1 text-dark">' . htmlspecialchars($member['full_name']) . '</h6>';
            echo '<small class="text-muted d-block">' . htmlspecialchars($member['member_id']) . '</small>';
            echo '<small class="text-muted d-block">' . htmlspecialchars($member['phone']) . '</small>';
            if (!empty($member['email'])) {
                echo '<small class="text-muted d-block">' . htmlspecialchars($member['email']) . '</small>';
            }
            echo '</div>';
            echo '</div>';
            echo '</a>';
            echo '</div>';
        }
        echo '</div>';
    }
    exit();
}

require_once __DIR__ . '/../includes/header.php';

$database = new Database();
$db = $database->getConnection();

// Get current month and year
$current_month = date('m');
$current_year = date('Y');

$dashboard_error = '';

try {
    // Get welfare settings for current calendar year
    $current_year = date('Y');
    $settings = getYearlyTarget($db, $current_year);
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

    // Year debt (shortfall against annual target)
    $year_debt = max(0.0, $annual_target - $yearly_total);

    // Get total members
    $members_query = "SELECT COUNT(*) as total FROM members WHERE member_id != :treasurer_id";
    $members_stmt = $db->prepare($members_query);
    $members_stmt->execute([':treasurer_id' => TREASURER_MEMBER_ID]);
    $total_members = $members_stmt->fetch()['total'];

    // Search members
    $search_results = [];
    $search_term = '';
    if (isset($_GET['search'])) {
        $search_term = cleanInput($_GET['search']);
        $escaped_search = str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $search_term); $search_param = "%{$escaped_search}%";
        $phone_where = "phone LIKE :search3";
        $phone_params = [];
        $variants = getPhoneSearchVariants($search_term);
        if ($variants) {
            $clauses = [];
            foreach ($variants as $i => $v) {
                $key = ":phone_p{$i}";
                $clauses[] = "REPLACE(REPLACE(REPLACE(phone,' ',''),'+',''),'-','') LIKE {$key}";
                $phone_params[$key] = "%{$v}%";
            }
            $phone_where = "(" . implode(' OR ', $clauses) . ")";
        }
        $search_query = "SELECT member_id, full_name, passport_photo, phone, email 
                        FROM members 
                        WHERE (member_id LIKE :search1 OR full_name LIKE :search2 OR {$phone_where})
                        AND member_id != :treasurer_id
                        ORDER BY full_name ASC
                        LIMIT 20";
        $search_stmt = $db->prepare($search_query);
        $search_stmt->execute(array_merge([
            ':search1' => $search_param,
            ':search2' => $search_param,
            ':search3' => $search_param,
            ':treasurer_id' => TREASURER_MEMBER_ID
        ], $phone_params));
        $search_results = $search_stmt->fetchAll();
    }

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

    // Monthly collection for the chart (12 months of current year)
    $monthly_collection = [];
    for ($m = 1; $m <= 12; $m++) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions 
                              WHERE billing_cycle_month = :m AND billing_cycle_year = :y AND status != 'void'");
        $stmt->execute([':m' => $m, ':y' => $current_year]);
        $monthly_collection[$m] = (float) $stmt->fetch()['total'];
    }

    // Defaulters for current cycle
    $defaulters_stmt = $db->prepare("
        SELECT m.member_id, m.full_name, m.email, m.phone
        FROM members m
        WHERE m.member_id != :treasurer_id
          AND m.member_id NOT IN (
              SELECT DISTINCT member_id FROM transactions
              WHERE billing_cycle_month = :m AND billing_cycle_year = :y AND status != 'void'
          )
        ORDER BY m.full_name ASC
    ");
    $defaulters_stmt->execute([':treasurer_id' => TREASURER_MEMBER_ID, ':m' => $current_month, ':y' => $current_year]);
    $defaulters = $defaulters_stmt->fetchAll();

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
    $year_debt = 0;
    $total_members = 0;
    $pending_members = 0;
    $monthly_collection = [];
    $defaulters = [];
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
            <h5>Year Debt</h5>
            <h3 class="<?php echo $year_debt > 0 ? 'text-danger' : 'text-success'; ?>">
                GH₵ <?php echo number_format($year_debt, 2); ?>
            </h3>
            <small><?php echo $year_debt > 0 ? 'Outstanding' : 'Cleared'; ?></small>
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

<!-- Member Search -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">���� Search Member</h5>
            </div>
            <div class="card-body">
                <form id="memberSearchForm" class="row g-3">
                    <div class="col-md-8">
                        <label for="searchInput" class="form-label visually-hidden">Search member by ID, phone, or name</label>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-lg" id="searchInput" name="search" 
                                   placeholder="Search by Member ID, Phone Number, or Name..." autocomplete="off">
                            <button class="btn btn-primary" type="submit">
                                <i class="bi bi-search"></i> Search
                            </button>
                        </div>
                        <small class="text-muted">Search by Member ID (e.g., GYF-123456), Phone Number, or Full Name</small>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-outline-secondary w-100" id="clearSearchBtn">
                            <i class="bi bi-x-circle"></i> Clear
                        </button>
                    </div>
                </form>
                <div id="searchResults" class="mt-3"></div>
            </div>
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
                <div class="table-scroll-wrapper">
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

<!-- Defaulters & Arrears Panel + Monthly Collection Chart -->
<div class="row mb-4">
    <div class="col-lg-7 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Members Pending Payment (<?php echo date('F Y'); ?>)</h5>
                <span class="badge bg-<?php echo $pending_members > 0 ? 'danger' : 'success'; ?>">
                    <?php echo count($defaulters); ?> pending
                </span>
            </div>
            <div class="card-body">
                <div id="defaultersLoading" class="text-center py-3 d-none">
                    <div class="spinner-border text-primary"></div>
                </div>
                <div id="defaultersEmpty" class="alert alert-success d-none">
                    All members have paid for this cycle! 🎉
                </div>
                <div class="table-scroll-wrapper" id="defaultersTableWrap">
                    <table class="table table-hover" id="defaultersTable">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Contact</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="defaultersBody">
                            <?php if (empty($defaulters)): ?>
                                <tr id="emptyRow"><td colspan="3" class="text-center text-success fw-bold py-4">All members have paid!</td></tr>
                            <?php else: ?>
                                <?php foreach ($defaulters as $d): ?>
                                    <tr data-member-id="<?php echo htmlspecialchars($d['member_id']); ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($d['full_name']); ?></strong>
                                            <br><small><?php echo htmlspecialchars($d['member_id']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($d['phone']); ?>
                                            <br><small><?php echo htmlspecialchars($d['email']); ?></small>
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-primary sendReminderBtn" 
                                                    data-member-id="<?php echo htmlspecialchars($d['member_id']); ?>"
                                                    data-member-name="<?php echo htmlspecialchars($d['full_name']); ?>"
                                                    data-member-email="<?php echo htmlspecialchars($d['email']); ?>">
                                                📧 Remind
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-2">
                    <button class="btn btn-outline-danger w-100" id="sendAllRemindersBtn" <?php echo empty($defaulters) ? 'disabled' : ''; ?>>
                        📧 Send Reminders to All (<?php echo count($defaulters); ?>)
                    </button>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Monthly Collection Trend (<?php echo date('Y'); ?>)</h5>
            </div>
            <div class="card-body">
                <div class="chart-container" style="height: 250px;">
                    <div class="d-flex align-items-end justify-content-between chart-bars" style="height: 200px;">
                        <?php
                        $max_val = max($monthly_collection);
                        if (!$max_val) $max_val = 1;
                        $months_short = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                        for ($m = 1; $m <= 12; $m++):
                            $val = $monthly_collection[$m] ?? 0;
                            $pct = ($val / $max_val) * 100;
                            $color = $m == (int)date('m') ? 'bg-primary' : 'bg-secondary';
                        ?>
                            <div class="chart-bar-wrapper" style="flex:1; display:flex; flex-direction:column; align-items:center; gap:4px;">
                                <div class="chart-bar <?php echo $color; ?>" style="width:100%; height:<?php echo max(4, $pct); ?>%; min-height:4px; border-radius:4px 4px 0 0; transition:height .3s;"></div>
                                <small class="text-muted"><?php echo $months_short[$m-1]; ?></small>
                                <small class="fw-bold text-dark">GH₵ <?php echo number_format($monthly_collection[$m], 0); ?></small>
                            </div>
                        <?php endfor; ?>
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

    // Defaulters: send individual reminder
    document.querySelectorAll('.sendReminderBtn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const memberId = this.dataset.memberId;
            const memberName = this.dataset.memberName;
            const memberEmail = this.dataset.memberEmail;
            const originalText = this.textContent;
            this.disabled = true;
            this.textContent = 'Sending...';
            
            const formData = new FormData();
            formData.append('csrf_token', document.querySelector('[name="csrf_token"]').value);
            formData.append('member_id', memberId);
            formData.append('month', '<?php echo date("m"); ?>');
            formData.append('year', '<?php echo date("Y"); ?>');
            
            fetch('<?php echo APP_URL; ?>/api/members.php?action=send_reminder', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(d => {
                this.disabled = false;
                this.textContent = originalText;
                if (d.success) {
                    this.textContent = '✓ Sent';
                    this.classList.remove('btn-outline-primary');
                    this.classList.add('btn-success');
                    setTimeout(() => {
                        this.textContent = originalText;
                        this.classList.remove('btn-success');
                        this.classList.add('btn-outline-primary');
                    }, 3000);
                } else {
                    alert('Failed: ' + (d.message || 'Could not send reminder'));
                }
            })
            .catch(() => {
                this.disabled = false;
                this.textContent = originalText;
                alert('Network error sending reminder');
            });
        });
    });

    // Send all reminders
    const sendAllBtn = document.getElementById('sendAllRemindersBtn');
    if (sendAllBtn) {
        sendAllBtn.addEventListener('click', function() {
            const originalText = this.textContent;
            this.disabled = true;
            this.textContent = 'Sending...';
            
            const formData = new FormData();
            formData.append('csrf_token', document.querySelector('[name="csrf_token"]').value);
            formData.append('month', '<?php echo date("m"); ?>');
            formData.append('year', '<?php echo date("Y"); ?>');
            
            fetch('<?php echo APP_URL; ?>/api/members.php?action=send_reminder_all', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(d => {
                this.disabled = false;
                this.textContent = originalText;
                if (d.success) {
                    alert('Reminders sent: ' + d.sent + ', failed: ' + d.failed);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    alert('Failed: ' + (d.message || 'Could not send reminders'));
                }
            })
            .catch(() => {
                this.disabled = false;
                this.textContent = originalText;
                alert('Network error');
            });
        });
    }
});

// Member Search functionality
let searchDebounceTimer = null;
const searchInput = document.getElementById('searchInput');
const searchResults = document.getElementById('searchResults');
const searchForm = document.getElementById('memberSearchForm');
const clearBtn = document.getElementById('clearSearchBtn');

if (searchInput && searchResults) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchDebounceTimer);
        const query = this.value.trim();
        
        if (query.length < 2) {
            searchResults.innerHTML = '';
            return;
        }
        
        searchDebounceTimer = setTimeout(() => {
            searchResults.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div><span class="ms-2">Searching...</span></div>';
            
            fetch(`<?php echo APP_URL; ?>/treasurer/dashboard.php?search=${encodeURIComponent(query)}&ajax=1`)
                .then(response => response.text())
                .then(html => {
                    searchResults.innerHTML = html;
                })
                .catch(() => {
                    searchResults.innerHTML = '<div class="alert alert-danger">Search failed. Please try again.</div>';
                });
        }, 300);
    });
    
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            searchInput.value = '';
            searchResults.innerHTML = '';
            searchInput.focus();
        });
    }
    
    // Handle form submission (Enter key)
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const query = searchInput.value.trim();
            if (query.length >= 2) {
                window.location.href = `<?php echo APP_URL; ?>/treasurer/dashboard.php?search=${encodeURIComponent(query)}`;
            }
        });
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


