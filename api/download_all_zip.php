<?php
/**
 * VitalZone - Download All Nurses as ZIP of CSVs
 * 
 * Creates a ZIP archive containing one CSV file per nurse,
 * each following the same format as the individual report.
 */

require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Only finance and super_admin can download
if (!isLoggedIn() || !in_array(getRole(), ['finance', 'super_admin'])) {
    header('Location: ../login.php');
    exit;
}

// -------------------------------------------------------------------
// Get and validate parameters
// -------------------------------------------------------------------
$month = $_GET['month'] ?? '';
$approvalLevel = $_GET['approval_level'] ?? '3';

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    die('Invalid month format. Use YYYY-MM.');
}
if (!in_array($approvalLevel, ['2', '3'])) {
    $approvalLevel = '3';
}

// -------------------------------------------------------------------
// Build date range and month info
// -------------------------------------------------------------------
$startDate = $month . '-01';
$endDate   = date('Y-m-t', strtotime($startDate));
$monthTs   = strtotime($startDate);
$monthName = date('F', $monthTs);               // e.g., "January"
$daysInMonth = (int)date('t', $monthTs);

// -------------------------------------------------------------------
// Get all nurses
// -------------------------------------------------------------------
$nurses = $pdo->query("SELECT id, name FROM nurses ORDER BY name")->fetchAll();

// -------------------------------------------------------------------
// Fetch ALL shifts for the month with the approval filter
// -------------------------------------------------------------------
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

// Group shifts by nurse_id and day
$shiftsByNurse = [];
foreach ($allShifts as $shift) {
    $nurseId = $shift['nurse_id'];
    $day = (int)substr($shift['shift_date'], 8, 2);
    $shiftsByNurse[$nurseId][$day][] = $shift;
}

// -------------------------------------------------------------------
// Create ZIP archive
// -------------------------------------------------------------------
$zip = new ZipArchive();
$zipFilename = tempnam(sys_get_temp_dir(), 'zip');

if ($zip->open($zipFilename, ZipArchive::CREATE) !== true) {
    die('Cannot create ZIP file');
}

// -------------------------------------------------------------------
// Process each nurse and add CSV to ZIP
// -------------------------------------------------------------------
foreach ($nurses as $nurse) {
    $nurseId = $nurse['id'];
    $nurseName = $nurse['name'];
    $shiftsByDay = $shiftsByNurse[$nurseId] ?? [];

    // Calculate totals for this nurse
    $totalHours = 0;
    $dutyDays = count($shiftsByDay);
    foreach ($shiftsByDay as $dayShifts) {
        foreach ($dayShifts as $shift) {
            $start = $shift['start_time'];
            $end   = $shift['end_time'];
            $startMin = (int)substr($start, 0, 2) * 60 + (int)substr($start, 3, 2);
            $endMin   = (int)substr($end,   0, 2) * 60 + (int)substr($end,   3, 2);
            if ($endMin < $startMin) $endMin += 24 * 60;
            $totalHours += round(($endMin - $startMin) / 60, 1);
        }
    }

    // Build CSV in memory
    $csvHandle = fopen('php://memory', 'r+');

    // Header rows
    fputcsv($csvHandle, ['NAMES', $nurseName]);
    fputcsv($csvHandle, ['MONTH', $monthName]);
    fputcsv($csvHandle, []); // blank
    fputcsv($csvHandle, ['DATE', 'PATIENT/LOCATION', 'START TIME', 'END TIME', 'HOURS WORKED']);

    // Daily rows
    for ($day = 1; $day <= $daysInMonth; $day++) {
        if (isset($shiftsByDay[$day])) {
            foreach ($shiftsByDay[$day] as $shift) {
                $start = $shift['start_time'];
                $end   = $shift['end_time'];
                $startMin = (int)substr($start, 0, 2) * 60 + (int)substr($start, 3, 2);
                $endMin   = (int)substr($end,   0, 2) * 60 + (int)substr($end,   3, 2);
                if ($endMin < $startMin) $endMin += 24 * 60;
                $hours = round(($endMin - $startMin) / 60, 1);

                fputcsv($csvHandle, [
                    $day,
                    $shift['patient_name'],
                    formatTime($shift['start_time']),
                    formatTime($shift['end_time']),
                    $hours
                ]);
            }
        } else {
            fputcsv($csvHandle, [$day, '', 'OFF', 'OFF', '']);
        }
    }

    // Summary rows
    fputcsv($csvHandle, []);
    fputcsv($csvHandle, ['TOTAL # OF HRS.', '', '', '', $totalHours]);
    fputcsv($csvHandle, ['TOTAL # OF DUTY', '', '', '', $dutyDays]);

    // Rewind and get CSV content
    rewind($csvHandle);
    $csvContent = stream_get_contents($csvHandle);
    fclose($csvHandle);

    // Add file to ZIP (sanitize filename)
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $nurseName);
    $zip->addFromString($safeName . '_' . $month . '.csv', $csvContent);
}

$zip->close();

// -------------------------------------------------------------------
// Send ZIP to browser
// -------------------------------------------------------------------
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="all_nurses_' . $month . '_level' . $approvalLevel . '.zip"');
header('Content-Length: ' . filesize($zipFilename));
readfile($zipFilename);
unlink($zipFilename);
exit;