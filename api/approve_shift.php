<?php
/**
 * API: Approve or Unapprove Shift
 * Updates the approval status for a shift based on the admin level.
 * Only accessible to admin users (admin1, admin2, admin3).
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Ensure user is logged in and is an admin
if (!isLoggedIn() || !in_array(getRole(), ['admin1', 'admin2', 'admin3'])) {
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

$shiftId = isset($input['id']) ? (int)$input['id'] : 0;
$level = isset($input['level']) ? $input['level'] : '';
$unapprove = isset($input['unapprove']) && $input['unapprove'] === true;

if (!$shiftId || !in_array($level, ['admin1', 'admin2', 'admin3'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid parameters']);
    exit;
}

// Check that the logged-in user's role matches the level
if (getRole() !== $level) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You cannot modify this level']);
    exit;
}

try {
    // Fetch current approval status
    $stmt = $pdo->prepare("SELECT admin1_approved, admin2_approved, admin3_approved FROM shifts WHERE id = ?");
    $stmt->execute([$shiftId]);
    $shift = $stmt->fetch();

    if (!$shift) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Shift not found']);
        exit;
    }

    // Determine which column to update
    $column = $level . '_approved';

    // Validation for unapprove
    if ($unapprove) {
        // Cannot unapprove if it's already false
        if (!$shift[$column]) {
            echo json_encode(['success' => false, 'error' => 'This level is not approved']);
            exit;
        }
        // Check higher level approvals
        if ($level === 'admin1' && ($shift['admin2_approved'] || $shift['admin3_approved'])) {
            echo json_encode(['success' => false, 'error' => 'Cannot unapprove: a higher level has already approved']);
            exit;
        }
        if ($level === 'admin2' && $shift['admin3_approved']) {
            echo json_encode(['success' => false, 'error' => 'Cannot unapprove: Admin3 has already approved']);
            exit;
        }
        // Admin3 can always unapprove (no higher level)
    } else {
        // Validation for approve
        if ($level === 'admin2' && !$shift['admin1_approved']) {
            echo json_encode(['success' => false, 'error' => 'Admin1 must approve first']);
            exit;
        }
        if ($level === 'admin3' && (!$shift['admin1_approved'] || !$shift['admin2_approved'])) {
            echo json_encode(['success' => false, 'error' => 'Both Admin1 and Admin2 must approve first']);
            exit;
        }
        // If already approved, return success (idempotent)
        if ($shift[$column]) {
            echo json_encode(['success' => true, 'message' => 'Already approved']);
            exit;
        }
    }

    // Update the column (set to 1 for approve, 0 for unapprove)
    $newValue = $unapprove ? 0 : 1;
    $update = $pdo->prepare("UPDATE shifts SET $column = ? WHERE id = ?");
    $update->execute([$newValue, $shiftId]);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}