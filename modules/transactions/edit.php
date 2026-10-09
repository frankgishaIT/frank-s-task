<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require '../../includes/fund_helpers.php';

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
$categoryList = fund_expense_categories();

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
    } elseif ($expenseCategory !== null && !in_array($expenseCategory, $categoryList, true)) {
        $error = 'Invalid expense category.';
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

            $statement = mysqli_prepare($conn, 'UPDATE transactions SET category = ?, amount = ?, transaction_type = ?, description = ?, transaction_date = ?, recorded_by = ?, expense_category = ? WHERE id = ?');
            mysqli_stmt_bind_param($statement, 'sdsssisi', $category, $amount, $type, $description, $transactionDate, $recordedBy, $expenseCategory, $id);
            mysqli_stmt_execute($statement);

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

            mysqli_commit($conn);
            header('Location: index.php?success=' . urlencode('Transaction updated successfully.' . ($fundId && $amountChanged ? ' ' . ($fundRow['name'] ?? 'The fund') . ' was updated automatically.' : '')));
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
                        <label class="form-label small fw-semibold text-muted">Expense Category</label>
                        <select name="expense_category" class="form-select rm-input">
                            <option value="">Select category (optional)</option>
                            <?php foreach ($categoryList as $c) { ?>
                            <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>" <?= (($transaction['expense_category'] ?? '') === $c) ? 'selected' : ''; ?>><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <?php } ?>

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