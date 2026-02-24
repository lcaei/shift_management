<?php
/**
 * API: Export Patients to CSV
 * Generates a downloadable CSV file with all patient data.
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
header('Content-Disposition: attachment; filename="patients_export_' . date('Y-m-d') . '.csv"');

// Open output stream
$output = fopen('php://output', 'w');
// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write CSV header
fputcsv($output, ['ID', 'Name', 'Address', 'City', 'State', 'Zip', 'Location Pin', 'Phone', 'Doctor', 'Notes']);

// Fetch all patients
$stmt = $pdo->query("SELECT * FROM patients ORDER BY name");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['id'],
        $row['name'],
        $row['address'],
        $row['city'],
        $row['state'],
        $row['zip'],
        $row['location_pin'],
        $row['phone'],
        $row['doctor'],
        $row['notes']
    ]);
}

fclose($output);
exit;