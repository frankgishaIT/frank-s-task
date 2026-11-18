<?php
/**
 * NEW FILE: modules/sms/usage.php — SMS USAGE AND COSTS: messages and segments sent, estimated cost, use by notification,
 * module and branch, the most triggered notifications, failed and duplicate attempts — to find unnecessary SMS.
 */
require __DIR__ . '/sms_common.php';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$fb = (int) ($_GET['branch'] ?? 0);
$w = "created_at >= '$from 00:00:00' AND created_at <= '$to 23:59:59'" . ($fb ? " AND branch_id = $fb" : '');
$cat = sms_catalog();
$price = (float) sms_setting($conn, 'cost_per_segment');
$key = function (string $ev) use ($cat): string { if (strpos($ev, 'TIMETABLE_') === 0) { return 'TIMETABLE'; } if (substr($ev, -5) === '_COPY') { return substr($ev, 0, -5); } foreach ($cat as $k => $c) { if (($c['legacy'] ?? '') === $ev) { return $k; } } return strpos($ev, 'TEST') === 0 ? 'TEST' : $ev; };
$byEv = []; $tot = ['sent' => 0, 'seg' => 0, 'failed' => 0, 'duplicate' => 0, 'notsent' => 0, 'queued' => 0, 'tries' => 0];
foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT event, status, COUNT(*) n, COALESCE(SUM(segments), 0) seg FROM sms_log WHERE $w GROUP BY event, status"), MYSQLI_ASSOC) as $r) {
    $k = $key($r['event']); $n = (int) $r['n']; $b = &$byEv[$k];
    $b['tries'] = ($b['tries'] ?? 0) + $n; $tot['tries'] += $n;
    if (in_array($r['status'], ['sent', 'delivered'], true)) { $b['sent'] = ($b['sent'] ?? 0) + $n; $b['seg'] = ($b['seg'] ?? 0) + (int) $r['seg']; $tot['sent'] += $n; $tot['seg'] += (int) $r['seg']; }
    elseif ($r['status'] === 'failed') { $b['failed'] = ($b['failed'] ?? 0) + $n; $tot['failed'] += $n; }
    elseif ($r['status'] === 'duplicate') { $b['duplicate'] = ($b['duplicate'] ?? 0) + $n; $tot['duplicate'] += $n; }
    elseif ($r['status'] === 'queued') { $tot['queued'] += $n; }
    else { $b['notsent'] = ($b['notsent'] ?? 0) + $n; $tot['notsent'] += $n; }
    unset($b);
}
uasort($byEv, function ($a, $b) { return ($b['tries'] ?? 0) <=> ($a['tries'] ?? 0); });
$label = function (string $k) use ($cat) { return $k === 'TEST' ? 'Test SMS' : ($cat[$k]['name'] ?? sms_event_label($k)); };
$byMod = []; foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT event, module, COALESCE(SUM(segments), 0) seg, COUNT(*) n FROM sms_log WHERE $w AND status IN ('sent', 'delivered') GROUP BY event, module"), MYSQLI_ASSOC) as $r) { $m = $r['module'] ?: sms_event_module($r['event']); $byMod[$m] = ($byMod[$m] ?? 0) + (int) $r['seg']; }
arsort($byMod);
$branches = smsm_branches($conn);
$byBr = []; foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT branch_id, COALESCE(SUM(segments), 0) seg FROM sms_log WHERE $w AND status IN ('sent', 'delivered') GROUP BY branch_id"), MYSQLI_ASSOC) as $r) { $byBr[$r['branch_id'] ? ($branches[(int) $r['branch_id']] ?? '#' . $r['branch_id']) : 'No branch / company-wide'] = (int) $r['seg']; }
$days = []; foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT SUBSTR(created_at, 1, 10) d, COUNT(*) n, COALESCE(SUM(segments), 0) seg FROM sms_log WHERE $w AND status IN ('sent', 'delivered') GROUP BY SUBSTR(created_at, 1, 10) ORDER BY d"), MYSQLI_ASSOC) as $r) { $days[$r['d']] = [(int) $r['n'], (int) $r['seg']]; }
$money = function ($v) { return 'RWF ' . number_format($v, 0); };
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
smsm_tabs($conn, 'usage.php', 'SMS usage and costs');
?>
<form class="d-flex flex-wrap gap-2 align-items-end small mb-3"><div><label class="form-label small mb-0">From</label><input type="date" name="from" value="<?= smsm_e($from); ?>" class="form-control form-control-sm"></div>
    <div><label class="form-label small mb-0">To</label><input type="date" name="to" value="<?= smsm_e($to); ?>" class="form-control form-control-sm"></div>
    <?php if ($branches) { ?><div><label class="form-label small mb-0">Branch</label><select name="branch" class="form-select form-select-sm"><option value="0">All</option><?php foreach ($branches as $id => $n) { ?><option value="<?= (int) $id; ?>" <?= $fb === (int) $id ? 'selected' : ''; ?>><?= smsm_e($n); ?></option><?php } ?></select></div><?php } ?>
    <button class="btn btn-sm btn-primary">Show</button></form>
