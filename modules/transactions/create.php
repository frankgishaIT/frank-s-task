<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require '../../includes/notification_helper.php';
require '../../includes/business_party_helpers.php';

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
$payeeList = business_parties_of_type($conn, 'Payee');
$partnerList = business_parties_of_type($conn, 'Partner');

if (isset($_POST['save'])) {
    $category = $_POST['category'] ?? '';
    $type = $_POST['transaction_type'] ?? '';
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $transactionDate = $_POST['transaction_date'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $partyId = filter_input(INPUT_POST, 'party_id', FILTER_VALIDATE_INT) ?: null;
    $recordedBy = $_SESSION['user_id'] ?? null;
    $validDate = DateTime::createFromFormat('Y-m-d', $transactionDate);

    if (!in_array($category, ['Product', 'Service'], true) || !in_array($type, ['Income', 'Expense'], true) || $amount === false || $amount <= 0 || !$validDate || $validDate->format('Y-m-d') !== $transactionDate) {
        $error = 'Please provide a valid category, type, amount, and date.';
    } else {
        // Income is always auto-approved. Expenses need admin approval
// unless the person recording it is already an admin.
$status = ($isAdmin || $type === 'Income') ? 'approved' : 'pending';

        $statement = mysqli_prepare($conn, 'INSERT INTO transactions (category, transaction_type, amount, transaction_date, description, recorded_by, status, party_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($statement, 'ssdssisi', $category, $type, $amount, $transactionDate, $description, $recordedBy, $status, $partyId);

        if (mysqli_stmt_execute($statement)) {
    if ($status === 'pending') {
        $newId = mysqli_insert_id($conn);
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

        }
        $error = 'Unable to save the transaction.';
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
})();
</script>

<?php include '../../includes/footer.php'; ?>