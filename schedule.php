<?php
/**
 * VitalZone - Enhanced Schedule Board
 * Features: drag-drop, copy-paste, context menu, selection, 24h limit, conflict detection,
 *           patient double-booking detection with override option.
 * Now supports toggling between Employee view (nurses) and Patient view.
 * Add Shift modal now uses Select2 for searchable dropdowns.
 * Role-based permissions:
 *   - super_admin, roaster: full editing (create, edit, delete, approve)
 *   - admin1, admin2, admin3: read-only calendar, approve/unapprove via modal
 *   - finance: read-only calendar (no editing, no approve)
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Allowed roles – now includes finance
requireRole(['super_admin', 'roaster', 'admin1', 'admin2', 'admin3', 'finance']);

$role = getRole();
$canEdit = in_array($role, ['super_admin', 'roaster']);
$canApprove = in_array($role, ['super_admin', 'roaster', 'admin1', 'admin2', 'admin3']);
$isFinance = ($role == 'finance');

// View mode (day/week/etc.) and view by (employee/patient)
$view = isset($_GET['view']) ? $_GET['view'] : 'week';
$viewBy = isset($_GET['view_by']) ? $_GET['view_by'] : 'employee';
$startParam = isset($_GET['start']) ? $_GET['start'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'name';

// ----- Normalize and validate start date using DateTime -----
$startDate = null;
if ($startParam && DateTime::createFromFormat('Y-m-d', $startParam) !== false) {
    $startDate = new DateTime($startParam);
}

// If no valid start date, set default based on view
if (!$startDate) {
    $startDate = new DateTime();
    switch ($view) {
        case 'day':
            // keep today
            break;
        case 'week':
            $startDate->modify('monday this week');
            break;
        case '2weeks':
            $startDate->modify('monday this week');
            break;
        case 'month':
            $startDate->modify('first day of this month');
            break;
        default:
            $startDate->modify('monday this week');
    }
}

// Force correct start for each view
switch ($view) {
    case 'week':
    case '2weeks':
        // Ensure start is Monday
        if ($startDate->format('N') != 1) { // 1 = Monday
            $startDate->modify('last monday');
        }
        break;
    case 'month':
        // Ensure start is first day of month
        $startDate->modify('first day of this month');
        break;
}

// Calculate end date based on view
$endDate = clone $startDate;
switch ($view) {
    case 'day':
        // end same as start
        break;
    case 'week':
        $endDate->modify('+6 days');
        break;
    case '2weeks':
        $endDate->modify('+13 days');
        break;
    case 'month':
        $endDate->modify('last day of this month');
        break;
    default:
        $endDate->modify('+6 days');
}

$start = $startDate->format('Y-m-d');
$end = $endDate->format('Y-m-d');

// Date title for display
switch ($view) {
    case 'day':
        $dateTitle = $startDate->format('M j, Y');
        break;
    case 'week':
        $dateTitle = $startDate->format('M j') . ' – ' . $endDate->format('M j, Y');
        break;
    case '2weeks':
        $dateTitle = $startDate->format('M j') . ' – ' . $endDate->format('M j, Y');
        break;
    case 'month':
        $dateTitle = $startDate->format('F Y');
        break;
    default:
        $dateTitle = $startDate->format('M j') . ' – ' . $endDate->format('M j, Y');
}

// Generate list of days
$days = [];
$current = clone $startDate;
while ($current <= $endDate) {
    $days[] = [
        'date' => $current->format('Y-m-d'),
        'dayName' => $current->format('D'),
        'dayNum' => $current->format('j'),
        'month' => $current->format('M')
    ];
    $current->modify('+1 day');
}

// ----- Navigation links using DateTime (robust) -----
$prevLink = $nextLink = '';
$prevDate = clone $startDate;
$nextDate = clone $startDate;

switch ($view) {
    case 'day':
        $prevDate->modify('-1 day');
        $nextDate->modify('+1 day');
        $prevLink = '?view=day&view_by=' . $viewBy . '&start=' . $prevDate->format('Y-m-d');
        $nextLink = '?view=day&view_by=' . $viewBy . '&start=' . $nextDate->format('Y-m-d');
        break;
    case 'week':
        $prevDate->modify('-7 days');
        $nextDate->modify('+7 days');
        // Ensure both are Mondays
        if ($prevDate->format('N') != 1) $prevDate->modify('last monday');
        if ($nextDate->format('N') != 1) $nextDate->modify('last monday');
        $prevLink = '?view=week&view_by=' . $viewBy . '&start=' . $prevDate->format('Y-m-d');
        $nextLink = '?view=week&view_by=' . $viewBy . '&start=' . $nextDate->format('Y-m-d');
        break;
    case '2weeks':
        $prevDate->modify('-14 days');
        $nextDate->modify('+14 days');
        // Ensure both are Mondays
        if ($prevDate->format('N') != 1) $prevDate->modify('last monday');
        if ($nextDate->format('N') != 1) $nextDate->modify('last monday');
        $prevLink = '?view=2weeks&view_by=' . $viewBy . '&start=' . $prevDate->format('Y-m-d');
        $nextLink = '?view=2weeks&view_by=' . $viewBy . '&start=' . $nextDate->format('Y-m-d');
        break;
    case 'month':
        $prevDate->modify('first day of last month');
        $nextDate->modify('first day of next month');
        $prevLink = '?view=month&view_by=' . $viewBy . '&start=' . $prevDate->format('Y-m-d');
        $nextLink = '?view=month&view_by=' . $viewBy . '&start=' . $nextDate->format('Y-m-d');
        break;
}

// Fetch data based on viewBy
if ($viewBy == 'patient') {
    $rows = $pdo->query("SELECT * FROM patients ORDER BY name")->fetchAll();
    $rowType = 'patient';
    $rowIdField = 'id';
    $rowNameField = 'name';
    $rowLabel = 'Patient';
} else {
    $rows = $pdo->query("
        SELECT n.*, eg.group_name
        FROM nurses n
        LEFT JOIN employee_groups eg ON n.group_id = eg.id
        ORDER BY n.name
    ")->fetchAll();
    $rowType = 'nurse';
    $rowIdField = 'id';
    $rowNameField = 'name';
    $rowLabel = 'Employee';
}

// Fetch all shift types for dropdowns
$shiftTypes = $pdo->query("SELECT id, type_name, color FROM shift_types ORDER BY type_name")->fetchAll();

// Fetch all shifts in the range, now including shift type info and color
$stmt = $pdo->prepare("
    SELECT s.*,
           n.name AS nurse_name,
           p.name AS patient_name,
           st.type_name AS shift_type_name,
           st.id AS shift_type_id,
           st.color AS shift_type_color
    FROM shifts s
    JOIN nurses n ON s.nurse_id = n.id
    JOIN patients p ON s.patient_id = p.id
    LEFT JOIN shift_types st ON s.shift_type_id = st.id
    WHERE s.shift_date BETWEEN ? AND ?
    ORDER BY s.shift_date, s.start_time
");
$stmt->execute([$start, $end]);
$shifts = $stmt->fetchAll();

// Organize shifts by row (nurse or patient) and date for display
$shiftsByRowAndDay = [];
foreach ($shifts as $shift) {
    if ($viewBy == 'patient') {
        $rowId = $shift['patient_id'];
    } else {
        $rowId = $shift['nurse_id'];
    }
    $date = $shift['shift_date'];
    if (!isset($shiftsByRowAndDay[$rowId][$date])) {
        $shiftsByRowAndDay[$rowId][$date] = [];
    }
    $shiftsByRowAndDay[$rowId][$date][] = $shift;
}

// Calculate total hours per day per row (for display)
$hoursByRowAndDay = [];
foreach ($shiftsByRowAndDay as $rowId => $daysShifts) {
    foreach ($daysShifts as $date => $dayShifts) {
        $totalMinutes = 0;
        foreach ($dayShifts as $shift) {
            $startMin = (int)substr($shift['start_time'],0,2)*60 + (int)substr($shift['start_time'],3,2);
            $endMin = (int)substr($shift['end_time'],0,2)*60 + (int)substr($shift['end_time'],3,2);
            if ($endMin < $startMin) $endMin += 24*60;
            $totalMinutes += ($endMin - $startMin);
        }
        $hoursByRowAndDay[$rowId][$date] = round($totalMinutes / 60, 1);
    }
}

// Build data structure for conflict detection (by nurse and by patient)
$shiftsByNurse = [];
$shiftsByPatient = [];
foreach ($shifts as $shift) {
    $nurseId = $shift['nurse_id'];
    $patientId = $shift['patient_id'];
    $date = $shift['shift_date'];
    $start = $shift['start_time'];
    $end = $shift['end_time'];
    $id = $shift['id'];

    if (!isset($shiftsByNurse[$nurseId][$date])) {
        $shiftsByNurse[$nurseId][$date] = [];
    }
    $shiftsByNurse[$nurseId][$date][] = ['start' => $start, 'end' => $end, 'id' => $id];

    if (!isset($shiftsByPatient[$patientId][$date])) {
        $shiftsByPatient[$patientId][$date] = [];
    }
    $shiftsByPatient[$patientId][$date][] = ['start' => $start, 'end' => $end, 'id' => $id];
}

// For patient view sorting by total hours, compute overall hours for the period
if ($viewBy == 'patient' && $sort == 'hours') {
    $rowHours = [];
    foreach ($rows as $row) {
        $rowId = $row['id'];
        $total = 0;
        foreach ($days as $day) {
            $date = $day['date'];
            $total += $hoursByRowAndDay[$rowId][$date] ?? 0;
        }
        $rowHours[$rowId] = $total;
    }
    usort($rows, function($a, $b) use ($rowHours) {
        return $rowHours[$b['id']] <=> $rowHours[$a['id']];
    });
} elseif ($viewBy == 'patient') {
    usort($rows, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
}

// Sorting for employee view
if ($viewBy == 'employee') {
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'name';
    if ($sort == 'group') {
        usort($rows, function($a, $b) {
            return strcmp($a['group_name'] ?? '', $b['group_name'] ?? '');
        });
    }
}

// Pre-fetch nurse and patient lists for dropdowns
$nursesList = getNursesList($pdo);
$patientsList = getPatientsList($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VitalZone - Enhanced Schedule Board</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { background-color: #f4f7fc; padding-top: 60px; font-size: 0.95rem; }
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
        .board-container { background: white; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 10px 25px rgba(0,0,0,0.05); margin-top: 20px; }
        .week-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 10px; }
        .employee-row { border-bottom: 1px solid #e9ecef; }
        .employee-row:hover { background-color: #f8f9fa; }
        .employee-cell { font-weight: 600; padding: 0.75rem; }
        .shift-cell {
            padding: 0.75rem;
            min-width: 150px;
            position: relative;
            vertical-align: top;
            min-height: 100px;
            transition: background-color 0.1s;
        }
        .shift-cell.drop-target {
            background-color: #e2f0ff;
        }
        .shift-badge {
            background-color: #e3f2fd;
            border-radius: 0.5rem;
            padding: 0.25rem 0.5rem;
            font-size: 0.85rem;
            margin-bottom: 4px;
            display: block;
            border: 4px solid #0d6efd;
            cursor: <?php echo $canEdit ? 'grab' : 'pointer'; ?>;
            user-select: none;
            position: relative;
        }
        .shift-badge.pending {
            border-color: #ffc107 !important;
        }
        .shift-badge.admin1-approved {
            border-color: #0d6efd !important;
        }
        .shift-badge.admin2-approved {
            border-color: #6f42c1 !important;
        }
        .shift-badge.fully-approved {
            border-color: #28a745 !important;
        }
        .shift-badge.over-limit {
            background: repeating-linear-gradient(
                45deg,
                #f8d7da,
                #f8d7da 10px,
                #ffe6e8 10px,
                #ffe6e8 20px
            ) !important;
        }
        .shift-badge.selected {
            outline: 2px solid #0d6efd;
            background-color: #cfe2ff;
        }
        .shift-badge.dragging {
            opacity: 0.5;
        }
        .empty-shift { color: #adb5bd; font-style: italic; font-size: 0.85rem; }
        .group-badge { background-color: #6c757d; color: white; font-size: 0.7rem; padding: 0.2rem 0.5rem; border-radius: 1rem; margin-left: 0.5rem; display: inline-block; }
        .employee-name { display: flex; align-items: center; flex-wrap: wrap; }
        .table th { background-color: #f8f9fc; text-align: center; position: relative; font-size: 1rem; padding: 0.75rem 0.5rem; }
        .table td { vertical-align: top; }
        .add-shift-icon {
            position: absolute;
            top: 2px;
            right: 2px;
            background: #28a745;
            color: white;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            cursor: pointer;
            z-index: 10;
        }
        .shift-cell:hover .add-shift-icon {
            display: <?php echo $canEdit ? 'flex' : 'none'; ?>;
        }
        .total-hours {
            font-size: 0.75rem;
            color: #6c757d;
            margin-top: 4px;
            text-align: right;
        }
        .total-hours.warning {
            color: #dc3545;
            font-weight: bold;
        }
        .table-responsive {
            overflow-x: auto;
            max-width: 100%;
        }
        .view-selector {
            display: flex;
            gap: 5px;
        }
        .sort-selector {
            margin-left: auto;
        }
        .context-menu {
            position: absolute;
            background: white;
            border: 1px solid #ccc;
            box-shadow: 2px 2px 5px rgba(0,0,0,0.2);
            z-index: 1000;
            border-radius: 4px;
        }
        .context-menu ul {
            list-style: none;
            margin: 0;
            padding: 5px 0;
        }
        .context-menu li {
            padding: 8px 20px;
            cursor: pointer;
        }
        .context-menu li:hover {
            background-color: #f0f0f0;
        }
        .view-toggle {
            margin-left: 15px;
            display: inline-flex;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            overflow: hidden;
        }
        .view-toggle a {
            border: none;
            padding: 0.375rem 0.75rem;
            background: white;
            text-decoration: none;
            color: #6c757d;
        }
        .view-toggle a.active {
            background: #0d6efd;
            color: white;
        }
        .export-btn {
            margin-left: 10px;
        }
        .select2-container {
            width: 100% !important;
        }
        /* Style for fixed field display */
        .fixed-field-display {
            background-color: #e9ecef;
            padding: 0.375rem 0.75rem;
            border-radius: 0.25rem;
            margin-bottom: 1rem;
            font-weight: 500;
        }
        /* Shift type badge with dynamic color */
        .shift-type-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.7rem;
            font-weight: 600;
            margin-top: 2px;
        }
    </style>
