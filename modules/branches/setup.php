<?php
/**
 * NEW FILE: modules/branches/setup.php — STEP 1 of multi-branch: register the existing operations as the Main Branch.
 * Shows what is in the database (the assessment) and exactly what will happen. Runs only when an Administrator
 * confirms that a database backup was taken. Additive and repeatable: nothing is deleted, no record is duplicated,
 * user accounts and passwords are untouched.
 */
require __DIR__ . '/common.php';
require_role(['Admin']);

$users = br_users($conn);
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!br_csrf_ok()) { $error = 'The page had expired. Please try again.'; }
    elseif (empty($_POST['backup_done']) || empty($_POST['approved'])) { $error = 'Please confirm the database backup and the management approval first.'; }
    else {
        $mgr = (int) ($_POST['manager_user_id'] ?? 0) ?: null;
        if ($mgr && !isset($users[$mgr])) { $mgr = null; }
        try {
            $log = br_run_setup($conn, (int) current_user_id(), $mgr);
            $_SESSION['branch_view'] = 0;
            br_go('index.php', 'success', "Multi-branch setup completed.\n" . implode("\n", $log ?: ['Everything was already set up; nothing changed.']));
        } catch (Throwable $e) {
            error_log('branch setup: ' . $e->getMessage());
            $error = 'The setup stopped: ' . $e->getMessage() . '. Records already attached stay attached; you can run the setup again safely.';
        }
    }
}
$a = br_assessment($conn);
$enabled = br_enabled($conn);
$labels = ['departments' => 'Departments', 'users' => 'Employees (user accounts)', 'attendance' => 'Attendance records', 'sales' => 'Sales', 'transactions' => 'Transactions (income & expenses)',
    'purchase_orders' => 'Purchase orders', 'tasks' => 'Tasks', 'assets' => 'Assets', 'loans' => 'Loans', 'payroll' => 'Payroll records', 'leave_requests' => 'Leave requests',
    'customers' => 'Customers (shared, remember the registering branch)', 'business_parties' => 'Business parties / suppliers (shared)'];

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<h2 class="mb-1">Branch Management · Setup</h2>
<p class="text-muted">Step 1: the current RISE MOTIVE operations become the <strong>Main Branch (Head Office)</strong>. One company, one platform, one database.</p>
<?php if ($error) { ?><div class="alert alert-danger"><?= br_e($error); ?></div><?php } ?>
<?php if ($enabled) { ?><div class="alert alert-success">Setup already done on <?= br_e(br_setting($conn, 'setup_at')); ?>. Running it again only attaches records that have no branch yet. <a href="index.php">Open Branch Management</a></div><?php } ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <h6>1. Existing system assessment</h6>
            <table class="table table-sm small mb-2">
                <tr><th>Records</th><th class="text-end">Now in MY MOTIVE</th><th>What happens</th></tr>
                <?php foreach ($a['counts'] as $t => $n) { ?>
                    <tr><td><?= br_e($labels[$t] ?? $t); ?></td><td class="text-end"><?= $n === null ? '<span class="text-muted">no table</span>' : number_format($n); ?></td>
                        <td class="text-muted"><?= $n === null ? '—' : (in_array($t, ['customers', 'business_parties'], true) ? 'stay shared; registered by Main Branch' : 'attached to the Main Branch'); ?></td></tr>
                <?php } ?>
            </table>
            <div class="small text-muted">Nothing is deleted, duplicated or recalculated. Financial records keep their amounts, references and dates; only an empty "branch" field is filled.</div>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6>2. Main Branch (from your existing company information)</h6>
            <table class="table table-sm small mb-0">
                <tr><td>Branch name</td><td><strong><?= br_e(BR_MAIN_NAME); ?></strong></td></tr>
                <tr><td>Branch code</td><td><strong><?= br_e(BR_MAIN_CODE); ?></strong></td></tr>
                <tr><td>Branch type</td><td>Head Office / Main Branch · Default branch</td></tr>
                <tr><td>Company</td><td><?= br_e($a['company']['name']); ?> Ltd · TIN <?= br_e($a['company']['tin']); ?> (company data stays separate from branch data)</td></tr>
                <tr><td>Location</td><td><?= br_e($a['company']['address']); ?></td></tr>
                <tr><td>Telephone</td><td><?= br_e($a['phone']); ?></td></tr>
                <tr><td>Opening date</td><td><strong><?= br_e(date('d M Y', strtotime($a['opening_date']))); ?></strong> <span class="text-muted">— earliest record in MY MOTIVE (<?= br_e($a['opening_source'] ?: 'none found'); ?>). You can correct it afterwards.</span></td></tr>
                <tr><td>Status</td><td><?= br_badge('Active'); ?></td></tr>
            </table>
        </div></div>
        <form method="POST" class="card border-0 shadow-sm"><div class="card-body"><?= br_csrf_field(); ?>
            <h6>3. Run the setup</h6>
            <label class="form-label small">Branch Manager of the Main Branch (authorized management)</label>
            <select name="manager_user_id" class="form-select mb-3"><option value="">Choose later</option>
                <?php foreach ($users as $u) { if (!in_array($u['role'], ['Admin', 'Manager'], true)) { continue; } ?><option value="<?= (int) $u['id']; ?>"><?= br_e($u['names'] . ' · ' . $u['role']); ?></option><?php } ?>
            </select>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="backup_done" id="b1" value="1"><label class="form-check-label" for="b1">A full <strong>database backup</strong> was taken today (hosting panel / phpMyAdmin → Export) and kept safely.</label></div>
            <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="approved" id="b2" value="1"><label class="form-check-label" for="b2">Management approved this change for production.</label></div>
            <button class="btn btn-primary" onclick="return confirm('Register the existing operations as the Main Branch now?');"><i class="bi bi-diagram-3"></i> <?= $enabled ? 'Run again (attach records without a branch)' : 'Register Main Branch and attach existing records'; ?></button>
            <div class="small text-muted mt-2">Employees keep their accounts and passwords. Administrators get Company View (all branches); everyone else works in the Main Branch until assigned elsewhere.</div>
        </div></form>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>