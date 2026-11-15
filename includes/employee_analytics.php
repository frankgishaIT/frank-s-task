<?php
/**
 * NEW FILE: includes/employee_analytics.php — data for the Employees Dashboard (Task 2, part A).
 *
 *  Attendance  from Attendance: Present and Late count as present; attendance % = present / (present + absent).
 *  Tasks       tasks assigned (created) in the period. Completed = Approved or Completed (submitted);
 *              Overdue = not completed and past its due date; Pending = the rest.
 *  Departments department-level KPI achievement (Performance & KPIs, same formula), task completion and attendance.
 *  Payroll     generated payroll per month (Draft and Paid). Cost = basic + overtime + performance bonus + commission
 *              + other bonus; net = what employees receive after deductions.
 */
require_once __DIR__ . '/analytics_ui.php';
require_once __DIR__ . '/kpi_helpers.php';

const EA_DONE = "('Approved', 'Completed')";

function ea_user_filter(?int $dep, string $col = 'u.department_id'): string
{
    return $dep ? " AND $col = " . (int) $dep : '';
}

function ea_bounds(array $period): array
{
    $end = min($period['end'] ?? date('Y-m-d'), date('Y-m-d'));
    $start = $period['start'] ?? date('Y-m-d', strtotime($end . ' -11 months'));
    return [min($start, $end), $end];
}

/** Daily buckets for up to 31 days, monthly otherwise. Returns [format for SQL, [key => label]]. */
function ea_buckets(string $from, string $to): array
{
    if ((strtotime($to) - strtotime($from)) / 86400 <= 31) {
        $out = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $out[$d] = date('D d M', strtotime($d)); }
        return ['%Y-%m-%d', $out];
    }
    return ['%Y-%m', an_months($from, $to)];
}

