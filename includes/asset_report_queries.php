<?php
/**
 * NEW FILE: includes/asset_report_queries.php
 * Data layer for the Assets & Depreciation reports. Same return shape as the Sales, Customers and
 * Transactions reports, so report_screen_result() and report_render_pdf() draw it.
 *
 * Sources:
 *   assets                    the register (acquisition value, residual, life, current value, status)
 *   asset_valuation_history   one row per day: daily_depreciation and the value at the end of that day
 *   asset_disposals           sold / stolen / damaged / disposed (book value, amount received, gain or loss)
 *   fund_movements            the assets' value in the RM Capital Fund (ref_type 'ASSET')
 *
 * "In use" = Active or Under Maintenance (the same rule as the RM Capital Fund).
 * Depreciation is straight-line, daily. Under Maintenance assets do not depreciate.
 * The page runs the depreciation catch-up first, so values are up to today.
 */
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/sales_report_queries.php'; // reuses sales_report_fetch()

const ASSET_REPORT_IN_USE = "('Active', 'Under Maintenance')";

function asset_report_types(): array
{
    return [
        'register'     => 'Asset Register',
        'depreciation' => 'Depreciation for the Period',
        'acquisitions' => 'Assets Added',
        'disposals'    => 'Assets Removed (Sold, Stolen, Damaged, Disposed)',
        'summary'      => 'Summary by Type / Department',
    ];
}

// Reports showing today's situation (no period).
function asset_report_uses_period(string $type): bool { return in_array($type, ['depreciation', 'acquisitions', 'disposals'], true); }

function asset_report_lookups(mysqli $conn): array
{
    return [
        'types'       => array_column(sales_report_fetch($conn, "SELECT DISTINCT asset_type FROM assets WHERE asset_type <> '' ORDER BY asset_type"), 'asset_type'),
        'departments' => sales_report_fetch($conn, 'SELECT id, name FROM departments ORDER BY name'),
        'statuses'    => ['in_use' => 'In use (Active + Under Maintenance)', 'Active' => 'Active', 'Under Maintenance' => 'Under Maintenance',
                          'Disposed' => 'Disposed', 'Lost' => 'Lost', 'all' => 'All statuses'],
    ];
}

function asset_report_build(mysqli $conn, string $type, array $req): array
{
    $types = asset_report_types();
    $fail = function ($msg) use ($type, $types) {
        return ['error' => $msg, 'title' => $types[$type] ?? 'Asset Report', 'type' => $type];
    };
    if (!isset($types[$type])) {
        return $fail('Please choose a report.');
    }
    $lookups = asset_report_lookups($conn);

    $filters = [];
    $period = null;
    if (asset_report_uses_period($type)) {
        $period = report_period_from_request($req);
        if ($period['error']) {
            return $fail($period['error']);
        }
        $filters[] = ['Period', $period['label']];
    } else {
        $filters[] = ['As of', date('d M Y')];
    }

    // Shared filters: asset type and department.
    $where = [];
    $bt = '';
    $params = [];
    $assetType = (string) ($req['asset_type'] ?? '');
    if ($assetType !== '' && in_array($assetType, $lookups['types'], true)) {
        $where[] = 'a.asset_type = ?'; $bt .= 's'; $params[] = $assetType;
        $filters[] = ['Asset Type', $assetType];
    }
    $deptId = (int) ($req['department_id'] ?? 0);
    if ($deptId > 0) {
        $deptName = null;
        foreach ($lookups['departments'] as $d) { if ((int) $d['id'] === $deptId) { $deptName = $d['name']; } }
        if ($deptName === null) {
            return $fail('That department was not found.');
        }
        $where[] = 'a.responsible_department_id = ?'; $bt .= 'i'; $params[] = $deptId;
        $filters[] = ['Department', $deptName];
    }

    $report = [
        'error' => null, 'type' => $type, 'title' => $types[$type], 'filters' => $filters,
        'summary' => [], 'columns' => [], 'rows' => [], 'totals' => [], 'totals_label' => 'TOTAL',
        'footer_summary' => [], 'notes' => [],
    ];

    try {
        switch ($type) {
            case 'register':     return asset_report_register($conn, $report, $where, $bt, $params, $req, $lookups);
            case 'depreciation': return asset_report_depreciation($conn, $report, $where, $bt, $params, $period);
            case 'acquisitions': return asset_report_acquisitions($conn, $report, $where, $bt, $params, $period);
            case 'disposals':    return asset_report_disposals($conn, $report, $where, $bt, $params, $period);
            case 'summary':      return asset_report_summary($conn, $report, $where, $bt, $params, $req);
        }
    } catch (mysqli_sql_exception $e) {
        error_log('asset report ' . $type . ' failed: ' . $e->getMessage());
        if (stripos($e->getMessage(), 'asset_disposals') !== false) {
            return $fail('The asset_disposals table is missing on this database. Run migration_asset_disposals.sql first.');
        }
        return $fail('Unable to build this report. Please try again.');
    }
    return $fail('Please choose a report.');
}

