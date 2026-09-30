<?php
require '../../config/db.php';
require '../../includes/loan_helpers.php';
require_role(['Admin']);

function money($v) { return number_format((float) $v, 2); }

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid loan selected.'); exit; }

if (isset($_POST['record_payment'])) {
    $paymentDate = $_POST['payment_date'] ?? '';
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $notes = trim($_POST['notes'] ?? '');
    $validDate = DateTime::createFromFormat('Y-m-d', $paymentDate);

    if (!$validDate || $validDate->format('Y-m-d') !== $paymentDate) {
        $error = 'Please provide a valid payment date.';
    } elseif ($amount === false || $amount <= 0) {
        $error = 'Please provide a valid payment amount.';
    } else {
        $result = loan_record_payment($conn, $id, $paymentDate, $amount, $notes, current_user_id());
        if (isset($result['error'])) {
            $error = $result['error'];
        } else {
            header('Location: view.php?id=' . $id . '&success=' . urlencode('Payment of RWF ' . money($amount) . ' recorded.'));
            exit;
        }
    }
}

$loanStatement = mysqli_prepare($conn, 'SELECT loans.*, users.names AS created_by_name FROM loans LEFT JOIN users ON loans.created_by = users.id WHERE loans.id = ?');
mysqli_stmt_bind_param($loanStatement, 'i', $id);
mysqli_stmt_execute($loanStatement);
$loan = mysqli_fetch_assoc(mysqli_stmt_get_result($loanStatement));
if (!$loan) { header('Location: index.php?success=Loan not found.'); exit; }

$scheduleStatement = mysqli_prepare($conn, 'SELECT * FROM loan_schedule WHERE loan_id = ? ORDER BY installment_no ASC');
mysqli_stmt_bind_param($scheduleStatement, 'i', $id);
mysqli_stmt_execute($scheduleStatement);
$schedule = mysqli_stmt_get_result($scheduleStatement);

$paymentsStatement = mysqli_prepare($conn, 'SELECT loan_payments.*, users.names AS recorded_by_name FROM loan_payments LEFT JOIN users ON loan_payments.recorded_by = users.id WHERE loan_id = ? ORDER BY payment_date DESC, id DESC');
mysqli_stmt_bind_param($paymentsStatement, 'i', $id);
mysqli_stmt_execute($paymentsStatement);
$payments = mysqli_stmt_get_result($paymentsStatement);

