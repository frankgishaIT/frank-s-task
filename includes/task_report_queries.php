<?php
/**
 * NEW FILE: includes/task_report_queries.php
 * Data layer for the Tasks & Performance reports. Same return shape as the other reports, so
 * report_screen_result() and report_render_pdf() draw it.
 *
 * Source: `tasks` (project, assigned employee, priority, status, due date, created / submitted /
 * reviewed dates, performance score), `projects`, `users`, `departments`.
 * Task workflow: Pending -> Accepted -> In Progress -> Completed (submitted, waiting for review)
 *                -> Approved or Rejected (after review).
 *   Approved = done and accepted          Waiting review = Completed
 *   Open     = Pending, Accepted, In Progress
 *   Overdue  = open (not submitted yet) and the due date has passed
 *   On time  = submitted on or before the due date
 *   Score    = performance_score given when the task is reviewed (the same score payroll uses)
 */
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/sales_report_queries.php'; // reuses sales_report_fetch()

// Submitted or reviewed: the employee has handed the task in, so it can no longer be overdue.
const TASK_REPORT_DONE = "('Completed', 'Approved', 'Rejected')";
const TASK_REPORT_DUE_SOON_DAYS = 7;

function task_report_types(): array
{
    return [
        'list'        => 'Task List',
        'overdue'     => 'Overdue & Due Soon',
        'employee'    => 'Employee Performance',
        'project'     => 'Project Progress',
    ];
}

function task_report_uses_period(string $type): bool { return $type !== 'overdue'; }

function task_report_lookups(mysqli $conn): array
{
    return [
        'projects'  => sales_report_fetch($conn, 'SELECT id, project_name FROM projects ORDER BY project_name'),
        'employees' => sales_report_fetch($conn, 'SELECT id, names FROM users ORDER BY names'),
        'statuses'  => array_column(sales_report_fetch($conn, "SELECT DISTINCT status FROM tasks WHERE status IS NOT NULL AND status <> '' ORDER BY status"), 'status'),
    ];
}

