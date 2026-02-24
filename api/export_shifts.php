<?php
/**
 * API: Export Shifts to CSV
 * Exports the currently filtered shifts (same as manage_shifts.php) to CSV.
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !in_array(getRole(), ['super_admin', 'roaster', 'admin1', 'admin2', 'admin3'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// Replicate filters from manage_shifts.php
$nurseFilter   = isset($_GET['nurse_id']) && $_GET['nurse_id'] !== '' ? (int)$_GET['nurse_id'] : null;
$patientFilter = isset($_GET['patient_id']) && $_GET['patient_id'] !== '' ? (int)$_GET['patient_id'] : null;
$approvalFilter = $_GET['approval'] ?? 'all';
$dateFrom      = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo        = $_GET['date_to'] ?? date('Y-m-d');

$sql = "SELECT s.id, s.shift_date, n.name AS nurse_name, p.name AS patient_name, s.start_time, s.end_time,
               s.admin1_approved, s.admin2_approved, s.admin3_approved, s.fully_approved
        FROM shifts s
        JOIN nurses n ON s.nurse_id = n.id
        JOIN patients p ON s.patient_id = p.id
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

$sql .= " ORDER BY s.shift_date DESC, s.start_time ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$shifts = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="shifts_export_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

fputcsv($output, ['ID', 'Date', 'Nurse', 'Patient', 'Start Time', 'End Time', 'Approval Status']);

foreach ($shifts as $shift) {
    $status = [];
    if ($shift['fully_approved']) $status = 'Fully Approved';
    elseif ($shift['admin1_approved'] || $shift['admin2_approved'] || $shift['admin3_approved']) $status = 'Partial';
    else $status = 'Pending';
    fputcsv($output, [
        $shift['id'],
        $shift['shift_date'],
        $shift['nurse_name'],
        $shift['patient_name'],
        substr($shift['start_time'],0,5),
        substr($shift['end_time'],0,5),
        $status
    ]);
}
fclose($output);