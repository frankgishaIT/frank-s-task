<?php
/**
 * NEW FILE: includes/dl_payments.php — RM TASKER PAYMENTS, CONFIRMATION, DISPUTES, RECONCILIATION.
 *
 * Per delegation: entitlement (pay_due, calculated at verification from the actual figures) → amount APPROVED by
 * management (pay_approved; a different amount needs a reason — nothing is ever deducted automatically) → payments
 * (dl_payments, each with its own reference, date, method, proof) → the Tasker's confirmation of receipt →
 * reconciliation (only when approved = paid = confirmed and no dispute is open).
 *
 * A payment is posted ONCE as an Expense in Transactions (category "RM Tasker payments", from the chosen fund and
 * financial account) through fund_post_automatic_expense, so funds and accounts follow. It is never posted twice:
 * a reference already used, the same amount on the same day, or a payment above what remains approved is refused
 * unless an approver accepts a written explanation. If the transaction is later deleted in Transactions (with its
 * reason there), the payment shows as void.
 *
 * Payment status is SEPARATE from the delegation status and is calculated (dl_pay_status). "Awaiting Customer
 * Payment" and the other legitimate holds are never shown as overdue.
 */
require_once __DIR__ . '/dl_workflow.php';

const DL_PAY_METHODS = ['Mobile Money', 'Bank transfer', 'Cash', 'Cheque'];
const DL_NONPAY_REASONS = [
    'Customer has not yet paid RISE MOTIVE', 'Tasker has not completed the assignment', 'Submitted work has not passed quality verification', 'Customer has raised a complaint',
    'Payment is awaiting management approval', 'Payment is awaiting Finance processing', 'Payment details are incomplete or incorrect', 'Payment is under dispute', 'Other',
];
/** Reasons that are a legitimate hold (not overdue). */
const DL_HOLD_REASONS = ['Customer has not yet paid RISE MOTIVE', 'Tasker has not completed the assignment', 'Submitted work has not passed quality verification', 'Customer has raised a complaint',
    'Payment is awaiting management approval', 'Payment details are incomplete or incorrect', 'Payment is under dispute'];
const DL_DISPUTE_KINDS = ['Payment not received', 'Amount received is lower than expected', 'Duplicate or incorrect payment', 'Other payment issue'];
const DL_DISPUTE_STATUSES = ['Open', 'Under Review', 'Awaiting Tasker Information', 'Awaiting Finance Review', 'Resolved', 'Rejected with Explanation'];
const DL_DISPUTE_OPEN = ['Open', 'Under Review', 'Awaiting Tasker Information', 'Awaiting Finance Review'];

