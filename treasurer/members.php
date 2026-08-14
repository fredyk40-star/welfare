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

// Get all members with their payment status
$current_month = date('m');
$current_year = date('Y');

$members_query = "SELECT m.*, 
                  COALESCE(SUM(t.amount), 0) as total_paid,
                  (SELECT COALESCE(SUM(t2.amount), 0) 
                   FROM transactions t2 
                   WHERE t2.member_id = m.member_id 
                   AND t2.billing_cycle_year = :year
                   AND t2.status != 'void') as yearly_total,
                  (SELECT COUNT(*) 
                   FROM transactions t3 
                   WHERE t3.member_id = m.member_id 
                   AND t3.billing_cycle_month = :month 
                   AND t3.billing_cycle_year = :year2
                   AND t3.status != 'void') as paid_this_month
                  FROM members m 
                  LEFT JOIN transactions t ON m.member_id = t.member_id AND t.status != 'void'
                   WHERE m.member_id != :treasurer_id 
                  GROUP BY m.id 
                  ORDER BY m.created_at DESC";

$members_stmt = $db->prepare($members_query);
$members_stmt->execute([
    ':year' => $current_year,
    ':month' => $current_month,
    ':year2' => $current_year,
    ':treasurer_id' => TREASURER_MEMBER_ID
]);
$members = $members_stmt->fetchAll();

// Get settings for current calendar year
$current_year = date('Y');
$settings = getYearlyTarget($db, $current_year);
$annual_target = $settings['annual_amount'];
?>

<div class="row">
    <div class="col-12">
        <h2 class="mb-4">Members Management</h2>
    </div>
</div>

