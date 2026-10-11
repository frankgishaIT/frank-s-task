<?php
/**
 * NEW FILE: includes/attendance_report_queries.php
 * Data layer for the Attendance reports. Same return shape as the other reports, so
 * report_screen_result() and report_render_pdf() draw it.
 *
 * Source: `attendance` (user_id, attendance_date, check_in, check_out, status, workflow_status,
 * overtime_minutes), `users`, `departments`.
 *   Attended      = Present or Late
 *   Working days  = Monday to Friday (the same rule payroll uses), counted up to today
 *   Not recorded  = working days with no attendance row for that employee
 *   Confirmed     = workflow_status 'Confirmed' (only confirmed days count in payroll)
 */
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/sales_report_queries.php'; // reuses sales_report_fetch()

function attendance_report_types(): array
{
    return [
        'register'   => 'Attendance Register',
        'employee'   => 'Attendance Summary by Employee',
        'department' => 'Attendance by Department',
        'late'       => 'Late Arrivals',
        'overtime'   => 'Overtime',
    ];
}

function attendance_report_lookups(mysqli $conn): array
{
    return [
        'employees'   => sales_report_fetch($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names'),
        'departments' => sales_report_fetch($conn, 'SELECT id, name FROM departments ORDER BY name'),
        'statuses'    => ['Present', 'Late', 'Absent', 'Leave'],
        'workflows'   => array_column(sales_report_fetch($conn, "SELECT DISTINCT workflow_status FROM attendance WHERE workflow_status IS NOT NULL AND workflow_status <> '' ORDER BY workflow_status"), 'workflow_status'),
    ];
}

// Monday-Friday days between two dates (inclusive), never counting days after today.
function attendance_report_working_days(string $start, string $end): int
{
    $s = new DateTime($start);
    $e = new DateTime(min($end, date('Y-m-d')));
    $n = 0;
    while ($s <= $e) {
        if ((int) $s->format('N') <= 5) { $n++; }
        $s->modify('+1 day');
    }
    return $n;
}

function attendance_report_build(mysqli $conn, string $type, array $req): array
{
    $types = attendance_report_types();
    $fail = function ($msg) use ($type, $types) {
        return ['error' => $msg, 'title' => $types[$type] ?? 'Attendance Report', 'type' => $type];
    };
    if (!isset($types[$type])) {
        return $fail('Please choose a report.');
    }
    $lookups = attendance_report_lookups($conn);

    $period = report_period_from_request($req);
    if ($period['error']) {
        return $fail($period['error']);
    }
    $where = ['a.attendance_date BETWEEN ? AND ?'];
    $bt = 'ss';
    $params = [$period['start'], $period['end']];
    $filters = [['Period', $period['label']]];

    $eid = (int) ($req['employee_id'] ?? 0);
    if ($eid > 0) {
        $name = null;
        foreach ($lookups['employees'] as $e) { if ((int) $e['id'] === $eid) { $name = $e['names']; } }
        if ($name === null) { return $fail('That employee was not found.'); }
        $where[] = 'a.user_id = ?'; $bt .= 'i'; $params[] = $eid;
        $filters[] = ['Employee', $name];
    }
    $deptId = (int) ($req['department_id'] ?? 0);
    if ($deptId > 0) {
        $dname = null;
        foreach ($lookups['departments'] as $d) { if ((int) $d['id'] === $deptId) { $dname = $d['name']; } }
        if ($dname === null) { return $fail('That department was not found.'); }
        $where[] = 'u.department_id = ?'; $bt .= 'i'; $params[] = $deptId;
        $filters[] = ['Department', $dname];
    }
    $status = (string) ($req['status'] ?? '');
    if ($type === 'register' && in_array($status, $lookups['statuses'], true)) {
        $where[] = 'a.status = ?'; $bt .= 's'; $params[] = $status;
        $filters[] = ['Status', $status];
    }
    $workflow = (string) ($req['workflow'] ?? '');
    if ($workflow !== '' && in_array($workflow, $lookups['workflows'], true)) {
        $where[] = 'a.workflow_status = ?'; $bt .= 's'; $params[] = $workflow;
        $filters[] = ['Confirmation', $workflow];
    }
    if ($type === 'late') { $where[] = "a.status = 'Late'"; }
    if ($type === 'overtime') { $where[] = 'a.overtime_minutes > 0'; }

    $rows = sales_report_fetch($conn, "SELECT a.*, u.names AS employee, d.name AS department, u.department_id,
            CASE WHEN a.check_in IS NOT NULL AND a.check_out IS NOT NULL AND a.check_out > a.check_in
                 THEN ROUND(TIME_TO_SEC(TIMEDIFF(a.check_out, a.check_in)) / 3600, 2) END AS hours
        FROM attendance a JOIN users u ON u.id = a.user_id LEFT JOIN departments d ON d.id = u.department_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.attendance_date DESC, u.names", $bt, $params);

    foreach ($rows as &$r) {
        $r['department'] = $r['department'] ?? '—';
        $r['overtime_hours'] = round(((int) ($r['overtime_minutes'] ?? 0)) / 60, 1);
        $r['check_in_txt'] = $r['check_in'] ? substr((string) $r['check_in'], 0, 5) : '';
        $r['check_out_txt'] = $r['check_out'] ? substr((string) $r['check_out'], 0, 5) : '';
        $r['day'] = date('D', strtotime($r['attendance_date']));
    }
    unset($r);

    $report = [
        'error' => null, 'type' => $type, 'title' => $types[$type], 'filters' => $filters,
        'summary' => [], 'columns' => [], 'rows' => [], 'totals' => [], 'totals_label' => 'TOTAL',
        'footer_summary' => [], 'notes' => [],
    ];
    $workingDays = attendance_report_working_days($period['start'], $period['end']);

    switch ($type) {
        case 'register':   return attendance_report_register($report, $rows);
        case 'employee':   return attendance_report_grouped($conn, $report, $rows, 'employee', $workingDays, $eid, $deptId);
        case 'department': return attendance_report_grouped($conn, $report, $rows, 'department', $workingDays, $eid, $deptId);
        case 'late':       return attendance_report_late($report, $rows);
        case 'overtime':   return attendance_report_overtime($report, $rows);
    }
    return $fail('Please choose a report.');
}

function attendance_report_count(array $rows, string $status): int
{
    return count(array_filter($rows, function ($r) use ($status) { return $r['status'] === $status; }));
}

/* ---------------------------------------------------------------------------------------- */

function attendance_report_register(array $report, array $rows): array
{
    $report['rows'] = $rows;
    $report['summary'] = [
        ['Records', number_format(count($rows))],
        ['Present', number_format(attendance_report_count($rows, 'Present'))],
        ['Late', number_format(attendance_report_count($rows, 'Late'))],
        ['Absent / Leave', number_format(attendance_report_count($rows, 'Absent')) . ' / ' . number_format(attendance_report_count($rows, 'Leave'))],
    ];
    $report['columns'] = [
        ['key' => 'attendance_date', 'label' => 'Date', 'w' => 9, 'type' => 'date'],
        ['key' => 'day', 'label' => 'Day', 'w' => 5],
        ['key' => 'employee', 'label' => 'Employee', 'w' => 16],
        ['key' => 'department', 'label' => 'Department', 'w' => 12],
        ['key' => 'status', 'label' => 'Status', 'w' => 8],
        ['key' => 'check_in_txt', 'label' => 'Check In', 'w' => 7],
        ['key' => 'check_out_txt', 'label' => 'Check Out', 'w' => 7],
        ['key' => 'hours', 'label' => 'Hours', 'w' => 6, 'align' => 'R'],
        ['key' => 'overtime_hours', 'label' => 'Overtime (hrs)', 'w' => 8, 'align' => 'R'],
        ['key' => 'workflow_status', 'label' => 'Confirmation', 'w' => 9],
    ];
    $report['totals'] = [
        'hours' => round(array_sum(array_map(function ($r) { return (float) $r['hours']; }, $rows)), 2),
        'overtime_hours' => round(array_sum(array_column($rows, 'overtime_hours')), 1),
    ];
    $report['notes'][] = 'Hours = check-out minus check-in. Only Confirmed records are counted in payroll.';
    return $report;
}

// Employee summary / department summary: attended, late, absent, leave, not recorded, rate, hours, overtime.
function attendance_report_grouped(mysqli $conn, array $report, array $rows, string $by, int $workingDays, int $eid, int $deptId): array
{
    // Every active employee in scope, so people with NO records still appear (all days "not recorded").
    $uWhere = ['u.is_active = 1']; $ubt = ''; $up = [];
    if ($eid > 0) { $uWhere[] = 'u.id = ?'; $ubt .= 'i'; $up[] = $eid; }
    if ($deptId > 0) { $uWhere[] = 'u.department_id = ?'; $ubt .= 'i'; $up[] = $deptId; }
    $users = sales_report_fetch($conn, "SELECT u.id, u.names, COALESCE(d.name, '—') AS department FROM users u
        LEFT JOIN departments d ON d.id = u.department_id WHERE " . implode(' AND ', $uWhere) . " ORDER BY u.names", $ubt, $up);

    $per = [];
    foreach ($users as $u) {
        $per[(int) $u['id']] = ['employee' => $u['names'], 'department' => $u['department'], 'present' => 0, 'late' => 0,
            'absent' => 0, 'leave' => 0, 'weekday_records' => 0, 'hours' => 0.0, 'overtime_hours' => 0.0, 'confirmed' => 0];
    }
    foreach ($rows as $r) {
        $k = (int) $r['user_id'];
        if (!isset($per[$k])) { continue; } // inactive employee
        $per[$k][['Present' => 'present', 'Late' => 'late', 'Absent' => 'absent', 'Leave' => 'leave'][$r['status']] ?? 'present']++;
        if ((int) date('N', strtotime($r['attendance_date'])) <= 5) { $per[$k]['weekday_records']++; }
        $per[$k]['hours'] += (float) $r['hours'];
        $per[$k]['overtime_hours'] += (float) $r['overtime_hours'];
        if (($r['workflow_status'] ?? '') === 'Confirmed') { $per[$k]['confirmed']++; }
    }
    foreach ($per as &$p) {
        $p['attended'] = $p['present'] + $p['late'];
        $p['not_recorded'] = max(0, $workingDays - $p['weekday_records']);
        $p['working_days'] = $workingDays;
    }
    unset($p);

    if ($by === 'department') {
        $g = [];
        foreach ($per as $p) {
            $k = $p['department'];
            if (!isset($g[$k])) {
                $g[$k] = ['department' => $k, 'employees' => 0, 'working_days' => 0, 'attended' => 0, 'present' => 0, 'late' => 0,
                          'absent' => 0, 'leave' => 0, 'not_recorded' => 0, 'hours' => 0.0, 'overtime_hours' => 0.0, 'confirmed' => 0];
            }
            $g[$k]['employees']++;
            foreach (['working_days', 'attended', 'present', 'late', 'absent', 'leave', 'not_recorded', 'hours', 'overtime_hours', 'confirmed'] as $f) {
                $g[$k][$f] += $p[$f];
            }
        }
        $out = array_values($g);
    } else {
        $out = array_values($per);
    }
    foreach ($out as &$o) {
        $o['rate'] = $o['working_days'] > 0 ? round(100 * $o['attended'] / $o['working_days'], 1) . '%' : '—';
        $o['hours'] = round($o['hours'], 2);
        $o['overtime_hours'] = round($o['overtime_hours'], 1);
    }
    unset($o);
    usort($out, function ($a, $b) { return $b['attended'] <=> $a['attended']; });

    $tot = function ($k) use ($out) { return array_sum(array_column($out, $k)); };
    $totalDays = $tot('working_days');
    $report['title'] = $by === 'department' ? 'Attendance by Department' : 'Attendance Summary by Employee';
    $report['rows'] = $out;
    $report['summary'] = [
        ['Working Days (Mon-Fri)', number_format($workingDays)],
        ['Attendance Rate', $totalDays > 0 ? round(100 * $tot('attended') / $totalDays, 1) . '%' : '—'],
        ['Late Arrivals', number_format($tot('late'))],
        ['Days Not Recorded', number_format($tot('not_recorded'))],
    ];
    $cols = [
        ['key' => 'attended', 'label' => 'Attended', 'w' => 7, 'align' => 'R', 'type' => 'int'],
        ['key' => 'late', 'label' => 'Late', 'w' => 6, 'align' => 'R', 'type' => 'int'],
        ['key' => 'absent', 'label' => 'Absent', 'w' => 6, 'align' => 'R', 'type' => 'int'],
        ['key' => 'leave', 'label' => 'Leave', 'w' => 6, 'align' => 'R', 'type' => 'int'],
        ['key' => 'not_recorded', 'label' => 'Not Recorded', 'w' => 8, 'align' => 'R', 'type' => 'int'],
        ['key' => 'rate', 'label' => 'Rate', 'w' => 7, 'align' => 'R'],
        ['key' => 'hours', 'label' => 'Hours', 'w' => 7, 'align' => 'R'],
        ['key' => 'overtime_hours', 'label' => 'Overtime (hrs)', 'w' => 8, 'align' => 'R'],
        ['key' => 'confirmed', 'label' => 'Confirmed', 'w' => 7, 'align' => 'R', 'type' => 'int'],
    ];
    $report['columns'] = $by === 'department'
        ? array_merge([['key' => 'department', 'label' => 'Department', 'w' => 16], ['key' => 'employees', 'label' => 'Employees', 'w' => 7, 'align' => 'R', 'type' => 'int']], $cols)
        : array_merge([['key' => 'employee', 'label' => 'Employee', 'w' => 16], ['key' => 'department', 'label' => 'Department', 'w' => 11]], $cols);
    $report['totals'] = ['attended' => $tot('attended'), 'late' => $tot('late'), 'absent' => $tot('absent'), 'leave' => $tot('leave'),
        'not_recorded' => $tot('not_recorded'), 'hours' => round($tot('hours'), 2), 'overtime_hours' => round($tot('overtime_hours'), 1), 'confirmed' => $tot('confirmed')];
    if ($by === 'department') { $report['totals']['employees'] = $tot('employees'); }
    $report['notes'][] = 'Attended = Present + Late. Rate = attended days / working days (Monday to Friday, up to today). Not Recorded = working days with no attendance entry at all. Confirmed = days that count in payroll.';
    return $report;
}

function attendance_report_late(array $report, array $rows): array
{
    $byEmployee = [];
    foreach ($rows as $r) { $byEmployee[$r['employee']] = ($byEmployee[$r['employee']] ?? 0) + 1; }
    arsort($byEmployee);
    $report['rows'] = $rows;
    $report['summary'] = [
        ['Late Arrivals', number_format(count($rows))],
        ['Employees', number_format(count($byEmployee))],
        ['Most Often Late', $byEmployee ? array_key_first($byEmployee) . ' (' . reset($byEmployee) . ')' : '—'],
    ];
    $report['columns'] = [
        ['key' => 'attendance_date', 'label' => 'Date', 'w' => 10, 'type' => 'date'],
        ['key' => 'day', 'label' => 'Day', 'w' => 5],
        ['key' => 'employee', 'label' => 'Employee', 'w' => 18],
        ['key' => 'department', 'label' => 'Department', 'w' => 14],
        ['key' => 'check_in_txt', 'label' => 'Check In', 'w' => 8],
        ['key' => 'check_out_txt', 'label' => 'Check Out', 'w' => 8],
        ['key' => 'hours', 'label' => 'Hours', 'w' => 7, 'align' => 'R'],
        ['key' => 'workflow_status', 'label' => 'Confirmation', 'w' => 10],
    ];
    $i = 0;
    foreach ($byEmployee as $name => $n) {
        if (++$i > 8) { break; }
        $report['footer_summary'][] = [$name, $n . ' time(s)'];
    }
    return $report;
}

function attendance_report_overtime(array $report, array $rows): array
{
    $report['rows'] = $rows;
    $hours = round(array_sum(array_column($rows, 'overtime_hours')), 1);
    $report['summary'] = [
        ['Days with Overtime', number_format(count($rows))],
        ['Overtime Hours', number_format($hours, 1)],
        ['Employees', number_format(count(array_unique(array_column($rows, 'user_id'))))],
    ];
    $report['columns'] = [
        ['key' => 'attendance_date', 'label' => 'Date', 'w' => 10, 'type' => 'date'],
        ['key' => 'day', 'label' => 'Day', 'w' => 5],
        ['key' => 'employee', 'label' => 'Employee', 'w' => 18],
        ['key' => 'department', 'label' => 'Department', 'w' => 14],
        ['key' => 'check_in_txt', 'label' => 'Check In', 'w' => 8],
        ['key' => 'check_out_txt', 'label' => 'Check Out', 'w' => 8],
        ['key' => 'overtime_hours', 'label' => 'Overtime (hrs)', 'w' => 9, 'align' => 'R'],
        ['key' => 'workflow_status', 'label' => 'Confirmation', 'w' => 10],
    ];
    $report['totals'] = ['overtime_hours' => $hours];
    $report['notes'][] = 'Overtime is paid in payroll only for Confirmed days (overtime hours x hourly rate).';
    return $report;
}