function dl_add_column(mysqli $conn, string $table, string $col, string $def): void
{
    try {
        $s = mysqli_prepare($conn, 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        mysqli_stmt_bind_param($s, 'ss', $table, $col);
        mysqli_stmt_execute($s);
        $r = mysqli_fetch_row(mysqli_stmt_get_result($s));
        if ($r && (int) $r[0] === 0) { mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$col` $def"); }
    } catch (Throwable $e) { error_log('dl_add_column ' . $col . ': ' . $e->getMessage()); }
}

function dlp_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    dlw_ensure($conn);
    foreach (['pay_approved' => 'DECIMAL(15,2) NULL', 'pay_approved_by' => 'INT(11) NULL', 'pay_approved_at' => 'DATETIME NULL', 'pay_approval_note' => 'VARCHAR(255) NULL',
        'pay_due_date' => 'DATE NULL', 'nonpay_reason' => 'VARCHAR(60) NULL', 'nonpay_note' => 'VARCHAR(255) NULL', 'pay_overdue_alert_at' => 'DATETIME NULL', 'reconciled_by' => 'INT(11) NULL'] as $c => $def) {
        dl_add_column($conn, 'dl_delegations', $c, $def);
    }
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS dl_payments (
        id INT(11) NOT NULL AUTO_INCREMENT, delegation_id INT(11) NOT NULL, party_id INT(11) NOT NULL, amount DECIMAL(15,2) NOT NULL, paid_on DATE NOT NULL, method VARCHAR(20) NOT NULL,
        reference VARCHAR(80) NOT NULL, proof_ref VARCHAR(40) NULL, fund_id INT(11) NULL, account_id INT(11) NULL, transaction_id INT(11) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Recorded', explanation VARCHAR(500) NULL, approved_by INT(11) NULL, approved_at DATETIME NULL, void_reason VARCHAR(255) NULL,
        confirm_status VARCHAR(30) NULL, confirm_requested_at DATETIME NULL, confirm_due DATETIME NULL, confirm_reminder_at DATETIME NULL, confirm_expired_at DATETIME NULL,
        confirmed_at DATETIME NULL, confirmed_by_staff INT(11) NULL, confirm_note VARCHAR(500) NULL,
        recorded_by INT(11) NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY d (delegation_id), KEY party (party_id), KEY ref (reference)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS dl_disputes (
        id INT(11) NOT NULL AUTO_INCREMENT, delegation_id INT(11) NOT NULL, payment_id INT(11) NULL, party_id INT(11) NOT NULL, kind VARCHAR(50) NOT NULL,
        payment_reference VARCHAR(80) NULL, expected_amount DECIMAL(15,2) NULL, amount_received DECIMAL(15,2) NULL, disputed_amount DECIMAL(15,2) NULL, description TEXT NOT NULL,
        evidence TEXT NULL, reported_by VARCHAR(10) NOT NULL DEFAULT 'tasker', reported_user INT(11) NULL, assigned_to INT(11) NULL, status VARCHAR(30) NOT NULL DEFAULT 'Open',
        resolution TEXT NULL, resolved_by INT(11) NULL, resolved_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL,
        PRIMARY KEY (id), KEY d (delegation_id), KEY party (party_id, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS dl_dispute_notes (
        id INT(11) NOT NULL AUTO_INCREMENT, dispute_id INT(11) NOT NULL, actor VARCHAR(10) NOT NULL, user_id INT(11) NULL, note TEXT NOT NULL, visible_to_tasker TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY d (dispute_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function dl_pay_ref(int $id): string { return 'TP' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }
function dl_dispute_ref(int $id): string { return 'PD' . str_pad((string) $id, 5, '0', STR_PAD_LEFT); }

/** Payments of a delegation (posted ones whose transaction was deleted in Transactions become Void, once). */
function dl_payments(mysqli $conn, int $did): array
{
    $rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT p.*, t.id tx_exists FROM dl_payments p LEFT JOIN transactions t ON t.id = p.transaction_id WHERE p.delegation_id = ' . $did . ' ORDER BY p.paid_on, p.id'), MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        if ($r['status'] === 'Recorded' && $r['transaction_id'] && !$r['tx_exists']) {
            $why = 'Transaction #' . (int) $r['transaction_id'] . ' was deleted in Transactions';
            mysqli_query($conn, "UPDATE dl_payments SET status = 'Void', void_reason = '" . mysqli_real_escape_string($conn, $why) . "' WHERE id = " . (int) $r['id']);
            dl_event($conn, $did, 'payment_void', dl_pay_ref((int) $r['id']) . ' (' . dl_money($r['amount']) . ') is void: ' . $why, 'system', 0, null, 1);
            $r['status'] = 'Void'; $r['void_reason'] = $why;
        }
    }
    unset($r);
    return $rows;
}
function dl_paid_total(array $pays): float { $t = 0; foreach ($pays as $p) { if ($p['status'] === 'Recorded') { $t += (float) $p['amount']; } } return round($t, 2); }

function dl_open_disputes(mysqli $conn, int $did): array
{
    return mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM dl_disputes WHERE delegation_id = $did AND status IN ('" . implode("','", DL_DISPUTE_OPEN) . "')"), MYSQLI_ASSOC);
}

/**
 * The payment position of a delegation: ['status', 'entitled', 'approved', 'paid', 'outstanding', 'overpaid', 'due_date', 'overdue', 'hold', 'last', 'confirmed_all', 'label'].
 */
function dl_pay_position(mysqli $conn, array $d, ?array $pays = null, ?array $disputes = null): array
{
    $pays = $pays ?? dl_payments($conn, (int) $d['id']);
    $disputes = $disputes ?? dl_open_disputes($conn, (int) $d['id']);
    $paid = dl_paid_total($pays);
    $entitled = $d['pay_due'] !== null ? (float) $d['pay_due'] : (float) $d['pay_exp'];
    $approved = $d['pay_approved'] !== null ? (float) $d['pay_approved'] : null;
    $base = $approved ?? $entitled;
    $out = round(max(0, $base - $paid), 2);
    $over = round(max(0, $paid - $base), 2);
    $rec = array_values(array_filter($pays, function ($p) { return $p['status'] === 'Recorded'; }));
    $last = $rec ? end($rec) : null;
    $confAll = $rec && !array_filter($rec, function ($p) { return $p['confirm_status'] !== 'Confirmed'; });
    $issue = (bool) array_filter($rec, function ($p) { return in_array($p['confirm_status'], ['Reported issue', 'Expired – follow up'], true); });
    $pending = (bool) array_filter($pays, function ($p) { return $p['status'] === 'Pending approval'; });
    $sale = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT status, total_amount, amount_paid FROM sales WHERE id = ' . (int) $d['sale_id']));
    $custUnpaid = $sale && ($sale['status'] === 'Credit' || ($sale['status'] === 'Partially Paid') || (float) $sale['amount_paid'] + 0.5 < (float) $sale['total_amount']) && $sale['status'] !== 'Paid';
    $hold = $d['nonpay_reason'] && in_array($d['nonpay_reason'], DL_HOLD_REASONS, true);
    $dueDate = $d['pay_due_date'] ?: null;
    $today = date('Y-m-d');
    $overdue = false;
    if (in_array($d['status'], DL_ENDED, true) && $paid <= 0) { $s = 'Not applicable'; }
    elseif ($disputes) { $s = 'Payment Disputed'; }
    elseif ($over > 0.5) { $s = 'Overpaid'; }
    elseif ($paid > 0 && $out <= 0.5) { $s = $confAll ? 'Confirmed' : ($issue ? 'Reconciliation Required' : 'Fully Paid – Awaiting Tasker Confirmation'); }
    elseif ($paid > 0) { $s = 'Partially Paid'; }
    elseif (!$d['completed_at']) { $s = 'Awaiting Service Completion'; }
    elseif (!$d['verified_at']) { $s = 'Awaiting Quality Verification'; }
    elseif ($approved === null || $pending) { $s = 'Pending Payment Approval'; }
    elseif ($custUnpaid && (!$d['nonpay_reason'] || $d['nonpay_reason'] === 'Customer has not yet paid RISE MOTIVE')) { $s = 'Awaiting Customer Payment'; }
    elseif ($dueDate && $today < $dueDate) { $s = 'Not Yet Due'; }
    else { $s = 'Unpaid'; }
    if (in_array($s, ['Unpaid', 'Partially Paid'], true) && $dueDate && $today > $dueDate && !$hold && !$custUnpaid) { $overdue = true; }
    $label = $s === 'Partially Paid' ? 'PARTIALLY PAID – ' . dl_money($out) . ' OUTSTANDING' : ($s === 'Overpaid' ? 'OVERPAID BY ' . dl_money($over) . ' – INVESTIGATE' : strtoupper($s));
    return ['status' => $s, 'entitled' => $entitled, 'approved' => $approved, 'paid' => $paid, 'outstanding' => $out, 'overpaid' => $over, 'due_date' => $dueDate, 'overdue' => $overdue,
        'hold' => $hold, 'cust_unpaid' => $custUnpaid, 'last' => $last, 'confirmed_all' => $confAll, 'label' => $label, 'pays' => $pays, 'disputes' => $disputes,
        'needs_reason' => in_array($s, ['Unpaid', 'Partially Paid'], true) && !$d['nonpay_reason'] && $dueDate && $today > $dueDate];
}

function dl_pay_badge(array $pos): string
{
    $c = ['Confirmed' => 'dl-ok', 'Fully Paid – Awaiting Tasker Confirmation' => 'dl-blue', 'Partially Paid' => 'dl-warn', 'Unpaid' => 'dl-bad', 'Payment Disputed' => 'dl-bad', 'Overpaid' => 'dl-bad',
        'Reconciliation Required' => 'dl-bad', 'Pending Payment Approval' => 'dl-gold', 'Awaiting Customer Payment' => '', 'Not Yet Due' => '', 'Awaiting Service Completion' => '', 'Awaiting Quality Verification' => ''][$pos['status']] ?? '';
    return '<span class="dl-st ' . $c . '">' . dl_e($pos['status'] === 'Partially Paid' ? 'Partially paid · ' . dl_money($pos['outstanding']) . ' outstanding' : $pos['status']) . '</span>' . ($pos['overdue'] ? ' <span class="dl-st dl-bad">OVERDUE</span>' : '');
}

/** Keeps the delegation status in line with its payments (Awaiting Payment → Partially Paid → Paid). */
function dl_sync_after_payment(mysqli $conn, int $did): void
{
    $d = dl_get($conn, $did);
    if (!$d || !in_array($d['status'], ['Verified', 'Awaiting Payment', 'Partially Paid', 'Paid', 'Issue Review'], true)) { return; }
    $pos = dl_pay_position($conn, $d);
    $to = $pos['paid'] <= 0 ? ($d['pay_approved'] !== null ? 'Awaiting Payment' : 'Verified') : ($pos['outstanding'] > 0.5 ? 'Partially Paid' : 'Paid');
    if ($d['status'] === 'Issue Review') { return; }
    if ($to !== $d['status']) { dl_set_status($conn, $did, $to); }
}

/** Users holding a delegations permission (for alerts). */
function dl_users_with(mysqli $conn, string $perm): array
{
    if (!function_exists('access_user_breakdown') && is_file(__DIR__ . '/access_control.php')) { require_once __DIR__ . '/access_control.php'; }
    $out = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM users WHERE is_active = 1 AND role IN ('Admin', 'Manager', 'Employee')"), MYSQLI_NUM) as [$u]) {
        try { if (function_exists('access_user_breakdown') && in_array($perm, access_user_breakdown($conn, (int) $u)['effective'], true)) { $out[] = (int) $u; } } catch (Throwable $e) { }
    }
    if (!$out) { $out = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM users WHERE is_active = 1 AND role = 'Admin'"), MYSQLI_NUM), 0)); }
    return $out;
}

/** SMS + e-mail to the Tasker after a payment is recorded. */
function dl_payment_notice(mysqli $conn, array $d, array $p): bool
{
    $t = $d['tasker'] ?? dl_tasker($conn, (int) $d['party_id']);
    if (!$t) { return false; }
    $link = 'https://' . (defined('SMS_PORTAL_URL_C') ? SMS_PORTAL_URL_C : 'myspace.risemotive.rw/portal') . '/tasker_payments.php';
    $vars = ['tasker_name' => trim(explode(' ', (string) $t['name'])[0]), 'reference' => $d['ref'], 'amount' => number_format((float) $p['amount']), 'date' => date('d M Y', strtotime($p['paid_on'])),
        'payment_ref' => $p['reference'], 'link' => $link];
    $ok = false;
    try { require_once __DIR__ . '/sms_center.php'; $ok = sms_notify($conn, 'DL_PAYMENT', $t['phone'], $vars, ['to' => 'tasker', 'ref_type' => 'dl_payment', 'ref_id' => (int) $p['id'], 'user' => dl_uid() ?: null]); } catch (Throwable $e) { error_log('dl pay sms: ' . $e->getMessage()); }
    if (!empty($t['email']) && is_file(__DIR__ . '/mailer.php')) {
        try {
            require_once __DIR__ . '/mailer.php';
            send_email($t['email'], $t['display'], 'Payment recorded for ' . $d['ref'], '<p>Dear ' . dl_e($vars['tasker_name']) . ',</p><p>RISE MOTIVE has recorded a payment for Delegation <b>' . dl_e($d['ref']) . '</b>.</p><p>Amount: <b>RWF ' . dl_e($vars['amount'])
                . '</b><br>Payment date: ' . dl_e($vars['date']) . '<br>Payment reference: ' . dl_e($p['reference']) . '</p><p>Please confirm whether you have received this amount or report a payment issue: <a href="' . dl_e($link) . '">' . dl_e($link) . '</a></p><p>RISE MOTIVE</p>');
        } catch (Throwable $e) { error_log('dl pay mail: ' . $e->getMessage()); }
    }
    return $ok;
}

/**
 * Posts a payment (inside a DB transaction): Expense in Transactions from the fund, linked to the financial account.
 * Returns the transaction id.
 */
function dl_post_payment(mysqli $conn, array $d, array $p): int
{
    require_once __DIR__ . '/fund_helpers.php';
    $fund = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, code, name FROM funds WHERE id = ' . (int) $p['fund_id']));
    if (!$fund) { throw new RuntimeException('Choose the fund the payment comes from.'); }
    $desc = mb_substr('RM Tasker payment ' . $p['reference'] . ' · ' . $d['ref'] . ' · ' . ($d['tasker']['display'] ?? '') . ' · ' . $d['product_name'], 0, 255);
    $tx = fund_post_automatic_expense($conn, $fund['code'], 'Service', (float) $p['amount'], $p['paid_on'], $desc, dl_uid() ?: null, 'RM Tasker payments', 'DL_PAYMENT', (int) $p['id']);
    $ref = mb_substr((string) $p['reference'], 0, 80);
    $acc = $p['account_id'] ? (int) $p['account_id'] : null;
    try {
        $s = mysqli_prepare($conn, 'UPDATE transactions SET account_id = ?, reference = ? WHERE id = ?');
        mysqli_stmt_bind_param($s, 'isi', $acc, $ref, $tx);
        mysqli_stmt_execute($s);
    } catch (Throwable $e) { /* financial accounts not installed */ }
    mysqli_query($conn, 'UPDATE dl_payments SET transaction_id = ' . $tx . ' WHERE id = ' . (int) $p['id']);
    return $tx;
}

/** After posting: confirmation request (window from settings), notice to the Tasker, status sync. */
function dl_after_post(mysqli $conn, int $did, int $payId): void
{
    $now = dl_now();
    $due = date('Y-m-d H:i:s', time() + max(1, (int) dl_setting($conn, 'confirm_days')) * 86400);
    mysqli_query($conn, "UPDATE dl_payments SET confirm_status = 'Awaiting confirmation', confirm_requested_at = '$now', confirm_due = '$due' WHERE id = $payId");
    try { if (is_file(__DIR__ . '/fin_accounts.php')) { require_once __DIR__ . '/fin_accounts.php'; if (function_exists('fa_sync')) { fa_sync($conn); } } } catch (Throwable $e) { error_log('dl fa_sync: ' . $e->getMessage()); }
    $d = dl_get($conn, $did);
    $p = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_payments WHERE id = ' . $payId));
    $sent = dl_payment_notice($conn, $d, $p);
    dl_event($conn, $did, 'payment_recorded', dl_pay_ref($payId) . ': ' . dl_money($p['amount']) . ' paid on ' . date('d M Y', strtotime($p['paid_on'])) . ' by ' . $p['method'] . ', reference ' . $p['reference']
        . ' · transaction #' . (int) $p['transaction_id'] . ' · confirmation requested until ' . dl_deadline_text($due) . ' (SMS ' . ($sent ? 'sent' : 'not sent') . ')', 'staff', 0, $p['explanation'], 1);
    dl_sync_after_payment($conn, $did);
}

/** Confirmation reminders, expired windows (management told; never taken as received), overdue payments. */
function dl_pay_monitor(mysqli $conn): array
{
    dlp_ensure($conn);
    $now = dl_now();
    $n = ['confirm_reminders' => 0, 'confirm_expired' => 0, 'pay_overdue' => 0];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM dl_payments WHERE status = 'Recorded' AND confirm_status = 'Awaiting confirmation' AND confirm_reminder_at IS NULL AND confirm_due > '$now'"), MYSQLI_ASSOC) as $p) {
        $span = strtotime($p['confirm_due']) - strtotime($p['confirm_requested_at']);
        if (time() < strtotime($p['confirm_due']) - min(86400, $span / 3)) { continue; }
        mysqli_query($conn, "UPDATE dl_payments SET confirm_reminder_at = '$now' WHERE id = " . (int) $p['id']);
        $d = dl_get($conn, (int) $p['delegation_id']);
        dl_notify_tasker($conn, $d, 'update', 'Please confirm the payment ' . $p['reference'] . ' of RWF ' . number_format((float) $p['amount']) . ' (or report a problem) before ' . dl_deadline_text($p['confirm_due']) . '.');
        $n['confirm_reminders']++;
    }
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM dl_payments WHERE status = 'Recorded' AND confirm_status = 'Awaiting confirmation' AND confirm_due <= '$now'"), MYSQLI_ASSOC) as $p) {
        mysqli_query($conn, "UPDATE dl_payments SET confirm_status = 'Expired – follow up', confirm_expired_at = '$now' WHERE id = " . (int) $p['id']);
        $d = dl_get($conn, (int) $p['delegation_id']);
        dl_notify_staff($conn, array_merge(dl_users_with($conn, 'delegations.finance'), dl_approvers($conn)), 'Tasker has not confirmed payment ' . $p['reference'], ($d['tasker']['display'] ?? '') . ' did not confirm ' . dl_money($p['amount']) . ' for ' . $d['ref'] . ' within the confirmation period. This does NOT mean it was received: follow up with the Tasker.');
        dl_event($conn, (int) $p['delegation_id'], 'confirm_expired', 'Confirmation period for ' . $p['reference'] . ' ended without an answer: follow-up needed (not taken as received)', 'system');
        $n['confirm_expired']++;
    }
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM dl_delegations WHERE pay_approved IS NOT NULL AND pay_due_date IS NOT NULL AND pay_due_date < '" . date('Y-m-d') . "' AND pay_overdue_alert_at IS NULL AND status IN ('Verified', 'Awaiting Payment', 'Partially Paid')"), MYSQLI_NUM) as [$id]) {
        $d = dl_get($conn, (int) $id);
        $pos = dl_pay_position($conn, $d);
        if (!$pos['overdue']) { continue; }
        mysqli_query($conn, "UPDATE dl_delegations SET pay_overdue_alert_at = '$now' WHERE id = " . (int) $id);
        dl_notify_staff($conn, dl_users_with($conn, 'delegations.finance'), 'Tasker payment overdue: ' . $d['ref'], ($d['tasker']['display'] ?? '') . ' is owed ' . dl_money($pos['outstanding']) . ' since ' . date('d M Y', strtotime($pos['due_date'])) . '. Pay it or record why it is held.');
        $n['pay_overdue']++;
    }
    return $n;
}

/** A Tasker's financial summary (lifetime). */
function dl_tasker_money(mysqli $conn, int $pid): array
{
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT d.*, p.product_name FROM dl_delegations d LEFT JOIN products p ON p.id = d.product_id WHERE d.party_id = $pid AND d.verified_at IS NOT NULL ORDER BY d.verified_at DESC"), MYSQLI_ASSOC);
    $sum = ['earned' => 0, 'paid' => 0, 'outstanding' => 0, 'overpaid' => 0, 'unpaid' => 0, 'partial' => 0, 'disputes' => 0, 'days' => [], 'rows' => [], 'months' => []];
    foreach ($rows as $d) {
        $d['ref'] = dl_ref((int) $d['id']);
        $pos = dl_pay_position($conn, $d);
        $base = $pos['approved'] ?? $pos['entitled'];
        $sum['earned'] += $base; $sum['paid'] += $pos['paid']; $sum['outstanding'] += $pos['outstanding']; $sum['overpaid'] += $pos['overpaid'];
        if ($pos['paid'] <= 0 && $pos['outstanding'] > 0) { $sum['unpaid']++; }
        if ($pos['status'] === 'Partially Paid') { $sum['partial']++; }
        if ($pos['paid'] > 0 && $pos['outstanding'] <= 0.5 && $pos['last']) { $sum['days'][] = max(0, (strtotime($pos['last']['paid_on']) - strtotime(substr($d['verified_at'], 0, 10))) / 86400); }
        foreach ($pos['pays'] as $p) { if ($p['status'] === 'Recorded') { $m = substr($p['paid_on'], 0, 7); $sum['months'][$m] = ($sum['months'][$m] ?? 0) + (float) $p['amount']; } }
        $sum['rows'][] = ['d' => $d, 'pos' => $pos];
    }
    $sum['disputes'] = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM dl_disputes WHERE party_id = $pid"))[0];
    $sum['avg_days'] = $sum['days'] ? round(array_sum($sum['days']) / count($sum['days']), 1) : null;
    krsort($sum['months']);
    return $sum;
}

/** Creates a payment dispute (by the Tasker in the portal, or by staff for the Tasker). */
function dl_create_dispute(mysqli $conn, array $d, ?array $pay, string $kind, ?float $received, string $desc, string $by, array $evidence = []): int
{
    $pos = dl_pay_position($conn, $d);
    $expected = $pay ? (float) $pay['amount'] : (float) ($pos['approved'] ?? $pos['entitled']);
    $disputed = $received !== null ? round(max(0, $expected - $received), 2) : $expected;
    if ($kind === 'Duplicate or incorrect payment') { $disputed = $received !== null ? abs($received - $expected) : null; }
    $now = dl_now(); $u = $by === 'staff' ? dl_uid() : null; $pid = (int) $d['party_id']; $payId = $pay ? (int) $pay['id'] : null; $pref = $pay['reference'] ?? null;
    $ev = $evidence ? json_encode($evidence) : null; $did = (int) $d['id'];
    $s = mysqli_prepare($conn, 'INSERT INTO dl_disputes (delegation_id, payment_id, party_id, kind, payment_reference, expected_amount, amount_received, disputed_amount, description, evidence, reported_by, reported_user, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'iiissdddsssiss', $did, $payId, $pid, $kind, $pref, $expected, $received, $disputed, $desc, $ev, $by, $u, $now, $now);
    mysqli_stmt_execute($s);
    $id = (int) mysqli_insert_id($conn);
    if ($payId) { mysqli_query($conn, "UPDATE dl_payments SET confirm_status = 'Reported issue' WHERE id = $payId"); }
    dl_event($conn, $did, 'dispute_opened', 'Payment dispute ' . dl_dispute_ref($id) . ' (' . $kind . ')' . ($pref ? ' on ' . $pref : '') . ': ' . mb_substr($desc, 0, 300), $by === 'staff' ? 'staff' : 'tasker', $by === 'staff' ? 1 : 0, null, 1, $pid);
    dl_audit($conn, 'dispute', $id, 'open', dl_dispute_ref($id) . ' · ' . $d['ref'] . ' · ' . $kind . ($received !== null ? ' · received ' . dl_money($received) . ' of ' . dl_money($expected) : ''), null, $by === 'staff' ? 'staff' : 'tasker', $pid);
    dl_notify_staff($conn, array_merge(dl_users_with($conn, 'delegations.finance'), dl_approvers($conn)), 'Payment dispute ' . dl_dispute_ref($id) . ' from ' . ($d['tasker']['display'] ?? 'an RM Tasker'), $kind . ' on ' . $d['ref'] . ': ' . mb_substr($desc, 0, 300));
    return $id;
}