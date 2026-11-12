<?php
/**
 * NEW FILE: modules/session/expired.php — ends the session after 30 minutes of inactivity and opens the login page
 * with the explanation. Safe to open at any time (also when the session is already gone).
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?: 'Lax']);
}
@session_destroy();
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Location: ' . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'], 3)), '/') . '/login.php?expired=1');
exit;