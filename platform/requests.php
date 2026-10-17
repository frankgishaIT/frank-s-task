<?php
require_once __DIR__ . '/../includes/platform_web.php';
require_once __DIR__ . '/../includes/signup.php';

$admin = platform_require_admin();
$allowed = ['pending', 'approved', 'activating', 'provisioning', 'active', 'failed', 'rejected'];
$status = (is_string($_GET['status'] ?? null) && in_array($_GET['status'], $allowed, true)) ? $_GET['status'] : null;
$rows = signup_list($status);
platform_page_start('Sign-up requests', $admin, 'requests');
?>
<h4 class="mb-3">Sign-up requests</h4>
<p>
    <a class="btn btn-sm <?= $status === null ? 'btn-primary' : 'btn-outline-primary'; ?>" href="requests.php">All</a>
<?php foreach ($allowed as $s) { ?>
    <a class="btn btn-sm <?= $status === $s ? 'btn-primary' : 'btn-outline-primary'; ?>" href="requests.php?status=<?= h($s); ?>"><?= h($s); ?></a>
<?php } ?>
</p>
<div class="card p-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
<thead><tr><th>Reference</th><th>Company</th><th>Owner</th><th>Package</th><th>Due</th><th>Status</th><th>Sent</th></tr></thead><tbody>
<?php foreach ($rows as $r) { ?>
<tr><td><a href="request.php?id=<?= (int) $r['id']; ?>"><?= h($r['reference']); ?></a></td><td><?= h($r['company_name']); ?></td><td><?= h($r['owner_name']); ?><br><small class="text-muted"><?= h($r['owner_email']); ?></small></td><td><?= h($r['package_name']); ?> / <?= h($r['billing_period']); ?></td><td><?= h(signup_money((int) $r['amount_due'])); ?></td><td><?= h($r['status']); ?></td><td><?= h($r['created_at']); ?></td></tr>
<?php } ?>
<?php if (!$rows) { ?><tr><td colspan="7" class="text-muted">Nothing here.</td></tr><?php } ?>
</tbody></table></div></div>
<?php platform_page_end();
