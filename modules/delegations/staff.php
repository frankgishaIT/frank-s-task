<?php
/**
 * NEW FILE: modules/delegations/staff.php — STAFF DELEGATION PERFORMANCE & RECOGNITION.
 * For each employee: customer requests handled through delegation, successful (verified) delegated services,
 * revenue, requests saved from being turned away, customer satisfaction, correct use of the procedure and
 * compliance with approval and financial policies — and requests they recorded as unavailable.
 * "Delegated although RISE MOTIVE can provide it internally" is shown so delegation stays a smart choice,
 * never a target in itself. Recognition is suggested only with good outcomes and recorded by management.
 */
require __DIR__ . '/common.php';
dl_require($conn, 'reports');
$names = dl_users($conn);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'staff.php?from=' . $from . '&to=' . $to;
    if (!dl_csrf_ok()) { dl_go($back, 'error', 'The form expired. Please try again.'); }
    if (!dlc($conn, 'approve')) { dl_go($back, 'error', 'Only management records staff recognition.'); }
    $uid = (int) ($_POST['user'] ?? 0);
    $kind = in_array($_POST['kind'] ?? '', DLQ_STAFF_RECOGNITION, true) ? $_POST['kind'] : '';
    $period = preg_match('/^\d{4}-\d{2}$/', (string) ($_POST['period'] ?? '')) ? $_POST['period'] : date('Y-m');
    $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
    if (!isset($names[$uid]) || $kind === '' || mb_strlen($note) < 5) { dl_go($back, 'error', 'Choose the employee, the recognition and say what it is for.'); }
    $now = dl_now(); $u = dl_uid();
    $s = mysqli_prepare($conn, 'INSERT INTO dl_staff_recognition (user_id, kind, period, note, given_by, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'isssis', $uid, $kind, $period, $note, $u, $now);
    mysqli_stmt_execute($s);
    dl_audit($conn, 'staff', $uid, 'recognition', $names[$uid] . ' · ' . $kind . ' · ' . $period, $note);
    dl_notify_staff($conn, [$uid], $kind, 'Thank you: ' . $note);
    dl_go($back, 'success', 'Recognition recorded and sent to ' . $names[$uid] . '.');
}

$S = dlq_staff($conn, $from, $to);
uasort($S, function ($a, $b) { return $b['successful'] <=> $a['successful'] ?: $b['revenue'] <=> $a['revenue']; });
$sug = dlq_staff_suggestions($S, $names);
$rec = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM dl_staff_recognition ORDER BY id DESC LIMIT 50'), MYSQLI_ASSOC);
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="staff_delegation_' . $from . '_' . $to . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Employee', 'Requests handled through delegation', 'Successful', 'Failed / cancelled', 'Revenue', 'Profit after errors', 'Requests saved from rejection', 'Recorded as unavailable', 'Saved %', 'Customer satisfaction', 'Delegated although internal', 'Recommendation overridden', 'Override without reason', 'Not approved', 'Issues found to be RISE MOTIVE\'s', 'Actions on a Tasker\'s behalf', 'Compliance %']);
    foreach ($S as $u => $s) { fputcsv($o, [$names[$u] ?? '#' . $u, $s['handled'], $s['successful'], $s['failed'], $s['revenue'], $s['profit'], $s['saved'], $s['lost'], $s['saved_rate'], $s['satisfaction'], $s['avoidable'], $s['override'], $s['override_noreason'], $s['refused'], $s['upheld'], $s['on_behalf'], $s['compliance']]); }
    exit;
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'staff.php', 'Staff delegation performance', '<a class="btn btn-outline-secondary btn-sm" href="?' . dl_e(http_build_query(['from' => $from, 'to' => $to, 'export' => 'csv'])) . '"><i class="bi bi-download"></i> Excel (CSV)</a>');
?>
<form class="d-flex flex-wrap gap-2 align-items-end mb-2">
    <div><label class="form-label small mb-0">From</label><input type="date" name="from" value="<?= dl_e($from); ?>" class="form-control form-control-sm"></div>
    <div><label class="form-label small mb-0">To</label><input type="date" name="to" value="<?= dl_e($to); ?>" class="form-control form-control-sm"></div>
    <button class="btn btn-outline-primary btn-sm">Show</button>
