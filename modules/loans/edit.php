<?php
require '../../config/db.php';
require '../../includes/loan_helpers.php';
require '../../includes/business_party_helpers.php';
require_role(['Admin']);

$loanId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$loanId) { header('Location: index.php'); exit; }

$loanStmt = mysqli_prepare($conn, 'SELECT * FROM loans WHERE id = ?');
mysqli_stmt_bind_param($loanStmt, 'i', $loanId);
mysqli_stmt_execute($loanStmt);
$loan = mysqli_fetch_assoc(mysqli_stmt_get_result($loanStmt));
if (!$loan) { header('Location: index.php'); exit; }

$paymentCount = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM loan_payments WHERE loan_id = ' . (int) $loanId))[0];

$lenderList = business_parties_of_type($conn, 'Partner');

$lenderTypes = ['Bank', 'Financial Institution', 'Company', 'Individual'];
// Older loans may still hold the previous wording ("Bank Loan"); map it to the new value.
$currentLenderType = preg_replace('/ Loan$/', '', (string) $loan['loan_type']);

// Form value helper: posted value after a failed save, otherwise the stored value.
$val = function ($key, $default = '') { return $_POST[$key] ?? $default; };

if (isset($_POST['save'])) {
    $lenderPartyId = filter_input(INPUT_POST, 'lender_party_id', FILTER_VALIDATE_INT) ?: null;
    $lender = $loan['lender']; // keep the current lender unless another one is picked
    if ($lenderPartyId !== null) {
        foreach ($lenderList as $l) { if ((int) $l['id'] === $lenderPartyId) { $lender = $l['name']; break; } }
    }
    // Stored in the existing loans.loan_type column.
    $loanType = trim($_POST['lender_type'] ?? '');
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

    if ($lender === '' || !in_array($loanType, $lenderTypes, true)) {
        $error = 'Please provide the Lender and Lender Type.';
    } elseif ($loanAmount === false || $loanAmount === null || $loanAmount <= 0) {
        $error = 'Please provide a valid Loan Amount.';
    } elseif ($interestRate === false || $interestRate === null || $interestRate < 0) {
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
        $fixedInstallment = ($installmentAmountInput !== false && $installmentAmountInput !== null) ? $installmentAmountInput : null;

        mysqli_begin_transaction($conn);
        try {
            // Lock the loan row so a payment can't be recorded mid-edit.
            $lock = mysqli_prepare($conn, 'SELECT id FROM loans WHERE id = ? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 'i', $loanId);
            mysqli_stmt_execute($lock);
            mysqli_stmt_get_result($lock); // read the result so the connection is free for the next command
            mysqli_stmt_close($lock);

            // 1. Rebuild the schedule from the corrected terms (nothing paid yet).
            $del = mysqli_prepare($conn, 'DELETE FROM loan_schedule WHERE loan_id = ?');
            mysqli_stmt_bind_param($del, 'i', $loanId);
            mysqli_stmt_execute($del);

            $schedule = loan_generate_schedule($conn, $loanId, $loanAmount, $interestRate, $repaymentPeriod, $repaymentFrequency, $startDate, $fixedInstallment);

            // 2. Existing payments must still fit inside the new total.
            $alreadyPaid = (float) mysqli_fetch_row(mysqli_query($conn, 'SELECT COALESCE(SUM(amount), 0) FROM loan_payments WHERE loan_id = ' . (int) $loanId))[0];
            if ($alreadyPaid > $schedule['total_payable'] + 0.01) {
                throw new RuntimeException('Payments already recorded (RWF ' . number_format($alreadyPaid, 2) . ') exceed the new total payable (RWF ' . number_format($schedule['total_payable'], 2) . '). Check the amount, rate, and period.');
            }

            // 3. Replay the recorded payments against the new schedule.
            $replay = loan_replay_payments($conn, $loanId, $schedule['total_payable']);
            $outstanding = max(0, round($schedule['total_payable'] - $replay['total_paid'], 2));

            // A loan with payments that now clears to zero is Fully Paid; a loan
            // marked Fully Paid that now has a balance goes back to Active.
            $finalStatus = $status;
            if ($replay['total_paid'] > 0 && $outstanding <= 0.01) {
                $finalStatus = 'Fully Paid';
            } elseif ($status === 'Fully Paid' && $outstanding > 0.01) {
                $finalStatus = 'Active';
            }

            // 4. Save the loan's descriptive fields and terms.
            $updTerms = mysqli_prepare($conn, 'UPDATE loans SET
                lender = ?, loan_type = ?, loan_amount = ?, interest_rate = ?, loan_start_date = ?,
                repayment_period = ?, repayment_frequency = ?, loan_purpose = ?, collateral = ?, status = ?
                WHERE id = ?');
            mysqli_stmt_bind_param($updTerms, 'ssddsissssi',
                $lender, $loanType, $loanAmount, $interestRate, $startDate,
                $repaymentPeriod, $repaymentFrequency, $loanPurpose, $collateral, $finalStatus, $loanId);
            mysqli_stmt_execute($updTerms);

            // 5. Save the recalculated totals.
            $updTotals = mysqli_prepare($conn, 'UPDATE loans SET
                total_interest = ?, total_payable = ?, outstanding_balance = ?, installment_amount = ?, maturity_date = ?,
                total_paid = ?, principal_repaid = ?, interest_paid = ?
                WHERE id = ?');
            mysqli_stmt_bind_param($updTotals, 'ddddsdddi',
                $schedule['total_interest'], $schedule['total_payable'], $outstanding,
                $schedule['installment_amount'], $schedule['maturity_date'],
                $replay['total_paid'], $replay['principal_repaid'], $replay['interest_paid'], $loanId);
            mysqli_stmt_execute($updTotals);

            // 6. Keep the 'Loan Received' income entry in step with the corrected loan.
            $receivedDescription = 'Loan received from ' . $lender . ' (' . $loanType . ') — Principal RWF ' . number_format($loanAmount, 2);
            if (!empty($loan['received_transaction_id'])) {
                $receivedTxId = (int) $loan['received_transaction_id'];
                $updIncome = mysqli_prepare($conn, 'UPDATE transactions SET amount = ?, transaction_date = ?, description = ? WHERE id = ?');
                mysqli_stmt_bind_param($updIncome, 'dssi', $loanAmount, $startDate, $receivedDescription, $receivedTxId);
                mysqli_stmt_execute($updIncome);
            } else {
                // Older loan created before this feature: post its income now.
                $editorId = current_user_id();
                $insIncome = mysqli_prepare($conn, "INSERT INTO transactions
                    (category, transaction_type, amount, transaction_date, description, recorded_by, status)
                    VALUES ('Loan Received', 'Income', ?, ?, ?, ?, 'approved')");
                mysqli_stmt_bind_param($insIncome, 'dssi', $loanAmount, $startDate, $receivedDescription, $editorId);
                mysqli_stmt_execute($insIncome);
                $newTxId = mysqli_insert_id($conn);

                $linkTx = mysqli_prepare($conn, 'UPDATE loans SET received_transaction_id = ? WHERE id = ?');
                mysqli_stmt_bind_param($linkTx, 'ii', $newTxId, $loanId);
                mysqli_stmt_execute($linkTx);
            }

            mysqli_commit($conn);
            header('Location: view.php?id=' . $loanId . '&success=' . urlencode('Loan updated and repayment schedule recalculated.'));
            exit;
        } catch (RuntimeException $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $error = 'Unable to update the loan. Please try again.';
        }
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Edit Loan</h2>
    <a href="view.php?id=<?= (int) $loanId; ?>" class="rm-btn rm-btn-light">Back to Loan</a>
</div>

<?php if (isset($error)) { ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
    <i class="bi bi-exclamation-circle-fill"></i>
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php } ?>

<?php if ($paymentCount > 0) { ?>
<div class="alert alert-warning mb-3" style="border-radius:10px; border:none; font-size:13px; padding:10px 14px;">
    <i class="bi bi-info-circle-fill me-1"></i>
    This loan has <?= $paymentCount; ?> recorded payment<?= $paymentCount === 1 ? '' : 's'; ?>. If you change the amount, rate, dates, period, or frequency, the schedule is rebuilt and the payments are re-applied to it in date order. The payment amounts and dates stay the same, but the principal/interest split and balances are recalculated.
</div>
<?php } ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="POST">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-muted">Lender</label>
                    <select name="lender_party_id" class="form-select rm-input">
                        <option value="">Keep current (<?= htmlspecialchars($loan['lender'], ENT_QUOTES, 'UTF-8'); ?>)</option>
                        <?php foreach ($lenderList as $l) { ?>
                        <option value="<?= (int) $l['id']; ?>" <?= (isset($_POST['lender_party_id']) ? (int) $_POST['lender_party_id'] === (int) $l['id'] : false) ? 'selected' : ''; ?>><?= htmlspecialchars($l['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-muted">Lender Type</label>
                    <select name="lender_type" class="form-select rm-input" required>
                        <?php foreach ($lenderTypes as $t) { ?>
                        <option value="<?= $t; ?>" <?= $val('lender_type', $currentLenderType) === $t ? 'selected' : ''; ?>><?= $t; ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Loan Amount (RWF)</label>
                    <input type="number" name="loan_amount" class="form-control rm-input" min="0" step="0.01" value="<?= htmlspecialchars((string) $val('loan_amount', $loan['loan_amount']), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Interest Rate (% per year)</label>
                    <input type="number" name="interest_rate" class="form-control rm-input" min="0" step="0.01" value="<?= htmlspecialchars((string) $val('interest_rate', $loan['interest_rate']), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Loan Start Date</label>
                    <input type="date" name="loan_start_date" class="form-control rm-input" value="<?= htmlspecialchars((string) $val('loan_start_date', $loan['loan_start_date']), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Repayment Period (number of installments)</label>
                    <input type="number" name="repayment_period" class="form-control rm-input" min="1" step="1" value="<?= htmlspecialchars((string) $val('repayment_period', $loan['repayment_period']), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Repayment Frequency</label>
                    <select name="repayment_frequency" class="form-select rm-input" required>
                        <?php foreach (['Weekly', 'Monthly', 'Quarterly', 'Annually'] as $f) { ?>
                        <option value="<?= $f; ?>" <?= $val('repayment_frequency', $loan['repayment_frequency']) === $f ? 'selected' : ''; ?>><?= $f; ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Installment Amount <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="number" name="installment_amount" class="form-control rm-input" min="0" step="0.01" placeholder="Auto-calculated (currently <?= number_format((float) $loan['installment_amount'], 2); ?>)" value="<?= htmlspecialchars((string) ($_POST['installment_amount'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-text">Leave blank to recalculate the standard installment from the terms above.</div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">Loan Purpose</label>
                <textarea name="loan_purpose" class="form-control rm-input" rows="2" style="height:auto;"><?= htmlspecialchars((string) $val('loan_purpose', $loan['loan_purpose']), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">Collateral <span class="text-muted fw-normal">(if applicable)</span></label>
                <textarea name="collateral" class="form-control rm-input" rows="2" style="height:auto;"><?= htmlspecialchars((string) $val('collateral', $loan['collateral']), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <div class="mb-4">
                <label class="form-label small fw-semibold text-muted">Loan Status</label>
                <select name="status" class="form-select rm-input" required>
                    <?php foreach (['Active', 'Fully Paid', 'Defaulted', 'Cancelled'] as $s) { ?>
                    <option value="<?= $s; ?>" <?= $val('status', $loan['status']) === $s ? 'selected' : ''; ?>><?= $s; ?></option>
                    <?php } ?>
                </select>
                <div class="form-text">Maturity Date, the schedule, and balances are recalculated automatically after saving.</div>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-end">
                <button type="submit" name="save" class="rm-btn rm-btn-primary"><i class="bi bi-check-circle-fill me-2"></i>Save Changes</button>
                <a href="view.php?id=<?= (int) $loanId; ?>" class="rm-btn rm-btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>