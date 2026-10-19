<?php
/**
 * NEW FILE: includes/credit_charge_helpers.php
 * Overdue charges on credit invoices (approved policy):
 *   - Grace period: until the invoice's payment_deadline (72 hours after it was issued).
 *   - After it, ONE charge per full day: 1% of what is still owed that day (remaining principal +
 *     unpaid charges). With no payments: balance after n days = principal x 1.01^n.
 *   - Each charge day is numbered; the database refuses a second row for the same day
 *     (UNIQUE sale_id + entry_type + charge_day_no), so retries can never charge twice.
 *   - Payments pay the charges first, then the original amount (principal).
 *   - Every entry (principal, payments, charges, waivers) is kept in credit_ledger with the
 *     balances after it, who did it and when: a complete audit trail.
 * sales.amount_paid stays "principal paid", so revenue, profit and Loyalty Points are unchanged.
 */
require_once __DIR__ . '/fund_helpers.php';
require_once __DIR__ . '/income_allocation_helpers.php';

const CREDIT_CHARGE_DAILY_RATE = 0.01;   // 1% per day (approved)
const CREDIT_CHARGE_MAX_DAYS = 0;        // 0 = no limit. Set e.g. 90 to stop charging after 90 days.
const CREDIT_CHARGE_CATEGORY = 'Overdue Charges';

/** SQL for everything still owed on a sale (principal + unpaid charges). $a = table alias. */
function credit_owed_sql(string $a = 'sales'): string
{
    return "($a.total_amount - $a.amount_paid + $a.charges_accrued - $a.charges_paid)";
}

/** Writes one ledger row. $sale must hold the sale's values AFTER this entry. */
function credit_ledger_add(mysqli $conn, array $sale, string $type, float $amount, ?int $dayNo, ?string $chargeDate,
                           ?int $paymentId, string $notes, ?int $userId): void
{
    $principalBal = round((float) $sale['total_amount'] - (float) $sale['amount_paid'], 2);
    $chargesBal = round((float) $sale['charges_accrued'] - (float) $sale['charges_paid'], 2);
    $s = mysqli_prepare($conn, 'INSERT INTO credit_ledger
        (sale_id, entry_type, amount, charge_day_no, charge_date, sale_payment_id, principal_balance, charges_balance, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $sid = (int) $sale['id'];
    $amount = round($amount, 2);
    mysqli_stmt_bind_param($s, 'isdisiddsi', $sid, $type, $amount, $dayNo, $chargeDate, $paymentId, $principalBal, $chargesBal, $notes, $userId);
    mysqli_stmt_execute($s);
}

/** Called when a credit invoice is issued (inside sales_finalize's transaction). */
function credit_issue_invoice(mysqli $conn, int $saleId, ?int $userId): void
{
    $sale = credit_lock_sale($conn, $saleId);
    if (!$sale) { return; }
    $exists = mysqli_prepare($conn, "SELECT id FROM credit_ledger WHERE sale_id = ? AND entry_type = 'PRINCIPAL' LIMIT 1");
    mysqli_stmt_bind_param($exists, 'i', $saleId);
    mysqli_stmt_execute($exists);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($exists))) { return; }

    $paid = (float) $sale['amount_paid'];
    $atIssue = $sale;
    $atIssue['amount_paid'] = 0;
    credit_ledger_add($conn, $atIssue, 'PRINCIPAL', (float) $sale['total_amount'], null, null, null, 'Credit invoice issued', $userId);
    if ($paid > 0.009) {
        credit_ledger_add($conn, $sale, 'PAYMENT_PRINCIPAL', $paid, null, null, null, 'Paid at checkout', $userId);
    }
}

