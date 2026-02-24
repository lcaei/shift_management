<?php
/**
 * General helper functions used across the application.
 * Updated with employee groups, logging, and wage formatting.
 */

/**
 * Sanitize input data to prevent XSS.
 * @param string $data Raw input
 * @return string Sanitized output
 */
function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Get a list of all nurses for dropdowns.
 * @param PDO $pdo Database connection
 * @return array Associative array [id => name]
 */
function getNursesList($pdo) {
    $stmt = $pdo->query("SELECT id, name FROM nurses ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Get a list of all patients for dropdowns.
 * @param PDO $pdo Database connection
 * @return array Associative array [id => name]
 */
function getPatientsList($pdo) {
    $stmt = $pdo->query("SELECT id, name FROM patients ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Get a list of all employee groups for dropdowns.
 * @param PDO $pdo Database connection
 * @return array Associative array [id => group_name]
 */
function getEmployeeGroupsList($pdo) {
    $stmt = $pdo->query("SELECT id, group_name FROM employee_groups ORDER BY group_name");
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Get full employee group data including wage.
 * @param PDO $pdo
 * @param int $groupId
 * @return array|false Group row or false if not found.
 */
function getEmployeeGroup($pdo, $groupId) {
    $stmt = $pdo->prepare("SELECT * FROM employee_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    return $stmt->fetch();
}

/**
 * Check if a shift is fully approved.
 * @param array $shift A shift row from the database
 * @return bool
 */
function isFullyApproved($shift) {
    return $shift['admin1_approved'] && $shift['admin2_approved'] && $shift['admin3_approved'];
}

/**
 * Format time for display (e.g., 07:00 AM).
 * @param string $time Time in H:i:s format
 * @return string Formatted time
 */
function formatTime($time) {
    return date('g:i A', strtotime($time));
}

/**
 * Format date for display (e.g., Jan 15, 2025).
 * @param string $date Date in Y-m-d format
 * @return string Formatted date
 */
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

/**
 * Format hourly wage (e.g., 45.00 AED).
 * @param float $amount
 * @return string
 */
function formatWage($amount) {
    return number_format($amount, 2) . ' AED';
}

/**
 * Generate a month dropdown for reports.
 * @param string $selected Currently selected month (Y-m format)
 * @return string HTML <select> element
 */
function monthDropdown($selected = '') {
    $months = [];
    for ($i = 0; $i < 12; $i++) {
        $time = strtotime("-$i months");
        $value = date('Y-m', $time);
        $label = date('F Y', $time);
        $months[$value] = $label;
    }
    $html = '<select name="month" class="form-control">';
    foreach ($months as $val => $lab) {
        $sel = ($val == $selected) ? ' selected' : '';
        $html .= "<option value=\"$val\"$sel>$lab</option>";
    }
    $html .= '</select>';
    return $html;
}

/**
 * Redirect with a flash message.
 * @param string $url
 * @param string $msg
 * @param string $type
 */
function redirectWithMessage($url, $msg, $type = 'info') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
    header("Location: $url");
    exit;
}

/**
 * Display and clear a flash message.
 * @return string|null HTML for alert or null.
 */
function displayFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        $class = match($flash['type']) {
            'success' => 'alert-success',
            'error'   => 'alert-danger',
            default   => 'alert-info'
        };
        return '<div class="alert ' . $class . ' alert-dismissible fade show" role="alert">'
               . htmlspecialchars($flash['msg'])
               . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
               . '</div>';
    }
    return null;
}

/**
 * Log an action to the system logs.
 * @param PDO $pdo
 * @param int $userId
 * @param string $action  e.g., 'INSERT', 'UPDATE', 'DELETE', 'APPROVE', 'UNAPPROVE', 'LOGIN', 'LOGOUT'
 * @param string $tableName e.g., 'nurses', 'patients', 'shifts', 'employee_groups'
 * @param int|null $recordId ID of affected record (optional)
 * @param string|null $details JSON or text description (optional)
 * @param string|null $ip IP address (if null, auto-detected)
 * @return bool
 */
function logAction($pdo, $userId, $action, $tableName, $recordId = null, $details = null, $ip = null) {
    if ($ip === null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO logs (user_id, action, table_name, record_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        return $stmt->execute([$userId, $action, $tableName, $recordId, $details, $ip]);
    } catch (PDOException $e) {
        // Log error silently (or handle as needed)
        error_log("Failed to insert log: " . $e->getMessage());
        return false;
    }
}
?>