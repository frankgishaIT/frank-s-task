<?php
/**
 * NEW FILE: modules/delegations/issues.php — SERVICE QUALITY & ISSUE TRACKING: every complaint, mistake, late delivery
 * and its financial impact, the responsibility finding and the resolution. Report a new issue from a delegation
 * (?new=<delegation id>). Reporting: "create"; investigating: "quality" (issue.php).
 */
require __DIR__ . '/common.php';
dl_require($conn, 'view');
$canReport = dlc($conn, 'create');
$names = dl_users($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dl_csrf_ok()) { dl_go('issues.php', 'error', 'The form expired. Please try again.'); }
    $did = (int) ($_POST['delegation_id'] ?? 0);
    $d = dl_get($conn, $did);
    $back = 'issues.php?new=' . $did;
    if (!$canReport || !$d) { dl_go('issues.php', 'error', 'Not allowed.'); }
    $type = in_array($_POST['type'] ?? '', DLQ_TYPES, true) ? $_POST['type'] : '';
    $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 4000);
    $rep = in_array($_POST['reporter'] ?? '', DLQ_REPORTERS, true) ? $_POST['reporter'] : 'Staff';
    $repName = mb_substr(trim((string) ($_POST['reporter_name'] ?? '')), 0, 150);
    $ik = in_array($_POST['impact_kind'] ?? '', DLQ_IMPACT, true) ? $_POST['impact_kind'] : 'None';
    $amt = round(max(0, (float) str_replace(',', '', (string) ($_POST['impact_amount'] ?? '0'))), 2);
    $inote = mb_substr(trim((string) ($_POST['impact_note'] ?? '')), 0, 500);
    if ($type === '') { dl_go($back, 'error', 'Choose the issue type.'); }
    if (mb_strlen($desc) < 10) { dl_go($back, 'error', 'Describe the issue (what happened, when, who noticed it).'); }
    if ($ik !== 'None' && $amt <= 0) { dl_go($back, 'error', 'Give the amount of the financial impact, or choose "None".'); }
    if ($amt > 0 && $ik === 'None') { $ik = 'Other cost'; }
    $iid = dlq_create($conn, $d, $type, $desc, $rep, $rep === 'Staff' && $repName === '' ? ($names[dl_uid()] ?? '') : $repName, $ik, $amt, $inote, !empty($_POST['hold']));
    dl_go('issue.php?id=' . $iid, 'success', dlq_ref($iid) . ' recorded. It does not affect the Tasker\'s rating until it is investigated and a finding is made.');
}

