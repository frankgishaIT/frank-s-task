<?php
/**
 * The pay page asks this every few seconds: "is it paid yet? is the account ready?"
 * It asks XentriPay for the real answer (at most once every 2 seconds per payment) and returns a small JSON message.
 * The secret pay link is the only key: it shows nothing about any other request.
 */
require_once __DIR__ . '/includes/platform_web.php';
require_once __DIR__ . '/includes/signup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$token = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
$attemptId = (is_string($_GET['a'] ?? null) && ctype_digit($_GET['a'])) ? (int) $_GET['a'] : 0;
$req = payment_request_by_token($token);
if (!$req) {
    echo json_encode(['state' => 'invalid', 'paid' => false, 'activated' => false]);
    exit;
}
if (platform_rate_count('pay_status', hash('sha256', $token), 300) >= 300) {   // about 3 seconds apart for 15 minutes
    http_response_code(429);
    echo json_encode(['state' => 'pending', 'paid' => false, 'activated' => false, 'reason' => 'Too many checks. Please wait a moment.']);
    exit;
}
platform_rate_hit('pay_status', hash('sha256', $token));

$attempt = null;
if ($attemptId > 0) {
    $a = payment_attempt_get($attemptId);
    if ($a && (int) $a['signup_request_id'] === (int) $req['id']) {     // it must belong to THIS request
        $attempt = payment_refresh($attemptId);
    }
}
$status = (string) (signup_get((int) $req['id'])['status'] ?? $req['status']);
$paid = in_array($status, ['activating', 'provisioning', 'active', 'failed'], true);
$state = $attempt ? $attempt['status'] : ($paid ? 'success' : 'pending');
echo json_encode([
    'state'          => $state,
    'paid'           => $paid || ($attempt && $attempt['status'] === 'success'),
    'activated'      => $status === 'active',
    'request_status' => $status,
    'reason'         => $attempt && in_array($attempt['status'], ['failed', 'expired'], true) ? (string) $attempt['failure_reason'] : null,
]);