function credit_lock_sale(mysqli $conn, int $saleId): ?array
{
    $s = mysqli_prepare($conn, 'SELECT * FROM sales WHERE id = ? FOR UPDATE');
    mysqli_stmt_bind_param($s, 'i', $saleId);
    mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

/**
 * Adds every daily charge that is due on one invoice and not charged yet, day by day.
 * MUST run inside the caller's transaction. Returns the total added.
 */
function credit_accrue(mysqli $conn, int $saleId, ?int $userId = null): float
{
    $sale = credit_lock_sale($conn, $saleId);
    if (!$sale || !in_array($sale['status'], ['Credit', 'Partially Paid'], true) || empty($sale['payment_deadline'])) {
        return 0.0;
    }
    $due = mysqli_fetch_row(mysqli_query($conn, "SELECT GREATEST(0, FLOOR(TIMESTAMPDIFF(SECOND, '"
        . mysqli_real_escape_string($conn, $sale['payment_deadline']) . "', NOW()) / 86400))"))[0];
    $due = (int) $due;
    if (CREDIT_CHARGE_MAX_DAYS > 0) { $due = min($due, CREDIT_CHARGE_MAX_DAYS); }

    $added = 0.0;
    $startDays = (int) $sale['charge_days'];
    $deadline = new DateTimeImmutable($sale['payment_deadline']);
    for ($day = (int) $sale['charge_days'] + 1; $day <= $due; $day++) {
        $owed = round((float) $sale['total_amount'] - (float) $sale['amount_paid'] + (float) $sale['charges_accrued'] - (float) $sale['charges_paid'], 2);
        if ($owed <= 0.009) { break; }
        $charge = round($owed * CREDIT_CHARGE_DAILY_RATE, 2);
        $sale['charges_accrued'] = round((float) $sale['charges_accrued'] + $charge, 2);
        $sale['charge_days'] = $day;
        $chargeDate = $deadline->modify('+' . $day . ' days')->format('Y-m-d');
        credit_ledger_add($conn, $sale, 'CHARGE', $charge, $day, $chargeDate, null,
            'Day ' . $day . ': ' . (CREDIT_CHARGE_DAILY_RATE * 100) . '% of RWF ' . number_format($owed, 2), $userId);
        $added += $charge;
    }
    if ((int) $sale['charge_days'] !== $startDays) {
        $u = mysqli_prepare($conn, 'UPDATE sales SET charges_accrued = ?, charge_days = ? WHERE id = ?');
        $acc = (float) $sale['charges_accrued'];
        $days = (int) $sale['charge_days'];
        mysqli_stmt_bind_param($u, 'dii', $acc, $days, $saleId);
        mysqli_stmt_execute($u);
    }
    return round($added, 2);
}

/** Daily job: brings every overdue invoice up to date (each in its own transaction). */
function credit_accrue_all(mysqli $conn): array
{
    $ids = mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM sales
        WHERE status IN ('Credit', 'Partially Paid') AND payment_deadline IS NOT NULL AND payment_deadline < NOW()"), MYSQLI_ASSOC);
    $invoices = 0; $total = 0.0;
    foreach ($ids as $r) {
        mysqli_begin_transaction($conn);
        try {
            $a = credit_accrue($conn, (int) $r['id'], null);
            mysqli_commit($conn);
            if ($a > 0) { $invoices++; $total += $a; }
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('credit_accrue failed for sale #' . $r['id'] . ': ' . $e->getMessage());
        }
    }
    return ['invoices' => $invoices, 'amount' => round($total, 2)];
}

/** Brings one invoice up to date in its own transaction (used when a page shows it). */
function credit_accrue_now(mysqli $conn, int $saleId): void
{
    mysqli_begin_transaction($conn);
    try {
        credit_accrue($conn, $saleId, null);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('credit_accrue_now failed for sale #' . $saleId . ': ' . $e->getMessage());
    }
}

/**
 * Applies a payment: charges first, then principal. MUST run inside the caller's transaction,
 * after credit_accrue() for this sale. $sale = the locked, up-to-date sale row.
 * The charge part becomes an Income ("Overdue Charges") allocated to the four Funds.
 * Returns ['to_charges', 'to_principal', 'status', 'sale' (updated row)].
 */
function credit_apply_payment(mysqli $conn, array $sale, float $amount, int $paymentId, ?int $userId): array
{
    $chargesOut = round((float) $sale['charges_accrued'] - (float) $sale['charges_paid'], 2);
    $toCharges = round(min($amount, max(0, $chargesOut)), 2);
    $toPrincipal = round($amount - $toCharges, 2);

    if ($toCharges > 0) {
        $sale['charges_paid'] = round((float) $sale['charges_paid'] + $toCharges, 2);
        credit_ledger_add($conn, $sale, 'PAYMENT_CHARGE', $toCharges, null, null, $paymentId, 'Payment applied to overdue charges', $userId);

        $desc = 'Overdue charges paid on invoice ' . company_doc_no((int) $sale['id']);
        $tx = mysqli_prepare($conn, "INSERT INTO transactions (category, transaction_type, amount, transaction_date, description, recorded_by, status, is_automatic)
            VALUES (?, 'Income', ?, CURDATE(), ?, ?, 'approved', 1)");
        $cat = CREDIT_CHARGE_CATEGORY;
        mysqli_stmt_bind_param($tx, 'sdsi', $cat, $toCharges, $desc, $userId);
        mysqli_stmt_execute($tx);
        allocate_income_transaction($conn, (int) mysqli_insert_id($conn), $toCharges, $userId);
    }
    if ($toPrincipal > 0) {
        $sale['amount_paid'] = round((float) $sale['amount_paid'] + $toPrincipal, 2);
        credit_ledger_add($conn, $sale, 'PAYMENT_PRINCIPAL', $toPrincipal, null, null, $paymentId, 'Payment applied to the invoice amount', $userId);
    }

    $principalOut = round((float) $sale['total_amount'] - (float) $sale['amount_paid'], 2);
    $chargesLeft = round((float) $sale['charges_accrued'] - (float) $sale['charges_paid'], 2);
    $status = ($principalOut <= 0.01 && $chargesLeft <= 0.009) ? 'Paid' : (((float) $sale['amount_paid'] > 0.009) ? 'Partially Paid' : 'Credit');

    $u = mysqli_prepare($conn, 'UPDATE sales SET amount_paid = ?, charges_paid = ?, status = ? WHERE id = ?');
    $ap = (float) $sale['amount_paid']; $cp = (float) $sale['charges_paid']; $sid = (int) $sale['id'];
    mysqli_stmt_bind_param($u, 'ddsi', $ap, $cp, $status, $sid);
    mysqli_stmt_execute($u);
    $sale['status'] = $status;

    return ['to_charges' => $toCharges, 'to_principal' => $toPrincipal, 'status' => $status, 'sale' => $sale];
}

/**
 * Waives unpaid charges (Admin, with a reason), or all of them when a sale is cancelled.
 * MUST run inside the caller's transaction. Returns the amount waived.
 */
function credit_waive(mysqli $conn, int $saleId, ?float $amount, string $reason, ?int $userId): float
{
    $sale = credit_lock_sale($conn, $saleId);
    if (!$sale) { return 0.0; }
    $out = round((float) $sale['charges_accrued'] - (float) $sale['charges_paid'], 2);
    $w = $amount === null ? $out : round(min($amount, $out), 2);
    if ($w <= 0.009) { return 0.0; }
    $sale['charges_accrued'] = round((float) $sale['charges_accrued'] - $w, 2);
    credit_ledger_add($conn, $sale, 'WAIVER', $w, null, null, null, $reason, $userId);
    $u = mysqli_prepare($conn, 'UPDATE sales SET charges_accrued = ? WHERE id = ?');
    $acc = (float) $sale['charges_accrued'];
    mysqli_stmt_bind_param($u, 'di', $acc, $saleId);
    mysqli_stmt_execute($u);
    return $w;
}