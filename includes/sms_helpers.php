<?php
/**
 * NEW FILE: includes/sms_helpers.php
 * Sends SMS through Pindo (https://api.pindo.io/v1/sms/) and records every attempt in sms_log.
 *
 * Rules:
 *  - sms_send() NEVER throws and NEVER stops the page. A failed SMS is logged, nothing else.
 *  - Call it AFTER mysqli_commit(), never inside a database transaction: a sale must not be
 *    rolled back because an SMS failed, and the SMS must not go out if the sale was rolled back.
 *  - Phone numbers are converted to the format Pindo needs (+2507XXXXXXXX).
 */
require_once __DIR__ . '/../config/sms.php';

// Longest message sent (3 SMS parts). Longer texts are shortened.
const SMS_MAX_LENGTH = 459;

/**
 * Converts a Rwandan phone number to E.164 (+2507XXXXXXXX). Returns null when it is not valid.
 * Accepts 0788123456, 788123456, 250788123456, +250 788 123 456, 0788-123-456 ...
 * Numbers that already start with + and another country code are kept as they are.
 */
function sms_normalize_phone(?string $raw): ?string {
    if ($raw === null) { return null; }
    $p = preg_replace('/[\s\-\.\(\)]/', '', $raw);
    if ($p === '') { return null; }

    if ($p[0] === '+') {
        $p = '+' . preg_replace('/\D/', '', substr($p, 1));
    } else {
        $p = preg_replace('/\D/', '', $p);
        if (strlen($p) === 10 && $p[0] === '0') {          // 0788123456
            $p = '+250' . substr($p, 1);
        } elseif (strlen($p) === 9 && $p[0] === '7') {      // 788123456
            $p = '+250' . $p;
        } elseif (strlen($p) === 12 && strpos($p, '250') === 0) { // 250788123456
            $p = '+' . $p;
        } else {
            return null;
        }
    }

    // Rwanda mobile numbers: +2507 followed by 8 digits. Other countries: 10 to 15 digits.
    if (strpos($p, '+250') === 0) {
        return preg_match('/^\+2507\d{8}$/', $p) ? $p : null;
    }
    return preg_match('/^\+\d{10,15}$/', $p) ? $p : null;
}

