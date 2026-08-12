<?php
// Ensures a session is active (safe to include even if session_start() was already called elsewhere)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$base = defined('BASE_URL') ? BASE_URL : '';

// Not logged in at all -> send to login
if (empty($_SESSION['user_id'])) {
    header('Location: ' . $base . '/login.php');
    exit;
}

// Logged in, but not an admin -> block access
if (($_SESSION['user_role'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo '<div style="max-width:480px;margin:80px auto;text-align:center;font-family:Arial,sans-serif;">
            <h2>Access denied</h2>
            <p>You don\'t have permission to view this page.</p>
            <a href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '/modules/dashboard/index.php">Back to dashboard</a>
          </div>';
    exit;
}