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
<?php
$dry = (bool) platform_setting('mail_dry_run', false);
$payOk = xentripay_configured();
$sandbox = $payOk && strpos((string) platform_setting('xentripay_base_url', ''), 'test') !== false;
$testMode = (bool) platform_setting('payment_test_mode', false);
$workerOk = $age !== null && $age <= 300;
$chips = [
    ['Email', $dry ? 'Test mode: nothing is sent' : 'Live: emails are really sent', $dry ? 'warning' : 'success', 'emails.php', $dry ? 'Open Emails to read them' : 'Send a test email'],
    ['Online payment', !$payOk ? 'Not set up yet' : ($sandbox ? 'Sandbox: no real money' : ($testMode ? 'Live, TEST amount ' . number_format((int) platform_setting('payment_test_amount', 100)) . ' RWF' : 'Live: real payments')), !$payOk ? 'danger' : ($sandbox || $testMode ? 'warning' : 'success'), null, null],
    ['Worker (creates companies)', $workerOk ? 'Running' : ($age === null ? 'Has never run' : 'Stopped ' . (int) ($age / 60) . ' min ago'), $workerOk ? 'success' : 'danger', null, null],
];
?>
<div class="row g-3 mb-4">
<?php foreach ($chips as [$label, $text, $color, $href, $linkText]) { ?>
    <div class="col-md-4"><div class="card p-3 h-100"><div class="small text-muted mb-1"><?= h($label); ?></div>
        <div><span class="badge bg-<?= h($color); ?>"><?= h($text); ?></span></div>
        <?php if ($href) { ?><div class="mt-2 small"><a href="<?= h($href); ?>"><?= h($linkText); ?> &rarr;</a></div><?php } ?>
    </div></div>
<?php } ?>
</div>
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
