<?php
/**
 * VitalZone - Finance Shift Reports
 * View and download approved shifts by nurse and month.
 * Now supports toggling between 2‑level and 3‑level approval.
 * Includes a chart, three download options, and a preview table for all nurses.
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Only finance and super_admin can access
if (!isLoggedIn() || !in_array(getRole(), ['finance', 'super_admin'])) {
    header('Location: login.php');
    exit;
}

$role = getRole();

// Get all nurses for dropdown
$allNurses = getNursesList($pdo);

// Filters
$selectedNurse = isset($_GET['nurse_id']) ? (int)$_GET['nurse_id'] : 0;
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$approvalLevel = isset($_GET['approval_level']) ? $_GET['approval_level'] : '3';   // default to 3-level
$previewAll = isset($_GET['preview_all']) && $_GET['preview_all'] == '1';

// Validate month format
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

// URLs for downloads and preview
$downloadAllCsvUrl = "api/download_all_reports.php?month=" . urlencode($selectedMonth) . "&approval_level=" . urlencode($approvalLevel);
$downloadAllZipUrl = "api/download_all_zip.php?month=" . urlencode($selectedMonth) . "&approval_level=" . urlencode($approvalLevel);
$previewUrl = "?preview_all=1&month=" . urlencode($selectedMonth) . "&approval_level=" . urlencode($approvalLevel);
$hidePreviewUrl = "?month=" . urlencode($selectedMonth) . "&approval_level=" . urlencode($approvalLevel) . ($selectedNurse ? "&nurse_id=$selectedNurse" : "");

// Fetch shifts if nurse selected (for single‑nurse view) – only if not in preview mode
$shifts = [];
$nurseName = '';
$chartData = [];
if ($selectedNurse && !$previewAll) {
    // Verify nurse exists
    $stmt = $pdo->prepare("SELECT name FROM nurses WHERE id = ?");
    $stmt->execute([$selectedNurse]);
    $nurse = $stmt->fetch();
    if ($nurse) {
        $nurseName = $nurse['name'];
        $startDate = $selectedMonth . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        // Base query
        $sql = "
            SELECT s.*, p.name AS patient_name
            FROM shifts s
            JOIN patients p ON s.patient_id = p.id
            WHERE s.nurse_id = ?
              AND s.shift_date BETWEEN ? AND ?
        ";

        // Add approval condition based on selected level
        if ($approvalLevel == '2') {
            $sql .= " AND s.admin1_approved = 1 AND s.admin2_approved = 1";
        } else {
            $sql .= " AND s.fully_approved = 1";
        }

        $sql .= " ORDER BY s.shift_date, s.start_time";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$selectedNurse, $startDate, $endDate]);
        $shifts = $stmt->fetchAll();

        // Prepare chart data: total hours per day
        $daysInMonth = (int)date('t', strtotime($startDate));
        $dailyHours = array_fill(1, $daysInMonth, 0);
        foreach ($shifts as $shift) {
            $day = (int)substr($shift['shift_date'], 8, 2);
            $start = $shift['start_time'];
            $end = $shift['end_time'];
            $startMin = (int)substr($start,0,2)*60 + (int)substr($start,3,2);
            $endMin = (int)substr($end,0,2)*60 + (int)substr($end,3,2);
            if ($endMin < $startMin) $endMin += 24*60;
            $hours = round(($endMin - $startMin) / 60, 1);
            $dailyHours[$day] += $hours;
        }
        $chartData = [
            'labels' => range(1, $daysInMonth),
            'values' => array_values($dailyHours)
        ];
    }
}

// For preview: fetch all nurses' data
$previewData = null;
if ($previewAll) {
    $startDate = $selectedMonth . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));
    $monthTs = strtotime($startDate);
    $monthName = date('F', $monthTs);
    $daysInMonth = (int)date('t', $monthTs);

    // Get all nurses
    $nurses = $pdo->query("SELECT id, name FROM nurses ORDER BY name")->fetchAll();

    // Fetch all shifts with approval filter
    $sql = "
        SELECT
            s.nurse_id,
            s.shift_date,
            p.name AS patient_name,
            s.start_time,
            s.end_time
        FROM shifts s
        JOIN patients p ON s.patient_id = p.id
        WHERE s.shift_date BETWEEN ? AND ?
    ";
    if ($approvalLevel == '2') {
        $sql .= " AND s.admin1_approved = 1 AND s.admin2_approved = 1";
    } else {
        $sql .= " AND s.fully_approved = 1";
    }
    $sql .= " ORDER BY s.nurse_id, s.shift_date, s.start_time";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$startDate, $endDate]);
    $allShifts = $stmt->fetchAll();

    // Group by nurse and day
    $shiftsByNurse = [];
    foreach ($allShifts as $shift) {
        $nurseId = $shift['nurse_id'];
        $day = (int)substr($shift['shift_date'], 8, 2);
        $shiftsByNurse[$nurseId][$day][] = $shift;
    }

    // Prepare preview structure
    $previewData = [
        'monthName' => $monthName,
        'daysInMonth' => $daysInMonth,
        'nurses' => []
    ];
    foreach ($nurses as $nurse) {
        $nurseId = $nurse['id'];
        $nurseName = $nurse['name'];
        $shiftsByDay = $shiftsByNurse[$nurseId] ?? [];

        // Calculate totals
        $totalHours = 0;
        $dutyDays = count($shiftsByDay);
        foreach ($shiftsByDay as $dayShifts) {
            foreach ($dayShifts as $shift) {
                $start = $shift['start_time'];
                $end = $shift['end_time'];
                $startMin = (int)substr($start,0,2)*60 + (int)substr($start,3,2);
                $endMin = (int)substr($end,0,2)*60 + (int)substr($end,3,2);
                if ($endMin < $startMin) $endMin += 24*60;
                $totalHours += round(($endMin - $startMin) / 60, 1);
            }
        }

        $previewData['nurses'][] = [
            'id' => $nurseId,
            'name' => $nurseName,
            'shiftsByDay' => $shiftsByDay,
            'totalHours' => $totalHours,
            'dutyDays' => $dutyDays
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VitalZone - Shift Reports</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <!-- Select2 -->
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
        .filter-bar { background: white; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .chart-container { position: relative; height: 300px; margin-bottom: 2rem; }
        .action-buttons { display: flex; gap: 10px; justify-content: flex-end; margin: 15px 0; flex-wrap: wrap; }
        .preview-table-container {
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            margin-top: 20px;
        }
        .preview-table {
            background: white;
        }
        .preview-table th {
            position: sticky;
            top: 0;
            background: #f8f9fc;
            z-index: 10;
        }
        .nurse-section {
            background-color: #e9ecef;
            font-weight: bold;
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
                    <li class="nav-item"><a class="nav-link" href="index.php"><i class="fas fa-tachometer-alt me-1"></i>Dashboard</a></li>
                    <?php if ($role == 'super_admin'): ?>
                        <li class="nav-item"><a class="nav-link" href="nurse.php"><i class="fas fa-user-nurse me-1"></i>Employee</a></li>
                        <li class="nav-item"><a class="nav-link" href="patient.php"><i class="fas fa-home me-1"></i>Patients</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link" href="schedule.php"><i class="fas fa-calendar-check me-1"></i>Schedule Board</a></li>
                    <?php if ($role == 'super_admin'): ?>
                        <li class="nav-item"><a class="nav-link" href="manage_shifts.php"><i class="fas fa-list me-1"></i>Manage Shifts</a></li>
                        <li class="nav-item"><a class="nav-link" href="groups.php"><i class="fas fa-users-cog me-1"></i>Employee Groups</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link active" href="finance_shifts.php"><i class="fas fa-file-csv me-1"></i>Shift Reports</a></li>
                    <li class="nav-item"><a class="nav-link" href="profit.php"><i class="fas fa-chart-line me-1"></i>Profit Dashboard</a></li>
                    <?php if ($role == 'super_admin'): ?>
                        <li class="nav-item"><a class="nav-link" href="logs.php"><i class="fas fa-history me-1"></i>System Logs</a></li>
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

    <main class="container-fluid px-md-4 py-4">
        <!-- Flash message -->
        <?php $flash = displayFlashMessage(); if ($flash): ?>
            <div class="row"><div class="col-12"><?php echo $flash; ?></div></div>
        <?php endif; ?>

        <!-- Page header -->
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
            <h1 class="h2">Shift Reports</h1>
        </div>

        <!-- Filter Bar (with approval level radio & onchange submit) -->
        <div class="filter-bar">
            <form method="get" action="finance_shifts.php" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="nurse_id" class="form-label">Nurse</label>
                    <select name="nurse_id" id="nurse_id" class="form-select select2">
                        <option value="">-- All Nurses (Preview) --</option>
                        <?php foreach ($allNurses as $id => $name): ?>
                            <option value="<?php echo $id; ?>" <?php echo ($selectedNurse == $id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="month" class="form-label">Month</label>
                    <input type="month" class="form-control" id="month" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label d-block">Approval required</label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="approval_level" id="level2" value="2" <?php echo ($approvalLevel == '2') ? 'checked' : ''; ?> onchange="this.form.submit()">
                        <label class="form-check-label" for="level2">2 levels (Admin1+2)</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="approval_level" id="level3" value="3" <?php echo ($approvalLevel == '3') ? 'checked' : ''; ?> onchange="this.form.submit()">
                        <label class="form-check-label" for="level3">3 levels (Admin1+2+3)</label>
                    </div>
                </div>
                <div class="col-md-2 d-flex">
                    <button type="submit" class="btn btn-primary me-2 w-100"><i class="fas fa-search"></i> View</button>
                    <a href="finance_shifts.php" class="btn btn-secondary w-100">Clear</a>
                </div>
            </form>
        </div>

        <!-- Action Buttons (Download & Preview) -->
        <div class="action-buttons">
            <a href="<?php echo $downloadAllCsvUrl; ?>" class="btn btn-info">
                <i class="fas fa-file-csv me-1"></i> All Nurses (One CSV)
            </a>
            <a href="<?php echo $downloadAllZipUrl; ?>" class="btn btn-warning">
                <i class="fas fa-file-archive me-1"></i> All Nurses (ZIP of CSVs)
            </a>
            <?php if ($previewAll): ?>
                <a href="<?php echo $hidePreviewUrl; ?>" class="btn btn-secondary">
                    <i class="fas fa-times me-1"></i> Hide Preview
                </a>
            <?php else: ?>
                <a href="<?php echo $previewUrl; ?>" class="btn btn-outline-primary">
                    <i class="fas fa-eye me-1"></i> Preview All Nurses
                </a>
            <?php endif; ?>
        </div>

        <!-- Preview Table (if requested) -->
        <?php if ($previewAll && $previewData): ?>
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        Preview: All Nurses – <?php echo $previewData['monthName']; ?> (<?php echo $selectedMonth; ?>)
                        <span class="badge bg-secondary ms-2">Approval: <?php echo $approvalLevel == '2' ? '2‑level' : '3‑level'; ?></span>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="preview-table-container">
                        <table class="table table-bordered table-hover preview-table">
                            <thead>
                                <tr>
                                    <th>Nurse</th>
                                    <th>Date</th>
                                    <th>Patient/Location</th>
                                    <th>Start Time</th>
                                    <th>End Time</th>
                                    <th>Hours</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewData['nurses'] as $nurse): ?>
                                    <?php
                                    $shiftsByDay = $nurse['shiftsByDay'];
                                    $firstRow = true;
                                    for ($day = 1; $day <= $previewData['daysInMonth']; $day++):
                                        if (isset($shiftsByDay[$day])):
                                            foreach ($shiftsByDay[$day] as $shift):
                                                $start = $shift['start_time'];
                                                $end = $shift['end_time'];
                                                $startMin = (int)substr($start,0,2)*60 + (int)substr($start,3,2);
                                                $endMin = (int)substr($end,0,2)*60 + (int)substr($end,3,2);
                                                if ($endMin < $startMin) $endMin += 24*60;
                                                $hours = round(($endMin - $startMin) / 60, 1);
                                    ?>
                                                <tr>
                                                    <?php if ($firstRow): ?>
                                                        <td rowspan="<?php echo $nurse['dutyDays']; ?>" style="vertical-align: middle; background-color: #f8f9fc; font-weight: 600;">
                                                            <?php echo htmlspecialchars($nurse['name']); ?>
                                                        </td>
                                                        <?php $firstRow = false; ?>
                                                    <?php endif; ?>
                                                    <td><?php echo $day; ?></td>
                                                    <td><?php echo htmlspecialchars($shift['patient_name']); ?></td>
                                                    <td><?php echo formatTime($shift['start_time']); ?></td>
                                                    <td><?php echo formatTime($shift['end_time']); ?></td>
                                                    <td><?php echo $hours; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    <!-- Totals row for this nurse -->
                                    <tr class="nurse-section">
                                        <td colspan="5" class="text-end fw-bold"><?php echo htmlspecialchars($nurse['name']); ?> Totals:</td>
                                        <td class="fw-bold"><?php echo $nurse['totalHours']; ?> hrs (<?php echo $nurse['dutyDays']; ?> days)</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted small mt-2">Only days with shifts are shown. Totals include all shifts for the month.</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Single nurse chart and table (only if a nurse is selected and not in preview mode) -->
        <?php if ($selectedNurse && !$previewAll && !empty($nurseName)): ?>
            <!-- Chart -->
            <?php if (!empty($shifts)): ?>
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Daily Hours – <?php echo htmlspecialchars($nurseName); ?> (<?php echo date('F Y', strtotime($selectedMonth)); ?>)</h6>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="hoursChart"></canvas>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Results table -->
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <?php echo ($approvalLevel == '2') ? '2‑Level Approved' : 'Fully Approved'; ?> Shifts for <?php echo htmlspecialchars($nurseName); ?> – <?php echo date('F Y', strtotime($selectedMonth)); ?>
                    </h6>
                    <a href="api/download_report.php?nurse_id=<?php echo $selectedNurse; ?>&month=<?php echo urlencode($selectedMonth); ?>&approval_level=<?php echo urlencode($approvalLevel); ?>" class="btn btn-sm btn-success">
                        <i class="fas fa-download"></i> Download CSV
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($shifts)): ?>
                        <p class="text-muted">No approved shifts for this nurse in the selected month with the chosen approval level.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Patient</th>
                                        <th>Start Time</th>
                                        <th>End Time</th>
                                        <th>Hours</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $totalHours = 0;
                                    foreach ($shifts as $shift):
                                        $start = $shift['start_time'];
                                        $end = $shift['end_time'];
                                        $startMin = (int)substr($start,0,2)*60 + (int)substr($start,3,2);
                                        $endMin = (int)substr($end,0,2)*60 + (int)substr($end,3,2);
                                        if ($endMin < $startMin) $endMin += 24*60;
                                        $hours = round(($endMin - $startMin) / 60, 1);
                                        $totalHours += $hours;
                                    ?>
                                    <tr>
                                        <td><?php echo formatDate($shift['shift_date']); ?></td>
                                        <td><?php echo htmlspecialchars($shift['patient_name']); ?></td>
                                        <td><?php echo formatTime($shift['start_time']); ?></td>
                                        <td><?php echo formatTime($shift['end_time']); ?></td>
                                        <td><?php echo $hours; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="4" class="text-end">Total Hours:</th>
                                        <th><?php echo $totalHours; ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($selectedNurse && empty($nurseName)): ?>
            <div class="alert alert-danger">Selected nurse not found.</div>
        <?php endif; ?>
    </main>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.select2').select2({
                placeholder: '-- Select Nurse (or leave empty for preview) --',
                allowClear: true,
                width: '100%'
            });
        });

        <?php if (!empty($chartData) && array_sum($chartData['values']) > 0): ?>
        // Render chart
        const ctx = document.getElementById('hoursChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chartData['labels']); ?>,
                datasets: [{
                    label: 'Hours Worked',
                    data: <?php echo json_encode($chartData['values']); ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Hours' } },
                    x: { title: { display: true, text: 'Day of Month' } }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>