function task_report_build(mysqli $conn, string $type, array $req): array
{
    $types = task_report_types();
    $fail = function ($msg) use ($type, $types) {
        return ['error' => $msg, 'title' => $types[$type] ?? 'Task Report', 'type' => $type];
    };
    if (!isset($types[$type])) {
        return $fail('Please choose a report.');
    }
    $lookups = task_report_lookups($conn);

    $where = [];
    $bt = '';
    $params = [];
    $filters = [];

    if (task_report_uses_period($type)) {
        $period = report_period_from_request($req, true);
        if ($period['error']) {
            return $fail($period['error']);
        }
        if ($period['start'] !== null) {
            $where[] = 'DATE(t.created_at) BETWEEN ? AND ?'; $bt .= 'ss'; $params[] = $period['start']; $params[] = $period['end'];
        }
        $filters[] = ['Tasks created', $period['label']];
    } else {
        $where[] = 't.status NOT IN ' . TASK_REPORT_DONE;
        $where[] = 't.due_date IS NOT NULL';
        $where[] = 't.due_date <= DATE_ADD(CURDATE(), INTERVAL ' . TASK_REPORT_DUE_SOON_DAYS . ' DAY)';
        $filters[] = ['As of', date('d M Y') . ' (overdue, and due in the next ' . TASK_REPORT_DUE_SOON_DAYS . ' days)'];
    }

    $projectId = (int) ($req['project_id'] ?? 0);
    if ($projectId > 0) {
        $pname = null;
        foreach ($lookups['projects'] as $p) { if ((int) $p['id'] === $projectId) { $pname = $p['project_name']; } }
        if ($pname === null) { return $fail('That project was not found.'); }
        $where[] = 't.project_id = ?'; $bt .= 'i'; $params[] = $projectId;
        $filters[] = ['Project', $pname];
    }
    $eid = (int) ($req['employee_id'] ?? 0);
    if ($eid > 0) {
        $ename = null;
        foreach ($lookups['employees'] as $e) { if ((int) $e['id'] === $eid) { $ename = $e['names']; } }
        if ($ename === null) { return $fail('That employee was not found.'); }
        $where[] = 't.assigned_to = ?'; $bt .= 'i'; $params[] = $eid;
        $filters[] = ['Employee', $ename];
    }
    $priority = (string) ($req['priority'] ?? '');
    if (in_array($priority, ['Low', 'Medium', 'High'], true)) {
        $where[] = 't.priority = ?'; $bt .= 's'; $params[] = $priority;
        $filters[] = ['Priority', $priority];
    }
    $status = (string) ($req['status'] ?? '');
    if ($type === 'list' && $status !== '' && in_array($status, $lookups['statuses'], true)) {
        $where[] = 't.status = ?'; $bt .= 's'; $params[] = $status;
        $filters[] = ['Status', $status];
    }

    $rows = sales_report_fetch($conn, "SELECT t.id, t.title, t.priority, t.status, t.due_date, t.created_at, t.submitted_at, t.reviewed_at,
            t.performance_score, t.assigned_to, t.project_id,
            p.project_name, p.status AS project_status, u.names AS employee, d.name AS department,
            (t.status IN " . TASK_REPORT_DONE . ") AS is_done,
            CASE WHEN t.due_date IS NULL THEN NULL ELSE DATEDIFF(t.due_date, CURDATE()) END AS days_left,
            CASE WHEN t.submitted_at IS NULL OR t.due_date IS NULL THEN NULL ELSE (DATE(t.submitted_at) <= t.due_date) END AS on_time
        FROM tasks t
        LEFT JOIN projects p ON p.id = t.project_id
        LEFT JOIN users u ON u.id = t.assigned_to
        LEFT JOIN departments d ON d.id = u.department_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
        ORDER BY " . ($type === 'overdue' ? 't.due_date, t.priority' : 't.created_at DESC'), $bt, $params);

    foreach ($rows as &$r) {
        $r['is_done'] = (int) $r['is_done'] === 1;   // handed in (Completed, Approved or Rejected)
        $r['overdue'] = !$r['is_done'] && $r['days_left'] !== null && (int) $r['days_left'] < 0;
        $r['employee'] = $r['employee'] ?? 'Unassigned';
        $r['score_txt'] = $r['performance_score'] !== null ? round((float) $r['performance_score'], 1) . '/100' : '—';
        if ($r['is_done']) {
            $handed = $r['on_time'] === null ? '' : ((int) $r['on_time'] === 1 ? ' on time' : ' late');
            $r['when'] = ['Completed' => 'Submitted' . $handed . ', waiting review', 'Approved' => 'Approved' . ($handed ? ' (handed in' . $handed . ')' : ''),
                          'Rejected' => 'Rejected' . ($handed ? ' (handed in' . $handed . ')' : '')][$r['status']] ?? 'Handed in';
        } elseif ($r['days_left'] === null) {
            $r['when'] = 'No due date';
        } elseif ((int) $r['days_left'] < 0) {
            $r['when'] = 'Overdue ' . abs((int) $r['days_left']) . ' days';
        } elseif ((int) $r['days_left'] === 0) {
            $r['when'] = 'Due today';
        } else {
            $r['when'] = 'In ' . (int) $r['days_left'] . ' days';
        }
    }
    unset($r);

    $report = [
        'error' => null, 'type' => $type, 'title' => $types[$type], 'filters' => $filters,
        'summary' => [], 'columns' => [], 'rows' => [], 'totals' => [], 'totals_label' => 'TOTAL',
        'footer_summary' => [], 'notes' => [],
    ];

    switch ($type) {
        case 'list':     return task_report_list($report, $rows);
        case 'overdue':  return task_report_overdue($report, $rows);
        case 'employee': return task_report_grouped($report, $rows, 'assigned_to', 'employee', 'Employee');
        case 'project':  return task_report_grouped($report, $rows, 'project_id', 'project_name', 'Project');
    }
    return $fail('Please choose a report.');
}

// Shared counts for a set of tasks.
function task_report_stats(array $rows): array
{
    $done = 0; $overdue = 0; $onTime = 0; $timed = 0; $scores = []; $waiting = 0; $rejected = 0; $handed = 0;
    foreach ($rows as $r) {
        if ($r['status'] === 'Approved') { $done++; }
        if ($r['status'] === 'Completed') { $waiting++; }
        if ($r['status'] === 'Rejected') { $rejected++; }
        if ($r['is_done']) { $handed++; }
        if ($r['overdue']) { $overdue++; }
        if ($r['on_time'] !== null) { $timed++; if ((int) $r['on_time'] === 1) { $onTime++; } }
        if ($r['performance_score'] !== null) { $scores[] = (float) $r['performance_score']; }
    }
    $n = count($rows);
    return [
        'tasks' => $n, 'done' => $done, 'waiting' => $waiting, 'rejected' => $rejected, 'open' => $n - $handed, 'overdue' => $overdue,
        'done_pct' => $n ? round(100 * $done / $n, 1) . '%' : '—',
        'approval_pct' => ($done + $rejected) ? round(100 * $done / ($done + $rejected), 1) . '%' : '—',
        'on_time_pct' => $timed ? round(100 * $onTime / $timed, 1) . '%' : '—',
        'avg_score' => $scores ? round(array_sum($scores) / count($scores), 1) . '/100' : '—',
    ];
}

/* ---------------------------------------------------------------------------------------- */

function task_report_list(array $report, array $rows): array
{
    $s = task_report_stats($rows);
    $report['rows'] = $rows;
    $report['summary'] = [
        ['Tasks', number_format($s['tasks'])],
        ['Approved', number_format($s['done']) . ' (' . $s['done_pct'] . ')'],
        ['Overdue', number_format($s['overdue'])],
        ['Average Score', $s['avg_score']],
    ];
    $report['columns'] = [
        ['key' => 'title', 'label' => 'Task', 'w' => 18],
        ['key' => 'project_name', 'label' => 'Project', 'w' => 12],
        ['key' => 'employee', 'label' => 'Assigned To', 'w' => 11],
        ['key' => 'priority', 'label' => 'Priority', 'w' => 6],
        ['key' => 'status', 'label' => 'Status', 'w' => 8],
        ['key' => 'created_at', 'label' => 'Created', 'w' => 8, 'type' => 'date'],
        ['key' => 'due_date', 'label' => 'Due', 'w' => 8, 'type' => 'date'],
        ['key' => 'submitted_at', 'label' => 'Submitted', 'w' => 8, 'type' => 'date'],
        ['key' => 'when', 'label' => 'Timing', 'w' => 10],
        ['key' => 'score_txt', 'label' => 'Score', 'w' => 6, 'align' => 'R'],
    ];
    $report['footer_summary'] = [
        ['Open (not handed in)', number_format($s['open'])],
        ['Waiting Review', number_format($s['waiting'])],
        ['Rejected', number_format($s['rejected'])],
        ['Handed in on Time', $s['on_time_pct']],
    ];
    $report['notes'][] = 'Open = Pending, Accepted or In Progress. Completed = submitted and waiting for review. Overdue = still open after the due date. Timing compares the submission date with the due date. Score is the review score (it also counts in payroll).';
    return $report;
}

function task_report_overdue(array $report, array $rows): array
{
    $overdue = array_values(array_filter($rows, function ($r) { return $r['overdue']; }));
    $high = array_filter($rows, function ($r) { return $r['priority'] === 'High'; });
    $report['rows'] = $rows;
    $report['summary'] = [
        ['Tasks', number_format(count($rows))],
        ['Overdue', number_format(count($overdue))],
        ['Due Soon', number_format(count($rows) - count($overdue))],
        ['High Priority', number_format(count($high))],
    ];
    $report['columns'] = [
        ['key' => 'due_date', 'label' => 'Due', 'w' => 9, 'type' => 'date'],
        ['key' => 'when', 'label' => 'When', 'w' => 11],
        ['key' => 'title', 'label' => 'Task', 'w' => 22],
        ['key' => 'project_name', 'label' => 'Project', 'w' => 14],
        ['key' => 'employee', 'label' => 'Assigned To', 'w' => 13],
        ['key' => 'department', 'label' => 'Department', 'w' => 11],
        ['key' => 'priority', 'label' => 'Priority', 'w' => 7],
        ['key' => 'status', 'label' => 'Status', 'w' => 9],
    ];
    $report['notes'][] = 'Tasks not handed in yet (Pending, Accepted or In Progress) that are overdue, or due within the next ' . TASK_REPORT_DUE_SOON_DAYS . ' days. Follow up the overdue High-priority tasks first.';
    return $report;
}

// Employee Performance / Project Progress: the same counts grouped by person or by project.
function task_report_grouped(array $report, array $rows, string $idKey, string $nameKey, string $label): array
{
    $groups = [];
    foreach ($rows as $r) {
        $k = (string) ($r[$idKey] ?? '0');
        $groups[$k]['name'] = $r[$nameKey] ?? ($label === 'Employee' ? 'Unassigned' : '—');
        $groups[$k]['extra'] = $label === 'Employee' ? ($r['department'] ?? '—') : ($r['project_status'] ?? '—');
        $groups[$k]['rows'][] = $r;
    }
    $out = [];
    foreach ($groups as $g) {
        $out[] = ['name' => $g['name'], 'extra' => $g['extra']] + task_report_stats($g['rows']);
    }
    usort($out, function ($a, $b) { return $b['tasks'] <=> $a['tasks']; });

    $all = task_report_stats($rows);
    $report['rows'] = $out;
    $report['summary'] = [
        [$label === 'Employee' ? 'Employees' : 'Projects', number_format(count($out))],
        ['Tasks', number_format($all['tasks'])],
        ['Approved', $all['done_pct']],
        [$label === 'Employee' ? 'Average Score' : 'On Time', $label === 'Employee' ? $all['avg_score'] : $all['on_time_pct']],
    ];
    $report['columns'] = [
        ['key' => 'name', 'label' => $label, 'w' => 18],
        ['key' => 'extra', 'label' => $label === 'Employee' ? 'Department' : 'Project Status', 'w' => 12],
        ['key' => 'tasks', 'label' => 'Tasks', 'w' => 7, 'align' => 'R', 'type' => 'int'],
        ['key' => 'done', 'label' => 'Approved', 'w' => 7, 'align' => 'R', 'type' => 'int'],
        ['key' => 'rejected', 'label' => 'Rejected', 'w' => 7, 'align' => 'R', 'type' => 'int'],
        ['key' => 'waiting', 'label' => 'In Review', 'w' => 7, 'align' => 'R', 'type' => 'int'],
        ['key' => 'open', 'label' => 'Open', 'w' => 6, 'align' => 'R', 'type' => 'int'],
        ['key' => 'overdue', 'label' => 'Overdue', 'w' => 7, 'align' => 'R', 'type' => 'int'],
        ['key' => 'done_pct', 'label' => '% Approved', 'w' => 8, 'align' => 'R'],
        ['key' => 'on_time_pct', 'label' => 'On Time', 'w' => 8, 'align' => 'R'],
        ['key' => 'avg_score', 'label' => 'Avg Score', 'w' => 8, 'align' => 'R'],
    ];
    $report['totals'] = ['tasks' => $all['tasks'], 'done' => $all['done'], 'rejected' => $all['rejected'], 'waiting' => $all['waiting'],
                         'open' => $all['open'], 'overdue' => $all['overdue'],
                         'done_pct' => $all['done_pct'], 'on_time_pct' => $all['on_time_pct'], 'avg_score' => $all['avg_score']];
    $report['notes'][] = $label === 'Employee'
        ? 'Tasks assigned to each employee (created in the period). On Time = share of submitted tasks handed in by the due date. Avg Score = average review score, the same score payroll uses for the performance bonus.'
        : 'Tasks of each project (created in the period). % Done shows how far each project has progressed.';
    return $report;
}