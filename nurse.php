<?php
/**
 * VitalZone - Employee (Nurse) Management
 * CRUD operations for nurses + CSV import/export + filters.
 * Now allows adding multiple nurses at once.
 * Hourly wage is automatically derived from the selected group.
 * Added group filter dropdown and share button.
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Only admins and roaster can access (super_admin always allowed)
requireRole(['super_admin', 'roaster', 'admin1', 'admin2', 'admin3']);

$role = getRole();

$message = '';
$error = '';

// Fetch employee groups for dropdown and filter
$groups = getEmployeeGroupsList($pdo);

// Filters
$searchName  = isset($_GET['name']) ? trim($_GET['name']) : '';
$searchPhone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
$searchEmail = isset($_GET['email']) ? trim($_GET['email']) : '';
$groupFilter = isset($_GET['group_id']) && $_GET['group_id'] !== '' ? (int)$_GET['group_id'] : null;

// Handle POST actions (delete, edit, add_multiple, import)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Delete nurse
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM nurses WHERE id = ?");
            $stmt->execute([$id]);
            logAction($pdo, $_SESSION['user_id'], 'DELETE', 'nurses', $id, "Deleted nurse ID $id");
            redirectWithMessage('nurse.php', 'Nurse deleted successfully.', 'success');
        } catch (PDOException $e) {
            redirectWithMessage('nurse.php', 'Error deleting nurse: ' . $e->getMessage(), 'error');
        }
    }

    // Add multiple nurses (if name[] is an array)
    if (isset($_POST['action']) && $_POST['action'] === 'add_multiple' && isset($_POST['name']) && is_array($_POST['name'])) {
        $names = $_POST['name'];
        $phones = $_POST['phone'] ?? [];
        $emails = $_POST['email'] ?? [];
        $addresses = $_POST['address'] ?? [];
        $groupIds = $_POST['group_id'] ?? [];

        $inserted = 0;
        $errors = [];
        for ($i = 0; $i < count($names); $i++) {
            $name = trim($names[$i] ?? '');
            if (empty($name)) continue; // skip empty rows

            $phone = trim($phones[$i] ?? '');
            $email = trim($emails[$i] ?? '');
            $address = trim($addresses[$i] ?? '');
            $groupId = !empty($groupIds[$i]) ? (int)$groupIds[$i] : null;

            try {
                $stmt = $pdo->prepare("INSERT INTO nurses (name, phone, email, address, group_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $phone, $email, $address, $groupId]);
                $inserted++;
            } catch (PDOException $e) {
                $errors[] = "Row $i: " . $e->getMessage();
            }
        }
        if ($inserted > 0) {
            $message = "$inserted nurses added successfully.";
            logAction($pdo, $_SESSION['user_id'], 'INSERT_MULTIPLE', 'nurses', null, "Added $inserted nurses");
        }
        if (!empty($errors)) {
            $error = implode('<br>', $errors);
        }
        if ($inserted == 0 && empty($errors)) {
            $error = 'No valid entries found.';
        }
        // Stay on the same page without redirect to show messages
    }

    // Edit single nurse
    if (isset($_POST['action']) && $_POST['action'] === 'edit' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $groupId = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;

        if (empty($name)) {
            $error = 'Nurse name is required.';
        } else {
            $stmt = $pdo->prepare("UPDATE nurses SET name = ?, phone = ?, email = ?, address = ?, group_id = ? WHERE id = ?");
            try {
                $stmt->execute([$name, $phone, $email, $address, $groupId, $id]);
                logAction($pdo, $_SESSION['user_id'], 'UPDATE', 'nurses', $id, "Updated nurse: $name");
                redirectWithMessage('nurse.php', 'Nurse updated successfully.', 'success');
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }

    // CSV IMPORT (unchanged)
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
                $expected = ['name', 'phone', 'email', 'address', 'group'];
                $headerLower = array_map('strtolower', $header);
                $missing = array_diff($expected, $headerLower);
                if (count($missing) > 0) {
                    $error = 'CSV must have headers: name, phone, email, address, group';
                } else {
                    $imported = 0;
                    $errors = [];
                    $rowNum = 2;
                    while (($row = fgetcsv($handle)) !== false) {
                        if (count(array_filter($row)) == 0) continue;
                        $data = array_combine($headerLower, array_pad($row, count($headerLower), ''));
                        $name = trim($data['name'] ?? '');
                        $phone = trim($data['phone'] ?? '');
                        $email = trim($data['email'] ?? '');
                        $address = trim($data['address'] ?? '');
                        $groupName = trim($data['group'] ?? '');

                        if (empty($name)) {
                            $errors[] = "Row $rowNum: name is required.";
                            $rowNum++;
                            continue;
                        }

                        // Find group_id by group name if provided
                        $groupId = null;
                        if (!empty($groupName)) {
                            $stmt = $pdo->prepare("SELECT id FROM employee_groups WHERE group_name = ?");
                            $stmt->execute([$groupName]);
                            $group = $stmt->fetch();
                            if ($group) {
                                $groupId = $group['id'];
                            } else {
                                $errors[] = "Row $rowNum: group '$groupName' not found.";
                                $rowNum++;
                                continue;
                            }
                        }

                        try {
                            $stmt = $pdo->prepare("INSERT INTO nurses (name, phone, email, address, group_id) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$name, $phone, $email, $address, $groupId]);
                            $imported++;
                        } catch (PDOException $e) {
                            $errors[] = "Row $rowNum: database error - " . $e->getMessage();
                        }
                        $rowNum++;
                    }
                    fclose($handle);
                    if ($imported > 0) {
                        $message = "$imported nurses imported successfully.";
                        logAction($pdo, $_SESSION['user_id'], 'IMPORT', 'nurses', null, "Imported $imported nurses via CSV");
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

// Build nurses query with filters
$sql = "SELECT n.*, eg.group_name
        FROM nurses n
        LEFT JOIN employee_groups eg ON n.group_id = eg.id
        WHERE 1=1";
$params = [];

if (!empty($searchName)) {
    $sql .= " AND n.name LIKE ?";
    $params[] = "%$searchName%";
}
if (!empty($searchPhone)) {
    $sql .= " AND n.phone LIKE ?";
    $params[] = "%$searchPhone%";
}
if (!empty($searchEmail)) {
    $sql .= " AND n.email LIKE ?";
    $params[] = "%$searchEmail%";
}
if ($groupFilter) {
    $sql .= " AND n.group_id = ?";
    $params[] = $groupFilter;
}
$sql .= " ORDER BY n.name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$nurses = $stmt->fetchAll();

// Determine if we are editing a specific nurse
$editNurse = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM nurses WHERE id = ?");
    $stmt->execute([$id]);
    $editNurse = $stmt->fetch();
    if (!$editNurse) {
        redirectWithMessage('nurse.php', 'Nurse not found.', 'error');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VitalZone - Manage Employees</title>
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
                        <a class="nav-link active" href="nurse.php"><i class="fas fa-user-nurse me-1"></i>Employee</a>
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
            <h1 class="h2">Manage Employees</h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <a href="api/export_nurses.php" class="btn btn-sm btn-success me-2">
                    <i class="fas fa-download"></i> Export CSV
                </a>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#nurseModal" onclick="clearForm()">
                    <i class="fas fa-plus"></i> Add New Employee
                </button>
            </div>
        </div>

        <!-- Filter Bar (added group dropdown) -->
        <div class="filter-bar">
            <form method="get" action="nurse.php" class="row g-3">
                <div class="col-md-2">
                    <label for="name" class="form-label">Name</label>
                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($searchName); ?>" placeholder="Search name">
                </div>
                <div class="col-md-2">
                    <label for="phone" class="form-label">Phone</label>
                    <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($searchPhone); ?>" placeholder="Search phone">
                </div>
                <div class="col-md-2">
                    <label for="email" class="form-label">Email</label>
                    <input type="text" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($searchEmail); ?>" placeholder="Search email">
                </div>
                <div class="col-md-2">
                    <label for="group_id" class="form-label">Group</label>
                    <select name="group_id" id="group_id" class="form-select select2">
                        <option value="">All Groups</option>
                        <?php foreach ($groups as $id => $name): ?>
                            <option value="<?php echo $id; ?>" <?php echo ($groupFilter == $id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2"><i class="fas fa-search"></i> Search</button>
                    <a href="nurse.php" class="btn btn-clear"><i class="fas fa-times"></i> Clear</a>
                </div>
            </form>
        </div>

        <!-- Import CSV Section (unchanged) -->
        <div class="import-card">
            <h5><i class="fas fa-file-import"></i> Import Employees from CSV</h5>
            <p class="text-muted">Upload a CSV file with columns: <code>name, phone, email, address, group</code>. Group must match an existing group name. The first row must be the header.</p>
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
                    <a href="data:text/csv;charset=utf-8,name,phone,email,address,group%0AJohn%20Doe,555-1234,john.doe@example.com,123%20Main%20St,RN%0AJane%20Smith,555-5678,jane.smith@example.com,456%20Oak%20Ave,LPN"
                       download="employee_template.csv" class="btn btn-outline-secondary">
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

        <!-- Employees table (with share button) -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">All Employees (<?php echo count($nurses); ?>)</h6>
            </div>
            <div class="card-body">
                <?php if (empty($nurses)): ?>
                    <p class="text-muted">No employees found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th>Email</th>
                                    <th>Address</th>
                                    <th>Group</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($nurses as $nurse): ?>
                                    <tr>
                                        <td><?php echo $nurse['id']; ?></td>
                                        <td><?php echo htmlspecialchars($nurse['name']); ?></td>
                                        <td><?php echo htmlspecialchars($nurse['phone']); ?></td>
                                        <td><?php echo htmlspecialchars($nurse['email']); ?></td>
                                        <td><?php echo htmlspecialchars($nurse['address']); ?></td>
                                        <td><?php echo htmlspecialchars($nurse['group_name'] ?? ''); ?></td>
                                        <td>
                                            <a href="?edit=<?php echo $nurse['id']; ?>" class="btn btn-sm btn-primary" onclick="loadEditData(<?php echo htmlspecialchars(json_encode($nurse)); ?>); return false;">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this employee?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $nurse['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                            <i class="fas fa-share-alt share-btn" onclick="shareEmployee(<?php echo $nurse['id']; ?>, '<?php echo htmlspecialchars($nurse['name']); ?>', '<?php echo htmlspecialchars($nurse['phone']); ?>', '<?php echo htmlspecialchars($nurse['email']); ?>')"></i>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add/Edit Modal (unchanged) -->
        <div class="modal fade" id="nurseModal" tabindex="-1" aria-labelledby="nurseModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="post" action="nurse.php" id="multiNurseForm">
                        <div class="modal-header">
                            <h5 class="modal-title" id="nurseModalLabel">Add New Employee</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="action" id="action" value="add_multiple">
                            <input type="hidden" name="id" id="nurseId" value="">

                            <div id="nurse-rows-container">
                                <!-- First row (will be cloned) -->
                                <div class="row multi-row">
                                    <div class="col-md-4 mb-2">
                                        <input type="text" class="form-control" name="name[]" placeholder="Name *" required>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <input type="text" class="form-control" name="phone[]" placeholder="Phone">
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <input type="email" class="form-control" name="email[]" placeholder="Email">
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <select class="form-select" name="group_id[]">
                                            <option value="">Group</option>
                                            <?php foreach ($groups as $id => $name): ?>
                                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-8 mb-2">
                                        <input type="text" class="form-control" name="address[]" placeholder="Address">
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <i class="fas fa-times remove-row" style="display:none;"></i>
                                    </div>
                                </div>
                            </div>

                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="addAnotherRow">
                                <i class="fas fa-plus"></i> Add another employee
                            </button>

                            <div class="form-text mt-3">Hourly wage will be taken from the selected group.</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Employees</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Share Modal (fallback for when Web Share API not supported) -->
        <div class="modal fade" id="shareModal" tabindex="-1" aria-labelledby="shareModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="shareModalLabel">Share Employee</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="shareModalContent">
                        <!-- Content populated by JS -->
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
        // Initialize Select2 for group filter
        $(document).ready(function() {
            $('.select2').select2({
                placeholder: 'All Groups',
                allowClear: true,
                width: '100%'
            });
        });

        // Share function
        function shareEmployee(id, name, phone, email) {
            let shareText = `Employee: ${name}\nPhone: ${phone}\nEmail: ${email}`;
            if (navigator.share) {
                // Use Web Share API
                navigator.share({
                    title: 'Employee Information',
                    text: shareText,
                })
                .catch(err => console.log('Share cancelled', err));
            } else {
                // Fallback: show modal with email and WhatsApp links
                let modalContent = document.getElementById('shareModalContent');
                let encodedText = encodeURIComponent(shareText);
                let emailLink = `mailto:?subject=Employee%20Information&body=${encodedText}`;
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
            let container = document.getElementById('nurse-rows-container');
            let firstRow = container.querySelector('.multi-row');
            let newRow = firstRow.cloneNode(true);

            // Clear input values
            newRow.querySelectorAll('input').forEach(input => input.value = '');
            newRow.querySelector('select').selectedIndex = 0;
            // Show the remove icon for this row
            let removeIcon = newRow.querySelector('.remove-row');
            if (removeIcon) {
                removeIcon.style.display = 'inline-block';
                removeIcon.onclick = function() { this.closest('.multi-row').remove(); };
            }
            container.appendChild(newRow);
        });

        // For editing a single nurse, we need to switch the action and populate the form
        function loadEditData(nurse) {
            document.getElementById('action').value = 'edit';
            document.getElementById('nurseId').value = nurse.id;

            // Clear any extra rows
            let container = document.getElementById('nurse-rows-container');
            container.innerHTML = ''; // remove all rows

            // Create a single row for editing
            let rowDiv = document.createElement('div');
            rowDiv.className = 'row multi-row';
            rowDiv.innerHTML = `
                <div class="col-md-4 mb-2">
                    <input type="text" class="form-control" name="name" value="${escapeHtml(nurse.name || '')}" placeholder="Name *" required>
                </div>
                <div class="col-md-3 mb-2">
                    <input type="text" class="form-control" name="phone" value="${escapeHtml(nurse.phone || '')}" placeholder="Phone">
                </div>
                <div class="col-md-3 mb-2">
                    <input type="email" class="form-control" name="email" value="${escapeHtml(nurse.email || '')}" placeholder="Email">
                </div>
                <div class="col-md-2 mb-2">
                    <select class="form-select" name="group_id">
                        <option value="">Group</option>
                        <?php foreach ($groups as $id => $name): ?>
                            <option value="<?php echo $id; ?>" ${nurse.group_id == <?php echo $id; ?> ? 'selected' : ''}> <?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8 mb-2">
                    <input type="text" class="form-control" name="address" value="${escapeHtml(nurse.address || '')}" placeholder="Address">
                </div>
                <div class="col-md-2 mb-2">
                    <!-- no remove icon -->
                </div>
            `;
            container.appendChild(rowDiv);

            // Hide the "Add another" button during edit
            document.getElementById('addAnotherRow').style.display = 'none';

            document.getElementById('nurseModalLabel').innerText = 'Edit Employee';
            var modal = new bootstrap.Modal(document.getElementById('nurseModal'));
            modal.show();
        }

        // Clear form for adding new nurses (multiple)
        function clearForm() {
            document.getElementById('action').value = 'add_multiple';
            document.getElementById('nurseId').value = '';
            document.getElementById('addAnotherRow').style.display = 'inline-block';

            let container = document.getElementById('nurse-rows-container');
            container.innerHTML = ''; // clear all rows

            // Create a fresh first row
            let firstRow = document.createElement('div');
            firstRow.className = 'row multi-row';
            firstRow.innerHTML = `
                <div class="col-md-4 mb-2">
                    <input type="text" class="form-control" name="name[]" placeholder="Name *" required>
                </div>
                <div class="col-md-3 mb-2">
                    <input type="text" class="form-control" name="phone[]" placeholder="Phone">
                </div>
                <div class="col-md-3 mb-2">
                    <input type="email" class="form-control" name="email[]" placeholder="Email">
                </div>
                <div class="col-md-2 mb-2">
                    <select class="form-select" name="group_id[]">
                        <option value="">Group</option>
                        <?php foreach ($groups as $id => $name): ?>
                            <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8 mb-2">
                    <input type="text" class="form-control" name="address[]" placeholder="Address">
                </div>
                <div class="col-md-2 mb-2">
                    <i class="fas fa-times remove-row" style="display:none;"></i>
                </div>
            `;
            container.appendChild(firstRow);

            document.getElementById('nurseModalLabel').innerText = 'Add New Employee';
        }

        function escapeHtml(unsafe) {
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
            <?php if ($editNurse): ?>
                loadEditData(<?php echo json_encode($editNurse); ?>);
            <?php endif; ?>
        };
    </script>
</body>
</html>