// Writes one row in sms_log. Never throws.
// CHANGED (Task 10): also writes the SMS Management columns (segments, template version, recipient category, module,
// branch) given by sms_notify() in $GLOBALS['__sms_meta']; works the same when those columns do not exist yet.
function sms_log(mysqli $conn, ?string $phone, string $text, string $event, ?string $refType, ?int $refId,
        string $status, ?string $response, ?string $error, ?int $userId): void {
    $response = $response !== null ? mb_substr($response, 0, 2000) : null;
    $error = $error !== null ? mb_substr($error, 0, 500) : null;
    $m = $GLOBALS['__sms_meta'] ?? null;
    if (!$m && function_exists('sms_segments')) { $m = ['segments' => sms_segments($text)[1], 'template_version' => null, 'recipient_type' => null, 'module' => function_exists('sms_event_module') ? sms_event_module($event) : null, 'branch_id' => null]; }
    if ($m) {
        try {
            $s = mysqli_prepare($conn, 'INSERT INTO sms_log (phone, message, event, ref_type, ref_id, status, response, error, created_by, segments, template_version, recipient_type, module, branch_id, sent_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $seg = (int) $m['segments']; $ver = $m['template_version']; $rt = $m['recipient_type']; $mod = $m['module']; $br = $m['branch_id'];
            $at = $status === 'sent' ? date('Y-m-d H:i:s') : null;
            mysqli_stmt_bind_param($s, 'ssssisssiiissis', $phone, $text, $event, $refType, $refId, $status, $response, $error, $userId, $seg, $ver, $rt, $mod, $br, $at);
            mysqli_stmt_execute($s);
            return;
        } catch (Throwable $e) {
            // columns not created yet: fall back to the original row
        }
    }
    try {
        $s = mysqli_prepare($conn, 'INSERT INTO sms_log (phone, message, event, ref_type, ref_id, status, response, error, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'ssssisssi', $phone, $text, $event, $refType, $refId, $status, $response, $error, $userId);
        mysqli_stmt_execute($s);
    } catch (Throwable $e) {
        error_log('sms_log failed: ' . $e->getMessage());
    }
}

/**
 * NEW (Task 10): sends one text through Pindo (the existing provider and sender ID RISEMOTIVE), without logging.
 * Returns [status 'sent'|'failed'|'skipped', provider reply, error].
 */
function sms_transport(string $phone, string $text): array {
    if (!SMS_ENABLED) { return ['skipped', null, 'SMS is switched off (SMS_ENABLED)']; }
    if (PINDO_API_TOKEN === '' || PINDO_API_TOKEN === 'PASTE_YOUR_PINDO_TOKEN_HERE') { return ['skipped', null, 'Pindo API token is not set in config/sms.php']; }
    if (!function_exists('curl_init')) { return ['failed', null, 'PHP cURL extension is not enabled']; }
    try {
        $ch = curl_init(PINDO_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,   // never keep a cashier waiting long
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . PINDO_API_TOKEN,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(['to' => $phone, 'text' => $text, 'sender' => PINDO_SENDER]),
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($body === false) { return ['failed', null, 'Connection error: ' . $curlError]; }
        if ($httpCode < 200 || $httpCode >= 300) {
            $decoded = json_decode((string) $body, true);
            return ['failed', (string) $body, is_array($decoded) && !empty($decoded['message']) ? $decoded['message'] : ('HTTP ' . $httpCode)];
        }
        return ['sent', (string) $body, null];
    } catch (Throwable $e) {
        return ['failed', null, $e->getMessage()];
    }
}

/**
 * Sends one SMS. Returns true when Pindo accepted it.
 * $event names what triggered it (e.g. 'SALE_RECEIPT'), $refType/$refId link it to the record.
 * CHANGED (Task 10): automatic messages go through sms_notify() (SMS Management). Safeguards here for every SMS:
 * the same text to the same number within a few minutes is blocked as a duplicate, and an optional daily maximum
 * per number (SMS Management → Settings). Security codes and tests are never blocked.
 */
function sms_send(mysqli $conn, ?string $to, string $text, string $event, ?string $refType = null,
        ?int $refId = null, ?int $userId = null): bool {
    $text = trim($text);
    if (mb_strlen($text) > SMS_MAX_LENGTH) {
        $text = mb_substr($text, 0, SMS_MAX_LENGTH - 3) . '...';
    }

    $phone = sms_normalize_phone($to);
    if ($phone === null) {
        sms_log($conn, $to, $text, $event, $refType, $refId, 'skipped', null, 'Invalid or missing phone number', $userId);
        return false;
    }
    $free = !empty($GLOBALS['__sms_security']) || strpos($event, 'TEST') === 0;
    if (!$free && function_exists('sms_center_ready') && sms_center_ready($conn)) {
        try {
            $mins = (int) sms_setting($conn, 'duplicate_minutes');
            if ($mins > 0) {
                $s = mysqli_prepare($conn, "SELECT id FROM sms_log WHERE phone = ? AND message = ? AND status IN ('sent', 'queued') AND created_at >= ? LIMIT 1");
                $since = date('Y-m-d H:i:s', time() - 60 * $mins);
                mysqli_stmt_bind_param($s, 'sss', $phone, $text, $since); mysqli_stmt_execute($s);
                if (mysqli_fetch_assoc(mysqli_stmt_get_result($s))) {
                    sms_log($conn, $phone, $text, $event, $refType, $refId, 'duplicate', null, 'The same message went to this number less than ' . $mins . ' minute(s) ago', $userId);
                    return false;
                }
            }
            $cap = (int) sms_setting($conn, 'daily_cap');
            if ($cap > 0 && $event !== 'MANUAL') {
                $s = mysqli_prepare($conn, "SELECT COUNT(*) FROM sms_log WHERE phone = ? AND status = 'sent' AND created_at >= ?");
                $day = date('Y-m-d 00:00:00');
                mysqli_stmt_bind_param($s, 'ss', $phone, $day); mysqli_stmt_execute($s);
                if ((int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0] >= $cap) {
                    sms_log($conn, $phone, $text, $event, $refType, $refId, 'filtered', null, 'Daily maximum of ' . $cap . ' SMS for this number reached', $userId);
                    return false;
                }
            }
        } catch (Throwable $e) { /* never stop a sale because of a safeguard */ }
    }

    [$status, $body, $error] = sms_transport($phone, $text);
    sms_log($conn, $phone, $text, $event, $refType, $refId, $status, $body, $error, $userId);
    return $status === 'sent';
}