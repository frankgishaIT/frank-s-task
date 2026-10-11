<?php
/**
 * NEW FILE: includes/payroll_report_queries.php
 * Data layer for the Payroll reports. Same return shape as the other reports, so
 * report_screen_result() and report_render_pdf() draw it.
 *
 * Source: the `payroll` table (one payslip per employee per month; pay_period = first day of the month).
 *   Earnings   = basic salary + overtime pay + performance bonus + sales commission + extra bonus
 *   Deductions = attendance deduction + other deductions
 *   Net Salary = Earnings - Deductions
 * Only "Paid" payslips have been paid from the RM Business Operating Fund; Drafts have not.
 */
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/sales_report_queries.php'; // reuses sales_report_fetch()

function payroll_report_types(): array
{
    return [
        'register'   => 'Payroll Register',
        'employee'   => 'Employee Payroll History',
        'monthly'    => 'Monthly Payroll Totals',
        'department' => 'Payroll by Department',
        'attendance' => 'Attendance & Performance',
    ];
}

function payroll_report_lookups(mysqli $conn): array
{
    return [
        'employees'   => sales_report_fetch($conn, 'SELECT id, names FROM users ORDER BY names'),
        'departments' => sales_report_fetch($conn, 'SELECT id, name FROM departments ORDER BY name'),
        'statuses'    => ['all' => 'Paid and Draft', 'Paid' => 'Paid only', 'Draft' => 'Draft only'],
    ];
}

// "Earnings" and "Deductions" of a payslip row, as SQL (alias p).
const PAYROLL_REPORT_EARNINGS_SQL = '(p.basic_salary + p.overtime_pay + p.performance_bonus + p.sales_commission + p.bonus)';
const PAYROLL_REPORT_DEDUCTIONS_SQL = '(p.attendance_deduction + p.deductions)';

