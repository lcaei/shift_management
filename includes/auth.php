<?php
/**
 * Authentication and role management.
 * Updated to include super_admin (full access) and roaster roles.
 */
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if a user is logged in.
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Require login – redirect to login page if not logged in.
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Check if the logged‑in user has a specific role.
 * @param string $role The role to check
 */
function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

/**
 * Check if the logged‑in user is a super admin.
 */
function isSuperAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';
}

/**
 * Get the role of the logged‑in user.
 */
function getRole() {
    return $_SESSION['role'] ?? null;
}

/**
 * Require that the logged‑in user has one of the allowed roles.
 * Super admin is always allowed.
 * @param string|array $allowedRoles Single role or array of roles.
 */
function requireRole($allowedRoles) {
    requireLogin();
    if (isSuperAdmin()) {
        return; // super admin can do anything
    }
    if (!is_array($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }
    if (!in_array(getRole(), $allowedRoles)) {
        header('HTTP/1.0 403 Forbidden');
        echo '<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>';
        exit;
    }
}

/**
 * Log out the current user.
 */
function logout() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header('Location: login.php');
    exit;
}
?>