<?php
/**
 * API: Export Schedule to CSV
 * Generates a CSV file with one row per shift.
 * Columns: Date, Nurse, Patient, Start Time, End Time, Hours, Group (for employees).
 * Accessible to super_admin, roaster, and all admins.
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Allowed roles
if (!isLoggedIn() || !in_array(getRole(), ['super_admin', 'roaster', 'admin1', 'admin2', 'admin3'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// Get parameters
$view = isset($_GET['view']) ? $_GET['view'] : 'week';
$viewBy = isset($_GET['view_by']) ? $_GET['view_by'] : 'employee';
$startDate = isset($_GET['start']) ? $_GET['start'] : '';

// Determine date range (same logic as schedule.php)
switch ($view) {
    case 'day':
        if (!$startDate) $startDate = date('Y-m-d');
        $start = $startDate;
        $end = $startDate;
        break;
    case 'week':
        if (!$startDate) $startDate = date('Y-m-d', strtotime('monday this week'));
        $start = $startDate;
        $end = date('Y-m-d', strtotime($start . ' +6 days'));
        break;
    case '2weeks':
        if (!$startDate) $startDate = date('Y-m-d', strtotime('monday this week'));
        $start = $startDate;
        $end = date('Y-m-d', strtotime($start . ' +13 days'));
        break;
    case 'month':
        if (!$startDate) $startDate = date('Y-m-01');
        $start = $startDate;
        $end = date('Y-m-t', strtotime($start));
        break;
    default:
        $view = 'week';
        $start = date('Y-m-d', strtotime('monday this week'));
        $end = date('Y-m-d', strtotime($start . ' +6 days'));
}

// Build filename
$filename = 'schedule_export_' . date('Y-m-d') . '.csv';

// Set CSV headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Open output stream
$output = fopen('php://output', 'w');
// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write CSV header based on view
if ($viewBy == 'employee') {
    fputcsv($output, ['Date', 'Nurse', 'Patient', 'Start Time', 'End Time', 'Hours', 'Group']);
} else {
    fputcsv($output, ['Date', 'Patient', 'Nurse', 'Start Time', 'End Time', 'Hours', 'Group']);
}

// Fetch all shifts in the range with nurse and patient details
$stmt = $pdo->prepare("
    SELECT s.shift_date,
           n.name AS nurse_name,
           p.name AS patient_name,
           s.start_time,
           s.end_time,
           eg.group_name AS nurse_group
    FROM shifts s
    JOIN nurses n ON s.nurse_id = n.id
    JOIN patients p ON s.patient_id = p.id
    LEFT JOIN employee_groups eg ON n.group_id = eg.id
    WHERE s.shift_date BETWEEN ? AND ?
    ORDER BY s.shift_date, s.start_time
");
$stmt->execute([$start, $end]);
$shifts = $stmt->fetchAll();

// Helper to calculate hours
function calculateHours($start, $end) {
    $startMin = (int)substr($start,0,2)*60 + (int)substr($start,3,2);
    $endMin = (int)substr($end,0,2)*60 + (int)substr($end,3,2);
    if ($endMin < $startMin) $endMin += 24*60;
    return round(($endMin - $startMin) / 60, 1);
}

// Write each shift as a row
foreach ($shifts as $shift) {
    $hours = calculateHours($shift['start_time'], $shift['end_time']);
    $startTime = substr($shift['start_time'], 0, 5); // HH:MM
    $endTime = substr($shift['end_time'], 0, 5);

    if ($viewBy == 'employee') {
        fputcsv($output, [
            $shift['shift_date'],
            $shift['nurse_name'],
            $shift['patient_name'],
            $startTime,
            $endTime,
            $hours,
            $shift['nurse_group'] ?? ''
        ]);
    } else {
        fputcsv($output, [
            $shift['shift_date'],
            $shift['patient_name'],
            $shift['nurse_name'],
            $startTime,
            $endTime,
            $hours,
            $shift['nurse_group'] ?? ''
        ]);
    }
}

fclose($output);
exit;