<div class="row g-2 mb-3">
    <?php foreach ([['SMS sent', number_format($tot['sent'])], ['Segments used', number_format($tot['seg'])], ['Estimated cost', $price > 0 ? $money($tot['seg'] * $price) : '— (set the price in Settings)'], ['Failed', number_format($tot['failed'])], ['Duplicates blocked', number_format($tot['duplicate'])], ['Not sent (off / targeting / no phone)', number_format($tot['notsent'])]] as [$l, $v]) { ?>
    <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="small text-muted"><?= smsm_e($l); ?></div><div class="fs-5 fw-bold" style="color:#0B1A8C"><?= smsm_e($v); ?></div></div></div></div><?php } ?>
</div>
<div class="row g-3 mb-3">
    <div class="col-xl-8"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="fw-semibold small mb-2">Segments per day</div><div style="height:220px"><canvas id="cDays"></canvas></div></div></div></div>
    <div class="col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="fw-semibold small mb-2">Segments by module</div><div style="height:220px"><canvas id="cMod"></canvas></div></div></div></div>
    <div class="col-xl-8"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="fw-semibold small mb-2">Most triggered notifications (attempts)</div><div style="height:<?= max(160, 26 * min(12, count($byEv))); ?>px"><canvas id="cEv"></canvas></div></div></div></div>
    <div class="col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="fw-semibold small mb-2">Segments by branch</div><div style="height:220px"><canvas id="cBr"></canvas></div></div></div></div>
</div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><table class="table table-sm align-middle mb-0" style="font-size:12.5px;">
    <tr class="table-light"><th class="ps-3">Notification</th><th class="text-end">Attempts</th><th class="text-end">Sent</th><th class="text-end">Segments</th><th class="text-end">Est. cost</th><th class="text-end">Failed</th><th class="text-end">Duplicates</th><th class="text-end">Not sent</th><th class="text-end">Share</th></tr>
    <?php foreach ($byEv as $k => $b) { ?><tr><td class="ps-3"><?= isset($cat[$k]) ? '<a href="template.php?event=' . rawurlencode($k) . '">' . smsm_e($label($k)) . '</a>' : smsm_e($label($k)); ?></td>
        <td class="text-end"><?= (int) ($b['tries'] ?? 0); ?></td><td class="text-end"><?= (int) ($b['sent'] ?? 0); ?></td><td class="text-end"><?= (int) ($b['seg'] ?? 0); ?></td><td class="text-end"><?= $price > 0 ? $money(($b['seg'] ?? 0) * $price) : '—'; ?></td>
        <td class="text-end <?= !empty($b['failed']) ? 'text-danger' : ''; ?>"><?= (int) ($b['failed'] ?? 0); ?></td><td class="text-end"><?= (int) ($b['duplicate'] ?? 0); ?></td><td class="text-end"><?= (int) ($b['notsent'] ?? 0); ?></td>
        <td class="text-end"><?= $tot['seg'] ? round(100 * ($b['seg'] ?? 0) / $tot['seg']) . '%' : '—'; ?></td></tr><?php } ?>
    <?php if (!$byEv) { ?><tr><td colspan="9" class="text-center text-muted py-4">No SMS in this period.</td></tr><?php } ?>
</table></div></div>
<div class="small text-muted mt-2">Segments: one SMS segment = 160 standard characters (153 when a message has several), or 70 when it contains special characters. Pindo charges per segment. Tip: notifications with many segments and few useful readers are the first to shorten, target or switch off.</div>
<script>
(function () {
    if (!window.Chart) return;
    var pal = ['#1E2FE0', '#0FA968', '#E8A33D', '#7A5AF8', '#E24B4A', '#0B1A8C', '#2BB0D6', '#9AA3B8', '#F28C38', '#5E6AD2', '#14B8A6', '#C026D3'];
    var opt = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } };
    new Chart(document.getElementById('cDays'), { type: 'bar', data: { labels: <?= json_encode(array_keys($days)); ?>, datasets: [{ label: 'Segments', data: <?= json_encode(array_column(array_values($days), 1)); ?>, backgroundColor: '#1E2FE0', borderRadius: 3 }] }, options: opt });
    new Chart(document.getElementById('cMod'), { type: 'doughnut', data: { labels: <?= json_encode(array_keys($byMod)); ?>, datasets: [{ data: <?= json_encode(array_values($byMod)); ?>, backgroundColor: pal }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } } } });
    <?php $top = array_slice($byEv, 0, 12, true); ?>
    new Chart(document.getElementById('cEv'), { type: 'bar', data: { labels: <?= json_encode(array_map($label, array_keys($top))); ?>, datasets: [
        { label: 'Sent', data: <?= json_encode(array_map(function ($b) { return (int) ($b['sent'] ?? 0); }, array_values($top))); ?>, backgroundColor: '#1E2FE0' },
        { label: 'Not sent / duplicate / failed', data: <?= json_encode(array_map(function ($b) { return (int) (($b['failed'] ?? 0) + ($b['duplicate'] ?? 0) + ($b['notsent'] ?? 0)); }, array_values($top))); ?>, backgroundColor: '#E8A33D' }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, ticks: { font: { size: 11 } } } }, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } } } });
    new Chart(document.getElementById('cBr'), { type: 'bar', data: { labels: <?= json_encode(array_keys($byBr)); ?>, datasets: [{ data: <?= json_encode(array_values($byBr)); ?>, backgroundColor: '#0FA968', borderRadius: 3 }] }, options: opt });
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>