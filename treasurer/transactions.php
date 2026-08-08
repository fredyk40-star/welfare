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

// Handle new transaction submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $member_id = sanitizeInput($_POST['member_id']);
        $amount = sanitizeInput($_POST['amount']);
        $payment_method = sanitizeInput($_POST['payment_method']);
        $billing_month = sanitizeInput($_POST['billing_month']);
        $billing_year = sanitizeInput($_POST['billing_year']);
        
        // Validate inputs
        if (empty($member_id) || empty($amount) || empty($payment_method) || empty($billing_month) || empty($billing_year)) {
            $error = 'Please fill in all fields.';
        } elseif (!is_numeric($amount) || $amount <= 0) {
            $error = 'Invalid amount.';
        } else {
            // Check if member exists
            $member_query = "SELECT * FROM members WHERE member_id = :member_id";
            $member_stmt = $db->prepare($member_query);
            $member_stmt->execute([':member_id' => $member_id]);
            $member = $member_stmt->fetch();
            
            if (!$member) {
                $error = 'Member not found.';
            } else {
                // Check annual limit
                $yearly_query = "SELECT COALESCE(SUM(amount), 0) as total FROM transactions 
                               WHERE member_id = :member_id AND billing_cycle_year = :year";
                $yearly_stmt = $db->prepare($yearly_query);
                $yearly_stmt->execute([':member_id' => $member_id, ':year' => $billing_year]);
                $yearly_total = $yearly_stmt->fetch()['total'];
                
                $settings_query = "SELECT annual_amount FROM settings WHERE id = 1";
                $settings = $db->query($settings_query)->fetch();
                $annual_limit = $settings ? $settings['annual_amount'] : 0;
                
                if (($yearly_total + $amount) > $annual_limit) {
                    $error = "Annual limit of GH₵ {$annual_limit} would be exceeded. Current total: GH₵ {$yearly_total}";
                } else {
                    // Generate receipt number
                    $receipt_no = generateReceiptNumber();
                    
                    // Insert transaction
                    $query = "INSERT INTO transactions (receipt_no, member_id, treasurer_id, amount, 
                             payment_method, billing_cycle_month, billing_cycle_year) 
                             VALUES (:receipt_no, :member_id, :treasurer_id, :amount, 
                             :payment_method, :billing_month, :billing_year)";
                    
                    $stmt = $db->prepare($query);
                    
                    try {
                        $stmt->execute([
                            ':receipt_no' => $receipt_no,
                            ':member_id' => $member_id,
                            ':treasurer_id' => $_SESSION['user_id'],
                            ':amount' => $amount,
                            ':payment_method' => $payment_method,
                            ':billing_month' => $billing_month,
                            ':billing_year' => $billing_year
                        ]);
                        
                        logAudit($_SESSION['user_id'], "Recorded payment of GH₵ {$amount} for member {$member_id}");
                        $success = "Payment recorded successfully! Receipt No: {$receipt_no}";
                        
                        // Store receipt for display
                        $_SESSION['last_receipt'] = [
                            'receipt_no' => $receipt_no,
                            'member_name' => $member['full_name'],
                            'member_id' => $member_id,
                            'amount' => $amount,
                            'payment_method' => $payment_method,
                            'billing_period' => date('F Y', mktime(0, 0, 0, $billing_month, 1, $billing_year)),
                            'date' => date('Y-m-d H:i:s')
                        ];
                        
                        // Send receipt email to member
                        $receipt_data = [
                            'receipt_no' => $receipt_no,
                            'member_name' => $member['full_name'],
                            'member_id' => $member_id,
                            'amount' => $amount,
                            'payment_method' => $payment_method,
                            'billing_period' => date('F Y', mktime(0, 0, 0, $billing_month, 1, $billing_year)),
                            'date' => date('Y-m-d H:i:s')
                        ];
                        
                        sendReceiptEmail($member['email'], $receipt_data, $member['passport_photo']);
                        
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'unique_member_billing') !== false) {
                            $error = 'Payment already recorded for this billing cycle.';
                        } else {
                            $error = 'Transaction failed. Please try again.';
                            error_log("Transaction Error: " . $e->getMessage());
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
    $search_term = sanitizeInput($_GET['search']);
    $search_query = "SELECT member_id, full_name, passport_photo, phone 
                    FROM members 
                    WHERE (member_id LIKE :search1 OR full_name LIKE :search2 OR phone LIKE :search3)
                    AND member_id != 'GYF-ADMIN'";
    $search_stmt = $db->prepare($search_query);
    $search_param = "%{$search_term}%";
    $search_stmt->execute([
        ':search1' => $search_param,
        ':search2' => $search_param,
        ':search3' => $search_param
    ]);
    $search_results = $search_stmt->fetchAll();
}

// Get transaction history with filters
$where_clause = "WHERE 1=1";
$params = [];

if (isset($_GET['filter_member']) && !empty($_GET['filter_member'])) {
    $where_clause .= " AND t.member_id = :filter_member";
    $params[':filter_member'] = sanitizeInput($_GET['filter_member']);
}

if (isset($_GET['filter_date']) && !empty($_GET['filter_date'])) {
    $where_clause .= " AND DATE(t.transaction_date) = :filter_date";
    $params[':filter_date'] = sanitizeInput($_GET['filter_date']);
}

if (isset($_GET['filter_method']) && !empty($_GET['filter_method'])) {
    $where_clause .= " AND t.payment_method = :filter_method";
    $params[':filter_method'] = sanitizeInput($_GET['filter_method']);
}

if (isset($_GET['filter_month']) && !empty($_GET['filter_month'])) {
    $where_clause .= " AND t.billing_cycle_month = :filter_month";
    $params[':filter_month'] = (int) date('m');
}

if (isset($_GET['filter_year']) && !empty($_GET['filter_year'])) {
    $where_clause .= " AND t.billing_cycle_year = :filter_year";
    $params[':filter_year'] = (int) date('Y');
}

$transactions_query = "SELECT t.*, m.full_name, m.passport_photo 
                      FROM transactions t 
                      JOIN members m ON t.member_id = m.member_id 
                      {$where_clause} 
                      ORDER BY t.transaction_date DESC 
                      LIMIT 50";

$transactions_stmt = $db->prepare($transactions_query);
$transactions_stmt->execute($params);
$transactions = $transactions_stmt->fetchAll();
?>

<div class="row">
    <div class="col-12">
        <h2 class="mb-4">Transaction Management</h2>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo $success; ?>
        <?php if (isset($_SESSION['last_receipt'])): ?>
            <button class="btn btn-sm btn-success ms-3" onclick="window.print()">
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
            <table class="table table-bordered">
                <tr>
                    <td><strong>Receipt No:</strong></td>
                    <td><?php echo $_SESSION['last_receipt']['receipt_no']; ?></td>
                </tr>
                <tr>
                    <td><strong>Member:</strong></td>
                    <td><?php echo $_SESSION['last_receipt']['member_name']; ?> (<?php echo $_SESSION['last_receipt']['member_id']; ?>)</td>
                </tr>
                <tr>
                    <td><strong>Amount:</strong></td>
                    <td>GH₵ <?php echo number_format($_SESSION['last_receipt']['amount'], 2); ?></td>
                </tr>
                <tr>
                    <td><strong>Payment Method:</strong></td>
                    <td><?php echo $_SESSION['last_receipt']['payment_method']; ?></td>
                </tr>
                <tr>
                    <td><strong>Billing Period:</strong></td>
                    <td><?php echo $_SESSION['last_receipt']['billing_period']; ?></td>
                </tr>
                <tr>
                    <td><strong>Date:</strong></td>
                    <td><?php echo date('F j, Y g:i A', strtotime($_SESSION['last_receipt']['date'])); ?></td>
                </tr>
            </table>
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
        <button class="btn btn-primary btn-lg" type="button" onclick="openPaymentModal()">
            ➕ Record New Payment
        </button>
        <a href="<?php echo APP_URL; ?>/api/transactions.php?action=export_csv" class="btn btn-success btn-lg ms-2">📊 Export to CSV</a>
        <a href="<?php echo APP_URL; ?>/api/transactions.php?action=export_pdf" class="btn btn-danger btn-lg ms-2">📄 Export to PDF</a>
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
                        <button class="btn btn-primary" type="button" onclick="searchMembers()">Search</button>
                    </div>
                    <div id="searchResults" class="mt-2"></div>
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

<!-- Transaction History -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Transaction History</h5>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" action="" class="row mb-3">
                    <div class="col-md-4 mb-2">
                        <input type="text" class="form-control" name="filter_member" 
                               placeholder="Filter by Member ID">
                    </div>
                    <div class="col-md-3 mb-2">
                        <input type="date" class="form-control" name="filter_date">
                    </div>
                    <div class="col-md-3 mb-2">
                        <select class="form-control" name="filter_method">
                            <option value="">All Methods</option>
                            <option value="Cash">Cash</option>
                            <option value="Mobile Money">Mobile Money</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Card">Card</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </form>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Receipt No</th>
                                <th>Member</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Billing Period</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
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
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $transaction['payment_method'] == 'Cash' ? 'success' : 
                                                ($transaction['payment_method'] == 'Mobile Money' ? 'warning' : 
                                                ($transaction['payment_method'] == 'Bank Transfer' ? 'info' : 'primary')); 
                                        ?>">
                                            <?php echo $transaction['payment_method']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($transaction['billing_cycle_month']) {
                                            echo date('M', mktime(0, 0, 0, $transaction['billing_cycle_month'], 1)) . ' ' . $transaction['billing_cycle_year'];
                                        } else {
                                            echo $transaction['billing_cycle_year'];
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($transaction['transaction_date'])); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick="viewReceipt(<?php echo (int)$transaction['id']; ?>)">
                                            👁️ View
                                        </button>
                                        <button class="btn btn-sm btn-success" onclick="printReceipt(<?php echo (int)$transaction['id']; ?>)">
                                            🖨️ Print
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function searchMembers() {
    const searchTerm = document.getElementById('memberSearch').value;
    if (searchTerm.length < 2) {
        alert('Please enter at least 2 characters to search');
        return;
    }

    const escapeHtml = (str) => {
        if (!str && str !== '') return '';
        return String(str).replace(/[&<>'"]/g, tag =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
        );
    };

    fetch(`<?php echo APP_URL; ?>/api/members.php?action=search&term=${encodeURIComponent(searchTerm)}`)
        .then(response => response.json())
        .then(data => {
            let html = '';
            if (data.success && data.members.length > 0) {
                data.members.forEach(member => {
                    const safeName = escapeHtml(member.full_name);
                    const safeId = escapeHtml(member.member_id);
                    const safePhone = escapeHtml(member.phone);
                    const safePhoto = escapeHtml(member.passport_photo);
                    html += `
                        <div class="card mb-2 member-card" style="cursor: pointer;" 
                             data-member-id="${safeId}" data-member-name="${safeName}">
                            <span class="selected-dot"></span>
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    ${safePhoto ? 
                                        `<img src="<?php echo APP_URL; ?>/uploads/photos/${safePhoto}" 
                                              class="member-photo me-3">` : ''}
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
            // Re-mark an already-selected member after a fresh search render
            const curId = document.getElementById('selectedMemberId').value;
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

function selectMember(memberId, memberName) {
    const idEl = document.getElementById('selectedMemberId');
    const nameEl = document.getElementById('selectedMemberName');
    const resultsEl = document.getElementById('searchResults');
    const searchEl = document.getElementById('memberSearch');
    const submitEl = document.getElementById('submitPayment');
    const dotEl = document.getElementById('selectedDot');
    if (!idEl || !nameEl) return;

    idEl.value = memberId;
    nameEl.textContent = memberName;
    nameEl.classList.add('is-selected');
    if (dotEl) dotEl.style.display = 'inline-block';

    // Visual confirmation: green dot on the chosen card, dim the rest
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

    // Member verified -> enable the record button
    if (submitEl) {
        submitEl.disabled = false;
        validateForm();
    }
}

// Enable submit only when member selected AND required fields filled
function validateForm() {
    const submitEl = document.getElementById('submitPayment');
    if (!submitEl) return;
    const memberId = document.getElementById('selectedMemberId').value;
    const amount = document.getElementById('amount').value;
    const method = document.getElementById('payment_method').value;
    const month = document.getElementById('billing_month').value;
    const year = document.getElementById('billing_year').value;
    const ready = memberId && amount && method && month && year;
    submitEl.disabled = !ready;
}

// Live validation as the treasurer fills the form
['amount', 'payment_method', 'billing_month', 'billing_year'].forEach(function (id) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', validateForm);
});

// Submit guard: ensure a member is actually selected before posting
document.getElementById('paymentForm').addEventListener('submit', function (e) {
    const memberId = document.getElementById('selectedMemberId').value;
    if (!memberId) {
        e.preventDefault();
        alert('Please select a member first.');
        return;
    }
    validateForm();
    if (document.getElementById('submitPayment').disabled) {
        e.preventDefault();
        alert('Please fill in all required payment details.');
    }
});

function viewReceipt(transactionId) {
    window.open(`<?php echo APP_URL; ?>/api/transactions.php?action=receipt&id=${transactionId}`, 
                'Receipt', 'width=600,height=400');
}

// Export functions
<?php if (isset($_GET['export'])): ?>
    <?php if ($_GET['export'] == 'csv'): ?>
        // CSV Export logic
        window.location.href = '<?php echo APP_URL; ?>/api/transactions.php?action=export_csv';
    <?php endif; ?>
<?php endif; ?>

// Pre-select member when redirected from members page with ?member_id=...&member_name=...
(function() {
    var params = new URLSearchParams(window.location.search);
    var memberId = params.get('member_id');
    var memberName = params.get('member_name');
    if (memberId && memberName) {
        // Wait for modal to be ready after global openPaymentModal fires
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

// ---- Feature: debounced search-as-you-type ----
let _searchTimer = null;
document.getElementById('memberSearch').addEventListener('input', function () {
    clearTimeout(_searchTimer);
    const v = this.value;
    if (v.length < 2) { document.getElementById('searchResults').innerHTML = ''; return; }
    _searchTimer = setTimeout(searchMembers, 350);
});

// ---- Feature: member annual progress + duplicate-cycle warning ----
function refreshMemberContext(memberId) {
    if (!memberId) {
        document.getElementById('memberProgress').style.display = 'none';
        document.getElementById('dupWarning').style.display = 'none';
        return;
    }
    const month = document.getElementById('billing_month').value;
    const year = document.getElementById('billing_year').value;
    const base = '<?php echo APP_URL; ?>/api/members.php';
    fetch(base + '?action=details&member_id=' + encodeURIComponent(memberId))
        .then(r => r.json())
        .then(d => {
            if (!d.success) return;
            const m = d.member;
            const ytd = parseFloat(m.ytd_paid || 0);
            const target = parseFloat(m.annual_target || 0);
            const pct = target > 0 ? Math.min(100, Math.round((ytd / target) * 100)) : 0;
            document.getElementById('memberProgress').style.display = 'block';
            document.getElementById('memberProgressBar').style.width = pct + '%';
            document.getElementById('memberProgressText').textContent =
                'GH₵ ' + ytd.toFixed(2) + ' of GH₵ ' + target.toFixed(2) + ' (' + pct + '%)';
        })
        .catch(() => {});
    checkDuplicate(memberId, month, year);
}

function checkDuplicate(memberId, month, year) {
    const box = document.getElementById('dupWarning');
    if (!memberId || !month || !year) { box.style.display = 'none'; return; }
    fetch('<?php echo APP_URL; ?>/api/transactions.php?action=check_duplicate&member_id=' +
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

// Re-check duplicate when billing month/year changes
['billing_month', 'billing_year'].forEach(function (id) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', function () {
        checkDuplicate(document.getElementById('selectedMemberId').value,
            document.getElementById('billing_month').value,
            document.getElementById('billing_year').value);
    });
});

// Hook into selectMember: show context after selection
const _origSelect = selectMember;
selectMember = function (memberId, memberName) {
    _origSelect(memberId, memberName);
    refreshMemberContext(memberId);
};

// ---- Feature: quick filter chips ----
(function () {
    const chips = document.querySelectorAll('.quick-filter');
    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            chips.forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            const f = chip.getAttribute('data-filter');
            const cur = new URLSearchParams(window.location.search);
            cur.delete('filter_member'); cur.delete('filter_date'); cur.delete('filter_method');
            if (f === 'month') { cur.set('filter_month', '1'); }
            else if (f === 'year') { cur.set('filter_year', '1'); }
            else if (f !== 'all') { cur.set('filter_method', f); }
            else { cur.delete('filter_month'); cur.delete('filter_year'); }
            window.location.search = cur.toString();
        });
    });

    const toggle = document.getElementById('advanceToggle');
    if (toggle) toggle.addEventListener('click', function () {
        const adv = document.getElementById('advanceFilters');
        adv.style.display = adv.style.display === 'none' ? 'block' : 'none';
    });
    const apply = document.getElementById('applyFilters');
    if (apply) apply.addEventListener('click', function () {
        const cur = new URLSearchParams(window.location.search);
        const m = document.getElementById('filterMember').value;
        const d = document.getElementById('filterDate').value;
        const me = document.getElementById('filterMethod').value;
        if (m) cur.set('filter_member', m); else cur.delete('filter_member');
        if (d) cur.set('filter_date', d); else cur.delete('filter_date');
        if (me) cur.set('filter_method', me); else cur.delete('filter_method');
        cur.delete('filter_month'); cur.delete('filter_year');
        window.location.search = cur.toString();
    });
})();

// ---- Feature: print single receipt from history ----
function printReceipt(transactionId) {
    window.open('<?php echo APP_URL; ?>/api/transactions.php?action=receipt&id=' + transactionId + '&print=1',
        'Receipt', 'width=600,height=400');
}

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
/* Selected member: green highlight + small green dot */
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
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
