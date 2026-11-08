<?php
// NEW FILE: modules/marketing/audit.php — Marketing audit trail (read-only).
require __DIR__ . '/mkt_common.php';
if (!access_can('marketing.reports', $conn) && !access_can('marketing.approve', $conn) && !access_can('marketing.manage', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
$q = trim((string) ($_GET['q'] ?? '')); $page = max(1, (int) ($_GET['page'] ?? 1)); $per = 50;
$w = $q !== '' ? 'WHERE a.action LIKE ? OR a.reference LIKE ? OR a.reason LIKE ? OR u.names LIKE ?' : '';
$bind = function ($s) use ($q) { if ($q !== '') { $l = "%$q%"; mysqli_stmt_bind_param($s, 'ssss', $l, $l, $l, $l); } };
$s = mysqli_prepare($conn, "SELECT COUNT(*) FROM mkt_audit a LEFT JOIN users u ON u.id = a.user_id $w"); $bind($s); mysqli_stmt_execute($s); $total = (int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0];
$s = mysqli_prepare($conn, "SELECT a.*, u.names FROM mkt_audit a LEFT JOIN users u ON u.id = a.user_id $w ORDER BY a.id DESC LIMIT " . (($page - 1) * $per) . ", $per"); $bind($s); mysqli_stmt_execute($s);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('audit.php');
?>
<form class="d-flex gap-2 mb-2" style="max-width:480px;"><input name="q" value="<?= $e($q); ?>" class="form-control form-control-sm" placeholder="Search action, reference, reason or user"><button class="btn btn-sm btn-primary">Search</button></form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:12.5px;">
    <tr><th class="ps-3">Date &amp; time</th><th>User</th><th>Action</th><th>Reference</th><th>Previous</th><th>New</th><th>Reason</th></tr>
    <?php if (!$rows) { ?><tr><td colspan="7" class="text-center text-muted py-3">Nothing yet.</td></tr><?php } ?>
    <?php foreach ($rows as $r) { ?><tr><td class="ps-3 text-nowrap"><?= date('d M Y, H:i:s', strtotime($r['created_at'])); ?></td><td><?= $e($r['names'] ?? 'System'); ?></td><td><?= $e($r['action']); ?></td><td class="text-nowrap"><?= $e($r['reference']); ?></td>
        <td class="small text-muted" style="max-width:220px; word-break:break-word;"><?= $e($r['previous_value']); ?></td><td class="small" style="max-width:220px; word-break:break-word;"><?= $e($r['new_value']); ?></td><td class="small"><?= $e($r['reason']); ?></td></tr><?php } ?>
</table></div></div></div>
<?php if ($total > $per) { ?><nav class="mt-2"><ul class="pagination pagination-sm"><?php for ($i = 1; $i <= min(50, (int) ceil($total / $per)); $i++) { ?><li class="page-item <?= $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?= $e(http_build_query(['page' => $i, 'q' => $q])); ?>"><?= $i; ?></a></li><?php } ?></ul></nav><?php } ?>
<?php include '../../includes/footer.php'; ?>