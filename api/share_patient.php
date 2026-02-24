<?php
/**
 * API: Share Patient via Email
 * Redirects to a mailto: link with patient name, address, and notes.
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Allowed roles (same as patient.php)
if (!isLoggedIn() || !in_array(getRole(), ['super_admin', 'roaster', 'admin1', 'admin2', 'admin3'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$patientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$patientId) {
    header('Location: patient.php');
    exit;
}

// Fetch patient
$stmt = $pdo->prepare("SELECT name, address, notes FROM patients WHERE id = ?");
$stmt->execute([$patientId]);
$patient = $stmt->fetch();

if (!$patient) {
    header('Location: patient.php');
    exit;
}

// Build mailto link
$subject = 'Patient Information: ' . $patient['name'];
$body = "Name: {$patient['name']}\nAddress: {$patient['address']}\nNotes: {$patient['notes']}";
$mailto = 'mailto:?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);

// Redirect
header('Location: ' . $mailto);
exit;