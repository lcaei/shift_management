<?php
/**
 * VitalZone Nurse Scheduling - Dashboard
 * Role‑based dashboard with filterable shifts table and inline shift details modal.
 * Supports super_admin and roaster roles.
 * Updated with top navigation bar and searchable dropdowns (Select2).
 * Now includes Shift Types link and column.
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$role = getRole();
$user_id = $_SESSION['user_id'] ?? 0;

// Define permissions
$canEdit = in_array($role, ['super_admin', 'roaster']);
$canApprove = in_array($role, ['super_admin', 'admin1', 'admin2', 'admin3']);
$isFinance = ($role == 'finance');

// Get all nurses, patients, and shift types for dropdowns
$allNurses = getNursesList($pdo);
$allPatients = getPatientsList($pdo);
$allShiftTypes = $pdo->query("SELECT id, type_name FROM shift_types ORDER BY type_name")->fetchAll(PDO::FETCH_KEY_PAIR);

// Get counts for pending approvals (for admins and super_admin)
$pendingAdmin1 = 0;
$pendingAdmin2 = 0;
$pendingAdmin3 = 0;
$recentShifts = [];
$db_error = null;

try {
    // Pending counts – only for relevant roles
    if ($role == 'admin1') {
        $stmt = $pdo->query("SELECT COUNT(*) FROM shifts WHERE admin1_approved = 0");
        $pendingAdmin1 = $stmt->fetchColumn();
    } elseif ($role == 'admin2') {
        $stmt = $pdo->query("SELECT COUNT(*) FROM shifts WHERE admin1_approved = 1 AND admin2_approved = 0");
        $pendingAdmin2 = $stmt->fetchColumn();
    } elseif ($role == 'admin3') {
        $stmt = $pdo->query("SELECT COUNT(*) FROM shifts WHERE admin1_approved = 1 AND admin2_approved = 1 AND admin3_approved = 0");
        $pendingAdmin3 = $stmt->fetchColumn();
    } elseif ($role == 'super_admin') {
        // Super admin sees all pending counts
        $stmt = $pdo->query("SELECT COUNT(*) FROM shifts WHERE admin1_approved = 0");
        $pendingAdmin1 = $stmt->fetchColumn();
        $stmt = $pdo->query("SELECT COUNT(*) FROM shifts WHERE admin1_approved = 1 AND admin2_approved = 0");
        $pendingAdmin2 = $stmt->fetchColumn();
        $stmt = $pdo->query("SELECT COUNT(*) FROM shifts WHERE admin1_approved = 1 AND admin2_approved = 1 AND admin3_approved = 0");
        $pendingAdmin3 = $stmt->fetchColumn();
    }

    // ==================== FILTER HANDLING ====================
    $nurseFilter   = isset($_GET['nurse_id']) && $_GET['nurse_id'] !== '' ? (int)$_GET['nurse_id'] : null;
    $patientFilter = isset($_GET['patient_id']) && $_GET['patient_id'] !== '' ? (int)$_GET['patient_id'] : null;
    $approvalFilter = $_GET['approval'] ?? 'all';
    $dateFrom      = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
    $dateTo        = $_GET['date_to'] ?? date('Y-m-d');

    // Build the base query – now includes shift type
    $sql = "SELECT s.*,
                   n.name AS nurse_name,
                   p.name AS patient_name,
                   st.type_name AS shift_type_name
            FROM shifts s
            JOIN nurses n ON s.nurse_id = n.id
            JOIN patients p ON s.patient_id = p.id
            LEFT JOIN shift_types st ON s.shift_type_id = st.id
            WHERE 1=1";

    $params = [];

    if ($nurseFilter) {
        $sql .= " AND s.nurse_id = ?";
        $params[] = $nurseFilter;
    }
    if ($patientFilter) {
        $sql .= " AND s.patient_id = ?";
        $params[] = $patientFilter;
    }
    $sql .= " AND s.shift_date BETWEEN ? AND ?";
    $params[] = $dateFrom;
    $params[] = $dateTo;

    if ($approvalFilter === 'pending') {
        $sql .= " AND s.admin1_approved = 0 AND s.admin2_approved = 0 AND s.admin3_approved = 0";
    } elseif ($approvalFilter === 'partial') {
        $sql .= " AND (s.admin1_approved = 1 OR s.admin2_approved = 1 OR s.admin3_approved = 1)
                  AND NOT (s.admin1_approved = 1 AND s.admin2_approved = 1 AND s.admin3_approved = 1)";
    } elseif ($approvalFilter === 'approved') {
        $sql .= " AND s.fully_approved = 1";
    }

    $sql .= " ORDER BY s.shift_date DESC, s.start_time ASC LIMIT 50";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $recentShifts = $stmt->fetchAll();

    // For finance, we don't need the nurses list anymore, but keep for compatibility
    if ($role == 'finance') {
        $nurses = $allNurses;
    }

} catch (PDOException $e) {
    error_log("Dashboard DB error: " . $e->getMessage());
    $db_error = "Unable to load dashboard data. Please try again later.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VitalZone Dashboard</title>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
        .card-header { background-color: #f8f9fc; border-bottom: 1px solid #e3e6f0; }
        .stat-card { border-left: 4px solid; }
        .filter-bar { background: white; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .filter-bar .row { align-items: flex-end; }
        .btn-clear { background-color: #6c757d; color: white; }
        .btn-clear:hover { background-color: #5a6268; }
        .action-btn { cursor: pointer; color: #0d6efd; }
        .action-btn:hover { color: #0a58ca; }
        /* Ensure select2 has proper width inside Bootstrap grid */
        .select2-container {
            width: 100% !important;
        }
        .shift-type-badge {
            background-color: #e9ecef;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.85rem;
            display: inline-block;
        }
    </style>
