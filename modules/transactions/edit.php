<?php
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../../includes/session_boot.php'; rm_session_start(); /* CHANGED: one staff session (RMSTAFF) */
}
require '../../config/db.php';
require '../../includes/fund_helpers.php';
require_once '../../includes/income_allocation_helpers.php'; // NEW: manual Income -> four Funds
require_once '../../includes/category_helpers.php'; // NEW (Task 3): Admin-managed categories

// Admin-only action
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
// CHANGED: problems are sent as "error" (they were sent as "success"), and all messages are urlencoded.
if (!$isAdmin) {
    header('Location: index.php?error=' . urlencode('You do not have permission to edit transactions.'));
    exit;
}
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid transaction selected.')); exit; }

// CHANGED: also finds the sale the transaction belongs to (if any).
$recordStatement = mysqli_prepare($conn, 'SELECT t.*, s.id AS linked_sale_id FROM transactions t LEFT JOIN sales s ON s.transaction_id = t.id WHERE t.id = ?');
mysqli_stmt_bind_param($recordStatement, 'i', $id);
mysqli_stmt_execute($recordStatement);
$transaction = mysqli_fetch_assoc(mysqli_stmt_get_result($recordStatement));
if (!$transaction) { header('Location: index.php?error=' . urlencode('Transaction not found.')); exit; }

// NEW (spec section 3): only MANUAL transactions can be edited. Automatic ones (sale income,
// purchase orders, re-stock, asset gain/loss) are changed through their own module, otherwise
// the sale, stock or fund would no longer match. The list page hides the button; this blocks
// the page itself too.
if ((int) $transaction['is_automatic'] === 1 || !empty($transaction['linked_sale_id'])) {
    header('Location: index.php?error=' . urlencode('This transaction was created automatically. Change it from its own module (for example, cancel the sale or purchase order).'));
    exit;
}

// NEW: a deleted transaction is kept only for the history and cannot be edited.
if ($transaction['status'] === 'deleted') {
    header('Location: index.php?error=' . urlencode('This transaction has been deleted and cannot be edited.'));
    exit;
}

// CHANGED (spec section 3): the amount and date of an expense paid from a Fund CAN now be
// edited. The Fund is updated automatically: the old ledger entry is reversed (linked to it)
// and the corrected one is recorded, so the history stays complete.
// The type and the fund stay locked (switching them would move money between funds).
$isFundExpense = $transaction['transaction_type'] === 'Expense' && !empty($transaction['fund_id']);
$fundRow = !empty($transaction['fund_id']) ? fund_get($conn, (int) $transaction['fund_id']) : null;
// CHANGED (Task 3): managed by Admins. The record's own category stays available even if it was deactivated.
$categoryList = categories_active($conn, 'expense', $transaction['expense_category'] ?? null);
// NEW (Financial Accounts): the account and the reference can be corrected; the fund entries follow the account.
require_once '../../includes/fin_accounts.php';
fa_ensure($conn);
$faAccounts = fa_accounts($conn, true);

