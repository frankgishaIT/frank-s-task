<?php
require_once __DIR__ . '/includes/session_boot.php'; // CHANGED: one staff session (RMSTAFF)
rm_session_start();
rm_forget_old_cookie();
$_SESSION = [];
if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => 'Lax']); }
@session_destroy();
header('Location: login.php');
exit;