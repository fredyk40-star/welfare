<?php
require_once __DIR__ . '/../includes/header.php';

// Check if user is treasurer
if (!isTreasurer()) {
    redirectTo('/member/login.php');
}

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
                   AND t2.billing_cycle_year = :year) as yearly_total,
                  (SELECT COUNT(*) 
                   FROM transactions t3 
                   WHERE t3.member_id = m.member_id 
                   AND t3.billing_cycle_month = :month 
                   AND t3.billing_cycle_year = :year2) as paid_this_month
                  FROM members m 
                  LEFT JOIN transactions t ON m.member_id = t.member_id 
                  WHERE m.member_id != 'GYF-ADMIN' 
                  GROUP BY m.id 
                  ORDER BY m.created_at DESC";

$members_stmt = $db->prepare($members_query);
$members_stmt->execute([
    ':year' => $current_year,
    ':month' => $current_month,
    ':year2' => $current_year
]);
$members = $members_stmt->fetchAll();

// Get settings for annual target
$settings_query = "SELECT annual_amount FROM settings ORDER BY id DESC LIMIT 1";
$settings = $db->query($settings_query)->fetch();
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
                <button class="btn btn-sm btn-light" onclick="window.print()">🖨️ Print List</button>
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
                                            <img src="<?php echo APP_URL; ?>/uploads/photos/<?php echo $member['passport_photo']; ?>" 
                                                 class="member-photo" alt="Photo">
                                        <?php else: ?>
                                            <div class="member-photo bg-secondary d-flex align-items-center justify-content-center text-white">
                                                <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo $member['member_id']; ?></strong></td>
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
                                            $percentage = ($member['yearly_total'] / $annual_target) * 100;
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
                                                    onclick="viewMemberDetails('<?php echo $member['member_id']; ?>')">
                                                👁️ View
                                            </button>
                                            <button class="btn btn-sm btn-primary" 
                                                    onclick="recordPayment('<?php echo $member['member_id']; ?>', '<?php echo htmlspecialchars($member['full_name']); ?>')">
                                                💰 Pay
                                            </button>
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

<script>
function viewMemberDetails(memberId) {
    fetch(`<?php echo APP_URL; ?>/api/members.php?action=details&member_id=${memberId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const member = data.member;
                let html = `
                    <div class="row">
                        <div class="col-md-4 text-center">
                            ${member.passport_photo ? 
                                `<img src="<?php echo APP_URL; ?>/uploads/photos/${member.passport_photo}" 
                                      class="img-fluid rounded mb-3" style="max-width: 200px;">` : 
                                '<div class="bg-secondary text-white rounded p-5 mb-3">No Photo</div>'}
                            <h5>${member.full_name}</h5>
                            <p class="text-muted">${member.member_id}</p>
                        </div>
                        <div class="col-md-8">
                            <table class="table">
                                <tr><td><strong>Email:</strong></td><td>${member.email}</td></tr>
                                <tr><td><strong>Phone:</strong></td><td>${member.phone}</td></tr>
                                <tr><td><strong>Date of Birth:</strong></td><td>${member.dob}</td></tr>
                                <tr><td><strong>Gender:</strong></td><td>${member.gender}</td></tr>
                                <tr><td><strong>Address:</strong></td><td>${member.address}</td></tr>
                                <tr><td><strong>Occupation:</strong></td><td>${member.occupation || 'N/A'}</td></tr>
                                <tr><td><strong>Emergency Contact:</strong></td><td>${member.emergency_contact_name} (${member.emergency_contact_relationship}) - ${member.emergency_contact_phone}</td></tr>
                                <tr><td><strong>Registered:</strong></td><td>${new Date(member.created_at).toLocaleDateString()}</td></tr>
                            </table>
                        </div>
                    </div>
                `;
                document.getElementById('memberDetailsContent').innerHTML = html;
                new bootstrap.Modal(document.getElementById('memberDetailsModal')).show();
            }
        });
}

function recordPayment(memberId, memberName) {
    // Open payment modal with pre-filled member
    const paymentModal = new bootstrap.Modal(document.getElementById('paymentModal'));
    document.getElementById('selectedMemberId').value = memberId;
    document.getElementById('selectedMemberName').textContent = memberName;
    document.getElementById('memberSearch').value = memberName;
    document.getElementById('submitPayment').disabled = false;
    paymentModal.show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>