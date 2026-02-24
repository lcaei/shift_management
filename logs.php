<?php
/**
 * VitalZone - System Logs Viewer
 * Displays all user actions with filters and pagination.
 * Accessible only to super_admin.
 * Updated with top navigation bar including all super_admin menus.
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Only super_admin can access
requireRole('super_admin');

$role = getRole();

// Pagination settings
$limit = 50;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Filter values
$filterUser   = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
$filterAction = isset($_GET['action']) ? trim($_GET['action']) : '';
$filterTable  = isset($_GET['table_name']) ? trim($_GET['table_name']) : '';
$filterDateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filterDateTo   = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query with filters
$sql = "SELECT l.*, u.username
        FROM logs l
        LEFT JOIN users u ON l.user_id = u.id
        WHERE 1=1";
$countSql = "SELECT COUNT(*) FROM logs l WHERE 1=1";
$params = [];

if ($filterUser) {
    $sql .= " AND l.user_id = ?";
    $countSql .= " AND l.user_id = ?";
    $params[] = $filterUser;
}
if (!empty($filterAction)) {
    $sql .= " AND l.action LIKE ?";
    $countSql .= " AND l.action LIKE ?";
    $params[] = "%$filterAction%";
}
if (!empty($filterTable)) {
    $sql .= " AND l.table_name LIKE ?";
    $countSql .= " AND l.table_name LIKE ?";
    $params[] = "%$filterTable%";
}
if (!empty($filterDateFrom)) {
    $sql .= " AND l.created_at >= ?";
    $countSql .= " AND l.created_at >= ?";
    $params[] = $filterDateFrom . ' 00:00:00';
}
if (!empty($filterDateTo)) {
    $sql .= " AND l.created_at <= ?";
    $countSql .= " AND l.created_at <= ?";
    $params[] = $filterDateTo . ' 23:59:59';
}

$sql .= " ORDER BY l.created_at DESC LIMIT $limit OFFSET $offset";

// Get total count for pagination
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalRows = $stmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Get logs
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get list of users for filter dropdown
$users = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VitalZone - System Logs</title>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fc; padding-top: 60px; }
        .navbar-custom {
            background-color: #1e2a3a;
            padding: 0.5rem 1rem;
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1030;
        }
        .navbar-custom .navbar-brand {
            color: white;
            font-weight: bold;
        }
        .navbar-custom .nav-link {
            color: #b0c4de;
        }
        .navbar-custom .nav-link:hover {
            color: white;
        }
        .navbar-custom .nav-link.active {
            color: white;
            background-color: #0d6efd;
            border-radius: 0.25rem;
        }
        .navbar-custom .dropdown-menu {
            background-color: #2c3e50;
        }
        .navbar-custom .dropdown-item {
            color: #b0c4de;
        }
        .navbar-custom .dropdown-item:hover {
            background-color: #0d6efd;
            color: white;
        }
        .card { border: none; box-shadow: 0 0.15rem 1.75rem 0 rgba(58,59,69,0.15); }
        .filter-bar { background: white; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .btn-clear { background-color: #6c757d; color: white; }
        .btn-clear:hover { background-color: #5a6268; }
        .log-details { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    </style>
</head>
<body>
    <!-- Top Navigation Bar (super_admin sees all links) -->
    <nav class="navbar navbar-expand-lg navbar-custom">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">VitalZone</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php"><i class="fas fa-tachometer-alt me-1"></i>Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="nurse.php"><i class="fas fa-user-nurse me-1"></i>Employee</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="patient.php"><i class="fas fa-home me-1"></i>Patients</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="schedule.php"><i class="fas fa-calendar-alt me-1"></i>Schedule Board</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_shifts.php"><i class="fas fa-list me-1"></i>Manage Shifts</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="groups.php"><i class="fas fa-users-cog me-1"></i>Employee Groups</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="finance_shifts.php"><i class="fas fa-file-csv me-1"></i>Shift Reports</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="profit.php"><i class="fas fa-chart-line me-1"></i>Profit Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="logs.php"><i class="fas fa-history me-1"></i>System Logs</a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main content (unchanged) -->
    <main class="container-fluid px-md-4 py-4">
        <!-- Page header -->
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
            <h1 class="h2">System Logs</h1>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="get" action="logs.php" class="row g-3">
                <div class="col-md-2">
                    <label for="user_id" class="form-label">User</label>
                    <select name="user_id" id="user_id" class="form-select">
                        <option value="">All Users</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo ($filterUser == $user['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="action" class="form-label">Action</label>
                    <input type="text" class="form-control" id="action" name="action" value="<?php echo htmlspecialchars($filterAction); ?>" placeholder="e.g., INSERT">
                </div>
                <div class="col-md-2">
                    <label for="table_name" class="form-label">Table</label>
                    <input type="text" class="form-control" id="table_name" name="table_name" value="<?php echo htmlspecialchars($filterTable); ?>" placeholder="e.g., nurses">
                </div>
                <div class="col-md-2">
                    <label for="date_from" class="form-label">From</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($filterDateFrom); ?>">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">To</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($filterDateTo); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2"><i class="fas fa-filter"></i> Filter</button>
                    <a href="logs.php" class="btn btn-clear"><i class="fas fa-times"></i> Clear</a>
                </div>
            </form>
        </div>

        <!-- Logs table -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Log Entries (<?php echo $totalRows; ?> total)</h6>
            </div>
            <div class="card-body">
                <?php if (empty($logs)): ?>
                    <p class="text-muted">No logs match your filters.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Timestamp</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Table</th>
                                    <th>Record ID</th>
                                    <th>Details</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td><?php echo $log['id']; ?></td>
                                        <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></td>
                                        <td><?php echo htmlspecialchars($log['action']); ?></td>
                                        <td><?php echo htmlspecialchars($log['table_name']); ?></td>
                                        <td><?php echo $log['record_id'] ?: '—'; ?></td>
                                        <td class="log-details" title="<?php echo htmlspecialchars($log['details'] ?? ''); ?>">
                                            <?php echo htmlspecialchars(substr($log['details'] ?? '', 0, 50)) . (strlen($log['details'] ?? '') > 50 ? '…' : ''); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Log pagination">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                                </li>
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Bootstrap JS bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>