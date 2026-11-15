<?php
/**
 * NEW FILE: modules/customers/analytics.php — Customer Analytics & Visual Insights (Task 2).
 *   A. Customers by province / district / sector, % and top areas.
 *   B. Best Customer of the Month and of the Year (confirmed purchase value).
 *   C. Growth, segmentation, revenue trend, purchase frequency, most requested services, location charts.
 *   D. Filters: Today, This Week, This Month, This Year, Custom (and All History). Clicking a bar/slice lists
 *      its customers. Each chart saves as JPG; the whole page downloads as a PDF.
 * Rules: includes/customer_analytics.php.
 */
require '../../config/db.php';
require_once '../../includes/access_control.php';
require_once '../../includes/sms_messages.php';
require_once '../../includes/customer_analytics.php';
if (current_user_role() === 'Admin') { require_once '../../includes/admin_error_guard.php'; }
// Same as Customers > Reports: Administrators and Managers (and only if Roles & Permissions allow customer reports).
if (!in_array(current_user_role(), ['Admin', 'Manager'], true) || !access_can('customers.reports', $conn)) {
    http_response_code(403); include '../../includes/access_denied.php'; exit;
}

if (empty($_GET['period'])) { $_GET['period'] = 'this_year'; }
$period = report_period_from_request($_GET, true);
if ($period['error'] ?? null) { $period = report_period_from_request(['period' => 'this_year'], true) + ['error_shown' => $period['error']]; }
$level = in_array($_GET['level'] ?? '', ['province', 'district', 'sector'], true) ? $_GET['level'] : 'province';
$bm = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['bm'] ?? '')) ? $_GET['bm'] : date('Y-m');
$by = preg_match('/^\d{4}$/', (string) ($_GET['by'] ?? '')) ? $_GET['by'] : date('Y');

// Data
$loc = ca_location($conn, $period, $level);
$bestMonth = ca_best($conn, $bm . '-01', date('Y-m-t', strtotime($bm . '-01')));
$bestYear = ca_best($conn, $by . '-01-01', $by . '-12-31');
$growth = ca_growth($conn, $period);
$revenue = ca_revenue($conn, $period);
$segMap = ca_segment_map($conn, $period);
$seg = array_fill_keys(array_keys(CA_SEGMENTS), 0);
foreach ($segMap as $k) { $seg[$k]++; }
$counts = ca_purchase_counts($conn, $period);
$freq = array_fill_keys(array_keys(CA_FREQ), 0);
foreach ($counts as $n) { $freq[ca_freq_key($n)]++; }
$avgFreq = $counts ? round(array_sum($counts) / count($counts), 2) : 0;
$services = ca_services($conn, $period);
[$from, $to] = ca_bounds($conn, $period);
$totalCustomers = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM customers'))[0];
$newInPeriod = array_sum(array_column($loc, 'n'));
$periodRevenue = (float) (an_rows($conn, "SELECT COALESCE(SUM(s.total_amount), 0) v FROM sales s WHERE " . CA_PURCHASE . " AND s.customer_id IS NOT NULL AND s.sale_date BETWEEN ? AND ?", 'ss', [$from, $to])[0]['v'] ?? 0);
$top = $loc[0] ?? null;
[$drillTitle, $drillRows] = !empty($_GET['drill']) ? ca_drill($conn, $period, $_GET) : ['', []];

$money = function ($v) { return 'RWF ' . number_format((float) $v, 0); };
$filters = [['Period', $period['label']], ['Location level', ucfirst($level)], ['Best customer', date('F Y', strtotime($bm . '-01')) . ' / ' . $by]];
$summary = [['Registered customers', number_format($totalCustomers)], ['New in the period', number_format($newInPeriod)], ['Buying customers', number_format(count($counts))],
    ['Revenue from customers', $money($periodRevenue)], ['Top area', $top ? $top['label'] . ' (' . $top['pct'] . '%)' : '—']];

