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
    } else {
        $annual_amount = sanitizeInput($_POST['annual_amount']);
        $monthly_amount = sanitizeInput($_POST['monthly_amount']);
        
        if (!is_numeric($annual_amount) || $annual_amount <= 0 || !is_numeric($monthly_amount) || $monthly_amount <= 0) {
            $error = 'Invalid amounts. Please enter positive numbers.';
        } else {
            $query = "UPDATE settings SET annual_amount = :annual, monthly_amount = :monthly WHERE id = 1";
            $stmt = $db->prepare($query);
            
            try {
                $stmt->execute([
                    ':annual' => $annual_amount,
                    ':monthly' => $monthly_amount
                ]);
                
                logAudit($_SESSION['user_id'], "Updated welfare settings: Annual={$annual_amount}, Monthly={$monthly_amount}");
                $success = 'Settings updated successfully.';
            } catch (PDOException $e) {
                $error = 'Failed to update settings.';
                error_log("Settings Error: " . $e->getMessage());
            }
        }
    }
}

// Get current settings
$settings_query = "SELECT * FROM settings WHERE id = 1";
$settings = $db->query($settings_query)->fetch();
$annual_amount = $settings['annual_amount'] ?? 1000;
$monthly_amount = $settings['monthly_amount'] ?? 100;
?>

<div class="row">
    <div class="col-md-8 mx-auto">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Welfare Settings</h5>
            </div>
            <div class="card-body">
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_settings">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="mb-3">
                        <label for="annual_amount" class="form-label">Annual Contribution Target (GH₵)</label>
                    <input type="number" class="form-control" id="annual_amount" name="annual_amount" 
                           value="<?php echo htmlspecialchars($annual_amount); ?>" step="0.01" min="0.01" required>
                        <small class="text-muted">The total amount each member should contribute annually</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="monthly_amount" class="form-label">Monthly Contribution Target (GH₵)</label>
                    <input type="number" class="form-control" id="monthly_amount" name="monthly_amount" 
                           value="<?php echo htmlspecialchars($monthly_amount); ?>" step="0.01" min="0.01" required>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
