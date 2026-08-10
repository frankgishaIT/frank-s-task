<?php
require '../../config/db.php';
require_role(['Admin']); $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT); if (!$id) { header('Location: index.php?success=Invalid payroll record selected.'); exit; } $recordStatement = mysqli_prepare($conn, 'SELECT payroll.*, users.names AS employee_name FROM payroll INNER JOIN users ON payroll.user_id = users.id WHERE payroll.id = ?'); mysqli_stmt_bind_param($recordStatement, 'i', $id); mysqli_stmt_execute($recordStatement); $payroll = mysqli_fetch_assoc(mysqli_stmt_get_result($recordStatement)); if (!$payroll) { header('Location: index.php?success=Payroll record not found.'); exit; }
if (isset($_POST['update'])) { $bonus = filter_input(INPUT_POST, 'bonus', FILTER_VALIDATE_FLOAT); $deductions = filter_input(INPUT_POST, 'deductions', FILTER_VALIDATE_FLOAT); $salesCommission = filter_input(INPUT_POST, 'sales_commission', FILTER_VALIDATE_FLOAT); $status = $_POST['status'] ?? ''; if ($bonus === false || $bonus < 0 || $deductions === false || $deductions < 0 || $salesCommission === false || $salesCommission < 0 || !in_array($status, ['Draft', 'Paid'], true)) { $error = 'Enter valid bonus, deductions, commission, and status.'; } else { $netSalary = (float) $payroll['basic_salary'] + (float) $payroll['overtime_pay'] + (float) $payroll['performance_bonus'] + $salesCommission + $bonus - $deductions - (float) $payroll['attendance_deduction']; $paidAt = $status === 'Paid' ? ($payroll['paid_at'] ?? date('Y-m-d H:i:s')) : null; $statement = mysqli_prepare($conn, 'UPDATE payroll SET bonus = ?, deductions = ?, sales_commission = ?, net_salary = ?, status = ?, paid_at = ? WHERE id = ?'); mysqli_stmt_bind_param($statement, 'ddddssi', $bonus, $deductions, $salesCommission, $netSalary, $status, $paidAt, $id); mysqli_stmt_execute($statement); header('Location: index.php?success=Payroll updated successfully.'); exit; } $payroll['bonus'] = $bonus; $payroll['deductions'] = $deductions; $payroll['sales_commission'] = $salesCommission; $payroll['status'] = $status; }
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
