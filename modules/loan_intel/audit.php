<?php
/** NEW FILE: modules/loan_intel/audit.php — who created / edited / approved / rejected what, previous and new values, when, why. */
require __DIR__ . '/common.php';
if (!li_can($conn, 'approve') && !li_can($conn, 'configure')) { li_deny(); }
$type = preg_replace('/[^a-z_]/', '', (string) ($_GET['type'] ?? ''));
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT a.*, u.names FROM li_audit a LEFT JOIN users u ON u.id = a.user_id' . ($type ? " WHERE a.ref_type = '$type'" : '') . ' ORDER BY a.id DESC LIMIT 500'), MYSQLI_ASSOC);
if (($_GET['export'] ?? '') === 'csv') { li_csv('loan-intelligence-audit-' . date('Ymd') . '.csv', ['When', 'Who', 'Action', 'Record', 'Before', 'After', 'Reason'],
    array_map(function ($r) { return [$r['created_at'], $r['names'], $r['action'], $r['ref_type'] . ($r['ref_id'] ? ' #' . $r['ref_id'] : ''), $r['prev_json'], $r['new_json'], $r['reason']]; }, $rows)); }
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
li_tabs($conn, 'audit.php', 'Audit Trail');
?>
<div class="d-flex justify-content-between mb-2"><form><select name="type" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All records</option>
    <?php foreach (['proposal' => 'Proposals', 'loan' => 'Loans', 'recommendation' => 'Recommendations', 'scenario' => 'Loan scenarios', 'settings' => 'Settings'] as $k => $l) { ?><option value="<?= $k; ?>" <?= $type === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></form>
    <a href="?<?= li_e(http_build_query(['type' => $type, 'export' => 'csv'])); ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0" style="font-size:12.5px;">
    <tr class="table-light"><th class="ps-3">When</th><th>Who</th><th>Action</th><th>Record</th><th>Before → after</th><th>Reason</th></tr>
    <?php foreach ($rows as $r) { $link = ['proposal' => 'proposal.php?id=', 'recommendation' => 'recommendations.php?id=', 'loan' => 'loan_details.php?id='][$r['ref_type']] ?? null; ?>
    <tr><td class="ps-3 text-nowrap"><?= li_e($r['created_at']); ?></td><td><?= li_e($r['names'] ?: 'System'); ?></td><td><?= li_e(str_replace('_', ' ', $r['action'])); ?></td>
        <td><?= $link && $r['ref_id'] ? '<a href="' . $link . (int) $r['ref_id'] . '">' . li_e($r['ref_type']) . ' #' . (int) $r['ref_id'] . '</a>' : li_e($r['ref_type']); ?></td>
        <td class="small" style="max-width:420px;word-break:break-word;"><?= $r['prev_json'] && $r['prev_json'] !== 'null' ? '<span class="text-danger">' . li_e(mb_substr($r['prev_json'], 0, 300)) . '</span> → ' : ''; ?><span class="text-success"><?= li_e(mb_substr((string) $r['new_json'], 0, 300)); ?></span></td>
        <td class="small"><?= li_e($r['reason']); ?></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="6" class="text-center text-muted py-4">No entries.</td></tr><?php } ?>
</table></div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>