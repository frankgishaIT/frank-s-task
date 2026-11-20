<?php
/**
 * NEW FILE: includes/session_boot.php — ONE way to start the STAFF PORTAL session, used by every staff page.
 *
 * Why: pages used to start the session in different ways (some with PHP's defaults: 24-minute cleanup, no strict
 * mode) and under the common cookie name PHPSESSID. When the browser also holds another PHPSESSID cookie (another
 * application on *.risemotive.rw, an old copy, a different path), PHP could read the wrong one: sign-in "did not
 * work" until the second or third try, and opening a page (e.g. Attendance) sent the user to the login page and from
 * there to the Dashboard.
 * Now: the staff portal has its OWN cookie (RMSTAFF), the same settings on every page, and its own session folder
 * (other applications on the server can no longer clean up our sessions).
 * The Customer Portal (its own cookie) and the Learning Centre website (RMLEARN) are not affected.
 */
if (!defined('RM_STAFF_SESSION')) { define('RM_STAFF_SESSION', 'RMSTAFF'); }

function rm_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    @ini_set('session.gc_maxlifetime', '7200');   // the 30-minute inactivity rule (includes/auth.php) decides, not PHP's cleanup
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_trans_sid', '0');
    // Our own session folder when sessions are stored as files (shared folders are cleaned by other applications' rules).
    if (ini_get('session.save_handler') === 'files') {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'rm_staff_sessions';
        if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
            @ini_set('session.gc_probability', '1');
            @ini_set('session.gc_divisor', '200');
        }
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name(RM_STAFF_SESSION);
    @session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    session_start();
}

/** Removes the old PHPSESSID cookie of this site (after the change of cookie name it is no longer used). */
function rm_forget_old_cookie(): void
{
    if (isset($_COOKIE['PHPSESSID']) && !headers_sent()) {
        setcookie('PHPSESSID', '', ['expires' => time() - 42000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }
}