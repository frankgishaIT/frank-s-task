<?php
// NEW FILE: modules/budget/audit.php — the Budget & Fund audit trail (read-only: nobody can change history here).
require __DIR__ . '/budget_common.php';
$q = trim((string) ($_GET['q'] ?? ''));
$w = $q !== '' ? "WHERE a.action LIKE ? OR a.reference LIKE ? OR a.reason LIKE ? OR u.names LIKE ?" : '';
$s = mysqli_prepare($conn, "SELECT a.*, u.names FROM bdg_audit a LEFT JOIN users u ON u.id = a.user_id $w ORDER BY a.id DESC LIMIT 1000");
if ($q !== '') { $like = "%$q%"; mysqli_stmt_bind_param($s, 'ssss', $like, $like, $like, $like); }
mysqli_stmt_execute($s); $rows = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
bdg_tabs('audit.php');
?>
<form class="d-flex gap-2 mb-2" style="max-width:480px;"><input name="q" value="<?= $e($q); ?>" class="form-control form-control-sm" placeholder="Search action, reference, reason or user"><button class="btn btn-sm btn-primary">Search</button></form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:12.5px;">
    <tr><th class="ps-3">Date &amp; time</th><th>User</th><th>Action</th><th>Reference</th><th>Previous value</th><th>New value</th><th>Reason</th></tr>
    <?php if (!$rows) { ?><tr><td colspan="7" class="text-center text-muted py-3">Nothing yet.</td></tr><?php } ?>
    <?php foreach ($rows as $r) { ?><tr><td class="ps-3 text-nowrap"><?= date('d M Y, H:i:s', strtotime($r['created_at'])); ?></td><td><?= $e($r['names'] ?? 'System'); ?></td><td><?= $e($r['action']); ?></td><td class="text-nowrap"><?= $e($r['reference']); ?></td>
        <td class="small text-muted" style="max-width:220px; word-break:break-word;"><?= $e($r['previous_value']); ?></td><td class="small" style="max-width:220px; word-break:break-word;"><?= $e($r['new_value']); ?></td><td class="small"><?= $e($r['reason']); ?></td></tr><?php } ?>
</table></div></div></div>
<p class="small text-muted mt-2">Fund money movements themselves are in each fund's history (Funds &amp; transfers); deleted or reversed transactions are in Transactions → Audit Trail.</p>
<?php include '../../includes/footer.php'; ?>