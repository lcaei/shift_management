<?php
/**
 * VitalZone - Logout
 * Logs the logout action and ends the user session.
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';

if (isLoggedIn()) {
    $userId = $_SESSION['user_id'];
    logAction($pdo, $userId, 'LOGOUT', 'users', $userId, 'User logged out');
}
logout();
?>