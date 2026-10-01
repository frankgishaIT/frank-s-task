<?php
require '../../config/db.php';
require '../../includes/loan_helpers.php';
require '../../includes/business_party_helpers.php';
require_role(['Admin']);

// Lenders are drawn from Business Parties of type 'Partner' — Partners
// store their name in the 'name' column (not 'business_name', which is
// Supplier-only), so that's what's used below.
$lenderList = business_parties_of_type($conn, 'Partner');

if (isset($_POST['save'])) {
    $lenderPartyId = filter_input(INPUT_POST, 'lender_party_id', FILTER_VALIDATE_INT) ?: null;
    $lender = '';
    foreach ($lenderList as $l) { if ((int) $l['id'] === $lenderPartyId) { $lender = $l['name']; break; } }
    $loanType = trim($_POST['loan_type'] ?? '');
    $loanAmount = filter_input(INPUT_POST, 'loan_amount', FILTER_VALIDATE_FLOAT);
    $interestRate = filter_input(INPUT_POST, 'interest_rate', FILTER_VALIDATE_FLOAT);
    $startDate = $_POST['loan_start_date'] ?? '';
    $repaymentPeriod = filter_input(INPUT_POST, 'repayment_period', FILTER_VALIDATE_INT);
    $repaymentFrequency = $_POST['repayment_frequency'] ?? 'Monthly';
    $installmentAmountInput = filter_input(INPUT_POST, 'installment_amount', FILTER_VALIDATE_FLOAT);
    $loanPurpose = trim($_POST['loan_purpose'] ?? '');
    $collateral = trim($_POST['collateral'] ?? '');
    $status = $_POST['status'] ?? 'Active';

    $validStartDate = DateTime::createFromFormat('Y-m-d', $startDate);
    $validFrequencies = ['Weekly', 'Monthly', 'Quarterly', 'Annually'];
    $validStatuses = ['Active', 'Fully Paid', 'Defaulted', 'Cancelled'];

    if ($lender === '' || $loanType === '') {
        $error = 'Please provide the Lender and Loan Type.';
    } elseif ($loanAmount === false || $loanAmount <= 0) {
        $error = 'Please provide a valid Loan Amount.';
    } elseif ($interestRate === false || $interestRate < 0) {
        $error = 'Please provide a valid Interest Rate (0 or more).';
    } elseif (!$validStartDate || $validStartDate->format('Y-m-d') !== $startDate) {
        $error = 'Please provide a valid Loan Start Date.';
    } elseif (!$repaymentPeriod || $repaymentPeriod <= 0) {
        $error = 'Please provide a valid Repayment Period (number of installments).';
    } elseif (!in_array($repaymentFrequency, $validFrequencies, true)) {
        $error = 'Please select a valid Repayment Frequency.';
    } elseif ($installmentAmountInput !== false && $installmentAmountInput !== null && $installmentAmountInput <= 0) {
        $error = 'Installment Amount must be greater than zero, or left blank to auto-calculate.';
    } elseif (!in_array($status, $validStatuses, true)) {
        $error = 'Invalid status.';
    } else {
        $userId = current_user_id();
        $fixedInstallment = ($installmentAmountInput !== false && $installmentAmountInput !== null) ? $installmentAmountInput : null;

        mysqli_begin_transaction($conn);
        try {
            $insertLoan = mysqli_prepare($conn, 'INSERT INTO loans
                (lender, loan_type, loan_amount, interest_rate, loan_start_date, repayment_period, repayment_frequency,
                 installment_amount, maturity_date, loan_purpose, collateral, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $placeholderMaturity = $startDate; // updated below once the schedule is generated
            mysqli_stmt_bind_param($insertLoan, 'ssddsisdsssi',
                $lender, $loanType, $loanAmount, $interestRate, $startDate, $repaymentPeriod, $repaymentFrequency,
                $fixedInstallment, $placeholderMaturity, $loanPurpose, $collateral, $status, $userId);
            mysqli_stmt_execute($insertLoan);
            $loanId = mysqli_insert_id($conn);

            $schedule = loan_generate_schedule($conn, $loanId, $loanAmount, $interestRate, $repaymentPeriod, $repaymentFrequency, $startDate, $fixedInstallment);

            $updateLoan = mysqli_prepare($conn, 'UPDATE loans SET
                total_interest = ?, total_payable = ?, outstanding_balance = ?, installment_amount = ?, maturity_date = ?
                WHERE id = ?');
            mysqli_stmt_bind_param($updateLoan, 'ddddsi',
                $schedule['total_interest'], $schedule['total_payable'], $schedule['total_payable'],
                $schedule['installment_amount'], $schedule['maturity_date'], $loanId);
            mysqli_stmt_execute($updateLoan);

            mysqli_commit($conn);
            header('Location: view.php?id=' . $loanId . '&success=' . urlencode('Loan created and repayment schedule generated.'));
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = 'Unable to save the loan. Please try again.';
        }
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>New Loan</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Loans</a>
</div>

<?php if (isset($error)) { ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
    <i class="bi bi-exclamation-circle-fill"></i>
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php } ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="POST">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-muted">Lender</label>
                    <select name="lender_party_id" class="form-select rm-input" required>
                        <option value="">Select lender</option>
                        <?php foreach ($lenderList as $l) { ?>
                        <option value="<?= (int) $l['id']; ?>" <?= (int) ($_POST['lender_party_id'] ?? 0) === (int) $l['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($l['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                    <?php if (empty($lenderList)) { ?>
                    <small class="text-danger">No Partners found to use as lenders. <a href="../business_parties/create.php">Add one first</a> (select type "RM Partner").</small>
                    <?php } ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-muted">Loan Type</label>
                    <select name="loan_type" class="form-select rm-input" required>
                        <?php foreach (['Bank Loan', 'Financial Institution Loan', 'Company Loan', 'Individual Loan'] as $t) { ?>
                        <option value="<?= $t; ?>" <?= ($_POST['loan_type'] ?? '') === $t ? 'selected' : ''; ?>><?= $t; ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Loan Amount (RWF)</label>
                    <input type="number" name="loan_amount" class="form-control rm-input" min="0" step="0.01" value="<?= htmlspecialchars($_POST['loan_amount'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Interest Rate (% per year)</label>
                    <input type="number" name="interest_rate" class="form-control rm-input" min="0" step="0.01" value="<?= htmlspecialchars($_POST['interest_rate'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Loan Start Date</label>
                    <input type="date" name="loan_start_date" class="form-control rm-input" value="<?= htmlspecialchars($_POST['loan_start_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Repayment Period (number of installments)</label>
                    <input type="number" name="repayment_period" class="form-control rm-input" min="1" step="1" placeholder="e.g. 12" value="<?= htmlspecialchars($_POST['repayment_period'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Repayment Frequency</label>
                    <select name="repayment_frequency" class="form-select rm-input" required>
                        <?php foreach (['Weekly', 'Monthly', 'Quarterly', 'Annually'] as $f) { ?>
                        <option value="<?= $f; ?>" <?= ($_POST['repayment_frequency'] ?? 'Monthly') === $f ? 'selected' : ''; ?>><?= $f; ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Installment Amount <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="number" name="installment_amount" class="form-control rm-input" min="0" step="0.01" placeholder="Leave blank to auto-calculate" value="<?= htmlspecialchars($_POST['installment_amount'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-text">Leave blank and the system calculates the standard installment amount for you.</div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">Loan Purpose</label>
                <textarea name="loan_purpose" class="form-control rm-input" rows="2" style="height:auto;"><?= htmlspecialchars($_POST['loan_purpose'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">Collateral <span class="text-muted fw-normal">(if applicable)</span></label>
                <textarea name="collateral" class="form-control rm-input" rows="2" style="height:auto;"><?= htmlspecialchars($_POST['collateral'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <div class="mb-4">
                <label class="form-label small fw-semibold text-muted">Loan Status</label>
                <select name="status" class="form-select rm-input" required>
                    <?php foreach (['Active', 'Fully Paid', 'Defaulted', 'Cancelled'] as $s) { ?>
                    <option value="<?= $s; ?>" <?= ($_POST['status'] ?? 'Active') === $s ? 'selected' : ''; ?>><?= $s; ?></option>
                    <?php } ?>
                </select>
                <div class="form-text">Maturity Date and the repayment schedule are calculated automatically after saving.</div>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-end">
                <button type="submit" name="save" class="rm-btn rm-btn-primary"><i class="bi bi-check-circle-fill me-2"></i>Save Loan &amp; Generate Schedule</button>
                <a href="index.php" class="rm-btn rm-btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>