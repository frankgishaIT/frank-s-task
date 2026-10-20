<?php
/**
 * Subscriptions after the first payment: reminders, expiry, and renewals.
 *
 *   7, 3 and 1 day before the end, and on the last day: an email to the owner (each sent once)
 *   a few days after the end (the grace period): the business is paused, with a clear reason. Its data is never deleted.
 *   a recorded renewal: the end date moves forward, the business is active again, and the owner gets a receipt.
 * Business 1 (your own business) is never paused.
 */

require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/signup.php';     // prices, add-months, payment methods, emails

const SUB_GRACE_DAYS = 3;

/** The newest subscription of a company with its package, or null. */
function subscription_latest(int $companyId): ?array
{
    $st = mysqli_prepare(platform_conn(), 'SELECT s.*, p.name AS package_name, p.monthly_price, p.yearly_discount_percent FROM subscriptions s JOIN packages p ON p.id = s.package_id WHERE s.company_id = ? ORDER BY s.id DESC LIMIT 1');
    mysqli_stmt_bind_param($st, 'i', $companyId);
    mysqli_stmt_execute($st);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: null;
}

/** What one renewal costs for this subscription's package and billing period. */
function subscription_renewal_amount(array $sub): int
{
    return signup_price(['monthly_price' => $sub['monthly_price'], 'yearly_discount_percent' => $sub['yearly_discount_percent']], $sub['billing_period']);
}

/**
 * Records a renewal payment received outside the website (MoMo, bank, cash) and extends the subscription.
 * The new end date counts from the old end date (or today, if it already ended), so early renewals lose nothing.
 *
 * @return string the new end date (Y-m-d)
 */
