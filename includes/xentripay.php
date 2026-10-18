<?php
/**
 * XentriPay (mobile money) for MY MOTIVE. This is the same account and the same calls as the RISE MOTIVE platform:
 *   POST {base}/api/collections/initiate        starts a collection: the customer gets an approval prompt on the phone
 *   GET  {base}/api/collections/status/{ref}    PENDING | SUCCESS | FAILED
 * Authentication: the header  X-XENTRIPAY-KEY.
 * Settings (config/platform.php): xentripay_base_url, xentripay_api_key.  Set them with tools/platform_config.php.
 */

require_once __DIR__ . '/platform.php';

function xentripay_configured(): bool
{
    return trim((string) platform_setting('xentripay_base_url', '')) !== '' && trim((string) platform_setting('xentripay_api_key', '')) !== '';
}

/**
 * A Rwandan mobile number in any usual shape: 0788123456, 788123456, 250788123456, +250 788 123 456.
 * @return array{local:string,msisdn:string,network:string}|null
 */
function xentripay_phone(string $input): ?array
{
    $d = preg_replace('/\D+/', '', $input);
    if (strlen($d) === 12 && strpos($d, '250') === 0) {
        $d = '0' . substr($d, 3);
    } elseif (strlen($d) === 9) {
        $d = '0' . $d;
    }
    if (!preg_match('/^07[2389][0-9]{7}$/', $d)) {
        return null;
    }
    return ['local' => $d, 'msisdn' => '250' . substr($d, 1), 'network' => in_array(substr($d, 0, 3), ['078', '079'], true) ? 'MTN' : 'Airtel'];
}

/** One call to XentriPay. Never throws. */
function xentripay_call(string $method, string $path, ?array $body = null): array
{
    $url = rtrim((string) platform_setting('xentripay_base_url', ''), '/') . $path;
    $headers = ['X-XENTRIPAY-KEY: ' . platform_setting('xentripay_api_key', ''), 'Content-Type: application/json', 'Accept: application/json'];
    $payload = $body === null ? null : json_encode($body);
    $raw = false;
    $http = 0;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $payload ?? '', 'timeout' => 15, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $http = (int) $m[1];
        }
        if ($raw === false) {
            $error = 'no answer';
        }
    }
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return ['http' => $http, 'json' => is_array($json) ? $json : null, 'raw' => is_string($raw) ? $raw : '', 'error' => $error];
}

/**
 * Starts a mobile-money collection (the customer gets an approval prompt on the phone).
 *
 * @return array{ok:bool, refid?:string, message?:string, rejected?:bool}
 *   rejected = true: XentriPay answered "no" (nothing was created). false: no usable answer (the customer may try again).
 */
function xentripay_initiate(array $p): array
{
    $r = xentripay_call('POST', '/api/collections/initiate', [
        'email' => $p['email'], 'cname' => $p['cname'], 'cnumber' => $p['cnumber'], 'msisdn' => $p['msisdn'],
        'amount' => (int) $p['amount'], 'currency' => 'RWF', 'pmethod' => 'momo',
        'customerRef' => $p['customerRef'], 'details' => $p['details'] ?? '', 'chargesIncluded' => true,
    ]);
    if ($r['http'] === 0 || $r['error'] !== '') {
        error_log('[MY MOTIVE xentripay] initiate: no answer (' . $r['error'] . ')');
        return ['ok' => false, 'rejected' => false, 'message' => 'The payment provider could not be reached. Please try again in a moment.'];
    }
    $j = $r['json'];
    if ($r['http'] >= 500) {
        error_log('[MY MOTIVE xentripay] initiate: HTTP ' . $r['http']);
        return ['ok' => false, 'rejected' => false, 'message' => 'The payment provider is busy. Please try again in a moment.'];
    }
    if ($r['http'] >= 400) {
        $msg = is_array($j) && !empty($j['message']) ? (string) $j['message'] : 'The payment provider did not accept the request.';
        return ['ok' => false, 'rejected' => true, 'message' => $msg];
    }
    if (is_array($j) && (int) ($j['success'] ?? 0) === 1 && !empty($j['refid'])) {
        return ['ok' => true, 'refid' => (string) $j['refid']];
    }
    return ['ok' => false, 'rejected' => true, 'message' => is_array($j) && !empty($j['reply']) ? (string) $j['reply'] : 'The payment was not accepted. Please check the number and try again.'];
}

/** @return array{ok:bool, status:string}  status = PENDING | SUCCESS | FAILED | UNKNOWN (no usable answer) */
function xentripay_status(string $reference): array
{
    $r = xentripay_call('GET', '/api/collections/status/' . rawurlencode($reference));
    if ($r['http'] !== 200 || !is_array($r['json'])) {
        return ['ok' => false, 'status' => 'UNKNOWN'];
    }
    $inner = isset($r['json']['data']) && is_array($r['json']['data']) ? $r['json']['data'] : $r['json'];
    $s = strtoupper(trim((string) ($inner['status'] ?? '')));
    return in_array($s, ['PENDING', 'SUCCESS', 'FAILED'], true) ? ['ok' => true, 'status' => $s] : ['ok' => false, 'status' => 'UNKNOWN'];
}
