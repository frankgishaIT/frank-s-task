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

if (!$isCli) {
    require_role(['Admin']);
}

$task = $isCli ? ($argv[1] ?? 'all') : ($_GET['task'] ?? 'all');
$results = [];
if ($task === 'all' || $task === 'reminders') {
    $results['Credit reminders sent'] = sms_send_credit_reminders($conn);
}
if ($task === 'all' || $task === 'loyalty') {
    $results['Loyalty messages sent'] = sms_send_loyalty_weekly($conn);
}

if ($isCli) {
    foreach ($results as $label => $n) { echo date('Y-m-d H:i:s') . ' ' . $label . ': ' . $n . PHP_EOL; }
    exit(0);
}

header('Location: sms_test.php?success=' . urlencode(implode(', ', array_map(function ($k, $v) { return $k . ': ' . $v; }, array_keys($results), $results)) . '.'));
exit;