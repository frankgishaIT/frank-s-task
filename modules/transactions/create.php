<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require '../../includes/notification_helper.php';
require '../../includes/business_party_helpers.php';
require '../../includes/fund_helpers.php';

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
$payeeList = business_parties_of_type($conn, 'Payee');
$partnerList = business_parties_of_type($conn, 'Partner');
$fundList = funds_all($conn);
$categoryList = fund_expense_categories();

if (isset($_POST['save'])) {
    $category = $_POST['category'] ?? '';
    $type = $_POST['transaction_type'] ?? '';
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $transactionDate = $_POST['transaction_date'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $partyId = filter_input(INPUT_POST, 'party_id', FILTER_VALIDATE_INT) ?: null;
    $fundId = filter_input(INPUT_POST, 'fund_id', FILTER_VALIDATE_INT) ?: null;
    $expenseCategory = trim($_POST['expense_category'] ?? '');
    $recordedBy = $_SESSION['user_id'] ?? null;
    $validDate = DateTime::createFromFormat('Y-m-d', $transactionDate);

    if (!in_array($category, ['Product', 'Service'], true) || !in_array($type, ['Income', 'Expense'], true) || $amount === false || $amount <= 0 || !$validDate || $validDate->format('Y-m-d') !== $transactionDate) {
        $error = 'Please provide a valid category, type, amount, and date.';
    } elseif ($type === 'Expense' && (!$fundId || !fund_get($conn, $fundId))) {
        $error = 'Please select the Fund this expense will be paid from.';
    } elseif ($type === 'Expense' && $expenseCategory !== '' && !in_array($expenseCategory, $categoryList, true)) {
        $error = 'Invalid expense category.';
    } else {
        if ($type === 'Income') { $fundId = null; $expenseCategory = null; }
        if ($expenseCategory === '') { $expenseCategory = null; }

        // Income is always auto-approved. Expenses need admin approval
        // unless the person recording it is already an admin.
        $status = ($isAdmin || $type === 'Income') ? 'approved' : 'pending';

        try {
            mysqli_begin_transaction($conn);

            if ($type === 'Expense') {
                fund_lock($conn, $fundId);
                $fund = fund_get($conn, $fundId);
                if ($amount > fund_available($conn, $fundId)) {
                    throw new InsufficientFundException('No Available Money in ' . $fund['name'] . '.');
                }
            }

            $statement = mysqli_prepare($conn, 'INSERT INTO transactions (category, transaction_type, amount, transaction_date, description, recorded_by, status, party_id, fund_id, expense_category) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($statement, 'ssdssisiis', $category, $type, $amount, $transactionDate, $description, $recordedBy, $status, $partyId, $fundId, $expenseCategory);
            mysqli_stmt_execute($statement);
            $newId = mysqli_insert_id($conn);

            // Approved expense = money leaves the fund now.
            // Pending expense = only reserved; the ledger entry is written on approval.
            if ($type === 'Expense' && $status === 'approved') {
                fund_record_movement(
                    $conn, $fundId, 'EXPENSE', 'OUT', $amount,
                    substr($transactionDate, 0, 7), $newId, $recordedBy,
                    $expenseCategory ?: ($description ?: 'Expense')
                );
            }

            mysqli_commit($conn);

            if ($status === 'pending') {
                $admins = mysqli_query($conn, "SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
                while ($admin = mysqli_fetch_assoc($admins)) {
                    notifyUser(
                        $conn,
                        $admin['id'],
                        'Transaction Approval Needed',
                        'A new ' . strtolower($type) . ' of RWF ' . number_format($amount, 2) . ' is awaiting your approval (#' . $newId . ').'
                    );
                }
                header('Location: index.php?success=Transaction submitted for admin approval.');
            } else {
                header('Location: index.php?success=Transaction recorded successfully.');
            }
            exit;
        } catch (InsufficientFundException $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $error = 'Unable to save the transaction.';
        }
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';

$modal_icon = 'bi-cash-coin';
$modal_title = 'Add Transaction';
$modal_subtitle = $isAdmin ? 'Record a new income or expense entry.' : 'Submit an entry for admin approval.';
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

            <?php if (!$isAdmin) { ?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; font-size:13px; padding:10px 14px;">
    <i class="bi bi-info-circle-fill"></i>
    Income is recorded right away. Expenses are sent to an admin for approval before they appear in totals.
</div>
<?php } ?>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Category</label>
                    <select name="category" class="form-select rm-input" required>
                        <option value="">Select category</option>
                        <option value="Product" <?= ($category ?? '') === 'Product' ? 'selected' : ''; ?>>Product</option>
                        <option value="Service" <?= ($category ?? '') === 'Service' ? 'selected' : ''; ?>>Service</option>
                    </select>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Type</label>
                        <select name="transaction_type" id="transactionTypeSelect" class="form-select rm-input" required>
                            <?php foreach (['Income', 'Expense'] as $option) { ?>
                                <option value="<?= $option; ?>" <?= ($type ?? 'Expense') === $option ? 'selected' : ''; ?>><?= $option; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Amount (RWF)</label>
                        <input type="number" name="amount" class="form-control rm-input" min="0.01" step="0.01" value="<?= htmlspecialchars(isset($amount) && $amount !== false ? (string) $amount : '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                </div>

                <!-- FUND SELECTION + EXPENSE CATEGORY (Expense only) -->
                <div id="expenseFields">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted">Select Fund</label>
                        <select name="fund_id" id="fundSelect" class="form-select rm-input">
                            <option value="">Select fund</option>
                            <?php foreach ($fundList as $f) { ?>
                            <option value="<?= (int) $f['id']; ?>" <?= (isset($fundId) && $fundId == $f['id']) ? 'selected' : ''; ?>><?= htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php } ?>
                        </select>
                        <div id="fundBalance" class="small fw-semibold mt-1"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted">Expense Category</label>
                        <select name="expense_category" class="form-select rm-input">
                            <option value="">Select category (optional)</option>
                            <?php foreach ($categoryList as $c) { ?>
                            <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>" <?= (($expenseCategory ?? '') === $c) ? 'selected' : ''; ?>><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Party (optional)</label>
                    <select name="party_id" id="partySelect" class="form-select rm-input">
                        <option value="">None</option>
                        <?php foreach ($payeeList as $p) { ?>
                        <option value="<?= (int) $p['id']; ?>" data-for="Expense" <?= (isset($partyId) && $partyId == $p['id']) ? 'selected' : ''; ?>><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?> (RM Payee)</option>
                        <?php } ?>
                        <?php foreach ($partnerList as $p) { ?>
                        <option value="<?= (int) $p['id']; ?>" data-for="Income" <?= (isset($partyId) && $partyId == $p['id']) ? 'selected' : ''; ?>><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?> (RM Partner)</option>
                        <?php } ?>
                    </select>
                    <small class="text-muted">Filtered automatically: Expense → RM Payees, Income → RM Partners.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Transaction Date</label>
                    <input type="date" name="transaction_date" class="form-control rm-input" value="<?= htmlspecialchars($transactionDate ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Description</label>
                    <textarea name="description" class="form-control rm-input" rows="4" style="height:auto;"><?= htmlspecialchars($description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button type="submit" name="save" class="rm-btn rm-btn-primary">
                        <i class="bi bi-check-circle-fill me-2"></i><?= $isAdmin ? 'Save Transaction' : 'Submit for Approval'; ?>
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-secondary">
                        <i class="bi bi-x-circle-fill me-2"></i>Cancel
                    </a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<script>
(function () {
    var typeSelect = document.getElementById('transactionTypeSelect');
    var partySelect = document.getElementById('partySelect');
    var options = Array.prototype.slice.call(partySelect.querySelectorAll('option[data-for]'));

    function filterParties() {
        var current = typeSelect.value;
        var matched = false;
        options.forEach(function (opt) {
            var show = opt.getAttribute('data-for') === current;
            opt.hidden = !show;
            if (show && opt.value === partySelect.value) { matched = true; }
        });
        if (!matched) { partySelect.value = ''; }
    }

    typeSelect.addEventListener('change', filterParties);
    filterParties();

    // ---- Fund selection ----
    var expenseFields = document.getElementById('expenseFields');
    var fundSelect = document.getElementById('fundSelect');
    var fundBalance = document.getElementById('fundBalance');
    var amountInput = document.querySelector('input[name="amount"]');
    var currentBalance = null;

    function toggleExpenseFields() {
        var isExpense = typeSelect.value === 'Expense';
        expenseFields.style.display = isExpense ? '' : 'none';
        fundSelect.required = isExpense;
        if (!isExpense) { fundSelect.value = ''; fundBalance.textContent = ''; currentBalance = null; }
    }

    function checkAmount() {
        if (currentBalance === null || typeSelect.value !== 'Expense') { return; }
        var amt = parseFloat(amountInput.value) || 0;
        if (amt > currentBalance) {
            fundBalance.style.color = 'var(--accent-red)';
            fundBalance.textContent = 'No Available Money. Available Balance: ' + currentBalance.toLocaleString() + ' Frw';
        } else {
            fundBalance.style.color = '';
            fundBalance.textContent = 'Available Balance: ' + currentBalance.toLocaleString() + ' Frw';
        }
    }

    function loadFundBalance() {
        if (!fundSelect.value) { fundBalance.textContent = ''; currentBalance = null; return; }
        fetch('get_fund_balance.php?fund_id=' + encodeURIComponent(fundSelect.value))
            .then(function (r) { return r.json(); })
            .then(function (d) { currentBalance = d.balance; checkAmount(); })
            .catch(function () { fundBalance.textContent = 'Could not load balance.'; });
    }

    typeSelect.addEventListener('change', toggleExpenseFields);
    fundSelect.addEventListener('change', loadFundBalance);
    amountInput.addEventListener('input', checkAmount);
    toggleExpenseFields();
    loadFundBalance();
})();
</script>

<?php include '../../includes/footer.php'; ?>