function company_record_renewal(int $companyId, string $method, string $reference, int $amount, string $adminEmail): string
{
    if (!in_array($method, SIGNUP_PAYMENT_METHODS, true)) {
        throw new SignupException('Choose how the business paid.');
    }
    $reference = trim($reference);
    if ($reference === '' || mb_strlen($reference) > 100) {
        throw new SignupException('Enter the payment reference.');
    }
    $company = platform_company_by_id($companyId);
    $sub = $company ? subscription_latest($companyId) : null;
    if (!$company || !$sub) {
        throw new SignupException('This business has no subscription to renew.');
    }
    $due = subscription_renewal_amount($sub);
    if ($amount < $due) {
        throw new SignupException('The amount is less than the ' . signup_money($due) . ' due for one renewal.');
    }
    $today = new DateTimeImmutable('today');
    $from = new DateTimeImmutable($sub['ends_at']);
    $base = $from > $today ? $from : $today;
    $end = signup_add_months($base, $sub['billing_period'] === 'yearly' ? 12 : 1)->format('Y-m-d');

    $db = platform_conn();
    mysqli_begin_transaction($db);
    try {
        $st = mysqli_prepare($db, "UPDATE subscriptions SET ends_at = ?, status = 'active' WHERE id = ?");
        $sid = (int) $sub['id'];
        mysqli_stmt_bind_param($st, 'si', $end, $sid);
        mysqli_stmt_execute($st);
        $st = mysqli_prepare($db, 'DELETE FROM subscription_notices WHERE subscription_id = ?');
        mysqli_stmt_bind_param($st, 'i', $sid);
        mysqli_stmt_execute($st);
        $st = mysqli_prepare($db, 'INSERT INTO payments (company_id, amount, method, reference, recorded_by) VALUES (?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($st, 'iisss', $companyId, $amount, $method, $reference, $adminEmail);
        mysqli_stmt_execute($st);
        // a company paused only because the subscription ended is opened again; a company paused for another reason stays paused
        $st = mysqli_prepare($db, "UPDATE companies SET status = 'active', status_note = NULL WHERE id = ? AND status = 'suspended' AND status_note LIKE 'Subscription ended%'");
        mysqli_stmt_bind_param($st, 'i', $companyId);
        mysqli_stmt_execute($st);
        mysqli_commit($db);
    } catch (Throwable $e) {
        mysqli_rollback($db);
        throw $e;
    }
    platform_audit($companyId, 'admin:' . $adminEmail, 'subscription_renewed', "until=$end amount=$amount method=$method");
    if (!empty($company['owner_email'])) {
        [$subject, $html] = mm_mail_renewed($company, $sub['package_name'], $end, $amount);
        platform_send_email($company['owner_email'], (string) ($company['owner_name'] ?? ''), $subject, $html);
    }
    return $end;
}

/**
 * The daily subscription check. Returns one line per thing it did (or, in preview mode, would do).
 *
 * @return string[]
 */
function subscription_daily(bool $dryRun = false): array
{
    $db = platform_conn();
    $log = [];
    $res = mysqli_query($db, "SELECT s.id, s.company_id, s.ends_at, s.billing_period, DATEDIFF(s.ends_at, CURDATE()) AS days_left, p.name AS package_name, p.monthly_price, p.yearly_discount_percent
        FROM subscriptions s JOIN packages p ON p.id = s.package_id JOIN companies c ON c.id = s.company_id
        WHERE s.status = 'active' AND s.company_id <> 1 AND c.status = 'active' ORDER BY s.id");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
    $help = (string) platform_setting('payment_instructions', '');
    foreach ($rows as $r) {
        $days = (int) $r['days_left'];
        $company = platform_company_by_id((int) $r['company_id']);
        if (!$company) {
            continue;
        }
        if ($days < -SUB_GRACE_DAYS) {
            $log[] = ($dryRun ? 'would pause ' : 'paused ') . $company['company_code'] . ' (subscription ended ' . $r['ends_at'] . ')';
            if (!$dryRun) {
                $note = 'Subscription ended on ' . $r['ends_at'];
                $st = mysqli_prepare($db, "UPDATE subscriptions SET status = 'expired' WHERE id = ?");
                $sid = (int) $r['id'];
                mysqli_stmt_bind_param($st, 'i', $sid);
                mysqli_stmt_execute($st);
                $st = mysqli_prepare($db, "UPDATE companies SET status = 'suspended', status_note = ? WHERE id = ? AND status = 'active'");
                $cid = (int) $company['id'];
                mysqli_stmt_bind_param($st, 'si', $note, $cid);
                mysqli_stmt_execute($st);
                platform_audit($cid, 'daily', 'subscription_expired', $note);
                if (!empty($company['owner_email'])) {
                    [$subject, $html] = mm_mail_subscription_suspended($company, (string) $r['ends_at']);
                    platform_send_email($company['owner_email'], (string) ($company['owner_name'] ?? ''), $subject, $html);
                }
            }
            continue;
        }
        $stage = $days <= 0 ? 'due' : ($days <= 1 ? 'd1' : ($days <= 3 ? 'd3' : ($days <= 7 ? 'd7' : '')));
        if ($stage === '') {
            continue;
        }
        $st = mysqli_prepare($db, 'SELECT COUNT(*) FROM subscription_notices WHERE subscription_id = ? AND stage = ?');
        $sid = (int) $r['id'];
        mysqli_stmt_bind_param($st, 'is', $sid, $stage);
        mysqli_stmt_execute($st);
        if ((int) mysqli_fetch_row(mysqli_stmt_get_result($st))[0] > 0) {
            continue;
        }
        $log[] = ($dryRun ? 'would email ' : 'emailed ') . $company['company_code'] . ' (' . $stage . ', ' . $days . ' day(s) left)';
        if (!$dryRun) {
            $st = mysqli_prepare($db, 'INSERT IGNORE INTO subscription_notices (subscription_id, stage) VALUES (?, ?)');
            mysqli_stmt_bind_param($st, 'is', $sid, $stage);
            mysqli_stmt_execute($st);
            if (!empty($company['owner_email'])) {
                [$subject, $html] = mm_mail_subscription_notice($company, $r['package_name'], (string) $r['ends_at'], $days, subscription_renewal_amount($r), $help);
                platform_send_email($company['owner_email'], (string) ($company['owner_name'] ?? ''), $subject, $html);
            }
        }
    }
    return $log;
}
