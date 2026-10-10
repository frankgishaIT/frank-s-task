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
function sms_log(mysqli $conn, ?string $phone, string $text, string $event, ?string $refType, ?int $refId,
        string $status, ?string $response, ?string $error, ?int $userId): void {
    try {
        $s = mysqli_prepare($conn, 'INSERT INTO sms_log (phone, message, event, ref_type, ref_id, status, response, error, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $response = $response !== null ? mb_substr($response, 0, 2000) : null;
        $error = $error !== null ? mb_substr($error, 0, 500) : null;
        mysqli_stmt_bind_param($s, 'ssssisssi', $phone, $text, $event, $refType, $refId, $status, $response, $error, $userId);
        mysqli_stmt_execute($s);
    } catch (Throwable $e) {
        error_log('sms_log failed: ' . $e->getMessage());
    }
}

/**
 * Sends one SMS. Returns true when Pindo accepted it.
 * $event names what triggered it (e.g. 'SALE_RECEIPT'), $refType/$refId link it to the record.
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
    if (!SMS_ENABLED) {
        sms_log($conn, $phone, $text, $event, $refType, $refId, 'skipped', null, 'SMS is switched off (SMS_ENABLED)', $userId);
        return false;
    }
    if (PINDO_API_TOKEN === '' || PINDO_API_TOKEN === 'PASTE_YOUR_PINDO_TOKEN_HERE') {
        sms_log($conn, $phone, $text, $event, $refType, $refId, 'skipped', null, 'Pindo API token is not set in config/sms.php', $userId);
        return false;
    }
    if (!function_exists('curl_init')) {
        sms_log($conn, $phone, $text, $event, $refType, $refId, 'failed', null, 'PHP cURL extension is not enabled', $userId);
        return false;
    }

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

        if ($body === false) {
            sms_log($conn, $phone, $text, $event, $refType, $refId, 'failed', null, 'Connection error: ' . $curlError, $userId);
            return false;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $decoded = json_decode((string) $body, true);
            $message = is_array($decoded) && !empty($decoded['message']) ? $decoded['message'] : ('HTTP ' . $httpCode);
            sms_log($conn, $phone, $text, $event, $refType, $refId, 'failed', (string) $body, $message, $userId);
            return false;
        }

        sms_log($conn, $phone, $text, $event, $refType, $refId, 'sent', (string) $body, null, $userId);
        return true;
    } catch (Throwable $e) {
        sms_log($conn, $phone, $text, $event, $refType, $refId, 'failed', null, $e->getMessage(), $userId);
        return false;
    }
}