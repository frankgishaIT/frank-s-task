<?php
/** NEW FILE: modules/delegations/list.php — Delegation Requests: filter by status, RM Tasker, service, staff, dates; what needs action. */
require __DIR__ . '/common.php';
dl_require($conn, 'view');
$f = ['status' => (string) ($_GET['status'] ?? ''), 'tasker' => (int) ($_GET['tasker'] ?? 0), 'service' => (int) ($_GET['service'] ?? 0), 'staff' => (int) ($_GET['staff'] ?? 0),
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : '', 'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : '',
    'need' => (string) ($_GET['need'] ?? ''), 'q' => trim((string) ($_GET['q'] ?? ''))];
$now = dl_now();
$w = ['1=1'];
if ($f['status'] === 'open') { $w[] = "d.status IN ('" . implode("','", DL_LIVE) . "')"; }
elseif (in_array($f['status'], DL_STATUSES, true)) { $w[] = "d.status = '" . mysqli_real_escape_string($conn, $f['status']) . "'"; }
if ($f['tasker']) { $w[] = 'd.party_id = ' . $f['tasker']; }
if ($f['service']) { $w[] = 'd.product_id = ' . $f['service']; }
if ($f['staff']) { $w[] = 'd.created_by = ' . $f['staff']; }
if ($f['from']) { $w[] = "d.created_at >= '{$f['from']} 00:00:00'"; }
if ($f['to']) { $w[] = "d.created_at <= '{$f['to']} 23:59:59'"; }
if ($f['need'] === 'noanswer') { $w[] = "d.status = 'Awaiting Tasker Acceptance' AND d.response IS NULL"; }
if ($f['need'] === 'overdue') { $w[] = "d.status IN ('Awaiting Tasker Acceptance', 'Accepted', 'In Progress') AND d.deadline < '$now'"; }
if ($f['need'] === 'verify') { $w[] = "d.status = 'Completed – Awaiting Verification'"; }
if ($f['need'] === 'approval') { $w[] = "d.status = 'Pending Approval'"; }
if ($f['q'] !== '') { $x = dl_id_from_ref($f['q']); $w[] = $x ? 'd.id = ' . $x : (ctype_digit($f['q']) ? 'd.sale_id = ' . (int) $f['q'] : '1=0'); }
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT d.*, p.product_name, bp.business_name, bp.name tname, c.name customer FROM dl_delegations d LEFT JOIN products p ON p.id = d.product_id
    LEFT JOIN business_parties bp ON bp.id = d.party_id LEFT JOIN customers c ON c.id = d.customer_id WHERE ' . implode(' AND ', $w) . ' ORDER BY d.id DESC LIMIT 500'), MYSQLI_ASSOC);
$cnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(CASE WHEN status = 'Awaiting Tasker Acceptance' AND response IS NULL THEN 1 ELSE 0 END) noanswer,
    SUM(CASE WHEN status IN ('Awaiting Tasker Acceptance', 'Accepted', 'In Progress') AND deadline < '$now' THEN 1 ELSE 0 END) overdue,
    SUM(CASE WHEN status = 'Completed – Awaiting Verification' THEN 1 ELSE 0 END) verify, SUM(CASE WHEN status = 'Pending Approval' THEN 1 ELSE 0 END) approval FROM dl_delegations"));