$new = (int) ($_GET['new'] ?? 0);
$nd = $new ? dl_get($conn, $new) : null;
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime('-12 months'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$f = ['status' => in_array($_GET['status'] ?? '', array_merge(DLQ_STATUSES, ['open']), true) ? $_GET['status'] : '', 'type' => in_array($_GET['type'] ?? '', DLQ_TYPES, true) ? $_GET['type'] : '',
    'resp' => in_array($_GET['resp'] ?? '', DLQ_RESP, true) ? $_GET['resp'] : '', 'party' => (int) ($_GET['party'] ?? 0), 'q' => trim((string) ($_GET['q'] ?? ''))];
$w = ["i.reported_at >= '$from 00:00:00'", "i.reported_at <= '$to 23:59:59'"];
if ($f['status'] === 'open') { $w[] = "i.status IN ('Open', 'Under Investigation')"; } elseif ($f['status']) { $w[] = "i.status = '" . mysqli_real_escape_string($conn, $f['status']) . "'"; }
if ($f['type']) { $w[] = "i.type = '" . mysqli_real_escape_string($conn, $f['type']) . "'"; }
if ($f['resp']) { $w[] = "i.responsibility = '" . mysqli_real_escape_string($conn, $f['resp']) . "'"; }
if ($f['party']) { $w[] = 'i.party_id = ' . $f['party']; }
if (preg_match('/^QI0*(\d+)$/i', $f['q'], $m)) { $w[] = 'i.id = ' . (int) $m[1]; } elseif (($x = dl_id_from_ref($f['q'])) > 0) { $w[] = 'i.delegation_id = ' . $x; }
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT i.*, p.product_name FROM dl_issues i LEFT JOIN products p ON p.id = i.product_id WHERE ' . implode(' AND ', $w) . ' ORDER BY i.id DESC'), MYSQLI_ASSOC);
$tk = [];
foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT bp.*, t.id tasker_no FROM dl_taskers t JOIN business_parties bp ON bp.id = t.party_id WHERE bp.type = 'Tasker' ORDER BY bp.name"), MYSQLI_ASSOC) as $t) { $tk[(int) $t['id']] = dl_tasker_name($t); }
$k = ['open' => 0, 'inv' => 0, 'upheld' => 0, 'cost' => 0, 'recovered' => 0];
foreach ($rows as $r) {
    if ($r['status'] === 'Open') { $k['open']++; } if ($r['status'] === 'Under Investigation') { $k['inv']++; }
    if (dlq_upheld($r)) { $k['upheld']++; } $k['cost'] += (float) $r['impact_amount'];
    if ($r['recovery_status'] === 'Recovered') { $k['recovered'] += (float) $r['recovery_amount']; }
}
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="delegation_issues_' . $from . '_' . $to . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Issue ID', 'Delegation ID', 'Tasker', 'Service', 'Issue type', 'Description', 'Reported by', 'Date reported', 'Financial impact', 'Impact type', 'Responsibility', 'Findings', 'Corrective action', 'Resolution', 'Status', 'Recovery', 'Recovery status']);
    foreach ($rows as $r) { fputcsv($o, [dlq_ref((int) $r['id']), dl_ref((int) $r['delegation_id']), $tk[(int) $r['party_id']] ?? '', $r['product_name'], $r['type'], $r['description'], $r['reported_by_kind'] . ($r['reporter_name'] ? ' – ' . $r['reporter_name'] : ''), $r['reported_at'], $r['impact_amount'], $r['impact_kind'], $r['responsibility'], $r['findings'], $r['corrective_action'], $r['resolution'], $r['status'], $r['recovery_amount'], $r['recovery_status']]); }
    exit;
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'issues.php', 'Service quality & issues', '<a class="btn btn-outline-secondary btn-sm" href="?' . dl_e(http_build_query(array_merge($_GET, ['export' => 'csv']))) . '"><i class="bi bi-download"></i> Excel (CSV)</a>');
?>
<?php if ($nd && $canReport) { $pos = dl_pay_position($conn, $nd); $holdDefault = $pos['outstanding'] > 0 || !$nd['verified_at']; ?>
<form method="post" class="card mb-3"><div class="card-body py-2" style="max-width:980px"><?= dl_csrf_field(); ?><input type="hidden" name="delegation_id" value="<?= (int) $nd['id']; ?>">
    <div class="fw-semibold mb-1">Report an issue on <a href="view.php?id=<?= (int) $nd['id']; ?>"><?= dl_e($nd['ref']); ?></a> · <?= dl_e($nd['product_name']); ?> · <?= dl_e($nd['tasker']['display'] ?? '—'); ?></div>
    <div class="row g-2">
        <div class="col-md-4"><label class="form-label small mb-0">Issue type</label><select name="type" class="form-select form-select-sm" required><option value="">Choose…</option><?php foreach (DLQ_TYPES as $x) { ?><option><?= $x; ?></option><?php } ?></select></div>
        <div class="col-md-3"><label class="form-label small mb-0">Reported by</label><select name="reporter" class="form-select form-select-sm"><?php foreach (DLQ_REPORTERS as $x) { ?><option><?= $x; ?></option><?php } ?></select></div>
        <div class="col-md-5"><label class="form-label small mb-0">Reporter's name <span class="text-muted">(customer or staff, optional)</span></label><input name="reporter_name" class="form-control form-control-sm" maxlength="150"></div>
        <div class="col-12"><label class="form-label small mb-0">What happened</label><textarea name="description" rows="3" class="form-control form-control-sm" required minlength="10"></textarea></div>
        <div class="col-md-3"><label class="form-label small mb-0">Financial impact</label><select name="impact_kind" class="form-select form-select-sm"><?php foreach (DLQ_IMPACT as $x) { ?><option><?= $x; ?></option><?php } ?></select></div>
        <div class="col-md-3"><label class="form-label small mb-0">Cost to RISE MOTIVE (RWF)</label><input name="impact_amount" type="number" min="0" step="1" value="0" class="form-control form-control-sm"></div>
        <div class="col-md-6"><label class="form-label small mb-0">How the cost arises <span class="text-muted">(optional)</span></label><input name="impact_note" class="form-control form-control-sm" maxlength="500" placeholder="e.g. reprint of 5 A3 posters"></div>
    </div>
    <label class="small d-block mt-2"><input type="checkbox" name="hold" value="1" <?= $holdDefault ? 'checked' : ''; ?>> Put the delegation in <b>Issue Review</b> and hold the Tasker payment until it is resolved (a legitimate hold, never shown as overdue)</label>
    <div class="small text-muted mb-2">Nothing is deducted from the Tasker and their rating does not change until the issue is investigated and a finding is made.</div>
    <button class="btn btn-danger btn-sm">Record the issue</button> <a href="view.php?id=<?= (int) $nd['id']; ?>" class="btn btn-link btn-sm">Cancel</a>
</div></form>
<?php } ?>
<div class="row g-2 mb-2">
    <div class="col-6 col-md"><div class="dl-kpi"><div class="v text-danger"><?= $k['open']; ?></div><div class="l">Open</div></div></div>
    <div class="col-6 col-md"><div class="dl-kpi"><div class="v"><?= $k['inv']; ?></div><div class="l">Under investigation</div></div></div>
    <div class="col-6 col-md"><div class="dl-kpi"><div class="v"><?= $k['upheld']; ?></div><div class="l">Upheld against a Tasker</div></div></div>
    <div class="col-6 col-md"><div class="dl-kpi"><div class="v"><?= dl_money($k['cost']); ?></div><div class="l">Total cost of errors</div></div></div>
    <div class="col-6 col-md"><div class="dl-kpi"><div class="v"><?= dl_money($k['recovered']); ?></div><div class="l">Recovered (authorized)</div></div></div>
