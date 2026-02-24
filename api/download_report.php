<?php
/**
 * VitalZone - Download Shift Report (CSV)
 *
 * Exports a monthly timesheet for a selected nurse.
 * Format: all days of the month, OFF days marked, summary totals.
 * Month field shows only the month name (e.g., "January").
 * Filename ends with .csv (sanitizes nurse name only).
 */

require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Only finance and super_admin can download reports
if (!isLoggedIn() || !in_array(getRole(), ['finance', 'super_admin'])) {
    header('Location: ../login.php');
    exit;
}

// -------------------------------------------------------------------
// Get and validate input parameters
// -------------------------------------------------------------------
$nurse_id = isset($_GET['nurse_id']) ? (int)$_GET['nurse_id'] : 0;
$month    = isset($_GET['month']) ? $_GET['month'] : '';
$approvalLevel = isset($_GET['approval_level']) ? $_GET['approval_level'] : '3';

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    die('Invalid month format. Use YYYY-MM.');
}
if (!in_array($approvalLevel, ['2', '3'])) {
    $approvalLevel = '3';
}

// Check nurse exists
$stmt = $pdo->prepare("SELECT name FROM nurses WHERE id = ?");
$stmt->execute([$nurse_id]);
$nurse = $stmt->fetch();
if (!$nurse) {
    die('Nurse not found.');
}

// -------------------------------------------------------------------
// Build date range and month info
// -------------------------------------------------------------------
$startDate = $month . '-01';
$endDate   = date('Y-m-t', strtotime($startDate));

$monthTs     = strtotime($startDate);
$monthName   = date('F', $monthTs);               // e.g., "January"
$daysInMonth = (int)date('t', $monthTs);

// -------------------------------------------------------------------
// Fetch shifts with approval filter
// -------------------------------------------------------------------
$sql = "
    SELECT
        s.shift_date,
        p.name AS patient_name,
        s.start_time,
        s.end_time
    FROM shifts s
    JOIN patients p ON s.patient_id = p.id
    WHERE s.nurse_id = ?
      AND s.shift_date BETWEEN ? AND ?
";

if ($approvalLevel == '2') {
    $sql .= " AND s.admin1_approved = 1 AND s.admin2_approved = 1";
} else {
    $sql .= " AND s.fully_approved = 1";
}
$sql .= " ORDER BY s.shift_date, s.start_time";

$stmt = $pdo->prepare($sql);
$stmt->execute([$nurse_id, $startDate, $endDate]);
$shifts = $stmt->fetchAll();

// Index shifts by day
$shiftsByDay = [];
foreach ($shifts as $shift) {
    $day = (int)substr($shift['shift_date'], 8, 2);
    $shiftsByDay[$day][] = $shift;
}

// Calculate totals
$totalHours = 0;
$dutyDays = count($shiftsByDay);

foreach ($shifts as $shift) {
    $start = $shift['start_time'];
    $end   = $shift['end_time'];
    $startMin = (int)substr($start, 0, 2) * 60 + (int)substr($start, 3, 2);
    $endMin   = (int)substr($end,   0, 2) * 60 + (int)substr($end,   3, 2);
    if ($endMin < $startMin) $endMin += 24 * 60;
    $totalHours += round(($endMin - $startMin) / 60, 1);
}

// -------------------------------------------------------------------
// Set CSV headers and output – ensure filename ends with .csv
// -------------------------------------------------------------------
$safe_nurse_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $nurse['name']);
$filename = 'timesheet_' . $safe_nurse_name . '_' . $month . '_level' . $approvalLevel . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// --- Header rows ---
fputcsv($output, ['NAMES', $nurse['name']]);
fputcsv($output, ['MONTH', $monthName]);          // month name only, no year
fputcsv($output, []);                              // blank line
fputcsv($output, ['DATE', 'PATIENT/LOCATION', 'START TIME', 'END TIME', 'HOURS WORKED']);

// --- Daily rows (all days, with OFF for empty days) ---
for ($day = 1; $day <= $daysInMonth; $day++) {
    if (isset($shiftsByDay[$day])) {
        foreach ($shiftsByDay[$day] as $shift) {
            $start = $shift['start_time'];
            $end   = $shift['end_time'];
            $startMin = (int)substr($start, 0, 2) * 60 + (int)substr($start, 3, 2);
            $endMin   = (int)substr($end,   0, 2) * 60 + (int)substr($end,   3, 2);
            if ($endMin < $startMin) $endMin += 24 * 60;
            $hours = round(($endMin - $startMin) / 60, 1);

            fputcsv($output, [
                $day,
                $shift['patient_name'],
                formatTime($shift['start_time']),
                formatTime($shift['end_time']),
                $hours
            ]);
        }
    } else {
        fputcsv($output, [$day, '', 'OFF', 'OFF', '']);
    }
}

// --- Summary rows ---
fputcsv($output, []);
fputcsv($output, ['TOTAL # OF HRS.', '', '', '', $totalHours]);
fputcsv($output, ['TOTAL # OF DUTY', '', '', '', $dutyDays]);

fclose($output);
exit;