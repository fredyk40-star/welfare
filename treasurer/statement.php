<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

// Check if user is logged in (treasurer or the member themselves)
if (!isLoggedIn()) {
    redirectTo('/member/login.php');
}

$database = new Database();
$db = $database->getConnection();

$member_id = cleanInput($_GET['member_id'] ?? '');
$embed = !empty($_GET['embed']);

// Authorization: treasurer can view any, member can only view own
if (!isTreasurer() && $_SESSION['user_id'] !== $member_id) {
    redirectTo('/member/dashboard.php');
}

$member = null;
$transactions = [];
$settings = [];
$ytd_paid = 0;
$annual_target = 0;

try {
    // Get member details
    $stmt = $db->prepare("SELECT * FROM members WHERE member_id = :mid");
    $stmt->execute([':mid' => $member_id]);
    $member = $stmt->fetch();

    if (!$member) {
        throw new Exception('Member not found');
    }

    // Get settings for the transaction year (fallback to current year)
    $settings = getYearlyTarget($db, date('Y'));
    $annual_target = $settings['annual_amount'];

    // Get all transactions for this member
    $stmt = $db->prepare("SELECT * FROM transactions WHERE member_id = :mid AND status != 'void' ORDER BY transaction_date DESC");
    $stmt->execute([':mid' => $member_id]);
    $transactions = $stmt->fetchAll();

    // Year-to-date total
    $ytd = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE member_id = :mid AND billing_cycle_year = :yr AND status != 'void'");
    $ytd->execute([':mid' => $member_id, ':yr' => date('Y')]);
    $ytd_paid = (float) $ytd->fetch()['total'];
} catch (Exception $e) {
    $member = null;
    error_log('Statement error: ' . $e->getMessage());
}

