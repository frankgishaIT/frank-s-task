<?php
/**
 * The daily job for the whole platform. It runs once a day (started by the worker) and does two things:
 *
 *   1. SUBSCRIPTIONS   renewal reminders, and pausing companies that did not renew (see subscriptions.php)
 *   2. EACH COMPANY    the SMS payment reminders, if the business has them switched on and its package limit allows it.
 *                      The "overdue charges" and "weekly loyalty" jobs run only for companies that switched them on.
 *
 * One company failing never stops the others. Every step can be previewed with dry-run: nothing is sent or changed.
 */

require_once __DIR__ . '/provisioning.php';
require_once __DIR__ . '/subscriptions.php';
require_once __DIR__ . '/features.php';           // the package of each business (SMS limit)
require_once __DIR__ . '/company_profile.php';   // each business's own details and options

const DAILY_DEFAULT_HOUR = 7;   // the job starts at or after this hour (server time) each day

/** The reminders of ONE company. Returns a one-line report. */
function daily_company(array $company, bool $dryRun): string
{
    $label = $company['company_code'];
    // the business's world, exactly as it would be during one of its own page requests
    $GLOBALS['MM_COMPANY'] = $company;
    $GLOBALS['MM_PACKAGE'] = platform_package_for_company((int) $company['id']);
    $GLOBALS['MM_FEATURES'] = $GLOBALS['MM_PACKAGE']['features'] ?? [];
    $conn = tenant_try_connect($company);   // an unreachable company is reported and skipped, it never stops the others
    $GLOBALS['conn'] = $conn;
    company_profile_reset();
    $out = [];
    try {
        require_once __DIR__ . '/sms_messages.php';
        require_once __DIR__ . '/credit_charge_helpers.php';
        $profile = company_profile();
        $quota = mm_sms_quota();
        $used = sms_month_usage($conn);

        if ($profile['auto_credit_charges']) {
            if ($dryRun) {
                $out[] = 'overdue charges: skipped in preview';
            } else {
                $c = credit_accrue_all($conn);
                $out[] = 'overdue charges: ' . $c['invoices'] . ' invoice(s)';
            }
        }
        if ($profile['sms_credit_reminders']) {
            $n = sms_send_credit_reminders($conn, $dryRun);
            $out[] = ($dryRun ? 'would send ' : 'sent ') . $n . ' payment reminder(s)';
        } else {
            $out[] = 'payment reminders: switched off';
        }
        if ($profile['sms_loyalty_weekly'] && !$dryRun) {
            $out[] = 'loyalty messages: ' . sms_send_loyalty_weekly($conn);
        }
        $out[] = 'SMS this month: ' . $used . ($quota === null ? ' (no limit)' : ' of ' . $quota);
    } catch (Throwable $e) {
        $out[] = 'FAILED: ' . $e->getMessage();
        error_log('[MY MOTIVE daily] ' . $label . ': ' . $e->getMessage());
    }
    mysqli_close($conn);
    unset($GLOBALS['MM_COMPANY'], $GLOBALS['MM_PACKAGE'], $GLOBALS['MM_FEATURES'], $GLOBALS['conn']);
    company_profile_reset();
    return $label . ': ' . implode('; ', $out);
}

/**
 * Runs the whole daily job.
 *
 * @return string[] the report, one line per step
 */
function daily_run(bool $dryRun = false, ?string $onlyCode = null): array
{
    $log = [];
    if ($onlyCode === null) {
        foreach (subscription_daily($dryRun) as $line) {
            $log[] = 'subscription: ' . $line;
        }
    }
    $db = platform_conn();
    $rows = [];
    $res = mysqli_query($db, "SELECT * FROM companies WHERE status = 'active' ORDER BY id");
    while ($r = mysqli_fetch_assoc($res)) {
        if ($onlyCode === null || $r['company_code'] === platform_normalize_code($onlyCode)) {
            $rows[] = $r;
        }
    }
    foreach ($rows as $company) {
        try {
            $log[] = daily_company($company, $dryRun);
        } catch (Throwable $e) {
            $log[] = $company['company_code'] . ': FAILED: ' . $e->getMessage();
        }
    }
    return $log;
}

/**
 * For the worker: runs the daily job once per day, after the daily hour. Safe to call every minute from several
 * processes: only the one that wins the claim runs it.
 *
 * @return string[]|null the report, or null if it was not time (or another process took it)
 */
function daily_maybe_run(): ?array
{
    $hour = (int) platform_setting('daily_hour', DAILY_DEFAULT_HOUR);
    if ((int) date('G') < $hour) {
        return null;
    }
    $today = (int) date('Ymd');
    $st = mysqli_prepare(platform_conn(), "UPDATE platform_counters SET value = ? WHERE name = 'daily_last_run' AND value < ?");
    mysqli_stmt_bind_param($st, 'ii', $today, $today);
    mysqli_stmt_execute($st);
    if (mysqli_stmt_affected_rows($st) !== 1) {
        return null;
    }
    return daily_run(false);
}