<!-- Members List -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">All Members</h5>
                <div class="btn-group">
                    <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#importCsvModal">
                        📥 Import CSV
                    </button>
                    <button class="btn btn-sm btn-light" id="printMembersListBtn">🖨️ Print List</button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="membersTable">
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Member ID</th>
                                <th>Full Name</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Monthly Status</th>
                                <th>Yearly Progress</th>
                                <th>Year Debt</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $member): ?>
                                <tr>
                                    <td>
                                        <?php if ($member['passport_photo']): ?>
                                            <img src="<?php echo displayPhotoUrl($member['passport_photo']); ?>"
                                                 class="member-photo" alt="Photo">
                                        <?php else: ?>
                                            <div class="member-photo bg-secondary d-flex align-items-center justify-content-center text-white">
                                                <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($member['member_id']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($member['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($member['phone']); ?></td>
                                    <td><?php echo htmlspecialchars($member['email']); ?></td>
                                    <td>
                                        <?php echo getMemberStatusBadge($member['status'] ?? 'active'); ?>
                                        <?php if (($member['deletion_count'] ?? 0) > 0): ?>
                                            <br><small class="text-muted">Deletions: <?php echo (int)$member['deletion_count']; ?>/3</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($member['paid_this_month'] > 0): ?>
                                            <span class="badge bg-success">Paid</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                        <?php 
                                        $percentage = $annual_target > 0 ? ($member['yearly_total'] / $annual_target) * 100 : 0;
                                        $bar_color = $percentage >= 100 ? 'success' : ($percentage >= 50 ? 'warning' : 'danger');
                                        ?>
                                            <div class="progress-bar bg-<?php echo $bar_color; ?>" 
                                                 role="progressbar" 
                                                 style="width: <?php echo min($percentage, 100); ?>%">
                                                <?php echo number_format($percentage, 1); ?>%
                                            </div>
                                        </div>
                                        <small>GH₵ <?php echo number_format($member['yearly_total'], 2); ?> / GH₵ <?php echo number_format($annual_target, 2); ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $debt = max(0.0, $annual_target - $member['yearly_total']);
                                        if ($debt > 0.01):
                                        ?>
                                            <span class="text-danger fw-bold">GH₵ <?php echo number_format($debt, 2); ?></span>
                                            <br><small class="text-muted">outstanding</small>
                                        <?php else: ?>
                                            <span class="text-success fw-bold">✓ Cleared</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                         <div class="btn-group">
                                             <a href="/treasurer/member_detail.php?member_id=<?php echo urlencode($member['member_id']); ?>" 
                                                class="btn btn-sm btn-info">
                                                  View
                                             </a>
                                             <button class="btn btn-sm btn-primary" 
                                                     data-member-id="<?php echo htmlspecialchars($member['member_id']); ?>" 
                                                     data-member-name="<?php echo htmlspecialchars($member['full_name']); ?>" 
                                                     data-action="pay">
                                                 Pay
                                             </button>
<a href="/treasurer/member_detail.php?member_id=<?php echo urlencode($member['member_id']); ?>" 
                                                class="btn btn-sm btn-outline-success"
                                                data-member-id="<?php echo htmlspecialchars($member['member_id']); ?>" 
                                                data-member-name="<?php echo htmlspecialchars($member['full_name']); ?>">
                                                 Statement
                                             </a>
                                         </div>
                                         <div class="mt-2 d-flex flex-wrap gap-1 status-actions" data-member-id="<?php echo htmlspecialchars($member['member_id']); ?>">
                                             <?php echo getMemberStatusActions(
                                                 $member['member_id'],
                                                 $member['status'] ?? 'active',
                                                 TREASURER_MEMBER_ID,
                                                 $member['deleted_at'] ?? '',
                                                 (int)($member['deletion_count'] ?? 0)
                                             ); ?>
                                         </div>
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

<!-- Import CSV Modal -->
<div class="modal fade" id="importCsvModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">📥 Import Members from CSV</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="importCsvForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <small>
                            <strong>Required columns:</strong> full_name, email, phone<br>
                            <strong>Optional:</strong> dob (YYYY-MM-DD), gender (Male/Female/Other), address, occupation,
                            emergency_contact_name, emergency_contact_relationship, emergency_contact_phone<br>
                            <strong>Defaults:</strong> dob=2000-01-01, gender=Other, address=N/A, emergency contact uses member's phone.
                        </small>
                    </div>
                    <div class="mb-3">
                        <label for="csv_file" class="form-label">CSV File (max 2MB)</label>
                        <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                    </div>
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <div id="importResult" class="mt-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">📥 Import Members</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Member Details Modal -->
<div class="modal fade" id="memberDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Member Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="memberDetailsContent">
                <!-- Loaded dynamically -->
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo CSP_NONCE; ?>">
function recordPayment(memberId, memberName) {
    window.location.href = `<?php echo APP_URL; ?>/treasurer/transactions.php?action=new&member_id=${memberId}&member_name=${encodeURIComponent(memberName)}`;
}

// Member status management (suspend / deactivate / delete / reactivate)
document.addEventListener('click', function (e) {
    const btn = e.target.closest ? e.target.closest('[data-action="update_status"]') : null;
    if (!btn) return;
    e.preventDefault();
    e.preventDefault();

    const memberId = btn.getAttribute('data-member-id');
    const newStatus = btn.getAttribute('data-status');
    const csrf = btn.getAttribute('data-csrf');
    if (!memberId || !newStatus) return;

    const labels = {
        'suspended': 'suspend',
        'deactivated': 'deactivate',
        'deleted': 'DELETE',
        'active': 'reactivate'
    };
    const verb = labels[newStatus] || newStatus;
    if (!confirm(`Are you sure you want to ${verb} member ${memberId}?`)) {
        return;
    }

    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '...';

    const fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('member_id', memberId);
    fd.append('status', newStatus);

    fetch('<?php echo APP_URL; ?>/api/members.php?action=update_status', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            location.reload();
        } else {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            alert(d.message || 'Action failed.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        alert('Network error. Please try again.');
    });
});

// Print members list
document.addEventListener('DOMContentLoaded', function() {
    var printBtn = document.getElementById('printMembersListBtn');
    if (printBtn) {
        printBtn.addEventListener('click', function() {
            window.print();
        });
    }
});

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
