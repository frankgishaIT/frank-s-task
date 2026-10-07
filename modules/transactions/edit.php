<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require '../../includes/fund_helpers.php';

// Admin-only action
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?success=' . urlencode('You do not have permission to edit transactions.'));
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid transaction selected.'); exit; }
$recordStatement = mysqli_prepare($conn, 'SELECT * FROM transactions WHERE id = ?');
mysqli_stmt_bind_param($recordStatement, 'i', $id);
mysqli_stmt_execute($recordStatement);
$transaction = mysqli_fetch_assoc(mysqli_stmt_get_result($recordStatement));
if (!$transaction) { header('Location: index.php?success=Transaction not found.'); exit; }

// An expense that is linked to a fund (approved = already in the ledger,
// pending = money reserved) must keep its amount, date, type and fund.
// Changing them would make the fund balance wrong. To correct one, delete it
// (which returns the money to the fund) and record it again.
// The type is always locked, because switching Income <-> Expense would
// need a fund and a ledger entry.
$fundLocked = $transaction['transaction_type'] === 'Expense'
    && !empty($transaction['fund_id'])
    && in_array($transaction['status'], ['approved', 'pending'], true);
$fundRow = !empty($transaction['fund_id']) ? fund_get($conn, (int) $transaction['fund_id']) : null;
$categoryList = fund_expense_categories();

if (isset($_POST['update'])) {
    $category = $_POST['category'] ?? '';
    $type = $transaction['transaction_type']; // locked
    $description = trim($_POST['description'] ?? '');
    $recordedBy = filter_input(INPUT_POST, 'recorded_by', FILTER_VALIDATE_INT) ?: null;

    if ($fundLocked) {
        $amount = (float) $transaction['amount'];
        $transactionDate = $transaction['transaction_date'];
    } else {
        $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
        $transactionDate = $_POST['transaction_date'] ?? '';
    }
    $validDate = DateTime::createFromFormat('Y-m-d', $transactionDate);

    $expenseCategory = null;
    if ($type === 'Expense') {
        $expenseCategory = trim($_POST['expense_category'] ?? '');
        if ($expenseCategory === '') {
            $expenseCategory = null;
        }
    }

    if (!in_array($category, ['Product', 'Service'], true) || $amount === false || $amount <= 0 || !$validDate || $validDate->format('Y-m-d') !== $transactionDate) {
        $error = 'Please enter a category, positive amount, and valid date.';
    } elseif ($expenseCategory !== null && !in_array($expenseCategory, $categoryList, true)) {
        $error = 'Invalid expense category.';
    } else {
        $statement = mysqli_prepare($conn, 'UPDATE transactions SET category = ?, amount = ?, transaction_type = ?, description = ?, transaction_date = ?, recorded_by = ?, expense_category = ? WHERE id = ?');
        mysqli_stmt_bind_param($statement, 'sdsssisi', $category, $amount, $type, $description, $transactionDate, $recordedBy, $expenseCategory, $id);
        mysqli_stmt_execute($statement);
        header('Location: index.php?success=Transaction updated successfully.'); exit;
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

            <?php if ($fundLocked) { ?>
            <div class="alert alert-info d-flex align-items-start gap-2 mb-3" style="border-radius:10px; border:none; font-size:13px; padding:10px 14px;">
                <i class="bi bi-lock-fill"></i>
                <div>This expense is linked to <strong><?= htmlspecialchars($fundRow['name'] ?? 'a Fund', ENT_QUOTES, 'UTF-8'); ?></strong>, so its type, amount and date are locked to keep the Fund balance correct. To change the amount, delete it (the money returns to the Fund) and record it again.</div>
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
                        <input type="number" name="amount" class="form-control rm-input" min="0.01" step="0.01" value="<?= htmlspecialchars((string) $transaction['amount'], ENT_QUOTES, 'UTF-8'); ?>" <?= $fundLocked ? 'readonly' : ''; ?> required>
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
                    <input type="date" name="transaction_date" class="form-control rm-input" value="<?= htmlspecialchars($transaction['transaction_date'], ENT_QUOTES, 'UTF-8'); ?>" <?= $fundLocked ? 'readonly' : ''; ?> required>
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