<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    $basePath = dirname($_SERVER['SCRIPT_NAME'], 3);
    header('Location: ' . $basePath . '/login.php');
    exit;
}

/**
 * Returns the logged-in user's id, or null if not logged in.
 */
function current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Returns the logged-in user's role (Admin / Manager / Employee), or null.
 */
function current_user_role() {
    return $_SESSION['user_role'] ?? null;
}

/**
 * Restricts a page to the given roles. Call this after requiring config/db.php
 * (which already enforces login) on any page that should not be open to everyone.
 * Usage: require_role(['Admin']); or require_role(['Admin', 'Manager']);
 */
function require_role(array $roles) {
    if (!in_array(current_user_role(), $roles, true)) {
        http_response_code(403);
        include __DIR__ . '/access_denied.php';
        exit;
    }
}