</form>
<div class="small text-muted mb-2">The aim is smart service delivery, not the most external assignments: "Saved" are requests RISE MOTIVE could not serve internally that were served through an RM Tasker; "Delegated although internal" are services RISE MOTIVE can provide itself.</div>
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0">
<thead><tr><th>Employee</th><th class="text-end">Handled</th><th class="text-end">Successful</th><th class="text-end">Revenue</th><th class="text-end">Saved from rejection</th><th class="text-end">Recorded unavailable</th><th class="text-end">Satisfaction</th><th class="text-end">Delegated although internal</th><th class="text-end">Overrides (no reason)</th><th class="text-end">Not approved</th><th class="text-end">Compliance</th></tr></thead><tbody>
<?php foreach ($S as $u => $s) { ?>
<tr><td><?= dl_e($names[$u] ?? ($u ? '#' . $u : 'Unknown')); ?></td><td class="text-end"><?= $s['handled']; ?></td><td class="text-end"><?= $s['successful']; ?><?= $s['success_rate'] !== null ? ' <span class="small text-muted">(' . $s['success_rate'] . '%)</span>' : ''; ?></td>
    <td class="text-end"><?= dl_money($s['revenue']); ?></td><td class="text-end"><?= $s['saved']; ?><?= $s['saved_rate'] !== null ? ' <span class="small text-muted">(' . $s['saved_rate'] . '%)</span>' : ''; ?></td><td class="text-end"><?= $s['lost']; ?></td>
    <td class="text-end"><?= $s['satisfaction'] !== null ? number_format($s['satisfaction'], 1) . '/5' : '—'; ?></td><td class="text-end <?= $s['avoidable'] ? 'text-warning' : ''; ?>"><?= $s['avoidable']; ?></td>
    <td class="text-end"><?= $s['override']; ?><?= $s['override_noreason'] ? ' <span class="text-danger">(' . $s['override_noreason'] . ')</span>' : ''; ?></td><td class="text-end"><?= $s['refused']; ?></td>
    <td class="text-end fw-semibold <?= $s['compliance'] !== null && $s['compliance'] < 80 ? 'text-danger' : ''; ?>"><?= $s['compliance'] !== null ? $s['compliance'] . '%' : '—'; ?></td></tr>
<?php } ?>
<?php if (!$S) { ?><tr><td colspan="11" class="text-center text-muted py-4">No delegation or unavailable request in this period.</td></tr><?php } ?>
</tbody></table></div></div>
<div class="small text-muted mb-3">Compliance = share of delegations without a policy exception (recommendation overridden without a reason, refused by the approver, or an issue found to be RISE MOTIVE's responsibility). Saved % = saved ÷ (saved + recorded as unavailable).</div>
<div class="row g-3">
<div class="col-lg-6"><div class="card"><div class="card-body py-2"><div class="fw-semibold small mb-1">Suggested recognition for this period</div>
    <?php foreach ($sug as $x) { ?><div class="border-top py-1 small"><b><?= dl_e($x['kind']); ?></b>: <?= dl_e($x['name']); ?> · <?= dl_e($x['why']); ?></div><?php } ?>
    <?php if (!$sug) { ?><div class="small text-muted">None: recognition is suggested only for verified, well-rated delegations with good compliance.</div><?php } ?>
    <?php if (dlc($conn, 'approve')) { ?>
    <form method="post" class="mt-2"><?= dl_csrf_field(); ?><div class="row g-1">
        <div class="col-md-6"><select name="user" class="form-select form-select-sm" required><option value="">Employee…</option><?php foreach ($names as $id => $n) { ?><option value="<?= (int) $id; ?>"><?= dl_e($n); ?></option><?php } ?></select></div>
        <div class="col-md-6"><select name="kind" class="form-select form-select-sm"><?php foreach (DLQ_STAFF_RECOGNITION as $x) { ?><option><?= $x; ?></option><?php } ?></select></div>
        <div class="col-md-3"><input type="month" name="period" value="<?= dl_e(substr($from, 0, 7)); ?>" class="form-control form-control-sm"></div>
        <div class="col-md-9"><input name="note" class="form-control form-control-sm" placeholder="What it recognizes" required></div>
    </div><button class="btn btn-primary btn-sm mt-1">Record recognition</button></form><?php } ?>
</div></div></div>
<div class="col-lg-6"><div class="card"><div class="card-body py-2"><div class="fw-semibold small mb-1">Recognition given</div>
    <?php foreach ($rec as $r) { ?><div class="border-top py-1 small"><b><?= dl_e($names[(int) $r['user_id']] ?? '#' . $r['user_id']); ?></b> · <?= dl_e($r['kind']); ?> · <?= dl_e($r['period']); ?><div class="text-muted"><?= dl_e($r['note']); ?> (by <?= dl_e($names[(int) $r['given_by']] ?? '—'); ?>)</div></div><?php } ?>
    <?php if (!$rec) { ?><div class="small text-muted">None yet.</div><?php } ?>
</div></div></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>