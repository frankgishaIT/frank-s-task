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
$allowed = ['public_url' => 'text', 'mail_dry_run' => 'bool', 'admin_notify_email' => 'text', 'payment_instructions' => 'text', 'terms_url' => 'text'];
$command = $argv[1] ?? '';
if ($command === 'show') {
    foreach ($cfg as $k => $v) {
        $shown = in_array($k, ['db_pass', 'key'], true) ? '<hidden>' : (is_bool($v) ? ($v ? 'true' : 'false') : $v);
        echo str_pad($k, 22) . ' ' . $shown . "\n";
    }
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
echo "Usage:\n  php tools/platform_config.php show\n  php tools/platform_config.php set <" . implode('|', array_keys($allowed)) . "> <value>\n";
exit($command === '' ? 0 : 2);
