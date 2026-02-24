<?php
/**
 * API: Save/Update/Delete Shift
 * Accepts JSON POST requests to create, update, or delete a shift.
 * Now allows super_admin, roaster, and all admins.
 * Handles shift_type_id.
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Ensure user is logged in and has appropriate role
$allowedRoles = ['super_admin', 'roaster', 'admin1', 'admin2', 'admin3'];
if (!isLoggedIn() || !in_array(getRole(), $allowedRoles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Handle DELETE action
if (isset($input['delete']) && $input['delete'] === true && isset($input['id'])) {
    $shiftId = (int)$input['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM shifts WHERE id = ?");
        $stmt->execute([$shiftId]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// For create/update, required fields
$id = isset($input['id']) && $input['id'] ? (int)$input['id'] : null;
$nurseId = isset($input['nurse_id']) ? (int)$input['nurse_id'] : 0;
$patientId = isset($input['patient_id']) ? (int)$input['patient_id'] : 0;
$shiftDate = isset($input['shift_date']) ? trim($input['shift_date']) : '';
$startTime = isset($input['start_time']) ? trim($input['start_time']) : '';
$endTime = isset($input['end_time']) ? trim($input['end_time']) : '';
$shiftTypeId = isset($input['shift_type_id']) && $input['shift_type_id'] !== '' ? (int)$input['shift_type_id'] : null;

// Validate required fields
if (!$nurseId || !$patientId || !$shiftDate || !$startTime || !$endTime) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// Validate date format (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $shiftDate)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD']);
    exit;
}

// Validate time format (HH:MM)
if (!preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid time format. Use HH:MM']);
    exit;
}

// Convert to 24-hour with seconds for database
$startTime .= ':00';
$endTime .= ':00';

// No longer require end time to be after start time – overnight shifts are allowed.

// Verify nurse and patient exist
$stmt = $pdo->prepare("SELECT id FROM nurses WHERE id = ?");
$stmt->execute([$nurseId]);
if (!$stmt->fetch()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nurse not found']);
    exit;
}
$stmt = $pdo->prepare("SELECT id FROM patients WHERE id = ?");
$stmt->execute([$patientId]);
if (!$stmt->fetch()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Patient not found']);
    exit;
}

// If shiftTypeId provided, verify it exists
if ($shiftTypeId !== null) {
    $stmt = $pdo->prepare("SELECT id FROM shift_types WHERE id = ?");
    $stmt->execute([$shiftTypeId]);
    if (!$stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Shift type not found']);
        exit;
    }
}

try {
    if ($id) {
        // Update existing shift
        $sql = "UPDATE shifts SET
                    nurse_id = ?,
                    patient_id = ?,
                    shift_date = ?,
                    start_time = ?,
                    end_time = ?,
                    shift_type_id = ?
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nurseId, $patientId, $shiftDate, $startTime, $endTime, $shiftTypeId, $id]);
    } else {
        // Create new shift
        $sql = "INSERT INTO shifts (nurse_id, patient_id, shift_date, start_time, end_time, shift_type_id)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nurseId, $patientId, $shiftDate, $startTime, $endTime, $shiftTypeId]);
        $id = $pdo->lastInsertId();
    }
    echo json_encode(['success' => true, 'id' => $id]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>