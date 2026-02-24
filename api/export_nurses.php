<?php
/**
 * API: Export Nurses to CSV
 * Generates a downloadable CSV file with all nurse data, including group and wage.
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

// Set CSV headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="nurses_export_' . date('Y-m-d') . '.csv"');

// Open output stream
$output = fopen('php://output', 'w');
// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write CSV header
fputcsv($output, ['ID', 'Name', 'Phone', 'Email', 'Address', 'Group', 'Hourly Wage (AED)']);

// Fetch all nurses with group name
$stmt = $pdo->query("
    SELECT n.*, eg.group_name 
    FROM nurses n
    LEFT JOIN employee_groups eg ON n.group_id = eg.id
    ORDER BY n.name
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['id'],
        $row['name'],
        $row['phone'],
        $row['email'],
        $row['address'],
        $row['group_name'] ?? '',
        $row['hourly_wage'] ? number_format($row['hourly_wage'], 2) : ''
    ]);
}

fclose($output);
exit;