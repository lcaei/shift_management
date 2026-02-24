<?php
/**
 * API: Get Shifts for FullCalendar
 * Returns JSON array of shift events.
 * Handles overnight shifts: if end_time < start_time, end date = next day.
 * Accessible only to logged‑in users.
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $sql = "SELECT
                s.id,
                s.nurse_id,
                s.patient_id,
                s.shift_date,
                s.start_time,
                s.end_time,
                s.admin1_approved,
                s.admin2_approved,
                s.admin3_approved,
                s.fully_approved,
                n.name AS nurse_name,
                p.name AS patient_name
            FROM shifts s
            JOIN nurses n ON s.nurse_id = n.id
            JOIN patients p ON s.patient_id = p.id
            ORDER BY s.shift_date DESC, s.start_time ASC";

    $stmt = $pdo->query($sql);
    $shifts = $stmt->fetchAll();

    // Optional logging
    error_log("get_shifts.php: " . count($shifts) . " shifts loaded.");

    $events = [];
    foreach ($shifts as $shift) {
        // Start datetime (always on shift_date)
        $start = $shift['shift_date'] . 'T' . substr($shift['start_time'], 0, 5);

        // Determine end date: if end_time < start_time, it's the next day
        $endDate = $shift['shift_date'];
        if ($shift['end_time'] < $shift['start_time']) {
            $endDate = date('Y-m-d', strtotime($shift['shift_date'] . ' +1 day'));
        }
        $end = $endDate . 'T' . substr($shift['end_time'], 0, 5);

        $events[] = [
            'id' => $shift['id'],
            'title' => $shift['nurse_name'] . ' - ' . $shift['patient_name'],
            'start' => $start,
            'end'   => $end,
            'extendedProps' => [
                'nurse_id'       => (int)$shift['nurse_id'],
                'patient_id'     => (int)$shift['patient_id'],
                'nurse_name'     => $shift['nurse_name'],
                'patient_name'   => $shift['patient_name'],
                'admin1_approved'=> (bool)$shift['admin1_approved'],
                'admin2_approved'=> (bool)$shift['admin2_approved'],
                'admin3_approved'=> (bool)$shift['admin3_approved'],
                'fully_approved' => (bool)$shift['fully_approved']
            ]
        ];
    }

    header('Content-Type: application/json');
    echo json_encode($events);

} catch (PDOException $e) {
    error_log("get_shifts.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}