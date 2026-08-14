<?php
require '../../config/db.php';
$pageSearchScope = 'payroll'; // tells the topbar search what module we're in
require_role(['Admin']);
require '../../includes/pagination.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';
const PER_PAGE = 10;
$currentPage = get_current_page();
$totalRows = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM payroll'))['c'];
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;
$payrolls = mysqli_query($conn, 'SELECT payroll.*, users.names AS employee_name, departments.name AS department_name FROM payroll INNER JOIN users ON payroll.user_id = users.id LEFT JOIN departments ON users.department_id = departments.id ORDER BY payroll.pay_period DESC, users.names LIMIT ' . PER_PAGE . ' OFFSET ' . $offset);
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<div class="d-flex justify-content-between align-items-center mb-4"><h2>Payroll Management</h2><a href="create.php" class="btn btn-primary">+ Generate Payroll</a></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-bordered table-hover bg-white mb-0">
<tr><th>Pay Period</th><th>Employee</th><th>Basic</th><th>Attendance</th><th>Overtime</th><th>Performance</th><th>Commission</th><th>Bonus</th><th>Deductions</th><th>Net Salary</th><th>Status</th><th>Action</th></tr>
<?php if (mysqli_num_rows($payrolls) === 0) { ?><tr><td colspan="12" class="text-center text-muted py-4">No payroll records generated yet.</td></tr><?php } ?>
<?php while ($payroll = mysqli_fetch_assoc($payrolls)) { ?>
<tr>
    <td><?= date('F Y', strtotime($payroll['pay_period'])); ?></td>
    <td><?= htmlspecialchars($payroll['employee_name'], ENT_QUOTES, 'UTF-8'); ?></td>
    <td>RWF <?= number_format((float) $payroll['basic_salary'], 2); ?></td>
    <td class="text-danger">-<?= number_format((float) $payroll['attendance_deduction'], 2); ?><br><small class="text-muted"><?= (int) $payroll['absent_days']; ?> absent day(s)</small></td>
    <td class="text-success">+<?= number_format((float) $payroll['overtime_pay'], 2); ?></td>
    <td class="text-success">+<?= number_format((float) $payroll['performance_bonus'], 2); ?><br><small class="text-muted"><?= $payroll['avg_performance_score'] !== null ? $payroll['avg_performance_score'] . '/100' : '—'; ?></small></td>
    <td>+<?= number_format((float) $payroll['sales_commission'], 2); ?></td>
    <td>+<?= number_format((float) $payroll['bonus'], 2); ?></td>
    <td class="text-danger">-<?= number_format((float) $payroll['deductions'], 2); ?></td>
    <td><strong>RWF <?= number_format((float) $payroll['net_salary'], 2); ?></strong></td>
    <td><span class="badge bg-<?= $payroll['status'] === 'Paid' ? 'success' : 'secondary'; ?>"><?= $payroll['status']; ?></span></td>
    <td class="text-nowrap"><a href="payslip.php?id=<?= (int) $payroll['id']; ?>" target="_blank" class="btn btn-outline-primary btn-sm">Payslip</a> <a href="edit.php?id=<?= (int) $payroll['id']; ?>" class="btn btn-warning btn-sm">Edit</a> <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('Delete this payroll record?')"><input type="hidden" name="id" value="<?= (int) $payroll['id']; ?>"><button type="submit" class="btn btn-danger btn-sm">Delete</button></form></td>
</tr>
<?php } ?>
</table>
</div><?php render_pagination($currentPage, $totalPages); ?></div></div>
<?php include '../../includes/footer.php'; ?>
