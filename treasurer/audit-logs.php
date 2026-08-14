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

$success = '';
$error = '';

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        if ($_POST['action'] === 'clear_old_logs') {
            $days = (int)($_POST['days'] ?? 90);
            if ($days > 0) {
                $query = "DELETE FROM audit_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL :days DAY)";
                $stmt = $db->prepare($query);
                $stmt->execute([':days' => $days]);
                $deleted = $stmt->rowCount();
                $success = "Cleared {$deleted} old log entries.";
                logAudit($_SESSION['user_id'], "Cleared {$deleted} old audit logs");
            }
        } elseif ($_POST['action'] === 'clear_all_logs') {
            // Permanent, unconditional wipe of every audit log.
            $countStmt = $db->query("SELECT COUNT(*) FROM audit_logs");
            $total = (int)$countStmt->fetchColumn();
            $db->exec("DELETE FROM audit_logs");
            $success = "Cleared ALL {$total} audit log entries.";
            logAudit($_SESSION['user_id'], "Cleared ALL {$total} audit logs");
        }
    }
}

// Build filter query
$where_clause = "1=1";
$params = [];

$filter_user = $_GET['filter_user'] ?? '';
$filter_action = $_GET['filter_action'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$filter_ip = $_GET['filter_ip'] ?? '';

if (!empty($filter_user)) {
    $where_clause .= " AND user_id LIKE :user_id";
    $params[':user_id'] = '%' . cleanInput($filter_user) . '%';
}

if (!empty($filter_action)) {
    $where_clause .= " AND action LIKE :action";
    $params[':action'] = '%' . cleanInput($filter_action) . '%';
}

if (!empty($filter_date_from)) {
    $where_clause .= " AND DATE(timestamp) >= :date_from";
    $params[':date_from'] = cleanInput($filter_date_from);
}

if (!empty($filter_date_to)) {
    $where_clause .= " AND DATE(timestamp) <= :date_to";
    $params[':date_to'] = cleanInput($filter_date_to);
}

if (!empty($filter_ip)) {
    $where_clause .= " AND ip_address = :ip_address";
    $params[':ip_address'] = cleanInput($filter_ip);
}

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get total count
$countQuery = "SELECT COUNT(*) as total FROM audit_logs WHERE {$where_clause}";
$countStmt = $db->prepare($countQuery);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetch()['total'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Get logs
$query = "SELECT * FROM audit_logs 
          WHERE {$where_clause} 
          ORDER BY timestamp DESC 
          LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

// Get unique actions for filter dropdown
$actionsQuery = "SELECT DISTINCT action FROM audit_logs ORDER BY action";
$actionsStmt = $db->prepare($actionsQuery);
$actionsStmt->execute();
$uniqueActions = $actionsStmt->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="row">
    <div class="col-12">
        <h2 class="mb-4">🔍 Audit Log Viewer</h2>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Filters</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">User ID</label>
                <input type="text" class="form-control" name="filter_user" 
                       placeholder="Search by user ID" value="<?php echo htmlspecialchars($filter_user); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Action</label>
                <input type="text" class="form-control" name="filter_action" 
                       placeholder="Search by action" value="<?php echo htmlspecialchars($filter_action); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">IP Address</label>
                <input type="text" class="form-control" name="filter_ip" 
                       placeholder="IP address" value="<?php echo htmlspecialchars($filter_ip); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Date From</label>
                <input type="date" class="form-control" name="filter_date_from" 
                       value="<?php echo htmlspecialchars($filter_date_from); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Date To</label>
                <input type="date" class="form-control" name="filter_date_to" 
                       value="<?php echo htmlspecialchars($filter_date_to); ?>">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">🔍 Apply Filters</button>
                <a href="audit-logs.php" class="btn btn-outline-secondary">🔄 Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Logs Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Audit Logs (<?php echo $totalRows; ?> total)</h5>
        <div>
            <form method="POST" action="" class="d-inline" id="clearOldLogsForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                <input type="hidden" name="action" value="clear_old_logs">
                <input type="number" name="days" value="90" class="form-control d-inline" 
                       style="width: 80px;" title="Days to keep">
                <button type="submit" class="btn btn-warning btn-sm">
                    🗑️ Clear Old Logs
                </button>
            </form>
            <form method="POST" action="" class="d-inline" id="clearAllLogsForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                <input type="hidden" name="action" value="clear_all_logs">
                <button type="submit" class="btn btn-danger btn-sm">
                    ⚠️ Clear ALL Logs
                </button>
            </form>
            <script nonce="<?php echo CSP_NONCE; ?>">
                document.getElementById('clearOldLogsForm').addEventListener('submit', function (e) {
                    if (!confirm('This will permanently delete logs older than the specified days. Continue?')) {
                        e.preventDefault();
                    }
                });
                document.getElementById('clearAllLogsForm').addEventListener('submit', function (e) {
                    if (!confirm('WARNING: This will PERMANENTLY delete ALL audit logs, including recent ones. This cannot be undone. Continue?')) {
                        e.preventDefault();
                    }
                });
            </script>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($logs)): ?>
            <div class="alert alert-info">No audit logs found.</div>
        <?php else: ?>
            <div class="table-scroll-wrapper">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User ID</th>
                            <th>Action</th>
                            <th>IP Address</th>
                            <th>User Agent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo date('M d, Y H:i:s', strtotime($log['timestamp'])); ?></td>
                                <td><?php echo htmlspecialchars($log['user_id'] ?? 'system'); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo strpos($log['action'], 'login') !== false ? 'primary' : 
                                            (strpos($log['action'], 'password') !== false ? 'warning' : 
                                            (strpos($log['action'], 'failed') !== false || strpos($log['action'], 'locked') !== false ? 'danger' : 'info')); 
                                    ?>">
                                        <?php echo htmlspecialchars($log['action']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                <td>
                                    <small class="text-muted">
                                        <?php 
                                        $ua = $log['user_agent'] ?? '';
                                        echo htmlspecialchars(substr($ua, 0, 50)) . (strlen($ua) > 50 ? '...' : ''); 
                                        ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav>
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 5); $i <= min($totalPages, $page + 5); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-info mt-3">
    <strong>💡 Tip:</strong> Audit logs track all significant actions including logins, password changes, 2FA changes, and security events.
    Use filters to find specific events. Old logs can be cleared to maintain database performance.
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>