</div>
<form class="d-flex flex-wrap gap-2 align-items-end mb-2">
    <div><label class="form-label small mb-0">From</label><input type="date" name="from" value="<?= dl_e($from); ?>" class="form-control form-control-sm"></div>
    <div><label class="form-label small mb-0">To</label><input type="date" name="to" value="<?= dl_e($to); ?>" class="form-control form-control-sm"></div>
    <div><label class="form-label small mb-0">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><option value="open" <?= $f['status'] === 'open' ? 'selected' : ''; ?>>Not resolved</option><?php foreach (DLQ_STATUSES as $x) { ?><option <?= $f['status'] === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
    <div><label class="form-label small mb-0">Type</label><select name="type" class="form-select form-select-sm"><option value="">All</option><?php foreach (DLQ_TYPES as $x) { ?><option <?= $f['type'] === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
    <div><label class="form-label small mb-0">Responsibility</label><select name="resp" class="form-select form-select-sm"><option value="">All</option><?php foreach (DLQ_RESP as $x) { ?><option <?= $f['resp'] === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
    <div><label class="form-label small mb-0">RM Tasker</label><select name="party" class="form-select form-select-sm"><option value="">All</option><?php foreach ($tk as $id => $n) { ?><option value="<?= $id; ?>" <?= $f['party'] === $id ? 'selected' : ''; ?>><?= dl_e($n); ?></option><?php } ?></select></div>
    <div><label class="form-label small mb-0">QI / DL number</label><input name="q" value="<?= dl_e($f['q']); ?>" class="form-control form-control-sm" style="width:120px"></div>
    <button class="btn btn-outline-primary btn-sm">Show</button>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0">
<thead><tr><th>Issue</th><th>Delegation</th><th>Tasker</th><th>Type</th><th>Reported</th><th class="text-end">Cost</th><th>Responsibility</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $r) { ?>
<tr><td><a href="issue.php?id=<?= (int) $r['id']; ?>"><?= dlq_ref((int) $r['id']); ?></a></td><td><a href="view.php?id=<?= (int) $r['delegation_id']; ?>"><?= dl_ref((int) $r['delegation_id']); ?></a><div class="small text-muted"><?= dl_e($r['product_name']); ?></div></td>
    <td class="small"><?= dl_e($tk[(int) $r['party_id']] ?? '—'); ?></td><td class="small"><?= dl_e($r['type']); ?></td>
    <td class="small text-nowrap"><?= dl_e(date('d M Y', strtotime($r['reported_at']))); ?><div class="text-muted"><?= dl_e($r['reported_by_kind']); ?></div></td>
    <td class="text-end small"><?= (float) $r['impact_amount'] > 0 ? dl_money($r['impact_amount']) : '—'; ?></td><td><?= dlq_resp_badge($r['responsibility']); ?></td><td><?= dlq_badge($r['status']); ?><?= (int) $r['held_payment'] && in_array($r['status'], DLQ_OPEN, true) ? ' <span class="dl-st dl-gold">payment held</span>' : ''; ?></td></tr>
<?php } ?>
<?php if (!$rows) { ?><tr><td colspan="8" class="text-center text-muted py-4">No issue in this period. Report one from a delegation's page.</td></tr><?php } ?>
</tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>