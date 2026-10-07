<?php
class InsufficientFundException extends RuntimeException {}

function fund_expense_categories(): array {
    return ['Rent','Electricity','Water','Internet','Telephone','Other utilities',
            'Transport','Marketing','Office Expenses','Bank Charges','Taxes',
            'Maintenance','Communication','Staff training','Learning materials',
            'Employee development','Team building','Employee recognition'];
}

function funds_all(mysqli $conn): array {
    $res = mysqli_query($conn, 'SELECT * FROM funds WHERE is_active = 1 ORDER BY sort_order');
    return mysqli_fetch_all($res, MYSQLI_ASSOC);
}

function fund_get(mysqli $conn, int $fundId): ?array {
    $s = mysqli_prepare($conn, 'SELECT * FROM funds WHERE id = ? AND is_active = 1');
    mysqli_stmt_bind_param($s, 'i', $fundId);
    mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

// Finds a fund by its code: OPERATING, FUTURE_PLANS, EMERGENCY, TEAM_GROWTH, CAPITAL.
function fund_by_code(mysqli $conn, string $code): ?array {
    $s = mysqli_prepare($conn, 'SELECT * FROM funds WHERE code = ? AND is_active = 1');
    mysqli_stmt_bind_param($s, 's', $code);
    mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

function fund_balance(mysqli $conn, int $fundId): float {
    $s = mysqli_prepare($conn, "SELECT COALESCE(SUM(CASE WHEN direction='IN' THEN amount ELSE -amount END),0) AS b FROM fund_movements WHERE fund_id = ?");
    mysqli_stmt_bind_param($s, 'i', $fundId);
    mysqli_stmt_execute($s);
    return (float) mysqli_fetch_assoc(mysqli_stmt_get_result($s))['b'];
}

// Expenses waiting for admin approval are "reserved" so two pending
// expenses cannot both spend the same money.
function fund_pending_total(mysqli $conn, int $fundId): float {
    $s = mysqli_prepare($conn, "SELECT COALESCE(SUM(amount),0) AS p FROM transactions WHERE fund_id = ? AND transaction_type = 'Expense' AND status = 'pending'");
    mysqli_stmt_bind_param($s, 'i', $fundId);
    mysqli_stmt_execute($s);
    return (float) mysqli_fetch_assoc(mysqli_stmt_get_result($s))['p'];
}

function fund_available(mysqli $conn, int $fundId): float {
    return fund_balance($conn, $fundId) - fund_pending_total($conn, $fundId);
}

// Locks the fund row so two users cannot overspend at the same moment.
// Must be called inside mysqli_begin_transaction().
function fund_lock(mysqli $conn, int $fundId): void {
    $s = mysqli_prepare($conn, 'SELECT id FROM funds WHERE id = ? AND is_active = 1 FOR UPDATE');
    mysqli_stmt_bind_param($s, 'i', $fundId);
    mysqli_stmt_execute($s);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($s))) {
        throw new RuntimeException('Selected fund does not exist.');
    }
}

