<?php
/**
 * NEW FILE: includes/cl_helpers.php
 * RM CUSTOMER LOANS — engine shared by the Customer Portal (customer side) and RM Loan Management (staff side).
 *
 *   Eligibility  verified points (from FULLY PAID, non-cancelled sales) x point value = verified profit;
 *                unsecured limit = profit x unsecured % - what the customer already owes (open RM loans + unpaid invoices).
 *   Calculator   flat monthly interest (default, 7%) or reducing balance; monthly or weekly instalments; optional fee.
 *   Testing mode ON by default: everything works EXCEPT disbursement (no real lending without regulatory clearance).
 *   Every status change and staff action is written to cl_audit (who, what, when, reason).
 */
require_once __DIR__ . '/rm_org.php';

const CL_DEFAULTS = [
    'point_value' => '50', 'unsecured_pct' => '50', 'monthly_rate' => '7', 'interest_method' => 'flat',
    'min_amount' => '10000', 'max_amount' => '5000000', 'periods' => '1,2,3,6,12', 'frequencies' => 'Monthly,Weekly',
    'fee_pct' => '0', 'late_fee_pct' => '0', 'testing_mode' => '1', 'enabled' => '1',
    'terms' => "1. Every application is reviewed by RISE MOTIVE; eligibility is not approval.\n2. Interest is charged at the rate shown in your offer.\n3. Late payment may lead to late fees, overdue status and recovery action as stated in your agreement.\n4. RISE MOTIVE (or its licensed financial partner) may verify the information you provide.",
    'privacy' => 'Your personal and financial information is used only to assess and manage your loan application, is kept confidential, and is shared only with an authorised financial partner where required by law.',
];
const CL_STATUSES = ['Draft', 'Submitted', 'Under Review', 'Additional Information Required', 'Approved', 'Rejected', 'Awaiting Customer Acceptance',
    'Agreement Signed', 'Disbursed', 'Active', 'Fully Repaid', 'Overdue', 'Defaulted', 'Cancelled'];
const CL_PURPOSES = ['Business expansion', 'Stock purchase', 'Education', 'Medical expenses', 'Household needs', 'Emergency expenses', 'Other'];
const CL_DISBURSE = ['Mobile Money', 'Bank transfer', 'Cash at RISE MOTIVE office'];
const CL_COLLATERAL = ['Land', 'Building', 'Vehicle', 'Equipment', 'Other acceptable asset'];
const CL_DOC_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'];

