<?php
// Builds the three Fund reports (summary / contributions / expenditure).
// Used by modules/transactions/fund_reports.php (screen + CSV) and
// modules/transactions/fund_report_pdf.php (PDF), so every format always
// shows exactly the same numbers.
// Save as: includes/fund_report_queries.php

if (!function_exists('fund_get')) {
    require_once __DIR__ . '/fund_helpers.php';
}

function fund_report_titles(): array {
    return [
        'summary' => 'Fund Summary Report',
        'contributions' => 'Fund Contributions Report',
        'expenditure' => 'Fund Expenditure Report',
    ];
}

function fund_report_month($value, string $default): string {
    return (is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) ? $value : $default;
}

function fund_report_query(mysqli $conn, string $sql, string $types, array $params): array {
    $statement = mysqli_prepare($conn, $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($statement, $types, ...$params);
    }
    mysqli_stmt_execute($statement);
    return mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
}

/**
 * $input is normally $_GET: tab, from (YYYY-MM), to (YYYY-MM), fund_id.
 * Returns:
 *   tab, title, from, to, fund_id, fund_label,
 *   cols, numeric (indexes of money columns), rows, footer,
 *   fund_totals (name => amount), pivot (contributions tab only)
 */
function fund_report_build(mysqli $conn, array $input): array {
    $titles = fund_report_titles();
    $tab = (isset($input['tab']) && isset($titles[$input['tab']])) ? $input['tab'] : 'summary';
    $from = fund_report_month($input['from'] ?? null, date('Y-01'));
    $to = fund_report_month($input['to'] ?? null, date('Y-m'));
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $fundId = filter_var($input['fund_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    $fundLabel = 'All Funds';
    if ($fundId) {
        $fund = fund_get($conn, (int) $fundId);
        if ($fund) {
            $fundLabel = $fund['name'];
        } else {
            $fundId = 0;
        }
    }

    $fundSql = $fundId ? ' AND m.fund_id = ?' : '';
    $cols = [];
    $numeric = [];
    $rows = [];
    $footer = null;
    $fundTotals = [];
    $pivot = null;

    if ($tab === 'contributions') {
        $types = 'ss';
        $params = [$from, $to];
        if ($fundId) { $types .= 'i'; $params[] = $fundId; }
        $data = fund_report_query($conn, "SELECT m.created_at, m.period, f.name AS fund, m.movement_type, m.direction, m.source_type, m.description, u.names AS who, m.amount
            FROM fund_movements m
            JOIN funds f ON f.id = m.fund_id
            LEFT JOIN users u ON u.id = m.created_by
            WHERE ((m.direction = 'IN' AND m.movement_type IN ('ALLOCATION', 'CAPITAL_INFLOW'))
                OR (m.direction = 'OUT' AND m.movement_type = 'ADJUSTMENT'))
              AND m.period BETWEEN ? AND ? $fundSql
            ORDER BY m.period DESC, m.id DESC", $types, $params);

        $cols = ['Date Added', 'Period', 'Fund', 'Source', 'Description', 'Added By', 'Amount (RWF)'];
        $numeric = [6];
        $total = 0.0;
        foreach ($data as $d) {
            // A downward correction (ADJUSTMENT out) is shown as a negative contribution.
            $amount = $d['direction'] === 'OUT' ? -(float) $d['amount'] : (float) $d['amount'];
            $total += $amount;
            $fundTotals[$d['fund']] = ($fundTotals[$d['fund']] ?? 0) + $amount;
            $rows[] = [
                date('d M Y', strtotime($d['created_at'])),
                $d['period'],
                $d['fund'],
                $d['movement_type'] === 'ALLOCATION' ? 'Net Profit Allocation' : (($d['movement_type'] === 'ADJUSTMENT' ? 'Correction: ' : '') . ($d['source_type'] ?: 'Capital Inflow')),
                $d['description'] ?? '',
                $d['who'] ?? '—',
                $amount,
            ];
        }
        $footer = ['', '', '', '', '', 'Total Contributions', $total];

        // Monthly Net Profit allocation: one line per month, one column per fund.
        $order = ['FUTURE_PLANS', 'EMERGENCY', 'TEAM_GROWTH', 'OPERATING'];
        $names = [];
        foreach (funds_all($conn) as $f) { $names[$f['code']] = $f['name']; }
        $pv = fund_report_query($conn, "SELECT m.period, f.code, SUM(m.amount) AS amt
            FROM fund_movements m JOIN funds f ON f.id = m.fund_id
            WHERE m.movement_type = 'ALLOCATION' AND m.period BETWEEN ? AND ?
            GROUP BY m.period, f.code ORDER BY m.period DESC", 'ss', [$from, $to]);
        $byMonth = [];
        foreach ($pv as $p) { $byMonth[$p['period']][$p['code']] = (float) $p['amt']; }

        $pCols = ['Month'];
        foreach ($order as $code) { $pCols[] = $names[$code] ?? $code; }
        $pCols[] = 'Total (Net Profit)';
        $pRows = [];
        foreach ($byMonth as $month => $vals) {
            $line = [date('F Y', strtotime($month . '-01'))];
            foreach ($order as $code) { $line[] = $vals[$code] ?? 0.0; }
            $line[] = array_sum($vals);
            $pRows[] = $line;
        }
        $pivot = ['title' => 'Monthly Net Profit Allocation', 'cols' => $pCols, 'numeric' => [1, 2, 3, 4, 5], 'rows' => $pRows];
    } elseif ($tab === 'expenditure') {
        $types = 'ss';
        $params = [$from, $to];
        if ($fundId) { $types .= 'i'; $params[] = $fundId; }
        $data = fund_report_query($conn, "SELECT COALESCE(t.transaction_date, DATE(m.created_at)) AS dt, f.name AS fund, m.movement_type, m.direction,
                t.expense_category, COALESCE(NULLIF(t.description, ''), m.description) AS descr, u.names AS who, m.amount
            FROM fund_movements m
            JOIN funds f ON f.id = m.fund_id
            LEFT JOIN transactions t ON t.id = m.transaction_id
            LEFT JOIN users u ON u.id = m.created_by
            WHERE m.movement_type IN ('EXPENSE', 'REVERSAL') AND m.period BETWEEN ? AND ? $fundSql
            ORDER BY dt DESC, m.id DESC", $types, $params);

        $cols = ['Date', 'Fund', 'Type', 'Category', 'Description', 'Posted By', 'Amount (RWF)'];
        $numeric = [6];
        $total = 0.0;
        foreach ($data as $d) {
            // Expense = money out (+). Reversal = money returned to the fund (-).
            $signed = $d['direction'] === 'OUT' ? (float) $d['amount'] : -(float) $d['amount'];
            $total += $signed;
            $fundTotals[$d['fund']] = ($fundTotals[$d['fund']] ?? 0) + $signed;
            $rows[] = [
                date('d M Y', strtotime($d['dt'])),
                $d['fund'],
                $d['movement_type'] === 'REVERSAL' ? 'Reversal' : 'Expense',
                $d['expense_category'] ?: '—',
                $d['descr'] ?? '',
                $d['who'] ?? '—',
                $signed,
            ];
        }
        $footer = ['', '', '', '', '', 'Net Amount Used', $total];
    } else {
        $types = 'sssss';
        $params = [$from, $from, $to, $from, $to];
        $where = 'f.is_active = 1';
        if ($fundId) { $where .= ' AND f.id = ?'; $types .= 'i'; $params[] = $fundId; }
        $data = fund_report_query($conn, "SELECT f.id, f.name,
                COALESCE(SUM(CASE WHEN m.period < ? THEN CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END END), 0) AS opening,
                COALESCE(SUM(CASE WHEN m.period BETWEEN ? AND ? THEN CASE WHEN m.direction = 'IN' AND m.movement_type IN ('ALLOCATION', 'CAPITAL_INFLOW') THEN m.amount WHEN m.direction = 'OUT' AND m.movement_type = 'ADJUSTMENT' THEN -m.amount END END), 0) AS contrib,
                COALESCE(SUM(CASE WHEN m.period BETWEEN ? AND ? THEN CASE WHEN m.direction = 'OUT' AND m.movement_type <> 'ADJUSTMENT' THEN m.amount WHEN m.movement_type = 'REVERSAL' THEN -m.amount END END), 0) AS used
            FROM funds f
            LEFT JOIN fund_movements m ON m.fund_id = f.id
            WHERE $where
            GROUP BY f.id, f.name
            ORDER BY FIELD(f.code, 'FUTURE_PLANS', 'EMERGENCY', 'TEAM_GROWTH', 'OPERATING', 'CAPITAL')", $types, $params);

        $cols = ['Fund', 'Opening Balance', 'Contributions', 'Amount Used', 'Closing Balance', 'Available Now'];
        $numeric = [1, 2, 3, 4, 5];
        $sum = [0.0, 0.0, 0.0, 0.0, 0.0];
        foreach ($data as $d) {
            $opening = (float) $d['opening'];
            $contrib = (float) $d['contrib'];
            $used = (float) $d['used'];
            $closing = $opening + $contrib - $used;
            $available = fund_available($conn, (int) $d['id']);
            $rows[] = [$d['name'], $opening, $contrib, $used, $closing, $available];
            foreach ([$opening, $contrib, $used, $closing, $available] as $i => $v) { $sum[$i] += $v; }
        }
        $footer = array_merge(['Total'], $sum);
    }

    return [
        'tab' => $tab,
        'title' => $titles[$tab],
        'from' => $from,
        'to' => $to,
        'fund_id' => (int) $fundId,
        'fund_label' => $fundLabel,
        'cols' => $cols,
        'numeric' => $numeric,
        'rows' => $rows,
        'footer' => $footer,
        'fund_totals' => $fundTotals,
        'pivot' => $pivot,
    ];
}

/**
 * Converts a fund_report_build() result into the array shape that
 * report_render_pdf() (includes/report_pdf.php) expects, so the Fund
 * reports get the same Rise Motive header, footer and styling as every
 * other report.
 * Money and dates are formatted here as text, so the PDF does not depend
 * on any particular cell type in report_format_cell().
 */
function fund_report_pdf_shape(array $r): array {
    $money = function ($v) { return number_format((float) $v, 2); };

    $widths = [
        'contributions' => [13, 9, 18, 17, 25, 9, 14],
        'expenditure'   => [11, 18, 9, 14, 28, 9, 14],
        'summary'       => [28, 15, 15, 14, 15, 15],
    ][$r['tab']];

    $toColumns = function (array $cols, array $numeric, array $w) {
        $out = [];
        foreach ($cols as $i => $label) {
            $out[] = [
                'key' => 'c' . $i,
                'label' => $label,
                'w' => $w[$i] ?? 10,
                'align' => in_array($i, $numeric, true) ? 'R' : 'L',
                'type' => 'text',
            ];
        }
        return $out;
    };
    $toRows = function (array $rows, array $numeric) use ($money) {
        $out = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $i => $cell) {
                $line['c' . $i] = in_array($i, $numeric, true) ? $money($cell) : (string) $cell;
            }
            $out[] = $line;
        }
        return $out;
    };

    // Key figures shown above the table
    $summary = [];
    if ($r['tab'] === 'summary' && $r['footer'] && $r['rows']) {
        $f = $r['footer'];
        $summary = [
            ['Total Opening Balance', 'RWF ' . $money($f[1])],
            ['Total Contributions', 'RWF ' . $money($f[2])],
            ['Total Amount Used', 'RWF ' . $money($f[3])],
            ['Total Closing Balance', 'RWF ' . $money($f[4])],
            ['Total Available Now', 'RWF ' . $money($f[5])],
        ];
    } else {
        foreach ($r['fund_totals'] as $name => $amount) {
            $summary[] = [$name, 'RWF ' . $money($amount)];
        }
    }

    $totals = [];
    $totalsLabel = 'TOTAL';
    if ($r['footer'] && $r['rows']) {
        foreach ($r['numeric'] as $i) {
            $totals['c' . $i] = $money($r['footer'][$i]);
        }
        $label = (string) ($r['footer'][0] !== '' ? $r['footer'][0] : ($r['footer'][5] ?? 'Total'));
        $totalsLabel = strtoupper($label);
    }

    $notes = [
        'summary' => ['Closing Balance = Opening Balance + Contributions - Amount Used. Available Now is today\'s balance after expenses waiting for approval.'],
        'contributions' => ['Contributions are grouped by the month they belong to.'],
        'expenditure' => ['Reversals (money returned to a Fund after a deleted expense) are shown as negative amounts.'],
    ][$r['tab']];

    $shape = [
        'title' => $r['title'],
        'filters' => [
            ['Fund', $r['fund_label']],
            ['Period', date('M Y', strtotime($r['from'] . '-01')) . ' to ' . date('M Y', strtotime($r['to'] . '-01'))],
            ['Amounts in', 'RWF'],
        ],
        'summary' => $summary,
        'columns' => $toColumns($r['cols'], $r['numeric'], $widths),
        'rows' => $toRows($r['rows'], $r['numeric']),
        'totals' => $totals,
        'totals_label' => $totalsLabel,
        'notes' => $notes,
    ];

    // Monthly Net Profit Allocation table (shown only if report_pdf.php supports 'extra_tables').
    if (!empty($r['pivot'])) {
        $p = $r['pivot'];
        $shape['extra_tables'] = [[
            'title' => $p['title'],
            'columns' => $toColumns($p['cols'], $p['numeric'], [20, 16, 16, 16, 16, 16]),
            'rows' => $toRows($p['rows'], $p['numeric']),
        ]];
    }

    return $shape;
}