function ea_attendance(mysqli $conn, array $period, ?int $dep): array
{
    [$from, $to] = ea_bounds($period);
    [$fmt, $buckets] = ea_buckets($from, $to);
    $rows = [];
    foreach (an_rows($conn, "SELECT DATE_FORMAT(a.attendance_date, '$fmt') b, SUM(a.status = 'Present') present, SUM(a.status = 'Late') late, SUM(a.status = 'Absent') absent,
            SUM(a.status NOT IN ('Present', 'Late', 'Absent')) other
        FROM attendance a JOIN users u ON u.id = a.user_id WHERE a.attendance_date BETWEEN ? AND ?" . ea_user_filter($dep) . " GROUP BY b", 'ss', [$from, $to]) as $r) { $rows[$r['b']] = $r; }
    $out = [];
    foreach ($buckets as $k => $label) {
        $p = (int) ($rows[$k]['present'] ?? 0); $l = (int) ($rows[$k]['late'] ?? 0); $a = (int) ($rows[$k]['absent'] ?? 0);
        $out[] = ['key' => $k, 'label' => $label, 'present' => $p, 'late' => $l, 'absent' => $a, 'leave' => (int) ($rows[$k]['other'] ?? 0),
            'pct' => ($p + $l + $a) ? round(100 * ($p + $l) / ($p + $l + $a), 1) : null];
    }
    return $out;
}

/** Tasks: totals and per employee. */
function ea_tasks(mysqli $conn, array $period, ?int $dep): array
{
    [$from, $to] = ea_bounds($period);
    $today = date('Y-m-d');
    $rows = an_rows($conn, "SELECT u.id, MAX(u.names) names, MAX(d.name) department, COUNT(t.id) assigned, SUM(t.status IN " . EA_DONE . ") done,
            SUM(t.status NOT IN " . EA_DONE . " AND t.due_date IS NOT NULL AND t.due_date < ?) overdue
        FROM tasks t JOIN users u ON u.id = t.assigned_to LEFT JOIN departments d ON d.id = u.department_id
        WHERE DATE(COALESCE(t.created_at, t.due_date)) BETWEEN ? AND ?" . ea_user_filter($dep) . "
        GROUP BY u.id ORDER BY assigned DESC", 'sss', [$today, $from, $to]);
    $tot = ['assigned' => 0, 'done' => 0, 'overdue' => 0, 'pending' => 0];
    foreach ($rows as &$r) {
        $r['assigned'] = (int) $r['assigned']; $r['done'] = (int) $r['done']; $r['overdue'] = (int) $r['overdue'];
        $r['pending'] = max(0, $r['assigned'] - $r['done'] - $r['overdue']);
        $r['rate'] = $r['assigned'] ? round(100 * $r['done'] / $r['assigned'], 1) : null;
        foreach (['assigned', 'done', 'overdue', 'pending'] as $k) { $tot[$k] += $r[$k]; }
    }
    $tot['rate'] = $tot['assigned'] ? round(100 * $tot['done'] / $tot['assigned'], 1) : null;
    return ['rows' => $rows, 'total' => $tot];
}

/** Per department: KPI achievement (department-level KPIs), task completion, attendance, people. */
function ea_departments(mysqli $conn, array $period, ?int $dep): array
{
    [$from, $to] = ea_bounds($period);
    $deps = an_rows($conn, 'SELECT d.id, d.name, (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.is_active = 1) people FROM departments d WHERE d.is_active = 1' . ($dep ? ' AND d.id = ' . (int) $dep : '') . ' ORDER BY d.name');
    $tasks = []; $att = [];
    foreach (an_rows($conn, "SELECT u.department_id dep, COUNT(t.id) n, SUM(t.status IN " . EA_DONE . ") done FROM tasks t JOIN users u ON u.id = t.assigned_to
        WHERE DATE(COALESCE(t.created_at, t.due_date)) BETWEEN ? AND ? GROUP BY u.department_id", 'ss', [$from, $to]) as $r) { $tasks[(int) $r['dep']] = $r; }
    foreach (an_rows($conn, "SELECT u.department_id dep, SUM(a.status IN ('Present', 'Late')) p, SUM(a.status IN ('Present', 'Late', 'Absent')) n FROM attendance a JOIN users u ON u.id = a.user_id
        WHERE a.attendance_date BETWEEN ? AND ? GROUP BY u.department_id", 'ss', [$from, $to]) as $r) { $att[(int) $r['dep']] = $r; }
    $kpis = [];
    try { kpi_ensure($conn); $kpis = kpi_results($conn, kpi_list($conn, ['level' => 'department']), $to); } catch (Throwable $e) { error_log('ea dept kpis: ' . $e->getMessage()); }
    $out = [];
    foreach ($deps as $d) {
        $id = (int) $d['id'];
        $mine = array_values(array_filter($kpis, function ($k) use ($id) { return (int) $k['department_id'] === $id; }));
        $t = $tasks[$id] ?? null; $a = $att[$id] ?? null;
        $out[] = ['id' => $id, 'name' => $d['name'], 'people' => (int) $d['people'],
            'kpi_score' => $mine ? kpi_weighted($mine) : null, 'kpis' => $mine,
            'tasks' => (int) ($t['n'] ?? 0), 'task_rate' => ($t && (int) $t['n']) ? round(100 * $t['done'] / $t['n'], 1) : null,
            'attendance' => ($a && (int) $a['n']) ? round(100 * $a['p'] / $a['n'], 1) : null];
    }
    return $out;
}

/** Payroll per month (at least the last 6 months): totals and by department. */
function ea_payroll(mysqli $conn, array $period, ?int $dep): array
{
    [$from, $to] = ea_bounds($period);
    $from = min($from, date('Y-m-01', strtotime(date('Y-m-01', strtotime($to)) . ' -5 months')));
    $months = an_months($from, $to);
    $cost = "(p.basic_salary + COALESCE(p.overtime_pay, 0) + COALESCE(p.performance_bonus, 0) + COALESCE(p.sales_commission, 0) + COALESCE(p.bonus, 0))";
    $rows = [];
    try {
        $rows = an_rows($conn, "SELECT DATE_FORMAT(p.pay_period, '%Y-%m') m, COALESCE(d.name, 'No department') dep, SUM($cost) cost, SUM(p.net_salary) net, COUNT(*) people
            FROM payroll p JOIN users u ON u.id = p.user_id LEFT JOIN departments d ON d.id = u.department_id
            WHERE p.pay_period BETWEEN ? AND ?" . ea_user_filter($dep) . " GROUP BY m, dep", 'ss', [array_key_first($months) . '-01', date('Y-m-t', strtotime($to))]);
    } catch (Throwable $e) { error_log('ea payroll: ' . $e->getMessage()); }
    $byMonth = []; $deps = [];
    foreach ($rows as $r) {
        $byMonth[$r['m']]['cost'] = ($byMonth[$r['m']]['cost'] ?? 0) + (float) $r['cost'];
        $byMonth[$r['m']]['net'] = ($byMonth[$r['m']]['net'] ?? 0) + (float) $r['net'];
        $byMonth[$r['m']]['people'] = ($byMonth[$r['m']]['people'] ?? 0) + (int) $r['people'];
        $deps[$r['dep']][$r['m']] = (float) $r['cost'];
    }
    $out = []; $prev = null;
    foreach ($months as $m => $label) {
        $c = (float) ($byMonth[$m]['cost'] ?? 0);
        $out[] = ['month' => $m, 'label' => $label, 'cost' => $c, 'net' => (float) ($byMonth[$m]['net'] ?? 0), 'people' => (int) ($byMonth[$m]['people'] ?? 0),
            'change' => $prev ? round(100 * ($c - $prev) / $prev, 1) : null];
        $prev = $c;
    }
    return ['months' => $out, 'departments' => $deps];
}