if (isset($_POST['update'])) {
    $category = $_POST['category'] ?? '';
    $type = $transaction['transaction_type']; // locked
    $description = trim($_POST['description'] ?? '');
    $recordedBy = filter_input(INPUT_POST, 'recorded_by', FILTER_VALIDATE_INT) ?: null;
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $amount = ($amount === false || $amount === null) ? $amount : round($amount, 2);
    $transactionDate = $_POST['transaction_date'] ?? '';
    $validDate = DateTime::createFromFormat('Y-m-d', $transactionDate);

    $expenseCategory = null;
    if ($type === 'Expense') {
        $expenseCategory = trim($_POST['expense_category'] ?? '');
        if ($expenseCategory === '') {
            $expenseCategory = null;
        }
    }

    if (!in_array($category, ['Product', 'Service'], true) || $amount === false || $amount === null || $amount <= 0 || !$validDate || $validDate->format('Y-m-d') !== $transactionDate) {
        $error = 'Please enter a category, positive amount, and valid date.';
    } elseif ($transactionDate > date('Y-m-d')) {
        $error = 'The date cannot be in the future.';
    } elseif ($type === 'Expense' && $expenseCategory === null) {
        // CHANGED (Task 3): an expense must always have its category.
        $error = 'Please choose the Expense Category.';
    } elseif ($expenseCategory !== null && !category_is_valid($conn, 'expense', $expenseCategory, $transaction['expense_category'] ?? null)) {
        $error = 'Please choose an Expense Category from the list.';
    } elseif (($transaction['status'] ?? '') === 'approved' && trim((string) ($_POST['edit_reason'] ?? '')) === ''
        && (abs((float) $transaction['amount'] - (float) $amount) >= 0.01 || $transaction['transaction_date'] !== $transactionDate || $transaction['transaction_type'] !== $type
            || $transaction['category'] !== $category || (string) ($transaction['expense_category'] ?? '') !== (string) ($expenseCategory ?? ''))) {
        // NEW (Task 2, 2.9): a POSTED transaction is never corrected silently: the reason is kept in the audit history.
        $error = 'This transaction is already posted: give the reason for the correction.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            $fundId = $isFundExpense ? (int) $transaction['fund_id'] : null;
            if ($fundId) {
                fund_lock($conn, $fundId);
            }

            // Re-read under a lock so two edits cannot run at the same time.
            $lock = mysqli_prepare($conn, 'SELECT * FROM transactions WHERE id = ? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 'i', $id);
            mysqli_stmt_execute($lock);
            $current = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));

            $oldAmount = round((float) $current['amount'], 2);
            $amountChanged = abs($oldAmount - $amount) >= 0.01;
            $dateChanged = $current['transaction_date'] !== $transactionDate;

            if ($fundId && in_array($current['status'], ['approved', 'pending'], true)) {
                // A higher amount must still fit in the fund. For a pending expense the old amount
                // is already reserved, so only the increase is checked in both cases.
                $increase = round($amount - $oldAmount, 2);
                if ($increase > 0 && $increase > fund_available($conn, $fundId) + 0.001) {
                    throw new InsufficientFundException(fund_insufficient_message($fundRow['name'] ?? 'Fund'));
                }
            }

            // NEW (Financial Accounts): account + reference (the linked fund entries move to the new account, with an audit line)
            $newAcc = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT) ?: null; $newRef = mb_substr(trim((string) ($_POST['reference'] ?? '')), 0, 80); $newRef = $newRef !== '' ? $newRef : null;
            if ($faAccounts && $newAcc && !in_array($newAcc, array_map('intval', array_column($faAccounts, 'id')), true)) { throw new RuntimeException('Choose an active financial account.'); }
            if ((int) ($transaction['account_id'] ?? 0) !== (int) $newAcc || (string) ($transaction['reference'] ?? '') !== (string) $newRef) {
                $fa = mysqli_prepare($conn, 'UPDATE transactions SET account_id = ?, reference = ? WHERE id = ?'); $tid = (int) $id; mysqli_stmt_bind_param($fa, 'isi', $newAcc, $newRef, $tid); mysqli_stmt_execute($fa);
                if ((int) ($transaction['account_id'] ?? 0) !== (int) $newAcc) {
                    if ($newAcc) { mysqli_query($conn, "UPDATE fa_movement_accounts SET account_id = " . (int) $newAcc . ", rule = 'transaction' WHERE movement_id IN (SELECT id FROM fund_movements WHERE transaction_id = $tid OR (ref_type = 'TX_INCOME' AND ref_id = $tid))"); }
                    else { mysqli_query($conn, "DELETE FROM fa_movement_accounts WHERE movement_id IN (SELECT id FROM fund_movements WHERE transaction_id = $tid OR (ref_type = 'TX_INCOME' AND ref_id = $tid))"); }
                    fa_audit($conn, 'transaction', $tid, 'account_changed', 'Transaction #' . $tid . ': account ' . ((fa_account($conn, (int) ($transaction['account_id'] ?? 0))['name'] ?? 'none')) . ' → ' . ($newAcc ? (fa_account($conn, (int) $newAcc)['name'] ?? '') : 'none'));
                }
            }
            $statement = mysqli_prepare($conn, 'UPDATE transactions SET category = ?, amount = ?, transaction_type = ?, description = ?, transaction_date = ?, recorded_by = ?, expense_category = ? WHERE id = ?');
            mysqli_stmt_bind_param($statement, 'sdsssisi', $category, $amount, $type, $description, $transactionDate, $recordedBy, $expenseCategory, $id);
            mysqli_stmt_execute($statement);

            // NEW (cross-task audit trail): every edit is recorded in the transaction audit (who, when, what changed).
            $chg = [];
            foreach (['category' => $category, 'amount' => $amount, 'transaction_type' => $type, 'description' => $description, 'transaction_date' => $transactionDate, 'expense_category' => $expenseCategory] as $k => $nv) {
                $ov = $current[$k] ?? '';
                if ($k === 'amount' ? abs((float) $ov - (float) $nv) >= 0.01 : (string) $ov !== (string) ($nv ?? '')) { $chg[] = $k . ': ' . ($k === 'amount' ? number_format((float) $ov) : $ov) . ' → ' . ($k === 'amount' ? number_format((float) $nv) : $nv); }
            }
            if ($chg) {
                require_once __DIR__ . '/../../includes/transaction_audit_helpers.php';
                tx_audit_log($conn, ['id' => (int) $id, 'amount' => $amount, 'transaction_type' => $type, 'category' => $category], 'Edited', mb_substr(trim((string) ($_POST['edit_reason'] ?? '')), 0, 500), implode('; ', $chg) . (($current['status'] ?? '') === 'approved' && ($amountChanged || $dateChanged) ? ' (posted: fund entries reversed and re-recorded)' : ''), $userId);
            }

            // An APPROVED fund expense is in the fund ledger: reverse the old entry and record
            // the corrected one. (A pending one is only a reservation, read from this row.)
            if ($fundId && $current['status'] === 'approved' && ($amountChanged || $dateChanged)) {
                $reversed = fund_reverse_movements($conn, 'm.transaction_id = ?', 'i', [(int) $id], $userId,
                    'Transaction #' . $id . ' edited');
                // Only re-post when the old expense was really in the ledger (expenses recorded
                // before the Funds existed never reduced any fund).
                if ($reversed > 0) {
                    $desc = mb_substr(($description !== '' ? $description : 'Expense') . ' (edited)', 0, 255);
                    fund_record_movement($conn, $fundId, 'EXPENSE', 'OUT', $amount, substr($transactionDate, 0, 7),
                        (int) $id, $userId, $desc);
                }
            }

            // NEW (spec sections 1 and 3): an APPROVED Income was allocated to the four Funds.
            // When its amount changes, the old allocation is reversed and the new amount allocated.
            // (Income recorded before this feature was never allocated, so nothing is re-posted.)
            $incomeReallocated = false;
            if ($type === 'Income' && $current['status'] === 'approved' && $amountChanged) {
                if (reverse_income_allocation($conn, (int) $id, $userId, 'edited') > 0) {
                    allocate_income_transaction($conn, (int) $id, $amount, $userId);
                    $incomeReallocated = true;
                }
            }

            mysqli_commit($conn);
            header('Location: index.php?success=' . urlencode('Transaction updated successfully.' . ($incomeReallocated ? ' The Funds were updated automatically.' : '') . ($fundId && $amountChanged ? ' ' . ($fundRow['name'] ?? 'The fund') . ' was updated automatically.' : '')));
            exit;
        } catch (InsufficientFundException $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('transaction edit failed for #' . $id . ': ' . $e->getMessage());
            $error = 'Unable to update the transaction. Nothing was changed.';
        }
    }
    // Keep fund_id, status etc. so the form still works after a validation error.
    $transaction = array_merge($transaction, [
        'category' => $category,
        'amount' => $amount,
        'transaction_type' => $type,
        'description' => $description,
        'transaction_date' => $transactionDate,
        'recorded_by' => $recordedBy,
        'expense_category' => $expenseCategory,
    ]);
}

