<?php
// Loan Management calculation helpers.
//
// Interest method: REDUCING BALANCE. Each installment's interest is charged
// on the principal still outstanding at that point, exactly like a bank
// amortization schedule. The schedule (and each installment's principal /
// interest split) is fixed once, at loan creation, using the standard EMI
// formula. Payments are then matched against that fixed schedule in order —
// this keeps the module predictable (the schedule doesn't silently
// reshuffle itself if a payment is late, early, or partial).

function loan_periods_per_year($frequency) {
    switch ($frequency) {
        case 'Weekly': return 52;
        case 'Quarterly': return 4;
        case 'Annually': return 1;
        case 'Monthly':
        default: return 12;
    }
}

function loan_add_period(DateTime $date, $frequency, $count = 1) {
    $d = clone $date;
    switch ($frequency) {
        case 'Weekly': $d->modify('+' . (7 * $count) . ' days'); break;
        case 'Quarterly': $d->modify('+' . (3 * $count) . ' months'); break;
        case 'Annually': $d->modify('+' . (12 * $count) . ' months'); break;
        case 'Monthly':
        default: $d->modify('+' . (1 * $count) . ' months'); break;
    }
    return $d;
}

// Computes the standard EMI (equal installment amount) for a reducing-
// balance loan. Falls back to a plain principal-only split when the rate is
// zero (interest-free loan) to avoid a division by zero.
function loan_calculate_emi($principal, $periodicRate, $numInstallments) {
    if ($periodicRate <= 0) {
        return round($principal / $numInstallments, 2);
    }
    $factor = pow(1 + $periodicRate, $numInstallments);
    $emi = $principal * $periodicRate * $factor / ($factor - 1);
    return round($emi, 2);
}