// PDF of the whole page (charts sent by the browser + the tables).
if (($_GET['export'] ?? '') === 'pdf' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $bestRow = function ($r, $label) use ($money) { return [$label, $r['name'], $r['code'], $money($r['total']), $r['purchases'], $r['loyalty_points'] ?? '—']; };
    $tables = [
        ['title' => 'Best customers', 'columns' => ['Recognition', 'Customer', 'Customer ID', 'Confirmed purchases', 'Purchases', 'Loyalty points'],
            'rows' => array_merge($bestMonth ? [$bestRow($bestMonth[0], 'Best of ' . date('F Y', strtotime($bm . '-01')))] : [], $bestYear ? [$bestRow($bestYear[0], 'Best of ' . $by)] : [])],
        ['title' => 'Customers by ' . $level, 'columns' => [ucfirst($level), 'Customers', '%'], 'rows' => array_map(function ($r) { return [$r['label'], $r['n'], $r['pct'] . '%']; }, $loc)],
        ['title' => 'Customer growth', 'columns' => ['Month', 'New customers', 'Total registered', 'Growth rate'], 'rows' => array_map(function ($r) { return [$r['label'], $r['new'], $r['total'], $r['rate'] === null ? '—' : $r['rate'] . '%']; }, $growth)],
        ['title' => 'Customer segments', 'columns' => ['Segment', 'Definition', 'Customers'], 'rows' => array_map(function ($k) use ($seg) { return [CA_SEGMENTS[$k][0], CA_SEGMENTS[$k][1], $seg[$k]]; }, array_keys(CA_SEGMENTS))],
        ['title' => 'Revenue from customers', 'columns' => ['Month', 'Registered customers', 'Walk-in', 'Growth vs previous month'], 'rows' => array_map(function ($r) use ($money) { return [$r['label'], $money($r['registered']), $money($r['walkin']), $r['growth'] === null ? '—' : $r['growth'] . '%']; }, $revenue)],
        ['title' => 'Purchase frequency (average ' . $avgFreq . ' purchases per buying customer)', 'columns' => ['Group', 'Customers'], 'rows' => array_map(function ($k) use ($freq) { return [CA_FREQ[$k], $freq[$k]]; }, array_keys(CA_FREQ))],
        ['title' => 'Most requested services', 'columns' => ['Service', 'Requests', 'Quantity', 'Customers', '% of requests'], 'rows' => array_map(function ($r) { return [$r['name'], $r['requests'], $r['quantity'], $r['customers'], $r['pct'] . '%']; }, $services)],
    ];
    an_render_pdf('Customer Analytics', $filters, $summary, an_posted_charts(), $tables, 'customer-analytics-' . date('Ymd') . '.pdf');
}