function payroll_report_build(mysqli $conn, string $type, array $req): array
{
    $types = payroll_report_types();
    $fail = function ($msg) use ($type, $types) {
        return ['error' => $msg, 'title' => $types[$type] ?? 'Payroll Report', 'type' => $type];
    };
    if (!isset($types[$type])) {
        return $fail('Please choose a report.');
    }
    $lookups = payroll_report_lookups($conn);

    $period = report_period_from_request($req, true);
    if ($period['error']) {
        return $fail($period['error']);
    }
    $where = [];
    $bt = '';
    $params = [];
    $filters = [['Pay Period', $period['label']]];
    if ($period['start'] !== null) {
        // pay_period is the first day of the month: a month counts when its first day is in the range,
        // or the range starts inside that month.
        $where[] = 'p.pay_period BETWEEN ? AND ?';
        $bt .= 'ss';
        $params[] = date('Y-m-01', strtotime($period['start']));
        $params[] = $period['end'];
    }

    $eid = (int) ($req['employee_id'] ?? 0);
    if ($type === 'employee' && $eid <= 0) {
        return $fail('Please select an employee.');
    }
    if ($eid > 0) {
        $name = null;
        foreach ($lookups['employees'] as $e) { if ((int) $e['id'] === $eid) { $name = $e['names']; } }
        if ($name === null) {
            return $fail('That employee was not found.');
        }
        $where[] = 'p.user_id = ?'; $bt .= 'i'; $params[] = $eid;
        $filters[] = ['Employee', $name];
    }

    $deptId = (int) ($req['department_id'] ?? 0);
    if ($deptId > 0) {
        $dname = null;
        foreach ($lookups['departments'] as $d) { if ((int) $d['id'] === $deptId) { $dname = $d['name']; } }
        if ($dname === null) {
            return $fail('That department was not found.');
        }
        $where[] = 'u.department_id = ?'; $bt .= 'i'; $params[] = $deptId;
        $filters[] = ['Department', $dname];
    }

    $status = (string) ($req['status'] ?? 'all');
    if (!isset($lookups['statuses'][$status])) { $status = 'all'; }
    if ($status !== 'all') {
        $where[] = 'p.status = ?'; $bt .= 's'; $params[] = $status;
    }
    $filters[] = ['Status', $lookups['statuses'][$status]];

    $rows = sales_report_fetch($conn, "SELECT p.*, u.names AS employee, d.name AS department,
            " . PAYROLL_REPORT_EARNINGS_SQL . " AS earnings, " . PAYROLL_REPORT_DEDUCTIONS_SQL . " AS total_deductions
        FROM payroll p JOIN users u ON u.id = p.user_id LEFT JOIN departments d ON d.id = u.department_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
        ORDER BY p.pay_period DESC, u.names", $bt, $params);

    foreach ($rows as &$r) {
        $r['month'] = date('M Y', strtotime($r['pay_period']));
        $r['overtime_hours'] = round(((int) $r['overtime_minutes']) / 60, 1);
        $r['score'] = $r['avg_performance_score'] !== null ? round((float) $r['avg_performance_score'], 1) . '/100' : '—';
        $r['department'] = $r['department'] ?? '—';
    }
    unset($r);

    $report = [
        'error' => null, 'type' => $type, 'title' => $types[$type], 'filters' => $filters,
        'summary' => [], 'columns' => [], 'rows' => [], 'totals' => [], 'totals_label' => 'TOTAL',
        'footer_summary' => [], 'notes' => [],
    ];

    switch ($type) {
        case 'register':   return payroll_report_register($report, $rows);
        case 'employee':   return payroll_report_employee($report, $rows);
        case 'monthly':    return payroll_report_grouped($report, $rows, 'month', 'Month');
        case 'department': return payroll_report_grouped($report, $rows, 'department', 'Department');
        case 'attendance': return payroll_report_attendance($report, $rows);
    }
    return $fail('Please choose a report.');
}

function payroll_report_sum(array $rows, string $key): float
{
    return round(array_sum(array_map(function ($r) use ($key) { return (float) $r[$key]; }, $rows)), 2);
}

function payroll_report_paid_split(array $rows): array
{
    $paid = 0.0; $draft = 0.0;
    foreach ($rows as $r) {
        if ($r['status'] === 'Paid') { $paid += (float) $r['net_salary']; } else { $draft += (float) $r['net_salary']; }
    }
    return [round($paid, 2), round($draft, 2)];
}

/* ---------------------------------------------------------------------------------------- */

function payroll_report_register(array $report, array $rows): array
{
    [$paid, $draft] = payroll_report_paid_split($rows);
    $report['rows'] = $rows;
    $report['summary'] = [
        ['Payslips', number_format(count($rows))],
        ['Total Earnings', report_money(payroll_report_sum($rows, 'earnings'))],
        ['Total Deductions', report_money(payroll_report_sum($rows, 'total_deductions'))],
        ['Total Net Salary', report_money(payroll_report_sum($rows, 'net_salary'))],
    ];
    $report['columns'] = [
        ['key' => 'month', 'label' => 'Month', 'w' => 7],
        ['key' => 'employee', 'label' => 'Employee', 'w' => 13],
        ['key' => 'department', 'label' => 'Department', 'w' => 10],
        ['key' => 'basic_salary', 'label' => 'Basic', 'w' => 9, 'align' => 'R', 'type' => 'money'],
        ['key' => 'overtime_pay', 'label' => 'Overtime', 'w' => 8, 'align' => 'R', 'type' => 'money'],
        ['key' => 'performance_bonus', 'label' => 'Perf. Bonus', 'w' => 8, 'align' => 'R', 'type' => 'money'],
        ['key' => 'sales_commission', 'label' => 'Commission', 'w' => 8, 'align' => 'R', 'type' => 'money'],
        ['key' => 'bonus', 'label' => 'Extra Bonus', 'w' => 8, 'align' => 'R', 'type' => 'money'],
        ['key' => 'attendance_deduction', 'label' => 'Absence Ded.', 'w' => 8, 'align' => 'R', 'type' => 'money'],
        ['key' => 'deductions', 'label' => 'Other Ded.', 'w' => 8, 'align' => 'R', 'type' => 'money'],
        ['key' => 'net_salary', 'label' => 'Net Salary', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'status', 'label' => 'Status', 'w' => 6],
        ['key' => 'paid_at', 'label' => 'Paid On', 'w' => 8, 'type' => 'date'],
    ];
    $totals = [];
    foreach (['basic_salary', 'overtime_pay', 'performance_bonus', 'sales_commission', 'bonus', 'attendance_deduction', 'deductions', 'net_salary'] as $k) {
        $totals[$k] = payroll_report_sum($rows, $k);
    }
    $report['totals'] = $totals;
    $report['footer_summary'] = [
        ['Paid (from Operating Fund)', report_money($paid)],
        ['Draft (not paid yet)', report_money($draft)],
    ];
    $report['notes'][] = 'Net Salary = Basic + Overtime + Performance Bonus + Commission + Extra Bonus - Absence Deduction - Other Deductions. Only Paid payslips were paid from the RM Business Operating Fund.';
    return $report;
}

function payroll_report_employee(array $report, array $rows): array
{
    $report = payroll_report_register($report, $rows);
    $n = count($rows);
    $report['title'] = 'Employee Payroll History' . ($rows ? ' — ' . $rows[0]['employee'] : '');
    $report['summary'] = [
        ['Months', number_format($n)],
        ['Total Net Salary', report_money(payroll_report_sum($rows, 'net_salary'))],
        ['Average Net / Month', report_money($n ? payroll_report_sum($rows, 'net_salary') / $n : 0)],
        ['Commission Earned', report_money(payroll_report_sum($rows, 'sales_commission'))],
    ];
    // The employee and department columns repeat on every row: leave them out.
    $report['columns'] = array_values(array_filter($report['columns'], function ($c) { return !in_array($c['key'], ['employee', 'department'], true); }));
    return $report;
}

function payroll_report_grouped(array $report, array $rows, string $key, string $label): array
{
    $by = [];
    foreach ($rows as $r) {
        $g = $r[$key];
        if (!isset($by[$g])) {
            $by[$g] = ['grp' => $g, 'sort' => $key === 'month' ? $r['pay_period'] : $g, 'payslips' => 0, 'employees' => [],
                       'earnings' => 0.0, 'total_deductions' => 0.0, 'net_salary' => 0.0, 'paid' => 0.0, 'draft' => 0.0];
        }
        $by[$g]['payslips']++;
        $by[$g]['employees'][$r['user_id']] = true;
        $by[$g]['earnings'] += (float) $r['earnings'];
        $by[$g]['total_deductions'] += (float) $r['total_deductions'];
        $by[$g]['net_salary'] += (float) $r['net_salary'];
        $by[$g][$r['status'] === 'Paid' ? 'paid' : 'draft'] += (float) $r['net_salary'];
    }
    foreach ($by as &$g) { $g['employee_count'] = count($g['employees']); unset($g['employees']); }
    unset($g);
    $out = array_values($by);
    usort($out, function ($a, $b) use ($key) { return $key === 'month' ? strcmp($b['sort'], $a['sort']) : $b['net_salary'] <=> $a['net_salary']; });

    $report['rows'] = $out;
    $report['summary'] = [
        [$label === 'Month' ? 'Months' : 'Departments', number_format(count($out))],
        ['Total Earnings', report_money(payroll_report_sum($out, 'earnings'))],
        ['Total Net Salary', report_money(payroll_report_sum($out, 'net_salary'))],
        ['Paid', report_money(payroll_report_sum($out, 'paid'))],
    ];
    $report['columns'] = [
        ['key' => 'grp', 'label' => $label, 'w' => 16],
        ['key' => 'employee_count', 'label' => 'Employees', 'w' => 8, 'align' => 'R', 'type' => 'int'],
        ['key' => 'payslips', 'label' => 'Payslips', 'w' => 8, 'align' => 'R', 'type' => 'int'],
        ['key' => 'earnings', 'label' => 'Earnings (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
        ['key' => 'total_deductions', 'label' => 'Deductions (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
        ['key' => 'net_salary', 'label' => 'Net Salary (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
        ['key' => 'paid', 'label' => 'Paid (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
        ['key' => 'draft', 'label' => 'Draft (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
    ];
    $report['totals'] = [
        'payslips' => array_sum(array_column($out, 'payslips')), 'earnings' => payroll_report_sum($out, 'earnings'),
        'total_deductions' => payroll_report_sum($out, 'total_deductions'), 'net_salary' => payroll_report_sum($out, 'net_salary'),
        'paid' => payroll_report_sum($out, 'paid'), 'draft' => payroll_report_sum($out, 'draft'),
    ];
    $report['notes'][] = 'Earnings = basic + overtime + performance bonus + commission + extra bonus. Deductions = absence deduction + other deductions. Paid amounts came from the RM Business Operating Fund.';
    return $report;
}

function payroll_report_attendance(array $report, array $rows): array
{
    $present = array_sum(array_map(function ($r) { return (int) $r['present_days']; }, $rows));
    $absent = array_sum(array_map(function ($r) { return (int) $r['absent_days']; }, $rows));
    $scores = array_filter(array_map(function ($r) { return $r['avg_performance_score']; }, $rows), function ($v) { return $v !== null; });

    $report['rows'] = $rows;
    $report['summary'] = [
        ['Payslips', number_format(count($rows))],
        ['Attendance Rate', ($present + $absent) > 0 ? round(100 * $present / ($present + $absent), 1) . '%' : '—'],
        ['Overtime Hours', number_format(payroll_report_sum($rows, 'overtime_hours'), 1)],
        ['Average Task Score', $scores ? round(array_sum($scores) / count($scores), 1) . '/100' : '—'],
    ];
    $report['columns'] = [
        ['key' => 'month', 'label' => 'Month', 'w' => 8],
        ['key' => 'employee', 'label' => 'Employee', 'w' => 15],
        ['key' => 'department', 'label' => 'Department', 'w' => 12],
        ['key' => 'present_days', 'label' => 'Present', 'w' => 7, 'align' => 'R', 'type' => 'int'],
        ['key' => 'absent_days', 'label' => 'Absent', 'w' => 7, 'align' => 'R', 'type' => 'int'],
        ['key' => 'attendance_deduction', 'label' => 'Absence Ded. (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'overtime_hours', 'label' => 'Overtime (hrs)', 'w' => 8, 'align' => 'R'],
        ['key' => 'overtime_pay', 'label' => 'Overtime Pay (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'score', 'label' => 'Task Score', 'w' => 8, 'align' => 'R'],
        ['key' => 'performance_bonus', 'label' => 'Perf. Bonus (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
    ];
    $report['totals'] = [
        'present_days' => $present, 'absent_days' => $absent,
        'attendance_deduction' => payroll_report_sum($rows, 'attendance_deduction'),
        'overtime_hours' => round(payroll_report_sum($rows, 'overtime_hours'), 1),
        'overtime_pay' => payroll_report_sum($rows, 'overtime_pay'),
        'performance_bonus' => payroll_report_sum($rows, 'performance_bonus'),
    ];
    $report['notes'][] = 'Present = confirmed attendance days; Absent = expected weekdays not present. Task Score = average score of tasks approved in that month. These are the figures each payslip was calculated from.';
    return $report;
}