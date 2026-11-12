<?php
// NEW: Rwanda time (Africa/Kigali, UTC+2) for every page. This file is in Git, so the setting reaches the
// live server with a normal push (config/db.php is not in Git and is not updated by a push).
date_default_timezone_set('Africa/Kigali');

/*
 * CHANGED (session security): ONE inactivity rule for the whole staff portal.
 *  - After 30 minutes without activity the session ends, and the user is told why on the login page.
 *  - PHP's own cleanup (by default it may delete a session after 24 minutes, at a random moment: the cause of the
 *    "logged out without knowing why") is set to 2 hours, so only this rule decides.
 *  - Protected pages are never cached: after logout, the Back button and direct URLs go to the login page.
 *  - An expired session is destroyed completely, so the next login starts clean.
 */
const RM_IDLE_LIMIT = 1800; // seconds (30 minutes)
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.gc_maxlifetime', '7200');
    @ini_set('session.use_strict_mode', '1');
    $rmHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    @session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $rmHttps]);
    session_start();
}
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/** Ends the session completely (data, cookie, server file). */
function rm_end_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?: 'Lax']);
    }
    @session_destroy();
}

/** Where the login page is, from any page of the portal. */
function rm_login_url(bool $expired = false): string
{
    $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'], 3)), '/');
    return $base . '/login.php' . ($expired ? '?expired=1' : '');
}

$rmExpired = !empty($_SESSION['user_id']) && isset($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > RM_IDLE_LIMIT;
if ($rmExpired) { rm_end_session(); }
if (empty($_SESSION['user_id'])) {
    // Background checks (session ping) get a short answer, not a page.
    if (defined('RM_SESSION_PING') || (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json')) {
        http_response_code(401); header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'expired' => true, 'login' => rm_login_url(true)]); exit;
    }
    header('Location: ' . rm_login_url($rmExpired));
    exit;
}
// Activity: every page or form request counts (a status check with ?touch=0 does not).
if (!(defined('RM_SESSION_PING') && ($_GET['touch'] ?? '1') === '0')) { $_SESSION['last_activity'] = time(); }
if (!isset($_SESSION['last_activity'])) { $_SESSION['last_activity'] = time(); }

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