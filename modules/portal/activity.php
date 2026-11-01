<?php
// NEW FILE: modules/portal/activity.php — what customers did in the portal, including invoice / receipt lookups.
require __DIR__ . '/portal_common.php';
$only = $_GET['only'] ?? '';
$where = $only === 'lookups' ? "a.action LIKE 'lookup%'" : ($only === 'security' ? "a.action IN ('login_failed', 'otp_sent', 'activated', 'password_reset', 'staff_block', 'staff_allow', 'staff_reset')" : '1 = 1');
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, c.name FROM portal_activity a LEFT JOIN customers c ON c.id = a.customer_id WHERE $where ORDER BY a.id DESC LIMIT 500"), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
portal_staff_tabs('activity.php');
?>
<div class="btn-group btn-group-sm mb-3"><?php foreach (['' => 'Everything', 'lookups' => 'Invoice / receipt lookups', 'security' => 'Sign-in & security'] as $k => $l) { ?><a href="activity.php?only=<?= $k; ?>" class="btn <?= $only === $k ? 'btn-primary' : 'btn-outline-primary'; ?>"><?= $l; ?></a><?php } ?></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm bg-white mb-0 align-middle" style="font-size:12.5px;">
    <tr><th class="ps-3">Date &amp; time</th><th>Customer</th><th>Action</th><th>Details</th><th>IP</th></tr>
    <?php if (!$rows) { ?><tr><td colspan="5" class="text-center text-muted py-3">Nothing yet.</td></tr><?php } ?>
    <?php foreach ($rows as $r) { ?><tr<?= in_array($r['action'], ['login_failed', 'lookup_not_found'], true) ? ' class="text-danger"' : ''; ?>>
        <td class="ps-3 text-nowrap"><?= date('d M Y, H:i', strtotime($r['created_at'])); ?></td><td><?= $e($r['name'] ?? '—'); ?></td><td><?= $e(str_replace('_', ' ', $r['action'])); ?></td><td><?= $e($r['details']); ?></td><td class="text-muted"><?= $e($r['ip']); ?></td></tr><?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>