function cl_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $q = function ($s) use ($conn) { mysqli_query($conn, $s); };
    $q('CREATE TABLE IF NOT EXISTS cl_settings (name VARCHAR(40) NOT NULL, value TEXT NULL, PRIMARY KEY (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q("CREATE TABLE IF NOT EXISTS cl_applications (id INT(11) NOT NULL AUTO_INCREMENT, customer_id INT(11) NOT NULL, status VARCHAR(40) NOT NULL DEFAULT 'Draft',
        requested_amount DECIMAL(14,2) NULL, approved_amount DECIMAL(14,2) NULL, purpose VARCHAR(40) NULL, purpose_other VARCHAR(120) NULL, intended_use TEXT NULL,
        period_months INT(11) NULL, frequency VARCHAR(10) NULL, proposed_date DATE NULL, disbursement_method VARCHAR(40) NULL,
        personal TEXT NULL, financial TEXT NULL, has_collateral VARCHAR(3) NULL, collateral TEXT NULL,
        points_at_submit INT(11) NULL, limit_at_submit DECIMAL(14,2) NULL, monthly_rate DECIMAL(6,3) NULL, interest_method VARCHAR(10) NULL, fee_pct DECIMAL(6,3) NULL,
        declared_at DATETIME NULL, submitted_at DATETIME NULL, offered_at DATETIME NULL, accepted_at DATETIME NULL, accepted_name VARCHAR(120) NULL,
        disbursed_at DATETIME NULL, disbursement_ref VARCHAR(80) NULL, closed_at DATETIME NULL, info_request TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL, PRIMARY KEY (id), KEY customer_id (customer_id), KEY status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS cl_documents (id INT(11) NOT NULL AUTO_INCREMENT, application_id INT(11) NOT NULL, kind VARCHAR(30) NOT NULL,
        file_ref VARCHAR(30) NOT NULL, original_name VARCHAR(255) NOT NULL, by_customer TINYINT(1) NOT NULL DEFAULT 1, user_id INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY application_id (application_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS cl_schedule (id INT(11) NOT NULL AUTO_INCREMENT, application_id INT(11) NOT NULL, no INT(11) NOT NULL, due_date DATE NOT NULL,
        principal DECIMAL(14,2) NOT NULL, interest DECIMAL(14,2) NOT NULL, fee DECIMAL(14,2) NOT NULL DEFAULT 0, amount_due DECIMAL(14,2) NOT NULL,
        paid DECIMAL(14,2) NOT NULL DEFAULT 0, status VARCHAR(10) NOT NULL DEFAULT 'Due', PRIMARY KEY (id), UNIQUE KEY one (application_id, no)) ENGINE=InnoDB");
    $q("CREATE TABLE IF NOT EXISTS cl_payments (id INT(11) NOT NULL AUTO_INCREMENT, application_id INT(11) NOT NULL, amount DECIMAL(14,2) NOT NULL, paid_on DATE NOT NULL,
        method VARCHAR(40) NULL, reference VARCHAR(80) NULL, status VARCHAR(25) NOT NULL DEFAULT 'Pending verification', by_customer TINYINT(1) NOT NULL DEFAULT 1,
        verified_by INT(11) NULL, verified_at DATETIME NULL, note VARCHAR(255) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY one_ref (application_id, reference), KEY application_id (application_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS cl_audit (id INT(11) NOT NULL AUTO_INCREMENT, application_id INT(11) NULL, user_id INT(11) NULL, customer_id INT(11) NULL,
        action VARCHAR(60) NOT NULL, from_status VARCHAR(40) NULL, to_status VARCHAR(40) NULL, reason TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY application_id (application_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Customer-facing notifications (shown in the portal; SMS where enabled). Shared with RM Invest.
    $q("CREATE TABLE IF NOT EXISTS portal_notifications (id INT(11) NOT NULL AUTO_INCREMENT, customer_id INT(11) NOT NULL, title VARCHAR(160) NOT NULL,
        body VARCHAR(500) NULL, link VARCHAR(200) NULL, read_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY customer_id (customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Profile fields the applications need (kept on the customer, so nobody types them twice).
    foreach (['national_id' => 'VARCHAR(30)', 'date_of_birth' => 'DATE', 'gender' => 'VARCHAR(10)', 'cell' => 'VARCHAR(60)',
              'marital_status' => 'VARCHAR(20)', 'nationality' => 'VARCHAR(60)'] as $c => $t) {
        $s = mysqli_prepare($conn, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = ?");
        mysqli_stmt_bind_param($s, 's', $c); mysqli_stmt_execute($s);
        if ((int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0] === 0) { $q("ALTER TABLE customers ADD COLUMN $c $t NULL"); }
    }
}

function cl_setting(mysqli $conn, string $name): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT name, value FROM cl_settings'), MYSQLI_ASSOC) as $r) { $cache[$r['name']] = (string) $r['value']; }
    }
    return $cache[$name] ?? (CL_DEFAULTS[$name] ?? '');
}

function cl_testing(mysqli $conn): bool { return cl_setting($conn, 'testing_mode') === '1'; }
function cl_ref(int $id, string $createdAt): string { return 'CL-' . date('Y', strtotime($createdAt ?: 'now')) . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT); }
function cl_money($v): string { return 'RWF ' . number_format((float) $v, 0); }

/* ------------------------------------------------------------------ eligibility */

/** Customer's verified points, profit, what they owe, and the unsecured limit available now. */
function cl_eligibility(mysqli $conn, int $customerId): array
{
    $hasCol = function ($t, $c) use ($conn) {
        $s = mysqli_prepare($conn, 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        mysqli_stmt_bind_param($s, 'ss', $t, $c); mysqli_stmt_execute($s);
        return (int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0] > 0;
    };
    // Verified points: earned on sales that are FULLY PAID and not cancelled (unpaid / cancelled / reversed sales do not count).
    if ($hasCol('sales', 'loyalty_points_earned')) {
        $points = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(loyalty_points_earned), 0) FROM sales WHERE customer_id = $customerId AND status = 'Paid'"))[0];
    } else {
        $points = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT loyalty_points FROM customers WHERE id = $customerId"))[0] ?? 0);
    }
    $current = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT loyalty_points FROM customers WHERE id = $customerId"))[0] ?? 0);
    $profit = $points * (float) cl_setting($conn, 'point_value');
    $gross = $profit * (float) cl_setting($conn, 'unsecured_pct') / 100;
    // What the customer already owes: open RM customer loans + unpaid credit invoices.
    $loans = (float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(s.amount_due - s.paid), 0) FROM cl_schedule s JOIN cl_applications a ON a.id = s.application_id
        WHERE a.customer_id = $customerId AND a.status IN ('Disbursed', 'Active', 'Overdue', 'Defaulted', 'Agreement Signed')"))[0];
    $ch = $hasCol('sales', 'charges_accrued') ? ' + COALESCE(charges_accrued, 0) - COALESCE(charges_paid, 0)' : '';
    $invoices = (float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount - amount_paid$ch), 0) FROM sales WHERE customer_id = $customerId AND status IN ('Credit', 'Partially Paid')"))[0];
    $limit = max(0, floor($gross - $loans - $invoices));
    return ['points' => $points, 'current_points' => $current, 'profit' => $profit, 'gross_limit' => $gross,
            'owed_loans' => round($loans, 2), 'owed_invoices' => round($invoices, 2), 'limit' => $limit];
}

/* ------------------------------------------------------------------ calculator */

/**
 * The repayment schedule for an amount: [rows (no, due_date, principal, interest, fee, amount_due), totals].
 * flat: interest = principal x monthly rate x months, spread evenly. reducing: equal instalments on the remaining balance.
 */
function cl_schedule_calc(float $principal, float $monthlyRatePct, int $months, string $frequency, string $method, float $feePct, string $startDate): array
{
    $months = max(1, $months);
    $n = $frequency === 'Weekly' ? $months * 4 : $months;
    $r = $monthlyRatePct / 100;
    $fee = round($principal * $feePct / 100, 2);
    $rows = []; $date = $startDate;
    if ($method === 'reducing' && $r > 0) {
        $pr = $frequency === 'Weekly' ? $r * 12 / 52 : $r;
        $emi = $principal * $pr / (1 - pow(1 + $pr, -$n));
        $bal = $principal;
        for ($i = 1; $i <= $n; $i++) {
            $int = round($bal * $pr, 2);
            $p = $i === $n ? round($bal, 2) : round($emi - $int, 2);
            $bal = round($bal - $p, 2);
            $date = date('Y-m-d', strtotime($date . ($frequency === 'Weekly' ? ' +1 week' : ' +1 month')));
            $rows[] = ['no' => $i, 'due_date' => $date, 'principal' => $p, 'interest' => $int, 'fee' => 0.0];
        }
    } else {
        $interest = round($principal * $r * $months, 2);
        $pEach = floor(100 * $principal / $n) / 100; $iEach = floor(100 * $interest / $n) / 100;
        for ($i = 1; $i <= $n; $i++) {
            $p = $i === $n ? round($principal - $pEach * ($n - 1), 2) : $pEach;
            $int = $i === $n ? round($interest - $iEach * ($n - 1), 2) : $iEach;
            $date = date('Y-m-d', strtotime($date . ($frequency === 'Weekly' ? ' +1 week' : ' +1 month')));
            $rows[] = ['no' => $i, 'due_date' => $date, 'principal' => $p, 'interest' => $int, 'fee' => 0.0];
        }
    }
    if ($fee > 0) { $rows[0]['fee'] = $fee; }
    foreach ($rows as &$x) { $x['amount_due'] = round($x['principal'] + $x['interest'] + $x['fee'], 2); } unset($x);
    $tot = ['principal' => round(array_sum(array_column($rows, 'principal')), 2), 'interest' => round(array_sum(array_column($rows, 'interest')), 2),
            'fee' => $fee, 'total' => round(array_sum(array_column($rows, 'amount_due')), 2), 'instalments' => $n];
    return ['rows' => $rows, 'totals' => $tot];
}

/** Writes the schedule of an approved application (replacing any earlier offer). */
function cl_save_schedule(mysqli $conn, int $appId, array $calc): void
{
    mysqli_query($conn, 'DELETE FROM cl_schedule WHERE application_id = ' . $appId);
    $s = mysqli_prepare($conn, 'INSERT INTO cl_schedule (application_id, no, due_date, principal, interest, fee, amount_due) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($calc['rows'] as $r) { mysqli_stmt_bind_param($s, 'iisdddd', $appId, $r['no'], $r['due_date'], $r['principal'], $r['interest'], $r['fee'], $r['amount_due']); mysqli_stmt_execute($s); }
}

/** Outstanding balance of a loan (what is still to pay on its schedule). */
function cl_outstanding(mysqli $conn, int $appId): float
{
    return round((float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(amount_due - paid), 0) FROM cl_schedule WHERE application_id = $appId"))[0], 2);
}

/** Applies a VERIFIED payment to the oldest instalments; marks the loan Fully Repaid when nothing is left. */
function cl_apply_payment(mysqli $conn, int $appId, float $amount): void
{
    $left = $amount;
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM cl_schedule WHERE application_id = $appId AND paid < amount_due ORDER BY no"), MYSQLI_ASSOC) as $r) {
        if ($left <= 0.004) { break; }
        $pay = min($left, (float) $r['amount_due'] - (float) $r['paid']);
        $paid = round((float) $r['paid'] + $pay, 2);
        $st = $paid >= (float) $r['amount_due'] - 0.004 ? 'Paid' : 'Partial';
        mysqli_query($conn, "UPDATE cl_schedule SET paid = $paid, status = '$st' WHERE id = " . (int) $r['id']);
        $left = round($left - $pay, 2);
    }
}

/** Marks loans with an instalment past its due date as Overdue (and back to Active once caught up). */
function cl_refresh_overdue(mysqli $conn, ?int $customerId = null): void
{
    $w = $customerId ? ' AND a.customer_id = ' . $customerId : '';
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT a.id, a.status, a.customer_id,
        (SELECT COUNT(*) FROM cl_schedule s WHERE s.application_id = a.id AND s.paid < s.amount_due - 0.004 AND s.due_date < CURDATE()) late,
        (SELECT COALESCE(SUM(s.amount_due - s.paid), 0) FROM cl_schedule s WHERE s.application_id = a.id) owed
        FROM cl_applications a WHERE a.status IN ('Active', 'Overdue', 'Disbursed')$w"), MYSQLI_ASSOC) as $a) {
        $to = (float) $a['owed'] <= 0.004 ? 'Fully Repaid' : ((int) $a['late'] > 0 ? 'Overdue' : 'Active');
        if ($to !== $a['status'] && !($a['status'] === 'Disbursed' && $to === 'Active')) {
            cl_set_status($conn, (int) $a['id'], $to, null, 'Automatic: ' . ($to === 'Overdue' ? 'an instalment is past its due date' : ($to === 'Fully Repaid' ? 'nothing left to pay' : 'payments caught up')));
        }
    }
}

/* ------------------------------------------------------------------ status, audit, notifications */

function cl_audit(mysqli $conn, ?int $appId, string $action, ?string $from, ?string $to, ?string $reason, ?int $userId = null, ?int $customerId = null): void
{
    $s = mysqli_prepare($conn, 'INSERT INTO cl_audit (application_id, user_id, customer_id, action, from_status, to_status, reason) VALUES (?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'iiissss', $appId, $userId, $customerId, $action, $from, $to, $reason);
    mysqli_stmt_execute($s);
}

const CL_NOTICES = [
    'Submitted' => 'Loan application submitted', 'Under Review' => 'Your loan application is under review',
    'Additional Information Required' => 'More information needed for your loan application', 'Rejected' => 'Loan application not approved',
    'Awaiting Customer Acceptance' => 'Loan approved: your offer is ready', 'Agreement Signed' => 'Loan agreement signed',
    'Disbursed' => 'Loan payment disbursed', 'Overdue' => 'Your loan is overdue', 'Fully Repaid' => 'Loan fully repaid. Thank you!',
    'Defaulted' => 'Loan in default', 'Cancelled' => 'Loan application cancelled',
];

/** Changes the status of an application, records it, and tells the customer (portal + SMS). */
function cl_set_status(mysqli $conn, int $appId, string $to, ?int $userId, ?string $reason, ?string $action = null): void
{
    $a = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM cl_applications WHERE id = ' . $appId));
    if (!$a) { return; }
    $s = mysqli_prepare($conn, 'UPDATE cl_applications SET status = ?, updated_at = NOW() WHERE id = ?');
    mysqli_stmt_bind_param($s, 'si', $to, $appId); mysqli_stmt_execute($s);
    cl_audit($conn, $appId, $action ?? 'Status changed', $a['status'], $to, $reason, $userId, $userId ? null : (int) $a['customer_id']);
    if (isset(CL_NOTICES[$to])) {
        portal_notify_customer($conn, (int) $a['customer_id'], CL_NOTICES[$to] . ' (' . cl_ref($appId, $a['created_at']) . ')',
            $to === 'Additional Information Required' || $to === 'Rejected' ? (string) $reason : '', 'loan.php?id=' . $appId);
    }
}

/** A notification in the customer's portal, and an SMS when SMS is set up. */
function portal_notify_customer(mysqli $conn, int $customerId, string $title, string $body = '', string $link = '', bool $sms = true): void
{
    $s = mysqli_prepare($conn, 'INSERT INTO portal_notifications (customer_id, title, body, link) VALUES (?, ?, ?, ?)');
    $b = mb_substr($body, 0, 500);
    mysqli_stmt_bind_param($s, 'isss', $customerId, $title, $b, $link); mysqli_stmt_execute($s);
    if ($sms && is_file(__DIR__ . '/sms_helpers.php')) {
        require_once __DIR__ . '/sms_helpers.php';
        $phone = (string) (mysqli_fetch_row(mysqli_query($conn, 'SELECT phone FROM customers WHERE id = ' . $customerId))[0] ?? '');
        if ($phone !== '' && function_exists('sms_send')) {
            try { sms_send($conn, $phone, 'RISE MOTIVE: ' . mb_substr($title, 0, 120) . '. Details in your customer portal.', 'portal_notice', 'customer', $customerId, null); } catch (Throwable $e) { error_log('portal sms: ' . $e->getMessage()); }
        }
    }
}