<?php
/**
 * NEW FILE: modules/delegations/internal.php — INTERNALIZATION ANALYSIS: for each delegated service, Option A
 * (continue outsourcing, from the actual delegations of the last N months) against Option B (internalize: equipment,
 * training, employee and operating costs, internal cost per service, capacity). Materials are cost of sales either
 * way, so the comparison is the Tasker payments against the internal costs. The suggestion weighs demand, capacity,
 * quality, investment and payback; management records the decision (kept in the audit trail).
 */
require __DIR__ . '/common.php';
dl_require($conn, 'reports');
$months = max(1, min(24, (int) ($_GET['months'] ?? dl_setting($conn, 'int_months'))));
$pid = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$appr = dlc($conn, 'approve');
$names = dl_users($conn);
$plan = function ($p) use ($conn) { return mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_internal_plans WHERE product_id = ' . (int) $p)) ?: null; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'internal.php?id=' . $pid . '&months=' . $months;
    if (!dl_csrf_ok()) { dl_go($back, 'error', 'The form expired. Please try again.'); }
    if (!$appr) { dl_go($back, 'error', 'Only management can change the internalization plan.'); }
    $now = dl_now(); $u = dl_uid();
    $old = $plan($pid);
    if (($_POST['act'] ?? '') === 'plan') {
        $n = function ($k) { return round(max(0, (float) str_replace(',', '', (string) ($_POST[$k] ?? '0'))), 2); };
        $v = ['equipment_cost' => $n('equipment_cost'), 'training_cost' => $n('training_cost'), 'employee_monthly' => $n('employee_monthly'), 'operating_monthly' => $n('operating_monthly'),
            'internal_unit_cost' => $n('internal_unit_cost'), 'capacity_monthly' => $n('capacity_monthly')];
        $eq = ($_POST['expected_quality'] ?? '') === '' ? null : max(1, min(5, (float) $_POST['expected_quality']));
        $eh = ($_POST['expected_hours'] ?? '') === '' ? null : max(0, (float) $_POST['expected_hours']);
        $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 2000);
        $s = mysqli_prepare($conn, 'INSERT INTO dl_internal_plans (product_id, equipment_cost, training_cost, employee_monthly, operating_monthly, internal_unit_cost, capacity_monthly, expected_quality, expected_hours, notes, updated_by, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE equipment_cost = VALUES(equipment_cost), training_cost = VALUES(training_cost), employee_monthly = VALUES(employee_monthly), operating_monthly = VALUES(operating_monthly),
            internal_unit_cost = VALUES(internal_unit_cost), capacity_monthly = VALUES(capacity_monthly), expected_quality = VALUES(expected_quality), expected_hours = VALUES(expected_hours), notes = VALUES(notes), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
        mysqli_stmt_bind_param($s, 'iddddddddsis', $pid, $v['equipment_cost'], $v['training_cost'], $v['employee_monthly'], $v['operating_monthly'], $v['internal_unit_cost'], $v['capacity_monthly'], $eq, $eh, $notes, $u, $now);
        mysqli_stmt_execute($s);
        $chg = [];
        foreach ($v as $k => $x) { if (!$old || abs((float) $old[$k] - $x) > 0.004) { $chg[] = str_replace('_', ' ', $k) . ' ' . ($old ? number_format((float) $old[$k]) : '—') . ' → ' . number_format($x); } }
        dl_audit($conn, 'internal', $pid, 'plan', 'Internal cost estimate: ' . ($chg ? implode('; ', $chg) : 'no amount changed'));
        dl_go($back, 'success', 'Internal cost estimate saved.');
    }
    if (($_POST['act'] ?? '') === 'decide') {
        $dec = in_array($_POST['decision'] ?? '', ['Continue outsourcing', 'Internalize', 'Partly internalize', 'Review later'], true) ? $_POST['decision'] : '';
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
        if ($dec === '' || mb_strlen($note) < 5) { dl_go($back, 'error', 'Choose the decision and give its reason.'); }
        if (!$old) { mysqli_query($conn, "INSERT INTO dl_internal_plans (product_id, updated_by, updated_at) VALUES ($pid, $u, '$now')"); }
        $s = mysqli_prepare($conn, 'UPDATE dl_internal_plans SET decision = ?, decision_note = ?, decided_by = ?, decided_at = ? WHERE product_id = ?');
        mysqli_stmt_bind_param($s, 'ssisi', $dec, $note, $u, $now, $pid);
        mysqli_stmt_execute($s);
        $map = ['Continue outsourcing' => 'None', 'Internalize' => 'Planned', 'Partly internalize' => 'Planned', 'Review later' => 'Considering'];
        $s = mysqli_prepare($conn, 'UPDATE dl_offering_settings SET internalization_plan = ?, internalization_note = ? WHERE product_id = ?');
        $pn = mb_substr($dec . ': ' . $note, 0, 255);
        mysqli_stmt_bind_param($s, 'ssi', $map[$dec], $pn, $pid);
        mysqli_stmt_execute($s);
        dl_audit($conn, 'internal', $pid, 'decision', 'Internalization decision: ' . $dec, $note);
        dl_go($back, 'success', 'Decision recorded: ' . $dec . '.');
    }
    dl_go($back, 'error', 'Unknown action.');
}

