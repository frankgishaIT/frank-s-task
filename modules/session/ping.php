<?php
/**
 * NEW FILE: modules/session/ping.php — keeps an active session alive (?touch=1: the user is working in the page) or
 * only reports the time left (?touch=0). An expired session answers 401 with the login address.
 */
define('RM_SESSION_PING', true);
require __DIR__ . '/../../includes/auth.php';
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'remaining' => max(0, RM_IDLE_LIMIT - (time() - (int) $_SESSION['last_activity'])), 'limit' => RM_IDLE_LIMIT]);