</head>
<body>
    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-custom">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">VitalZone</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php"><i class="fas fa-tachometer-alt me-1"></i>Dashboard</a>
                    </li>
                    <?php if (in_array($role, ['super_admin', 'roaster', 'admin1', 'admin2', 'admin3'])): ?>
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
                        <!-- New Shift Types link -->
                        <li class="nav-item">
                            <a class="nav-link" href="shift_types.php"><i class="fas fa-tags me-1"></i>Shift Types</a>
                        </li>
                        <?php if ($role == 'super_admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="logs.php"><i class="fas fa-history me-1"></i>System Logs</a>
                            </li>
                        <?php endif; ?>
                    <?php elseif ($isFinance): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="schedule.php"><i class="fas fa-calendar-check me-1"></i>View Schedule</a>
                        </li>
                    <?php endif; ?>

                    <!-- Shift Reports and Profit Dashboard - for super_admin and finance -->
                    <?php if ($role == 'super_admin' || $role == 'finance'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="finance_shifts.php"><i class="fas fa-file-csv me-1"></i>Shift Reports</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="profit.php"><i class="fas fa-chart-line me-1"></i>Profit Dashboard</a>
                        </li>
                    <?php endif; ?>
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

    <!-- Main content -->
    <main class="container-fluid px-md-4 py-4">
        <!-- Flash message -->
        <?php $flash = displayFlashMessage(); if ($flash): ?>
            <div class="row">
                <div class="col-12">
                    <?php echo $flash; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Page heading -->
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
            <h1 class="h2">Dashboard</h1>
        </div>

        <!-- Error display if DB failed -->
        <?php if (isset($db_error)): ?>
            <div class="alert alert-danger"><?php echo $db_error; ?></div>
        <?php endif; ?>

        <!-- Role‑specific statistics cards -->
        <div class="row">
            <?php if ($role == 'admin1'): ?>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card border-left-primary h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Pending Your Approval</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $pendingAdmin1; ?></div>
                                </div>
                                <div class="col-auto"><i class="fas fa-clock fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($role == 'admin2'): ?>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card border-left-success h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Pending Your Approval (after Admin1)</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $pendingAdmin2; ?></div>
                                </div>
                                <div class="col-auto"><i class="fas fa-check-double fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($role == 'admin3'): ?>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card border-left-warning h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending Final Approval</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $pendingAdmin3; ?></div>
                                </div>
                                <div class="col-auto"><i class="fas fa-check-circle fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($role == 'super_admin'): ?>
                <!-- Three cards for super admin -->
                <div class="col-xl-4 col-md-6 mb-4">
                    <div class="card stat-card border-left-primary h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Pending Admin1</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $pendingAdmin1; ?></div>
                                </div>
                                <div class="col-auto"><i class="fas fa-user-shield fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-md-6 mb-4">
                    <div class="card stat-card border-left-success h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Pending Admin2</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $pendingAdmin2; ?></div>
                                </div>
                                <div class="col-auto"><i class="fas fa-user-check fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-md-6 mb-4">
                    <div class="card stat-card border-left-warning h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Pending Admin3</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $pendingAdmin3; ?></div>
                                </div>
                                <div class="col-auto"><i class="fas fa-user-lock fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($role == 'finance'): ?>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card stat-card border-left-info h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Fully Approved Shifts (This Month)</div>
                                    <?php
                                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM shifts WHERE fully_approved = 1 AND MONTH(shift_date) = MONTH(CURDATE())");
                                    $stmt->execute();
                                    $monthCount = $stmt->fetchColumn();
                                    ?>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $monthCount; ?></div>
                                </div>
                                <div class="col-auto"><i class="fas fa-file-invoice-dollar fa-2x text-gray-300"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Filter Bar with searchable dropdowns -->
        <div class="filter-bar">
            <form method="get" action="index.php" class="row g-3">
                <div class="col-md-2">
                    <label for="nurse_id" class="form-label">Nurse</label>
                    <select name="nurse_id" id="nurse_id" class="form-select select2">
                        <option value="">All Nurses</option>
                        <?php foreach ($allNurses as $id => $name): ?>
                            <option value="<?php echo $id; ?>" <?php echo (isset($_GET['nurse_id']) && $_GET['nurse_id'] == $id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="patient_id" class="form-label">Patient</label>
                    <select name="patient_id" id="patient_id" class="form-select select2">
                        <option value="">All Patients</option>
                        <?php foreach ($allPatients as $id => $name): ?>
                            <option value="<?php echo $id; ?>" <?php echo (isset($_GET['patient_id']) && $_GET['patient_id'] == $id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="approval" class="form-label">Approval Status</label>
                    <select name="approval" id="approval" class="form-select">
                        <option value="all" <?php echo ($_GET['approval'] ?? 'all') == 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="pending" <?php echo ($_GET['approval'] ?? '') == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="partial" <?php echo ($_GET['approval'] ?? '') == 'partial' ? 'selected' : ''; ?>>Partially Approved</option>
                        <option value="approved" <?php echo ($_GET['approval'] ?? '') == 'approved' ? 'selected' : ''; ?>>Fully Approved</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="date_from" class="form-label">From</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'))); ?>">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">To</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($_GET['date_to'] ?? date('Y-m-d')); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2"><i class="fas fa-filter"></i> Filter</button>
                    <a href="index.php" class="btn btn-clear"><i class="fas fa-times"></i> Clear</a>
                </div>
            </form>
        </div>

        <!-- Shifts table with new Shift Type column -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Shifts (Filtered)</h6>
            </div>
            <div class="card-body">
                <?php if (empty($recentShifts)): ?>
                    <p class="text-muted">No shifts match your filters.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Nurse</th>
                                    <th>Patient</th>
                                    <th>Time</th>
                                    <th>Shift Type</th> <!-- New column -->
                                    <th>Approval Status</th>
                                    <?php if ($role != 'finance'): ?>
                                        <th>Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentShifts as $shift): ?>
                                    <tr data-shift='<?php echo htmlspecialchars(json_encode([
                                        'id' => $shift['id'],
                                        'nurse_id' => $shift['nurse_id'],
                                        'patient_id' => $shift['patient_id'],
                                        'shift_date' => $shift['shift_date'],
                                        'start_time' => $shift['start_time'],
                                        'end_time' => $shift['end_time'],
                                        'nurse_name' => $shift['nurse_name'],
                                        'patient_name' => $shift['patient_name'],
                                        'shift_type_name' => $shift['shift_type_name'],
                                        'admin1_approved' => (bool)$shift['admin1_approved'],
                                        'admin2_approved' => (bool)$shift['admin2_approved'],
                                        'admin3_approved' => (bool)$shift['admin3_approved'],
                                        'fully_approved' => (bool)$shift['fully_approved']
                                    ]), ENT_QUOTES, 'UTF-8'); ?>'>
                                        <td><?php echo formatDate($shift['shift_date']); ?></td>
                                        <td><?php echo htmlspecialchars($shift['nurse_name']); ?></td>
                                        <td><?php echo htmlspecialchars($shift['patient_name']); ?></td>
                                        <td><?php echo formatTime($shift['start_time']) . ' - ' . formatTime($shift['end_time']); ?></td>
                                        <td>
                                            <?php if (!empty($shift['shift_type_name'])): ?>
                                                <span class="shift-type-badge"><?php echo htmlspecialchars($shift['shift_type_name']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status = [];
                                            if ($shift['admin1_approved']) $status[] = 'Admin1✓';
                                            if ($shift['admin2_approved']) $status[] = 'Admin2✓';
                                            if ($shift['admin3_approved']) $status[] = 'Admin3✓';
                                            if (empty($status)) echo 'Pending';
                                            else echo implode(' ', $status);
                                            ?>
                                        </td>
                                        <?php if ($role != 'finance'): ?>
                                            <td>
                                                <i class="fas fa-pen action-btn" onclick="showShiftDetails(this)"></i>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($role == 'finance'): ?>
        <!-- Finance users have dedicated page – no modal here -->
        <?php endif; ?>
    </main>

    <!-- Shift Details Modal (updated to show shift type) -->
    <div class="modal fade" id="shiftDetailsModal" tabindex="-1" aria-labelledby="shiftDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="shiftDetailsModalLabel">Shift Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="shiftDetailsContent">
                    <!-- Populated by JavaScript -->
                </div>
                <div class="modal-footer" id="shiftDetailsFooter">
                    <!-- Buttons appear here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Shift Modal (unchanged) -->
    <div class="modal fade" id="editShiftModal" tabindex="-1" aria-labelledby="editShiftModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editShiftModalLabel">Edit Shift</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editShiftForm">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="mb-3">
                            <label for="edit_nurse_id" class="form-label">Nurse *</label>
                            <select class="form-select" id="edit_nurse_id" name="nurse_id" required>
                                <option value="">-- Select Nurse --</option>
                                <?php foreach ($allNurses as $id => $name): ?>
                                    <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_patient_id" class="form-label">Patient *</label>
                            <select class="form-select" id="edit_patient_id" name="patient_id" required>
                                <option value="">-- Select Patient --</option>
                                <?php foreach ($allPatients as $id => $name): ?>
                                    <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_shift_date" class="form-label">Date</label>
                            <input type="date" class="form-control" id="edit_shift_date" name="shift_date" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_start_time" class="form-label">Start Time</label>
                                <input type="time" class="form-control" id="edit_start_time" name="start_time" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_end_time" class="form-label">End Time</label>
                                <input type="time" class="form-control" id="edit_end_time" name="end_time" required>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveEditedShift()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery and Select2 JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Bootstrap JS bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize Select2 for nurse and patient dropdowns
        $(document).ready(function() {
            $('.select2').select2({
                placeholder: '-- Select --',
                allowClear: true,
                width: '100%'
            });
        });

        const role = '<?php echo $role; ?>';
        const canApprove = <?php echo $canApprove ? 'true' : 'false'; ?>;
        const canEdit = <?php echo $canEdit ? 'true' : 'false'; ?>;

        // Show shift details modal (updated to include shift type)
        function showShiftDetails(element) {
            const row = element.closest('tr');
            const shiftData = JSON.parse(row.getAttribute('data-shift'));

            const startTime = shiftData.start_time.substring(0,5);
            const endTime = shiftData.end_time.substring(0,5);
            const date = new Date(shiftData.shift_date).toLocaleDateString();

            let approvalHtml = '';
            if (shiftData.fully_approved) {
                approvalHtml = '<span class="badge bg-success">Fully Approved</span>';
            } else {
                const steps = [];
                if (shiftData.admin1_approved) steps.push('✓ Admin1');
                if (shiftData.admin2_approved) steps.push('✓ Admin2');
                if (shiftData.admin3_approved) steps.push('✓ Admin3');
                approvalHtml = steps.length ? steps.join(' ') : '<span class="badge bg-warning">Pending</span>';
            }

            // Include shift type in details
            const shiftTypeHtml = shiftData.shift_type_name
                ? `<p><strong>Shift Type:</strong> ${escapeHtml(shiftData.shift_type_name)}</p>`
                : '';

            document.getElementById('shiftDetailsContent').innerHTML = `
                <p><strong>Nurse:</strong> ${escapeHtml(shiftData.nurse_name)}</p>
                <p><strong>Patient:</strong> ${escapeHtml(shiftData.patient_name)}</p>
                ${shiftTypeHtml}
                <p><strong>Date:</strong> ${date}</p>
                <p><strong>Time:</strong> ${startTime} – ${endTime}</p>
                <p><strong>Approval:</strong> ${approvalHtml}</p>
            `;

            let footerHtml = '';

            // Approve/Unapprove buttons (only if canApprove)
            if (canApprove) {
                // Admin1
                if (role === 'admin1' && !shiftData.admin1_approved) {
                    footerHtml += `<button class="btn btn-success" onclick="approveShift(${shiftData.id}, 'admin1')">Approve as Admin1</button> `;
                }
                if (role === 'admin1' && shiftData.admin1_approved && !shiftData.admin2_approved && !shiftData.admin3_approved) {
                    footerHtml += `<button class="btn btn-warning" onclick="unapproveShift(${shiftData.id}, 'admin1')">Unapprove as Admin1</button> `;
                }
                // Admin2
                if (role === 'admin2' && shiftData.admin1_approved && !shiftData.admin2_approved) {
                    footerHtml += `<button class="btn btn-success" onclick="approveShift(${shiftData.id}, 'admin2')">Approve as Admin2</button> `;
                }
                if (role === 'admin2' && shiftData.admin2_approved && !shiftData.admin3_approved) {
                    footerHtml += `<button class="btn btn-warning" onclick="unapproveShift(${shiftData.id}, 'admin2')">Unapprove as Admin2</button> `;
                }
                // Admin3
                if (role === 'admin3' && shiftData.admin1_approved && shiftData.admin2_approved && !shiftData.admin3_approved) {
                    footerHtml += `<button class="btn btn-success" onclick="approveShift(${shiftData.id}, 'admin3')">Approve as Admin3</button> `;
                }
                if (role === 'admin3' && shiftData.admin3_approved) {
                    footerHtml += `<button class="btn btn-warning" onclick="unapproveShift(${shiftData.id}, 'admin3')">Unapprove as Admin3</button> `;
                }
                // Super admin
                if (role === 'super_admin') {
                    if (!shiftData.admin1_approved) {
                        footerHtml += `<button class="btn btn-success" onclick="approveShift(${shiftData.id}, 'admin1')">Approve Admin1</button> `;
                    }
                    if (shiftData.admin1_approved && !shiftData.admin2_approved) {
                        footerHtml += `<button class="btn btn-success" onclick="approveShift(${shiftData.id}, 'admin2')">Approve Admin2</button> `;
                    }
                    if (shiftData.admin1_approved && shiftData.admin2_approved && !shiftData.admin3_approved) {
                        footerHtml += `<button class="btn btn-success" onclick="approveShift(${shiftData.id}, 'admin3')">Approve Admin3</button> `;
                    }
                    if (shiftData.admin1_approved && !shiftData.admin2_approved && !shiftData.admin3_approved) {
                        footerHtml += `<button class="btn btn-warning" onclick="unapproveShift(${shiftData.id}, 'admin1')">Unapprove Admin1</button> `;
                    }
                    if (shiftData.admin2_approved && !shiftData.admin3_approved) {
                        footerHtml += `<button class="btn btn-warning" onclick="unapproveShift(${shiftData.id}, 'admin2')">Unapprove Admin2</button> `;
                    }
                    if (shiftData.admin3_approved) {
                        footerHtml += `<button class="btn btn-warning" onclick="unapproveShift(${shiftData.id}, 'admin3')">Unapprove Admin3</button> `;
                    }
                }
            }

            // Edit and Delete buttons only for canEdit (super_admin and roaster)
            if (canEdit) {
                footerHtml += `<button class="btn btn-primary" onclick="openEditModal(${shiftData.id})">Edit</button> `;
                footerHtml += `<button class="btn btn-danger" onclick="deleteShift(${shiftData.id})">Delete</button> `;
            }

            footerHtml += `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>`;
            document.getElementById('shiftDetailsFooter').innerHTML = footerHtml;

            new bootstrap.Modal(document.getElementById('shiftDetailsModal')).show();
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function approveShift(shiftId, level) {
            fetch('api/approve_shift.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: shiftId, level: level })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) location.reload();
                else alert('Error: ' + result.error);
            })
            .catch(err => alert('Network error'));
        }

        function unapproveShift(shiftId, level) {
            fetch('api/approve_shift.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: shiftId, level: level, unapprove: true })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) location.reload();
                else alert('Error: ' + result.error);
            })
            .catch(err => alert('Network error'));
        }

        function openEditModal(shiftId) {
            const row = document.querySelector(`tr[data-shift*='"id":${shiftId}']`);
            if (!row) return;
            const shiftData = JSON.parse(row.getAttribute('data-shift'));

            document.getElementById('edit_id').value = shiftData.id;
            document.getElementById('edit_nurse_id').value = shiftData.nurse_id;
            document.getElementById('edit_patient_id').value = shiftData.patient_id;
            document.getElementById('edit_shift_date').value = shiftData.shift_date;
            document.getElementById('edit_start_time').value = shiftData.start_time.substring(0,5);
            document.getElementById('edit_end_time').value = shiftData.end_time.substring(0,5);

            bootstrap.Modal.getInstance(document.getElementById('shiftDetailsModal')).hide();
            new bootstrap.Modal(document.getElementById('editShiftModal')).show();
        }

        function saveEditedShift() {
            const data = {
                id: document.getElementById('edit_id').value,
                nurse_id: document.getElementById('edit_nurse_id').value,
                patient_id: document.getElementById('edit_patient_id').value,
                shift_date: document.getElementById('edit_shift_date').value,
                start_time: document.getElementById('edit_start_time').value,
                end_time: document.getElementById('edit_end_time').value
            };

            fetch('api/save_shift.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) location.reload();
                else alert('Error: ' + result.error);
            })
            .catch(err => alert('Network error'));
        }

        function deleteShift(shiftId) {
            if (!confirm('Are you sure you want to delete this shift?')) return;
            fetch('api/save_shift.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: shiftId, delete: true })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) location.reload();
                else alert('Error: ' + result.error);
            })
            .catch(err => alert('Network error'));
        }
    </script>
</body>
</html>