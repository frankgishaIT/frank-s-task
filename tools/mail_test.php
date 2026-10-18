<?php
/**
 * Sends one test email with the platform's real mail settings, and explains what to check if it fails.
 *
 *   php tools/mail_test.php --to=you@example.com
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/platform_mail.php';

$to = '';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--to=(.+)$/', $arg, $m)) {
        $to = trim($m[1]);
    }
}
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php tools/mail_test.php --to=you@example.com\n");
    exit(2);
}

$mailFile = __DIR__ . '/../config/mail.php';
$cfg = is_file($mailFile) ? require $mailFile : null;
if (!is_array($cfg)) {
    fwrite(STDERR, "config/mail.php was not found, so there is no sender configured.\n");
    exit(1);
}
$mask = static function (string $v): string {
    return strlen($v) <= 4 ? str_repeat('*', strlen($v)) : substr($v, 0, 2) . str_repeat('*', max(1, strlen($v) - 4)) . substr($v, -2);
};
echo "Mail settings in config/mail.php:\n";
printf("  server:      %s  port %s  encryption: %s\n", $cfg['host'] ?? '?', $cfg['port'] ?? '?', $cfg['encryption'] ?? '?');
printf("  sign-in as:  %s   password: %s\n", $mask((string) ($cfg['username'] ?? '')), isset($cfg['password']) && $cfg['password'] !== '' ? '(set, hidden)' : 'EMPTY');
printf("  sent from:   %s <%s>\n", $cfg['from_name'] ?? '?', $cfg['from_email'] ?? '?');

$dry = (bool) platform_setting('mail_dry_run', false);
if ($dry) {
    echo "\nNOTE: mail_dry_run is ON. Nothing will really be sent: the message is only written to storage/platform_outbox.log.\n";
    echo "      To send real emails run:  php tools/platform_config.php set mail_dry_run false\n";
} elseif (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    fwrite(STDERR, "\nThe mail library is not installed (vendor/autoload.php is missing). Run:  composer install --no-dev\n");
    exit(1);
}

ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');
echo "\nSending a test message to $to ...\n";
$ok = platform_send_email($to, 'Test', 'MY MOTIVE test email', platform_mail_wrap('It works', '<p>This is a test message from MY MOTIVE. If you can read it, your mail settings are correct.</p>'));
if ($ok) {
    echo $dry ? "Done (dry-run): see storage/platform_outbox.log.\n" : "SENT. Check the inbox of $to (and the spam folder).\n";
    exit(0);
}
echo "\nFAILED. The reason is in the line(s) above that start with 'Email send failed'. Common causes:\n";
echo "  - Wrong server, port or encryption. Most servers: port 587 with 'tls', or port 465 with 'ssl'.\n";
echo "  - Wrong password. Gmail does not accept your normal password: turn on 2-step verification, then create an\n";
echo "    'App password' and put THAT in config/mail.php. Gmail server: smtp.gmail.com, port 587, encryption 'tls'.\n";
echo "  - The sender address (from_email) is not allowed by that mail account.\n";
echo "  - The server blocks outgoing mail ports (some hosts do): ask the hosting provider.\n";
exit(1);
