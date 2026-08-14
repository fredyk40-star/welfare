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
$current_year = (int) date('Y');

// Update global defaults (legacy fallback)
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
                logAudit($_SESSION['user_id'] ?? 'system', "Updated global welfare defaults: Annual={$annual_amount}, Monthly={$monthly_amount}");
                $success = 'Global default settings updated.';
            } else {
                $error = 'Failed to update settings.';
            }
        }
    }
}

// Update per-year target
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_year_target') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } elseif (!checkRateLimit($_SESSION['user_id'] ?? getClientIp(), 10, 300, '%year_target%')) {
        $error = 'Too many updates. Please try again later.';
    } else {
        $year = (int) ($_POST['year'] ?? 0);
        $annual_amount = filter_var($_POST['annual_amount'] ?? '', FILTER_VALIDATE_FLOAT);
        $monthly_amount = filter_var($_POST['monthly_amount'] ?? '', FILTER_VALIDATE_FLOAT);

        if ($year <= 2019 || $year > 2100) {
            $error = 'Invalid year.';
        } elseif ($annual_amount === false || $monthly_amount === false || $annual_amount <= 0 || $monthly_amount <= 0) {
            $error = 'Invalid amounts. Please enter positive numbers.';
        } else {
            $stmt = $db->prepare("INSERT INTO yearly_targets (year, annual_amount, monthly_amount) VALUES (:yr, :annual, :monthly) ON DUPLICATE KEY UPDATE annual_amount = :annual, monthly_amount = :monthly, updated_at = NOW()");
            if ($stmt->execute([':yr' => $year, ':annual' => $annual_amount, ':monthly' => $monthly_amount])) {
                logAudit($_SESSION['user_id'] ?? 'system', "Updated yearly target for {$year}: Annual={$annual_amount}, Monthly={$monthly_amount}");
                $success = "Target for {$year} updated successfully.";
            } else {
                $error = 'Failed to update yearly target.';
            }
        }
    }
}

// Delete a yearly target
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_year_target') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } elseif (!checkRateLimit($_SESSION['user_id'] ?? getClientIp(), 10, 300, '%year_target%')) {
        $error = 'Too many updates. Please try again later.';
    } else {
        $year = (int) ($_POST['year'] ?? 0);
        if ($year <= 2019 || $year > 2100) {
            $error = 'Invalid year.';
        } elseif ($year === $current_year) {
            $error = 'You cannot delete the current year\'s target.';
        } else {
            $stmt = $db->prepare("DELETE FROM yearly_targets WHERE year = :yr");
            if ($stmt->execute([':yr' => $year])) {
                logAudit($_SESSION['user_id'] ?? 'system', "Deleted yearly target for {$year}");
                $success = "Target for {$year} deleted. Future payments for that year will fall back to global defaults.";
            } else {
                $error = 'Failed to delete yearly target.';
            }
        }
    }
}

// Load data
$global_settings = getWelfareSettings($db);
$yearly_targets = $db->query("SELECT * FROM yearly_targets ORDER BY year DESC")->fetchAll();
$current_target = getYearlyTarget($db, $current_year);
?>

<div class="row">
    <div class="col-md-8 mx-auto">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">📅 Current Year Target (<?php echo $current_year; ?>)</h5>
            </div>
            <div class="card-body">
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_year_target">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="year" value="<?php echo $current_year; ?>">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="annual_amount" class="form-label">Annual Target (GH₵)</label>
                            <input type="number" class="form-control" id="annual_amount" name="annual_amount"
                                   value="<?php echo htmlspecialchars($current_target['annual_amount']); ?>" step="0.01" min="0.01" max="1000000" required>
                            <small class="text-muted">Total expected contribution per member for <?php echo $current_year; ?></small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="monthly_amount" class="form-label">Monthly Target (GH₵)</label>
                            <input type="number" class="form-control" id="monthly_amount" name="monthly_amount"
                                   value="<?php echo htmlspecialchars($current_target['monthly_amount']); ?>" step="0.01" min="0.01" max="1000000" required>
                            <small class="text-muted">Expected per-member monthly contribution. Set any amount that fits your welfare plan.</small>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Update <?php echo $current_year; ?> Target</button>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">🗓️ All Year Targets</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">Each calendar year can have its own target. Historical years keep their original targets so progress is always measured correctly.</p>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Year</th>
                                <th>Annual Target</th>
                                <th>Monthly Target</th>
                                <th>Last Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($yearly_targets as $yt): ?>
                                <tr>
                                    <td><strong><?php echo (int)$yt['year']; ?></strong></td>
                                    <td>GH₵ <?php echo number_format($yt['annual_amount'], 2); ?></td>
                                    <td>GH₵ <?php echo number_format($yt['monthly_amount'], 2); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($yt['updated_at'])); ?></td>
                                    <td>
                                        <?php if ((int)$yt['year'] === $current_year): ?>
                                            <span class="badge bg-primary">Current</span>
                                        <?php else: ?>
                                            <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Delete target for <?php echo (int)$yt['year']; ?>? This will fall back to global defaults for that year.');">
                                                <input type="hidden" name="action" value="delete_year_target">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="year" value="<?php echo (int)$yt['year']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($yearly_targets)): ?>
                                <tr><td colspan="5" class="text-center text-muted">No yearly targets configured yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Global Defaults (Fallback)</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">These are used when a year has no explicit target. They also initialize new years.</p>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_settings">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Default Annual (GH₵)</label>
                            <input type="number" class="form-control" name="annual_amount" value="<?php echo htmlspecialchars($global_settings['annual_amount']); ?>" step="0.01" min="0.01" max="1000000" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Default Monthly (GH₵)</label>
                            <input type="number" class="form-control" name="monthly_amount" value="<?php echo htmlspecialchars($global_settings['monthly_amount']); ?>" step="0.01" min="0.01" max="1000000" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary">Update Global Defaults</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
