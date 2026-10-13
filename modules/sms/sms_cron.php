<?php
/**
 * NEW FILE: modules/sms/sms_cron.php
 * Daily automatic SMS (from the MY MOTIVE SMS document):
 *   Message 8  unpaid credit reminder   -> each customer who owes money, once every 2 days
 *   Message 9  weekly Loyalty Points    -> each active customer with points, once a week
 * Run it once a day with Windows Task Scheduler (see the instructions), or open it in the
 * browser as Admin to run it by hand. Running it twice the same day sends nothing twice.
 */
$isCli = PHP_SAPI === 'cli';
require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sms_messages.php';
require_once __DIR__ . '/../../includes/credit_charge_helpers.php'; // NEW: daily overdue charges

// CHANGED: besides an Admin in the browser, an online scheduler (e.g. cron-job.org) can run this
// daily through a secret key: sms_cron.php?key=YOUR_KEY. The key is SMS_CRON_KEY in config/sms.php.
// Without the right key, the normal Admin login is required.
$keyOk = !$isCli && defined('SMS_CRON_KEY') && SMS_CRON_KEY !== '' && strlen(SMS_CRON_KEY) >= 20
    && isset($_GET['key']) && hash_equals(SMS_CRON_KEY, (string) $_GET['key']);
if (!$isCli && !$keyOk) {
    require_role(['Admin']);
}

$task = $isCli ? ($argv[1] ?? 'all') : ($_GET['task'] ?? 'all');
$results = [];
// NEW: apply the day's overdue charges FIRST, so the reminders show the up-to-date balance.
// Safe to run several times a day: a day is never charged twice.
if ($task === 'all' || $task === 'charges') {
    $c = credit_accrue_all($conn);
    $results['Overdue charges applied'] = $c['invoices'] . ' invoice(s), RWF ' . number_format($c['amount'], 2);
}
if ($task === 'all' || $task === 'reminders') {
    $results['Credit reminders sent'] = sms_send_credit_reminders($conn);
}
if ($task === 'all' || $task === 'loyalty') {
    $results['Loyalty messages sent'] = sms_send_loyalty_weekly($conn);
}

if ($isCli || $keyOk) {
    if ($keyOk) { header('Content-Type: text/plain; charset=utf-8'); }
    foreach ($results as $label => $n) { echo date('Y-m-d H:i:s') . ' ' . $label . ': ' . $n . PHP_EOL; }
    exit(0);
}

header('Location: sms_test.php?success=' . urlencode(implode(', ', array_map(function ($k, $v) { return $k . ': ' . $v; }, array_keys($results), $results)) . '.'));
exit;