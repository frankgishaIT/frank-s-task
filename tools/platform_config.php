<?php
/**
 * Change one platform setting in config/platform.php without editing the file by hand.
 *
 *   php tools/platform_config.php show
 *   php tools/platform_config.php set public_url https://mymotive.risemotive.rw
 *   php tools/platform_config.php set mail_dry_run true
 *
 * Settings: public_url, mail_dry_run (true/false), admin_notify_email, payment_instructions, terms_url
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$file = __DIR__ . '/../config/platform.php';
$cfg = is_file($file) ? require $file : null;
if (!is_array($cfg)) {
    fwrite(STDERR, "config/platform.php was not found. Run tools/install_platform.php first.\n");
    exit(1);
}
$allowed = ['public_url' => 'text', 'mail_dry_run' => 'bool', 'admin_notify_email' => 'text', 'payment_instructions' => 'text', 'terms_url' => 'text',
    'xentripay_base_url' => 'text', 'xentripay_api_key' => 'text', 'payment_test_mode' => 'bool', 'payment_test_amount' => 'text'];
$secret = ['db_pass', 'key', 'xentripay_api_key'];
$command = $argv[1] ?? '';
if ($command === 'show') {
    foreach ($cfg as $k => $v) {
        $shown = in_array($k, $secret, true) ? '<hidden>' : (is_bool($v) ? ($v ? 'true' : 'false') : $v);
        echo str_pad($k, 22) . ' ' . $shown . "\n";
    }
    exit(0);
}
if ($command === 'import-xentripay') {
    // Copies the XentriPay address and key from the RISE MOTIVE backend's .env file, without ever printing them.
    $envFile = $argv[2] ?? '/var/www/api.risemotive.rw/.env';
    if (!is_readable($envFile)) {
        fwrite(STDERR, "Cannot read $envFile\n");
        exit(1);
    }
    $found = [];
    foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^\s*(XENTRIPAY_BASE_URL|XENTRIPAY_API_KEY)\s*=\s*(.*)$/', $line, $m)) {
            $found[$m[1]] = trim(trim($m[2]), "\"'");
        }
    }
    if (empty($found['XENTRIPAY_BASE_URL']) || empty($found['XENTRIPAY_API_KEY'])) {
        fwrite(STDERR, "XENTRIPAY_BASE_URL and XENTRIPAY_API_KEY were not both found in $envFile\n");
        exit(1);
    }
    $cfg['xentripay_base_url'] = rtrim($found['XENTRIPAY_BASE_URL'], '/');
    $cfg['xentripay_api_key'] = $found['XENTRIPAY_API_KEY'];
    $text = "<?php\n// MY MOTIVE SaaS platform settings. NOT in git. Keep this file private.\n"
        . "// 'key' encrypts every company's database password. If it is lost, those passwords cannot be recovered: back this file up.\n"
        . 'return ' . var_export($cfg, true) . ";\n";
    if (file_put_contents($file, $text) === false) {
        fwrite(STDERR, "Could not write config/platform.php.\n");
        exit(1);
    }
    $sandbox = strpos($cfg['xentripay_base_url'], 'test') !== false;
    echo "XentriPay settings copied (the key is not shown).\nAddress: {$cfg['xentripay_base_url']}   " . ($sandbox ? '(this looks like the SANDBOX: no real money moves)' : '(this is the LIVE address: real money moves)') . "\n";
    exit(0);
}
if ($command === 'set' && isset($argv[2], $argv[3]) && isset($allowed[$argv[2]])) {
    $cfg[$argv[2]] = $allowed[$argv[2]] === 'bool' ? in_array(strtolower($argv[3]), ['1', 'true', 'yes', 'on'], true) : $argv[3];
    $text = "<?php\n// MY MOTIVE SaaS platform settings. NOT in git. Keep this file private.\n"
        . "// 'key' encrypts every company's database password. If it is lost, those passwords cannot be recovered: back this file up.\n"
        . 'return ' . var_export($cfg, true) . ";\n";
    if (file_put_contents($file, $text) === false) {
        fwrite(STDERR, "Could not write config/platform.php.\n");
        exit(1);
    }
    echo "Saved: {$argv[2]}\n";
    exit(0);
}
echo "Usage:\n  php tools/platform_config.php show\n  php tools/platform_config.php import-xentripay [/path/to/.env]   (copies the XentriPay address and key from RISE MOTIVE)\n  php tools/platform_config.php set <" . implode('|', array_keys($allowed)) . "> <value>\n";
exit($command === '' ? 0 : 2);
