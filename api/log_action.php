<?php
/**
 * API: Log Action
 * Accepts a JSON POST request to insert a log entry.
 * Useful for logging frontend events via AJAX.
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Ensure user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
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

// Required fields
$action = $input['action'] ?? '';
$tableName = $input['table_name'] ?? '';
$recordId = isset($input['record_id']) ? (int)$input['record_id'] : null;
$details = $input['details'] ?? null;

if (empty($action) || empty($tableName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing action or table_name']);
    exit;
}

// Insert log
$userId = $_SESSION['user_id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

try {
    $result = logAction($pdo, $userId, $action, $tableName, $recordId, $details, $ip);
    echo json_encode(['success' => $result]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}