function fund_record_movement(mysqli $conn, int $fundId, string $type, string $direction,
        float $amount, string $period, ?int $txId, ?int $userId, string $desc, ?string $source = null): void {
    $s = mysqli_prepare($conn, 'INSERT INTO fund_movements (fund_id, period, movement_type, direction, amount, transaction_id, source_type, description, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
    mysqli_stmt_bind_param($s, 'isssdissi', $fundId, $period, $type, $direction, $amount, $txId, $source, $desc, $userId);
    mysqli_stmt_execute($s);
}

function fund_insufficient_message(string $fundName): string {
    return 'Insufficient ' . $fundName . ' Balance. This transaction cannot be completed because the available '
         . preg_replace('/^RM /', '', $fundName) . ' is insufficient.';
}

/**
 * Posts an AUTOMATIC expense (payroll, loan repayment, purchase order, re-stocking...).
 * It locks the fund, checks the available balance, creates an approved Expense
 * transaction linked to the fund, and writes the ledger entry.
 *
 * MUST be called inside mysqli_begin_transaction(). Throws InsufficientFundException
 * when the fund cannot cover the amount, so the caller can roll everything back.
 * Returns the new transaction id.
 */
function fund_post_automatic_expense(mysqli $conn, string $fundCode, string $category, float $amount,
        string $date, string $description, ?int $userId, ?string $expenseCategory = null): int {
    $fund = fund_by_code($conn, $fundCode);
    if (!$fund) {
        throw new RuntimeException('Fund ' . $fundCode . ' was not found.');
    }
    $fundId = (int) $fund['id'];

    fund_lock($conn, $fundId);
    if ($amount > fund_available($conn, $fundId) + 0.001) {
        throw new InsufficientFundException(fund_insufficient_message($fund['name']));
    }

    $s = mysqli_prepare($conn, "INSERT INTO transactions
        (category, transaction_type, amount, transaction_date, description, recorded_by, status, fund_id, expense_category, is_automatic)
        VALUES (?, 'Expense', ?, ?, ?, ?, 'approved', ?, ?, 1)");
    mysqli_stmt_bind_param($s, 'sdssiis', $category, $amount, $date, $description, $userId, $fundId, $expenseCategory);
    mysqli_stmt_execute($s);
    $txId = (int) mysqli_insert_id($conn);

    fund_record_movement($conn, $fundId, 'EXPENSE', 'OUT', $amount, substr($date, 0, 7), $txId, $userId, $description);
    return $txId;
}

// Records money coming INTO the RM Capital Fund from a source that is not profit
// (a business loan received, share capital, a grant...). It is NOT income and does
// not touch Net Profit. $refType / $refId optionally link the inflow to the record
// that caused it (for example 'loan' and the loan id), so it can be found and
// adjusted later. Call inside mysqli_begin_transaction() when it is part of a bigger save.
function fund_record_capital_inflow(mysqli $conn, float $amount, string $date, string $source,
        string $description, ?int $userId, ?string $refType = null, ?int $refId = null): void {
    $fund = fund_by_code($conn, 'CAPITAL');
    if (!$fund) {
        throw new RuntimeException('RM Capital Fund was not found.');
    }
    $fundId = (int) $fund['id'];
    $period = substr($date, 0, 7);
    $description = mb_substr($description, 0, 255);

    $s = mysqli_prepare($conn, "INSERT INTO fund_movements
        (fund_id, period, movement_type, direction, amount, source_type, description, created_by, ref_type, ref_id)
        VALUES (?, ?, 'CAPITAL_INFLOW', 'IN', ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($s, 'isdssisi', $fundId, $period, $amount, $source, $description, $userId, $refType, $refId);
    mysqli_stmt_execute($s);
}

// Takes money OUT of the RM Capital Fund as a CORRECTION of an earlier inflow
// (for example when the amount of a loan that was received is reduced).
// It is stored as an ADJUSTMENT, so it lowers "Contributed" and the balance
// but is not counted as money "Used". The caller must check that the fund has
// enough money first. Call inside mysqli_begin_transaction().
function fund_record_capital_adjustment_out(mysqli $conn, float $amount, string $date, string $source,
        string $description, ?int $userId, ?string $refType = null, ?int $refId = null): void {
    $fund = fund_by_code($conn, 'CAPITAL');
    if (!$fund) {
        throw new RuntimeException('RM Capital Fund was not found.');
    }
    $fundId = (int) $fund['id'];
    $period = substr($date, 0, 7);
    $description = mb_substr($description, 0, 255);

    $s = mysqli_prepare($conn, "INSERT INTO fund_movements
        (fund_id, period, movement_type, direction, amount, source_type, description, created_by, ref_type, ref_id)
        VALUES (?, ?, 'ADJUSTMENT', 'OUT', ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($s, 'isdssisi', $fundId, $period, $amount, $source, $description, $userId, $refType, $refId);
    mysqli_stmt_execute($s);
}
