<?php
/** NEW FILE: modules/learning/audit.php — every sensitive Learning Centre action (who, what, when, from where). */
require __DIR__ . '/common.php';
lcs_require($conn, 'settings');
$t = preg_replace('/[^a-z_]/', '', (string) ($_GET['type'] ?? ''));
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT l.*, u.names FROM lc_audit l LEFT JOIN users u ON u.id = l.user_id' . ($t ? " WHERE l.ref_type = '$t'" : '') . ' ORDER BY l.id DESC LIMIT 500'), MYSQLI_ASSOC);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'audit.php', 'Audit Trail');
$link = ['application' => 'application.php?id=', 'course' => 'course.php?id=', 'intake' => 'intakes.php?edit='];
?>
<form class="mb-2"><select name="type" class="form-select form-select-sm" style="max-width:260px;" onchange="this.form.submit()"><option value="">All</option><?php foreach (['application', 'course', 'intake', 'branch', 'customer', 'settings'] as $x) { ?><option <?= $t === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0" style="font-size:12.5px;">
    <tr class="table-light"><th class="ps-3">When</th><th>Who</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr>
    <?php foreach ($rows as $r) { ?><tr><td class="ps-3 text-nowrap"><?= lc_e($r['created_at']); ?></td><td><?= lc_e($r['names'] ?: ($r['customer_id'] ? 'Applicant / customer' : 'Public')); ?></td><td><?= lc_e(str_replace('_', ' ', $r['action'])); ?></td>
        <td><?= isset($link[$r['ref_type']]) && $r['ref_id'] ? '<a href="' . $link[$r['ref_type']] . (int) $r['ref_id'] . '">' . lc_e($r['ref_type']) . ' #' . (int) $r['ref_id'] . '</a>' : lc_e($r['ref_type']); ?></td>
        <td class="small" style="max-width:480px;word-break:break-word;"><?= lc_e(mb_substr((string) $r['details'], 0, 400)); ?></td><td class="small text-muted"><?= lc_e($r['ip']); ?></td></tr><?php } ?>
</table></div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>