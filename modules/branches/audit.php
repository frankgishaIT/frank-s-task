<?php
/** NEW FILE: modules/branches/audit.php — branch audit trail (registrations, edits, status, assignments, transfers, access refusals). Head Office. */
require __DIR__ . '/common.php';
br_require_setup($conn);
if (!br_head_office($conn)) { br_deny(); }
$bid = (int) ($_GET['branch'] ?? 0);
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT l.*, u.names, b.name bname FROM branch_audit_logs l LEFT JOIN users u ON u.id = l.user_id LEFT JOIN branches b ON b.id = l.branch_id'
    . ($bid ? ' WHERE l.branch_id = ' . $bid : '') . ' ORDER BY l.id DESC LIMIT 300'), MYSQLI_ASSOC);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
br_tabs($conn, 'audit.php');
?>
<form class="mb-2"><select name="branch" class="form-select form-select-sm" style="max-width:320px;" onchange="this.form.submit()"><option value="0">All branches</option>
    <?php foreach (br_all($conn) as $b) { ?><option value="<?= (int) $b['id']; ?>" <?= $bid === (int) $b['id'] ? 'selected' : ''; ?>><?= br_e($b['name']); ?></option><?php } ?></select></form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0" style="font-size:13px;">
    <tr><th class="ps-3">When</th><th>Who</th><th>Branch</th><th>Action</th><th>Details</th></tr>
    <?php foreach ($rows as $r) { ?><tr><td class="ps-3 text-nowrap"><?= br_e($r['created_at']); ?></td><td><?= br_e($r['names'] ?: 'System'); ?></td><td><?= br_e($r['bname'] ?: 'Company'); ?></td>
        <td><span class="badge bg-<?= $r['action'] === 'access_denied' ? 'danger' : 'light text-dark border'; ?>"><?= br_e($r['action']); ?></span></td><td class="small"><?= br_e($r['details']); ?></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="5" class="text-center text-muted py-4">No entries.</td></tr><?php } ?>
</table></div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>