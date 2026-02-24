<?php
/**
 * VitalZone - Employee Group Management
 * CRUD for employee groups: group name, description, default hourly wage.
 * Now supports adding multiple groups at once, import, export, sorting, and filtering.
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
$allowedSortColumns = ['group_name', 'default_hourly_wage'];
$sort = isset($_GET['sort']) && in_array($_GET['sort'], $allowedSortColumns) ? $_GET['sort'] : 'group_name';
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
    // Delete group
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        try {
            // Check if group is used by any nurse
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM nurses WHERE group_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            if ($count > 0) {
                $error = 'Cannot delete group because it is assigned to one or more nurses.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM employee_groups WHERE id = ?");
                $stmt->execute([$id]);
                logAction($pdo, $_SESSION['user_id'], 'DELETE', 'employee_groups', $id, "Deleted group ID $id");
                redirectWithMessage('groups.php', 'Group deleted successfully.', 'success');
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }

    // Add multiple groups
    if (isset($_POST['action']) && $_POST['action'] === 'add_multiple' && isset($_POST['group_name']) && is_array($_POST['group_name'])) {
        $groupNames = $_POST['group_name'];
        $descriptions = $_POST['description'] ?? [];
        $wages = $_POST['default_hourly_wage'] ?? [];

        $inserted = 0;
        $errors = [];
        for ($i = 0; $i < count($groupNames); $i++) {
            $groupName = trim($groupNames[$i] ?? '');
            if (empty($groupName)) continue; // skip empty rows

            $description = trim($descriptions[$i] ?? '');
            $wage = !empty($wages[$i]) ? (float)$wages[$i] : 0;

            try {
                $stmt = $pdo->prepare("INSERT INTO employee_groups (group_name, description, default_hourly_wage) VALUES (?, ?, ?)");
                $stmt->execute([$groupName, $description, $wage]);
                $inserted++;
            } catch (PDOException $e) {
                $errors[] = "Row " . ($i+1) . ": " . $e->getMessage();
            }
        }
        if ($inserted > 0) {
            $message = "$inserted groups added successfully.";
            logAction($pdo, $_SESSION['user_id'], 'INSERT_MULTIPLE', 'employee_groups', null, "Added $inserted groups");
        }
        if (!empty($errors)) {
            $error = implode('<br>', $errors);
        }
        if ($inserted == 0 && empty($errors)) {
            $error = 'No valid entries found.';
        }
        // Stay on same page to show messages
    }

    // Edit single group
    if (isset($_POST['action']) && $_POST['action'] === 'edit' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $groupName = trim($_POST['group_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $defaultWage = !empty($_POST['default_hourly_wage']) ? (float)$_POST['default_hourly_wage'] : 0;

        if (empty($groupName)) {
            $error = 'Group name is required.';
        } else {
            $stmt = $pdo->prepare("UPDATE employee_groups SET group_name = ?, description = ?, default_hourly_wage = ? WHERE id = ?");
            try {
                $stmt->execute([$groupName, $description, $defaultWage, $id]);
                logAction($pdo, $_SESSION['user_id'], 'UPDATE', 'employee_groups', $id, "Updated group: $groupName");
                redirectWithMessage('groups.php', 'Group updated successfully.', 'success');
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
                $expected = ['group_name', 'description', 'default_hourly_wage'];
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
                        $groupName = trim($data['group_name'] ?? '');
                        $description = trim($data['description'] ?? '');
                        $wage = isset($data['default_hourly_wage']) ? (float)trim($data['default_hourly_wage']) : 0;

                        if (empty($groupName)) {
                            $errors[] = "Row $rowNum: group name is required.";
                            $rowNum++;
                            continue;
                        }

                        try {
                            $stmt = $pdo->prepare("INSERT INTO employee_groups (group_name, description, default_hourly_wage) VALUES (?, ?, ?)");
                            $stmt->execute([$groupName, $description, $wage]);
                            $imported++;
                        } catch (PDOException $e) {
                            $errors[] = "Row $rowNum: database error - " . $e->getMessage();
                        }
                        $rowNum++;
                    }
                    fclose($handle);
                    if ($imported > 0) {
                        $message = "$imported groups imported successfully.";
                        logAction($pdo, $_SESSION['user_id'], 'IMPORT', 'employee_groups', null, "Imported $imported groups via CSV");
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
$sql = "SELECT * FROM employee_groups WHERE 1=1";
$countSql = "SELECT COUNT(*) FROM employee_groups WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND group_name LIKE ?";
    $countSql .= " AND group_name LIKE ?";
    $params[] = "%$search%";
}

$sql .= " ORDER BY $sort $dir LIMIT $limit OFFSET $offset";

// Get total count for pagination
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalRows = $stmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch groups
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$groups = $stmt->fetchAll();

// Determine if we are editing a specific group
$editGroup = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM employee_groups WHERE id = ?");
    $stmt->execute([$id]);
    $editGroup = $stmt->fetch();
    if (!$editGroup) {
        redirectWithMessage('groups.php', 'Group not found.', 'error');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VitalZone - Manage Employee Groups</title>
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
                        <a class="nav-link active" href="groups.php"><i class="fas fa-users-cog me-1"></i>Employee Groups</a>
                    </li>
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

        <!-- Page header with Export and Add buttons -->
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
            <h1 class="h2">Manage Employee Groups</h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <a href="api/export_groups.php?<?php echo http_build_query(['search' => $search]); ?>" class="btn btn-sm btn-success me-2">
                    <i class="fas fa-download"></i> Export CSV
                </a>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#groupModal" onclick="clearForm()">
                    <i class="fas fa-plus"></i> Add New Group
                </button>
            </div>
        </div>

        <!-- Import CSV Section -->
        <div class="import-card">
            <h5><i class="fas fa-file-import"></i> Import Groups from CSV</h5>
            <p class="text-muted">Upload a CSV file with columns: <code>group_name, description, default_hourly_wage</code>. The first row must be the header.</p>
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
                    <a href="data:text/csv;charset=utf-8,group_name,description,default_hourly_wage%0ARN,Registered Nurse,45%0ALPN,Licensed Practical Nurse,30"
                       download="group_template.csv" class="btn btn-outline-secondary">
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
            <form method="get" action="groups.php" class="row g-3">
                <div class="col-md-4">
                    <label for="search" class="form-label">Search by Group Name</label>
                    <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="e.g. RN">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2"><i class="fas fa-search"></i> Search</button>
                    <a href="groups.php" class="btn btn-clear"><i class="fas fa-times"></i> Clear</a>
                </div>
            </form>
        </div>

        <!-- Groups table -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Employee Groups (<?php echo $totalRows; ?> total)</h6>
            </div>
            <div class="card-body">
                <?php if (empty($groups)): ?>
                    <p class="text-muted">No groups found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th><?php echo sortLink('group_name', 'Group Name'); ?></th>
                                    <th>Description</th>
                                    <th><?php echo sortLink('default_hourly_wage', 'Default Hourly Wage (AED)'); ?></th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groups as $group): ?>
                                    <tr>
                                        <td><?php echo $group['id']; ?></td>
                                        <td><?php echo htmlspecialchars($group['group_name']); ?></td>
                                        <td><?php echo htmlspecialchars($group['description']); ?></td>
                                        <td><?php echo number_format($group['default_hourly_wage'], 2); ?></td>
                                        <td>
                                            <a href="?edit=<?php echo $group['id']; ?>" class="btn btn-sm btn-primary" onclick="loadEditData(<?php echo htmlspecialchars(json_encode($group)); ?>); return false;">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this group?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $group['id']; ?>">
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
                        <nav aria-label="Group pagination">
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

        <!-- Group Modal (Add/Edit) - Multi-add capable -->
        <div class="modal fade" id="groupModal" tabindex="-1" aria-labelledby="groupModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="post" action="groups.php" id="multiGroupForm">
                        <div class="modal-header">
                            <h5 class="modal-title" id="groupModalLabel">Add New Group</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="action" id="action" value="add_multiple">
                            <input type="hidden" name="id" id="groupId" value="">

                            <div id="group-rows-container">
                                <!-- First row (template) -->
                                <div class="row multi-row">
                                    <div class="col-md-4 mb-2">
                                        <input type="text" class="form-control" name="group_name[]" placeholder="Group Name *" required>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <input type="text" class="form-control" name="description[]" placeholder="Description">
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <input type="number" step="0.01" min="0" class="form-control" name="default_hourly_wage[]" placeholder="Hourly Wage">
                                    </div>
                                    <div class="col-md-1 mb-2">
                                        <i class="fas fa-times remove-row" style="display:none;"></i>
                                    </div>
                                </div>
                            </div>

                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="addAnotherRow">
                                <i class="fas fa-plus"></i> Add another group
                            </button>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Groups</button>
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
            let container = document.getElementById('group-rows-container');
            let firstRow = container.querySelector('.multi-row');
            let newRow = firstRow.cloneNode(true);

            // Clear input values
            newRow.querySelectorAll('input').forEach(input => input.value = '');
            // Show the remove icon for this row
            let removeIcon = newRow.querySelector('.remove-row');
            if (removeIcon) {
                removeIcon.style.display = 'inline-block';
                removeIcon.onclick = function() { this.closest('.multi-row').remove(); };
            }
            container.appendChild(newRow);
        });

        // For editing a single group, we need to switch the action and populate the form
        function loadEditData(group) {
            document.getElementById('action').value = 'edit';
            document.getElementById('groupId').value = group.id;

            // Clear any extra rows
            let container = document.getElementById('group-rows-container');
            container.innerHTML = ''; // remove all rows

            // Create a single row for editing
            let rowDiv = document.createElement('div');
            rowDiv.className = 'row multi-row';
            rowDiv.innerHTML = `
                <div class="col-md-4 mb-2">
                    <input type="text" class="form-control" name="group_name" value="${escapeHtml(group.group_name || '')}" placeholder="Group Name *" required>
                </div>
                <div class="col-md-4 mb-2">
                    <input type="text" class="form-control" name="description" value="${escapeHtml(group.description || '')}" placeholder="Description">
                </div>
                <div class="col-md-3 mb-2">
                    <input type="number" step="0.01" min="0" class="form-control" name="default_hourly_wage" value="${group.default_hourly_wage || ''}" placeholder="Hourly Wage">
                </div>
                <div class="col-md-1 mb-2">
                    <!-- no remove icon -->
                </div>
            `;
            container.appendChild(rowDiv);

            // Hide the "Add another" button during edit
            document.getElementById('addAnotherRow').style.display = 'none';

            document.getElementById('groupModalLabel').innerText = 'Edit Group';
            var modal = new bootstrap.Modal(document.getElementById('groupModal'));
            modal.show();
        }

        // Clear form for adding new groups (multiple)
        function clearForm() {
            document.getElementById('action').value = 'add_multiple';
            document.getElementById('groupId').value = '';
            document.getElementById('addAnotherRow').style.display = 'inline-block';

            let container = document.getElementById('group-rows-container');
            container.innerHTML = ''; // clear all rows

            // Create a fresh first row
            let firstRow = document.createElement('div');
            firstRow.className = 'row multi-row';
            firstRow.innerHTML = `
                <div class="col-md-4 mb-2">
                    <input type="text" class="form-control" name="group_name[]" placeholder="Group Name *" required>
                </div>
                <div class="col-md-4 mb-2">
                    <input type="text" class="form-control" name="description[]" placeholder="Description">
                </div>
                <div class="col-md-3 mb-2">
                    <input type="number" step="0.01" min="0" class="form-control" name="default_hourly_wage[]" placeholder="Hourly Wage">
                </div>
                <div class="col-md-1 mb-2">
                    <i class="fas fa-times remove-row" style="display:none;"></i>
                </div>
            `;
            container.appendChild(firstRow);

            document.getElementById('groupModalLabel').innerText = 'Add New Group';
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
            <?php if ($editGroup): ?>
                loadEditData(<?php echo json_encode($editGroup); ?>);
            <?php endif; ?>
        };
    </script>
</body>
</html>