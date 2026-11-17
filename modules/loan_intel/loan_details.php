<?php
/**
 * NEW FILE: modules/loan_intel/loan_details.php — extra details of a COMPANY LOAN (spec 3, 12): loan product, fixed /
 * variable rate, grace period, amounts approved and received, purpose, department, reference, and the fees and charges.
 * Fees are a borrowing cost: posted ONCE as an expense "Loan Charges" (Profit & Loss: finance costs), never as a
 * reduction of the loan, never as income. Every change is audited (previous and new values, reason).
 */
require __DIR__ . '/common.php';
li_require($conn, 'finance');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$loan = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM loans WHERE id = $id AND status <> 'Deleted'"));
if (!$loan) { li_go('performance.php', 'error', 'Loan not found.'); }
br_guard_record($conn, 'loans', $id);
$d = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM li_loan_details WHERE loan_id = $id")) ?: ['loan_product' => '', 'rate_type' => 'Fixed', 'grace_periods' => 0, 'processing_fee' => 0, 'other_charges' => 0,
    'fees_transaction_id' => null, 'approved_amount' => null, 'received_amount' => null, 'purpose_category' => '', 'department_id' => null, 'reference_no' => '', 'lender_contact' => ''];
$back = 'loan_details.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!li_csrf_ok()) { li_go($back, 'error', 'The page had expired.'); }
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if (($_POST['do'] ?? '') === 'save') {
        $n = ['loan_product' => mb_substr(trim((string) $_POST['loan_product']), 0, 100), 'rate_type' => $_POST['rate_type'] === 'Variable' ? 'Variable' : 'Fixed',
              'approved_amount' => $_POST['approved_amount'] === '' ? null : max(0, (float) $_POST['approved_amount']),
              'received_amount' => $_POST['received_amount'] === '' ? null : max(0, (float) $_POST['received_amount']),
              'purpose_category' => in_array($_POST['purpose_category'], LI_CATEGORIES, true) ? $_POST['purpose_category'] : null,
              'department_id' => (int) $_POST['department_id'] ?: null, 'reference_no' => mb_substr(trim((string) $_POST['reference_no']), 0, 60),
              'lender_contact' => mb_substr(trim((string) $_POST['lender_contact']), 0, 150)];
        if ($n['received_amount'] !== null && $n['received_amount'] > (float) $loan['loan_amount'] + 0.01) { li_go($back, 'error', 'The amount received cannot be more than the loan principal.'); }
        $s = mysqli_prepare($conn, 'INSERT INTO li_loan_details (loan_id, loan_product, rate_type, approved_amount, received_amount, purpose_category, department_id, reference_no, lender_contact, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE loan_product = VALUES(loan_product), rate_type = VALUES(rate_type), approved_amount = VALUES(approved_amount),
            received_amount = VALUES(received_amount), purpose_category = VALUES(purpose_category), department_id = VALUES(department_id), reference_no = VALUES(reference_no),
            lender_contact = VALUES(lender_contact), updated_by = VALUES(updated_by)');
        $uid = (int) current_user_id();
        mysqli_stmt_bind_param($s, 'issddsissi', $id, $n['loan_product'], $n['rate_type'], $n['approved_amount'], $n['received_amount'], $n['purpose_category'], $n['department_id'], $n['reference_no'], $n['lender_contact'], $uid);
        mysqli_stmt_execute($s);
        [$a, $b] = li_diff($d, $n);
        if ($b) { li_audit($conn, 'loan_details_edit', 'loan', $id, $a, $b, $reason ?: null); }
        li_cache_clear($conn);
        li_go($back, 'success', 'Loan details saved.');
    }
    if (($_POST['do'] ?? '') === 'fees') {
        if (!empty($d['fees_transaction_id'])) { li_go($back, 'error', 'The fees of this loan are already recorded.'); }
        $fee = round(max(0, (float) $_POST['processing_fee']), 2); $oth = round(max(0, (float) $_POST['other_charges']), 2);
        $date = (string) ($_POST['fee_date'] ?? ''); $how = $_POST['how'] === 'separate' ? 'OPERATING' : 'CAPITAL';
        if ($fee + $oth <= 0 || !strtotime($date) || $date > date('Y-m-d')) { li_go($back, 'error', 'Enter the fees and a date that is not in the future.'); }
        if (mb_strlen($reason) < 3) { li_go($back, 'error', 'Give the reference of the lender\'s statement or receipt.'); }
        mysqli_begin_transaction($conn);
        try {
            $tx = fund_post_automatic_expense($conn, $how, 'Loan Charges', $fee + $oth, $date,
                'Loan #' . $id . ' fees and charges (' . $loan['lender'] . '): processing RWF ' . number_format($fee, 2) . ', other RWF ' . number_format($oth, 2) . ' — ' . $reason,
                (int) current_user_id(), 'Loan fees and charges', 'LOAN_FEE', $id);
            $uid = (int) current_user_id();
            $recv = $how === 'CAPITAL' ? round((float) $loan['loan_amount'] - $fee - $oth, 2) : null;
            $s = mysqli_prepare($conn, 'INSERT INTO li_loan_details (loan_id, processing_fee, other_charges, fees_transaction_id, received_amount, updated_by) VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE processing_fee = VALUES(processing_fee), other_charges = VALUES(other_charges), fees_transaction_id = VALUES(fees_transaction_id),
                received_amount = COALESCE(VALUES(received_amount), received_amount), updated_by = VALUES(updated_by)');
            mysqli_stmt_bind_param($s, 'iddidi', $id, $fee, $oth, $tx, $recv, $uid);
            mysqli_stmt_execute($s);
            mysqli_commit($conn);
        } catch (InsufficientFundException $e) { mysqli_rollback($conn); li_go($back, 'error', $e->getMessage()); }
        catch (Throwable $e) { mysqli_rollback($conn); error_log('loan fees: ' . $e->getMessage()); li_go($back, 'error', 'The fees could not be recorded.'); }
        li_audit($conn, 'loan_fees_record', 'loan', $id, null, ['processing_fee' => $fee, 'other_charges' => $oth, 'fund' => $how, 'transaction_id' => $tx, 'date' => $date], $reason);
        li_cache_clear($conn);
        li_go($back, 'success', 'Fees recorded as an expense "Loan Charges" (transaction #' . $tx . '), a borrowing cost in the Profit & Loss.');
    }
}
$hist = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, u.names FROM li_audit a LEFT JOIN users u ON u.id = a.user_id WHERE a.ref_type = 'loan' AND a.ref_id = $id ORDER BY a.id DESC"), MYSQLI_ASSOC);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
li_tabs($conn, 'performance.php', 'Company loan #' . $id . ' · ' . $loan['lender']);
?>
<div class="row g-3">
<div class="col-lg-7"><form method="POST" class="card border-0 shadow-sm"><div class="card-body row g-2"><?= li_csrf_field(); ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= $id; ?>">
    <div class="small text-muted">Principal <?= li_rwf($loan['loan_amount']); ?> · <?= (float) $loan['interest_rate']; ?>% · <?= (int) $loan['repayment_period']; ?> x <?= li_e(strtolower($loan['repayment_frequency'])); ?> · grace <?= (int) $d['grace_periods']; ?> · from <?= li_e($loan['loan_start_date']); ?> (change these on the loan itself)</div>
    <div class="col-md-6"><label class="form-label small">Loan product</label><input name="loan_product" class="form-control form-control-sm" value="<?= li_e($d['loan_product']); ?>" placeholder="e.g. SME working capital loan"></div>
    <div class="col-md-6"><label class="form-label small">Interest rate type</label><select name="rate_type" class="form-select form-select-sm"><option <?= $d['rate_type'] === 'Fixed' ? 'selected' : ''; ?>>Fixed</option><option <?= $d['rate_type'] === 'Variable' ? 'selected' : ''; ?>>Variable</option></select></div>
    <div class="col-md-6"><label class="form-label small">Amount approved by the lender (RWF)</label><input name="approved_amount" type="number" step="0.01" min="0" class="form-control form-control-sm" value="<?= li_e($d['approved_amount']); ?>" placeholder="<?= (float) $loan['loan_amount']; ?>"></div>
    <div class="col-md-6"><label class="form-label small">Amount actually received (RWF)</label><input name="received_amount" type="number" step="0.01" min="0" class="form-control form-control-sm" value="<?= li_e($d['received_amount']); ?>" placeholder="principal - fees deducted"></div>
    <div class="col-md-6"><label class="form-label small">Purpose category</label><select name="purpose_category" class="form-select form-select-sm"><option value="">—</option><?php foreach (LI_CATEGORIES as $c) { ?><option <?= $d['purpose_category'] === $c ? 'selected' : ''; ?>><?= li_e($c); ?></option><?php } ?></select></div>
    <div class="col-md-6"><label class="form-label small">Department</label><select name="department_id" class="form-select form-select-sm"><option value="">—</option><?php foreach (li_departments($conn) as $k => $v) { ?><option value="<?= $k; ?>" <?= (int) $d['department_id'] === $k ? 'selected' : ''; ?>><?= li_e($v); ?></option><?php } ?></select></div>
    <div class="col-md-6"><label class="form-label small">Lender's reference / contract number</label><input name="reference_no" class="form-control form-control-sm" value="<?= li_e($d['reference_no']); ?>"></div>
    <div class="col-md-6"><label class="form-label small">Lender contact</label><input name="lender_contact" class="form-control form-control-sm" value="<?= li_e($d['lender_contact']); ?>"></div>
    <div class="col-12"><label class="form-label small">Reason for the change</label><input name="reason" class="form-control form-control-sm"></div>
    <div class="col-12"><button class="btn btn-sm btn-primary">Save details</button></div>
</div></form>
<div class="card border-0 shadow-sm mt-3"><div class="card-body">
    <h6>Fees and charges</h6>
    <?php if (!empty($d['fees_transaction_id'])) { ?>
        <div class="small">Recorded: processing <?= li_rwf($d['processing_fee']); ?>, other <?= li_rwf($d['other_charges']); ?> · transaction #<?= (int) $d['fees_transaction_id']; ?> (expense "Loan Charges").</div>
    <?php } else { ?>
    <form method="POST" class="row g-2"><?= li_csrf_field(); ?><input type="hidden" name="do" value="fees"><input type="hidden" name="id" value="<?= $id; ?>">
        <div class="col-md-4"><label class="form-label small">Processing fee</label><input name="processing_fee" type="number" step="0.01" min="0" class="form-control form-control-sm"></div>
        <div class="col-md-4"><label class="form-label small">Other charges</label><input name="other_charges" type="number" step="0.01" min="0" class="form-control form-control-sm"></div>
        <div class="col-md-4"><label class="form-label small">Date</label><input name="fee_date" type="date" max="<?= date('Y-m-d'); ?>" value="<?= li_e($loan['loan_start_date']); ?>" class="form-control form-control-sm"></div>
        <div class="col-md-6"><select name="how" class="form-select form-select-sm"><option value="deducted">Deducted by the lender from the money received (Capital Fund)</option><option value="separate">Paid separately (Operating Fund)</option></select></div>
        <div class="col-md-6"><input name="reason" class="form-control form-control-sm" placeholder="Lender's statement / receipt reference" required></div>
        <div class="col-12"><button class="btn btn-sm btn-outline-primary" onclick="return confirm('Record these fees as an expense? This is done once.');">Record fees</button>
            <span class="small text-muted">Posted once as an approved expense "Loan Charges": a borrowing cost (finance costs in the Profit &amp; Loss). The loan liability stays at the full principal.</span></div>
    </form>
    <?php } ?>
</div></div>
</div>
<div class="col-lg-5"><div class="card border-0 shadow-sm"><div class="card-body small"><h6>History</h6>
    <?php foreach ($hist as $h) { ?><div class="border-top py-1"><strong><?= li_e(str_replace('_', ' ', $h['action'])); ?></strong> · <?= li_e($h['names']); ?> · <span class="text-muted"><?= li_e($h['created_at']); ?></span>
        <?= $h['reason'] ? '<div>' . li_e($h['reason']) . '</div>' : ''; ?><div class="text-muted"><?= $h['prev_json'] && $h['prev_json'] !== 'null' ? 'before ' . li_e($h['prev_json']) . ' → ' : ''; ?><?= li_e($h['new_json']); ?></div></div><?php } ?>
    <?php if (!$hist) { ?><div class="text-muted">No change yet.</div><?php } ?>
</div></div></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>