function asset_report_where(array $where, string $extra = ''): string
{
    $all = $where;
    if ($extra !== '') { $all[] = $extra; }
    $all[] = "a.status <> 'Deleted'"; // CHANGED (Task I): deleted assets are in no report
    return $all ? ' WHERE ' . implode(' AND ', $all) : '';
}

/* ---------------------------------------------------------------------------------------- */

// Every asset with its cost, depreciation so far and value today.
function asset_report_register(mysqli $conn, array $report, array $where, string $bt, array $params, array $req, array $lookups): array
{
    $status = (string) ($req['status'] ?? 'in_use');
    if (!isset($lookups['statuses'][$status])) { $status = 'in_use'; }
    if ($status === 'in_use') {
        $where[] = 'a.status IN ' . ASSET_REPORT_IN_USE;
    } elseif ($status !== 'all') {
        $where[] = 'a.status = ?'; $bt .= 's'; $params[] = $status;
    }
    $report['filters'][] = ['Status', $lookups['statuses'][$status]];

    $rows = sales_report_fetch($conn, "SELECT a.id, a.asset_code, a.asset_name, a.asset_type, a.acquisition_date, a.acquisition_value,
            a.residual_value, a.useful_life_days, a.current_value, a.status, d.name AS department
        FROM assets a LEFT JOIN departments d ON d.id = a.responsible_department_id"
        . asset_report_where($where) . " ORDER BY a.asset_code", $bt, $params);

    $tCost = 0.0; $tAccum = 0.0; $tValue = 0.0; $inUseValue = 0.0;
    foreach ($rows as &$r) {
        $r['life_years'] = round(((int) $r['useful_life_days']) / 365, 1);
        $r['accumulated'] = round((float) $r['acquisition_value'] - (float) $r['current_value'], 2);
        $r['age_years'] = round((time() - strtotime($r['acquisition_date'])) / 86400 / 365, 1);
        $tCost += (float) $r['acquisition_value'];
        $tAccum += $r['accumulated'];
        $tValue += (float) $r['current_value'];
        if (in_array($r['status'], ['Active', 'Under Maintenance'], true)) { $inUseValue += (float) $r['current_value']; }
    }
    unset($r);

    $report['rows'] = $rows;
    $report['summary'] = [
        ['Assets', number_format(count($rows))],
        ['Total Cost', report_money($tCost)],
        ['Depreciation So Far', report_money($tAccum)],
        ['Value Today', report_money($tValue)],
    ];
    $report['columns'] = [
        ['key' => 'asset_code', 'label' => 'Code', 'w' => 10],
        ['key' => 'asset_name', 'label' => 'Asset', 'w' => 15],
        ['key' => 'asset_type', 'label' => 'Type', 'w' => 9],
        ['key' => 'department', 'label' => 'Department', 'w' => 10],
        ['key' => 'acquisition_date', 'label' => 'Acquired', 'w' => 8, 'type' => 'date'],
        ['key' => 'age_years', 'label' => 'Age (yrs)', 'w' => 5, 'align' => 'R'],
        ['key' => 'life_years', 'label' => 'Life (yrs)', 'w' => 5, 'align' => 'R'],
        ['key' => 'acquisition_value', 'label' => 'Cost (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'residual_value', 'label' => 'Residual (RWF)', 'w' => 9, 'align' => 'R', 'type' => 'money'],
        ['key' => 'accumulated', 'label' => 'Depreciated (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'current_value', 'label' => 'Value Today (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'status', 'label' => 'Status', 'w' => 8],
    ];
    $report['totals'] = ['acquisition_value' => $tCost, 'residual_value' => array_sum(array_column($rows, 'residual_value')), 'accumulated' => $tAccum, 'current_value' => $tValue];

    // Cross-check with the RM Capital Fund (only when no filter narrows the list).
    if ($status === 'in_use' && count($where) === 1) {
        $fund = sales_report_fetch($conn, "SELECT COALESCE(SUM(CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END), 0) AS v
            FROM fund_movements m JOIN funds f ON f.id = m.fund_id AND f.code = 'CAPITAL' WHERE m.ref_type = 'ASSET'");
        $inFund = round((float) ($fund[0]['v'] ?? 0), 2);
        $report['footer_summary'] = [
            ['Assets in Use, Value Today', report_money($inUseValue)],
            ['Assets in RM Capital Fund', report_money($inFund)],
            ['Difference', report_money($inFund - $inUseValue)],
            ['Check', abs($inFund - $inUseValue) < 0.01 ? 'Matches' : 'Check the Capital Fund'],
        ];
    }
    $report['notes'][] = 'Value Today = cost minus straight-line depreciation up to today, never below the residual value. Assets Under Maintenance do not depreciate.';
    return $report;
}

// Depreciation charged in the period, asset by asset.
function asset_report_depreciation(mysqli $conn, array $report, array $where, string $bt, array $params, array $period): array
{
    $s = $period['start']; $e = $period['end'];
    // Value at the start = last value BEFORE the period (or the cost if acquired in the period).
    // Value at the end   = last value ON OR BEFORE the end of the period.
    $rows = sales_report_fetch($conn, "SELECT a.id, a.asset_code, a.asset_name, a.asset_type, a.acquisition_date, a.acquisition_value, a.status,
            d.name AS department,
            (SELECT h.value FROM asset_valuation_history h WHERE h.asset_id = a.id AND h.valuation_date < ? ORDER BY h.valuation_date DESC LIMIT 1) AS value_before,
            (SELECT h.value FROM asset_valuation_history h WHERE h.asset_id = a.id AND h.valuation_date <= ? ORDER BY h.valuation_date DESC LIMIT 1) AS value_end,
            (SELECT COALESCE(SUM(h.daily_depreciation), 0) FROM asset_valuation_history h WHERE h.asset_id = a.id AND h.valuation_date BETWEEN ? AND ?) AS charged
        FROM assets a LEFT JOIN departments d ON d.id = a.responsible_department_id"
        . asset_report_where($where, 'a.acquisition_date <= ?') . "
        ORDER BY a.asset_code", 'ssss' . $bt . 's', array_merge([$s, $e, $s, $e], $params, [$e]));

    $tStart = 0.0; $tCharged = 0.0; $tEnd = 0.0;
    $out = [];
    foreach ($rows as $r) {
        $start = $r['value_before'] !== null ? (float) $r['value_before'] : (float) $r['acquisition_value'];
        $end = $r['value_end'] !== null ? (float) $r['value_end'] : $start;
        $charged = round((float) $r['charged'], 2);
        // Leave out assets that did not depreciate in this period because they were already removed
        // (disposed or lost before it), or have no value left.
        if ($charged <= 0 && (in_array($r['status'], ['Disposed', 'Lost'], true) || $start <= 0)) { continue; }
        $r['value_start'] = $start;
        $r['value_end'] = $end;
        $r['charged'] = $charged;
        $r['note'] = $r['value_before'] === null ? 'Acquired in period' : (in_array($r['status'], ['Disposed', 'Lost'], true) ? $r['status'] : '');
        $tStart += $start; $tCharged += $charged; $tEnd += $end;
        $out[] = $r;
    }

    $report['rows'] = $out;
    $report['summary'] = [
        ['Assets', number_format(count($out))],
        ['Value at Start', report_money($tStart)],
        ['Depreciation Charged', report_money($tCharged)],
        ['Value at End', report_money($tEnd)],
    ];
    $report['columns'] = [
        ['key' => 'asset_code', 'label' => 'Code', 'w' => 10],
        ['key' => 'asset_name', 'label' => 'Asset', 'w' => 17],
        ['key' => 'asset_type', 'label' => 'Type', 'w' => 10],
        ['key' => 'department', 'label' => 'Department', 'w' => 11],
        ['key' => 'value_start', 'label' => 'Value at Start (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
        ['key' => 'charged', 'label' => 'Depreciation (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
        ['key' => 'value_end', 'label' => 'Value at End (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
        ['key' => 'note', 'label' => 'Note', 'w' => 12],
    ];
    $report['totals'] = ['value_start' => $tStart, 'charged' => $tCharged, 'value_end' => $tEnd];
    $report['totals_label'] = 'TOTAL';
    $report['notes'][] = 'Depreciation is the cost of the assets wearing out in this period. It lowers the RM Capital Fund by the same amount. Days Under Maintenance are not depreciated.';
    return $report;
}

// Assets registered (acquired) in the period.
function asset_report_acquisitions(mysqli $conn, array $report, array $where, string $bt, array $params, array $period): array
{
    $rows = sales_report_fetch($conn, "SELECT a.asset_code, a.asset_name, a.asset_type, a.acquisition_date, a.acquisition_value,
            a.residual_value, a.useful_life_days, a.current_value, a.status, d.name AS department, u.names AS created_by_name
        FROM assets a LEFT JOIN departments d ON d.id = a.responsible_department_id LEFT JOIN users u ON u.id = a.created_by"
        . asset_report_where($where, 'a.acquisition_date BETWEEN ? AND ?') . " ORDER BY a.acquisition_date, a.asset_code",
        $bt . 'ss', array_merge($params, [$period['start'], $period['end']]));

    $tCost = 0.0; $tValue = 0.0;
    foreach ($rows as &$r) {
        $r['life_years'] = round(((int) $r['useful_life_days']) / 365, 1);
        $tCost += (float) $r['acquisition_value'];
        $tValue += (float) $r['current_value'];
    }
    unset($r);

    $report['rows'] = $rows;
    $report['summary'] = [
        ['Assets Added', number_format(count($rows))],
        ['Total Cost', report_money($tCost)],
        ['Value Today', report_money($tValue)],
    ];
    $report['columns'] = [
        ['key' => 'acquisition_date', 'label' => 'Acquired', 'w' => 9, 'type' => 'date'],
        ['key' => 'asset_code', 'label' => 'Code', 'w' => 10],
        ['key' => 'asset_name', 'label' => 'Asset', 'w' => 16],
        ['key' => 'asset_type', 'label' => 'Type', 'w' => 10],
        ['key' => 'department', 'label' => 'Department', 'w' => 11],
        ['key' => 'life_years', 'label' => 'Life (yrs)', 'w' => 6, 'align' => 'R'],
        ['key' => 'acquisition_value', 'label' => 'Cost (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'current_value', 'label' => 'Value Today (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'status', 'label' => 'Status', 'w' => 9],
        ['key' => 'created_by_name', 'label' => 'Registered By', 'w' => 11],
    ];
    $report['totals'] = ['acquisition_value' => $tCost, 'current_value' => $tValue];
    $report['notes'][] = 'Each asset added increases the RM Capital Fund by its value.';
    return $report;
}

// Assets sold, stolen, damaged or disposed in the period, with the gain or loss.
function asset_report_disposals(mysqli $conn, array $report, array $where, string $bt, array $params, array $period): array
{
    $rows = sales_report_fetch($conn, "SELECT x.disposal_date, x.disposal_type, x.book_value, x.proceeds, x.gain_loss, x.notes,
            a.asset_code, a.asset_name, a.asset_type, a.acquisition_value, u.names AS recorded_by_name
        FROM asset_disposals x JOIN assets a ON a.id = x.asset_id LEFT JOIN users u ON u.id = x.recorded_by"
        . asset_report_where($where, 'x.disposal_date BETWEEN ? AND ?') . " ORDER BY x.disposal_date, x.id",
        $bt . 'ss', array_merge($params, [$period['start'], $period['end']]));

    $tBook = 0.0; $tProceeds = 0.0; $tGain = 0.0; $tLoss = 0.0; $byReason = [];
    foreach ($rows as &$r) {
        $g = (float) $r['gain_loss'];
        $r['result'] = $g > 0.009 ? 'Gain' : ($g < -0.009 ? 'Loss' : '—');
        $tBook += (float) $r['book_value'];
        $tProceeds += (float) $r['proceeds'];
        if ($g > 0) { $tGain += $g; } else { $tLoss += -$g; }
        $byReason[$r['disposal_type']] = ($byReason[$r['disposal_type']] ?? 0) + 1;
    }
    unset($r);

    $report['rows'] = $rows;
    $report['summary'] = [
        ['Assets Removed', number_format(count($rows))],
        ['Book Value Removed', report_money($tBook)],
        ['Amount Received', report_money($tProceeds)],
        ['Net Gain / (Loss)', report_money($tGain - $tLoss)],
    ];
    $report['columns'] = [
        ['key' => 'disposal_date', 'label' => 'Date', 'w' => 9, 'type' => 'date'],
        ['key' => 'asset_code', 'label' => 'Code', 'w' => 10],
        ['key' => 'asset_name', 'label' => 'Asset', 'w' => 15],
        ['key' => 'disposal_type', 'label' => 'Reason', 'w' => 8],
        ['key' => 'acquisition_value', 'label' => 'Cost (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'book_value', 'label' => 'Book Value (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'proceeds', 'label' => 'Received (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'gain_loss', 'label' => 'Gain/(Loss) (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'notes', 'label' => 'Notes', 'w' => 12],
        ['key' => 'recorded_by_name', 'label' => 'Recorded By', 'w' => 9],
    ];
    $report['totals'] = ['acquisition_value' => array_sum(array_column($rows, 'acquisition_value')), 'book_value' => $tBook, 'proceeds' => $tProceeds, 'gain_loss' => $tGain - $tLoss];
    foreach ($byReason as $reason => $n) {
        $report['footer_summary'][] = [$reason, number_format($n)];
    }
    $report['notes'][] = 'Gain/(Loss) = amount received minus book value. A negative amount is a loss (recorded as an Asset Loss expense); a positive amount is a gain (Asset Gain income).';
    return $report;
}

// Assets in use grouped by type or by department.
function asset_report_summary(mysqli $conn, array $report, array $where, string $bt, array $params, array $req): array
{
    $byDept = ($req['group_by'] ?? 'type') === 'department';
    $group = $byDept ? "COALESCE(d.name, 'No department')" : 'a.asset_type';
    $report['filters'][] = ['Grouped By', $byDept ? 'Department' : 'Asset Type'];

    $rows = sales_report_fetch($conn, "SELECT $group AS grp, COUNT(*) AS assets,
            SUM(a.acquisition_value) AS cost, SUM(a.acquisition_value - a.current_value) AS accumulated, SUM(a.current_value) AS value,
            SUM(CASE WHEN a.status = 'Under Maintenance' THEN 1 ELSE 0 END) AS maintenance
        FROM assets a LEFT JOIN departments d ON d.id = a.responsible_department_id"
        . asset_report_where($where, 'a.status IN ' . ASSET_REPORT_IN_USE) . "
        GROUP BY grp ORDER BY value DESC", $bt, $params);

    $t = ['assets' => 0, 'cost' => 0.0, 'accumulated' => 0.0, 'value' => 0.0, 'maintenance' => 0];
    foreach ($rows as &$r) {
        $r['share'] = 0;
        foreach ($t as $k => $v) { $t[$k] += $r[$k]; }
    }
    unset($r);
    foreach ($rows as &$r) {
        $r['share'] = $t['value'] > 0 ? round(100 * (float) $r['value'] / $t['value'], 1) . '%' : '—';
    }
    unset($r);

    $report['rows'] = $rows;
    $report['summary'] = [
        ['Assets in Use', number_format($t['assets'])],
        ['Total Cost', report_money($t['cost'])],
        ['Depreciation So Far', report_money($t['accumulated'])],
        ['Value Today', report_money($t['value'])],
    ];
    $report['columns'] = [
        ['key' => 'grp', 'label' => $byDept ? 'Department' : 'Asset Type', 'w' => 20],
        ['key' => 'assets', 'label' => 'Assets', 'w' => 8, 'align' => 'R', 'type' => 'int'],
        ['key' => 'maintenance', 'label' => 'Under Maint.', 'w' => 9, 'align' => 'R', 'type' => 'int'],
        ['key' => 'cost', 'label' => 'Cost (RWF)', 'w' => 14, 'align' => 'R', 'type' => 'money'],
        ['key' => 'accumulated', 'label' => 'Depreciated (RWF)', 'w' => 14, 'align' => 'R', 'type' => 'money'],
        ['key' => 'value', 'label' => 'Value Today (RWF)', 'w' => 14, 'align' => 'R', 'type' => 'money'],
        ['key' => 'share', 'label' => 'Share of Value', 'w' => 9, 'align' => 'R'],
    ];
    $report['totals'] = ['assets' => $t['assets'], 'maintenance' => $t['maintenance'], 'cost' => $t['cost'], 'accumulated' => $t['accumulated'], 'value' => $t['value']];
    $report['notes'][] = 'Only assets in use (Active and Under Maintenance) are counted, the same as the RM Capital Fund.';
    return $report;
}