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

// Get settings for annual target
$settings = getWelfareSettings($db);
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
                                <th>Monthly Status</th>
                                <th>Yearly Progress</th>
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
                                        <div class="btn-group">
                                            <button class="btn btn-sm btn-info" 
                                                    data-member-id="<?php echo htmlspecialchars($member['member_id']); ?>" 
                                                    data-member-name="<?php echo htmlspecialchars($member['full_name']); ?>" 
                                                    data-action="view">
                                                View
                                            </button>
                                            <button class="btn btn-sm btn-primary" 
                                                    data-member-id="<?php echo htmlspecialchars($member['member_id']); ?>" 
                                                    data-member-name="<?php echo htmlspecialchars($member['full_name']); ?>" 
                                                    data-action="pay">
                                                Pay
                                            </button>
                                            <a href="statement.php?member_id=<?php echo htmlspecialchars($member['member_id']); ?>" 
                                               class="btn btn-sm btn-outline-success" target="_blank">
                                                Statement
                                            </a>
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
function viewMemberDetails(memberId) {
    const modalEl = document.getElementById('memberDetailsModal');
    const contentEl = document.getElementById('memberDetailsContent');
    
    // Clean up any stuck backdrops BEFORE opening new modal
    document.querySelectorAll('.modal-backdrop').forEach(function(b) { b.remove(); });
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
    
    contentEl.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';
    
    if (!window._memberDetailsModalInstance) {
        window._memberDetailsModalInstance = new bootstrap.Modal(modalEl);
    }
    window._memberDetailsModalInstance.show();
    
    fetch(`<?php echo APP_URL; ?>/api/members.php?action=details&member_id=${memberId}`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                contentEl.innerHTML = '<div class="alert alert-danger">Failed to load member details.</div>';
                return;
            }
            const member = data.member;
            const escapeHtml = (str) => {
                if (!str && str !== '') return '';
                return String(str).replace(/[&<>'"]/g, tag =>
                    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
                );
            };
            const safePhoto = member.passport_photo ? escapeHtml(String(member.passport_photo).replace(/^.*[\\\/]/, '')) : '';
            const photoUrl = (p) => { if (!p) return ''; p = String(p); return p.indexOf('http') === 0 ? p : '<?php echo APP_URL; ?>/uploads/photos/' + p; };

            let html = `
                <div class="row">
                    <div class="col-md-4 text-center">
                        ${safePhoto ?
                            `<img src="${photoUrl(safePhoto)}"
                                  class="img-fluid rounded mb-3" style="max-width: 200px;">` :
                            '<div class="bg-secondary text-white rounded p-5 mb-3">No Photo</div>'}
                        <h5>${escapeHtml(member.full_name)}</h5>
                        <p class="text-muted">${escapeHtml(member.member_id)}</p>
                    </div>
                    <div class="col-md-8">
                        <table class="table">
                            <tr><td><strong>Email:</strong></td><td>${escapeHtml(member.email)}</td></tr>
                            <tr><td><strong>Phone:</strong></td><td>${escapeHtml(member.phone)}</td></tr>
                            <tr><td><strong>Date of Birth:</strong></td><td>${escapeHtml(member.dob)}</td></tr>
                            <tr><td><strong>Gender:</strong></td><td>${escapeHtml(member.gender)}</td></tr>
                            <tr><td><strong>Address:</strong></td><td>${escapeHtml(member.address)}</td></tr>
                            <tr><td><strong>Occupation:</strong></td><td>${escapeHtml(member.occupation || 'N/A')}</td></tr>
                            <tr><td><strong>Emergency Contact:</strong></td><td>${escapeHtml(member.emergency_contact_name)} (${escapeHtml(member.emergency_contact_relationship)}) - ${escapeHtml(member.emergency_contact_phone)}</td></tr>
                            <tr><td><strong>Registered:</strong></td><td>${new Date(member.created_at).toLocaleDateString()}</td></tr>
                        </table>
                    </div>
                </div>
            `;
            contentEl.innerHTML = html;
        })
        .catch(() => {
            contentEl.innerHTML = '<div class="alert alert-danger">Failed to load member details.</div>';
        });
}

function recordPayment(memberId, memberName) {
    // Redirect to transactions page where the payment modal actually exists
    window.location.href = `<?php echo APP_URL; ?>/treasurer/transactions.php?action=new&member_id=${memberId}&member_name=${encodeURIComponent(memberName)}`;
}

document.addEventListener('DOMContentLoaded', function() {
    var printBtn = document.getElementById('printMembersListBtn');
    if (printBtn) { printBtn.addEventListener('click', window.print); }
    
    document.querySelectorAll('[data-action="view"]').forEach(function(btn) {
        btn.addEventListener('click', function() { viewMemberDetails(this.dataset.memberId); });
    });
    document.querySelectorAll('[data-action="pay"]').forEach(function(btn) {
        btn.addEventListener('click', function() { recordPayment(this.dataset.memberId, this.dataset.memberName); });
    });

    // Import CSV form handler
    const importForm = document.getElementById('importCsvForm');
    if (importForm) {
        importForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            const resultDiv = document.getElementById('importResult');
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Importing...';
            resultDiv.innerHTML = '<div class="spinner-border spinner-border-sm text-primary me-2"></div>Importing...';
            
            const formData = new FormData(this);
            fetch('<?php echo APP_URL; ?>/api/members.php?action=import_csv', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(d => {
                btn.disabled = false;
                btn.textContent = '📥 Import Members';
                if (d.success) {
                    let html = '<div class="alert alert-success"><strong>Imported: ' + d.imported + '</strong>, Skipped: ' + d.skipped;
                    if (d.errors && d.errors.length) {
                        html += '<ul class="mb-0 mt-2 small">';
                        d.errors.forEach(e => { html += '<li>' + e + '</li>'; });
                        html += '</ul>';
                    }
                    if (d.generated && Object.keys(d.generated).length) {
                        html += '<hr class="my-2"><strong>Generated passwords (share with members):</strong><ul class="mb-0 mt-2 small">';
                        for (const [mid, pwd] of Object.entries(d.generated)) {
                            html += '<li><code>' + mid + '</code>: <code>' + pwd + '</code></li>';
                        }
                        html += '</ul>';
                    }
                    html += '</div>';
                    resultDiv.innerHTML = html;
                    if (d.imported > 0) {
                        setTimeout(() => location.reload(), 2000);
                    }
                } else {
                    resultDiv.innerHTML = '<div class="alert alert-danger">Failed: ' + (d.message || 'Import failed') + '</div>';
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.textContent = '📥 Import Members';
                resultDiv.innerHTML = '<div class="alert alert-danger">Network error</div>';
            });
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
