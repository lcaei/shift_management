<?php
/**
 * VitalZone - Shift Types Management
 * CRUD for shift types: type name, description, color.
 * Supports adding multiple types at once, import, export, sorting, and filtering.
 * Accessible to super_admin, roaster, and all admin levels (admin1, admin2, admin3).
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Allowed roles: super_admin, roaster, and all admins
requireRole(['super_admin', 'roaster', 'admin1', 'admin2', 'admin3']);

$role = getRole();

// Pagination settings
$limit = 50;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Sorting
$allowedSortColumns = ['type_name'];
$sort = isset($_GET['sort']) && in_array($_GET['sort'], $allowedSortColumns) ? $_GET['sort'] : 'type_name';
$dir = isset($_GET['dir']) && strtoupper($_GET['dir']) == 'ASC' ? 'ASC' : 'DESC';
$nextDir = ($dir == 'ASC') ? 'DESC' : 'ASC';

// Filter (search)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Helper to build sort link
function sortLink($column, $label) {
    global $sort, $dir, $nextDir, $search, $page;
    $params = $_GET;
    $params['sort'] = $column;
    $params['dir'] = ($sort == $column && $dir == 'ASC') ? 'DESC' : 'ASC';
    unset($params['page']); // reset page when sorting
    $query = http_build_query($params);
    $arrow = '';
    if ($sort == $column) {
        $arrow = $dir == 'ASC' ? ' ↑' : ' ↓';
    }
    return '<a href="?' . $query . '" class="text-dark text-decoration-none">' . $label . $arrow . '</a>';
}

$message = '';
$error = '';

// Handle POST actions (delete, add_multiple, edit, import)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Delete shift type
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        try {
            // Check if shift type is used by any shift
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM shifts WHERE shift_type_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            if ($count > 0) {
                $error = 'Cannot delete shift type because it is assigned to one or more shifts.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM shift_types WHERE id = ?");
                $stmt->execute([$id]);
                logAction($pdo, $_SESSION['user_id'], 'DELETE', 'shift_types', $id, "Deleted shift type ID $id");
                redirectWithMessage('shift_types.php', 'Shift type deleted successfully.', 'success');
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }

    // Add multiple shift types
    if (isset($_POST['action']) && $_POST['action'] === 'add_multiple' && isset($_POST['type_name']) && is_array($_POST['type_name'])) {
        $typeNames = $_POST['type_name'];
        $descriptions = $_POST['description'] ?? [];
        $colors = $_POST['color'] ?? [];

        $inserted = 0;
        $errors = [];
        for ($i = 0; $i < count($typeNames); $i++) {
            $typeName = trim($typeNames[$i] ?? '');
            if (empty($typeName)) continue; // skip empty rows

            $description = trim($descriptions[$i] ?? '');
            $color = trim($colors[$i] ?? '');
            if (!preg_match('/^#[a-f0-9]{6}$/i', $color)) {
                $color = '#0d6efd'; // default color
            }

            try {
                $stmt = $pdo->prepare("INSERT INTO shift_types (type_name, description, color) VALUES (?, ?, ?)");
                $stmt->execute([$typeName, $description, $color]);
                $inserted++;
            } catch (PDOException $e) {
                $errors[] = "Row " . ($i+1) . ": " . $e->getMessage();
            }
        }
        if ($inserted > 0) {
            $message = "$inserted shift types added successfully.";
            logAction($pdo, $_SESSION['user_id'], 'INSERT_MULTIPLE', 'shift_types', null, "Added $inserted shift types");
        }
        if (!empty($errors)) {
            $error = implode('<br>', $errors);
        }
        if ($inserted == 0 && empty($errors)) {
            $error = 'No valid entries found.';
        }
        // Stay on same page to show messages
    }

    // Edit single shift type
    if (isset($_POST['action']) && $_POST['action'] === 'edit' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $typeName = trim($_POST['type_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $color = trim($_POST['color'] ?? '');
        if (!preg_match('/^#[a-f0-9]{6}$/i', $color)) {
            $color = '#0d6efd';
        }

        if (empty($typeName)) {
            $error = 'Type name is required.';
        } else {
            $stmt = $pdo->prepare("UPDATE shift_types SET type_name = ?, description = ?, color = ? WHERE id = ?");
            try {
                $stmt->execute([$typeName, $description, $color, $id]);
                logAction($pdo, $_SESSION['user_id'], 'UPDATE', 'shift_types', $id, "Updated shift type: $typeName");
                redirectWithMessage('shift_types.php', 'Shift type updated successfully.', 'success');
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
                $expected = ['type_name', 'description', 'color'];
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
                        $typeName = trim($data['type_name'] ?? '');
                        $description = trim($data['description'] ?? '');
                        $color = trim($data['color'] ?? '');
                        if (!preg_match('/^#[a-f0-9]{6}$/i', $color)) {
                            $color = '#0d6efd';
                        }

                        if (empty($typeName)) {
                            $errors[] = "Row $rowNum: type name is required.";
                            $rowNum++;
                            continue;
                        }

                        try {
                            $stmt = $pdo->prepare("INSERT INTO shift_types (type_name, description, color) VALUES (?, ?, ?)");
                            $stmt->execute([$typeName, $description, $color]);
                            $imported++;
                        } catch (PDOException $e) {
                            $errors[] = "Row $rowNum: database error - " . $e->getMessage();
                        }
                        $rowNum++;
                    }
                    fclose($handle);
                    if ($imported > 0) {
                        $message = "$imported shift types imported successfully.";
                        logAction($pdo, $_SESSION['user_id'], 'IMPORT', 'shift_types', null, "Imported $imported shift types via CSV");
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

// Build query with filters and sorting
$sql = "SELECT * FROM shift_types WHERE 1=1";
$countSql = "SELECT COUNT(*) FROM shift_types WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND type_name LIKE ?";
    $countSql .= " AND type_name LIKE ?";
    $params[] = "%$search%";
}

$sql .= " ORDER BY $sort $dir LIMIT $limit OFFSET $offset";

// Get total count for pagination
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalRows = $stmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch shift types
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shiftTypes = $stmt->fetchAll();

// Determine if we are editing a specific type
$editType = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM shift_types WHERE id = ?");
    $stmt->execute([$id]);
    $editType = $stmt->fetch();
    if (!$editType) {
        redirectWithMessage('shift_types.php', 'Shift type not found.', 'error');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VitalZone - Manage Shift Types</title>
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
        .card-header { background-color: #f8f9fc; border-bottom: 1px solid #e3e6f0; }
        .import-card { background-color: #e9ecef; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; }
        .filter-bar { background: white; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .btn-clear { background-color: #6c757d; color: white; }
        .btn-clear:hover { background-color: #5a6268; }
        .multi-row { margin-bottom: 1rem; padding: 0.5rem; background: #f9f9f9; border-radius: 0.5rem; }
        .remove-row { color: #dc3545; cursor: pointer; margin-top: 2rem; }
        .pagination { margin-top: 1rem; }
        .color-preview { width: 25px; height: 25px; border-radius: 4px; display: inline-block; vertical-align: middle; margin-right: 5px; border: 1px solid #ccc; }
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
                        <a class="nav-link" href="manage_shifts.php"><i class="fas fa-list me-1"></i>Manage Shifts</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="shift_types.php"><i class="fas fa-tags me-1"></i>Shift Types</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="groups.php"><i class="fas fa-users-cog me-1"></i>Employee Groups</a>
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

        <!-- Page header with Export and Add buttons -->
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
            <h1 class="h2">Manage Shift Types</h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <a href="api/export_shift_types.php?<?php echo http_build_query(['search' => $search]); ?>" class="btn btn-sm btn-success me-2">
                    <i class="fas fa-download"></i> Export CSV
                </a>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#typeModal" onclick="clearForm()">
                    <i class="fas fa-plus"></i> Add New Shift Type
                </button>
            </div>
        </div>

        <!-- Import CSV Section -->
        <div class="import-card">
            <h5><i class="fas fa-file-import"></i> Import Shift Types from CSV</h5>
            <p class="text-muted">Upload a CSV file with columns: <code>type_name, description, color</code> (color is optional, hex format like #0d6efd). The first row must be the header.</p>
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
                    <a href="data:text/csv;charset=utf-8,type_name,description,color%0AMorning,Morning shift,#ffc107%0AEvening,Evening shift,#0d6efd%0AOrientation,Orientation shift,#28a745"
                       download="shift_type_template.csv" class="btn btn-outline-secondary">
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

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="get" action="shift_types.php" class="row g-3">
                <div class="col-md-4">
                    <label for="search" class="form-label">Search by Type Name</label>
                    <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="e.g. Morning">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2"><i class="fas fa-search"></i> Search</button>
                    <a href="shift_types.php" class="btn btn-clear"><i class="fas fa-times"></i> Clear</a>
                </div>
            </form>
        </div>

        <!-- Shift Types table -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Shift Types (<?php echo $totalRows; ?> total)</h6>
            </div>
            <div class="card-body">
                <?php if (empty($shiftTypes)): ?>
                    <p class="text-muted">No shift types found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th><?php echo sortLink('type_name', 'Type Name'); ?></th>
                                    <th>Description</th>
                                    <th>Color</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shiftTypes as $type): ?>
                                    <tr>
                                        <td><?php echo $type['id']; ?></td>
                                        <td><?php echo htmlspecialchars($type['type_name']); ?></td>
                                        <td><?php echo htmlspecialchars($type['description']); ?></td>
                                        <td>
                                            <span class="color-preview" style="background-color: <?php echo htmlspecialchars($type['color']); ?>;"></span>
                                            <?php echo htmlspecialchars($type['color']); ?>
                                        </td>
                                        <td>
                                            <a href="?edit=<?php echo $type['id']; ?>" class="btn btn-sm btn-primary" onclick="loadEditData(<?php echo htmlspecialchars(json_encode($type)); ?>); return false;">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this shift type?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $type['id']; ?>">
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

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Shift type pagination">
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

        <!-- Shift Type Modal (Add/Edit) - Multi-add capable -->
        <div class="modal fade" id="typeModal" tabindex="-1" aria-labelledby="typeModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="post" action="shift_types.php" id="multiTypeForm">
                        <div class="modal-header">
                            <h5 class="modal-title" id="typeModalLabel">Add New Shift Type</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="action" id="action" value="add_multiple">
                            <input type="hidden" name="id" id="typeId" value="">

                            <div id="type-rows-container">
                                <!-- First row (template) -->
                                <div class="row multi-row">
                                    <div class="col-md-4 mb-2">
                                        <input type="text" class="form-control" name="type_name[]" placeholder="Type Name *" required>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <input type="text" class="form-control" name="description[]" placeholder="Description">
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <input type="color" class="form-control form-control-color" name="color[]" value="#0d6efd" title="Choose color">
                                    </div>
                                    <div class="col-md-1 mb-2">
                                        <i class="fas fa-times remove-row" style="display:none;"></i>
                                    </div>
                                </div>
                            </div>

                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="addAnotherRow">
                                <i class="fas fa-plus"></i> Add another shift type
                            </button>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Shift Types</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </main>

    <!-- Bootstrap JS bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle adding multiple rows
        document.getElementById('addAnotherRow').addEventListener('click', function() {
            let container = document.getElementById('type-rows-container');
            let firstRow = container.querySelector('.multi-row');
            let newRow = firstRow.cloneNode(true);

            // Clear input values
            newRow.querySelectorAll('input').forEach(input => input.value = input.type === 'color' ? '#0d6efd' : '');
            // Show the remove icon for this row
            let removeIcon = newRow.querySelector('.remove-row');
            if (removeIcon) {
                removeIcon.style.display = 'inline-block';
                removeIcon.onclick = function() { this.closest('.multi-row').remove(); };
            }
            container.appendChild(newRow);
        });

        // For editing a single shift type, we need to switch the action and populate the form
        function loadEditData(type) {
            document.getElementById('action').value = 'edit';
            document.getElementById('typeId').value = type.id;

            // Clear any extra rows
            let container = document.getElementById('type-rows-container');
            container.innerHTML = ''; // remove all rows

            // Create a single row for editing
            let rowDiv = document.createElement('div');
            rowDiv.className = 'row multi-row';
            rowDiv.innerHTML = `
                <div class="col-md-4 mb-2">
                    <input type="text" class="form-control" name="type_name" value="${escapeHtml(type.type_name || '')}" placeholder="Type Name *" required>
                </div>
                <div class="col-md-4 mb-2">
                    <input type="text" class="form-control" name="description" value="${escapeHtml(type.description || '')}" placeholder="Description">
                </div>
                <div class="col-md-3 mb-2">
                    <input type="color" class="form-control form-control-color" name="color" value="${escapeHtml(type.color || '#0d6efd')}" title="Choose color">
                </div>
                <div class="col-md-1 mb-2">
                    <!-- no remove icon -->
                </div>
            `;
            container.appendChild(rowDiv);

            // Hide the "Add another" button during edit
            document.getElementById('addAnotherRow').style.display = 'none';

            document.getElementById('typeModalLabel').innerText = 'Edit Shift Type';
            var modal = new bootstrap.Modal(document.getElementById('typeModal'));
            modal.show();
        }

        // Clear form for adding new types (multiple)
        function clearForm() {
            document.getElementById('action').value = 'add_multiple';
            document.getElementById('typeId').value = '';
            document.getElementById('addAnotherRow').style.display = 'inline-block';

            let container = document.getElementById('type-rows-container');
            container.innerHTML = ''; // clear all rows

            // Create a fresh first row
            let firstRow = document.createElement('div');
            firstRow.className = 'row multi-row';
            firstRow.innerHTML = `
                <div class="col-md-4 mb-2">
                    <input type="text" class="form-control" name="type_name[]" placeholder="Type Name *" required>
                </div>
                <div class="col-md-4 mb-2">
                    <input type="text" class="form-control" name="description[]" placeholder="Description">
                </div>
                <div class="col-md-3 mb-2">
                    <input type="color" class="form-control form-control-color" name="color[]" value="#0d6efd" title="Choose color">
                </div>
                <div class="col-md-1 mb-2">
                    <i class="fas fa-times remove-row" style="display:none;"></i>
                </div>
            `;
            container.appendChild(firstRow);

            document.getElementById('typeModalLabel').innerText = 'Add New Shift Type';
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
            <?php if ($editType): ?>
                loadEditData(<?php echo json_encode($editType); ?>);
            <?php endif; ?>
        };
    </script>
</body>
</html>