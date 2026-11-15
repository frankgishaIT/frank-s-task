<?php
/**
 * NEW FILE: modules/users/analytics.php — Employees Dashboard: Performance Analytics & Best Employee (Task 2).
 *   Best Employee of the Month (top), attendance trend, task completion & productivity, department comparison,
 *   payroll / employee cost trend, and the full KPI ranking with the tie-break rules.
 *   Filters: period (Today ... Custom) and department. Charts save as JPG; the page downloads as PDF.
 * Data rules: includes/employee_analytics.php and includes/best_employee.php.
 */
require '../../config/db.php';
require_once '../../includes/access_control.php';
require_once '../../includes/employee_analytics.php';
require_once '../../includes/best_employee.php';
if (current_user_role() === 'Admin') { require_once '../../includes/admin_error_guard.php'; }
if (!in_array(current_user_role(), ['Admin', 'Manager'], true) || !access_can('users.view', $conn)) {
    http_response_code(403); include '../../includes/access_denied.php'; exit;
}

if (empty($_GET['period'])) { $_GET['period'] = 'this_year'; }
$period = report_period_from_request($_GET, false);
if ($period['error'] ?? null) { $err = $period['error']; $period = report_period_from_request(['period' => 'this_year']); }
$dep = (int) ($_GET['department'] ?? 0) ?: null;
$rankMonth = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['rank_month'] ?? '')) ? $_GET['rank_month'] : date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
if (!empty($_GET['refresh'])) { be_ranking($conn, $rankMonth, true); }

$departments = an_rows($conn, 'SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name');
$depName = $dep ? (string) (array_column($departments, 'name', 'id')[$dep] ?? '') : 'All departments';
$att = ea_attendance($conn, $period, $dep);
$tasks = ea_tasks($conn, $period, $dep);
$depts = ea_departments($conn, $period, $dep);
$pay = ea_payroll($conn, $period, $dep);
$ranking = be_ranking($conn, $rankMonth);
if ($dep) { $depUsers = array_map('intval', array_column(an_rows($conn, 'SELECT id FROM users WHERE department_id = ?', 'i', [$dep]), 'id')); $ranking = array_values(array_filter($ranking, function ($r) use ($depUsers) { return in_array((int) $r['user_id'], $depUsers, true); })); }

$sum = function ($k) use ($att) { return array_sum(array_column($att, $k)); };
$attPct = ($sum('present') + $sum('late') + $sum('absent')) ? round(100 * ($sum('present') + $sum('late')) / ($sum('present') + $sum('late') + $sum('absent')), 1) : null;
[$from, $to] = ea_bounds($period);
$payInPeriod = array_sum(array_column(array_filter($pay['months'], function ($m) use ($from, $to) { return $m['month'] >= substr($from, 0, 7) && $m['month'] <= substr($to, 0, 7); }), 'cost'));
$activeEmployees = (int) (an_rows($conn, 'SELECT COUNT(*) n FROM users u WHERE u.is_active = 1' . ea_user_filter($dep))[0]['n'] ?? 0);
$money = function ($v) { return 'RWF ' . number_format((float) $v, 0); };
$summary = [['Active employees', number_format($activeEmployees)], ['Attendance', $attPct === null ? '—' : $attPct . '%'], ['Late arrivals', number_format($sum('late'))],
    ['Tasks completed', $tasks['total']['done'] . ' / ' . $tasks['total']['assigned'] . ($tasks['total']['rate'] !== null ? ' (' . $tasks['total']['rate'] . '%)' : '')],
    ['Overdue tasks', number_format($tasks['total']['overdue'])], ['Payroll cost', $money($payInPeriod)]];
