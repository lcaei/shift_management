<?php
/**
 * VitalZone - Patient Management
 * CRUD operations for patients + CSV import/export + filters.
 * Now allows adding multiple patients at once.
 * Phone is collected and stored.
 * For super_admin: phone column visible and editable.
 * For other roles: phone hidden and not editable (static message).
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Only admins and roaster can access (super_admin always allowed)
requireRole(['super_admin', 'roaster', 'admin1', 'admin2', 'admin3']);

$role = getRole();
$isSuperAdmin = ($role == 'super_admin');

$message = '';
$error = '';

// Get distinct cities and doctors for filters (for dropdowns)
$cities = $pdo->query("SELECT DISTINCT city FROM patients WHERE city != '' ORDER BY city")->fetchAll(PDO::FETCH_COLUMN);
$doctors = $pdo->query("SELECT DISTINCT doctor FROM patients WHERE doctor != '' ORDER BY doctor")->fetchAll(PDO::FETCH_COLUMN);

// Filters
$searchName   = isset($_GET['name']) ? trim($_GET['name']) : '';
$searchCity   = isset($_GET['city']) ? trim($_GET['city']) : '';
$searchDoctor = isset($_GET['doctor']) ? trim($_GET['doctor']) : '';

// Handle POST actions (delete, add_multiple, edit, import)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Delete patient
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM patients WHERE id = ?");
            $stmt->execute([$id]);
            logAction($pdo, $_SESSION['user_id'], 'DELETE', 'patients', $id, "Deleted patient ID $id");
            redirectWithMessage('patient.php', 'Patient deleted successfully.', 'success');
        } catch (PDOException $e) {
            redirectWithMessage('patient.php', 'Error deleting patient: ' . $e->getMessage(), 'error');
        }
    }

    // Add multiple patients (if name[] is an array)
    if (isset($_POST['action']) && $_POST['action'] === 'add_multiple' && isset($_POST['name']) && is_array($_POST['name'])) {
        $names = $_POST['name'];
        $addresses = $_POST['address'] ?? [];
        $cities = $_POST['city'] ?? [];
        $locationPins = $_POST['location_pin'] ?? [];
        $phones = $_POST['phone'] ?? [];
        $doctors = $_POST['doctor'] ?? [];
        $notes = $_POST['notes'] ?? [];

        $inserted = 0;
        $errors = [];
        for ($i = 0; $i < count($names); $i++) {
            $name = trim($names[$i] ?? '');
            if (empty($name)) continue; // skip empty rows

            $address = trim($addresses[$i] ?? '');
            $city = trim($cities[$i] ?? '');
            $locationPin = trim($locationPins[$i] ?? '');
            $phone = trim($phones[$i] ?? '');
            $doctor = trim($doctors[$i] ?? '');
            $note = trim($notes[$i] ?? '');

            try {
                $stmt = $pdo->prepare("INSERT INTO patients (name, address, city, location_pin, phone, doctor, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $address, $city, $locationPin, $phone, $doctor, $note]);
                $inserted++;
            } catch (PDOException $e) {
                $errors[] = "Row $i: " . $e->getMessage();
            }
        }
        if ($inserted > 0) {
            $message = "$inserted patients added successfully.";
            logAction($pdo, $_SESSION['user_id'], 'INSERT_MULTIPLE', 'patients', null, "Added $inserted patients");
        }
        if (!empty($errors)) {
            $error = implode('<br>', $errors);
        }
        if ($inserted == 0 && empty($errors)) {
            $error = 'No valid entries found.';
        }
        // Stay on same page to show messages
    }

    // Edit single patient
    if (isset($_POST['action']) && $_POST['action'] === 'edit' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $locationPin = trim($_POST['location_pin'] ?? '');
        $phone = trim($_POST['phone'] ?? ''); // from input (may be hidden for non-super)
        $doctor = trim($_POST['doctor'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (empty($name)) {
            $error = 'Patient name is required.';
        } else {
            $stmt = $pdo->prepare("UPDATE patients SET name = ?, address = ?, city = ?, location_pin = ?, phone = ?, doctor = ?, notes = ? WHERE id = ?");
            try {
                $stmt->execute([$name, $address, $city, $locationPin, $phone, $doctor, $notes, $id]);
                logAction($pdo, $_SESSION['user_id'], 'UPDATE', 'patients', $id, "Updated patient: $name");
                redirectWithMessage('patient.php', 'Patient updated successfully.', 'success');
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }

    // CSV IMPORT (with phone column)
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
                $expected = ['name', 'address', 'city', 'location_pin', 'phone', 'doctor', 'notes'];
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
                        $name = trim($data['name'] ?? '');
                        $address = trim($data['address'] ?? '');
                        $city = trim($data['city'] ?? '');
                        $locationPin = trim($data['location_pin'] ?? '');
                        $phone = trim($data['phone'] ?? '');
                        $doctor = trim($data['doctor'] ?? '');
                        $notes = trim($data['notes'] ?? '');

                        if (empty($name)) {
                            $errors[] = "Row $rowNum: name is required.";
                            $rowNum++;
                            continue;
                        }

                        try {
                            $stmt = $pdo->prepare("INSERT INTO patients (name, address, city, location_pin, phone, doctor, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$name, $address, $city, $locationPin, $phone, $doctor, $notes]);
                            $imported++;
                        } catch (PDOException $e) {
                            $errors[] = "Row $rowNum: database error - " . $e->getMessage();
                        }
                        $rowNum++;
                    }
                    fclose($handle);
                    if ($imported > 0) {
                        $message = "$imported patients imported successfully.";
                        logAction($pdo, $_SESSION['user_id'], 'IMPORT', 'patients', null, "Imported $imported patients via CSV");
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

// Build patients query with filters
$sql = "SELECT * FROM patients WHERE 1=1";
$params = [];

if (!empty($searchName)) {
    $sql .= " AND name LIKE ?";
    $params[] = "%$searchName%";
}
if (!empty($searchCity)) {
    $sql .= " AND city = ?";
    $params[] = $searchCity;
}
if (!empty($searchDoctor)) {
    $sql .= " AND doctor = ?";
    $params[] = $searchDoctor;
}
$sql .= " ORDER BY name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll();

// Determine if we are editing a specific patient
$editPatient = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$id]);
    $editPatient = $stmt->fetch();
    if (!$editPatient) {
        redirectWithMessage('patient.php', 'Patient not found.', 'error');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VitalZone - Manage Patients</title>
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
        .share-btn { cursor: pointer; color: #17a2b8; margin-left: 5px; }
        .share-btn:hover { color: #138496; }
        .select2-container {
            width: 100% !important;
        }
        .static-message {
            font-style: italic;
            color: #6c757d;
            padding-top: 0.5rem;
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
                        <a class="nav-link active" href="patient.php"><i class="fas fa-home me-1"></i>Patients</a>
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
            <div class="row">
                <div class="col-12">
                    <?php echo $flash; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Page header with Export button -->
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
            <h1 class="h2">Manage Patients</h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <a href="api/export_patients.php" class="btn btn-sm btn-success me-2">
                    <i class="fas fa-download"></i> Export CSV
                </a>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#patientModal" onclick="clearForm()">
                    <i class="fas fa-plus"></i> Add New Patient
                </button>
            </div>
        </div>

        <!-- Filter Bar with searchable dropdowns -->
        <div class="filter-bar">
            <form method="get" action="patient.php" class="row g-3">
                <div class="col-md-3">
                    <label for="name" class="form-label">Name</label>
                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($searchName); ?>" placeholder="Search name">
                </div>
                <div class="col-md-3">
                    <label for="city" class="form-label">City</label>
                    <select name="city" id="city" class="form-select select2">
                        <option value="">All Cities</option>
                        <?php foreach ($cities as $city): ?>
                            <option value="<?php echo htmlspecialchars($city); ?>" <?php echo ($searchCity == $city) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($city); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="doctor" class="form-label">Doctor</label>
                    <select name="doctor" id="doctor" class="form-select select2">
                        <option value="">All Doctors</option>
                        <?php foreach ($doctors as $doc): ?>
                            <option value="<?php echo htmlspecialchars($doc); ?>" <?php echo ($searchDoctor == $doc) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($doc); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2"><i class="fas fa-search"></i> Search</button>
                    <a href="patient.php" class="btn btn-clear"><i class="fas fa-times"></i> Clear</a>
                </div>
            </form>
        </div>

        <!-- Import CSV Section (phone column included) -->
        <div class="import-card">
            <h5><i class="fas fa-file-import"></i> Import Patients from CSV</h5>
            <p class="text-muted">Upload a CSV file with columns: <code>name, address, city, location_pin, phone, doctor, notes</code>. The first row must be the header.</p>
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
                    <a href="data:text/csv;charset=utf-8,name,address,city,location_pin,phone,doctor,notes%0AJohn%20Doe,123%20Main%20St,Springfield,https://maps.app.goo.gl/abc123,555-1234,Dr.%20Brown,Diabetic%0AJane%20Smith,456%20Oak%20Ave,Shelbyville,https://maps.app.goo.gl/xyz789,555-5678,Dr.%20Green,Mobility%20assistance"
                       download="patient_template.csv" class="btn btn-outline-secondary">
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

        <!-- Patients table (Phone column visible only for super_admin) -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">All Patients (<?php echo count($patients); ?>)</h6>
            </div>
            <div class="card-body">
                <?php if (empty($patients)): ?>
                    <p class="text-muted">No patients found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Address</th>
                                    <th>City</th>
                                    <th>Location Pin</th>
                                    <?php if ($isSuperAdmin): ?>
                                        <th>Phone</th>
                                    <?php endif; ?>
                                    <th>Doctor</th>
                                    <th>Notes</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($patients as $patient): ?>
                                    <tr>
                                        <td><?php echo $patient['id']; ?></td>
                                        <td><?php echo htmlspecialchars($patient['name']); ?></td>
                                        <td><?php echo htmlspecialchars($patient['address']); ?></td>
                                        <td><?php echo htmlspecialchars($patient['city']); ?></td>
                                        <td>
                                            <?php if (!empty($patient['location_pin'])): ?>
                                                <a href="<?php echo htmlspecialchars($patient['location_pin']); ?>" target="_blank">
                                                    <i class="fas fa-map-marker-alt"></i> Map
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($isSuperAdmin): ?>
                                            <td><?php echo htmlspecialchars($patient['phone']); ?></td>
                                        <?php endif; ?>
                                        <td><?php echo htmlspecialchars($patient['doctor']); ?></td>
                                        <td><?php echo htmlspecialchars(substr($patient['notes'], 0, 50)) . (strlen($patient['notes']) > 50 ? '…' : ''); ?></td>
                                        <td>
                                            <a href="?edit=<?php echo $patient['id']; ?>" class="btn btn-sm btn-primary" onclick='loadEditData(<?php echo json_encode($patient); ?>); return false;'>
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this patient?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $patient['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                            <i class="fas fa-share-alt share-btn" onclick="sharePatient(<?php echo $patient['id']; ?>, '<?php echo htmlspecialchars($patient['name']); ?>', '<?php echo htmlspecialchars($patient['address']); ?>', '<?php echo htmlspecialchars($patient['location_pin']); ?>')"></i>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add/Edit Modal (phone visible for add, for edit: editable only for super_admin) -->
        <div class="modal fade" id="patientModal" tabindex="-1" aria-labelledby="patientModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="post" action="patient.php" id="multiPatientForm">
                        <div class="modal-header">
                            <h5 class="modal-title" id="patientModalLabel">Add New Patient</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="action" id="action" value="add_multiple">
                            <input type="hidden" name="id" id="patientId" value="">

                            <div id="patient-rows-container">
                                <!-- First row (will be cloned) -->
                                <div class="row multi-row">
                                    <div class="col-md-6 mb-2">
                                        <input type="text" class="form-control" name="name[]" placeholder="Name *" required>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <input type="text" class="form-control" name="address[]" placeholder="Address">
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <input type="text" class="form-control" name="city[]" placeholder="City">
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <input type="url" class="form-control" name="location_pin[]" placeholder="Location Pin (URL)">
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <input type="text" class="form-control" name="phone[]" placeholder="Phone">
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <input type="text" class="form-control" name="doctor[]" placeholder="Doctor">
                                    </div>
                                    <div class="col-md-10 mb-2">
                                        <textarea class="form-control" name="notes[]" placeholder="Notes" rows="1"></textarea>
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <i class="fas fa-times remove-row" style="display:none;"></i>
                                    </div>
                                </div>
                            </div>

                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="addAnotherRow">
                                <i class="fas fa-plus"></i> Add another patient
                            </button>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Patients</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Share Modal (fallback) -->
        <div class="modal fade" id="shareModal" tabindex="-1" aria-labelledby="shareModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="shareModalLabel">Share Patient</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="shareModalContent">
                        <!-- Populated by JS -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
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
        // Initialize Select2 for city and doctor filters
        $(document).ready(function() {
            $('.select2').select2({
                placeholder: 'All',
                allowClear: true,
                width: '100%'
            });
        });

        // Flag to know if current user is super_admin
        const isSuperAdmin = <?php echo $isSuperAdmin ? 'true' : 'false'; ?>;

        // Share function for patients (name, address, location pin only)
        function sharePatient(id, name, address, locationPin) {
            let shareText = `Patient: ${name}\nAddress: ${address}`;
            if (locationPin) {
                shareText += `\nLocation: ${locationPin}`;
            }
            if (navigator.share) {
                navigator.share({
                    title: 'Patient Information',
                    text: shareText,
                })
                .catch(err => console.log('Share cancelled', err));
            } else {
                let modalContent = document.getElementById('shareModalContent');
                let encodedText = encodeURIComponent(shareText);
                let emailLink = `mailto:?subject=Patient%20Information&body=${encodedText}`;
                let whatsappLink = `https://wa.me/?text=${encodedText}`;
                modalContent.innerHTML = `
                    <p>${shareText.replace(/\n/g, '<br>')}</p>
                    <hr>
                    <a href="${emailLink}" target="_blank" class="btn btn-outline-primary me-2"><i class="fas fa-envelope"></i> Email</a>
                    <a href="${whatsappLink}" target="_blank" class="btn btn-outline-success"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                `;
                new bootstrap.Modal(document.getElementById('shareModal')).show();
            }
        }

        // Handle adding multiple rows
        document.getElementById('addAnotherRow').addEventListener('click', function() {
            let container = document.getElementById('patient-rows-container');
            let firstRow = container.querySelector('.multi-row');
            let newRow = firstRow.cloneNode(true);

            // Clear input values
            newRow.querySelectorAll('input, textarea').forEach(input => input.value = '');
            // Show the remove icon for this row
            let removeIcon = newRow.querySelector('.remove-row');
            if (removeIcon) {
                removeIcon.style.display = 'inline-block';
                removeIcon.onclick = function() { this.closest('.multi-row').remove(); };
            }
            container.appendChild(newRow);
        });

        // For editing a single patient
        function loadEditData(patient) {
            document.getElementById('action').value = 'edit';
            document.getElementById('patientId').value = patient.id;

            let container = document.getElementById('patient-rows-container');
            container.innerHTML = ''; // clear all rows

            // Build the phone field based on role
            let phoneFieldHtml = '';
            if (isSuperAdmin) {
                phoneFieldHtml = `
                    <div class="col-md-3 mb-2">
                        <input type="text" class="form-control" name="phone" value="${escapeHtml(patient.phone || '')}" placeholder="Phone">
                    </div>
                `;
            } else {
                phoneFieldHtml = `
                    <div class="col-md-3 mb-2">
                        <div class="static-message">Contact super admin to modify phone number</div>
                        <input type="hidden" name="phone" value="${escapeHtml(patient.phone || '')}">
                    </div>
                `;
            }

            // Create a single row for editing
            let rowDiv = document.createElement('div');
            rowDiv.className = 'row multi-row';
            rowDiv.innerHTML = `
                <div class="col-md-6 mb-2">
                    <input type="text" class="form-control" name="name" value="${escapeHtml(patient.name || '')}" placeholder="Name *" required>
                </div>
                <div class="col-md-6 mb-2">
                    <input type="text" class="form-control" name="address" value="${escapeHtml(patient.address || '')}" placeholder="Address">
                </div>
                <div class="col-md-3 mb-2">
                    <input type="text" class="form-control" name="city" value="${escapeHtml(patient.city || '')}" placeholder="City">
                </div>
                <div class="col-md-3 mb-2">
                    <input type="url" class="form-control" name="location_pin" value="${escapeHtml(patient.location_pin || '')}" placeholder="Location Pin (URL)">
                </div>
                ${phoneFieldHtml}
                <div class="col-md-3 mb-2">
                    <input type="text" class="form-control" name="doctor" value="${escapeHtml(patient.doctor || '')}" placeholder="Doctor">
                </div>
                <div class="col-md-10 mb-2">
                    <textarea class="form-control" name="notes" placeholder="Notes" rows="1">${escapeHtml(patient.notes || '')}</textarea>
                </div>
                <div class="col-md-2 mb-2">
                    <!-- no remove icon -->
                </div>
            `;
            container.appendChild(rowDiv);

            // Hide the "Add another" button during edit
            document.getElementById('addAnotherRow').style.display = 'none';

            document.getElementById('patientModalLabel').innerText = 'Edit Patient';
            var modal = new bootstrap.Modal(document.getElementById('patientModal'));
            modal.show();
        }

        // Clear form for adding new patients (multiple)
        function clearForm() {
            document.getElementById('action').value = 'add_multiple';
            document.getElementById('patientId').value = '';
            document.getElementById('addAnotherRow').style.display = 'inline-block';

            let container = document.getElementById('patient-rows-container');
            container.innerHTML = ''; // clear all rows

            // Create a fresh first row
            let firstRow = document.createElement('div');
            firstRow.className = 'row multi-row';
            firstRow.innerHTML = `
                <div class="col-md-6 mb-2">
                    <input type="text" class="form-control" name="name[]" placeholder="Name *" required>
                </div>
                <div class="col-md-6 mb-2">
                    <input type="text" class="form-control" name="address[]" placeholder="Address">
                </div>
                <div class="col-md-3 mb-2">
                    <input type="text" class="form-control" name="city[]" placeholder="City">
                </div>
                <div class="col-md-3 mb-2">
                    <input type="url" class="form-control" name="location_pin[]" placeholder="Location Pin (URL)">
                </div>
                <div class="col-md-3 mb-2">
                    <input type="text" class="form-control" name="phone[]" placeholder="Phone">
                </div>
                <div class="col-md-3 mb-2">
                    <input type="text" class="form-control" name="doctor[]" placeholder="Doctor">
                </div>
                <div class="col-md-10 mb-2">
                    <textarea class="form-control" name="notes[]" placeholder="Notes" rows="1"></textarea>
                </div>
                <div class="col-md-2 mb-2">
                    <i class="fas fa-times remove-row" style="display:none;"></i>
                </div>
            `;
            container.appendChild(firstRow);

            document.getElementById('patientModalLabel').innerText = 'Add New Patient';
        }

        function escapeHtml(unsafe) {
            if (!unsafe) return '';
            return unsafe.replace(/[&<>"]/g, function(m) {
                if(m === '&') return '&amp;';
                if(m === '<') return '&lt;';
                if(m === '>') return '&gt;';
                if(m === '"') return '&quot;';
                return m;
            });
        }

        // If URL has ?edit=ID, trigger the edit modal automatically
        window.onload = function() {
            <?php if ($editPatient): ?>
                loadEditData(<?php echo json_encode($editPatient); ?>);
            <?php endif; ?>
        };
    </script>
</body>
</html>