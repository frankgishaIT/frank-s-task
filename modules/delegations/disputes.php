<?php
/** NEW FILE: modules/delegations/disputes.php — Payment disputes raised by RM Taskers (or by staff for them). */
require __DIR__ . '/common.php';
if (!dlc($conn, 'finance') && !dlc($conn, 'approve')) { dl_require($conn, 'finance'); }
$st = (string) ($_GET['status'] ?? 'open');
$w = $st === 'open' ? "x.status IN ('" . implode("','", DL_DISPUTE_OPEN) . "')" : (in_array($st, DL_DISPUTE_STATUSES, true) ? "x.status = '" . mysqli_real_escape_string($conn, $st) . "'" : '1=1');
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT x.*, bp.business_name, bp.name tname FROM dl_disputes x LEFT JOIN business_parties bp ON bp.id = x.party_id WHERE $w ORDER BY x.id DESC LIMIT 300"), MYSQLI_ASSOC);
$names = dl_users($conn);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'disputes.php', 'Payment disputes');
?>
<div class="d-flex flex-wrap gap-1 mb-2"><?php foreach (array_merge(['open' => 'Open'], array_combine(DL_DISPUTE_STATUSES, DL_DISPUTE_STATUSES), ['all' => 'All']) as $k => $l) { ?><a href="?status=<?= urlencode($k); ?>" class="btn btn-sm <?= $st === $k ? 'btn-primary' : 'btn-outline-primary'; ?> py-0"><?= dl_e($l); ?></a><?php } ?></div>
<div class="card"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0">
<thead><tr><th>Dispute</th><th>RM Tasker</th><th>Delegation</th><th>Issue</th><th class="text-end">Expected</th><th class="text-end">Received</th><th class="text-end">Disputed</th><th>Reviewer</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $x) { ?>
<tr><td><a href="dispute.php?id=<?= (int) $x['id']; ?>" class="fw-semibold"><?= dl_dispute_ref((int) $x['id']); ?></a><div class="small text-muted"><?= dl_e(date('d M Y', strtotime($x['created_at']))); ?> · <?= $x['reported_by'] === 'tasker' ? 'by the Tasker' : 'by staff'; ?></div></td>
    <td class="small"><?= dl_e(dl_tasker_name(['business_name' => $x['business_name'], 'name' => $x['tname'], 'id' => $x['party_id']])); ?></td>
    <td class="small"><a href="pay.php?id=<?= (int) $x['delegation_id']; ?>"><?= dl_ref((int) $x['delegation_id']); ?></a><?= $x['payment_reference'] ? '<div class="text-muted">' . dl_e($x['payment_reference']) . '</div>' : ''; ?></td>
    <td class="small"><?= dl_e($x['kind']); ?><div class="text-muted"><?= dl_e(mb_strimwidth($x['description'], 0, 80, '…')); ?></div></td>
    <td class="text-end small"><?= $x['expected_amount'] !== null ? dl_money($x['expected_amount']) : '—'; ?></td><td class="text-end small"><?= $x['amount_received'] !== null ? dl_money($x['amount_received']) : '—'; ?></td><td class="text-end small"><?= $x['disputed_amount'] !== null ? dl_money($x['disputed_amount']) : '—'; ?></td>
    <td class="small"><?= dl_e($names[(int) $x['assigned_to']] ?? '—'); ?></td><td><span class="dl-st <?= in_array($x['status'], DL_DISPUTE_OPEN, true) ? 'dl-bad' : 'dl-ok'; ?>"><?= dl_e($x['status']); ?></span></td></tr>
<?php } ?>
<?php if (!$rows) { ?><tr><td colspan="9" class="text-center text-muted py-5">No dispute here.</td></tr><?php } ?>
</tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>