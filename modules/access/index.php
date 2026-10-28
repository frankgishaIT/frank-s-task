<?php
/**
 * NEW FILE: modules/access/index.php
 * Task II: every employee, their roles and their access, with a review of high-risk access.
 */
require '_common.php';
$users = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names, role, is_active FROM users ORDER BY is_active DESC, names'), MYSQLI_ASSOC);
$rows = [];
foreach ($users as $u) {
    $b = access_user_breakdown($conn, (int) $u['id']);
    $high = array_values(array_filter($b['effective'], 'access_is_high_risk'));
    $rows[] = ['u' => $u, 'roles' => $b['roles'], 'total' => count($b['effective']), 'high' => $high, 'grants' => count($b['grants']), 'denies' => count($b['denies'])];
}
$allCount = count(access_all_permissions());
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success alert-dismissible fade show"><?= $e($_GET['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<h2 class="mb-1">Roles &amp; Permissions</h2>
<p class="text-muted small">Give each employee only the roles and actions their job needs. Every page checks these permissions on the server.
    The pages' own role rules (Admin / Manager / Employee) still apply on top.</p>
<?php access_tabs('index.php'); ?>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover bg-white mb-0 align-middle" style="font-size:13px;">
    <tr><th>Employee</th><th>Account type</th><th>Roles</th><th class="text-end">Permissions</th><th>High-risk access</th><th>Exceptions</th><th></th></tr>
    <?php foreach ($rows as $r) { $u = $r['u']; $hc = count($r['high']); ?>
    <tr<?= $u['is_active'] ? '' : ' class="text-muted"'; ?>>
        <td><?= $e($u['names']); ?><?= $u['is_active'] ? '' : ' <span class="badge bg-secondary">Inactive</span>'; ?></td>
        <td><?= $e(ucfirst(strtolower($u['role']))); ?></td>
        <td><?= $r['roles'] ? $e(implode(', ', $r['roles'])) : '<span class="text-danger">No role</span>'; ?></td>
        <td class="text-end"><?= $r['total']; ?> / <?= $allCount; ?></td>
        <td><?php if ($hc === 0) { ?><span class="text-muted">None</span>
            <?php } else { ?><span class="badge <?= strtolower($u['role']) === 'employee' && $hc > 0 ? 'bg-danger' : 'bg-primary'; ?>" title="<?= $e(implode(', ', array_map('access_label', $r['high']))); ?>"><?= $hc; ?> high-risk</span>
            <?php if (strtolower($u['role']) === 'employee') { ?> <span class="small text-danger">review: employee account</span><?php } ?><?php } ?></td>
        <td class="small"><?= $r['grants'] ? '+' . $r['grants'] . ' granted ' : ''; ?><?= $r['denies'] ? '−' . $r['denies'] . ' denied' : ''; ?><?= !$r['grants'] && !$r['denies'] ? '—' : ''; ?></td>
        <td><a href="user.php?id=<?= (int) $u['id']; ?>" class="rm-btn rm-btn-primary rm-btn-sm">Manage access</a></td>
    </tr>
    <?php } ?>
</table>
</div></div></div>
<p class="small text-muted mt-2">"High-risk" = deleting, approving, payroll, funds, financial reports, access management and executive deletion. An employee account holding them is flagged for review.</p>
<?php include '../../includes/footer.php'; ?>