if ($embed) {
    // Embedded statement (no navbar/footer) for modal / print
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Statement - <?php echo htmlspecialchars($member['full_name'] ?? ''); ?></title>
        <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/bootstrap/css/bootstrap.min.css">
        <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
        <style>
            body { background: #f4f6f9; }
            .statement-wrap { max-width: 900px; margin: 24px auto; }
            .statement-header {
                background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
                color: #fff;
                border-radius: 12px;
                padding: 24px;
            }
            .statement-header .org-name { font-size: 0.85rem; opacity: 0.8; letter-spacing: 0.08em; text-transform: uppercase; }
            .statement-header h1 { font-size: 1.6rem; font-weight: 700; margin: 6px 0 0; }
            .stat-card {
                border: 0;
                border-radius: 12px;
                box-shadow: 0 2px 8px rgba(15,23,42,0.06);
                transition: transform .15s ease, box-shadow .15s ease;
            }
            .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(15,23,42,0.1); }
            .member-avatar {
                width: 72px; height: 72px; border-radius: 50%; object-fit: cover;
                border: 3px solid #e2e8f0; background: #fff;
            }
            .avatar-placeholder {
                width: 72px; height: 72px; border-radius: 50%;
                background: linear-gradient(135deg, #6366f1, #8b5cf6);
                color: #fff; font-size: 1.6rem; font-weight: 700;
                display: inline-flex; align-items: center; justify-content: center;
                border: 3px solid #e2e8f0;
            }
            .tx-table thead th { background: #f8fafc; border-bottom: 2px solid #e2e8f0; }
            .tx-table tbody tr:hover { background: #f8fafc; }
            .badge-void { background: #fee2e2; color: #991b1b; }
            .badge-active { background: #d1fae5; color: #065f46; }
            @media print {
                body { background: #fff; }
                .statement-wrap { margin: 0; max-width: 100%; }
                .no-print { display: none !important; }
                .statement-header { background: #0f172a !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        </style>
    </head>
    <body>
        <div class="statement-wrap">
            <?php if (!$member): ?>
                <div class="alert alert-danger">Member not found or access denied.</div>
            <?php else: ?>
                <div class="statement-header mb-3 d-flex align-items-center gap-3">
                    <div class="org-name"><?php echo htmlspecialchars(APP_NAME); ?></div>
                    <div class="flex-grow-1">
                        <h1 class="mb-0">Contribution Statement</h1>
                        <div class="opacity-75">Generated on <?php echo date('F j, Y g:i A'); ?></div>
                    </div>
                    <div class="no-print text-end">
                        <button onclick="window.print()" class="btn btn-light btn-sm">🖨️ Print / Save PDF</button>
                    </div>
                </div>

                <div class="card stat-card mb-3">
                    <div class="card-body d-flex align-items-center gap-3">
                        <?php if ($member['passport_photo']): ?>
                            <img src="<?php echo displayPhotoUrl($member['passport_photo']); ?>" class="member-avatar" alt="Photo">
                        <?php else: ?>
                            <div class="avatar-placeholder"><?php echo strtoupper(substr($member['full_name'], 0, 1)); ?></div>
                        <?php endif; ?>
                        <div>
                            <h5 class="mb-1"><?php echo htmlspecialchars($member['full_name']); ?></h5>
                            <p class="text-muted mb-0 small"><?php echo htmlspecialchars($member['member_id']); ?> · <?php echo htmlspecialchars($member['email']); ?> · <?php echo htmlspecialchars($member['phone']); ?></p>
                            <p class="text-muted mb-0 small">Member since <?php echo date('F j, Y', strtotime($member['created_at'])); ?></p>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="card stat-card h-100">
                            <div class="card-body text-center">
                                <div class="text-muted small text-uppercase tracking-wide mb-1">Yearly Paid</div>
                                <div class="fw-bold" style="font-size:1.35rem;">GH₵ <?php echo number_format($ytd_paid, 2); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card h-100">
                            <div class="card-body text-center">
                                <div class="text-muted small text-uppercase tracking-wide mb-1">Annual Target</div>
                                <div class="fw-bold" style="font-size:1.35rem;">GH₵ <?php echo number_format($annual_target, 2); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stat-card h-100">
                            <div class="card-body text-center">
                                <div class="text-muted small text-uppercase tracking-wide mb-1">Year Debt</div>
                                <?php $debt = max(0.0, $annual_target - $ytd_paid); ?>
                                <div class="fw-bold" style="font-size:1.35rem; color: <?php echo $debt > 0.01 ? '#dc2626' : '#16a34a'; ?>;">
                                    GH₵ <?php echo number_format($debt, 2); ?>
                                </div>
                                <small class="text-muted"><?php echo $debt > 0.01 ? 'Outstanding' : 'Cleared'; ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card stat-card mb-3">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0">Transaction History</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive tx-table">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Receipt No</th>
                                        <th class="text-end">Amount</th>
                                        <th>Method</th>
                                        <th>Billing Period</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($transactions)): ?>
                                        <tr><td colspan="7" class="text-center text-muted py-4">No transactions found</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($transactions as $i => $tx): ?>
                                            <tr>
                                                <td class="text-muted"><?php echo $i + 1; ?></td>
                                                <td><code><?php echo htmlspecialchars($tx['receipt_no']); ?></code></td>
                                                <td class="text-end fw-bold text-success">GH₵ <?php echo number_format($tx['amount'], 2); ?></td>
                                                <td><?php echo htmlspecialchars($tx['payment_method']); ?></td>
                                                <td>
                                                    <?php echo $tx['billing_cycle_month'] ? htmlspecialchars(formatBillingPeriod($tx['billing_cycle_month'], $tx['billing_cycle_year'] ?? date('Y'))) : htmlspecialchars($tx['billing_cycle_year']); ?>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($tx['transaction_date'])); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $tx['status'] === 'void' ? 'danger' : 'success'; ?>">
                                                        <?php echo ucfirst($tx['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card stat-card">
                    <div class="card-body text-center">
                        <small class="text-muted"><?php echo htmlspecialchars(APP_NAME); ?> · Generated on <?php echo date('F j, Y g:i A'); ?></small>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Full page mode (standalone statement)
require_once __DIR__ . '/../includes/header.php';
?>
<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Contribution Statement</h2>
            <button class="btn btn-primary" onclick="window.print()">🖨️ Print / Save as PDF</button>
        </div>
    </div>
</div>

<?php if (!$member): ?>
    <div class="alert alert-danger">Member not found or access denied.</div>
<?php else: ?>
    <div class="row mb-4">
        <div class="col-md-4 text-center">
            <?php if ($member['passport_photo']): ?>
                <img src="<?php echo displayPhotoUrl($member['passport_photo']); ?>"
                     class="img-fluid rounded mb-3" style="max-width: 200px;" alt="Photo">
            <?php else: ?>
                <div class="bg-secondary text-white rounded p-5 mb-3">
                    <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                </div>
            <?php endif; ?>
            <h4><?php echo htmlspecialchars($member['full_name']); ?></h4>
            <p class="text-muted"><?php echo htmlspecialchars($member['member_id']); ?></p>
        </div>
        <div class="col-md-8">
            <table class="table table-borderless">
                <tr><td><strong>Email:</strong></td><td><?php echo htmlspecialchars($member['email']); ?></td></tr>
                <tr><td><strong>Phone:</strong></td><td><?php echo htmlspecialchars($member['phone']); ?></td></tr>
                <tr><td><strong>Member Since:</strong></td><td><?php echo date('F j, Y', strtotime($member['created_at'])); ?></td></tr>
            </table>
        </div>
    </div>

    <!-- Annual Progress -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Annual Contribution Progress (<?php echo date('Y'); ?>)</h5>
        </div>
        <div class="card-body">
            <?php
            $pct = $annual_target > 0 ? min(100, round(($ytd_paid / $annual_target) * 100)) : 0;
            $bar_color = $pct >= 100 ? 'success' : ($pct >= 50 ? 'warning' : 'danger');
            ?>
            <div class="progress" style="height: 30px;">
                <div class="progress-bar bg-<?php echo $bar_color; ?>" role="progressbar"
                     style="width: <?php echo $pct; ?>%"><?php echo $pct; ?>%</div>
            </div>
            <div class="mt-2 text-center">
                <strong>GH₵ <?php echo number_format($ytd_paid, 2); ?></strong> of <strong>GH₵ <?php echo number_format($annual_target, 2); ?></strong>
                <?php $debt = max(0.0, $annual_target - $ytd_paid); ?>
                <?php if ($debt > 0.01): ?>
                    <br><span class="text-danger fw-bold">Year debt: GH₵ <?php echo number_format($debt, 2); ?></span>
                <?php else: ?>
                    <br><span class="text-success fw-bold">✓ Target cleared</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Transaction History -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Transaction History</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Receipt No</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Billing Period</th>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No transactions found</td></tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $tx): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($tx['receipt_no']); ?></td>
                                    <td class="text-success fw-bold">GH₵ <?php echo number_format($tx['amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($tx['payment_method']); ?></td>
                                    <td>
                                        <?php
                                        if ($tx['billing_cycle_month']) {
                                            echo date('F Y', mktime(0, 0, 0, $tx['billing_cycle_month'], 1, $tx['billing_cycle_year']));
                                        } else {
                                            echo htmlspecialchars($tx['billing_cycle_year']);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($tx['transaction_date'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $tx['status'] === 'void' ? 'danger' : 'success'; ?>">
                                            <?php echo ucfirst($tx['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Summary -->
    <div class="card mt-4">
        <div class="card-body text-center">
            <h5>Summary</h5>
            <p class="mb-1"><strong>Total Transactions:</strong> <?php echo count($transactions); ?></p>
            <p class="mb-1"><strong>Total Paid (<?php echo date('Y'); ?>):</strong> GH₵ <?php echo number_format($ytd_paid, 2); ?></p>
            <p class="mb-0"><strong>Annual Target:</strong> GH₵ <?php echo number_format($annual_target, 2); ?></p>
        </div>
    </div>

    <div class="text-center mt-4 text-muted small">
        Generated on <?php echo date('F j, Y g:i A'); ?> | <?php echo APP_NAME; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
