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

// Update welfare settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } elseif (!checkRateLimit($_SESSION['user_id'] ?? getClientIp(), 5, 300, '%settings_update%')) {
        $error = 'Too many settings updates. Please try again later.';
    } else {
        $annual_amount_raw = $_POST['annual_amount'] ?? '';
        $monthly_amount_raw = $_POST['monthly_amount'] ?? '';
        
        $annual_amount = filter_var($annual_amount_raw, FILTER_VALIDATE_FLOAT);
        $monthly_amount = filter_var($monthly_amount_raw, FILTER_VALIDATE_FLOAT);
        
        if ($annual_amount === false || $monthly_amount === false || $annual_amount <= 0 || $monthly_amount <= 0) {
            $error = 'Invalid amounts. Please enter positive numbers.';
        } elseif ($annual_amount > 1000000 || $monthly_amount > 1000000) {
            $error = 'Amounts cannot exceed GH₵ 1,000,000.';
        } else {
            if (updateWelfareSettings($db, $annual_amount, $monthly_amount)) {
                logAudit($_SESSION['user_id'] ?? 'system', "Updated welfare settings: Annual={$annual_amount}, Monthly={$monthly_amount}");
                $success = 'Settings updated successfully.';
            } else {
                $error = 'Failed to update settings.';
            }
        }
    }
}

// Get current settings
$settings = getWelfareSettings($db);
$annual_amount = $settings['annual_amount'];
$monthly_amount = $settings['monthly_amount'];
?>

<div class="row">
    <div class="col-md-8 mx-auto">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Welfare Settings</h5>
            </div>
            <div class="card-body">
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_settings">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    
                    <div class="mb-3">
                        <label for="annual_amount" class="form-label">Annual Contribution Target (GH₵)</label>
                    <input type="number" class="form-control" id="annual_amount" name="annual_amount" 
                           value="<?php echo htmlspecialchars($annual_amount); ?>" step="0.01" min="0.01" max="1000000" required>
                        <small class="text-muted">The total amount each member should contribute annually</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="monthly_amount" class="form-label">Monthly Contribution Target (GH₵)</label>
                    <input type="number" class="form-control" id="monthly_amount" name="monthly_amount" 
                           value="<?php echo htmlspecialchars($monthly_amount); ?>" step="0.01" min="0.01" max="1000000" required>
                        <small class="text-muted">The expected monthly contribution per member</small>
                    </div>
                    
                    <div class="alert alert-info">
                        <strong>Note:</strong> Changing these settings will apply to future transactions only. 
                        Existing transactions will not be affected.
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Update Settings</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Danger Zone: Database Reset -->
<div class="row mt-4">
    <div class="col-md-8 mx-auto">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white d-flex align-items-center">
                <h5 class="mb-0">⚠️ Danger Zone — Reset Database</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-warning">
                    <strong>Warning:</strong> This permanently removes data and <u>cannot be undone</u>.
                    The treasurer account and welfare settings are always preserved.
                </div>

                <form id="resetDatabaseForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Select data to reset:</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="reset_transactions" id="reset_transactions" checked>
                            <label class="form-check-label" for="reset_transactions">All transactions (payment records)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="reset_audit_logs" id="reset_audit_logs" checked>
                            <label class="form-check-label" for="reset_audit_logs">Audit logs</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="reset_password_resets" id="reset_password_resets" checked>
                            <label class="form-check-label" for="reset_password_resets">Password reset tokens</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="reset_members" id="reset_members">
                            <label class="form-check-label text-danger" for="reset_members">All members (except the treasurer account)</label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="reset_confirm" class="form-label">Type <code>RESET</code> to confirm:</label>
                        <input type="text" class="form-control" id="reset_confirm" name="confirm" placeholder="RESET" autocomplete="off">
                    </div>

                    <button type="submit" class="btn btn-danger" id="resetDbBtn">Reset Selected Data</button>
                    <div id="resetResult" class="mt-3"></div>
                </form>
            </div>
        </div>
    </div>
</div>

<script nonce="<?php echo CSP_NONCE; ?>">
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('resetDatabaseForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var confirmInput = document.getElementById('reset_confirm');
        if (confirmInput.value.trim() !== 'RESET') {
            alert('Type RESET in the confirmation box to proceed.');
            return;
        }
        if (!confirm('This will PERMANENTLY delete the selected data. Continue?')) {
            return;
        }
        var btn = document.getElementById('resetDbBtn');
        var result = document.getElementById('resetResult');
        btn.disabled = true;
        result.innerHTML = '<div class="alert alert-info">Processing...</div>';

        var fd = new FormData(form);
        fetch('<?php echo APP_URL; ?>/api/members.php?action=reset_database', {
            method: 'POST',
            body: fd
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (d.success) {
                result.innerHTML = '<div class="alert alert-success">'
                    + (d.details ? d.details.join('<br>') : d.message) + '</div>';
                confirmInput.value = '';
            } else {
                result.innerHTML = '<div class="alert alert-danger">' + (d.message || 'Reset failed.') + '</div>';
            }
        })
        .catch(function () {
            btn.disabled = false;
            result.innerHTML = '<div class="alert alert-danger">Network error. Please try again.</div>';
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