// Builds and saves the full repayment schedule for a newly created loan.
// Returns ['total_interest', 'total_payable', 'installment_amount', 'maturity_date'].
function loan_generate_schedule($conn, $loanId, $principal, $annualRatePercent, $numInstallments, $frequency, $startDate, $fixedInstallmentAmount = null) {
    $periodicRate = ($annualRatePercent / 100) / loan_periods_per_year($frequency);

    $installmentAmount = $fixedInstallmentAmount > 0
        ? round($fixedInstallmentAmount, 2)
        : loan_calculate_emi($principal, $periodicRate, $numInstallments);

    $balance = $principal;
    $totalInterest = 0;
    $dueDate = new DateTime($startDate);
    $lastDueDate = $dueDate->format('Y-m-d');

    for ($i = 1; $i <= $numInstallments; $i++) {
        $dueDate = loan_add_period($dueDate, $frequency, 1);
        $lastDueDate = $dueDate->format('Y-m-d');

        $interestDue = round($balance * $periodicRate, 2);
        $principalDue = round($installmentAmount - $interestDue, 2);

        // Final installment: absorb any rounding drift so the schedule
        // clears the balance to exactly zero rather than leaving a stray
        // few cents outstanding forever.
        if ($i === $numInstallments) {
            $principalDue = round($balance, 2);
            $installmentThis = round($principalDue + $interestDue, 2);
        } else {
            $installmentThis = $installmentAmount;
        }

        $balance = round($balance - $principalDue, 2);
        $totalInterest += $interestDue;

        $stmt = mysqli_prepare($conn, 'INSERT INTO loan_schedule
            (loan_id, installment_no, due_date, principal_due, interest_due, amount_due)
            VALUES (?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'iisddd', $loanId, $i, $lastDueDate, $principalDue, $interestDue, $installmentThis);
        mysqli_stmt_execute($stmt);
    }

    $totalInterest = round($totalInterest, 2);
    $totalPayable = round($principal + $totalInterest, 2);

    return [
        'total_interest' => $totalInterest,
        'total_payable' => $totalPayable,
        'installment_amount' => $installmentAmount,
        'maturity_date' => $lastDueDate,
    ];
}

// Records one repayment: applies it against the oldest unpaid/partial
// installments in order (interest first, then principal, within each
// installment — standard practice), updates the schedule, appends one row
// to loan_payments (never edits/deletes earlier ones), and refreshes the
// loan's running totals. Returns ['error' => '...'] on failure, or the
// applied ['principal', 'interest', 'remaining_balance'] on success.
function loan_record_payment($conn, $loanId, $paymentDate, $amount, $notes, $recordedBy) {
    if ($amount <= 0) {
        return ['error' => 'Payment amount must be greater than zero.'];
    }

    $loanStmt = mysqli_prepare($conn, 'SELECT * FROM loans WHERE id = ? FOR UPDATE');
    mysqli_stmt_bind_param($loanStmt, 'i', $loanId);
    mysqli_stmt_execute($loanStmt);
    $loan = mysqli_fetch_assoc(mysqli_stmt_get_result($loanStmt));
    if (!$loan) {
        return ['error' => 'Loan not found.'];
    }
    if ($loan['status'] === 'Fully Paid') {
        return ['error' => 'This loan is already fully paid.'];
    }

    $remainingOwed = round((float) $loan['outstanding_balance'], 2);
    if ($amount > $remainingOwed + 0.01) {
        return ['error' => 'Payment (' . number_format($amount, 2) . ') exceeds the outstanding balance (' . number_format($remainingOwed, 2) . ').'];
    }

    $scheduleResult = mysqli_query($conn, 'SELECT * FROM loan_schedule WHERE loan_id = ' . (int) $loanId . " AND status != 'Paid' ORDER BY installment_no ASC");

    $remaining = $amount;
    $principalApplied = 0;
    $interestApplied = 0;

    while ($remaining > 0.001 && ($row = mysqli_fetch_assoc($scheduleResult))) {
        $outstandingOnRow = round((float) $row['amount_due'] - (float) $row['paid_amount'], 2);
        if ($outstandingOnRow <= 0.001) { continue; }

        $applyToRow = min($remaining, $outstandingOnRow);

        // Within this installment, satisfy remaining interest first, then principal.
        $interestRemainingOnRow = max(0, round((float) $row['interest_due'] - max(0, (float) $row['paid_amount'] - (float) $row['principal_due']), 2));
        // Simpler and equally correct: interest already covered = min(paid_amount, interest_due)
        $interestAlreadyCovered = min((float) $row['paid_amount'], (float) $row['interest_due']);
        $interestRemainingOnRow = round((float) $row['interest_due'] - $interestAlreadyCovered, 2);

        $interestPortionHere = min($applyToRow, $interestRemainingOnRow);
        $principalPortionHere = round($applyToRow - $interestPortionHere, 2);

        $newPaidAmount = round((float) $row['paid_amount'] + $applyToRow, 2);
        $newStatus = ($newPaidAmount >= (float) $row['amount_due'] - 0.01) ? 'Paid' : 'Partial';

        $updateRow = mysqli_prepare($conn, 'UPDATE loan_schedule SET paid_amount = ?, status = ? WHERE id = ?');
        mysqli_stmt_bind_param($updateRow, 'dsi', $newPaidAmount, $newStatus, $row['id']);
        mysqli_stmt_execute($updateRow);

        $principalApplied = round($principalApplied + $principalPortionHere, 2);
        $interestApplied = round($interestApplied + $interestPortionHere, 2);
        $remaining = round($remaining - $applyToRow, 2);
    }

    $newTotalPaid = round((float) $loan['total_paid'] + $amount, 2);
    $newPrincipalRepaid = round((float) $loan['principal_repaid'] + $principalApplied, 2);
    $newInterestPaid = round((float) $loan['interest_paid'] + $interestApplied, 2);
    $newOutstanding = round((float) $loan['total_payable'] - $newTotalPaid, 2);
    if ($newOutstanding < 0) { $newOutstanding = 0; }
    $newStatusLoan = ($newOutstanding <= 0.01) ? 'Fully Paid' : $loan['status'];

    $updateLoan = mysqli_prepare($conn, 'UPDATE loans SET total_paid = ?, principal_repaid = ?, interest_paid = ?, outstanding_balance = ?, status = ? WHERE id = ?');
    mysqli_stmt_bind_param($updateLoan, 'ddddsi', $newTotalPaid, $newPrincipalRepaid, $newInterestPaid, $newOutstanding, $newStatusLoan, $loanId);
    mysqli_stmt_execute($updateLoan);

    $insertPayment = mysqli_prepare($conn, 'INSERT INTO loan_payments
        (loan_id, payment_date, amount, principal_portion, interest_portion, remaining_balance, notes, recorded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($insertPayment, 'isdddssi', $loanId, $paymentDate, $amount, $principalApplied, $interestApplied, $newOutstanding, $notes, $recordedBy);
    mysqli_stmt_execute($insertPayment);

    // Auto-post to Transactions as an Expense — no approval needed, mirrors
    // how Restock posts its cost immediately.
    $lenderLabel = $loan['lender'] . ' (' . $loan['loan_type'] . ')';
    $description = 'Loan repayment to ' . $lenderLabel . ' — Principal RWF ' . number_format($principalApplied, 2) . ', Interest RWF ' . number_format($interestApplied, 2);
    $insertTransaction = mysqli_prepare($conn, "INSERT INTO transactions
        (category, transaction_type, amount, transaction_date, description, recorded_by, status)
        VALUES ('Loan Repayment', 'Expense', ?, ?, ?, ?, 'approved')");
    mysqli_stmt_bind_param($insertTransaction, 'dssi', $amount, $paymentDate, $description, $recordedBy);
    mysqli_stmt_execute($insertTransaction);

    return [
        'principal' => $principalApplied,
        'interest' => $interestApplied,
        'remaining_balance' => $newOutstanding,
    ];
}

// The next unpaid installment for a loan — used to show "next due date / amount".
function loan_next_installment($conn, $loanId) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM loan_schedule WHERE loan_id = ? AND status != 'Paid' ORDER BY installment_no ASC LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $loanId);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

// Company-wide loan report totals, for the Loans index page.
function loan_report_totals($conn) {
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT
        COALESCE(SUM(outstanding_balance), 0) AS total_outstanding,
        COALESCE(SUM(principal_repaid), 0) AS total_principal_repaid,
        COALESCE(SUM(interest_paid), 0) AS total_interest_paid,
        COALESCE(SUM(total_paid), 0) AS total_paid,
        COALESCE(SUM(CASE WHEN status = 'Active' THEN outstanding_balance ELSE 0 END), 0) AS active_balance
        FROM loans WHERE status != 'Cancelled'"));
    return $row;
}
// ---------------------------------------------------------------------
// APPEND THIS TO THE END OF includes/loan_helpers.php
// ---------------------------------------------------------------------

// Applies an amount against the oldest unpaid/partial schedule rows
// (interest first, then principal, within each installment). Same rules as
// loan_record_payment(), but it ONLY touches loan_schedule: no loan_payments
// row, no Transactions row, no loan totals. Returns the principal/interest split.
function loan_apply_to_schedule($conn, $loanId, $amount) {
    $scheduleResult = mysqli_query($conn, 'SELECT * FROM loan_schedule WHERE loan_id = ' . (int) $loanId . " AND status != 'Paid' ORDER BY installment_no ASC");

    $remaining = round((float) $amount, 2);
    $principalApplied = 0;
    $interestApplied = 0;

    while ($remaining > 0.001 && ($row = mysqli_fetch_assoc($scheduleResult))) {
        $outstandingOnRow = round((float) $row['amount_due'] - (float) $row['paid_amount'], 2);
        if ($outstandingOnRow <= 0.001) { continue; }

        $applyToRow = min($remaining, $outstandingOnRow);

        $interestAlreadyCovered = min((float) $row['paid_amount'], (float) $row['interest_due']);
        $interestRemainingOnRow = round((float) $row['interest_due'] - $interestAlreadyCovered, 2);
        $interestPortionHere = min($applyToRow, $interestRemainingOnRow);
        $principalPortionHere = round($applyToRow - $interestPortionHere, 2);

        $newPaidAmount = round((float) $row['paid_amount'] + $applyToRow, 2);
        $newStatus = ($newPaidAmount >= (float) $row['amount_due'] - 0.01) ? 'Paid' : 'Partial';

        $updateRow = mysqli_prepare($conn, 'UPDATE loan_schedule SET paid_amount = ?, status = ? WHERE id = ?');
        mysqli_stmt_bind_param($updateRow, 'dsi', $newPaidAmount, $newStatus, $row['id']);
        mysqli_stmt_execute($updateRow);

        $principalApplied = round($principalApplied + $principalPortionHere, 2);
        $interestApplied = round($interestApplied + $interestPortionHere, 2);
        $remaining = round($remaining - $applyToRow, 2);
    }

    return ['principal' => $principalApplied, 'interest' => $interestApplied];
}

// After a loan's schedule has been regenerated (fresh, nothing paid), replays
// every recorded payment in date order against it, and rewrites each payment's
// principal_portion / interest_portion / remaining_balance. The payment
// amounts and dates themselves never change, and Transactions are not touched.
// Returns the recomputed running totals.
function loan_replay_payments($conn, $loanId, $totalPayable) {
    $payments = mysqli_query($conn, 'SELECT id, amount FROM loan_payments WHERE loan_id = ' . (int) $loanId . ' ORDER BY payment_date ASC, id ASC');

    $totalPaid = 0;
    $principalRepaid = 0;
    $interestPaid = 0;

    while ($p = mysqli_fetch_assoc($payments)) {
        $applied = loan_apply_to_schedule($conn, $loanId, (float) $p['amount']);

        $totalPaid = round($totalPaid + (float) $p['amount'], 2);
        $principalRepaid = round($principalRepaid + $applied['principal'], 2);
        $interestPaid = round($interestPaid + $applied['interest'], 2);
        $remainingBalance = max(0, round($totalPayable - $totalPaid, 2));

        $upd = mysqli_prepare($conn, 'UPDATE loan_payments SET principal_portion = ?, interest_portion = ?, remaining_balance = ? WHERE id = ?');
        mysqli_stmt_bind_param($upd, 'dddi', $applied['principal'], $applied['interest'], $remainingBalance, $p['id']);
        mysqli_stmt_execute($upd);
    }

    return [
        'total_paid' => $totalPaid,
        'principal_repaid' => $principalRepaid,
        'interest_paid' => $interestPaid,
    ];
}