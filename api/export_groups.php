<?php
/**
 * API: Export Employee Groups to CSV
 * Generates a CSV of all groups.
 * Accessible to super_admin and roaster.
 */
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !in_array(getRole(), ['super_admin', 'roaster'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="groups_export_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

fputcsv($output, ['ID', 'Group Name', 'Description', 'Default Hourly Wage']);

$stmt = $pdo->query("SELECT * FROM employee_groups ORDER BY group_name");
while ($row = $stmt->fetch()) {
    fputcsv($output, [
        $row['id'],
        $row['group_name'],
        $row['description'],
        $row['default_hourly_wage']
    ]);
}
fclose($output);