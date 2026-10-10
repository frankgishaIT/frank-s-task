<?php
/**
 * NEW FILE: includes/income_allocation_helpers.php
 * Spec section 1: profit from ANY transaction is allocated to the four Funds automatically.
 * A manual Income (commission, interest, partner payment ... anything with no stock involved)
 * is 100% profit, so its full amount is split by the fund percentages, exactly like sales profit.
 *
 * Product and service SALES must be recorded in the Sales module, not as manual Income:
 * only the Sales module deducts stock, lowers the Capital Fund by the cost of stock sold,
 * and allocates only the real profit.
 *
 * Allocation rows are linked to the transaction (ref_type 'TX_INCOME', ref_id = transaction id),
 * so editing or deleting the Income reverses exactly them.
 */
require_once __DIR__ . '/fund_helpers.php';

// Employee Income waits for admin approval (like employee expenses) before it reaches the Funds.
// Set to false to record employee Income immediately again.
const INCOME_NEEDS_APPROVAL = true;

/**
 * Allocates an APPROVED manual Income to the four Funds. Safe to call twice: does nothing while
 * an allocation for this transaction is still active. MUST be called inside mysqli_begin_transaction().
 */
function allocate_income_transaction(mysqli $conn, int $transactionId, float $amount, ?int $userId): void {
    $chk = mysqli_prepare($conn, "SELECT m.id FROM fund_movements m
        LEFT JOIN fund_movements r ON r.reverses_movement_id = m.id
        WHERE m.ref_type = 'TX_INCOME' AND m.ref_id = ? AND m.movement_type = 'ALLOCATION' AND r.id IS NULL
        LIMIT 1");
    mysqli_stmt_bind_param($chk, 'i', $transactionId);
    mysqli_stmt_execute($chk);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($chk))) { return; }

    $amount = round($amount, 2);
    if ($amount <= 0) { return; }

    $period = date('Y-m');
    foreach (build_allocation(funds_all($conn), $amount) as $row) {
        if (abs($row['amount']) < 0.01) { continue; }
        fund_record_movement($conn, (int) $row['fund']['id'], 'ALLOCATION', $row['amount'] >= 0 ? 'IN' : 'OUT', abs($row['amount']),
            $period, $transactionId, $userId, 'Income #' . $transactionId . ' allocation', null, 'TX_INCOME', $transactionId);
    }
}

/**
 * Reverses the allocation of a manual Income (when it is edited or deleted).
 * Returns how many rows were reversed (0 for Income recorded before this feature, which was
 * never allocated). MUST be called inside mysqli_begin_transaction().
 */
function reverse_income_allocation(mysqli $conn, int $transactionId, ?int $userId, string $reason): int {
    return fund_reverse_movements($conn,
        "m.ref_type = 'TX_INCOME' AND m.ref_id = ? AND m.movement_type = 'ALLOCATION'", 'i', [$transactionId],
        $userId, 'Income #' . $transactionId . ' ' . $reason);
}