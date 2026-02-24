<?php
/**
 * VitalZone - Manage Shifts
 * CRUD operations for shifts with filters (nurse, patient, date range, approval).
 * Supports adding multiple shifts at once, import/export.
 * Now includes shift type selection and display.
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Allowed roles: super_admin, roaster, admin1, admin2, admin3
requireRole(['super_admin', 'roaster', 'admin1', 'admin2', 'admin3']);

$role = getRole();
$isSuperAdmin = ($role == 'super_admin');

// Get lists for dropdowns
$nursesList = getNursesList($pdo);
$patientsList = getPatientsList($pdo);
$shiftTypes = $pdo->query("SELECT id, type_name, color FROM shift_types ORDER BY type_name")->fetchAll();

// Filters
$nurseFilter   = isset($_GET['nurse_id']) && $_GET['nurse_id'] !== '' ? (int)$_GET['nurse_id'] : null;
$patientFilter = isset($_GET['patient_id']) && $_GET['patient_id'] !== '' ? (int)$_GET['patient_id'] : null;
$approvalFilter = $_GET['approval'] ?? 'all';
$dateFrom      = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo        = $_GET['date_to'] ?? date('Y-m-d');

// Handle POST actions (delete, add_multiple, edit, import)
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Delete shift
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM shifts WHERE id = ?");
            $stmt->execute([$id]);
            logAction($pdo, $_SESSION['user_id'], 'DELETE', 'shifts', $id, "Deleted shift ID $id");
            redirectWithMessage('manage_shifts.php', 'Shift deleted successfully.', 'success');
        } catch (PDOException $e) {
            redirectWithMessage('manage_shifts.php', 'Error deleting shift: ' . $e->getMessage(), 'error');
        }
    }

    // Add multiple shifts (if nurse_id[] etc. are arrays)
    if (isset($_POST['action']) && $_POST['action'] === 'add_multiple' && isset($_POST['nurse_id']) && is_array($_POST['nurse_id'])) {
        $nurseIds = $_POST['nurse_id'];
        $patientIds = $_POST['patient_id'] ?? [];
        $shiftTypeIds = $_POST['shift_type_id'] ?? [];
        $dates = $_POST['shift_date'] ?? [];
        $startTimes = $_POST['start_time'] ?? [];
        $endTimes = $_POST['end_time'] ?? [];

        $inserted = 0;
        $errors = [];
        for ($i = 0; $i < count($nurseIds); $i++) {
            $nurseId = !empty($nurseIds[$i]) ? (int)$nurseIds[$i] : 0;
            $patientId = !empty($patientIds[$i]) ? (int)$patientIds[$i] : 0;
            $shiftTypeId = !empty($shiftTypeIds[$i]) ? (int)$shiftTypeIds[$i] : null;
            $date = trim($dates[$i] ?? '');
            $start = trim($startTimes[$i] ?? '');
            $end = trim($endTimes[$i] ?? '');

            if (!$nurseId || !$patientId || !$date || !$start || !$end) continue;

            // Add seconds for database
            $start .= ':00';
            $end .= ':00';

            try {
                $stmt = $pdo->prepare("INSERT INTO shifts (nurse_id, patient_id, shift_date, start_time, end_time, shift_type_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nurseId, $patientId, $date, $start, $end, $shiftTypeId]);
                $inserted++;
            } catch (PDOException $e) {
                $errors[] = "Row $i: " . $e->getMessage();
            }
        }
        if ($inserted > 0) {
            $message = "$inserted shifts added successfully.";
            logAction($pdo, $_SESSION['user_id'], 'INSERT_MULTIPLE', 'shifts', null, "Added $inserted shifts");
        }
        if (!empty($errors)) {
            $error = implode('<br>', $errors);
        }
        if ($inserted == 0 && empty($errors)) {
            $error = 'No valid entries found.';
        }
        // Stay on same page to show messages
    }

    // Edit single shift
    if (isset($_POST['action']) && $_POST['action'] === 'edit' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $nurseId = (int)$_POST['nurse_id'];
        $patientId = (int)$_POST['patient_id'];
        $shiftTypeId = !empty($_POST['shift_type_id']) ? (int)$_POST['shift_type_id'] : null;
        $date = trim($_POST['shift_date'] ?? '');
        $start = trim($_POST['start_time'] ?? '');
        $end = trim($_POST['end_time'] ?? '');

        if (!$nurseId || !$patientId || !$date || !$start || !$end) {
            $error = 'All fields are required.';
        } else {
            $start .= ':00';
            $end .= ':00';
            $stmt = $pdo->prepare("UPDATE shifts SET nurse_id = ?, patient_id = ?, shift_date = ?, start_time = ?, end_time = ?, shift_type_id = ? WHERE id = ?");
            try {
                $stmt->execute([$nurseId, $patientId, $date, $start, $end, $shiftTypeId, $id]);
                logAction($pdo, $_SESSION['user_id'], 'UPDATE', 'shifts', $id, "Updated shift ID $id");
                redirectWithMessage('manage_shifts.php', 'Shift updated successfully.', 'success');
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }

    // CSV IMPORT
    if (isset($_POST['action']) && $_POST['action'] === 'import' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'File upload error.';
        } elseif ($file['type'] !== 'text/csv' && pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
            $error = 'Only CSV files are allowed.';
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle) {
                $header = fgetcsv($handle);
                $expected = ['nurse_name', 'patient_name', 'shift_date', 'start_time', 'end_time', 'shift_type'];
                $headerLower = array_map('strtolower', $header);
                $missing = array_diff($expected, $headerLower);
                if (!empty($missing)) {
                    $error = 'CSV must have headers: ' . implode(', ', $expected);
                } else {
                    $imported = 0;
                    $errors = [];
                    $rowNum = 2;
                    while (($row = fgetcsv($handle)) !== false) {
                        if (count(array_filter($row)) == 0) continue;
                        $data = array_combine($headerLower, array_pad($row, count($headerLower), ''));
                        $nurseName = trim($data['nurse_name'] ?? '');
                        $patientName = trim($data['patient_name'] ?? '');
                        $shiftTypeName = trim($data['shift_type'] ?? '');
                        $date = trim($data['shift_date'] ?? '');
                        $start = trim($data['start_time'] ?? '');
                        $end = trim($data['end_time'] ?? '');

                        if (!$nurseName || !$patientName || !$date || !$start || !$end) {
                            $errors[] = "Row $rowNum: missing required fields.";
                            $rowNum++;
                            continue;
                        }

                        // Look up nurse ID
                        $stmt = $pdo->prepare("SELECT id FROM nurses WHERE name = ?");
                        $stmt->execute([$nurseName]);
                        $nurse = $stmt->fetch();
                        if (!$nurse) {
                            $errors[] = "Row $rowNum: nurse '$nurseName' not found.";
                            $rowNum++;
                            continue;
                        }
                        $nurseId = $nurse['id'];

                        // Look up patient ID
                        $stmt = $pdo->prepare("SELECT id FROM patients WHERE name = ?");
                        $stmt->execute([$patientName]);
                        $patient = $stmt->fetch();
                        if (!$patient) {
                            $errors[] = "Row $rowNum: patient '$patientName' not found.";
                            $rowNum++;
                            continue;
                        }
                        $patientId = $patient['id'];

                        // Look up shift type ID if provided
                        $shiftTypeId = null;
                        if ($shiftTypeName) {
                            $stmt = $pdo->prepare("SELECT id FROM shift_types WHERE type_name = ?");
                            $stmt->execute([$shiftTypeName]);
                            $st = $stmt->fetch();
                            if ($st) {
                                $shiftTypeId = $st['id'];
                            } else {
                                $errors[] = "Row $rowNum: shift type '$shiftTypeName' not found.";
                                $rowNum++;
                                continue;
                            }
                        }

                        $start .= ':00';
                        $end .= ':00';

                        try {
                            $stmt = $pdo->prepare("INSERT INTO shifts (nurse_id, patient_id, shift_date, start_time, end_time, shift_type_id) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$nurseId, $patientId, $date, $start, $end, $shiftTypeId]);
                            $imported++;
                        } catch (PDOException $e) {
                            $errors[] = "Row $rowNum: database error - " . $e->getMessage();
                        }
                        $rowNum++;
                    }
                    fclose($handle);
                    if ($imported > 0) {
                        $message = "$imported shifts imported successfully.";
                        logAction($pdo, $_SESSION['user_id'], 'IMPORT', 'shifts', null, "Imported $imported shifts via CSV");
                    }
                    if (!empty($errors)) {
                        $error = implode('<br>', $errors);
                    }
                    if ($imported == 0 && empty($errors)) {
                        $error = 'No valid rows found in CSV.';
                    }
                }
            } else {
                $error = 'Could not open uploaded file.';
            }
        }
    }
}

// Build main query with filters
$sql = "SELECT s.*,
               n.name AS nurse_name,
               p.name AS patient_name,
               st.type_name AS shift_type_name,
               st.color AS shift_type_color
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

$sql .= " ORDER BY s.shift_date DESC, s.start_time";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shifts = $stmt->fetchAll();

// Determine if we are editing a specific shift
$editShift = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM shifts WHERE id = ?");
    $stmt->execute([$id]);
    $editShift = $stmt->fetch();
    if (!$editShift) {
        redirectWithMessage('manage_shifts.php', 'Shift not found.', 'error');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VitalZone - Manage Shifts</title>
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
        .import-card { background-color: #e9ecef; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; }
        .filter-bar { background: white; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .btn-clear { background-color: #6c757d; color: white; }
        .btn-clear:hover { background-color: #5a6268; }
        .multi-row { margin-bottom: 1rem; border-bottom: 1px dashed #ccc; padding-bottom: 1rem; }
        .remove-row { color: #dc3545; cursor: pointer; margin-top: 2rem; }
        .select2-container { width: 100% !important; }
        .shift-type-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            font-weight: 600;
            color: #fff;
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
                        <a class="nav-link active" href="manage_shifts.php"><i class="fas fa-list me-1"></i>Manage Shifts</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="groups.php"><i class="fas fa-users-cog me-1"></i>Employee Groups</a>
                    </li>
                    <!-- Shift Types link -->
                    <li class="nav-item">
                        <a class="nav-link" href="shift_types.php"><i class="fas fa-tags me-1"></i>Shift Types</a>
                    </li>
                    <?php if ($role == 'super_admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="finance_shifts.php"><i class="fas fa-file-csv me-1"></i>Shift Reports</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="profit.php"><i class="fas fa-chart-line me-1"></i>Profit Dashboard</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="logs.php"><i class="fas fa-history me-1"></i>System Logs</a>
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
            <div class="row"><div class="col-12"><?php echo $flash; ?></div></div>
        <?php endif; ?>

        <!-- Page header -->
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
            <h1 class="h2">Manage Shifts</h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <a href="api/export_shifts.php?<?php echo http_build_query($_GET); ?>" class="btn btn-sm btn-success me-2">
                    <i class="fas fa-download"></i> Export CSV
                </a>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#shiftModal" onclick="clearForm()">
                    <i class="fas fa-plus"></i> Add New Shift
                </button>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="get" action="manage_shifts.php" class="row g-3">
                <div class="col-md-2">
                    <label for="nurse_id" class="form-label">Nurse</label>
                    <select name="nurse_id" id="nurse_id" class="form-select select2">
                        <option value="">All Nurses</option>
                        <?php foreach ($nursesList as $id => $name): ?>
                            <option value="<?php echo $id; ?>" <?php echo ($nurseFilter == $id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="patient_id" class="form-label">Patient</label>
                    <select name="patient_id" id="patient_id" class="form-select select2">
                        <option value="">All Patients</option>
                        <?php foreach ($patientsList as $id => $name): ?>
                            <option value="<?php echo $id; ?>" <?php echo ($patientFilter == $id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="approval" class="form-label">Approval</label>
                    <select name="approval" id="approval" class="form-select">
                        <option value="all" <?php echo ($approvalFilter == 'all') ? 'selected' : ''; ?>>All</option>
                        <option value="pending" <?php echo ($approvalFilter == 'pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="partial" <?php echo ($approvalFilter == 'partial') ? 'selected' : ''; ?>>Partially Approved</option>
                        <option value="approved" <?php echo ($approvalFilter == 'approved') ? 'selected' : ''; ?>>Fully Approved</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="date_from" class="form-label">From</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">To</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2"><i class="fas fa-filter"></i> Filter</button>
                    <a href="manage_shifts.php" class="btn btn-clear"><i class="fas fa-times"></i> Clear</a>
                </div>
            </form>
        </div>

        <!-- Import CSV Section -->
        <div class="import-card">
            <h5><i class="fas fa-file-import"></i> Import Shifts from CSV</h5>
            <p class="text-muted">Upload a CSV file with columns: <code>nurse_name, patient_name, shift_date, start_time, end_time, shift_type</code>. Shift type is optional (type name). The first row must be the header.</p>
            <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                <input type="hidden" name="action" value="import">
                <div class="col-auto">
                    <label for="csv_file" class="form-label">Choose CSV file</label>
                    <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-success"><i class="fas fa-upload"></i> Import</button>
                </div>
                <div class="col-auto">
                    <a href="data:text/csv;charset=utf-8,nurse_name,patient_name,shift_date,start_time,end_time,shift_type%0AJohn%20Doe,Jane%20Smith,2025-03-01,07:00,19:00,Morning%0AJane%20Smith,John%20Doe,2025-03-02,19:00,07:00,Night"
                       download="shift_template.csv" class="btn btn-outline-secondary">
                        <i class="fas fa-download"></i> Download Template
                    </a>
                </div>
            </form>
            <?php if ($error): ?>
                <div class="alert alert-danger mt-3"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($message): ?>
                <div class="alert alert-success mt-3"><?php echo $message; ?></div>
            <?php endif; ?>
        </div>

        <!-- Shifts table -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">All Shifts (<?php echo count($shifts); ?>)</h6>
            </div>
            <div class="card-body">
                <?php if (empty($shifts)): ?>
                    <p class="text-muted">No shifts match the filters.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Date</th>
                                    <th>Nurse</th>
                                    <th>Patient</th>
                                    <th>Start</th>
                                    <th>End</th>
                                    <th>Shift Type</th>
                                    <th>Approval</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shifts as $shift):
                                    $start = date('g:i A', strtotime($shift['start_time']));
                                    $end = date('g:i A', strtotime($shift['end_time']));
                                    $approval = [];
                                    if ($shift['admin1_approved']) $approval[] = 'Admin1✓';
                                    if ($shift['admin2_approved']) $approval[] = 'Admin2✓';
                                    if ($shift['admin3_approved']) $approval[] = 'Admin3✓';
                                    $approvalText = empty($approval) ? 'Pending' : implode(' ', $approval);
                                ?>
                                    <tr>
                                        <td><?php echo $shift['id']; ?></td>
                                        <td><?php echo formatDate($shift['shift_date']); ?></td>
                                        <td><?php echo htmlspecialchars($shift['nurse_name']); ?></td>
                                        <td><?php echo htmlspecialchars($shift['patient_name']); ?></td>
                                        <td><?php echo $start; ?></td>
                                        <td><?php echo $end; ?></td>
                                        <td>
                                            <?php if (!empty($shift['shift_type_name'])):
                                                $bgColor = $shift['shift_type_color'] ?? '#0d6efd';
                                                $hex = ltrim($bgColor, '#');
                                                $r = hexdec(substr($hex,0,2));
                                                $g = hexdec(substr($hex,2,2));
                                                $b = hexdec(substr($hex,4,2));
                                                $luminance = (0.299*$r + 0.587*$g + 0.114*$b)/255;
                                                $textColor = $luminance > 0.5 ? '#000000' : '#ffffff';
                                            ?>
                                                <span class="shift-type-badge" style="background-color: <?php echo $bgColor; ?>; color: <?php echo $textColor; ?>;">
                                                    <?php echo htmlspecialchars($shift['shift_type_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $approvalText; ?></td>
                                        <td>
                                            <a href="?edit=<?php echo $shift['id']; ?>" class="btn btn-sm btn-primary" onclick='loadEditData(<?php echo json_encode($shift); ?>); return false;'>
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this shift?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $shift['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add/Edit Shift Modal (multi-add capable) -->
        <div class="modal fade" id="shiftModal" tabindex="-1" aria-labelledby="shiftModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="post" action="manage_shifts.php" id="multiShiftForm">
                        <div class="modal-header">
                            <h5 class="modal-title" id="shiftModalLabel">Add New Shift</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="action" id="action" value="add_multiple">
                            <input type="hidden" name="id" id="shiftId" value="">

                            <div id="shift-rows-container">
                                <!-- First row (will be cloned) -->
                                <div class="row multi-row">
                                    <div class="col-md-3 mb-2">
                                        <select class="form-select select2-modal" name="nurse_id[]" required>
                                            <option value="">Select Nurse *</option>
                                            <?php foreach ($nursesList as $id => $name): ?>
                                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <select class="form-select select2-modal" name="patient_id[]" required>
                                            <option value="">Select Patient *</option>
                                            <?php foreach ($patientsList as $id => $name): ?>
                                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <input type="date" class="form-control" name="shift_date[]" required>
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <input type="time" class="form-control" name="start_time[]" required>
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <input type="time" class="form-control" name="end_time[]" required>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <select class="form-select select2-modal" name="shift_type_id[]">
                                            <option value="">-- No Type --</option>
                                            <?php foreach ($shiftTypes as $type): ?>
                                                <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['type_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-1 mb-2">
                                        <i class="fas fa-times remove-row" style="display:none;"></i>
                                    </div>
                                </div>
                            </div>

                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="addAnotherRow">
                                <i class="fas fa-plus"></i> Add another shift
                            </button>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Shifts</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <!-- jQuery and Select2 JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Bootstrap JS bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize Select2 for filter bar
        $(document).ready(function() {
            $('.select2').select2({
                placeholder: 'All',
                allowClear: true,
                width: '100%'
            });
        });

        // Data for edit
        const nurses = <?php echo json_encode($nursesList); ?>;
        const patients = <?php echo json_encode($patientsList); ?>;
        const shiftTypes = <?php echo json_encode($shiftTypes); ?>;

        // Handle adding multiple rows
        document.getElementById('addAnotherRow').addEventListener('click', function() {
            let container = document.getElementById('shift-rows-container');
            let firstRow = container.querySelector('.multi-row');
            let newRow = firstRow.cloneNode(true);

            // Clear select values (Select2 needs to be destroyed and reinitialized)
            $(newRow).find('select').each(function() {
                if ($(this).hasClass('select2-hidden-accessible')) {
                    $(this).select2('destroy');
                }
                this.selectedIndex = 0;
            });
            // Clear other inputs
            newRow.querySelectorAll('input[type="date"], input[type="time"]').forEach(input => input.value = '');
            // Show remove icon
            let removeIcon = newRow.querySelector('.remove-row');
            if (removeIcon) {
                removeIcon.style.display = 'inline-block';
                removeIcon.onclick = function() { this.closest('.multi-row').remove(); };
            }
            container.appendChild(newRow);

            // Reinitialize Select2 on new selects (with dropdownParent set to modal)
            $(newRow).find('select.select2-modal').select2({
                dropdownParent: $('#shiftModal'),
                width: '100%'
            });
        });

        // For editing a single shift
        function loadEditData(shift) {
            document.getElementById('action').value = 'edit';
            document.getElementById('shiftId').value = shift.id;

            let container = document.getElementById('shift-rows-container');
            // Destroy any existing Select2 instances in container
            $(container).find('select.select2-modal').each(function() {
                if ($(this).hasClass('select2-hidden-accessible')) {
                    $(this).select2('destroy');
                }
            });
            container.innerHTML = ''; // clear all rows

            // Create a single row for editing
            let rowDiv = document.createElement('div');
            rowDiv.className = 'row multi-row';
            rowDiv.innerHTML = `
                <div class="col-md-3 mb-2">
                    <select class="form-select select2-modal" name="nurse_id" required>
                        <option value="">Select Nurse *</option>
                        <?php foreach ($nursesList as $id => $name): ?>
                            <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-2">
                    <select class="form-select select2-modal" name="patient_id" required>
                        <option value="">Select Patient *</option>
                        <?php foreach ($patientsList as $id => $name): ?>
                            <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 mb-2">
                    <input type="date" class="form-control" name="shift_date" value="${shift.shift_date}" required>
                </div>
                <div class="col-md-2 mb-2">
                    <input type="time" class="form-control" name="start_time" value="${shift.start_time.substring(0,5)}" required>
                </div>
                <div class="col-md-2 mb-2">
                    <input type="time" class="form-control" name="end_time" value="${shift.end_time.substring(0,5)}" required>
                </div>
                <div class="col-md-3 mb-2">
                    <select class="form-select select2-modal" name="shift_type_id">
                        <option value="">-- No Type --</option>
                        <?php foreach ($shiftTypes as $type): ?>
                            <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['type_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1 mb-2">
                    <!-- no remove icon -->
                </div>
            `;
            container.appendChild(rowDiv);

            // Set selected values
            $(rowDiv).find('select[name="nurse_id"]').val(shift.nurse_id);
            $(rowDiv).find('select[name="patient_id"]').val(shift.patient_id);
            $(rowDiv).find('select[name="shift_type_id"]').val(shift.shift_type_id || '');

            // Reinitialize Select2 on the new selects
            $(rowDiv).find('select.select2-modal').select2({
                dropdownParent: $('#shiftModal'),
                width: '100%'
            });

            // Hide the "Add another" button during edit
            document.getElementById('addAnotherRow').style.display = 'none';

            document.getElementById('shiftModalLabel').innerText = 'Edit Shift';
            var modal = new bootstrap.Modal(document.getElementById('shiftModal'));
            modal.show();
        }

        // Clear form for adding new shifts (multiple)
        function clearForm() {
            document.getElementById('action').value = 'add_multiple';
            document.getElementById('shiftId').value = '';
            document.getElementById('addAnotherRow').style.display = 'inline-block';

            let container = document.getElementById('shift-rows-container');
            // Destroy existing Select2
            $(container).find('select.select2-modal').each(function() {
                if ($(this).hasClass('select2-hidden-accessible')) {
                    $(this).select2('destroy');
                }
            });
            container.innerHTML = ''; // clear all rows

            // Create a fresh first row
            let firstRow = document.createElement('div');
            firstRow.className = 'row multi-row';
            firstRow.innerHTML = `
                <div class="col-md-3 mb-2">
                    <select class="form-select select2-modal" name="nurse_id[]" required>
                        <option value="">Select Nurse *</option>
                        <?php foreach ($nursesList as $id => $name): ?>
                            <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-2">
                    <select class="form-select select2-modal" name="patient_id[]" required>
                        <option value="">Select Patient *</option>
                        <?php foreach ($patientsList as $id => $name): ?>
                            <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 mb-2">
                    <input type="date" class="form-control" name="shift_date[]" required>
                </div>
                <div class="col-md-2 mb-2">
                    <input type="time" class="form-control" name="start_time[]" required>
                </div>
                <div class="col-md-2 mb-2">
                    <input type="time" class="form-control" name="end_time[]" required>
                </div>
                <div class="col-md-3 mb-2">
                    <select class="form-select select2-modal" name="shift_type_id[]">
                        <option value="">-- No Type --</option>
                        <?php foreach ($shiftTypes as $type): ?>
                            <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['type_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1 mb-2">
                    <i class="fas fa-times remove-row" style="display:none;"></i>
                </div>
            `;
            container.appendChild(firstRow);

            // Initialize Select2 on the first row
            $(firstRow).find('select.select2-modal').select2({
                dropdownParent: $('#shiftModal'),
                width: '100%'
            });

            document.getElementById('shiftModalLabel').innerText = 'Add New Shift';
        }

        // If URL has ?edit=ID, trigger the edit modal automatically
        window.onload = function() {
            <?php if ($editShift): ?>
                loadEditData(<?php echo json_encode($editShift); ?>);
            <?php endif; ?>
        };
    </script>
</body>
</html>