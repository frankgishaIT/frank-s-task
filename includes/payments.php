<?php
/**
 * Paying for a company: from the "Approved" email to a confirmed payment.
 *
 *   approval  -> a secure pay link is made (only its fingerprint is stored)
 *   pay page  -> the customer gives a mobile-money number -> XentriPay sends an approval prompt to the phone
 *   status    -> the page (and, as a safety net, the worker) asks XentriPay "is it paid?"
 *   confirmed -> the request goes to "activating" exactly once -> the worker creates the company
 *
 * Nothing here trusts the browser: the amount is read from our database, never from the page, and a payment is
 * counted only when XentriPay itself says SUCCESS.
 */

require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/platform_web.php';   // the rate-limit helpers
require_once __DIR__ . '/xentripay.php';
require_once __DIR__ . '/mail_templates.php';
require_once __DIR__ . '/platform_mail.php';

const PAY_LINK_DAYS = 14;          // how long a pay link works
const PAY_WAIT_SECONDS = 180;      // how long the customer is asked to wait for the phone prompt
const PAY_GIVE_UP_HOURS = 3;       // a payment still "pending" after this long is closed

function payment_pay_url(string $token): string
{
    return platform_public_url() . '/pay.php?t=' . $token;
}

/** Makes a new pay link for an approved request. Older links stop working. Returns [secret part, expires at]. */
function payment_issue_token(int $requestId): array
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $st = mysqli_prepare(platform_conn(), 'UPDATE signup_requests SET pay_token_hash = ?, pay_token_expires = (NOW() + INTERVAL ? DAY) WHERE id = ?');
    $days = PAY_LINK_DAYS;
    mysqli_stmt_bind_param($st, 'sii', $hash, $days, $requestId);
    mysqli_stmt_execute($st);
    $st = mysqli_prepare(platform_conn(), 'SELECT pay_token_expires FROM signup_requests WHERE id = ?');
    mysqli_stmt_bind_param($st, 'i', $requestId);
    mysqli_stmt_execute($st);
    $expires = (string) mysqli_fetch_row(mysqli_stmt_get_result($st))[0];
    return [$token, $expires];
}

/** The request behind a pay link: null if the link is not genuine or has expired. */
function payment_request_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $hash = hash('sha256', $token);
    $st = mysqli_prepare(platform_conn(), 'SELECT r.*, p.name AS package_name, p.code AS package_code FROM signup_requests r JOIN packages p ON p.id = r.package_id WHERE r.pay_token_hash = ? AND r.pay_token_expires > NOW() LIMIT 1');
    mysqli_stmt_bind_param($st, 's', $hash);
    mysqli_stmt_execute($st);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: null;
}

/**
 * What the customer is charged. Normally the price of the package. On a test server you can set
 * payment_test_mode and payment_test_amount (for example 100) to try a REAL payment for a small sum.
 *
 * @return array{amount:int, list:int, test:bool}
 */
function payment_charge(array $req): array
{
    $list = (int) $req['amount_due'];
    if (platform_setting('payment_test_mode', false)) {
        $test = (int) platform_setting('payment_test_amount', 100);
        if ($test >= 100 && $test < $list) {
            return ['amount' => $test, 'list' => $list, 'test' => true];
        }
    }
    return ['amount' => $list, 'list' => $list, 'test' => false];
}