</head>
<body>
    <!-- Top Navigation Bar (role‑aware) -->
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

                    <?php if (!in_array($role, ['finance'])): ?>
                        <!-- Management links for non‑finance -->
                        <li class="nav-item">
                            <a class="nav-link" href="nurse.php"><i class="fas fa-user-nurse me-1"></i>Employee</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="patient.php"><i class="fas fa-home me-1"></i>Patients</a>
                        </li>
                        <!-- New Shift Types link -->
                        <li class="nav-item">
                            <a class="nav-link" href="shift_types.php"><i class="fas fa-tags me-1"></i>Shift Types</a>
                        </li>
                    <?php endif; ?>

                    <li class="nav-item">
                        <a class="nav-link active" href="schedule.php"><i class="fas fa-calendar-alt me-1"></i>Schedule Board</a>
                    </li>

                    <?php if (!in_array($role, ['finance'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="manage_shifts.php"><i class="fas fa-list me-1"></i>Manage Shifts</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="groups.php"><i class="fas fa-users-cog me-1"></i>Employee Groups</a>
                        </li>
                    <?php endif; ?>

                    <?php if ($role == 'super_admin'): ?>
                        <!-- Super admin extra links -->
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

                    <?php if ($isFinance): ?>
                        <!-- Finance‑specific links -->
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
            <div class="row"><div class="col-12"><?php echo $flash; ?></div></div>
        <?php endif; ?>

        <!-- Page header with view selector, view toggle, and export button -->
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
            <h1 class="h2">Schedule Board</h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <div class="view-selector">
                    <a href="?view=day&view_by=<?php echo $viewBy; ?>&start=<?php echo date('Y-m-d'); ?>" class="btn btn-sm <?php echo $view=='day'?'btn-primary':'btn-outline-primary'; ?>">Day</a>
                    <a href="?view=week&view_by=<?php echo $viewBy; ?>&start=<?php echo date('Y-m-d', strtotime('monday this week')); ?>" class="btn btn-sm <?php echo $view=='week'?'btn-primary':'btn-outline-primary'; ?>">Week</a>
                    <a href="?view=2weeks&view_by=<?php echo $viewBy; ?>&start=<?php echo date('Y-m-d', strtotime('monday this week')); ?>" class="btn btn-sm <?php echo $view=='2weeks'?'btn-primary':'btn-outline-primary'; ?>">2 Weeks</a>
                    <a href="?view=month&view_by=<?php echo $viewBy; ?>&start=<?php echo date('Y-m-01'); ?>" class="btn btn-sm <?php echo $view=='month'?'btn-primary':'btn-outline-primary'; ?>">Month</a>
                </div>
                <div class="view-toggle">
                    <!-- Swapped order: Patients first, then Employees -->
                    <a href="?view=<?php echo $view; ?>&view_by=patient&start=<?php echo $start; ?>" class="<?php echo $viewBy=='patient'?'active':''; ?>">Patients</a>
                    <a href="?view=<?php echo $view; ?>&view_by=employee&start=<?php echo $start; ?>" class="<?php echo $viewBy=='employee'?'active':''; ?>">Employees</a>
                </div>
                <?php if ($canEdit): ?>
                    <!-- New Shift button opens modal with both selects free -->
                    <button type="button" class="btn btn-sm btn-primary ms-2" data-bs-toggle="modal" data-bs-target="#addShiftModal" onclick="openAddShiftModal(null, null, null)">
                        <i class="fas fa-plus me-1"></i> New Shift
                    </button>
                <?php endif; ?>

            </div>
        </div>

        <!-- Navigation and sorting -->
        <div class="board-container">
            <div class="week-nav">
                <a href="<?php echo $prevLink; ?>" class="btn btn-outline-primary"><i class="fas fa-chevron-left"></i> Previous</a>
                <h4><?php echo $dateTitle; ?></h4>
                <a href="<?php echo $nextLink; ?>" class="btn btn-outline-primary">Next <i class="fas fa-chevron-right"></i></a>
                <div class="sort-selector">
                    <?php if ($viewBy == 'employee'): ?>
                        <select class="form-select form-select-sm" id="sort-select" onchange="window.location.href=this.value;">
                            <option value="?view=<?php echo $view; ?>&view_by=employee&start=<?php echo $start; ?>&sort=name" <?php echo ($sort ?? 'name')=='name'?'selected':''; ?>>Sort by Name</option>
                            <option value="?view=<?php echo $view; ?>&view_by=employee&start=<?php echo $start; ?>&sort=group" <?php echo ($sort ?? '')=='group'?'selected':''; ?>>Sort by Group</option>
                        </select>
                    <?php else: ?>
                        <select class="form-select form-select-sm" id="sort-select" onchange="window.location.href=this.value;">
                            <option value="?view=<?php echo $view; ?>&view_by=patient&start=<?php echo $start; ?>&sort=name" <?php echo ($sort ?? 'name')=='name'?'selected':''; ?>>Sort by Name</option>
                            <option value="?view=<?php echo $view; ?>&view_by=patient&start=<?php echo $start; ?>&sort=hours" <?php echo ($sort ?? '')=='hours'?'selected':''; ?>>Sort by Total Hours</option>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Schedule table -->
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="schedule-table">
                    <thead>
                        <tr>
                            <th><?php echo $rowLabel; ?></th>
                            <?php foreach ($days as $day): ?>
                                <th class="text-center">
                                    <?php echo $day['dayName']; ?><br>
                                    <small><?php echo $day['month'] . ' ' . $day['dayNum']; ?></small>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr class="employee-row" data-name="<?php echo strtolower(htmlspecialchars($row[$rowNameField])); ?>">
                                <td class="employee-cell">
                                    <div class="employee-name">
                                        <?php echo htmlspecialchars($row[$rowNameField]); ?>
                                        <?php if ($viewBy == 'employee' && !empty($row['group_name'])): ?>
                                            <span class="group-badge"><?php echo htmlspecialchars($row['group_name']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php foreach ($days as $day): ?>
                                    <td class="shift-cell" data-row-id="<?php echo $row[$rowIdField]; ?>" data-row-type="<?php echo $rowType; ?>" data-date="<?php echo $day['date']; ?>">
                                        <?php if ($canEdit): ?>
                                            <div class="add-shift-icon" onclick="openAddShiftModal(<?php echo $row[$rowIdField]; ?>, '<?php echo $day['date']; ?>', '<?php echo $rowType; ?>')">
                                                <i class="fas fa-plus"></i>
                                            </div>
                                        <?php endif; ?>
                                        <?php
                                        $date = $day['date'];
                                        if (isset($shiftsByRowAndDay[$row[$rowIdField]][$date])) {
                                            $totalHours = $hoursByRowAndDay[$row[$rowIdField]][$date] ?? 0;
                                            foreach ($shiftsByRowAndDay[$row[$rowIdField]][$date] as $shift) {
                                                $start = date('g:i A', strtotime($shift['start_time']));
                                                $end = date('g:i A', strtotime($shift['end_time']));
                                                $overLimit = ($totalHours > 24) ? 'over-limit' : '';

                                                // Determine approval class
                                                $approvalClass = '';
                                                if ($shift['fully_approved']) {
                                                    $approvalClass = 'fully-approved';
                                                } elseif ($shift['admin2_approved']) {
                                                    // admin2 approved implies admin1 also approved
                                                    $approvalClass = 'admin2-approved';
                                                } elseif ($shift['admin1_approved']) {
                                                    $approvalClass = 'admin1-approved';
                                                } else {
                                                    $approvalClass = 'pending';
                                                }

                                                $badgeClasses = 'shift-badge ' . $overLimit . ' ' . $approvalClass;

                                                // Helper to determine text color for badge background
                                                $textColor = '#ffffff';
                                                if (!empty($shift['shift_type_color'])) {
                                                    // Simple contrast check: if color is light, use dark text
                                                    $hex = ltrim($shift['shift_type_color'], '#');
                                                    $r = hexdec(substr($hex,0,2));
                                                    $g = hexdec(substr($hex,2,2));
                                                    $b = hexdec(substr($hex,4,2));
                                                    $luminance = (0.299*$r + 0.587*$g + 0.114*$b)/255;
                                                    $textColor = $luminance > 0.5 ? '#000000' : '#ffffff';
                                                }

                                                $shiftTypeHtml = '';
                                                if (!empty($shift['shift_type_name'])) {
                                                    $shiftTypeHtml = '<br><span class="shift-type-badge" style="background-color: ' . htmlspecialchars($shift['shift_type_color'] ?? '#0d6efd') . '; color: ' . $textColor . ';">' . htmlspecialchars($shift['shift_type_name']) . '</span>';
                                                }

                                                echo '<div class="' . trim($badgeClasses) . '"
                                                        data-shift-id="' . $shift['id'] . '"
                                                        data-nurse-id="' . $shift['nurse_id'] . '"
                                                        data-patient-id="' . $shift['patient_id'] . '"
                                                        data-nurse-name="' . htmlspecialchars($shift['nurse_name']) . '"
                                                        data-patient-name="' . htmlspecialchars($shift['patient_name']) . '"
                                                        data-shift-type-id="' . ($shift['shift_type_id'] ?? '') . '"
                                                        data-shift-type-name="' . htmlspecialchars($shift['shift_type_name'] ?? '') . '"
                                                        data-shift-type-color="' . htmlspecialchars($shift['shift_type_color'] ?? '') . '"
                                                        data-start="' . $shift['start_time'] . '"
                                                        data-end="' . $shift['end_time'] . '"
                                                        data-admin1="' . ($shift['admin1_approved'] ? 'true' : 'false') . '"
                                                        data-admin2="' . ($shift['admin2_approved'] ? 'true' : 'false') . '"
                                                        data-admin3="' . ($shift['admin3_approved'] ? 'true' : 'false') . '"
                                                        data-fully="' . ($shift['fully_approved'] ? 'true' : 'false') . '"
                                                        title="' . htmlspecialchars($shift['nurse_name']) . ' → ' . htmlspecialchars($shift['patient_name']) . '">';
                                                // Show time
                                                echo $start . ' - ' . $end;
                                                // Show nurse and patient
                                                echo '<br><small>' . htmlspecialchars($shift['nurse_name']) . ' → ' . htmlspecialchars($shift['patient_name']) . '</small>';
                                                // Show shift type badge
                                                echo $shiftTypeHtml;
                                                echo '</div>';
                                            }
                                            echo '<div class="total-hours ' . ($totalHours > 24 ? 'warning' : '') . '">Total: ' . $totalHours . 'h</div>';
                                        } else {
                                            echo '<span class="empty-shift">—</span>';
                                        }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <?php if ($canEdit): ?>
    <!-- Add Shift Modal (supports date range and "Add Another") -->
    <div class="modal fade" id="addShiftModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="addShiftForm">
                    <div class="modal-header"><h5 class="modal-title">Add Shift(s)</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <!-- Date range inputs -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modal_start_date" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="modal_start_date" name="start_date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="modal_end_date" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="modal_end_date" name="end_date" required>
                            </div>
                        </div>

                        <!-- Fixed field display area (hidden by default) -->
                        <div id="modal_fixed_display" style="display: none;" class="fixed-field-display"></div>

                        <!-- Hidden fixed inputs -->
                        <div id="modal_fixed_field" style="display:none;"></div>

                        <!-- Select fields (will be populated by JS) -->
                        <div id="modal_select_field"></div>

                        <!-- Shift Type dropdown (always present) -->
                        <div class="mb-3" id="shift_type_container">
                            <label for="modal_shift_type" class="form-label">Shift Type (optional)</label>
                            <select class="form-select select2" id="modal_shift_type" name="shift_type_id">
                                <option value="">-- No Type --</option>
                                <?php foreach ($shiftTypes as $type): ?>
                                    <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['type_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modal_start_time" class="form-label">Start Time</label>
                                <input type="time" class="form-control" id="modal_start_time" name="start_time" value="07:00" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="modal_end_time" class="form-label">End Time</label>
                                <input type="time" class="form-control" id="modal_end_time" name="end_time" value="19:00" required>
                            </div>
                        </div>
                        <div id="modal_hours_warning" class="text-danger small"></div>
                        <div id="modal_conflict_warning" class="text-danger small"></div>
                        <div id="modal_patient_conflict_warning" class="text-danger small"></div>
                        <div id="modal_range_warning" class="text-info small"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-info" id="addAnotherBtn">Add Another</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Shift Modal (with shift type) -->
    <div class="modal fade" id="editShiftModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="editShiftForm">
                    <div class="modal-header"><h5 class="modal-title">Edit Shift</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <input type="hidden" name="id" id="edit_shift_id">
                        <div class="mb-3">
                            <label for="edit_nurse_id" class="form-label">Nurse *</label>
                            <select class="form-select" id="edit_nurse_id" name="nurse_id" required>
                                <option value="">-- Select Nurse --</option>
                                <?php foreach ($nursesList as $id => $name): ?>
                                    <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_patient_id" class="form-label">Patient *</label>
                            <select class="form-select" id="edit_patient_id" name="patient_id" required>
                                <option value="">-- Select Patient --</option>
                                <?php foreach ($patientsList as $id => $name): ?>
                                    <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_shift_type" class="form-label">Shift Type (optional)</label>
                            <select class="form-select" id="edit_shift_type" name="shift_type_id">
                                <option value="">-- No Type --</option>
                                <?php foreach ($shiftTypes as $type): ?>
                                    <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['type_name']); ?></option>
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
                        <div id="edit_modal_hours_warning" class="text-danger small"></div>
                        <div id="edit_modal_conflict_warning" class="text-danger small"></div>
                        <div id="edit_modal_patient_conflict_warning" class="text-danger small"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Shift</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Context Menu -->
    <div id="contextMenu" class="context-menu" style="display:none;">
        <ul>
            <li onclick="contextEdit()">Edit</li>
            <li onclick="contextCopy()">Copy</li>
            <li onclick="contextDelete()">Delete</li>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Shift Details Modal (for approve/unapprove/view) -->
    <div class="modal fade" id="shiftDetailsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Shift Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="shiftDetailsContent"></div>
                <div class="modal-footer" id="shiftDetailsFooter"></div>
            </div>
        </div>
    </div>

    <!-- Data for conflict detection (keep your existing data script) -->
    <script>
        // All shifts indexed by nurse and date
        var allShiftsByNurse = <?php echo json_encode($shiftsByNurse); ?>;
        // All shifts indexed by patient and date
        var allShiftsByPatient = <?php echo json_encode($shiftsByPatient); ?>;
        // Shift types list for JS
        var shiftTypes = <?php echo json_encode($shiftTypes); ?>;

        // Global lists for dropdowns (used in openAddShiftModal)
        var nurses = <?php echo json_encode($nursesList); ?>;
        var patients = <?php echo json_encode($patientsList); ?>;
    </script>

    <!-- jQuery and Select2 -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ---------- State ----------
        let selectedShiftElement = null;
        let copiedShiftData = null; // { patient_id, nurse_id, start_time, end_time, shift_type_id }
        let draggedShift = null;
        let contextShiftId = null;
        let contextShiftElement = null;
        let lastClickedCell = null; // for paste target
        const viewBy = '<?php echo $viewBy; ?>';
        const canEdit = <?php echo $canEdit ? 'true' : 'false'; ?>;
        const canApprove = <?php echo $canApprove ? 'true' : 'false'; ?>;
        const role = <?php echo json_encode($role); ?>; // Properly encoded

        // Flag to control reload on cancel
        let reloadOnCancel = false;

        // When the add shift modal is hidden, reload if no save was performed
        document.getElementById('addShiftModal').addEventListener('hidden.bs.modal', function () {
            if (reloadOnCancel) {
                location.reload();
            }
        });

        // ---------- Utility Functions (defined early) ----------
        function timeToMinutes(time) {
            let [h, m] = time.split(':').map(Number);
            return h * 60 + m;
        }

        function calculateHours(start, end) {
            let startMin = timeToMinutes(start);
            let endMin = timeToMinutes(end);
            if (endMin < startMin) endMin += 24*60;
            return (endMin - startMin) / 60;
        }

        function getTotalHoursForCell(cell) {
            let totalDiv = cell.querySelector('.total-hours');
            if (totalDiv) {
                return parseFloat(totalDiv.innerText.replace('Total: ', '').replace('h', '')) || 0;
            }
            return 0;
        }

        // Get all shifts in a cell as array of { start, end, patientId, nurseId, shiftId } in minutes
        function getShiftsInCell(cell) {
            let shifts = [];
            let badges = cell.querySelectorAll('.shift-badge');
            badges.forEach(badge => {
                let start = badge.dataset.start;
                let end = badge.dataset.end;
                shifts.push({
                    startMin: timeToMinutes(start),
                    endMin: timeToMinutes(end) + (timeToMinutes(end) < timeToMinutes(start) ? 24*60 : 0),
                    patientId: badge.dataset.patientId,
                    nurseId: badge.dataset.nurseId,
                    shiftId: badge.dataset.shiftId
                });
            });
            return shifts;
        }

        function hasOverlap(existingShifts, startMin, endMin) {
            for (let s of existingShifts) {
                if (startMin < s.endMin && endMin > s.startMin) {
                    return true;
                }
            }
            return false;
        }

        // Check if a nurse has any overlapping shift on a given date (using data structure)
        function nurseHasOverlapOnDate(nurseId, date, startMin, endMin, excludeShiftId = null) {
            if (!allShiftsByNurse[nurseId] || !allShiftsByNurse[nurseId][date]) return false;
            let shifts = allShiftsByNurse[nurseId][date];
            for (let s of shifts) {
                if (s.id == excludeShiftId) continue;
                let sStart = timeToMinutes(s.start);
                let sEnd = timeToMinutes(s.end);
                if (sEnd < sStart) sEnd += 24*60;
                if (startMin < sEnd && endMin > sStart) return true;
            }
            return false;
        }

        // Check if a patient has any overlapping shift on a given date
        function patientHasOverlapOnDate(patientId, date, startMin, endMin, excludeShiftId = null) {
            if (!allShiftsByPatient[patientId] || !allShiftsByPatient[patientId][date]) return false;
            let shifts = allShiftsByPatient[patientId][date];
            for (let s of shifts) {
                if (s.id == excludeShiftId) continue;
                let sStart = timeToMinutes(s.start);
                let sEnd = timeToMinutes(s.end);
                if (sEnd < sStart) sEnd += 24*60;
                if (startMin < sEnd && endMin > sStart) return true;
            }
            return false;
        }

        // Get total hours for a nurse on a given date (using data structure)
        function getNurseTotalHours(nurseId, date) {
            if (!allShiftsByNurse[nurseId] || !allShiftsByNurse[nurseId][date]) return 0;
            let total = 0;
            allShiftsByNurse[nurseId][date].forEach(s => {
                total += calculateHours(s.start, s.end);
            });
            return total;
        }

        function showMessage(msg, isError = false) {
            alert(msg);
        }

        // ---------- Selection ----------
        function clearSelection() {
            if (selectedShiftElement) {
                selectedShiftElement.classList.remove('selected');
                selectedShiftElement = null;
            }
        }

        function selectShift(element) {
            clearSelection();
            element.classList.add('selected');
            selectedShiftElement = element;
        }

        // Track last clicked cell (for paste target)
        document.addEventListener('click', function(e) {
            const cell = e.target.closest('.shift-cell');
            if (cell) lastClickedCell = cell;
        });

        // ---------- Drag & Drop (only if canEdit) ----------
        if (canEdit) {
            let dragAction = 'move'; // default to move

            function handleDragStart(e) {
                const shift = e.target.closest('.shift-badge');
                if (!shift) return;
                draggedShift = shift;
                dragAction = e.ctrlKey ? 'copy' : 'move'; // set action based on Ctrl key
                e.dataTransfer.setData('text/plain', shift.dataset.shiftId);
                e.dataTransfer.effectAllowed = dragAction === 'copy' ? 'copy' : 'move';
                shift.classList.add('dragging');
            }

            function handleDragEnd(e) {
                const shift = e.target.closest('.shift-badge');
                if (shift) shift.classList.remove('dragging');
                document.querySelectorAll('.shift-cell').forEach(cell => cell.classList.remove('drop-target'));
                dragAction = 'move'; // reset
            }

            function handleDragOver(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                const cell = e.target.closest('.shift-cell');
                if (cell) cell.classList.add('drop-target');
            }

            function handleDragLeave(e) {
                const cell = e.target.closest('.shift-cell');
                if (cell) cell.classList.remove('drop-target');
            }

            function handleDrop(e) {
                e.preventDefault();
                const cell = e.target.closest('.shift-cell');
                if (!cell) return;
                cell.classList.remove('drop-target');

                const shiftId = e.dataTransfer.getData('text/plain');
                const sourceShift = document.querySelector(`.shift-badge[data-shift-id="${shiftId}"]`);
                if (!sourceShift) return;

                const sourceCell = sourceShift.closest('.shift-cell');
                if (sourceCell === cell) return; // same cell

                const targetRowId = cell.dataset.rowId;
                const targetDate = cell.dataset.date;
                const targetRowType = cell.dataset.rowType;

                // Get shift data
                const nurseId = sourceShift.dataset.nurseId;
                const patientId = sourceShift.dataset.patientId;
                const start = sourceShift.dataset.start;
                const end = sourceShift.dataset.end;
                const shiftTypeId = sourceShift.dataset.shiftTypeId || null;
                const startMin = timeToMinutes(start);
                let endMin = timeToMinutes(end);
                if (endMin < startMin) endMin += 24*60;

                // Determine new nurse_id and patient_id based on target row type
                let newNurseId, newPatientId;
                if (targetRowType === 'nurse') {
                    newNurseId = targetRowId;
                    newPatientId = patientId;
                } else {
                    newNurseId = nurseId;
                    newPatientId = targetRowId;
                }

                // Check 24h limit for the nurse
                let currentNurseTotal = getNurseTotalHours(newNurseId, targetDate);
                let newHours = calculateHours(start, end);
                if (currentNurseTotal + newHours > 24) {
                    if (!confirm('Total hours for this nurse on this day would exceed 24. ' + (dragAction === 'copy' ? 'Copy anyway?' : 'Move anyway?'))) {
                        return;
                    }
                }

                // Check overlap for the new nurse
                if (nurseHasOverlapOnDate(newNurseId, targetDate, startMin, endMin, dragAction === 'move' ? shiftId : null)) {
                    if (!confirm('This shift overlaps with another shift for this nurse. ' + (dragAction === 'copy' ? 'Copy anyway?' : 'Move anyway?'))) {
                        return;
                    }
                }

                // Check overlap for the patient
                if (patientHasOverlapOnDate(newPatientId, targetDate, startMin, endMin, dragAction === 'move' ? shiftId : null)) {
                    if (!confirm('This shift overlaps with another shift for this patient. ' + (dragAction === 'copy' ? 'Copy anyway?' : 'Move anyway?'))) {
                        return;
                    }
                }

                // Prepare API payload
                let payload;
                if (dragAction === 'copy') {
                    payload = {
                        nurse_id: newNurseId,
                        patient_id: newPatientId,
                        shift_date: targetDate,
                        start_time: start.substring(0,5),
                        end_time: end.substring(0,5),
                        shift_type_id: shiftTypeId
                    };
                } else {
                    payload = {
                        id: shiftId,
                        nurse_id: newNurseId,
                        patient_id: newPatientId,
                        shift_date: targetDate,
                        start_time: start.substring(0,5),
                        end_time: end.substring(0,5),
                        shift_type_id: shiftTypeId
                    };
                }

                // API call
                fetch('api/save_shift.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                .then(res => res.json())
                .then(result => {
                    if (result.success) {
                        location.reload();
                    } else {
                        showMessage('Error: ' + result.error, true);
                    }
                })
                .catch(err => showMessage('Network error', true));
            }

            // Attach drag events to shift badges
            document.querySelectorAll('.shift-badge').forEach(shift => {
                shift.setAttribute('draggable', 'true');
                shift.addEventListener('dragstart', handleDragStart);
                shift.addEventListener('dragend', handleDragEnd);
            });

            // Attach drop events to cells
            document.querySelectorAll('.shift-cell').forEach(cell => {
                cell.addEventListener('dragover', handleDragOver);
                cell.addEventListener('dragleave', handleDragLeave);
                cell.addEventListener('drop', handleDrop);
            });
        }

        // ---------- Copy-Paste (Keyboard) ----------
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'c') {
                if (selectedShiftElement) {
                    copiedShiftData = {
                        nurse_id: selectedShiftElement.dataset.nurseId,
                        patient_id: selectedShiftElement.dataset.patientId,
                        start_time: selectedShiftElement.dataset.start.substring(0,5),
                        end_time: selectedShiftElement.dataset.end.substring(0,5),
                        shift_type_id: selectedShiftElement.dataset.shiftTypeId || null
                    };
                    showMessage('Shift copied');
                }
            }
            if (e.ctrlKey && e.key === 'v' && canEdit) {
                if (!copiedShiftData) {
                    showMessage('No shift copied', true);
                    return;
                }
                if (!lastClickedCell) {
                    showMessage('Select a cell first (click on empty area or shift)', true);
                    return;
                }
                let targetRowId = lastClickedCell.dataset.rowId;
                let targetDate = lastClickedCell.dataset.date;
                let targetRowType = lastClickedCell.dataset.rowType;

                let newNurseId, newPatientId;
                if (targetRowType === 'nurse') {
                    newNurseId = targetRowId;
                    newPatientId = copiedShiftData.patient_id;
                } else {
                    newNurseId = copiedShiftData.nurse_id;
                    newPatientId = targetRowId;
                }

                let startMin = timeToMinutes(copiedShiftData.start_time);
                let endMin = timeToMinutes(copiedShiftData.end_time);
                if (endMin < startMin) endMin += 24*60;
                let newHours = calculateHours(copiedShiftData.start_time, copiedShiftData.end_time);

                // Check 24h limit
                let currentNurseTotal = getNurseTotalHours(newNurseId, targetDate);
                if (currentNurseTotal + newHours > 24) {
                    if (!confirm('Total hours for this nurse on this day would exceed 24. Paste anyway?')) {
                        return;
                    }
                }

                // Check overlap for nurse
                if (nurseHasOverlapOnDate(newNurseId, targetDate, startMin, endMin)) {
                    if (!confirm('This shift overlaps with another shift for this nurse. Paste anyway?')) {
                        return;
                    }
                }

                // Check overlap for patient
                if (patientHasOverlapOnDate(newPatientId, targetDate, startMin, endMin)) {
                    if (!confirm('This shift overlaps with another shift for this patient. Paste anyway?')) {
                        return;
                    }
                }

                // API call to create new shift
                fetch('api/save_shift.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        nurse_id: newNurseId,
                        patient_id: newPatientId,
                        shift_date: targetDate,
                        start_time: copiedShiftData.start_time,
                        end_time: copiedShiftData.end_time,
                        shift_type_id: copiedShiftData.shift_type_id
                    })
                })
                .then(res => res.json())
                .then(result => {
                    if (result.success) {
                        location.reload();
                    } else {
                        showMessage('Error: ' + result.error, true);
                    }
                })
                .catch(err => showMessage('Network error', true));
            }
        });

        // ---------- Context Menu ----------
        document.addEventListener('contextmenu', function(e) {
            if (!canEdit) return; // no context menu for non-editors
            const shift = e.target.closest('.shift-badge');
            if (shift) {
                e.preventDefault();
                contextShiftId = shift.dataset.shiftId;
                contextShiftElement = shift;
                const menu = document.getElementById('contextMenu');
                menu.style.display = 'block';
                menu.style.left = e.pageX + 'px';
                menu.style.top = e.pageY + 'px';
            } else {
                document.getElementById('contextMenu').style.display = 'none';
            }
        });

        document.addEventListener('click', function() {
            document.getElementById('contextMenu').style.display = 'none';
        });

        function contextEdit() {
            if (!canEdit) return;
            if (contextShiftId) {
                const shift = contextShiftElement;
                document.getElementById('edit_shift_id').value = shift.dataset.shiftId;
                document.getElementById('edit_nurse_id').value = shift.dataset.nurseId;
                document.getElementById('edit_patient_id').value = shift.dataset.patientId;
                document.getElementById('edit_shift_type').value = shift.dataset.shiftTypeId || '';
                document.getElementById('edit_shift_date').value = shift.closest('.shift-cell').dataset.date;
                document.getElementById('edit_start_time').value = shift.dataset.start.substring(0,5);
                document.getElementById('edit_end_time').value = shift.dataset.end.substring(0,5);
                new bootstrap.Modal(document.getElementById('editShiftModal')).show();
            }
        }

        function contextCopy() {
            if (contextShiftElement) {
                copiedShiftData = {
                    nurse_id: contextShiftElement.dataset.nurseId,
                    patient_id: contextShiftElement.dataset.patientId,
                    start_time: contextShiftElement.dataset.start.substring(0,5),
                    end_time: contextShiftElement.dataset.end.substring(0,5),
                    shift_type_id: contextShiftElement.dataset.shiftTypeId || null
                };
                showMessage('Shift copied');
            }
        }

        function contextDelete() {
            if (!canEdit) return;
            if (contextShiftId && confirm('Delete this shift?')) {
                fetch('api/save_shift.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: contextShiftId, delete: true })
                })
                .then(res => res.json())
                .then(result => {
                    if (result.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + result.error);
                    }
                });
            }
        }

        // ---------- Add shift modal with Select2, date range, shift type, and "Add Another" ----------
        // Store current fixed info for "Add Another"
        let currentFixedNurseId = null;
        let currentFixedPatientId = null;
        let currentFixedNurseName = null;
        let currentFixedPatientName = null;
        let currentRowType = null;

        function openAddShiftModal(rowId, date, rowType) {
            // Set reload flag to true – if the modal is hidden without save, we reload
            reloadOnCancel = true;

            // Store for "Add Another"
            currentFixedNurseId = null;
            currentFixedPatientId = null;
            currentFixedNurseName = null;
            currentFixedPatientName = null;
            currentRowType = rowType;

            if (!canEdit) return;
            // Set date range (if date provided, use it; otherwise today)
            let defaultDate = date ? date : new Date().toISOString().slice(0,10);
            document.getElementById('modal_start_date').value = defaultDate;
            document.getElementById('modal_end_date').value = defaultDate;
            document.getElementById('modal_start_time').value = '07:00';
            document.getElementById('modal_end_time').value = '19:00';
            document.getElementById('modal_hours_warning').innerText = '';
            document.getElementById('modal_conflict_warning').innerText = '';
            document.getElementById('modal_patient_conflict_warning').innerText = '';
            document.getElementById('modal_range_warning').innerText = '';

            // Reset shift type dropdown
            $('#modal_shift_type').val('').trigger('change');

            let selectField = document.getElementById('modal_select_field');
            let fixedField = document.getElementById('modal_fixed_field');
            let fixedDisplay = document.getElementById('modal_fixed_display');

            if (rowType === 'nurse') {
                // Nurse is fixed
                currentFixedNurseId = rowId;
                currentFixedNurseName = nurses[rowId] || 'Unknown';
                fixedDisplay.innerHTML = `<strong>Nurse:</strong> ${currentFixedNurseName} (fixed)`;
                fixedDisplay.style.display = 'block';
                fixedField.innerHTML = `<input type="hidden" name="nurse_id" id="modal_fixed_nurse" value="${rowId}">`;
                selectField.innerHTML = `
                    <label for="modal_patient_select" class="form-label">Patient *</label>
                    <select class="form-select select2" id="modal_patient_select" name="patient_id" required>
                        <option value="">-- Select Patient --</option>
                        <?php foreach ($patientsList as $id => $name): ?>
                            <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                `;
                let oldPatientHidden = document.getElementById('modal_fixed_patient');
                if (oldPatientHidden) oldPatientHidden.remove();

                setTimeout(function() {
                    $('#modal_patient_select').select2({
                        dropdownParent: $('#addShiftModal'),
                        placeholder: '-- Select Patient --',
                        allowClear: true,
                        width: '100%'
                    });
                    $('#modal_shift_type').select2({
                        dropdownParent: $('#addShiftModal'),
                        placeholder: '-- No Type --',
                        allowClear: true,
                        width: '100%'
                    });
                }, 100);
            } else if (rowType === 'patient') {
                // Patient is fixed
                currentFixedPatientId = rowId;
                currentFixedPatientName = patients[rowId] || 'Unknown';
                fixedDisplay.innerHTML = `<strong>Patient:</strong> ${currentFixedPatientName} (fixed)`;
                fixedDisplay.style.display = 'block';
                fixedField.innerHTML = `<input type="hidden" name="patient_id" id="modal_fixed_patient" value="${rowId}">`;
                selectField.innerHTML = `
                    <label for="modal_nurse_select" class="form-label">Nurse *</label>
                    <select class="form-select select2" id="modal_nurse_select" name="nurse_id" required>
                        <option value="">-- Select Nurse --</option>
                        <?php foreach ($nursesList as $id => $name): ?>
                            <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                `;
                let oldNurseHidden = document.getElementById('modal_fixed_nurse');
                if (oldNurseHidden) oldNurseHidden.remove();

                setTimeout(function() {
                    $('#modal_nurse_select').select2({
                        dropdownParent: $('#addShiftModal'),
                        placeholder: '-- Select Nurse --',
                        allowClear: true,
                        width: '100%'
                    });
                    $('#modal_shift_type').select2({
                        dropdownParent: $('#addShiftModal'),
                        placeholder: '-- No Type --',
                        allowClear: true,
                        width: '100%'
                    });
                }, 100);
            } else {
                // No fixed row (opened from header) – show both selects
                fixedDisplay.style.display = 'none';
                fixedField.innerHTML = ''; // no hidden fields
                selectField.innerHTML = `
                    <div class="mb-3">
                        <label for="modal_nurse_select" class="form-label">Nurse *</label>
                        <select class="form-select select2" id="modal_nurse_select" name="nurse_id" required>
                            <option value="">-- Select Nurse --</option>
                            <?php foreach ($nursesList as $id => $name): ?>
                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="modal_patient_select" class="form-label">Patient *</label>
                        <select class="form-select select2" id="modal_patient_select" name="patient_id" required>
                            <option value="">-- Select Patient --</option>
                            <?php foreach ($patientsList as $id => $name): ?>
                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                `;
                setTimeout(function() {
                    $('#modal_nurse_select').select2({
                        dropdownParent: $('#addShiftModal'),
                        placeholder: '-- Select Nurse --',
                        allowClear: true,
                        width: '100%'
                    });
                    $('#modal_patient_select').select2({
                        dropdownParent: $('#addShiftModal'),
                        placeholder: '-- Select Patient --',
                        allowClear: true,
                        width: '100%'
                    });
                    $('#modal_shift_type').select2({
                        dropdownParent: $('#addShiftModal'),
                        placeholder: '-- No Type --',
                        allowClear: true,
                        width: '100%'
                    });
                }, 100);
            }

            new bootstrap.Modal(document.getElementById('addShiftModal')).show();
        }

        // "Add Another" button handler
        document.getElementById('addAnotherBtn').addEventListener('click', function() {
            saveShift(true);
        });

        document.getElementById('addShiftForm').addEventListener('submit', function(e) {
            e.preventDefault();
            saveShift(false);
        });

        function saveShift(keepOpen) {
            // A save is in progress – prevent reload on cancel
            reloadOnCancel = false;

            let nurseId = document.getElementById('modal_fixed_nurse')?.value || document.getElementById('modal_nurse_select')?.value;
            let patientId = document.getElementById('modal_fixed_patient')?.value || document.getElementById('modal_patient_select')?.value;
            let shiftTypeId = document.getElementById('modal_shift_type').value || null;
            let startDate = document.getElementById('modal_start_date').value;
            let endDate = document.getElementById('modal_end_date').value;
            let startTime = document.getElementById('modal_start_time').value;
            let endTime = document.getElementById('modal_end_time').value;

            if (!nurseId || !patientId) {
                alert('Please select both nurse and patient.');
                return;
            }
            if (!startDate || !endDate) {
                alert('Please select start and end dates.');
                return;
            }
            if (new Date(startDate) > new Date(endDate)) {
                alert('End date must be after start date.');
                return;
            }

            let startMin = timeToMinutes(startTime);
            let endMin = timeToMinutes(endTime);
            if (endMin < startMin) endMin += 24*60;
            let shiftHours = calculateHours(startTime, endTime);

            // Collect dates in range
            let dates = [];
            let current = new Date(startDate);
            let last = new Date(endDate);
            while (current <= last) {
                dates.push(current.toISOString().slice(0,10));
                current.setDate(current.getDate() + 1);
            }

            // Check conflicts for each day
            let conflicts = [];
            for (let date of dates) {
                let nurseTotal = getNurseTotalHours(nurseId, date);
                if (nurseTotal + shiftHours > 24) {
                    conflicts.push(`- ${date}: Nurse total would be ${(nurseTotal + shiftHours).toFixed(1)}h (max 24).`);
                }
                if (nurseHasOverlapOnDate(nurseId, date, startMin, endMin)) {
                    conflicts.push(`- ${date}: Nurse already has an overlapping shift.`);
                }
                if (patientHasOverlapOnDate(patientId, date, startMin, endMin)) {
                    conflicts.push(`- ${date}: Patient already has an overlapping shift.`);
                }
            }

            if (conflicts.length > 0) {
                let msg = "The following conflicts were detected:\n\n" + conflicts.join("\n") + "\n\nDo you want to create all shifts anyway?";
                if (!confirm(msg)) {
                    return;
                }
            }

            // Create shifts one by one
            let promises = dates.map(date => {
                return fetch('api/save_shift.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        nurse_id: nurseId,
                        patient_id: patientId,
                        shift_date: date,
                        start_time: startTime,
                        end_time: endTime,
                        shift_type_id: shiftTypeId
                    })
                }).then(res => res.json());
            });

            Promise.all(promises)
                .then(results => {
                    let allSuccess = results.every(r => r.success);
                    if (allSuccess) {
                        if (keepOpen) {
                            // Reset time fields to default, keep selections
                            document.getElementById('modal_start_time').value = '07:00';
                            document.getElementById('modal_end_time').value = '19:00';
                            updateAddWarnings();
                            showMessage('Shift(s) added. You can add another.');
                        } else {
                            bootstrap.Modal.getInstance(document.getElementById('addShiftModal')).hide();
                            location.reload();
                        }
                    } else {
                        let errors = results.filter(r => !r.success).map(r => r.error).join(', ');
                        alert('Some shifts failed: ' + errors);
                    }
                })
                .catch(err => alert('Network error: ' + err));
        }

        // Update warnings
        document.getElementById('modal_start_time').addEventListener('change', updateAddWarnings);
        document.getElementById('modal_end_time').addEventListener('change', updateAddWarnings);
        document.getElementById('modal_start_date').addEventListener('change', updateAddWarnings);
        $(document).on('change', '#modal_patient_select, #modal_nurse_select, #modal_shift_type', function() {
            updateAddWarnings();
        });

        function updateAddWarnings() {
            let nurseId = document.getElementById('modal_fixed_nurse')?.value || document.getElementById('modal_nurse_select')?.value;
            let patientId = document.getElementById('modal_fixed_patient')?.value || document.getElementById('modal_patient_select')?.value;
            let startDate = document.getElementById('modal_start_date').value;
            let start = document.getElementById('modal_start_time').value;
            let end = document.getElementById('modal_end_time').value;

            let endDate = document.getElementById('modal_end_date').value;
            if (startDate && endDate) {
                let days = Math.floor((new Date(endDate) - new Date(startDate)) / (1000*60*60*24)) + 1;
                document.getElementById('modal_range_warning').innerText = days + ' day(s) will be created with the same times.';
            }

            if (!start || !end || !nurseId || !patientId || !startDate) return;

            let startMin = timeToMinutes(start);
            let endMin = timeToMinutes(end);
            if (endMin < startMin) endMin += 24*60;

            let currentNurseTotal = getNurseTotalHours(nurseId, startDate);
            let newHours = calculateHours(start, end);
            let hoursDiv = document.getElementById('modal_hours_warning');
            if (currentNurseTotal + newHours > 24) {
                hoursDiv.innerText = `Warning: Total hours for this nurse on ${startDate} would be ${(currentNurseTotal + newHours).toFixed(1)} (max 24).`;
            } else {
                hoursDiv.innerText = '';
            }

            let overlapDiv = document.getElementById('modal_conflict_warning');
            if (nurseHasOverlapOnDate(nurseId, startDate, startMin, endMin)) {
                overlapDiv.innerText = `Warning: This nurse already has an overlapping shift on ${startDate}.`;
            } else {
                overlapDiv.innerText = '';
            }

            let patientConflictDiv = document.getElementById('modal_patient_conflict_warning');
            if (patientHasOverlapOnDate(patientId, startDate, startMin, endMin)) {
                patientConflictDiv.innerText = `Warning: This patient already has an overlapping shift on ${startDate}.`;
            } else {
                patientConflictDiv.innerText = '';
            }
        }

        // Edit shift modal submission
        document.getElementById('editShiftForm').addEventListener('submit', function(e) {
            e.preventDefault();
            let shiftId = document.getElementById('edit_shift_id').value;
            let nurseId = document.getElementById('edit_nurse_id').value;
            let patientId = document.getElementById('edit_patient_id').value;
            let shiftTypeId = document.getElementById('edit_shift_type').value || null;
            let date = document.getElementById('edit_shift_date').value;
            let start = document.getElementById('edit_start_time').value;
            let end = document.getElementById('edit_end_time').value;

            let startMin = timeToMinutes(start);
            let endMin = timeToMinutes(end);
            if (endMin < startMin) endMin += 24*60;
            let newHours = calculateHours(start, end);

            let currentNurseTotal = getNurseTotalHours(nurseId, date);
            if (allShiftsByNurse[nurseId] && allShiftsByNurse[nurseId][date]) {
                let existingShift = allShiftsByNurse[nurseId][date].find(s => s.id == shiftId);
                if (existingShift) {
                    currentNurseTotal -= calculateHours(existingShift.start, existingShift.end);
                }
            }
            if (currentNurseTotal + newHours > 24) {
                if (!confirm('Total hours for this nurse would exceed 24. Update anyway?')) {
                    return;
                }
            }

            if (nurseHasOverlapOnDate(nurseId, date, startMin, endMin, shiftId)) {
                if (!confirm('This nurse already has an overlapping shift. Update anyway?')) {
                    return;
                }
            }

            if (patientHasOverlapOnDate(patientId, date, startMin, endMin, shiftId)) {
                if (!confirm('This patient already has an overlapping shift. Update anyway?')) {
                    return;
                }
            }

            fetch('api/save_shift.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: shiftId,
                    nurse_id: nurseId,
                    patient_id: patientId,
                    shift_date: date,
                    start_time: start,
                    end_time: end,
                    shift_type_id: shiftTypeId
                })
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    location.reload();
                } else {
                    alert('Error: ' + result.error);
                }
            })
            .catch(err => alert('Network error'));
        });

        // ---------- Shift Details Modal (improved) ----------
        function openShiftDetailsModal(badge) {
            if (!badge || !badge.dataset.shiftId) {
                console.warn("Invalid badge element or missing shift-id");
                return;
            }

            const shiftId = badge.dataset.shiftId;
            const nurseName = badge.dataset.nurseName || '—';
            const patientName = badge.dataset.patientName || '—';
            const shiftTypeName = badge.dataset.shiftTypeName || '';
            const shiftTypeColor = badge.dataset.shiftTypeColor || '#0d6efd';
            const start = badge.dataset.start || '';
            const end = badge.dataset.end || '';
            const date = badge.closest('.shift-cell')?.dataset?.date || '';
            const formattedDate = date ? new Date(date).toLocaleDateString() : '—';

            const admin1 = badge.dataset.admin1 === 'true';
            const admin2 = badge.dataset.admin2 === 'true';
            const admin3 = badge.dataset.admin3 === 'true';
            const fully = badge.dataset.fully === 'true';

            let approvalHtml = fully
                ? '<span class="badge bg-success">Fully Approved</span>'
                : (admin1 || admin2 || admin3)
                    ? [
                        admin1 ? '✓ Admin1' : '',
                        admin2 ? '✓ Admin2' : '',
                        admin3 ? '✓ Admin3' : ''
                      ].filter(Boolean).join(' ') || '<span class="badge bg-warning">Partial</span>'
                    : '<span class="badge bg-warning">Pending</span>';

            const shiftTypeHtml = shiftTypeName
                ? `<p><strong>Shift Type:</strong> <span style="background-color:${shiftTypeColor}; color:white; padding:0.2rem 0.5rem; border-radius:0.25rem;">${escapeHtml(shiftTypeName)}</span></p>`
                : '';

            const content = `
                <p><strong>Nurse:</strong> ${escapeHtml(nurseName)}</p>
                <p><strong>Patient:</strong> ${escapeHtml(patientName)}</p>
                ${shiftTypeHtml}
                <p><strong>Date:</strong> ${formattedDate}</p>
                <p><strong>Time:</strong> ${formatTime(start)} – ${formatTime(end)}</p>
                <p><strong>Approval:</strong> ${approvalHtml}</p>
            `;

            // Set content
            const contentEl = document.getElementById('shiftDetailsContent');
            if (!contentEl) {
                console.error("Cannot find #shiftDetailsContent");
                return;
            }
            contentEl.innerHTML = content;

            // Build footer buttons
            let footerHtml = '';

            if (canApprove) {
                if (role === 'admin1' && !admin1) {
                    footerHtml += `<button class="btn btn-success me-1" onclick="approveShift(${shiftId}, 'admin1')">Approve as Admin1</button>`;
                }
                if (role === 'admin1' && admin1 && !admin2 && !admin3) {
                    footerHtml += `<button class="btn btn-warning me-1" onclick="unapproveShift(${shiftId}, 'admin1')">Unapprove Admin1</button>`;
                }
                if (role === 'admin2' && admin1 && !admin2) {
                    footerHtml += `<button class="btn btn-success me-1" onclick="approveShift(${shiftId}, 'admin2')">Approve as Admin2</button>`;
                }
                if (role === 'admin2' && admin2 && !admin3) {
                    footerHtml += `<button class="btn btn-warning me-1" onclick="unapproveShift(${shiftId}, 'admin2')">Unapprove Admin2</button>`;
                }
                if (role === 'admin3' && admin1 && admin2 && !admin3) {
                    footerHtml += `<button class="btn btn-success me-1" onclick="approveShift(${shiftId}, 'admin3')">Approve as Admin3</button>`;
                }
                if (role === 'admin3' && admin3) {
                    footerHtml += `<button class="btn btn-warning me-1" onclick="unapproveShift(${shiftId}, 'admin3')">Unapprove Admin3</button>`;
                }
                if (role === 'super_admin') {
                    if (!admin1) footerHtml += `<button class="btn btn-success me-1" onclick="approveShift(${shiftId}, 'admin1')">Approve Admin1</button>`;
                    if (admin1 && !admin2) footerHtml += `<button class="btn btn-success me-1" onclick="approveShift(${shiftId}, 'admin2')">Approve Admin2</button>`;
                    if (admin1 && admin2 && !admin3) footerHtml += `<button class="btn btn-success me-1" onclick="approveShift(${shiftId}, 'admin3')">Approve Admin3</button>`;
                    if (admin1 && !admin2 && !admin3) footerHtml += `<button class="btn btn-warning me-1" onclick="unapproveShift(${shiftId}, 'admin1')">Unapprove Admin1</button>`;
                    if (admin2 && !admin3) footerHtml += `<button class="btn btn-warning me-1" onclick="unapproveShift(${shiftId}, 'admin2')">Unapprove Admin2</button>`;
                    if (admin3) footerHtml += `<button class="btn btn-warning me-1" onclick="unapproveShift(${shiftId}, 'admin3')">Unapprove Admin3</button>`;
                }
            }

            if (canEdit) {
                footerHtml += `<button class="btn btn-primary me-1" onclick="editShift(${shiftId})">Edit</button>`;
                footerHtml += `<button class="btn btn-danger me-1" onclick="deleteShift(${shiftId})">Delete</button>`;
            }

            footerHtml += `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>`;

            const footerEl = document.getElementById('shiftDetailsFooter');
            if (footerEl) footerEl.innerHTML = footerHtml;

            // Show modal – most reliable way in Bootstrap 5
            const modalElement = document.getElementById('shiftDetailsModal');
            if (!modalElement) {
                console.error("Modal element #shiftDetailsModal not found");
                return;
            }

            const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            modal.show();

            console.log("[Modal] Attempted to show shift details for ID:", shiftId);
        }

        // Helper – safe HTML escape
        function escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function formatTime(time) {
            if (!time) return '—';
            let [h, m] = time.split(':');
            let hour = parseInt(h, 10);
            let ampm = hour >= 12 ? 'PM' : 'AM';
            hour = hour % 12 || 12;
            return hour + ':' + m.padStart(2,'0') + ' ' + ampm;
        }

        // ---------- Click handler for shift badges ----------
        document.addEventListener('click', function(e) {
            const badge = e.target.closest('.shift-badge');
            if (!badge) return;

            const shiftId = badge.dataset.shiftId;
            console.log("[Shift badge clicked]", {
                shiftId: shiftId,
                nurse: badge.dataset.nurseName,
                patient: badge.dataset.patientName,
                date: badge.closest('.shift-cell')?.dataset?.date
            });

            try {
                openShiftDetailsModal(badge);
            } catch (err) {
                console.error("Failed to open shift details modal:", err);
                alert("Could not open shift details. Check browser console for more information.");
            }
        });

        // ---------- Shift Approval/Disapproval Functions ----------
        function approveShift(shiftId, level) {
            fetch('api/approve_shift.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: shiftId,
                    level: level,
                    unapprove: false
                })
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Could not approve shift'));
                }
            })
            .catch(err => alert('Network error: ' + err.message));
        }

        function unapproveShift(shiftId, level) {
            fetch('api/approve_shift.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: shiftId,
                    level: level,
                    unapprove: true
                })
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Could not unapprove shift'));
                }
            })
            .catch(err => alert('Network error: ' + err.message));
        }

        function editShift(shiftId) {
            const shift = document.querySelector(`.shift-badge[data-shift-id="${shiftId}"]`);
            if (!shift) {
                alert('Shift not found');
                return;
            }
            document.getElementById('edit_shift_id').value = shift.dataset.shiftId;
            document.getElementById('edit_nurse_id').value = shift.dataset.nurseId;
            document.getElementById('edit_patient_id').value = shift.dataset.patientId;
            document.getElementById('edit_shift_type').value = shift.dataset.shiftTypeId || '';
            document.getElementById('edit_shift_date').value = shift.closest('.shift-cell').dataset.date;
            document.getElementById('edit_start_time').value = shift.dataset.start.substring(0,5);
            document.getElementById('edit_end_time').value = shift.dataset.end.substring(0,5);
            // Close the details modal first
            bootstrap.Modal.getInstance(document.getElementById('shiftDetailsModal'))?.hide();
            // Then show edit modal
            new bootstrap.Modal(document.getElementById('editShiftModal')).show();
        }

        function deleteShift(shiftId) {
            if (!confirm('Are you sure you want to delete this shift?')) {
                return;
            }
            fetch('api/save_shift.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: shiftId, delete: true })
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Could not delete shift'));
                }
            })
            .catch(err => alert('Network error: ' + err.message));
        }
    </script>
</body>
</html>