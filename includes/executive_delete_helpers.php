<?php
/**
 * NEW FILE: includes/executive_delete_helpers.php
 * Task I: authorized deletion of Loans and Assets (including Fully Paid / fully processed ones).
 *
 *   - Only employees with the permission "loans.executive_delete" / "asset_management.executive_delete"
 *     (Roles & Permissions; at the start only the Managing Director), checked on the server.
 *   - A plan is built first and shown: every linked fund entry and transaction that will be reversed.
 *   - Everything is done in ONE database transaction: if any reversal fails, nothing changes.
 *   - The record is never erased: it is marked "Deleted" (out of every active list and total) and a frozen
 *     copy of it, the reason, the user, the time and every affected reference are kept in record_deletions.
 *   - Never twice: a deleted record is refused; each fund entry is reversed once (linked to its original).
 */
require_once __DIR__ . '/fund_helpers.php';
require_once __DIR__ . '/transaction_audit_helpers.php';
require_once __DIR__ . '/access_control.php';

const EXEC_DELETE_REASONS = [
    'Recorded by mistake',
    'Duplicate record',
    'Wrong amount or details (it will be recorded again correctly)',
    'Test record',
    'Other (explained below)',
];

/** Adds a value to an ENUM column if it is missing (keeps every existing value). */
function exec_enum_add(mysqli $conn, string $table, string $column, string $value): void
{
    $s = mysqli_prepare($conn, 'SELECT DATA_TYPE, COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    mysqli_stmt_bind_param($s, 'ss', $table, $column);
    mysqli_stmt_execute($s);
    $c = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if (!$c || strtolower($c['DATA_TYPE']) !== 'enum' || stripos($c['COLUMN_TYPE'], "'" . $value . "'") !== false) { return; }
    preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", $c['COLUMN_TYPE'], $m);
    $values = array_merge($m[1], [$value]);
    $list = implode(',', array_map(function ($v) use ($conn) { return "'" . mysqli_real_escape_string($conn, str_replace("''", "'", $v)) . "'"; }, $values));
    $default = $c['COLUMN_DEFAULT'] !== null ? " DEFAULT '" . mysqli_real_escape_string($conn, trim($c['COLUMN_DEFAULT'], "'")) . "'" : '';
    mysqli_query($conn, "ALTER TABLE `$table` MODIFY `$column` ENUM($list) " . ($c['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL') . $default);
}

function exec_delete_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    tx_audit_ensure($conn);
    foreach (['loans', 'assets'] as $t) {
        exec_enum_add($conn, $t, 'status', 'Deleted');
        foreach (['deleted_at' => 'DATETIME NULL', 'deleted_by' => 'INT(11) NULL', 'deleted_reason' => 'TEXT NULL'] as $c => $def) {
            $s = mysqli_prepare($conn, 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
            mysqli_stmt_bind_param($s, 'ss', $t, $c);
            mysqli_stmt_execute($s);
            if ((int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0] === 0) { mysqli_query($conn, "ALTER TABLE `$t` ADD COLUMN `$c` $def"); }
        }
    }
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS record_deletions (
        id INT(11) NOT NULL AUTO_INCREMENT, record_type VARCHAR(20) NOT NULL, record_id INT(11) NOT NULL,
        reference VARCHAR(40) NOT NULL, reason TEXT NOT NULL, snapshot LONGTEXT NOT NULL, affected TEXT NOT NULL,
        user_id INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY one_deletion (record_type, record_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function exec_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $s = mysqli_prepare($conn, $sql);
    if ($types !== '') { mysqli_stmt_bind_param($s, $types, ...$params); }
    mysqli_stmt_execute($s);
    return mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
}

/** Fund entries matching a condition that are not reversed yet (they will be). */
function exec_open_movements(mysqli $conn, string $where, string $types, array $params): array
{
    return exec_rows($conn, "SELECT m.id, m.fund_id, f.name AS fund, m.movement_type, m.direction, m.amount, m.description
        FROM fund_movements m JOIN funds f ON f.id = m.fund_id
        LEFT JOIN fund_movements r ON r.reverses_movement_id = m.id
        WHERE r.id IS NULL AND m.movement_type <> 'REVERSAL' AND $where ORDER BY m.id", $types, $params);
}

function exec_tx(mysqli $conn, int $id): ?array
{
    $r = exec_rows($conn, 'SELECT * FROM transactions WHERE id = ?', 'i', [$id]);
    return $r[0] ?? null;
}

/* ------------------------------------------------------------------------------------------ LOANS */

/** Plan for deleting a loan: ['loan', 'movements', 'transactions' => [tx + why], 'problems' => [...]] */
function exec_loan_plan(mysqli $conn, int $loanId): array
{
    exec_delete_ensure($conn);
    $loan = exec_rows($conn, 'SELECT * FROM loans WHERE id = ?', 'i', [$loanId])[0] ?? null;
    if (!$loan) { return ['loan' => null, 'problems' => ['Loan not found.']]; }
    $plan = ['loan' => $loan, 'movements' => [], 'transactions' => [], 'problems' => [], 'payments' => []];
    if ($loan['status'] === 'Deleted') { $plan['problems'][] = 'This loan has already been deleted.'; return $plan; }

    // 1. The money received (RM Capital Fund entries linked to the loan).
    // BINARY: 'loan' (money received) and 'LOAN' (repayments) must not be mixed up (the database ignores case).
    $plan['movements'] = exec_open_movements($conn, "BINARY m.ref_type = 'loan' AND m.ref_id = ?", 'i', [$loanId]);
    $claimed = [];
    if (array_key_exists('received_transaction_id', $loan) && !empty($loan['received_transaction_id'])) {
        $tx = exec_tx($conn, (int) $loan['received_transaction_id']);
        if ($tx && $tx['status'] !== 'deleted') { $plan['transactions'][] = $tx + ['why' => 'Loan money received']; $claimed[(int) $tx['id']] = true; }
        // Older loans: their money was put into the Capital Fund by the opening reconciliation (ref 'LOAN_TX').
        $plan['movements'] = array_merge($plan['movements'],
            exec_open_movements($conn, "m.ref_type = 'LOAN_TX' AND m.ref_id = ?", 'i', [(int) $loan['received_transaction_id']]));
    }

    // 2. Repayments. New ones are linked to the loan (ref 'LOAN'); older ones are matched by date, amount and lender.
    $linked = exec_rows($conn, "SELECT DISTINCT t.* FROM transactions t JOIN fund_movements m ON m.transaction_id = t.id
        WHERE BINARY m.ref_type = 'LOAN' AND m.ref_id = ? AND t.status <> 'deleted'", 'i', [$loanId]);
    $linkedByDate = [];
    foreach ($linked as $t) { $claimed[(int) $t['id']] = true; $linkedByDate[$t['transaction_date']][] = $t; $plan['transactions'][] = $t + ['why' => 'Repayment']; }

    $label = $loan['lender'] . ' (' . $loan['loan_type'] . ')';
    $payments = exec_rows($conn, 'SELECT * FROM loan_payments WHERE loan_id = ? ORDER BY payment_date, id', 'i', [$loanId]);
    $plan['payments'] = $payments;
    foreach ($payments as $p) {
        $amount = round((float) $p['amount'], 2);
        // Already covered by linked transactions of that date?
        $linkedSum = round(array_sum(array_map(function ($t) { return (float) $t['amount']; }, $linkedByDate[$p['payment_date']] ?? [])), 2);
        if ($linkedSum + 0.001 >= $amount) { $linkedByDate[$p['payment_date']] = []; continue; }

        $cands = exec_rows($conn, "SELECT * FROM transactions WHERE category = 'Loan Repayment' AND transaction_type = 'Expense' AND status <> 'deleted'
            AND transaction_date = ? AND (description LIKE ? OR description LIKE ? OR description LIKE ?) ORDER BY id", 'ssss',
            [$p['payment_date'], 'Loan interest paid to ' . $label . '%', 'Loan principal repaid to ' . $label . '%', 'Loan repayment to ' . $label . '%']);
        $cands = array_values(array_filter($cands, function ($t) use ($claimed) { return !isset($claimed[(int) $t['id']]); }));
        // Exactly one way to make up the payment's amount: all of them, one, or a pair.
        $options = [];
        $sumAll = round(array_sum(array_map(function ($t) { return (float) $t['amount']; }, $cands)), 2);
        if ($cands && abs($sumAll - $amount) < 0.011) { $options[] = $cands; }
        else {
            foreach ($cands as $i => $a) {
                if (abs((float) $a['amount'] - $amount) < 0.011) { $options[] = [$a]; }
                for ($j = $i + 1; $j < count($cands); $j++) {
                    if (abs((float) $a['amount'] + (float) $cands[$j]['amount'] - $amount) < 0.011) { $options[] = [$a, $cands[$j]]; }
                }
            }
        }
        $when = date('d M Y', strtotime($p['payment_date']));
        if (count($options) === 1) {
            foreach ($options[0] as $t) { $claimed[(int) $t['id']] = true; $plan['transactions'][] = $t + ['why' => 'Repayment of ' . $when]; }
        } elseif (!$options) {
            $plan['problems'][] = 'The transaction(s) of the repayment of ' . $when . ' (RWF ' . number_format($amount, 2) . ') were not found (they may have been deleted already). '
                . 'Check Transactions for "Loan Repayment" on that date before deleting this loan.';
        } else {
            $plan['problems'][] = 'The repayment of ' . $when . ' (RWF ' . number_format($amount, 2) . ') matches more than one set of transactions, so it cannot be identified with certainty. '
                . 'Delete the right "Loan Repayment" transactions of that date in Transactions first (they are listed there), then delete this loan.';
        }
    }
    return $plan;
}

/* ------------------------------------------------------------------------------------------ ASSETS */

function exec_asset_plan(mysqli $conn, int $assetId): array
{
    exec_delete_ensure($conn);
    $asset = exec_rows($conn, 'SELECT * FROM assets WHERE id = ?', 'i', [$assetId])[0] ?? null;
    if (!$asset) { return ['asset' => null, 'problems' => ['Asset not found.']]; }
    $plan = ['asset' => $asset, 'movements' => [], 'transactions' => [], 'problems' => [], 'disposals' => []];
    if ($asset['status'] === 'Deleted') { $plan['problems'][] = 'This asset has already been deleted.'; return $plan; }

    $disposals = exec_rows($conn, 'SELECT * FROM asset_disposals WHERE asset_id = ?', 'i', [$assetId]);
    $plan['disposals'] = $disposals;
    $dIds = array_map('intval', array_column($disposals, 'id'));
    // Value in the Capital Fund (in, depreciation, adjustments, out), the purchase payment, and sale / refund money.
    $plan['movements'] = exec_open_movements($conn, "((m.ref_type IN ('ASSET', 'ASSET_PAY') AND m.ref_id = ?)"
        . ($dIds ? " OR (m.ref_type IN ('ASSET_SALE', 'ASSET_REFUND') AND m.ref_id IN (" . implode(',', $dIds) . '))' : '') . ')', 'i', [$assetId]);

    $txIds = [];
    if (!empty($asset['funding_transaction_id'])) { $txIds[(int) $asset['funding_transaction_id']] = 'Purchase payment'; }
    foreach ($disposals as $d) { if (!empty($d['transaction_id'])) { $txIds[(int) $d['transaction_id']] = $d['disposal_type'] . ' gain / loss'; } }
    foreach ($txIds as $tid => $why) {
        $tx = exec_tx($conn, $tid);
        if ($tx && $tx['status'] !== 'deleted') { $plan['transactions'][] = $tx + ['why' => $why]; }
    }
    return $plan;
}

/* ------------------------------------------------------------------------------------------ EXECUTE */

/**
 * Deletes a loan or an asset following its plan. Own database transaction: all or nothing.
 * Returns a summary sentence. Throws RuntimeException with the exact reason when it refuses.
 */
function exec_delete(mysqli $conn, string $type, int $id, string $reason, int $userId): string
{
    exec_delete_ensure($conn);
    $perm = $type === 'loan' ? 'loans.executive_delete' : 'asset_management.executive_delete';
    if (!access_can($perm, $conn)) { throw new RuntimeException('You are not authorized to delete ' . ($type === 'loan' ? 'loans' : 'assets') . '.'); }
    if (mb_strlen(trim($reason)) < 10) { throw new RuntimeException('A clear reason is required (at least 10 characters).'); }

    mysqli_begin_transaction($conn);
    try {
        $table = $type === 'loan' ? 'loans' : 'assets';
        $lock = mysqli_prepare($conn, "SELECT status FROM `$table` WHERE id = ? FOR UPDATE");
        mysqli_stmt_bind_param($lock, 'i', $id);
        mysqli_stmt_execute($lock);
        if (!mysqli_fetch_row(mysqli_stmt_get_result($lock))) { throw new RuntimeException('Record not found.'); }

        $plan = $type === 'loan' ? exec_loan_plan($conn, $id) : exec_asset_plan($conn, $id);
        if ($plan['problems']) { throw new RuntimeException(implode(' ', $plan['problems'])); }
        $record = $type === 'loan' ? $plan['loan'] : $plan['asset'];
        $ref = $type === 'loan' ? 'LN-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT) : (string) $record['asset_code'];
        $note = $ref . ' deleted: ' . $reason;
        foreach (array_unique(array_column($plan['movements'], 'fund_id')) as $fid) { fund_lock($conn, (int) $fid); }

        $affected = [];
        // 1. Fund entries of the record itself (each reversed once, linked to its original).
        foreach ($plan['movements'] as $m) {
            $n = fund_reverse_movements($conn, 'm.id = ?', 'i', [(int) $m['id']], $userId, $note);
            if ($n !== 1) { throw new RuntimeException('Fund entry #' . $m['id'] . ' could not be reversed (it may have been reversed already). Nothing was changed.'); }
            $affected[] = 'Fund entry #' . $m['id'] . ' (' . $m['fund'] . ', ' . $m['movement_type'] . ' ' . $m['direction'] . ' RWF ' . number_format((float) $m['amount'], 2) . ') reversed';
        }
        // 2. Linked transactions: their fund entries reversed, the transaction marked deleted (audit trail).
        $upd = mysqli_prepare($conn, "UPDATE transactions SET status = 'deleted', deleted_by = ?, deleted_at = NOW() WHERE id = ? AND status <> 'deleted'");
        foreach ($plan['transactions'] as $tx) {
            $tid = (int) $tx['id'];
            fund_reverse_movements($conn, 'm.transaction_id = ?', 'i', [$tid], $userId, $note);
            mysqli_stmt_bind_param($upd, 'ii', $userId, $tid);
            mysqli_stmt_execute($upd);
            if (mysqli_stmt_affected_rows($upd) !== 1) { throw new RuntimeException(tx_reference($tid) . ' could not be marked deleted. Nothing was changed.'); }
            tx_audit_log($conn, $tx, 'Deleted with ' . $ref, $reason, $tx['why'] . ' of ' . $ref . '; its fund entries reversed.', $userId);
            $affected[] = tx_reference($tid) . ' (' . $tx['why'] . ', RWF ' . number_format((float) $tx['amount'], 2) . ') deleted';
        }
        // 3. The record: marked Deleted (out of every list and total), never erased.
        $mark = mysqli_prepare($conn, "UPDATE `$table` SET status = 'Deleted', deleted_at = NOW(), deleted_by = ?, deleted_reason = ? WHERE id = ? AND status <> 'Deleted'");
        mysqli_stmt_bind_param($mark, 'isi', $userId, $reason, $id);
        mysqli_stmt_execute($mark);
        if (mysqli_stmt_affected_rows($mark) !== 1) { throw new RuntimeException('The record could not be marked deleted. Nothing was changed.'); }

        // 4. The audit trail: a frozen copy of the record and everything related.
        $snapshot = ['record' => $record];
        if ($type === 'loan') {
            $snapshot['schedule'] = exec_rows($conn, 'SELECT * FROM loan_schedule WHERE loan_id = ? ORDER BY installment_no', 'i', [$id]);
            $snapshot['payments'] = $plan['payments'];
        } else {
            $snapshot['disposals'] = $plan['disposals'];
            try { $snapshot['valuations'] = exec_rows($conn, 'SELECT * FROM asset_valuations WHERE asset_id = ?', 'i', [$id]); } catch (Throwable $e) { $snapshot['valuations'] = []; }
        }
        $snapshot['transactions'] = $plan['transactions'];
        $snapshot['fund_entries'] = $plan['movements'];
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $affectedText = $affected ? implode("\n", $affected) : 'No linked fund entries or transactions.';
        $ins = mysqli_prepare($conn, 'INSERT INTO record_deletions (record_type, record_id, reference, reason, snapshot, affected, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($ins, 'sissssi', $type, $id, $ref, $reason, $json, $affectedText, $userId);
        mysqli_stmt_execute($ins);

        mysqli_commit($conn);
        return $ref . ' deleted. ' . count($plan['movements']) . ' fund entr' . (count($plan['movements']) === 1 ? 'y' : 'ies') . ' and '
            . count($plan['transactions']) . ' transaction(s) reversed. Recorded in the audit trail.';
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e instanceof RuntimeException && !($e instanceof mysqli_sql_exception) ? $e
            : new RuntimeException('Unable to delete. Nothing was changed. Reason: ' . $e->getMessage());
    }
}