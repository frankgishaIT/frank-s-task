<?php
require '../../config/db.php';
require_once '../../includes/notification_helper.php';
require_once '../../includes/payroll_fund_helpers.php'; // NEW: keeps the Operating Fund in step with the payroll
require_once '../../includes/sms_messages.php';         // NEW: payroll SMS when it becomes Paid (MY MOTIVE SMS, message 10)
require_role(['Admin']);

// CHANGED: problems are sent as "error" (they were sent as "success"), and messages are urlencoded.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid payroll record selected.')); exit; }

$recordStatement = mysqli_prepare($conn, 'SELECT payroll.*, users.names AS employee_name FROM payroll INNER JOIN users ON payroll.user_id = users.id WHERE payroll.id = ?');
mysqli_stmt_bind_param($recordStatement, 'i', $id);
mysqli_stmt_execute($recordStatement);
$payroll = mysqli_fetch_assoc(mysqli_stmt_get_result($recordStatement));
if (!$payroll) { header('Location: index.php?error=' . urlencode('Payroll record not found.')); exit; }

if (isset($_POST['update'])) {
    $bonus = filter_input(INPUT_POST, 'bonus', FILTER_VALIDATE_FLOAT);
    $deductions = filter_input(INPUT_POST, 'deductions', FILTER_VALIDATE_FLOAT);
    $salesCommission = filter_input(INPUT_POST, 'sales_commission', FILTER_VALIDATE_FLOAT);
    $status = $_POST['status'] ?? '';

    if ($bonus === false || $bonus === null || $bonus < 0 || $deductions === false || $deductions === null || $deductions < 0
        || $salesCommission === false || $salesCommission === null || $salesCommission < 0 || !in_array($status, ['Draft', 'Paid'], true)) {
        $error = 'Enter valid bonus, deductions, commission, and status.';
    } else {
        $userId = current_user_id();
        $userId = $userId ? (int) $userId : null;

        mysqli_begin_transaction($conn);
        try {
            // NEW: read the payroll again under a lock, so two edits cannot run at the same time.
            $lock = mysqli_prepare($conn, 'SELECT * FROM payroll WHERE id = ? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 'i', $id);
            mysqli_stmt_execute($lock);
            $current = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            $previousStatus = $current['status'];

            // CHANGED: rounded, and never negative.
            $netSalary = round((float) $current['basic_salary'] + (float) $current['overtime_pay'] + (float) $current['performance_bonus']
                + $salesCommission + $bonus - $deductions - (float) $current['attendance_deduction'], 2);
            if ($netSalary < 0) {
                throw new RuntimeException('The deductions are larger than the salary (Net Salary would be RWF ' . number_format($netSalary, 2) . '). Please check the amounts.');
            }
            $paidAt = $status === 'Paid' ? ($current['paid_at'] ?? date('Y-m-d H:i:s')) : null;

            $statement = mysqli_prepare($conn, 'UPDATE payroll SET bonus = ?, deductions = ?, sales_commission = ?, net_salary = ?, status = ?, paid_at = ? WHERE id = ?');
            mysqli_stmt_bind_param($statement, 'ddddssi', $bonus, $deductions, $salesCommission, $netSalary, $status, $paidAt, $id);
            mysqli_stmt_execute($statement);

            // NEW (spec sections 3 and 14): the RM Business Operating Fund follows the payroll.
            //   Draft -> Paid      the Net Salary is paid from the fund (balance is checked)
            //   Paid, new amount   the old expense is reversed and the new amount paid
            //   Paid -> Draft      the money goes back to the fund
            payroll_sync_fund($conn, (int) $id, $previousStatus, $userId);

            mysqli_commit($conn);

            // NEW: when it has just become Paid, tell the employee (in the system and by SMS).
            if ($status === 'Paid' && $previousStatus !== 'Paid') {
                notifyUser($conn, (int) $current['user_id'], 'Payslip ready — payment made',
                    'Your payroll for ' . date('F Y', strtotime($current['pay_period'])) . ' has been processed and paid. Net salary: RWF ' . number_format($netSalary, 2) . '.');
                sms_notify_payroll_paid($conn, (int) $id, $userId);
            }

            header('Location: index.php?success=' . urlencode('Payroll updated successfully.'));
            exit;
        } catch (InsufficientFundException $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage() . ' The payroll was not changed. You can keep it as a Draft until the fund has enough money.';
        } catch (mysqli_sql_exception $e) {
            mysqli_rollback($conn);
            error_log('payroll edit failed for #' . $id . ': ' . $e->getMessage());
            $error = 'Unable to update the payroll. Nothing was changed.';
        } catch (RuntimeException $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('payroll edit failed for #' . $id . ': ' . $e->getMessage());
            $error = 'Unable to update the payroll. Nothing was changed.';
        }
    }
    $payroll['bonus'] = $bonus;
    $payroll['deductions'] = $deductions;
    $payroll['sales_commission'] = $salesCommission;
    $payroll['status'] = $status;
}

// Shown on the form, so the admin can see if "Paid" will be blocked.
$operatingFund = fund_by_code($conn, 'OPERATING');
$operatingAvailable = $operatingFund ? fund_available($conn, (int) $operatingFund['id']) : null;

include '../../includes/header.php'; include '../../includes/sidebar.php';

$modal_icon = 'bi-wallet2';
$modal_title = 'Edit Payroll';
$modal_subtitle = 'Update bonus, deductions, and payment status.';
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

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Employee</label>
                    <input class="form-control rm-input" value="<?= htmlspecialchars($payroll['employee_name'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Pay Period</label>
                    <input class="form-control rm-input" value="<?= date('F Y', strtotime($payroll['pay_period'])); ?>" disabled>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Basic Salary</label>
                    <input class="form-control rm-input" value="RWF <?= number_format((float) $payroll['basic_salary'], 2); ?>" disabled>
                </div>

                <div class="mb-3 p-3" style="background:#F8FAFC; border-radius:12px;">
                    <div class="row g-2 small">
                        <div class="col-6"><span class="text-muted">Absent Days:</span> <strong><?= (int) $payroll['absent_days']; ?></strong></div>
                        <div class="col-6"><span class="text-muted">Overtime:</span> <strong><?= round((int) $payroll['overtime_minutes'] / 60, 1); ?> hrs</strong></div>
                        <div class="col-6"><span class="text-muted">Attendance Deduction:</span> <strong class="text-danger">- RWF <?= number_format((float) $payroll['attendance_deduction'], 2); ?></strong></div>
                        <div class="col-6"><span class="text-muted">Overtime Pay:</span> <strong class="text-success">+ RWF <?= number_format((float) $payroll['overtime_pay'], 2); ?></strong></div>
                        <div class="col-6"><span class="text-muted">Avg Task Score:</span> <strong><?= $payroll['avg_performance_score'] !== null ? $payroll['avg_performance_score'] . '/100' : '—'; ?></strong></div>
                        <div class="col-6"><span class="text-muted">Performance Bonus:</span> <strong class="text-success">+ RWF <?= number_format((float) $payroll['performance_bonus'], 2); ?></strong></div>
                    </div>
                    <small class="text-muted d-block mt-2">These are locked to keep this payslip consistent with the attendance/task records for this period.</small>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-4">
                        <label class="form-label small fw-semibold text-muted">Sales Commission (RWF)</label>
                        <input type="number" name="sales_commission" class="form-control rm-input" min="0" step="0.01" value="<?= htmlspecialchars((string) $payroll['sales_commission'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label small fw-semibold text-muted">Bonus (RWF)</label>
                        <input type="number" name="bonus" class="form-control rm-input" min="0" step="0.01" value="<?= htmlspecialchars((string) $payroll['bonus'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label small fw-semibold text-muted">Deductions (RWF)</label>
                        <input type="number" name="deductions" class="form-control rm-input" min="0" step="0.01" value="<?= htmlspecialchars((string) $payroll['deductions'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Status</label>
                    <select name="status" class="form-select rm-input">
                        <option value="Draft" <?= $payroll['status'] === 'Draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="Paid" <?= $payroll['status'] === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                    </select>
                    <!-- NEW -->
                    <small class="text-muted">"Paid" is paid from the RM Business Operating Fund<?php if ($operatingAvailable !== null) { ?> (available now: <strong>RWF <?= number_format($operatingAvailable, 2); ?></strong>)<?php } ?>. Changing the amount of a Paid payroll, or setting it back to Draft, updates the fund automatically.</small>
                </div>

                <p class="text-muted mb-4">Net Salary: <strong>RWF <?= number_format((float) $payroll['net_salary'], 2); ?></strong></p>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="btn btn-primary rm-btn-primary" type="submit" name="update">
                        <i class="bi bi-check-circle-fill me-2"></i>Update Payroll
                    </button>
                    <a href="index.php" class="btn btn-light rm-btn-light">Cancel</a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>