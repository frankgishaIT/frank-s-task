<?php
/** NEW FILE: modules/staff_cards/approvals.php — approve or reject announcements and Best Employee recognitions. */
require __DIR__ . '/common.php';
sdc_require($conn, 'approve');
$isAdmin = (($_SESSION['user_role'] ?? '') === 'Admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sdc_csrf_ok()) { sdc_go('approvals.php', 'error', 'The form expired. Please try again.'); }
    $c = sdc_card($conn, (int) ($_POST['id'] ?? 0));
    if (!$c || $c['status'] !== 'Pending') { sdc_go('approvals.php', 'error', 'This card is no longer waiting for approval.'); }
    $own = (int) $c['created_by'] === sdc_uid() || (int) $c['updated_by'] === sdc_uid();
    if ($own && !$isAdmin) { sdc_go('approvals.php', 'error', 'You prepared this card: another approver must decide.'); }
    $reason = trim((string) ($_POST['reason'] ?? ''));
    require_once __DIR__ . '/../../includes/notification_helper.php';
    if (($_POST['act'] ?? '') === 'approve') {
        sdc_set_status($conn, $c, 'Published', $reason ?: null);
        $msg = 'Approved: it appears on the Dashboard' . ($c['start_date'] && $c['start_date'] > date('Y-m-d') ? ' from ' . date('d M Y', strtotime($c['start_date'])) : '') . '.';
        if ($c['category'] === 'announcement') { $n = sdc_notify_audience($conn, $c); $msg .= ' ' . $n . ' staff notified.'; }
        if ($c['category'] === 'employee' && $c['user_id']) { notifyUser($conn, (int) $c['user_id'], 'You are ' . ($c['title'] ?: 'Best Employee of the Month'), 'Congratulations! Your recognition for ' . date('F Y', strtotime($c['month'] . '-01')) . ' is on the staff Dashboard.'); }
        if ($c['created_by'] && (int) $c['created_by'] !== sdc_uid()) { notifyUser($conn, (int) $c['created_by'], 'Dashboard card approved', SDC_CATS[$c['category']][0] . ': ' . $c['title']); }
        sdc_go('approvals.php', 'success', $msg);
    }
    if (($_POST['act'] ?? '') === 'reject') {
        if ($reason === '') { sdc_go('approvals.php', 'error', 'Give the reason for rejecting it.'); }
        sdc_set_status($conn, $c, 'Rejected', $reason);
        if ($c['created_by']) { notifyUser($conn, (int) $c['created_by'], 'Dashboard card rejected', SDC_CATS[$c['category']][0] . ': ' . $c['title'] . '. Reason: ' . $reason); }
        sdc_go('approvals.php', 'success', 'Rejected. The author was told why.');
    }
    sdc_go('approvals.php', 'error', 'Unknown action.');
}

$rows = array_map(function ($r) { $r['d'] = json_decode((string) $r['details'], true) ?: []; return $r; }, mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM sdc_cards WHERE status = 'Pending' ORDER BY updated_at"), MYSQLI_ASSOC));
$recent = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, c.category FROM sdc_audit a LEFT JOIN sdc_cards c ON c.id = a.card_id WHERE a.action IN ('approve', 'reject') ORDER BY a.id DESC LIMIT 15"), MYSQLI_ASSOC);
$names = sdc_user_names($conn);
$base = sdc_base();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
sdc_tabs($conn, 'approvals.php', 'Approvals');
echo sdc_css();
?>
<?php if (!$rows) { ?><div class="text-muted small mb-3">Nothing is waiting for approval.</div><?php } ?>
<?php foreach ($rows as $c) { $items = sdc_pool($conn, null, $c); $own = ((int) $c['created_by'] === sdc_uid() || (int) $c['updated_by'] === sdc_uid()); ?>
<div class="card mb-3"><div class="card-body py-2">
    <div class="small text-muted mb-1"><?= sdc_e(SDC_CATS[$c['category']][0]); ?> · prepared by <?= sdc_e($names[(int) ($c['updated_by'] ?: $c['created_by'])] ?? '?'); ?> on <?= sdc_e(date('d M Y H:i', strtotime((string) $c['updated_at']))); ?>
        <?php if ($c['category'] === 'announcement') { ?> · <?= sdc_e(sdc_audience_text($conn, (string) $c['audience'])); ?> · <?= sdc_e(date('d M', strtotime($c['start_date']))); ?> – <?= sdc_e(date('d M Y', strtotime($c['end_date']))); ?> · priority <?= (int) $c['priority']; ?><?php } ?>
        <?php if ($c['category'] === 'employee') { ?> · based on <?= ($c['d']['source'] ?? '') === 'kpi' ? 'approved KPI results' : 'a management decision' . (!empty($c['d']['reference']) ? ' (' . sdc_e($c['d']['reference']) . ')' : ''); ?><?php } ?></div>
    <?php if ($items) { ?><div class="sdc mb-2" style="max-width:860px"><div class="sdc-stage" style="min-height:0"><?= sdc_card_html($conn, $items[0], $base); ?></div></div><?php } ?>
    <?php if ($own && !$isAdmin) { ?><div class="small text-muted">You prepared this card: another approver must decide.</div>
    <?php } else { ?>
    <form method="post" class="d-flex flex-wrap gap-2 align-items-center"><?= sdc_csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $c['id']; ?>">
        <input name="reason" class="form-control form-control-sm" style="max-width:380px" placeholder="Note (required to reject)">
        <button name="act" value="approve" class="btn btn-success btn-sm">Approve</button>
        <button name="act" value="reject" class="btn btn-outline-danger btn-sm">Reject</button>
        <a href="edit.php?id=<?= (int) $c['id']; ?>" class="btn btn-link btn-sm">Open</a></form>
    <?php } ?>
</div></div>
<?php } ?>
<?php if ($recent) { ?>
<h6 class="mt-3">Recent decisions</h6>
<div class="card"><div class="table-responsive"><table class="table table-sm sdc-tbl mb-0"><thead><tr><th>When</th><th>Decision</th><th>Card</th><th>By</th><th>Reason</th></tr></thead><tbody>
<?php foreach ($recent as $a) { ?><tr><td class="text-nowrap"><?= sdc_e(date('d M Y H:i', strtotime($a['created_at']))); ?></td><td><?= $a['action'] === 'approve' ? 'Approved' : 'Rejected'; ?></td><td><?= sdc_e($a['detail']); ?></td><td><?= sdc_e($names[(int) $a['user_id']] ?? '?'); ?></td><td><?= sdc_e($a['reason']); ?></td></tr><?php } ?>
</tbody></table></div></div>
<?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>