// Services that are delegated or can be.
$svc = mysqli_fetch_all(mysqli_query($conn, "SELECT p.id, p.product_name, s.fulfilment, s.internal_capability, s.internalization_plan FROM products p LEFT JOIN dl_offering_settings s ON s.product_id = p.id
    WHERE s.fulfilment IN ('External Only', 'Delegatable', 'Not Available') OR p.id IN (SELECT product_id FROM dl_delegations) ORDER BY p.product_name"), MYSQLI_ASSOC);
$list = [];
foreach ($svc as $s) { $A = dlq_outsourcing($conn, (int) $s['id'], $months); $P = $plan((int) $s['id']); $list[] = ['s' => $s, 'A' => $A, 'P' => $P, 'E' => dlq_internal_eval($conn, $A, $P)]; }
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="internalization_' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Service', 'Fulfilment', 'Delegations (' . $months . ' months)', 'Monthly volume', 'Average Tasker %', 'Monthly Tasker payments', 'Annual outsourcing cost', 'Verified quality', 'On time', 'Average completion (h)', 'Equipment', 'Training', 'Employee / month', 'Operating / month', 'Internal cost per service', 'Expected saving / month', 'Payback (months)', 'Suggestion', 'Management decision']);
    foreach ($list as $x) { fputcsv($o, [$x['s']['product_name'], $x['s']['fulfilment'], $x['A']['count'], $x['A']['monthly_volume'], $x['A']['avg_rate'], $x['A']['monthly_payments'], $x['A']['annual_cost'], $x['A']['quality'], $x['A']['ontime'] !== null ? round($x['A']['ontime'] * 100) . '%' : '', $x['A']['hours'],
        $x['P']['equipment_cost'] ?? '', $x['P']['training_cost'] ?? '', $x['P']['employee_monthly'] ?? '', $x['P']['operating_monthly'] ?? '', $x['E']['per_service'] ?? '', $x['E']['saving'] ?? '', $x['E']['payback'] ?? '', $x['E']['verdict'], $x['P']['decision'] ?? '']); }
    exit;
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'internal.php', 'Internalization analysis', '<a class="btn btn-outline-secondary btn-sm" href="?' . dl_e(http_build_query(['months' => $months, 'export' => 'csv'])) . '"><i class="bi bi-download"></i> Excel (CSV)</a>');
$cur = null; foreach ($list as $x) { if ((int) $x['s']['id'] === $pid) { $cur = $x; } }
?>
<form class="d-flex gap-2 align-items-end mb-2"><?php if ($pid) { ?><input type="hidden" name="id" value="<?= $pid; ?>"><?php } ?><div><label class="form-label small mb-0">Demand measured over</label><select name="months" class="form-select form-select-sm" onchange="this.form.submit()"><?php foreach ([3, 6, 12, 24] as $m) { ?><option value="<?= $m; ?>" <?= $months === $m ? 'selected' : ''; ?>>last <?= $m; ?> months</option><?php } ?></select></div></form>
<?php if ($cur) { $A = $cur['A']; $P = $cur['P']; $E = $cur['E']; ?>
<div class="mb-2"><a href="internal.php?months=<?= $months; ?>" class="small">← All services</a></div>
<h5 class="mb-2"><?= dl_e($cur['s']['product_name']); ?> <?= dl_fulfilment_badge((string) $cur['s']['fulfilment']); ?></h5>
<div class="row g-3 mb-3">
<div class="col-lg-4"><div class="card h-100"><div class="card-body py-2 small"><div class="fw-semibold mb-1">Option A · continue outsourcing <span class="text-muted fw-normal">(actual, last <?= $months; ?> months)</span></div>
    <table class="table table-sm mb-0"><tbody>
    <tr><td>Delegations</td><td class="text-end"><?= (int) $A['count']; ?> (<?= (int) $A['verified']; ?> verified)</td></tr>
    <tr><td>Monthly delegation volume</td><td class="text-end"><?= dl_e($A['monthly_volume']); ?></td></tr>
    <tr><td>Average Tasker percentage</td><td class="text-end"><?= $A['avg_rate'] !== null ? dl_pct($A['avg_rate']) : '—'; ?></td></tr>
    <tr><td>Monthly Tasker payments</td><td class="text-end"><?= dl_money($A['monthly_payments']); ?></td></tr>
    <tr><td>Annual outsourcing cost</td><td class="text-end fw-semibold"><?= dl_money($A['annual_cost']); ?></td></tr>
    <tr><td>Service quality (verified)</td><td class="text-end"><?= $A['quality'] !== null ? $A['quality'] . '/5' : '—'; ?></td></tr>
    <tr><td>Completion time</td><td class="text-end"><?= $A['hours'] !== null ? dl_hours_text($A['hours']) : '—'; ?><?= $A['ontime'] !== null ? ' · ' . round($A['ontime'] * 100) . '% on time' : ''; ?></td></tr>
    <tr><td>Cost of errors</td><td class="text-end"><?= dl_money($A['error_cost']); ?></td></tr>
    <tr><td>Unavailable requests</td><td class="text-end"><?= (int) $A['lost']; ?></td></tr>
    </tbody></table></div></div></div>
<div class="col-lg-4"><form method="post" class="card h-100"><div class="card-body py-2 small"><?= dl_csrf_field(); ?><input type="hidden" name="act" value="plan"><input type="hidden" name="id" value="<?= $pid; ?>">
    <div class="fw-semibold mb-1">Option B · internalize the service <span class="text-muted fw-normal">(estimates)</span></div>
    <?php $fld = function ($k, $l, $step = '1') use ($P, $appr) { return '<div class="d-flex justify-content-between align-items-center mb-1 gap-2"><label class="mb-0">' . $l . '</label><input name="' . $k . '" type="number" min="0" step="' . $step . '" value="' . dl_e($P[$k] ?? '') . '" class="form-control form-control-sm text-end" style="width:130px" ' . ($appr ? '' : 'disabled') . '></div>'; };
    echo $fld('equipment_cost', 'Equipment cost (one-off)') . $fld('training_cost', 'Training cost (one-off)') . $fld('employee_monthly', 'Employee cost / month') . $fld('operating_monthly', 'Operating cost / month')
        . $fld('internal_unit_cost', 'Extra internal cost per service') . $fld('capacity_monthly', 'Capacity (services / month)', '0.5') . $fld('expected_quality', 'Expected quality (1–5)', '0.1') . $fld('expected_hours', 'Expected completion (hours)', '0.5'); ?>
    <textarea name="notes" rows="2" class="form-control form-control-sm mb-1" placeholder="Assumptions (quotes, salaries…)" <?= $appr ? '' : 'disabled'; ?>><?= dl_e($P['notes'] ?? ''); ?></textarea>
    <div class="text-muted mb-1">Materials (cost of sales) are the same either way and are not counted.</div>
    <?php if ($appr) { ?><button class="btn btn-outline-primary btn-sm">Save estimate</button><?php } ?>
</div></form></div>
<div class="col-lg-4"><div class="card h-100"><div class="card-body py-2 small"><div class="fw-semibold mb-1">Comparison</div>
    <?php if ($E['ready']) { ?>
    <table class="table table-sm mb-2"><tbody>
        <tr><td>Volume B can take over</td><td class="text-end"><?= dl_e(round($E['served'], 2)); ?> / month</td></tr>
        <tr><td>Tasker payments replaced</td><td class="text-end"><?= dl_money($E['a_served']); ?> / month</td></tr>
        <tr><td>Internal cost</td><td class="text-end"><?= dl_money($E['b_monthly']); ?> / month</td></tr>
        <tr><td>Expected internal cost per service</td><td class="text-end"><?= $E['per_service'] !== null ? dl_money($E['per_service']) : '—'; ?></td></tr>
        <tr><td>Expected savings</td><td class="text-end fw-semibold <?= $E['saving'] > 0 ? 'text-success' : 'text-danger'; ?>"><?= dl_money($E['saving']); ?> / month · <?= dl_money($E['annual_saving']); ?> / year</td></tr>
        <tr><td>Investment</td><td class="text-end"><?= dl_money($E['invest']); ?></td></tr>
        <tr><td>Payback period</td><td class="text-end"><?= $E['payback'] !== null ? $E['payback'] . ' months' : 'none (no saving)'; ?></td></tr>
    </tbody></table><?php } ?>
    <div class="p-2 rounded mb-2" style="background:<?= !empty($E['go']) ? '#E1F7EE' : '#F1F2F6'; ?>"><b><?= dl_e($E['verdict']); ?></b><?php foreach ($E['reasons'] as $r) { ?><div><?= dl_e($r); ?></div><?php } ?></div>
    <div class="text-muted mb-1">Suggestion only: thresholds in Settings (minimum demand <?= dl_e(dl_setting($conn, 'int_min_volume')); ?>/month, payback ≤ <?= dl_e(dl_setting($conn, 'int_max_payback')); ?> months).</div>
    <?php if ($P && $P['decision']) { ?><div class="mb-2">Management decision: <b><?= dl_e($P['decision']); ?></b> · <?= dl_e($P['decision_note']); ?> <span class="text-muted">(<?= dl_e(($names[(int) $P['decided_by']] ?? '') . ', ' . date('d M Y', strtotime($P['decided_at']))); ?>)</span></div><?php } ?>
    <?php if ($appr) { ?><form method="post" class="d-flex flex-wrap gap-1"><?= dl_csrf_field(); ?><input type="hidden" name="act" value="decide"><input type="hidden" name="id" value="<?= $pid; ?>">
        <select name="decision" class="form-select form-select-sm" style="width:auto"><?php foreach (['Continue outsourcing', 'Internalize', 'Partly internalize', 'Review later'] as $x) { ?><option><?= $x; ?></option><?php } ?></select>
        <input name="note" class="form-control form-control-sm" placeholder="Reason" required><button class="btn btn-primary btn-sm">Record decision</button></form><?php } ?>
</div></div></div>
</div>
<?php } else { ?>
<div class="card"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0">
<thead><tr><th>Service</th><th>Fulfilment</th><th class="text-end">Volume / month</th><th class="text-end">Avg Tasker %</th><th class="text-end">Annual outsourcing cost</th><th class="text-end">Quality</th><th class="text-end">Saving / month if internal</th><th class="text-end">Payback</th><th>Suggestion</th><th>Decision</th></tr></thead><tbody>
<?php foreach ($list as $x) { ?>
<tr><td><a href="internal.php?id=<?= (int) $x['s']['id']; ?>&months=<?= $months; ?>"><?= dl_e($x['s']['product_name']); ?></a></td><td><?= $x['s']['fulfilment'] ? dl_fulfilment_badge($x['s']['fulfilment']) : ''; ?></td>
    <td class="text-end"><?= dl_e($x['A']['monthly_volume']); ?></td><td class="text-end"><?= $x['A']['avg_rate'] !== null ? dl_pct($x['A']['avg_rate']) : '—'; ?></td><td class="text-end"><?= dl_money($x['A']['annual_cost']); ?></td>
    <td class="text-end"><?= $x['A']['quality'] !== null ? $x['A']['quality'] . '/5' : '—'; ?></td><td class="text-end"><?= $x['E']['ready'] ? dl_money($x['E']['saving']) : '—'; ?></td><td class="text-end"><?= !empty($x['E']['payback']) ? $x['E']['payback'] . ' m' : '—'; ?></td>
    <td class="small"><?= dl_e($x['E']['verdict']); ?></td><td class="small"><?= dl_e($x['P']['decision'] ?? '—'); ?></td></tr>
<?php } ?>
<?php if (!$list) { ?><tr><td colspan="10" class="text-center text-muted py-4">No service is delegated yet.</td></tr><?php } ?>
</tbody></table></div></div>
<?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>