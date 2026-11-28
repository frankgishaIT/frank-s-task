<?php
/**
 * NEW FILE: modules/delegations/cron.php — runs the Delegations monitoring now: follow-up to staff when an RM Tasker
 * has not answered, reminders before deadlines, alerts for missed deadlines.
 * It also runs by itself (every 10 minutes at most) whenever staff open the Dashboard or a Delegations page.
 * For exact timing, schedule it every 15 minutes: modules/delegations/cron.php?key=SMS_CRON_KEY (the same secret key as the SMS daily task).
 */
$isCli = PHP_SAPI === 'cli';
require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/dl_workflow.php';
require_once __DIR__ . '/../../includes/sms_helpers.php'; // the secret key (config/sms.php)
$keyOk = !$isCli && defined('SMS_CRON_KEY') && SMS_CRON_KEY !== '' && strlen(SMS_CRON_KEY) >= 20 && isset($_GET['key']) && hash_equals(SMS_CRON_KEY, (string) $_GET['key']);
if (!$isCli && !$keyOk) {
    require_once __DIR__ . '/../../includes/access_control.php';
    require_role(['Admin', 'Manager']);
}
$r = dl_monitor($conn, true);
header('Content-Type: text/plain; charset=utf-8');
echo 'Delegations monitoring ' . date('Y-m-d H:i') . ': ' . (int) ($r['followups'] ?? 0) . ' follow-up(s) to staff, ' . (int) ($r['reminders'] ?? 0) . ' deadline reminder(s), ' . (int) ($r['overdue'] ?? 0) . " missed-deadline alert(s).\n";