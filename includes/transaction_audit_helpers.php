<?php
/**
 * NEW FILE: includes/transaction_audit_helpers.php
 * Task 1: safe deletion of transactions, with an audit trail.
 *   - Manual transactions: an Admin deletes them (with a reason). The money they moved goes back.
 *   - Automatic transactions (sales, purchase orders / restocks, payroll, loans, assets, rewards, overdue
 *     charges): never deleted here. tx_source() finds the record they came from, where they are cancelled,
 *     reversed or corrected, so the source and the funds stay consistent.
 *   - Every deletion is written to transaction_audit (reference, amount, reason, admin, date and time, action).
 */
require_once __DIR__ . '/fund_helpers.php';

/**
 * Makes sure this database can record deletions: the transactions.status column must accept 'deleted'
 * (the soft-delete migration added deleted_by / deleted_at but did not allow that value, so every delete
 * failed with "Unable to delete the transaction"), and the audit table must exist. Adds only what is missing.
 */
function tx_audit_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $col = function (string $name) use ($conn): ?array {
        $s = mysqli_prepare($conn, "SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transactions' AND column_name = ?");
        mysqli_stmt_bind_param($s, 's', $name);
        mysqli_stmt_execute($s);
        return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
    };
    $status = $col('status');
    if ($status && strtolower($status['DATA_TYPE']) === 'enum' && stripos($status['COLUMN_TYPE'], "'deleted'") === false) {
        // Keep every existing value, add 'deleted'.
        preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", $status['COLUMN_TYPE'], $m);
        $values = array_unique(array_merge($m[1], ['deleted']));
        $list = implode(',', array_map(function ($v) use ($conn) { return "'" . mysqli_real_escape_string($conn, str_replace("''", "'", $v)) . "'"; }, $values));
        mysqli_query($conn, "ALTER TABLE transactions MODIFY status ENUM($list) NOT NULL DEFAULT 'pending'");
    }
    if (!$col('deleted_by')) { mysqli_query($conn, 'ALTER TABLE transactions ADD COLUMN deleted_by INT(11) NULL'); }
    if (!$col('deleted_at')) { mysqli_query($conn, 'ALTER TABLE transactions ADD COLUMN deleted_at DATETIME NULL'); }
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS transaction_audit (
        id INT(11) NOT NULL AUTO_INCREMENT,
        transaction_id INT(11) NOT NULL,
        reference VARCHAR(30) NOT NULL,
        action VARCHAR(30) NOT NULL,
        transaction_type VARCHAR(10) NULL,
        category VARCHAR(60) NULL,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        reason TEXT NOT NULL,
        details TEXT NULL,
        user_id INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY transaction_id (transaction_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function tx_reference(int $id): string
{
    return 'TX-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function tx_audit_log(mysqli $conn, array $tx, string $action, string $reason, string $details, ?int $userId): void
{
    tx_audit_ensure($conn);
    $id = (int) $tx['id'];
    $ref = tx_reference($id);
    $amount = (float) $tx['amount'];
    $s = mysqli_prepare($conn, 'INSERT INTO transaction_audit (transaction_id, reference, action, transaction_type, category, amount, reason, details, user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'issssdssi', $id, $ref, $action, $tx['transaction_type'], $tx['category'], $amount, $reason, $details, $userId);
    mysqli_stmt_execute($s);
}

/**
 * Where an AUTOMATIC transaction came from: ['label', 'url', 'how'] (url relative to modules/transactions/),
 * or null for a manual transaction (which may be deleted here).
 */
function tx_source(mysqli $conn, array $tx): ?array
{
    $id = (int) $tx['id'];
    $one = function (string $sql) use ($conn, $id) {
        $s = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($s, 'i', $id);
        mysqli_stmt_execute($s);
        return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
    };
    $has = function (string $table, string $column) use ($conn): bool {
        $s = mysqli_prepare($conn, 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        mysqli_stmt_bind_param($s, 'ss', $table, $column);
        mysqli_stmt_execute($s);
        return (int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0] > 0;
    };

    if ($sale = $one('SELECT id FROM sales WHERE transaction_id = ? LIMIT 1')) {
        return ['label' => 'Sale RM' . str_pad((string) $sale['id'], 6, '0', STR_PAD_LEFT), 'url' => '../sales/invoice.php?id=' . (int) $sale['id'],
                'how' => 'Cancel the sale in Sales (Request Cancel, then approve the cancellation). Its income, profit shares and stock are reversed together.'];
    }
    $m = $one("SELECT ref_type, ref_id FROM fund_movements WHERE transaction_id = ? AND ref_type IN ('PO', 'PAYROLL', 'ASSET_PAY', 'REWARD') ORDER BY id LIMIT 1");
    if ($m) {
        $rid = (int) $m['ref_id'];
        switch ($m['ref_type']) {
            case 'PO':
                return ['label' => 'Purchase order RM' . str_pad((string) $rid, 5, '0', STR_PAD_LEFT), 'url' => '../purchase_orders/view.php?id=' . $rid,
                        'how' => 'Cancel or delete the purchase order (or restock) in Purchase Orders. Its payment, stock and buying price are reversed together.'];
            case 'PAYROLL':
                return ['label' => 'Payroll #' . $rid, 'url' => '../payroll/index.php',
                        'how' => 'Correct or delete the payroll in Payroll. Its fund payment is reversed with it.'];
            case 'ASSET_PAY':
                return ['label' => 'Asset purchase', 'url' => '../asset_management/view.php?id=' . $rid,
                        'how' => 'Return the asset to the seller (Assets > Remove Asset > Returned to Seller). The refund goes back into the RM Capital Fund.'];
            case 'REWARD':
                return ['label' => 'Customer reward', 'url' => '../customers/rewards.php',
                        'how' => 'Cancel or reverse the reward in Customers > Rewards. The points and the money are returned together.'];
        }
    }
    if ($has('loans', 'received_transaction_id') && ($loan = $one('SELECT id FROM loans WHERE received_transaction_id = ? LIMIT 1'))) {
        return ['label' => 'Loan LN-' . str_pad((string) $loan['id'], 6, '0', STR_PAD_LEFT), 'url' => '../loans/index.php',
                'how' => 'Correct the loan in Loans (a loan with no repayments can be deleted there). Its Capital Fund entry is reversed with it.'];
    }
    if ($has('asset_disposals', 'transaction_id') && ($d = $one('SELECT asset_id FROM asset_disposals WHERE transaction_id = ? LIMIT 1'))) {
        return ['label' => 'Asset disposal', 'url' => '../asset_management/view.php?id=' . (int) $d['asset_id'],
                'how' => 'This gain or loss comes from removing an asset. It is part of the asset\'s history and is corrected there.'];
    }
    $category = (string) ($tx['category'] ?? '');
    $byCategory = [
        'Loan Repayment' => ['Loan repayment', '../loans/index.php', 'Correct the repayment in Loans, so the loan balance and its schedule stay right.'],
        'Loan Received' => ['Loan received', '../loans/index.php', 'Correct the loan in Loans.'],
        'Overdue Charges' => ['Overdue charge payment', '../sales/index.php', 'It belongs to a credit invoice: open the sale and its Ledger. An Admin can waive charges there.'],
        'Payroll' => ['Payroll', '../payroll/index.php', 'Correct or delete the payroll in Payroll.'],
        'Purchase (Re-stock)' => ['Stock purchase', '../purchase_orders/index.php', 'Correct it in Purchase Orders.'],
        'Asset Purchase' => ['Asset purchase', '../asset_management/index.php', 'Return the asset to the seller in Assets.'],
        'Loyalty Reward' => ['Customer reward', '../customers/rewards.php', 'Cancel or reverse the reward in Customers > Rewards.'],
    ];
    if ((int) ($tx['is_automatic'] ?? 0) === 1 || isset($byCategory[$category])) {
        [$label, $url, $how] = $byCategory[$category] ?? ['Another module', 'index.php', 'It was created automatically by another module: correct it there.'];
        return ['label' => $label, 'url' => $url, 'how' => $how];
    }
    return null; // manual
}

/** What deleting this (manual) transaction will do to the money, in plain words. */
function tx_delete_consequences(mysqli $conn, array $tx): array
{
    $lines = [];
    $id = (int) $tx['id'];
    $amount = number_format((float) $tx['amount'], 2);
    if ($tx['status'] === 'approved' && $tx['transaction_type'] === 'Expense') {
        $fund = !empty($tx['fund_id']) ? fund_get($conn, (int) $tx['fund_id']) : null;
        $lines[] = 'RWF ' . $amount . ' goes back into ' . ($fund['name'] ?? 'its fund') . ' (the expense is undone).';
        $lines[] = 'Totals and reports will no longer count this expense; it stays visible as "Deleted".';
    } elseif ($tx['status'] === 'approved' && $tx['transaction_type'] === 'Income') {
        $lines[] = 'Its share of RWF ' . $amount . ' is taken back out of the four funds it was allocated to.';
        $lines[] = 'If that money was already spent, the funds can go below zero: check them afterwards.';
        $lines[] = 'Totals and reports will no longer count this income; it stays visible as "Deleted".';
    } elseif ($tx['status'] === 'pending') {
        $lines[] = 'It was never approved, so no money moved: deleting only releases the RWF ' . $amount . ' reserved for it.';
    } else {
        $lines[] = 'It was ' . $tx['status'] . ', so no money moved: deleting only removes it from the list.';
    }
    $lines[] = 'The deletion is recorded in the audit trail with your name, the date and time, and your reason.';
    return $lines;
}