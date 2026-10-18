<?php
require_once __DIR__ . '/../includes/platform_web.php';
require_once __DIR__ . '/../includes/signup.php';

$admin = platform_require_admin();
$c = signup_counts();
$age = signup_worker_age();
$waiting = $c['activating'] > 0;
$failedMails = 0;
try {
    $failedMails = (int) mysqli_fetch_row(mysqli_query(platform_conn(), "SELECT COUNT(*) FROM platform_emails WHERE status = 'failed' AND created_at > (NOW() - INTERVAL 1 DAY)"))[0];
} catch (Throwable $e) {
}
platform_page_start('Dashboard', $admin, 'dashboard');
?>
<?php if ($failedMails > 0) { ?>
<div class="alert alert-danger"><?= (int) $failedMails; ?> email(s) could not be sent in the last 24 hours. <a href="emails.php?status=failed">See why</a>.</div>
<?php } ?>
<h4 class="mb-3">Dashboard</h4>
<?php if ($waiting && ($age === null || $age > 300)) { ?>
<div class="alert alert-warning">A paid company is waiting to be created, but the worker has <?= $age === null ? 'never run' : 'not run for ' . (int) ($age / 60) . ' minutes'; ?>. It runs every minute from the server's scheduler (cron). Check that the cron line is installed.</div>
<?php } ?>
<div class="row g-3 mb-4">
<?php foreach ([
    ['Waiting for your decision', $c['pending'], 'requests.php?status=pending', 'warning'],
    ['Approved, waiting for payment', $c['approved'], 'requests.php?status=approved', 'info'],
    ['Being created', $c['activating'], 'requests.php?status=activating', 'secondary'],
    ['Failed (needs a retry)', $c['failed'], 'requests.php?status=failed', 'danger'],
    ['Active companies', $c['active_companies'], 'companies.php', 'success'],
    ['Suspended companies', $c['suspended_companies'], 'companies.php', 'dark'],
] as [$label, $n, $href, $color]) { ?>
    <div class="col-6 col-md-4"><a class="text-decoration-none" href="<?= h($href); ?>"><div class="card p-3 border-start border-4 border-<?= h($color); ?>"><div class="fs-2 fw-bold text-dark"><?= (int) $n; ?></div><div class="text-muted"><?= h($label); ?></div></div></a></div>
<?php } ?>
</div>
<div class="card p-3"><h6>Newest requests</h6>
<table class="table table-sm mb-0"><thead><tr><th>Reference</th><th>Company</th><th>Package</th><th>Status</th></tr></thead><tbody>
<?php foreach (array_slice(signup_list(), 0, 6) as $r) { ?>
<tr><td><a href="request.php?id=<?= (int) $r['id']; ?>"><?= h($r['reference']); ?></a></td><td><?= h($r['company_name']); ?></td><td><?= h($r['package_name']); ?></td><td><?= h($r['status']); ?></td></tr>
<?php } ?>
</tbody></table></div>
<?php platform_page_end();
