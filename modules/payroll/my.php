<?php
require '../../config/db.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

$userId = current_user_id();
$statement = mysqli_prepare($conn, 'SELECT * FROM payroll WHERE user_id = ? ORDER BY pay_period DESC');
mysqli_stmt_bind_param($statement, 'i', $userId);
mysqli_stmt_execute($statement);
$payrolls = mysqli_stmt_get_result($statement);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>My Payslips</h2>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table table-bordered table-hover bg-white mb-0">
            <tr><th>Pay Period</th><th>Basic Salary</th><th>Net Salary</th><th>Status</th><th>Action</th></tr>
            <?php if (mysqli_num_rows($payrolls) === 0) { ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No payslips yet.</td></tr>
            <?php } ?>
            <?php while ($payroll = mysqli_fetch_assoc($payrolls)) { ?>
            <tr>
                <td><?= date('F Y', strtotime($payroll['pay_period'])); ?></td>
                <td>RWF <?= number_format((float) $payroll['basic_salary'], 2); ?></td>
                <td><strong>RWF <?= number_format((float) $payroll['net_salary'], 2); ?></strong></td>
                <td><span class="badge bg-<?= $payroll['status'] === 'Paid' ? 'success' : 'secondary'; ?>"><?= $payroll['status']; ?></span></td>
                <td><a href="payslip.php?id=<?= (int) $payroll['id']; ?>" target="_blank" class="btn btn-primary btn-sm">View / Download</a></td>
            </tr>
            <?php } ?>
        </table>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