$taskers = mysqli_fetch_all(mysqli_query($conn, "SELECT bp.id, COALESCE(NULLIF(bp.business_name, ''), bp.name) n FROM business_parties bp WHERE bp.type = 'Tasker' ORDER BY n"), MYSQLI_ASSOC);
$services = mysqli_fetch_all(mysqli_query($conn, 'SELECT DISTINCT p.id, p.product_name FROM dl_delegations d JOIN products p ON p.id = d.product_id ORDER BY p.product_name'), MYSQLI_ASSOC);
$names = dl_users($conn);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'list.php', 'Delegation requests', dlc($conn, 'create') ? '<a href="new.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Delegate a sale</a>' : '');
?>
<div class="d-flex flex-wrap gap-2 mb-2">
<?php foreach (['approval' => 'Waiting for approval', 'noanswer' => 'No Tasker answer', 'overdue' => 'Deadline missed', 'verify' => 'To verify'] as $k => $l) { ?>
    <a href="?need=<?= $k; ?>" class="text-decoration-none border rounded-3 px-2 py-1 small <?= $f['need'] === $k ? 'border-primary' : ''; ?> <?= (int) $cnt[$k] ? 'text-danger fw-semibold' : 'text-muted'; ?>"><?= $l; ?>: <?= (int) $cnt[$k]; ?></a>
<?php } ?>
</div>
<form class="row g-2 mb-2">
    <div class="col-6 col-md-2"><input name="q" value="<?= dl_e($f['q']); ?>" class="form-control form-control-sm" placeholder="DL number or sale #"></div>
    <div class="col-6 col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Any status</option><option value="open" <?= $f['status'] === 'open' ? 'selected' : ''; ?>>Open (not finished)</option><?php foreach (DL_STATUSES as $s) { ?><option <?= $f['status'] === $s ? 'selected' : ''; ?>><?= dl_e($s); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><select name="tasker" class="form-select form-select-sm"><option value="">Any RM Tasker</option><?php foreach ($taskers as $t) { ?><option value="<?= (int) $t['id']; ?>" <?= $f['tasker'] === (int) $t['id'] ? 'selected' : ''; ?>><?= dl_e($t['n']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><select name="service" class="form-select form-select-sm"><option value="">Any service</option><?php foreach ($services as $s) { ?><option value="<?= (int) $s['id']; ?>" <?= $f['service'] === (int) $s['id'] ? 'selected' : ''; ?>><?= dl_e($s['product_name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-1"><input type="date" name="from" value="<?= dl_e($f['from']); ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-1"><input type="date" name="to" value="<?= dl_e($f['to']); ?>" class="form-control form-control-sm"></div>
    <div class="col-12 col-md-2 d-flex gap-1"><button class="btn btn-outline-primary btn-sm">Filter</button><a href="list.php" class="btn btn-link btn-sm">Clear</a></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0">
<thead><tr><th>Delegation</th><th>Service</th><th>RM Tasker</th><th>Response</th><th>Status</th><th>Deadline</th><th class="text-end">Tasker payment</th><th>By</th></tr></thead><tbody>
<?php foreach ($rows as $r) { $late = in_array($r['status'], DL_WORKING, true) && $r['deadline'] && $r['deadline'] < $now; ?>
<tr><td><a href="view.php?id=<?= (int) $r['id']; ?>" class="fw-semibold text-decoration-none"><?= dl_ref((int) $r['id']); ?></a><div class="small text-muted">sale #<?= (int) $r['sale_id']; ?> · <?= dl_e($r['customer'] ?: 'Walk-in'); ?></div></td>
    <td class="small"><?= dl_e($r['product_name']); ?><?= (int) $r['urgent'] ? ' <span class="dl-st dl-bad">urgent</span>' : ''; ?></td>
    <td class="small"><?= dl_e(dl_tasker_name(['business_name' => $r['business_name'], 'name' => $r['tname'], 'id' => $r['party_id']])); ?></td>
    <td class="small <?= $r['response'] === 'Staff-confirmed' ? 'text-warning' : ($r['response'] ? '' : 'text-muted'); ?>"><?= dl_e(dl_response_text($r['response'])); ?></td>
    <td><?= dl_status_badge($r['status']); ?></td>
    <td class="small text-nowrap <?= $late ? 'text-danger fw-semibold' : ''; ?>"><?= dl_e(dl_deadline_text($r['deadline'])); ?></td>
    <td class="text-end small"><?= dl_money($r['pay_due'] ?? $r['pay_exp']); ?></td>
    <td class="small"><?= dl_e($names[(int) $r['created_by']] ?? ''); ?></td></tr>
<?php } ?>
<?php if (!$rows) { ?><tr><td colspan="8" class="text-center text-muted py-5">No delegation here.</td></tr><?php } ?>
</tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>