$next = $loan['status'] === 'Active' ? loan_next_installment($conn, $id) : null;

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php } ?>
<?php if (isset($error)) { ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?= htmlspecialchars($loan['lender'], ENT_QUOTES, 'UTF-8'); ?> <span class="text-muted fs-6 fw-normal"><?= htmlspecialchars($loan['loan_type'], ENT_QUOTES, 'UTF-8'); ?></span></h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Loans</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Principal Amount</div>
            <div class="fs-5 fw-bold">RWF <?= money($loan['loan_amount']); ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Interest Amount</div>
            <div class="fs-5 fw-bold">RWF <?= money($loan['total_interest']); ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Total Payable</div>
            <div class="fs-5 fw-bold">RWF <?= money($loan['total_payable']); ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Outstanding Balance</div>
            <div class="fs-5 fw-bold" style="color:var(--accent-red);">RWF <?= money($loan['outstanding_balance']); ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Principal Repaid</div>
            <div class="fw-semibold">RWF <?= money($loan['principal_repaid']); ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Interest Paid</div>
            <div class="fw-semibold">RWF <?= money($loan['interest_paid']); ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Total Paid</div>
            <div class="fw-semibold" style="color:var(--accent-blue);">RWF <?= money($loan['total_paid']); ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Next Payment Due</div>
            <?php if ($next) { ?>
            <div class="fw-semibold"><?= date('d M Y', strtotime($next['due_date'])); ?></div>
            <div class="text-muted small">RWF <?= money($next['amount_due'] - $next['paid_amount']); ?></div>
            <?php } else { ?>
            <div class="fw-semibold text-muted">—</div>
            <?php } ?>
        </div></div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h5 class="mb-3">Loan Details</h5>
                <div class="row small">
                    <div class="col-6 mb-2"><span class="text-muted">Interest Rate:</span> <strong><?= htmlspecialchars($loan['interest_rate'], ENT_QUOTES, 'UTF-8'); ?>% / year</strong></div>
                    <div class="col-6 mb-2"><span class="text-muted">Start Date:</span> <strong><?= date('d M Y', strtotime($loan['loan_start_date'])); ?></strong></div>
                    <div class="col-6 mb-2"><span class="text-muted">Repayment Period:</span> <strong><?= (int) $loan['repayment_period']; ?> installments</strong></div>
                    <div class="col-6 mb-2"><span class="text-muted">Frequency:</span> <strong><?= htmlspecialchars($loan['repayment_frequency'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div class="col-6 mb-2"><span class="text-muted">Installment Amount:</span> <strong>RWF <?= money($loan['installment_amount']); ?></strong></div>
                    <div class="col-6 mb-2"><span class="text-muted">Maturity Date:</span> <strong><?= date('d M Y', strtotime($loan['maturity_date'])); ?></strong></div>
                    <div class="col-12 mb-2"><span class="text-muted">Purpose:</span> <?= nl2br(htmlspecialchars($loan['loan_purpose'] ?: '—', ENT_QUOTES, 'UTF-8')); ?></div>
                    <div class="col-12 mb-2"><span class="text-muted">Collateral:</span> <?= nl2br(htmlspecialchars($loan['collateral'] ?: 'None', ENT_QUOTES, 'UTF-8')); ?></div>
                    <div class="col-12"><span class="text-muted">Recorded by:</span> <?= htmlspecialchars($loan['created_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?> on <?= date('d M Y', strtotime($loan['created_at'])); ?></div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="mb-3">Repayment Schedule</h5>
                <div style="max-height:340px; overflow-y:auto;">
                <table>
                    <thead><tr><th>#</th><th>Due Date</th><th>Principal</th><th>Interest</th><th>Amount Due</th><th>Paid</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php while ($row = mysqli_fetch_assoc($schedule)) { ?>
                        <tr>
                            <td><?= (int) $row['installment_no']; ?></td>
                            <td><?= date('d M Y', strtotime($row['due_date'])); ?></td>
                            <td><?= money($row['principal_due']); ?></td>
                            <td><?= money($row['interest_due']); ?></td>
                            <td><?= money($row['amount_due']); ?></td>
                            <td><?= money($row['paid_amount']); ?></td>
                            <td>
                                <?php $s = $row['status']; $c = $s === 'Paid' ? '#0FA968' : ($s === 'Partial' ? '#E68A1C' : '#8A90A3'); ?>
                                <span style="font-size:10px; font-weight:700; color:<?= $c; ?>;"><?= $s; ?></span>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

    </div>

    <div class="col-lg-5">

        <?php if ($loan['status'] === 'Active') { ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h5 class="mb-3">Record Payment</h5>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted">Payment Date</label>
                        <input type="date" name="payment_date" class="form-control rm-input" value="<?= date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted">Amount (RWF)</label>
                        <input type="number" name="amount" class="form-control rm-input" min="0.01" step="0.01"
                               value="<?= $next ? money($next['amount_due'] - $next['paid_amount']) : ''; ?>" required>
                        <div class="form-text">Outstanding balance: RWF <?= money($loan['outstanding_balance']); ?>. Overpayment beyond this is rejected.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted">Notes</label>
                        <textarea name="notes" class="form-control rm-input" rows="2" style="height:auto;"></textarea>
                    </div>
                    <button type="submit" name="record_payment" class="rm-btn rm-btn-primary w-100"><i class="bi bi-check-circle-fill me-2"></i>Record Payment</button>
                </form>
            </div>
        </div>
        <?php } else { ?>
        <div class="alert alert-info" style="border-radius:10px; border:none; background:var(--accent-blue-bg); color:var(--accent-blue); font-size:13px;">
            This loan is <?= htmlspecialchars(strtolower($loan['status']), ENT_QUOTES, 'UTF-8'); ?> — no further payments can be recorded.
        </div>
        <?php } ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="mb-3">Payment History</h5>
                <div style="max-height:340px; overflow-y:auto;">
                <?php if (mysqli_num_rows($payments) === 0) { ?>
                    <div class="text-muted small text-center py-3">No payments recorded yet.</div>
                <?php } ?>
                <?php while ($p = mysqli_fetch_assoc($payments)) { ?>
                <div class="pb-2 mb-2 border-bottom small">
                    <div class="d-flex justify-content-between">
                        <strong><?= date('d M Y', strtotime($p['payment_date'])); ?></strong>
                        <strong>RWF <?= money($p['amount']); ?></strong>
                    </div>
                    <div class="text-muted">Principal: RWF <?= money($p['principal_portion']); ?> &middot; Interest: RWF <?= money($p['interest_portion']); ?></div>
                    <div class="text-muted">Balance after: RWF <?= money($p['remaining_balance']); ?></div>
                    <?php if ($p['notes']) { ?><div class="text-muted fst-italic"><?= htmlspecialchars($p['notes'], ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
                    <div class="text-muted" style="font-size:11px;">Recorded by <?= htmlspecialchars($p['recorded_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <?php } ?>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>