$employees = mysqli_query($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names');
include '../../includes/header.php'; include '../../includes/sidebar.php';

$modal_icon = 'bi-cash-stack';
$modal_title = 'Edit Transaction';
$modal_subtitle = 'Update this transaction\'s details.';
?>

<div class="rm-modal-backdrop">
    <div class="rm-modal">
        <?php include '../../includes/model_header.php'; ?>

        <div class="rm-modal-body">
            <?php if (isset($error)) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php } ?>

            <?php if ($isFundExpense) { ?>
            <!-- CHANGED: the amount and date can be corrected; the fund follows automatically. -->
            <div class="alert alert-info d-flex align-items-start gap-2 mb-3" style="border-radius:10px; border:none; font-size:13px; padding:10px 14px;">
                <i class="bi bi-info-circle-fill"></i>
                <div>This expense is paid from <strong><?= htmlspecialchars($fundRow['name'] ?? 'a Fund', ENT_QUOTES, 'UTF-8'); ?></strong>.
                    If you change the amount or date, the Fund is updated automatically and the original entry stays in its history.
                    The type and fund cannot be changed.</div>
            </div>
            <?php } ?>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Category</label>
                    <select name="category" class="form-select rm-input" required>
                        <option value="Product" <?= $transaction['category'] === 'Product' ? 'selected' : ''; ?>>Product</option>
                        <option value="Service" <?= $transaction['category'] === 'Service' ? 'selected' : ''; ?>>Service</option>
                    </select>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Type</label>
                        <select name="transaction_type" class="form-select rm-input" disabled>
                            <?php foreach (['Income', 'Expense'] as $option) { ?>
                                <option value="<?= $option; ?>" <?= $transaction['transaction_type'] === $option ? 'selected' : ''; ?>><?= $option; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Amount (RWF)</label>
                        <input type="number" name="amount" class="form-control rm-input" min="0.01" step="0.01" value="<?= htmlspecialchars((string) $transaction['amount'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                </div>

                <?php if ($transaction['transaction_type'] === 'Expense') { ?>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Fund</label>
                        <input type="text" class="form-control rm-input" value="<?= htmlspecialchars($fundRow['name'] ?? 'Not assigned (recorded before Funds)', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Expense Category <span class="text-danger">*</span></label>
                        <select name="expense_category" class="form-select rm-input" <?= $transaction['transaction_type'] === 'Expense' ? 'required' : ''; ?>>
                            <option value="">Select category</option>
                            <?php foreach ($categoryList as $c) { ?>
                            <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>" <?= (($transaction['expense_category'] ?? '') === $c) ? 'selected' : ''; ?>><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <?php } ?>

                <!-- NEW (Financial Accounts) -->
                <div class="row g-3 mb-3">
                    <div class="col-md-6"><label class="form-label small fw-semibold text-muted">Financial account</label>
                        <select name="account_id" class="form-select rm-input"><option value="">—</option><?php foreach ($faAccounts as $fa) { ?><option value="<?= (int) $fa['id']; ?>" <?= (int) ($_POST['account_id'] ?? $transaction['account_id'] ?? 0) === (int) $fa['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($fa['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php } ?></select></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold text-muted">Reference</label><input name="reference" maxlength="80" class="form-control rm-input" value="<?= htmlspecialchars((string) ($_POST['reference'] ?? $transaction['reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Transaction Date</label>
                    <input type="date" name="transaction_date" class="form-control rm-input" max="<?= date('Y-m-d'); ?>" value="<?= htmlspecialchars($transaction['transaction_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Description</label>
                    <textarea name="description" class="form-control rm-input" rows="4" style="height:auto;"><?= htmlspecialchars($transaction['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Recorded By</label>
                    <select name="recorded_by" class="form-select rm-input">
                        <option value="">Not specified</option>
                        <?php while ($employee = mysqli_fetch_assoc($employees)) { ?>
                            <option value="<?= (int) $employee['id']; ?>" <?= (int) $transaction['recorded_by'] === (int) $employee['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($employee['names'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>

                <?php if (($transaction['status'] ?? '') === 'approved') { /* NEW (Task 2, 2.9): reason for correcting a posted transaction */ ?>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Reason for the correction <span class="text-danger">*</span></label>
                    <input name="edit_reason" maxlength="500" class="form-control rm-input" value="<?= htmlspecialchars((string) ($_POST['edit_reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Needed when you change the amount, date, type or category">
                    <div class="form-text">This transaction is posted. The change and its reason are kept in the transaction audit history.</div>
                </div>
                <?php } ?>
                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="btn btn-primary rm-btn-primary" type="submit" name="update">
                        <i class="bi bi-check-circle-fill me-2"></i>Update Transaction
                    </button>
                    <a href="index.php" class="btn btn-light rm-btn-light">Cancel</a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>