$filters = [['Period', $period['label']], ['Department', $depName], ['Ranking month', date('F Y', strtotime($rankMonth . '-01'))]];
$fmtPct = function ($v) { return $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%'; };

if (($_GET['export'] ?? '') === 'pdf' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tables = [
        ['title' => 'Best employee ranking · ' . date('F Y', strtotime($rankMonth . '-01')), 'columns' => ['Rank', 'Employee', 'Department', 'Score', 'KPIs on target', 'KPI weight measured', 'Attendance'],
            'rows' => array_map(function ($r) use ($fmtPct) { return [$r['rank'] ?? 'Not ranked', $r['name'], $r['department'], $fmtPct($r['score']), $r['met'] . ' / ' . $r['measured'], $r['weight'], $fmtPct($r['attendance'])]; }, $ranking)],
        ['title' => 'Attendance', 'columns' => ['Period', 'Present', 'Late', 'Absent', 'Attendance %'], 'rows' => array_map(function ($r) use ($fmtPct) { return [$r['label'], $r['present'], $r['late'], $r['absent'], $fmtPct($r['pct'])]; }, $att)],
        ['title' => 'Tasks per employee', 'columns' => ['Employee', 'Department', 'Assigned', 'Completed', 'Pending', 'Overdue', 'Completion rate'], 'rows' => array_map(function ($r) use ($fmtPct) { return [$r['names'], $r['department'], $r['assigned'], $r['done'], $r['pending'], $r['overdue'], $fmtPct($r['rate'])]; }, $tasks['rows'])],
        ['title' => 'Departments', 'columns' => ['Department', 'Employees', 'KPI achievement', 'Tasks', 'Task completion', 'Attendance'], 'rows' => array_map(function ($d) use ($fmtPct) { return [$d['name'], $d['people'], $fmtPct($d['kpi_score']), $d['tasks'], $fmtPct($d['task_rate']), $fmtPct($d['attendance'])]; }, $depts)],
        ['title' => 'Payroll / employee cost', 'columns' => ['Month', 'Employees paid', 'Cost', 'Net paid', 'Change vs previous month'], 'rows' => array_map(function ($m) use ($money, $fmtPct) { return [$m['label'], $m['people'], $money($m['cost']), $money($m['net']), $fmtPct($m['change'])]; }, $pay['months'])],
    ];
    an_render_pdf('Employees Dashboard', $filters, $summary, an_posted_charts(), $tables, 'employees-dashboard-' . date('Ymd') . '.pdf');
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div><h2 class="mb-0">Employees Dashboard</h2><div class="small text-muted">Attendance, productivity, departments, employee costs and the Best Employee of the Month.</div></div>
    <div class="d-flex gap-2"><a href="index.php" class="btn btn-sm btn-outline-secondary">Employees</a><?php an_pdf_button(); ?></div>
</div>
<?php if (!empty($err)) { ?><div class="alert alert-danger"><?= an_e($err); ?> Showing This Year instead.</div><?php } ?>

<?php be_banner($conn); ?>

<form class="row g-2 mb-3 align-items-end" method="GET">
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Period</label><select name="period" id="edPeriod" class="form-select form-select-sm">
        <?php foreach (report_period_options() as $k => $l) { ?><option value="<?= $k; ?>" <?= $period['preset'] === $k ? 'selected' : ''; ?>><?= an_e($l); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2 ed-custom"><label class="form-label small mb-0">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= an_e($_GET['from'] ?? ''); ?>"></div>
    <div class="col-6 col-md-2 ed-custom"><label class="form-label small mb-0">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= an_e($_GET['to'] ?? ''); ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-0">Department</label><select name="department" class="form-select form-select-sm"><option value="0">All departments</option>
        <?php foreach ($departments as $d) { ?><option value="<?= (int) $d['id']; ?>" <?= $dep === (int) $d['id'] ? 'selected' : ''; ?>><?= an_e($d['name']); ?></option><?php } ?></select></div>
    <input type="hidden" name="rank_month" value="<?= an_e($rankMonth); ?>">
    <div class="col-12 col-md-2"><button class="btn btn-sm btn-primary w-100">Show</button></div>
</form>
<script>(function(){var p=document.getElementById('edPeriod');function f(){document.querySelectorAll('.ed-custom').forEach(function(e){e.style.display=p.value==='custom'?'':'none';});}p.addEventListener('change',f);f();})();</script>

<div class="row g-2 mb-3">
    <?php foreach ($summary as [$k, $v]) { ?><div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="small text-muted"><?= an_e($k); ?></div><div class="fw-bold"><?= an_e($v); ?></div></div></div></div><?php } ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8"><?php an_chart_card('edAttendance', 'Employee attendance trend', 'Present, late and absent (bars) and attendance % (line) · ' . an_e($period['label']), 290); ?></div>
    <div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6>Monthly attendance comparison</h6>
        <div class="table-responsive" style="max-height:290px;"><table class="table table-sm mb-0" style="font-size:12.5px;">
            <tr><th></th><th class="text-end">Present</th><th class="text-end">Late</th><th class="text-end">Absent</th><th class="text-end">%</th></tr>
            <?php foreach (array_reverse($att) as $r) { if (!$r['present'] && !$r['late'] && !$r['absent']) { continue; } ?><tr><td><?= an_e($r['label']); ?></td><td class="text-end"><?= $r['present']; ?></td><td class="text-end"><?= $r['late']; ?></td><td class="text-end"><?= $r['absent']; ?></td><td class="text-end fw-semibold"><?= $fmtPct($r['pct']); ?></td></tr><?php } ?>
        </table></div>
        <div class="small text-muted mt-1">Late arrivals count as present (they came). Days on leave are not absences.</div>
    </div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-4"><?php an_chart_card('edTaskStatus', 'Task completion & productivity', 'Tasks assigned in the period: ' . (int) $tasks['total']['assigned'] . ' · completion rate ' . $fmtPct($tasks['total']['rate']), 280); ?></div>
    <div class="col-lg-8"><?php an_chart_card('edTaskPeople', 'Task completion rate per employee', 'Completed / assigned (top 15 by tasks assigned)', 280); ?></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7"><?php an_chart_card('edDepts', 'Department performance comparison', 'Department KPI achievement vs target, task completion and attendance', 300); ?></div>
    <div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6>Department targets vs actual achievements</h6>
        <div class="table-responsive" style="max-height:300px;"><table class="table table-sm mb-0" style="font-size:12.5px;">
            <tr><th>Department / KPI</th><th class="text-end">Target</th><th class="text-end">Actual</th><th class="text-end">Achieved</th></tr>
            <?php $anyKpi = false; foreach ($depts as $d) { ?>
                <tr class="table-light"><td colspan="3"><strong><?= an_e($d['name']); ?></strong> <span class="text-muted">· <?= (int) $d['people']; ?> people</span></td><td class="text-end fw-bold"><?= $fmtPct($d['kpi_score']); ?></td></tr>
                <?php foreach ($d['kpis'] as $k) { $anyKpi = true; ?><tr><td class="ps-3"><?= an_e($k['name']); ?> <span class="text-muted small"><?= an_e($k['period_label']); ?></span></td>
                    <td class="text-end"><?= an_e($k['direction'] === 'range' ? kpi_fmt((float) $k['range_min'], $k['unit']) . '–' . kpi_fmt((float) $k['range_max'], $k['unit']) : kpi_fmt((float) $k['target'], $k['unit'])); ?></td>
                    <td class="text-end"><?= an_e(kpi_fmt($k['actual'], $k['unit'])); ?></td><td class="text-end"><?= $fmtPct($k['achievement']); ?></td></tr><?php } ?>
            <?php } ?>
            <?php if (!$anyKpi) { ?><tr><td colspan="4" class="text-muted small">No department-level KPIs yet. Set them in <a href="../kpi/kpis.php">Performance &amp; KPIs</a>.</td></tr><?php } ?>
        </table></div>
    </div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7"><?php an_chart_card('edPayroll', 'Employee cost & payroll trend', 'Total monthly employee cost and net pay (lines) · last months', 290); ?></div>
    <div class="col-lg-5"><?php an_chart_card('edPayrollDept', 'Payroll expenses by department', 'Employee cost per month, by department', 290); ?></div>
</div>

<div class="card border-0 shadow-sm mb-3" id="ranking"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h6 class="mb-0"><i class="bi bi-trophy text-warning"></i> Best Employee ranking · <?= an_e(date('F Y', strtotime($rankMonth . '-01'))); ?><?= $dep ? ' · ' . an_e($depName) : ''; ?></h6>
        <form method="GET" class="d-flex gap-1">
            <?php foreach ($_GET as $k => $v) { if ($k === 'rank_month' || $k === 'refresh' || is_array($v)) { continue; } ?><input type="hidden" name="<?= an_e($k); ?>" value="<?= an_e($v); ?>"><?php } ?>
            <input type="month" name="rank_month" value="<?= an_e($rankMonth); ?>" max="<?= date('Y-m'); ?>" class="form-control form-control-sm" onchange="this.form.submit()">
            <button name="refresh" value="1" class="btn btn-sm btn-outline-secondary" title="Recalculate now (otherwise every 15 minutes)"><i class="bi bi-arrow-clockwise"></i></button>
        </form>
    </div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
        <tr><th>Rank</th><th>Employee</th><th>Department</th><th class="text-end">Score</th><th class="text-end">KPIs on target</th><th class="text-end">KPI weight measured</th><th class="text-end">Attendance</th><th>KPIs</th></tr>
        <?php if (!$ranking) { ?><tr><td colspan="8" class="text-center text-muted py-3">No employee has KPIs for this month. Assign employee KPIs in <a href="../kpi/kpis.php">Performance &amp; KPIs</a>.</td></tr><?php } ?>
        <?php foreach ($ranking as $r) { ?>
        <tr class="<?= $r['rank'] === 1 ? 'table-warning' : ''; ?>">
            <td><?= $r['rank'] ? ($r['rank'] === 1 ? '<i class="bi bi-trophy-fill text-warning"></i> 1' : (int) $r['rank']) : '<span class="text-muted small">Not ranked</span>'; ?></td>
            <td><strong><?= an_e($r['name']); ?></strong></td><td><?= an_e($r['department']); ?></td>
            <td class="text-end fw-bold"><?= $fmtPct($r['score']); ?></td><td class="text-end"><?= (int) $r['met']; ?> / <?= (int) $r['measured']; ?></td><td class="text-end"><?= an_e($r['weight']); ?></td><td class="text-end"><?= $fmtPct($r['attendance']); ?></td>
            <td class="small"><?php foreach ($r['detail'] as $d) { ?><span class="me-2 text-nowrap"><?= an_e($d['name']); ?> <strong><?= $fmtPct($d['achievement']); ?></strong> <span class="text-muted">×<?= an_e($d['weight']); ?></span></span><?php } ?>
                <?php if (!$r['eligible']) { ?><div class="text-muted"><?= $r['score'] === null ? 'No KPI results recorded this month.' : 'Only attendance KPIs measured: attendance alone does not qualify.'; ?></div><?php } ?></td>
        </tr>
        <?php } ?>
    </table></div>
    <div class="small text-muted mt-2"><strong>How the winner is chosen:</strong> each employee's own KPIs (Active or Completed; Draft and Cancelled never count), achievement vs target capped at 120%, weighted by the KPI weights.
        At least one non-attendance KPI must have a result. Ties: 1) score, 2) more KPIs on target, 3) more KPI weight measured, 4) attendance rate; still equal = shared recognition.
        The Dashboard shows the last completed month (or the current month's leader, "so far", until then).</div>
</div></div>

<?php an_js(); ?>
<script>
(function () {
    if (typeof Chart === 'undefined') { return; }
    var P = window.rmPalette;
    var a = <?= json_encode($att); ?>;
    new Chart(document.getElementById('edAttendance'), { data: { labels: a.map(function (r) { return r.label; }), datasets: [
            { type: 'bar', label: 'Present', data: a.map(function (r) { return r.present; }), backgroundColor: '#0FA968', stack: 's' },
            { type: 'bar', label: 'Late', data: a.map(function (r) { return r.late; }), backgroundColor: '#E68A1C', stack: 's' },
            { type: 'bar', label: 'Absent', data: a.map(function (r) { return r.absent; }), backgroundColor: '#E24B4A', stack: 's' },
            { type: 'line', label: 'Attendance %', data: a.map(function (r) { return r.pct; }), borderColor: '#1E2FE0', backgroundColor: '#1E2FE0', yAxisID: 'y1', tension: .3 }] },
        options: { maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, title: { display: true, text: 'Days' } }, y1: { position: 'right', min: 0, max: 100, grid: { display: false }, title: { display: true, text: '%' } } } } });

    var tt = <?= json_encode($tasks['total']); ?>;
    new Chart(document.getElementById('edTaskStatus'), { type: 'doughnut', data: { labels: ['Completed', 'Pending', 'Overdue'], datasets: [{ data: [tt.done, tt.pending, tt.overdue], backgroundColor: ['#0FA968', '#9AA5F5', '#E24B4A'] }] },
        options: { maintainAspectRatio: false, cutout: '58%', plugins: { legend: { position: 'bottom' } }, onClick: rmChartLinks(['../tasks/index.php', '../tasks/index.php', '../tasks/index.php']) } });

    var tp = <?= json_encode(array_slice($tasks['rows'], 0, 15)); ?>;
    new Chart(document.getElementById('edTaskPeople'), { type: 'bar', data: { labels: tp.map(function (r) { return r.names; }), datasets: [
            { label: 'Completed', data: tp.map(function (r) { return r.done; }), backgroundColor: '#0FA968', stack: 't' },
            { label: 'Pending', data: tp.map(function (r) { return r.pending; }), backgroundColor: '#9AA5F5', stack: 't' },
            { label: 'Overdue', data: tp.map(function (r) { return r.overdue; }), backgroundColor: '#E24B4A', stack: 't' }] },
        options: { maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } },
            plugins: { tooltip: { callbacks: { afterBody: function (it) { var r = tp[it[0].dataIndex]; return 'Completion rate: ' + (r.rate === null ? '—' : r.rate + '%'); } } } } } });

    var d = <?= json_encode(array_map(function ($x) { return ['n' => $x['name'], 'k' => $x['kpi_score'], 't' => $x['task_rate'], 'a' => $x['attendance']]; }, $depts)); ?>;
    new Chart(document.getElementById('edDepts'), { type: 'bar', data: { labels: d.map(function (r) { return r.n; }), datasets: [
            { label: 'KPI achievement %', data: d.map(function (r) { return r.k; }), backgroundColor: '#1E2FE0' },
            { label: 'Task completion %', data: d.map(function (r) { return r.t; }), backgroundColor: '#0FA968' },
            { label: 'Attendance %', data: d.map(function (r) { return r.a; }), backgroundColor: '#F0B429' }] },
        options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true, suggestedMax: 100 } } } });

    var pm = <?= json_encode($pay['months']); ?>;
    var money = function (v) { return v >= 1e6 ? (v / 1e6).toFixed(1) + 'M' : v >= 1e3 ? Math.round(v / 1e3) + 'k' : v; };
    new Chart(document.getElementById('edPayroll'), { data: { labels: pm.map(function (r) { return r.label; }), datasets: [
            { type: 'bar', label: 'Employee cost (RWF)', data: pm.map(function (r) { return r.cost; }), backgroundColor: '#9AA5F5' },
            { type: 'line', label: 'Net pay (RWF)', data: pm.map(function (r) { return r.net; }), borderColor: '#1E2FE0', backgroundColor: '#1E2FE0', tension: .3 }] },
        options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { callback: money } } },
            plugins: { tooltip: { callbacks: { afterBody: function (it) { var r = pm[it[0].dataIndex]; return r.change === null ? '' : 'Change vs previous month: ' + r.change + '%'; } } } } } });

    var pd = <?= json_encode($pay['departments']); ?>, names = Object.keys(pd);
    new Chart(document.getElementById('edPayrollDept'), { type: 'bar', data: { labels: pm.map(function (r) { return r.label; }), datasets: names.map(function (n, i) {
            return { label: n, data: pm.map(function (r) { return pd[n][r.month] || 0; }), backgroundColor: P[i % P.length], stack: 'p' }; }) },
        options: { maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { callback: money } } } } });
})();
</script>
<?php include '../../includes/footer.php'; ?>