$base = $_GET; unset($base['drill'], $base['key'], $base['value'], $base['id'], $base['month'], $base['export']);
$link = function (array $extra) use ($base) { return 'analytics.php?' . http_build_query(array_merge($base, $extra)) . '#drill'; };

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>
<style>
    /* Best Customer cards (blue = month, green = year) */
    .ca-best{ position:relative; overflow:hidden; border-radius:22px; color:#fff; padding:24px 26px 26px; box-shadow:0 14px 34px rgba(17,24,39,.16); }
    .ca-best.month{ background:linear-gradient(135deg,#1631A8 0%,#1E4FE0 55%,#3B82F6 100%); }
    .ca-best.year{ background:linear-gradient(135deg,#0B6B4C 0%,#0E8F62 55%,#2FD08D 100%); }
    .ca-best .ca-deco1{ position:absolute; right:-70px; top:-90px; width:270px; height:270px; border-radius:50%; background:rgba(255,255,255,.08); }
    .ca-best .ca-deco2{ position:absolute; left:-60px; bottom:-90px; width:220px; height:220px; border-radius:50%; background:rgba(255,255,255,.05); }
    .ca-best > *:not([class^="ca-deco"]){ position:relative; }
    .ca-best .ca-head{ display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
    .ca-best .ca-ico{ width:42px; height:42px; border-radius:12px; background:rgba(255,255,255,.16); display:inline-flex; align-items:center; justify-content:center; font-size:18px; }
    .ca-best .ca-title{ font-weight:900; letter-spacing:.12em; text-transform:uppercase; font-size:15px; }
    .ca-best .ca-pick{ border:0; border-radius:12px; padding:9px 14px; background:#fff; color:#1E2333; font-size:15px; min-width:150px; box-shadow:0 4px 12px rgba(0,0,0,.08); }
    .ca-best.year .ca-pick{ min-width:110px; }
    .ca-best .ca-avatar{ width:112px; height:112px; border-radius:50%; background:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:34px; font-weight:900;
        box-shadow:0 0 0 7px rgba(255,255,255,.22); flex-shrink:0; object-fit:cover; }
    .ca-best.month .ca-avatar{ color:#1E3FD0; } .ca-best.year .ca-avatar{ color:#0B7A55; }
    .ca-best .ca-name{ font-size:1.85rem; font-weight:900; line-height:1.15; }
    .ca-best .ca-id{ opacity:.9; } .ca-best .ca-id span{ background:rgba(255,255,255,.18); border-radius:8px; padding:2px 10px; font-weight:700; margin-left:6px; opacity:1; }
    .ca-best .ca-cur{ font-size:1rem; font-weight:700; opacity:.85; margin-right:8px; }
    .ca-best .ca-val{ font-size:2.6rem; font-weight:900; line-height:1.1; }
    .ca-best .ca-pill{ display:inline-block; border:1px solid rgba(255,255,255,.35); background:rgba(255,255,255,.12); border-radius:30px; padding:6px 16px; font-weight:600; font-size:14px; margin:0 8px 8px 0; }
    .ca-best .ca-next{ background:#F7F9FC; color:#1E2333; border-radius:18px; padding:16px 20px 6px; margin-top:12px; }
    .ca-best .ca-next-title{ font-weight:800; letter-spacing:.12em; font-size:13px; margin-bottom:6px; }
    .ca-best.month .ca-next-title, .ca-best.month .ca-amt{ color:#1E4FE0; } .ca-best.year .ca-next-title, .ca-best.year .ca-amt{ color:#0E8F62; }
    .ca-best .ca-row{ display:flex; align-items:center; gap:14px; padding:12px 0; border-top:1px solid #E6EAF2; }
    .ca-best .ca-next-title + .ca-row{ border-top:0; }
    .ca-best .ca-num{ width:30px; height:30px; border-radius:50%; color:#fff; font-weight:800; font-size:13px; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
    .ca-best.month .ca-num{ background:#3B6EF0; } .ca-best.year .ca-num{ background:#14A06C; }
    .ca-best .ca-row a{ color:#1E2333; text-decoration:none; font-weight:500; } .ca-best .ca-row a:hover{ text-decoration:underline; }
    .ca-best .ca-amt{ margin-left:auto; font-weight:800; white-space:nowrap; }
    @media (max-width: 576px){ .ca-best{ padding:18px; } .ca-best .ca-avatar{ width:80px; height:80px; font-size:26px; } .ca-best .ca-name{ font-size:1.35rem; } .ca-best .ca-val{ font-size:2rem; } }
</style>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div><h2 class="mb-0">Customer Analytics</h2><div class="small text-muted">Click any bar or slice to see its customers. Purchases = confirmed invoices (Paid, Partially Paid, Credit).</div></div>
    <div class="d-flex gap-2"><a href="index.php" class="btn btn-sm btn-outline-secondary">Customers</a><a href="reports.php" class="btn btn-sm btn-outline-secondary">Reports</a><?php an_pdf_button(); ?></div>
</div>
<?php if (!empty($period['error_shown'])) { ?><div class="alert alert-danger"><?= an_e($period['error_shown']); ?> Showing This Year instead.</div><?php } ?>

<form class="row g-2 mb-3 align-items-end" method="GET">
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Period</label><select name="period" id="caPeriod" class="form-select form-select-sm">
        <?php foreach (report_period_options(true) as $k => $l) { ?><option value="<?= $k; ?>" <?= $period['preset'] === $k ? 'selected' : ''; ?>><?= an_e($l); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2 ca-custom"><label class="form-label small mb-0">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= an_e($_GET['from'] ?? ''); ?>"></div>
    <div class="col-6 col-md-2 ca-custom"><label class="form-label small mb-0">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= an_e($_GET['to'] ?? ''); ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Location level</label><select name="level" class="form-select form-select-sm">
        <?php foreach (['province' => 'Province', 'district' => 'District', 'sector' => 'Sector'] as $k => $l) { ?><option value="<?= $k; ?>" <?= $level === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
    <input type="hidden" name="bm" value="<?= an_e($bm); ?>"><input type="hidden" name="by" value="<?= an_e($by); ?>">
    <div class="col-12 col-md-2"><button class="btn btn-sm btn-primary w-100">Show</button></div>
</form>
<script>(function(){var p=document.getElementById('caPeriod');function f(){document.querySelectorAll('.ca-custom').forEach(function(e){e.style.display=p.value==='custom'?'':'none';});}p.addEventListener('change',f);f();})();</script>

<div class="row g-2 mb-3">
    <?php foreach ($summary as [$k, $v]) { ?><div class="col-6 col-md"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="small text-muted"><?= an_e($k); ?></div><div class="fw-bold"><?= an_e($v); ?></div></div></div></div><?php } ?>
</div>

<!-- B. Best customers -->
<div class="row g-4 mb-4">
    <?php foreach ([['month', 'Best Customer of the Month', $bestMonth, date('F Y', strtotime($bm . '-01'))], ['year', 'Best Customer of the Year', $bestYear, $by]] as [$kind, $label, $list, $when]) { $b = $list[0] ?? null; ?>
    <div class="col-xl-6">
        <div class="ca-best <?= $kind; ?> h-100">
            <div class="ca-deco1"></div><div class="ca-deco2"></div>
            <div class="ca-head">
                <div class="d-flex align-items-center gap-3"><span class="ca-ico"><i class="bi bi-trophy-fill"></i></span><span class="ca-title"><?= an_e($label); ?></span></div>
                <form method="GET" class="m-0">
                    <?php foreach ($base as $k => $v) { if (in_array($k, ['bm', 'by'], true) || is_array($v)) { continue; } ?><input type="hidden" name="<?= an_e($k); ?>" value="<?= an_e($v); ?>"><?php } ?>
                    <?php if ($kind === 'month') { ?><input type="month" name="bm" value="<?= an_e($bm); ?>" max="<?= date('Y-m'); ?>" class="ca-pick" onchange="this.form.submit()" aria-label="Month"><input type="hidden" name="by" value="<?= an_e($by); ?>">
                    <?php } else { ?><select name="by" class="ca-pick" onchange="this.form.submit()" aria-label="Year"><?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 6; $y--) { ?><option <?= (string) $y === (string) $by ? 'selected' : ''; ?>><?= $y; ?></option><?php } ?></select><input type="hidden" name="bm" value="<?= an_e($bm); ?>"><?php } ?>
                </form>
            </div>
            <?php if ($b) { $parts = preg_split('/\s+/', trim($b['name'])) ?: ['?']; ?>
                <div class="d-flex align-items-center gap-4 mt-4 flex-wrap">
                    <?php if ($b['photo']) { ?><img class="ca-avatar" src="<?= an_e('../../' . ltrim($b['photo'], '/')); ?>" alt=""><?php } else { ?><span class="ca-avatar"><?= an_e(strtoupper(mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''))); ?></span><?php } ?>
                    <div style="min-width:0;">
                        <div class="ca-name"><?= an_e($b['name']); ?></div>
                        <div class="ca-id mt-1">Customer ID<span><?= an_e($b['code']); ?></span></div>
                        <div class="mt-2"><span class="ca-cur">RWF</span><span class="ca-val"><?= number_format((float) $b['total'], 0); ?></span></div>
                    </div>
                </div>
                <div class="mt-3">
                    <span class="ca-pill"><?= (int) $b['purchases']; ?> <?= (int) $b['purchases'] === 1 ? 'purchase' : 'purchases'; ?></span>
                    <?php if ($b['loyalty_points'] !== null) { ?><span class="ca-pill"><?= number_format((int) $b['loyalty_points']); ?> loyalty points</span><?php } ?>
                    <?php if ($b['district']) { ?><span class="ca-pill"><?= an_e($b['district']); ?></span><?php } ?>
                </div>
                <?php if (count($list) > 1) { ?>
                <div class="ca-next">
                    <div class="ca-next-title">NEXT IN LINE</div>
                    <?php foreach (array_slice($list, 1, 4) as $i => $r) { ?>
                        <div class="ca-row"><span class="ca-num"><?= $i + 2; ?></span><a href="edit.php?id=<?= (int) $r['id']; ?>"><?= an_e($r['name']); ?></a><span class="ca-amt">RWF <?= number_format((float) $r['total'], 0); ?></span></div>
                    <?php } ?>
                </div>
                <?php } ?>
            <?php } else { ?>
                <div class="mt-4 fs-5">No confirmed purchases by registered customers in <?= an_e($when); ?>.</div>
            <?php } ?>
        </div>
    </div>
    <?php } ?>
</div>

<?php if (!empty($_GET['drill'])) { ?>
<div class="card border-0 shadow-sm mb-3" id="drill"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0"><?= an_e($drillTitle); ?> <span class="badge bg-secondary"><?= count($drillRows); ?></span></h6><a href="<?= an_e('analytics.php?' . http_build_query($base)); ?>" class="btn btn-sm btn-outline-secondary">Close list</a></div>
    <div class="table-responsive" style="max-height:420px;"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
        <tr><th>Customer ID</th><th>Name</th><th>Phone</th><th>Location</th><th>Registered</th><th class="text-end">Purchases*</th><th class="text-end">Value*</th><th class="text-end">Loyalty</th><th></th></tr>
        <?php if (!$drillRows) { ?><tr><td colspan="9" class="text-center text-muted py-3">No customers.</td></tr><?php } ?>
        <?php foreach ($drillRows as $r) { ?><tr><td><?= an_e($r['code']); ?></td><td><?= an_e($r['name']); ?></td><td><?= an_e($r['phone']); ?></td>
            <td class="small"><?= an_e(implode(', ', array_filter([$r['sector'], $r['district'], $r['province']]))); ?></td><td><?= an_e($r['registered']); ?></td>
            <td class="text-end"><?= (int) $r['purchases']; ?></td><td class="text-end"><?= an_e($money($r['total'])); ?></td><td class="text-end"><?= $r['loyalty_points'] === null ? '—' : (int) $r['loyalty_points']; ?></td>
            <td><a href="edit.php?id=<?= (int) $r['id']; ?>" class="btn btn-sm btn-outline-primary">Open</a></td></tr><?php } ?>
    </table></div>
    <div class="small text-muted mt-1">* in the chosen period (or that month).</div>
</div></div>
<?php } ?>

<div class="row g-3 mb-3">
    <div class="col-lg-7"><?php an_chart_card('caLocation', 'Customers by ' . $level, 'Registered in the period · ' . an_e($period['label']) . ($top ? ' · highest: <strong>' . an_e($top['label']) . '</strong> (' . $top['pct'] . '%)' : ''), 300); ?></div>
    <div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6>Location distribution</h6>
        <div class="table-responsive" style="max-height:300px;"><table class="table table-sm mb-0" style="font-size:13px;">
            <tr><th><?= ucfirst($level); ?></th><th class="text-end">Customers</th><th class="text-end">%</th></tr>
            <?php if (!$loc) { ?><tr><td colspan="3" class="text-muted text-center">No customers registered in this period.</td></tr><?php } ?>
            <?php foreach ($loc as $i => $r) { ?><tr><td><a href="<?= an_e($link(['drill' => 'location', 'level' => $level, 'value' => $r['value']])); ?>"><?= $i < 3 && $r['value'] !== '' ? '<i class="bi bi-geo-alt-fill text-danger"></i> ' : ''; ?><?= an_e($r['label']); ?></a></td><td class="text-end"><?= $r['n']; ?></td>
                <td class="text-end"><div class="d-flex align-items-center justify-content-end gap-1"><div class="progress" style="width:60px;height:6px;"><div class="progress-bar" style="width:<?= $r['pct']; ?>%"></div></div><?= $r['pct']; ?>%</div></td></tr><?php } ?>
        </table></div>
    </div></div></div>
</div>
<div class="row g-3 mb-3">
    <div class="col-lg-8"><?php an_chart_card('caGrowth', 'Customer growth', 'New customers per month (bars), total registered and growth rate (lines)', 280); ?></div>
    <div class="col-lg-4"><?php an_chart_card('caSegments', 'Customer segmentation', 'Each customer is in one segment only · ' . an_e($period['start'] === null ? 'activity this year' : $period['label']), 280); ?></div>
</div>
<div class="row g-3 mb-3">
    <div class="col-lg-8"><?php an_chart_card('caRevenue', 'Customer revenue trend', 'Confirmed purchases per month, registered customers vs walk-in; growth vs previous month', 280); ?></div>
    <div class="col-lg-4"><?php an_chart_card('caFreq', 'Purchase frequency', 'Customers by number of purchases in the period · average <strong>' . $avgFreq . '</strong> per buying customer', 280); ?></div>
</div>
<div class="row g-3 mb-3">
    <div class="col-lg-5"><?php an_chart_card('caServices', 'Most requested services', $services ? 'Share of service requests in the period' : 'No services sold in this period', 280); ?></div>
    <div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6>Services: requests per service</h6>
        <div class="table-responsive" style="max-height:280px;"><table class="table table-sm mb-0" style="font-size:13px;">
            <tr><th>Service</th><th class="text-end">Requests</th><th class="text-end">Quantity</th><th class="text-end">Customers</th><th class="text-end">% of requests</th></tr>
            <?php if (!$services) { ?><tr><td colspan="5" class="text-muted text-center">No services (products of type Service) sold in this period.</td></tr><?php } ?>
            <?php foreach ($services as $r) { ?><tr><td><a href="<?= an_e($link(['drill' => 'service', 'id' => $r['id']])); ?>"><?= an_e($r['name']); ?></a></td><td class="text-end"><?= $r['requests']; ?></td><td class="text-end"><?= (float) $r['quantity']; ?></td><td class="text-end"><?= (int) $r['customers']; ?></td><td class="text-end"><?= $r['pct']; ?>%</td></tr><?php } ?>
        </table></div>
        <div class="small text-muted mt-2">A request = one service line on a confirmed invoice.</div>
    </div></div></div>
</div>
<div class="small text-muted mb-3"><strong>Segments:</strong> <?php foreach (CA_SEGMENTS as $k => [$l, $d]) { ?><span class="me-3"><strong><?= an_e($l); ?></strong>: <?= an_e($d); ?>.</span><?php } ?> <strong>Frequency:</strong> one-time = 1 purchase, repeat = 2–4, frequent = 5 or more.</div>

<?php an_js(); ?>
<script>
(function () {
    if (typeof Chart === 'undefined') { return; }
    var P = window.rmPalette;
    var loc = <?= json_encode(array_slice($loc, 0, 15)); ?>;
    new Chart(document.getElementById('caLocation'), { type: 'bar', data: { labels: loc.map(function (r) { return r.label; }), datasets: [{ label: 'Customers', data: loc.map(function (r) { return r.n; }), backgroundColor: loc.map(function (r, i) { return i < 3 ? '#1E2FE0' : '#9AA5F5'; }) }] },
        options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return c.raw + ' customers (' + loc[c.dataIndex].pct + '%)'; } } } },
            onClick: rmChartLinks(<?= json_encode(array_map(function ($r) use ($link, $level) { return $link(['drill' => 'location', 'level' => $level, 'value' => $r['value']]); }, array_slice($loc, 0, 15))); ?>) } });

    var g = <?= json_encode($growth); ?>;
    new Chart(document.getElementById('caGrowth'), { data: { labels: g.map(function (r) { return r.label; }), datasets: [
            { type: 'bar', label: 'New customers', data: g.map(function (r) { return r.new; }), backgroundColor: '#1E2FE0', yAxisID: 'y' },
            { type: 'line', label: 'Total registered', data: g.map(function (r) { return r.total; }), borderColor: '#0FA968', backgroundColor: '#0FA968', yAxisID: 'y1', tension: .3 },
            { type: 'line', label: 'Growth rate %', data: g.map(function (r) { return r.rate; }), borderColor: '#E68A1C', backgroundColor: '#E68A1C', borderDash: [5, 4], yAxisID: 'y2', tension: .3 }] },
        options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true, title: { display: true, text: 'New' } }, y1: { position: 'right', beginAtZero: true, grid: { display: false }, title: { display: true, text: 'Total' } }, y2: { display: false, beginAtZero: true } },
            onClick: rmChartLinks(<?= json_encode(array_map(function ($r) use ($link) { return $link(['drill' => 'new_month', 'month' => $r['month']]); }, $growth)); ?>) } });

    var seg = <?= json_encode(array_values(array_map(function ($k) use ($seg) { return ['k' => $k, 'l' => CA_SEGMENTS[$k][0], 'n' => $seg[$k], 'c' => CA_SEGMENTS[$k][2]]; }, array_keys(CA_SEGMENTS)))); ?>;
    new Chart(document.getElementById('caSegments'), { type: 'doughnut', data: { labels: seg.map(function (s) { return s.l; }), datasets: [{ data: seg.map(function (s) { return s.n; }), backgroundColor: seg.map(function (s) { return s.c; }) }] },
        options: { maintainAspectRatio: false, cutout: '58%', plugins: { legend: { position: 'bottom' } }, onClick: rmChartLinks(<?= json_encode(array_map(function ($k) use ($link) { return $link(['drill' => 'segment', 'key' => $k]); }, array_keys(CA_SEGMENTS))); ?>) } });

    var rv = <?= json_encode($revenue); ?>;
    new Chart(document.getElementById('caRevenue'), { data: { labels: rv.map(function (r) { return r.label; }), datasets: [
            { type: 'line', label: 'Registered customers (RWF)', data: rv.map(function (r) { return r.registered; }), borderColor: '#1E2FE0', backgroundColor: 'rgba(30,47,224,.12)', fill: true, tension: .3 },
            { type: 'line', label: 'Walk-in (RWF)', data: rv.map(function (r) { return r.walkin; }), borderColor: '#8A90A3', backgroundColor: '#8A90A3', tension: .3 },
            { type: 'line', label: 'Growth vs previous month %', data: rv.map(function (r) { return r.growth; }), borderColor: '#E68A1C', borderDash: [5, 4], yAxisID: 'y2', tension: .3 }] },
        options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return v >= 1e6 ? (v / 1e6) + 'M' : v >= 1e3 ? (v / 1e3) + 'k' : v; } } }, y2: { position: 'right', grid: { display: false }, title: { display: true, text: '%' } } },
            onClick: rmChartLinks(<?= json_encode(array_map(function ($r) use ($link) { return $link(['drill' => 'buyers_month', 'month' => $r['month']]); }, $revenue)); ?>) } });

    var fq = <?= json_encode(array_values(array_map(function ($k) use ($freq) { return ['l' => CA_FREQ[$k], 'n' => $freq[$k]]; }, array_keys(CA_FREQ)))); ?>;
    new Chart(document.getElementById('caFreq'), { type: 'bar', data: { labels: fq.map(function (r) { return r.l; }), datasets: [{ label: 'Customers', data: fq.map(function (r) { return r.n; }), backgroundColor: ['#9AA5F5', '#1E2FE0', '#0FA968'] }] },
        options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, onClick: rmChartLinks(<?= json_encode(array_map(function ($k) use ($link) { return $link(['drill' => 'frequency', 'key' => $k]); }, array_keys(CA_FREQ))); ?>) } });

    var sv = <?= json_encode(array_values(array_map(function ($r) { return ['l' => $r['name'], 'n' => $r['requests'], 'pct' => $r['pct']]; }, array_slice($services, 0, 8)))); ?>;
    var other = <?= (int) array_sum(array_column(array_slice($services, 8), 'requests')); ?>;
    if (other > 0) { sv.push({ l: 'Other services', n: other }); }
    new Chart(document.getElementById('caServices'), { type: 'pie', data: { labels: sv.map(function (r) { return r.l; }), datasets: [{ data: sv.map(function (r) { return r.n; }), backgroundColor: P }] },
        options: { maintainAspectRatio: false, plugins: { legend: { position: 'right' }, tooltip: { callbacks: { label: function (c) { var t = c.dataset.data.reduce(function (a, b) { return a + b; }, 0); return c.label + ': ' + c.raw + ' requests (' + (t ? Math.round(1000 * c.raw / t) / 10 : 0) + '%)'; } } } },
            onClick: rmChartLinks(<?= json_encode(array_map(function ($r) use ($link) { return $link(['drill' => 'service', 'id' => $r['id']]); }, array_slice($services, 0, 8))); ?>) } });
})();
</script>
<?php include '../../includes/footer.php'; ?>