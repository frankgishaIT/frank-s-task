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
$dry = (bool) platform_setting('mail_dry_run', false);
$payOk = xentripay_configured();
$sandbox = $payOk && strpos((string) platform_setting('xentripay_base_url', ''), 'test') !== false;
$testMode = (bool) platform_setting('payment_test_mode', false);
$workerOk = $age !== null && $age <= 300;
platform_page_start('Dashboard', $admin, 'dashboard');
?>
<h4>Dashboard</h4>
<?php if ($failedMails > 0) { ?>
<div class="alert alert-danger"><?= (int) $failedMails; ?> email(s) could not be sent in the last 24 hours. <a href="emails.php?status=failed">See why</a>.</div>
<?php } ?>
<?php if ($waiting && ($age === null || $age > 300)) { ?>
<div class="alert alert-warning">A paid company is waiting to be created, but the worker has <?= $age === null ? 'never run' : 'not run for ' . (int) ($age / 60) . ' minutes'; ?>. It runs every minute from the server's scheduler (cron). Check that the cron line is installed.</div>
<?php } ?>

<div class="row g-3 mb-4">
<?php foreach ([
    ['Waiting for your decision', $c['pending'], 'requests.php?status=pending', 'warn', 'users'],
    ['Approved, waiting for payment', $c['approved'], 'requests.php?status=approved', 'info', 'card'],
    ['Being created', $c['activating'], 'requests.php?status=activating', 'grey', 'clock'],
    ['Failed (needs a retry)', $c['failed'], 'requests.php?status=failed', 'bad', 'alert'],
    ['Active companies', $c['active_companies'], 'companies.php', 'ok', 'building'],
    ['Suspended companies', $c['suspended_companies'], 'companies.php', 'dark', 'lock'],
] as [$label, $n, $href, $tone, $icon]) { ?>
    <div class="col-12 col-md-6 col-xl-4"><a class="mma-stat tone-<?= h($tone); ?>" href="<?= h($href); ?>"><span class="ic"><?= mm_icon($icon, 24); ?></span><span><div class="n"><?= (int) $n; ?></div><div class="l"><?= h($label); ?></div></span></a></div>
<?php } ?>
</div>

<h6 class="mb-3">System health</h6>
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="mma-health"><span class="dot <?= $dry ? 'warn' : 'ok'; ?>"></span><div><div class="t">Email</div><div class="s"><?= $dry ? 'Test mode: nothing is sent' : 'Live: emails are really sent'; ?></div><a class="small" href="emails.php"><?= $dry ? 'Open Emails to read them' : 'Send a test email'; ?> &rarr;</a></div></div></div>
    <div class="col-md-4"><div class="mma-health"><span class="dot <?= !$payOk ? 'bad' : ($sandbox || $testMode ? 'warn' : 'ok'); ?>"></span><div><div class="t">Online payment</div><div class="s"><?= !$payOk ? 'Not set up yet' : ($sandbox ? 'Sandbox: no real money' : ($testMode ? 'Live, TEST amount ' . number_format((int) platform_setting('payment_test_amount', 100)) . ' RWF' : 'Live: real payments')); ?></div></div></div></div>
    <div class="col-md-4"><div class="mma-health"><span class="dot <?= $workerOk ? 'ok' : 'bad'; ?>"></span><div><div class="t">Worker (creates companies)</div><div class="s"><?= $workerOk ? 'Running' : ($age === null ? 'Has never run' : 'Stopped ' . (int) ($age / 60) . ' min ago'); ?></div></div></div></div>
</div>

<div class="card p-4"><h6 class="mb-3">Newest requests</h6>
<div class="table-responsive"><table class="table"><thead><tr><th>Reference</th><th>Company</th><th>Package</th><th>Status</th></tr></thead><tbody>
<?php foreach (array_slice(signup_list(), 0, 6) as $r) { $tone = ['active' => 'success', 'approved' => 'info', 'pending' => 'warning', 'rejected' => 'secondary', 'failed' => 'danger'][$r['status']] ?? 'secondary'; ?>
<tr><td><a href="request.php?id=<?= (int) $r['id']; ?>"><?= h($r['reference']); ?></a></td><td><?= h($r['company_name']); ?></td><td><?= h($r['package_name']); ?></td><td><span class="badge text-bg-<?= $tone; ?>"><?= h($r['status']); ?></span></td></tr>
<?php } ?>
<?php if (!signup_list()) { ?><tr><td colspan="4" class="text-muted">No requests yet. They appear here when a company signs up.</td></tr><?php } ?>
</tbody></table></div></div>
<?php platform_page_end();
