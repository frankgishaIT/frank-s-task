<?php
/**
 * NEW FILE: includes/payroll_fund_helpers.php
 * Keeps a payroll's expense in the RM Business Operating Fund in step with the payroll
 * (spec sections 3 and 14):
 *   Paid  -> the fund has paid exactly its Net Salary
 *   Draft or deleted -> the fund has paid nothing for it
 *
 * When the amount must change, the old expense is REVERSED (linked reversal in the fund, and its
 * transaction marked 'deleted' so reports stop counting it) and the new amount is posted as a
 * fresh automatic expense, with the usual "enough money in the fund" check. Nothing is erased.
 */
require_once __DIR__ . '/fund_helpers.php';

/**
 * Payrolls paid before their fund expense was linked to them ('PAYROLL' + id) are found by
 * the description the Generate page used ("Payroll: <name> (<Month Year>)") and linked now.
 * Only the link is added; amounts are never changed.
 */
function payroll_link_legacy_expense(mysqli $conn, array $payroll): void {
    $desc = 'Payroll: ' . $payroll['employee_name'] . ' (' . date('F Y', strtotime($payroll['pay_period'])) . ')';
    $s = mysqli_prepare($conn, "SELECT m.id FROM fund_movements m
        JOIN transactions t ON t.id = m.transaction_id
        WHERE m.ref_type IS NULL AND m.movement_type = 'EXPENSE' AND t.category = 'Payroll' AND t.description = ?
        ORDER BY m.id LIMIT 1");
    mysqli_stmt_bind_param($s, 's', $desc);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if ($row) {
        $u = mysqli_prepare($conn, "UPDATE fund_movements SET ref_type = 'PAYROLL', ref_id = ? WHERE id = ?");
        $pid = (int) $payroll['id'];
        $mid = (int) $row['id'];
        mysqli_stmt_bind_param($u, 'ii', $pid, $mid);
        mysqli_stmt_execute($u);
    }
}

/**
 * Brings the fund in line with the payroll. Call AFTER the payroll row has been updated, inside
 * the caller's mysqli_begin_transaction(). $previousStatus is the status before the change.
 * Throws InsufficientFundException when the fund cannot cover a new or higher amount.
 * Returns the amount now paid from the fund for this payroll; $paidBefore receives the amount before.
 */
function payroll_sync_fund(mysqli $conn, int $payrollId, string $previousStatus, ?int $userId, bool $deleted = false, ?float &$paidBefore = null): float {
    $paidBefore = 0.0; // what the fund had paid for this payroll before this change
    $s = mysqli_prepare($conn, 'SELECT p.*, u.names AS employee_name FROM payroll p JOIN users u ON u.id = p.user_id WHERE p.id = ? FOR UPDATE');
    mysqli_stmt_bind_param($s, 'i', $payrollId);
    mysqli_stmt_execute($s);
    $payroll = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if (!$payroll) {
        throw new RuntimeException('Payroll record not found.');
    }

    // Any expense ever posted for it (linked)?
    $hasAny = function () use ($conn, $payrollId) {
        $q = mysqli_prepare($conn, "SELECT id FROM fund_movements WHERE ref_type = 'PAYROLL' AND ref_id = ? LIMIT 1");
        mysqli_stmt_bind_param($q, 'i', $payrollId);
        mysqli_stmt_execute($q);
        return (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($q));
    };
    if (!$hasAny()) {
        payroll_link_legacy_expense($conn, $payroll);
    }

    // A payroll that was already Paid but never reached the fund (paid before the Funds existed):
    // leave the fund alone, otherwise editing an old payslip would charge the fund today.
    if ($previousStatus === 'Paid' && !$hasAny()) {
        return 0.0;
    }

    // What the fund has paid for it right now (expenses minus reversals).
    $q = mysqli_prepare($conn, "SELECT COALESCE(SUM(CASE WHEN direction = 'OUT' THEN amount ELSE -amount END), 0) AS paid
        FROM fund_movements WHERE ref_type = 'PAYROLL' AND ref_id = ?");
    mysqli_stmt_bind_param($q, 'i', $payrollId);
    mysqli_stmt_execute($q);
    $current = round((float) mysqli_fetch_assoc(mysqli_stmt_get_result($q))['paid'], 2);
    $paidBefore = $current;

    $target = (!$deleted && $payroll['status'] === 'Paid') ? max(0, round((float) $payroll['net_salary'], 2)) : 0.0;
    if (abs($target - $current) < 0.01) {
        return $current;
    }

    // Take the old expense back out (fund + its transaction), then post the new amount.
    if ($current > 0) {
        $tx = mysqli_prepare($conn, "SELECT DISTINCT m.transaction_id FROM fund_movements m
            LEFT JOIN fund_movements r ON r.reverses_movement_id = m.id
            WHERE m.ref_type = 'PAYROLL' AND m.ref_id = ? AND m.movement_type = 'EXPENSE' AND r.id IS NULL AND m.transaction_id IS NOT NULL");
        mysqli_stmt_bind_param($tx, 'i', $payrollId);
        mysqli_stmt_execute($tx);
        $txIds = array_map('intval', array_column(mysqli_fetch_all(mysqli_stmt_get_result($tx), MYSQLI_ASSOC), 'transaction_id'));

        fund_reverse_movements($conn, "m.ref_type = 'PAYROLL' AND m.ref_id = ?", 'i', [$payrollId], $userId,
            'Payroll #' . $payrollId . ($deleted ? ' deleted' : ' changed'));

        if ($txIds) {
            $mark = mysqli_prepare($conn, "UPDATE transactions SET status = 'deleted', deleted_by = ?, deleted_at = NOW() WHERE id = ?");
            foreach ($txIds as $txId) {
                mysqli_stmt_bind_param($mark, 'ii', $userId, $txId);
                mysqli_stmt_execute($mark);
            }
        }
    }

    if ($target > 0) {
        $description = 'Payroll: ' . $payroll['employee_name'] . ' (' . date('F Y', strtotime($payroll['pay_period'])) . ')';
        fund_post_automatic_expense($conn, 'OPERATING', 'Payroll', $target, date('Y-m-d'), $description,
            $userId, null, 'PAYROLL', $payrollId);
    }
    return $target;
}