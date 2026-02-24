<?php
/**
 * VitalZone - Profit Dashboard
 * Allows recording patient payments and calculates profit.
 * Now includes sorting and filtering options.
 * Accessible to finance and super_admin.
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Allowed roles
if (!isLoggedIn() || !in_array(getRole(), ['finance', 'super_admin'])) {
    header('Location: login.php');
    exit;
}

$role = getRole();
$message = '';
$error = '';

// Get all patients for dropdown (still needed for payment form)
$allPatients = getPatientsList($pdo);

// Handle payment submission (same as before)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_payment') {
        $patientId = (int)$_POST['patient_id'];
        $month = $_POST['month'];
        $amount = (float)$_POST['amount_paid'];

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $error = 'Invalid month format.';
        } elseif ($patientId <= 0) {
            $error = 'Please select a patient.';
        } elseif ($amount < 0) {
            $error = 'Amount cannot be negative.';
        } else {
            $monthStart = $month . '-01';
            $stmt = $pdo->prepare("INSERT INTO patient_payments (patient_id, month, amount_paid) VALUES (?, ?, ?)
                                    ON DUPLICATE KEY UPDATE amount_paid = ?");
            if ($stmt->execute([$patientId, $monthStart, $amount, $amount])) {
                $message = 'Payment recorded successfully.';
                logAction($pdo, $_SESSION['user_id'], 'PAYMENT', 'patient_payments', $patientId, "Recorded payment $amount for patient $patientId in $month");
            } else {
                $error = 'Database error.';
            }
        }
    } elseif ($_POST['action'] === 'delete_payment' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM patient_payments WHERE id = ?");
        if ($stmt->execute([$id])) {
            $message = 'Payment deleted.';
            logAction($pdo, $_SESSION['user_id'], 'DELETE_PAYMENT', 'patient_payments', $id, "Deleted payment ID $id");
        } else {
            $error = 'Error deleting payment.';
        }
    }
}

// Get selected month from URL or default to current
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

// ==================== FILTERING & SORTING ====================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'name';
$dir = isset($_GET['dir']) && strtoupper($_GET['dir']) == 'DESC' ? 'DESC' : 'ASC';

// Helper function to build sort links
function sortLink($column, $label) {
    global $selectedMonth, $search, $sort, $dir;
    $params = $_GET;
    $params['sort'] = $column;
    // Determine new direction
    $newDir = 'ASC';
    if ($sort == $column) {
        $newDir = ($dir == 'ASC') ? 'DESC' : 'ASC';
    }
    $params['dir'] = $newDir;
    // Keep other parameters
    unset($params['page']); // not used
    $query = http_build_query($params);
    $arrow = '';
    if ($sort == $column) {
        $arrow = ($dir == 'ASC') ? ' ↑' : ' ↓';
    }
    return '<a href="?' . $query . '" class="text-dark text-decoration-none">' . $label . $arrow . '</a>';
}

// Fetch all patients (base list)
$sql = "SELECT p.id, p.name,
               COALESCE(pp.amount_paid, 0) AS amount_paid,
               pp.id AS payment_id
        FROM patients p
        LEFT JOIN patient_payments pp ON p.id = pp.patient_id AND pp.month = ?
        WHERE 1=1";
$params = [$monthStart];

// Apply patient name filter
if (!empty($search)) {
    $sql .= " AND p.name LIKE ?";
    $params[] = "%$search%";
}
$sql .= " ORDER BY p.name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll();

// Fetch all shifts in the month (fully approved only) to calculate nurse costs
$stmt = $pdo->prepare("
    SELECT s.*, n.name AS nurse_name, n.hourly_wage, eg.default_hourly_wage AS group_wage,
           p.id AS patient_id, p.name AS patient_name
    FROM shifts s
    JOIN nurses n ON s.nurse_id = n.id
    LEFT JOIN employee_groups eg ON n.group_id = eg.id
    JOIN patients p ON s.patient_id = p.id
    WHERE s.shift_date BETWEEN ? AND ?
      AND s.fully_approved = 1
    ORDER BY s.shift_date, s.start_time
");
$stmt->execute([$monthStart, $monthEnd]);
$shifts = $stmt->fetchAll();

// Calculate costs per patient
$patientCosts = [];
$totalCost = 0;
$totalPaid = 0;
foreach ($shifts as $shift) {
    $patientId = $shift['patient_id'];
    $startMin = (int)substr($shift['start_time'],0,2)*60 + (int)substr($shift['start_time'],3,2);
    $endMin = (int)substr($shift['end_time'],0,2)*60 + (int)substr($shift['end_time'],3,2);
    if ($endMin < $startMin) $endMin += 24*60;
    $hours = round(($endMin - $startMin) / 60, 2);
    $wage = $shift['hourly_wage'] ?? $shift['group_wage'] ?? 0;
    $cost = $hours * $wage;

    if (!isset($patientCosts[$patientId])) {
        $patientCosts[$patientId] = [
            'hours' => 0,
            'cost' => 0
        ];
    }
    $patientCosts[$patientId]['hours'] += $hours;
    $patientCosts[$patientId]['cost'] += $cost;
    $totalCost += $cost;
}

// Attach cost data to each patient
foreach ($patients as &$patient) {
    $pid = $patient['id'];
    $patient['hours'] = $patientCosts[$pid]['hours'] ?? 0;
    $patient['cost'] = $patientCosts[$pid]['cost'] ?? 0;
    $patient['profit'] = $patient['amount_paid'] - $patient['cost'];
    $totalPaid += $patient['amount_paid'];
}
unset($patient);

// ==================== SORTING ====================
if ($sort == 'hours') {
    usort($patients, function($a, $b) use ($dir) {
        return ($dir == 'ASC') ? $a['hours'] <=> $b['hours'] : $b['hours'] <=> $a['hours'];
    });
} elseif ($sort == 'cost') {
    usort($patients, function($a, $b) use ($dir) {
        return ($dir == 'ASC') ? $a['cost'] <=> $b['cost'] : $b['cost'] <=> $a['cost'];
    });
} elseif ($sort == 'payment') {
    usort($patients, function($a, $b) use ($dir) {
        return ($dir == 'ASC') ? $a['amount_paid'] <=> $b['amount_paid'] : $b['amount_paid'] <=> $a['amount_paid'];
    });
} elseif ($sort == 'profit') {
    usort($patients, function($a, $b) use ($dir) {
        return ($dir == 'ASC') ? $a['profit'] <=> $b['profit'] : $b['profit'] <=> $a['profit'];
    });
} else { // default 'name'
    usort($patients, function($a, $b) use ($dir) {
        return ($dir == 'ASC') ? strcmp($a['name'], $b['name']) : strcmp($b['name'], $a['name']);
    });
}

$totalProfit = $totalPaid - $totalCost;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VitalZone - Profit Dashboard</title>
    <!-- Bootstrap 5 -->
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
        .navbar-custom .navbar-brand { color: white; font-weight: bold; }
        .navbar-custom .nav-link { color: #b0c4de; }
        .navbar-custom .nav-link:hover { color: white; }
        .navbar-custom .nav-link.active { color: white; background-color: #0d6efd; border-radius: 0.25rem; }
        .navbar-custom .dropdown-menu { background-color: #2c3e50; }
        .navbar-custom .dropdown-item { color: #b0c4de; }
        .navbar-custom .dropdown-item:hover { background-color: #0d6efd; color: white; }
        .card { border: none; box-shadow: 0 0.15rem 1.75rem 0 rgba(58,59,69,0.15); margin-bottom: 1.5rem; }
        .card-header { background-color: #f8f9fc; border-bottom: 1px solid #e3e6f0; font-weight: 600; }
        .filter-bar { background: white; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .btn-clear { background-color: #6c757d; color: white; }
        .btn-clear:hover { background-color: #5a6268; }
        .summary-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 0.5rem; padding: 1rem; }
        .summary-card .value { font-size: 1.8rem; font-weight: bold; }
        .profit-positive { color: #28a745; font-weight: bold; }
        .profit-negative { color: #dc3545; font-weight: bold; }
        .select2-container { width: 100% !important; }
        .table th a { text-decoration: none; color: inherit; }
        .table th a:hover { text-decoration: underline; }
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
                    <!-- Dashboard – always visible -->
                    <li class="nav-item">
                        <a class="nav-link" href="index.php"><i class="fas fa-tachometer-alt me-1"></i>Dashboard</a>
                    </li>

                    <!-- Super_admin only links -->
                    <?php if ($role == 'super_admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="nurse.php"><i class="fas fa-user-nurse me-1"></i>Employee</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="patient.php"><i class="fas fa-home me-1"></i>Patients</a>
                        </li>
                    <?php endif; ?>

                    <!-- Schedule Board – always visible -->
                    <li class="nav-item">
                        <a class="nav-link" href="schedule.php"><i class="fas fa-calendar-check me-1"></i>Schedule Board</a>
                    </li>

                    <!-- Super_admin only: management pages -->
                    <?php if ($role == 'super_admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="manage_shifts.php"><i class="fas fa-list me-1"></i>Manage Shifts</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="groups.php"><i class="fas fa-users-cog me-1"></i>Employee Groups</a>
                        </li>
                    <?php endif; ?>

                    <!-- Shift Reports – always visible -->
                    <li class="nav-item">
                        <a class="nav-link" href="finance_shifts.php"><i class="fas fa-file-csv me-1"></i>Shift Reports</a>
                    </li>

                    <!-- Profit Dashboard – always visible (current page) -->
                    <li class="nav-item">
                        <a class="nav-link active" href="profit.php"><i class="fas fa-chart-line me-1"></i>Profit Dashboard</a>
                    </li>

                    <!-- System Logs – super_admin only -->
                    <?php if ($role == 'super_admin'): ?>
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

    <!-- Main content (unchanged) -->
    <main class="container-fluid px-md-4 py-4">
        <!-- Flash messages -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <!-- Page header -->
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
            <h1 class="h2">Profit Dashboard</h1>
        </div>

        <!-- Month and filter bar -->
        <div class="filter-bar">
            <form method="get" action="profit.php" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="month" class="form-label">Select Month</label>
                    <input type="month" class="form-control" id="month" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="search" class="form-label">Patient Name</label>
                    <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="e.g. John">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i>Filter</button>
                    <a href="profit.php?month=<?php echo urlencode($selectedMonth); ?>" class="btn btn-clear"><i class="fas fa-times me-1"></i>Clear</a>
                </div>
            </form>
        </div>

        <!-- Payment entry card -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-money-bill me-2"></i>Record Patient Payment
            </div>
            <div class="card-body">
                <form method="post" action="profit.php" class="row g-3">
                    <input type="hidden" name="action" value="save_payment">
                    <div class="col-md-4">
                        <label for="patient_id" class="form-label">Patient</label>
                        <select name="patient_id" id="patient_id" class="form-select select2" required>
                            <option value="">-- Select Patient --</option>
                            <?php foreach ($allPatients as $id => $name): ?>
                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="month_entry" class="form-label">Month</label>
                        <input type="month" class="form-control" id="month_entry" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label for="amount_paid" class="form-label">Amount Paid (AED)</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="amount_paid" name="amount_paid" required>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-success w-100"><i class="fas fa-save me-1"></i>Save</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary cards -->
        <div class="row">
            <div class="col-md-4">
                <div class="summary-card">
                    <div class="value"><?php echo number_format($totalPaid, 2); ?> AED</div>
                    <div>Total Payments Received</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="summary-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="value"><?php echo number_format($totalCost, 2); ?> AED</div>
                    <div>Total Nurse Costs</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="summary-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="value <?php echo $totalProfit >= 0 ? 'profit-positive' : 'profit-negative'; ?>"><?php echo number_format($totalProfit, 2); ?> AED</div>
                    <div>Net Profit</div>
                </div>
            </div>
        </div>

        <!-- Profit table with sortable headers -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-table me-2"></i>Profit Breakdown – <?php echo date('F Y', strtotime($selectedMonth)); ?>
                <?php if (!empty($search)): ?>
                    <span class="badge bg-info ms-2">Filter: "<?php echo htmlspecialchars($search); ?>"</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($patients)): ?>
                    <p class="text-muted">No patients match your filter.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th><?php echo sortLink('name', 'Patient'); ?></th>
                                    <th><?php echo sortLink('hours', 'Total Hours'); ?></th>
                                    <th><?php echo sortLink('cost', 'Nurse Cost (AED)'); ?></th>
                                    <th><?php echo sortLink('payment', 'Payment Received (AED)'); ?></th>
                                    <th><?php echo sortLink('profit', 'Profit (AED)'); ?></th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($patients as $patient): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($patient['name']); ?></td>
                                        <td><?php echo number_format($patient['hours'], 1); ?></td>
                                        <td><?php echo number_format($patient['cost'], 2); ?></td>
                                        <td><?php echo number_format($patient['amount_paid'], 2); ?></td>
                                        <td class="<?php echo $patient['profit'] >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                            <?php echo number_format($patient['profit'], 2); ?>
                                        </td>
                                        <td>
                                            <?php if ($patient['payment_id']): ?>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this payment?');">
                                                    <input type="hidden" name="action" value="delete_payment">
                                                    <input type="hidden" name="id" value="<?php echo $patient['payment_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete payment"><i class="fas fa-trash"></i></button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-secondary">
                                <tr>
                                    <th>Totals</th>
                                    <th><?php echo number_format(array_sum(array_column($patients, 'hours')), 1); ?></th>
                                    <th><?php echo number_format($totalCost, 2); ?></th>
                                    <th><?php echo number_format($totalPaid, 2); ?></th>
                                    <th class="<?php echo $totalProfit >= 0 ? 'profit-positive' : 'profit-negative'; ?>"><?php echo number_format($totalProfit, 2); ?></th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
                <p class="text-muted small mt-2"><i class="fas fa-info-circle me-1"></i> Nurse costs are calculated using individual hourly wages or group defaults. Only fully approved shifts are included.</p>
            </div>
        </div>
    </main>

    <!-- jQuery and Select2 -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.select2').select2({
                placeholder: '-- Select --',
                allowClear: true,
                width: '100%'
            });
        });
    </script>
</body>
</html>