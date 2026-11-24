<?php
/**
 * NEW FILE: modules/customer_first/index.php — CUSTOMER SERVICE MONITORING (management): total requests, fulfilled,
 * unresolved, referred, alternatives, pending approval, awaiting sourcing, by product / service, by employee, by
 * department, reasons not fulfilled, average resolution time, escalations and overdue, customer feedback, and the
 * customer loss indicators (recorded signs of possibly lost opportunities — not proof that a customer left).
 */
require __DIR__ . '/common.php';
cf_require($conn, 'manage');
$p = (string) ($_GET['period'] ?? 'month'); $today = date('Y-m-d');
$periods = ['week' => [date('Y-m-d', strtotime('monday this week')), 'This week'], 'month' => [date('Y-m-01'), 'This month'], '3m' => [date('Y-m-d', strtotime('-3 months')), 'Last 3 months'], 'year' => [date('Y-01-01'), 'This year'], 'all' => ['2000-01-01', 'All time']];
if (!isset($periods[$p])) { $p = 'month'; } $from = $periods[$p][0];
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT c.*, u.names hname, d.name dname FROM cf_cases c LEFT JOIN users u ON u.id = c.handled_by LEFT JOIN departments d ON d.id = c.department_id WHERE " . cf_scope_sql($conn) . " AND c.created_at >= '$from 00:00:00'"), MYSQLI_ASSOC);
$n = count($rows); $by = function ($k) use ($rows) { $o = []; foreach ($rows as $r) { $v = $r[$k] ?: '—'; $o[$v] = ($o[$v] ?? 0) + 1; } arsort($o); return $o; };
$out = $by('outcome'); $reasons = array_filter($by('unresolved_reason'), function ($k) { return $k !== '—'; }, ARRAY_FILTER_USE_KEY);
$closed = array_filter($rows, function ($r) { return $r['closed_at']; }); $avgH = $closed ? array_sum(array_map(function ($r) { return (strtotime($r['closed_at']) - strtotime($r['created_at'])) / 3600; }, $closed)) / count($closed) : null;
$prods = []; foreach ($rows as $r) { $k = mb_strtolower(trim($r['request_text'])); if (!isset($prods[$k])) { $prods[$k] = ['name' => $r['request_text'], 'kind' => $r['kind'], 'n' => 0, 'ok' => 0, 'lost' => 0]; } $prods[$k]['n']++; if (in_array($r['outcome'], ['Fulfilled', 'Alternative Accepted'], true)) { $prods[$k]['ok']++; } if (cf_loss_indicator($r)) { $prods[$k]['lost']++; } }
uasort($prods, function ($a, $b) { return [$b['lost'], $b['n']] <=> [$a['lost'], $a['n']]; });
$emp = []; foreach ($rows as $r) { $k = $r['hname'] ?: '—'; $emp[$k] = $emp[$k] ?? ['n' => 0, 'ok' => 0, 'un' => 0, 'esc' => 0]; $emp[$k]['n']++; if (in_array($r['outcome'], ['Fulfilled', 'Alternative Accepted'], true)) { $emp[$k]['ok']++; } if ($r['outcome'] === 'Unresolved') { $emp[$k]['un']++; } if ($r['status'] === 'Escalated') { $emp[$k]['esc']++; } }
uasort($emp, function ($a, $b) { return $b['n'] <=> $a['n']; });
$dep = $by('dname');
$ids = array_map('intval', array_column($rows, 'id'));
$esc = $ids ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) n, SUM(CASE WHEN status IN ('Open', 'More information') THEN 1 ELSE 0 END) open_n, SUM(CASE WHEN status IN ('Open', 'More information') AND due_at < '" . date('Y-m-d H:i:s') . "' THEN 1 ELSE 0 END) over_n FROM cf_escalations WHERE case_id IN (" . implode(',', $ids) . ')')) : ['n' => 0, 'open_n' => 0, 'over_n' => 0];
$fb = array_filter($rows, function ($r) { return $r['feedback_rating']; }); $avgFb = $fb ? array_sum(array_column($fb, 'feedback_rating')) / count($fb) : null;
$loss = []; foreach ($rows as $r) { if ($l = cf_loss_indicator($r)) { $loss[$l][] = $r; } }
$k = function ($l, $v, $sub = '', $href = '') { return '<div class="col-6 col-md-3 col-xl"><' . ($href ? 'a href="' . $href . '" class="text-decoration-none"' : 'div') . '><div class="card border-0 shadow-sm h-100 cf-k"><div class="card-body py-2"><div class="lbl">' . cf_e($l) . '</div><div class="val">' . cf_e($v) . '</div><div class="sub">' . cf_e($sub) . '</div></div></div></' . ($href ? 'a' : 'div') . '></div>'; };
$pc = function ($x) use ($n) { return $n ? round($x / $n * 100) . '%' : ''; };
$qs = '&from=' . $from;
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
cf_tabs($conn, 'index.php', 'Customer service monitoring');
?>
<div class="d-flex flex-wrap gap-1 mb-3 small"><?php foreach ($periods as $key => [$d, $l]) { ?><a href="?period=<?= $key; ?>" class="btn btn-sm py-0 <?= $p === $key ? 'btn-primary' : 'btn-light border'; ?>"><?= $l; ?></a><?php } ?></div>
<div class="row g-2 mb-2">
    <?= $k('Customer requests', $n, $periods[$p][1], 'cases.php?' . ltrim($qs, '&')); ?>
    <?= $k('Fulfilled', $out['Fulfilled'] ?? 0, $pc($out['Fulfilled'] ?? 0), 'cases.php?outcome=Fulfilled' . $qs); ?>
    <?= $k('Through alternatives', $out['Alternative Accepted'] ?? 0, $pc($out['Alternative Accepted'] ?? 0), 'cases.php?outcome=Alternative+Accepted' . $qs); ?>
    <?= $k('Unresolved', $out['Unresolved'] ?? 0, $pc($out['Unresolved'] ?? 0), 'cases.php?outcome=Unresolved' . $qs); ?>
    <?= $k('Referred', $out['Referred'] ?? 0, '', 'cases.php?outcome=Referred' . $qs); ?>
</div>
<div class="row g-2 mb-3">
    <?= $k('Pending approval', $out['Pending Approval'] ?? 0, '', 'cases.php?outcome=Pending+Approval' . $qs); ?>
    <?= $k('Awaiting sourcing', $out['Product to Be Sourced'] ?? 0, 'scheduled: ' . ($out['Service Scheduled'] ?? 0), 'cases.php?outcome=Product+to+Be+Sourced' . $qs); ?>
    <?= $k('No outcome yet', $out['—'] ?? 0, '', 'cases.php?outcome=none' . $qs); ?>
    <?= $k('Average resolution', $avgH === null ? '—' : ($avgH < 48 ? round($avgH, 1) . ' h' : round($avgH / 24, 1) . ' days'), count($closed) . ' closed'); ?>
    <?= $k('Escalations', (int) $esc['n'], (int) $esc['open_n'] . ' open · ' . (int) $esc['over_n'] . ' overdue', 'cases.php?view=overdue'); ?>
    <?= $k('Customer feedback', $avgFb === null ? '—' : number_format($avgFb, 1) . ' / 5', count($fb) . ' rating(s)'); ?>
</div>
<div class="row g-3">
<div class="col-xl-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="fw-semibold mb-1">Customer loss indicators <span class="fw-normal text-muted">— possible lost opportunities, not proof that customers left</span></div>
        <?php foreach ($loss as $l => $list) { ?><details class="border-bottom py-1"><summary class="d-flex justify-content-between"><span><?= cf_e($l); ?></span><strong><?= count($list); ?></strong></summary>
            <?php foreach (array_slice($list, 0, 15) as $r) { ?><div class="ps-3"><a href="case.php?id=<?= (int) $r['id']; ?>"><?= cf_case_no((int) $r['id']); ?></a> · <?= cf_e($r['request_text']); ?> <span class="text-muted"><?= date('d M', strtotime($r['created_at'])); ?> · <?= cf_e($r['hname']); ?></span></div><?php } ?></details><?php } ?>
        <?php if (!$loss) { ?><div class="text-muted">None in this period.</div><?php } ?></div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="p-2 px-3 fw-semibold small">Requests by product / service <span class="fw-normal text-muted">— common needs and service gaps</span></div><div class="table-responsive"><table class="table table-sm mb-0 cf-tbl">
        <tr class="table-light"><th class="ps-3">Requested</th><th class="text-end">Requests</th><th class="text-end">Served</th><th class="text-end">Possibly lost</th></tr>
        <?php foreach (array_slice($prods, 0, 15) as $x) { ?><tr><td class="ps-3"><?= cf_e($x['name']); ?> <span class="small text-muted"><?= $x['kind'] === 'service' ? 'service' : 'product'; ?></span></td><td class="text-end"><?= $x['n']; ?></td><td class="text-end"><?= $x['ok']; ?></td><td class="text-end <?= $x['lost'] ? 'text-danger fw-semibold' : ''; ?>"><?= $x['lost']; ?></td></tr><?php } ?>
        <?php if (!$prods) { ?><tr><td class="text-center text-muted py-3">No request in this period.</td></tr><?php } ?>
    </table></div><div class="p-2 px-3 small text-muted">Unmet demand recorded here can be sent to <a href="../market/opportunities.php">RM Market Insights</a> from each request.</div></div></div>
</div>
<div class="col-xl-5">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="fw-semibold mb-1">Why requests were not fulfilled</div>
        <?php $mx = $reasons ? max($reasons) : 1; foreach ($reasons as $r => $c) { ?><div class="mb-1"><div class="d-flex justify-content-between"><span><?= cf_e($r); ?></span><strong><?= $c; ?></strong></div><div style="height:6px;background:#EEF1F8;border-radius:3px"><div style="height:6px;width:<?= round($c / $mx * 100); ?>%;background:#E24B4A;border-radius:3px"></div></div></div><?php } ?>
        <?php if (!$reasons) { ?><div class="text-muted">—</div><?php } ?></div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="p-2 px-3 fw-semibold small">By employee</div><div class="table-responsive"><table class="table table-sm mb-0 cf-tbl">
        <tr class="table-light"><th class="ps-3">Employee</th><th class="text-end">Requests</th><th class="text-end">Served</th><th class="text-end">Unresolved</th><th class="text-end">Escalated</th></tr>
        <?php foreach ($emp as $name => $x) { ?><tr><td class="ps-3"><?= cf_e($name); ?></td><td class="text-end"><?= $x['n']; ?></td><td class="text-end"><?= $x['ok']; ?></td><td class="text-end"><?= $x['un']; ?></td><td class="text-end"><?= $x['esc']; ?></td></tr><?php } ?></table></div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body small"><div class="fw-semibold mb-1">By department</div>
        <?php foreach ($dep as $d => $c) { ?><div class="d-flex justify-content-between border-bottom py-1"><span><?= cf_e($d); ?></span><strong><?= $c; ?></strong></div><?php } ?></div></div>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>