function payment_attempt_get(int $id): ?array
{
    $st = mysqli_prepare(platform_conn(), 'SELECT * FROM payment_attempts WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: null;
}

function payment_attempts_for(int $requestId): array
{
    $st = mysqli_prepare(platform_conn(), 'SELECT * FROM payment_attempts WHERE signup_request_id = ? ORDER BY id DESC LIMIT 20');
    mysqli_stmt_bind_param($st, 'i', $requestId);
    mysqli_stmt_execute($st);
    $out = [];
    $res = mysqli_stmt_get_result($st);
    while ($row = mysqli_fetch_assoc($res)) {
        $out[] = $row;
    }
    return $out;
}

/** The attempt that is still waiting for the phone (started less than PAY_WAIT_SECONDS ago), if any. */
function payment_open_attempt(int $requestId): ?array
{
    $st = mysqli_prepare(platform_conn(), "SELECT * FROM payment_attempts WHERE signup_request_id = ? AND status IN ('initiated','pending') AND created_at > (NOW() - INTERVAL ? SECOND) ORDER BY id DESC LIMIT 1");
    $wait = PAY_WAIT_SECONDS;
    mysqli_stmt_bind_param($st, 'ii', $requestId, $wait);
    mysqli_stmt_execute($st);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: null;
}

/**
 * Starts a payment. Returns ['ok' => bool, 'attempt' => row|null, 'message' => text].
 * If a payment is already waiting for the phone, that one is returned instead of starting a second charge.
 */
function payment_start(array $req, string $phoneInput, string $ip): array
{
    if ($req['status'] !== 'approved') {
        return ['ok' => false, 'attempt' => null, 'message' => 'This request is not waiting for a payment.'];
    }
    if (!xentripay_configured()) {
        error_log('[MY MOTIVE payments] XentriPay is not configured (xentripay_base_url / xentripay_api_key).');
        return ['ok' => false, 'attempt' => null, 'message' => 'Online payment is not available right now. Please contact us.'];
    }
    $phone = xentripay_phone($phoneInput);
    if (!$phone) {
        return ['ok' => false, 'attempt' => null, 'message' => 'Please enter a valid MTN or Airtel number, for example 0788 123 456.'];
    }
    $open = payment_open_attempt((int) $req['id']);
    if ($open) {
        return ['ok' => true, 'attempt' => $open, 'message' => 'A payment request is already waiting on your phone.'];
    }
    $rid = (string) $req['id'];
    if (platform_rate_count('pay_start_req', $rid, 3600) >= 8 || platform_rate_count('pay_start_ip', $ip, 3600) >= 25) {
        return ['ok' => false, 'attempt' => null, 'message' => 'Too many attempts. Please wait a few minutes and try again.'];
    }
    platform_rate_hit('pay_start_req', $rid);
    platform_rate_hit('pay_start_ip', $ip);

    $charge = payment_charge($req);
    $db = platform_conn();
    $count = (int) mysqli_fetch_row(mysqli_query($db, 'SELECT COUNT(*) FROM payment_attempts WHERE signup_request_id = ' . (int) $req['id']))[0];
    $customerRef = 'MM-' . $req['reference'] . '-' . ($count + 1);
    $st = mysqli_prepare($db, "INSERT INTO payment_attempts (signup_request_id, customer_ref, msisdn, amount, list_amount, status) VALUES (?, ?, ?, ?, ?, 'initiated')");
    $reqId = (int) $req['id'];
    mysqli_stmt_bind_param($st, 'issii', $reqId, $customerRef, $phone['msisdn'], $charge['amount'], $charge['list']);
    mysqli_stmt_execute($st);
    $attemptId = (int) mysqli_insert_id($db);

    $result = xentripay_initiate([
        'email'       => $req['owner_email'],
        'cname'       => $req['owner_name'],
        'cnumber'     => $phone['local'],
        'msisdn'      => $phone['msisdn'],
        'amount'      => $charge['amount'],
        'customerRef' => $customerRef,
        'details'     => 'MY MOTIVE ' . $req['package_name'] . ' (' . $req['billing_period'] . ') - ' . $req['reference'],
    ]);
    if (!$result['ok']) {
        $reason = substr((string) $result['message'], 0, 300);
        $st = mysqli_prepare($db, "UPDATE payment_attempts SET status = 'failed', failure_reason = ? WHERE id = ?");
        mysqli_stmt_bind_param($st, 'si', $reason, $attemptId);
        mysqli_stmt_execute($st);
        platform_audit(null, 'applicant:' . $req['owner_email'], 'payment_not_started', 'ref=' . $req['reference'] . ' ' . $reason);
        return ['ok' => false, 'attempt' => payment_attempt_get($attemptId), 'message' => (string) $result['message']];
    }
    $st = mysqli_prepare($db, "UPDATE payment_attempts SET status = 'pending', gateway_refid = ? WHERE id = ?");
    mysqli_stmt_bind_param($st, 'si', $result['refid'], $attemptId);
    mysqli_stmt_execute($st);
    platform_audit(null, 'applicant:' . $req['owner_email'], 'payment_started', 'ref=' . $req['reference'] . ' amount=' . $charge['amount'] . ' network=' . $phone['network']);
    return ['ok' => true, 'attempt' => payment_attempt_get($attemptId), 'message' => 'Payment request sent to your phone.'];
}

/**
 * Asks XentriPay about one attempt and records the answer. Safe to call as often as you like, from anywhere:
 * the step that confirms a payment can only succeed once.
 */
function payment_refresh(int $attemptId): ?array
{
    $a = payment_attempt_get($attemptId);
    if (!$a || !in_array($a['status'], ['initiated', 'pending'], true)) {
        return $a;
    }
    $db = platform_conn();
    // ask at most once every 2 seconds, however many pages are watching
    if ($a['last_checked_at'] !== null && (time() - strtotime($a['last_checked_at'])) < 2) {
        return $a;
    }
    $ageHours = (time() - strtotime($a['created_at'])) / 3600;
    $st = mysqli_prepare($db, 'UPDATE payment_attempts SET last_checked_at = NOW() WHERE id = ?');
    mysqli_stmt_bind_param($st, 'i', $attemptId);
    mysqli_stmt_execute($st);

    $reference = $a['gateway_refid'] ?: $a['customer_ref'];
    $r = xentripay_status($reference);

    if ($r['status'] === 'SUCCESS') {
        $st = mysqli_prepare($db, "UPDATE payment_attempts SET status = 'success', confirmed_at = NOW(), failure_reason = NULL WHERE id = ? AND status IN ('initiated','pending')");
        mysqli_stmt_bind_param($st, 'i', $attemptId);
        mysqli_stmt_execute($st);
        if (mysqli_stmt_affected_rows($st) === 1) {          // only the first one to notice does the rest
            payment_confirm(payment_attempt_get($attemptId));
        }
    } elseif ($r['status'] === 'FAILED') {
        $reason = 'The payment was declined, cancelled or timed out on the phone.';
        $st = mysqli_prepare($db, "UPDATE payment_attempts SET status = 'failed', failure_reason = ? WHERE id = ? AND status IN ('initiated','pending')");
        mysqli_stmt_bind_param($st, 'si', $reason, $attemptId);
        mysqli_stmt_execute($st);
    } elseif ($ageHours > PAY_GIVE_UP_HOURS) {
        $reason = 'No answer from the phone. Please try again.';
        $st = mysqli_prepare($db, "UPDATE payment_attempts SET status = 'expired', failure_reason = ? WHERE id = ? AND status IN ('initiated','pending')");
        mysqli_stmt_bind_param($st, 'si', $reason, $attemptId);
        mysqli_stmt_execute($st);
    }
    return payment_attempt_get($attemptId);
}

/**
 * A payment is confirmed: the request moves from "approved" to "activating" (once), the payment is recorded,
 * and the customer gets a receipt. The worker then creates the company.
 */
function payment_confirm(array $attempt): bool
{
    $db = platform_conn();
    $reqId = (int) $attempt['signup_request_id'];
    mysqli_begin_transaction($db);
    try {
        $st = mysqli_prepare($db, "UPDATE signup_requests SET status = 'activating', last_error = NULL WHERE id = ? AND status = 'approved'");
        mysqli_stmt_bind_param($st, 'i', $reqId);
        mysqli_stmt_execute($st);
        $moved = mysqli_stmt_affected_rows($st) === 1;
        if ($moved) {
            $ref = (string) ($attempt['gateway_refid'] ?: $attempt['customer_ref']);
            $method = 'XentriPay MoMo';
            $by = 'xentripay';
            $amount = (int) $attempt['amount'];
            $st = mysqli_prepare($db, 'INSERT INTO payments (signup_request_id, amount, method, reference, recorded_by) VALUES (?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($st, 'iisss', $reqId, $amount, $method, $ref, $by);
            mysqli_stmt_execute($st);
        }
        mysqli_commit($db);
    } catch (Throwable $e) {
        mysqli_rollback($db);
        error_log('[MY MOTIVE payments] could not confirm attempt ' . $attempt['id'] . ': ' . $e->getMessage());
        // put the attempt back so the next check tries again
        $st = mysqli_prepare($db, "UPDATE payment_attempts SET status = 'pending', confirmed_at = NULL WHERE id = ?");
        $id = (int) $attempt['id'];
        mysqli_stmt_bind_param($st, 'i', $id);
        mysqli_stmt_execute($st);
        return false;
    }
    $st = mysqli_prepare($db, 'SELECT r.*, p.name AS package_name FROM signup_requests r JOIN packages p ON p.id = r.package_id WHERE r.id = ?');
    mysqli_stmt_bind_param($st, 'i', $reqId);
    mysqli_stmt_execute($st);
    $req = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$moved) {
        // money arrived but the request was no longer waiting for it (cancelled, or paid another way): a person must look
        platform_audit(null, 'xentripay', 'payment_not_applied', 'ref=' . ($req['reference'] ?? '?') . ' attempt=' . $attempt['id'] . ' status=' . ($req['status'] ?? '?') . ' amount=' . $attempt['amount']);
        $notify = (string) platform_setting('admin_notify_email', '');
        if ($notify !== '' && $req) {
            platform_send_email($notify, 'MY MOTIVE admin', 'ATTENTION: a payment arrived for ' . $req['reference'], mm_email_layout([
                'title' => 'A payment needs your attention',
                'intro' => mm_mail_p('A payment of <b>' . mm_e(mm_mail_money((int) $attempt['amount'])) . '</b> was received for the request <b>' . mm_e($req['reference']) . '</b>, but the request was no longer waiting for payment (its status is ' . mm_e($req['status']) . '). Please check it and refund if needed.'),
            ])[1]);
        }
        return false;
    }
    platform_audit(null, 'xentripay', 'payment_confirmed', 'ref=' . $req['reference'] . ' amount=' . $attempt['amount']);
    [$subject, $html] = mm_mail_payment_received($req, (int) $attempt['amount'], (string) ($attempt['gateway_refid'] ?: $attempt['customer_ref']));
    platform_send_email($req['owner_email'], $req['owner_name'], $subject, $html);
    return true;
}

/** For the worker: checks every payment that is still waiting, so a payment is never lost if the customer closes the page. */
function payment_reconcile(): array
{
    $db = platform_conn();
    $before = [];
    $res = mysqli_query($db, "SELECT id, status FROM payment_attempts WHERE status IN ('initiated','pending') AND created_at > (NOW() - INTERVAL 1 DAY) ORDER BY id LIMIT 50");
    while ($row = mysqli_fetch_row($res)) {
        $before[(int) $row[0]] = $row[1];
    }
    $report = [];
    foreach ($before as $id => $was) {
        $a = payment_refresh($id);
        if ($a && $a['status'] !== $was && !in_array($a['status'], ['initiated', 'pending'], true)) {
            $report[] = 'attempt ' . $id . ': ' . $a['status'];      // only say something when something changed
        }
    }
    // anything older than a day that never got an answer is closed
    mysqli_query($db, "UPDATE payment_attempts SET status = 'expired', failure_reason = 'No answer from the phone.' WHERE status IN ('initiated','pending') AND created_at <= (NOW() - INTERVAL 1 DAY)");
    return $report;
}
