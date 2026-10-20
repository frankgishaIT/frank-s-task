<?php
/**
 * Shows, in plain words, why SMS does or does not work for one business, and can send a real test message.
 *
 *   php tools/sms_check.php --code=RMCP0002                       the settings, the monthly count, the last 5 attempts
 *   php tools/sms_check.php --code=RMCP0002 --to=0788123456       also sends one real test message to that number
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/provisioning.php';
require_once __DIR__ . '/../includes/features.php';
require_once __DIR__ . '/../includes/company_profile.php';
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $a, $m)) {
        $opt[$m[1]] = $m[2];
    }
}
$code = platform_normalize_code($opt['code'] ?? '');
$company = $code !== '' ? platform_company_by_code($code) : null;
if (!$company) {
    fwrite(STDERR, "Usage: php tools/sms_check.php --code=RMCP0002 [--to=0788123456]\n");
    exit(1);
}
$GLOBALS['MM_COMPANY'] = $company;
$GLOBALS['MM_PACKAGE'] = platform_package_for_company((int) $company['id']);
$GLOBALS['MM_FEATURES'] = $GLOBALS['MM_PACKAGE']['features'] ?? [];
$conn = tenant_try_connect($company);
$GLOBALS['conn'] = $conn;
company_profile_reset();
require_once __DIR__ . '/../config/sms.php';
require_once __DIR__ . '/../includes/sms_helpers.php';
require_once __DIR__ . '/../includes/sms_messages.php';

$quota = mm_sms_quota();
$say = static fn(string $k, string $v) => print(str_pad($k, 26) . $v . "\n");
echo "SMS check for {$company['company_code']} ({$company['name']})\n\n";
$say('SMS switched on:', SMS_ENABLED ? 'yes' : 'NO  -> set SMS_ENABLED to true in config/sms.php');
$tokenOk = PINDO_API_TOKEN !== '' && PINDO_API_TOKEN !== 'PASTE_YOUR_PINDO_TOKEN_HERE';
$say('Pindo token set:', $tokenOk ? 'yes (' . strlen(PINDO_API_TOKEN) . ' characters)' : 'NO  -> put the token in config/sms.php');
$say('Sender name:', SMS_SENDER_ID);
$say('PHP cURL:', function_exists('curl_init') ? 'yes' : 'NO  -> install php-curl');
$say('Package:', mm_package_name());
$say('Monthly limit:', $quota === null ? 'none (your own business)' : (string) $quota);
$say('Used this month:', (string) sms_month_usage($conn));
$say('Business name in texts:', company_profile()['name']);
if (isset($opt['to'])) {
    $ok = sms_send($conn, $opt['to'], 'Test SMS from ' . company_profile()['name'] . ' via MY MOTIVE.', 'SMS_CHECK');
    echo "\nTest message to {$opt['to']}: " . ($ok ? 'SENT' : 'NOT SENT') . "\n";
}
echo "\nLast 5 attempts:\n";
$res = mysqli_query($conn, 'SELECT created_at, status, phone, error FROM sms_log ORDER BY id DESC LIMIT 5');
$n = 0;
while ($r = mysqli_fetch_assoc($res)) {
    $n++;
    echo '  ' . $r['created_at'] . '  ' . str_pad($r['status'], 8) . ' ' . str_pad((string) $r['phone'], 14) . ' ' . ($r['error'] ?: 'ok') . "\n";
}
if ($n === 0) {
    echo "  (none yet)\n";
}
