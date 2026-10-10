<?php
// Builds the Fund reports (summary / contributions / expenditure / capital stock & assets).
// Used by modules/transactions/fund_reports.php (screen + CSV) and
// modules/transactions/fund_report_pdf.php (PDF), so every format always
// shows exactly the same numbers.
// Save as: includes/fund_report_queries.php
//
// CHANGED (spec sections 13 and 14): every movement is put in ONE group by
// fund_movement_category_sql() (includes/fund_report_helpers.php), the same rule the fund
// cards and the Capital Fund Check use, so all of them always agree:
//   Contributions  profit allocations (sales, income, monthly), capital inflows, loans,
//                  asset sale proceeds, corrections, and their reversals
//   Amount Used    expenses and their reversals
//   Stock          stock received, cost of stock sold, opening stock, stock corrections
//   Assets         assets added, depreciation, revaluation, removal
// Before, a cancelled sale's reversal was shown as an "expense reversal", cost of stock sold and
// depreciation were counted as "Used", and stock or assets added were not counted at all, so the
// Closing Balance did not match the real balance.

require_once __DIR__ . '/fund_helpers.php';
require_once __DIR__ . '/fund_report_helpers.php';

function fund_report_titles(): array {
    return [
        'summary' => 'Fund Summary Report',
        'contributions' => 'Fund Contributions Report',
        'expenditure' => 'Fund Expenditure Report',
        'capital' => 'Capital Fund Stock & Assets Report', // NEW
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
 * NEW: a readable name for a movement. $d needs movement_type, orig_type (the type of the row a
 * REVERSAL reverses), ref_type, source_type and transaction_id.
 */
function fund_report_label(array $d): string {
    $type = $d['movement_type'];
    $ref = $d['ref_type'] ?? null;

    if ($type === 'REVERSAL') {
        if (empty($d['orig_type'])) {
            return !empty($d['transaction_id']) ? 'Reversal: deleted expense' : 'Reversal';
        }
        return 'Reversal: ' . fund_report_label(array_merge($d, ['movement_type' => $d['orig_type'], 'orig_type' => null]));
    }

    switch ($type) {
        case 'ALLOCATION':
            if ($ref === 'SALE') { return 'Sale profit allocation'; }
            if ($ref === 'TX_INCOME') { return 'Income allocation'; }
            return 'Net Profit Allocation (monthly)';
        case 'CAPITAL_INFLOW':
            if ($ref === 'PO') { return 'Stock received'; }
            if ($ref === 'ASSET_SALE') { return 'Asset sale proceeds'; }
            return $d['source_type'] ?: 'Capital Inflow';
        case 'ADJUSTMENT':
            if ($ref === 'ASSET') { return 'Asset revaluation'; }
            if ($ref === 'STOCK_RECONCILE') { return 'Stock correction'; }
            return 'Correction: ' . ($d['source_type'] ?: 'Capital');
        case 'EXPENSE':       return 'Expense';
        case 'STOCK_IN':      return 'Stock received';
        case 'STOCK_OUT':     return 'Cost of stock sold';
        case 'OPENING_BALANCE': return $ref === 'STOCK_OPENING' ? 'Opening stock' : 'Opening balance';
        case 'ASSET_IN':      return 'Asset added';
        case 'ASSET_OUT':     return 'Asset removed';
        case 'DEPRECIATION':  return 'Depreciation';
    }
    return $type;
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

    $cat = fund_movement_category_sql();
    $signed = "(CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END)";
    $cols = [];
    $numeric = [];
    $rows = [];
    $footer = null;
    $fundTotals = [];
    $pivot = null;

    // Movement rows of one group in the period (used by the three detail tabs).
    $movementRows = function (array $groups, bool $useFundFilter = true) use ($conn, $cat, $from, $to, $fundId) {
        $in = "'" . implode("','", $groups) . "'";
        $types = 'ss';
        $params = [$from, $to];
        $filter = ($useFundFilter && $fundId) ? ' AND m.fund_id = ?' : '';
        if ($filter) { $types .= 'i'; $params[] = $fundId; }
        return fund_report_query($conn, "SELECT m.id, m.created_at, m.period, f.name AS fund, m.movement_type, o.movement_type AS orig_type,
                m.direction, m.source_type, m.ref_type, m.transaction_id, m.amount, $cat AS grp,
                COALESCE(t.transaction_date, DATE(m.created_at)) AS dt, t.expense_category,
                COALESCE(NULLIF(t.description, ''), m.description) AS descr, u.names AS who
            FROM fund_movements m
            JOIN funds f ON f.id = m.fund_id
            LEFT JOIN fund_movements o ON o.id = m.reverses_movement_id
            LEFT JOIN transactions t ON t.id = m.transaction_id
            LEFT JOIN users u ON u.id = m.created_by
            WHERE m.period BETWEEN ? AND ? $filter
            HAVING grp IN ($in)
            ORDER BY dt DESC, m.id DESC", $types, $params);
    };

    if ($tab === 'contributions') {
        $cols = ['Date Added', 'Period', 'Fund', 'Source', 'Description', 'Added By', 'Amount (RWF)'];
        $numeric = [6];
        $total = 0.0;
        foreach ($movementRows(['contrib']) as $d) {
            // IN adds to the fund; OUT (a reversal of a cancelled sale, a correction downwards,
            // or a loss-making sale) is shown as a negative contribution.
            $amount = $d['direction'] === 'OUT' ? -(float) $d['amount'] : (float) $d['amount'];
            $total += $amount;
            $fundTotals[$d['fund']] = ($fundTotals[$d['fund']] ?? 0) + $amount;
            $rows[] = [
                date('d M Y', strtotime($d['created_at'])),
                $d['period'],
                $d['fund'],
                fund_report_label($d),
                $d['descr'] ?? '',
                $d['who'] ?? '—',
                $amount,
            ];
        }
        $footer = ['', '', '', '', '', 'Total Contributions', $total];

        // Profit allocated per month: one line per month, one column per fund.
        // CHANGED: includes the automatic allocations (sales and income) and subtracts their
        // reversals (cancelled sales, deleted income), not only the monthly allocations.
        $order = ['FUTURE_PLANS', 'EMERGENCY', 'TEAM_GROWTH', 'OPERATING'];
        $names = [];
        foreach (funds_all($conn) as $f) { $names[$f['code']] = $f['name']; }
        $pv = fund_report_query($conn, "SELECT m.period, f.code, SUM($signed) AS amt
            FROM fund_movements m
            JOIN funds f ON f.id = m.fund_id
            LEFT JOIN fund_movements o ON o.id = m.reverses_movement_id
            WHERE (m.movement_type = 'ALLOCATION' OR (m.movement_type = 'REVERSAL' AND o.movement_type = 'ALLOCATION'))
              AND m.period BETWEEN ? AND ?
            GROUP BY m.period, f.code ORDER BY m.period DESC", 'ss', [$from, $to]);
        $byMonth = [];
        foreach ($pv as $p) { $byMonth[$p['period']][$p['code']] = (float) $p['amt']; }

        $pCols = ['Month'];
        foreach ($order as $code) { $pCols[] = $names[$code] ?? $code; }
        $pCols[] = 'Total (Profit Allocated)';
        $pRows = [];
        foreach ($byMonth as $month => $vals) {
            $line = [date('F Y', strtotime($month . '-01'))];
            foreach ($order as $code) { $line[] = $vals[$code] ?? 0.0; }
            $line[] = array_sum($vals);
            $pRows[] = $line;
        }
        $pivot = ['title' => 'Profit Allocated per Month', 'cols' => $pCols, 'numeric' => [1, 2, 3, 4, 5], 'rows' => $pRows];
    } elseif ($tab === 'expenditure') {
        $cols = ['Date', 'Fund', 'Type', 'Category', 'Description', 'Posted By', 'Amount (RWF)'];
        $numeric = [6];
        $total = 0.0;
        foreach ($movementRows(['used']) as $d) {
            // Expense = money out (+). Reversal of an expense = money returned to the fund (-).
            $signedAmount = $d['direction'] === 'OUT' ? (float) $d['amount'] : -(float) $d['amount'];
            $total += $signedAmount;
            $fundTotals[$d['fund']] = ($fundTotals[$d['fund']] ?? 0) + $signedAmount;
            $rows[] = [
                date('d M Y', strtotime($d['dt'])),
                $d['fund'],
                $d['movement_type'] === 'REVERSAL' ? 'Reversal' : 'Expense',
                $d['expense_category'] ?: '—',
                $d['descr'] ?? '',
                $d['who'] ?? '—',
                $signedAmount,
            ];
        }
        $footer = ['', '', '', '', '', 'Net Amount Used', $total];
    } elseif ($tab === 'capital') {
        // NEW (spec section 13): every change in the value of stock and assets held in the
        // RM Capital Fund (the fund filter does not apply: only the Capital Fund has these).
        $cols = ['Date', 'Group', 'Type', 'Description', 'Posted By', 'Amount (RWF)'];
        $numeric = [5];
        $total = 0.0;
        $fundId = 0;
        $fundLabel = 'RM Capital Fund';
        foreach ($movementRows(['stock', 'assets'], false) as $d) {
            $amount = $d['direction'] === 'IN' ? (float) $d['amount'] : -(float) $d['amount'];
            $group = $d['grp'] === 'stock' ? 'Stock' : 'Assets';
            $total += $amount;
            $fundTotals[$group . ' (net change)'] = ($fundTotals[$group . ' (net change)'] ?? 0) + $amount;
            $rows[] = [
                date('d M Y', strtotime($d['created_at'])),
                $group,
                fund_report_label($d),
                $d['descr'] ?? '',
                $d['who'] ?? '—',
                $amount,
            ];
        }
        $footer = ['', '', '', '', 'Net Change', $total];
    } else {
        $types = 'sssssssss';
        $params = [$from, $from, $to, $from, $to, $from, $to, $from, $to];
        $where = 'f.is_active = 1';
        if ($fundId) { $where .= ' AND f.id = ?'; $types .= 'i'; $params[] = $fundId; }
        $data = fund_report_query($conn, "SELECT f.id, f.name,
                COALESCE(SUM(CASE WHEN m.period < ? THEN $signed END), 0) AS opening,
                COALESCE(SUM(CASE WHEN m.period BETWEEN ? AND ? AND $cat = 'contrib' THEN $signed END), 0) AS contrib,
                COALESCE(SUM(CASE WHEN m.period BETWEEN ? AND ? AND $cat = 'used' THEN -$signed END), 0) AS used,
                COALESCE(SUM(CASE WHEN m.period BETWEEN ? AND ? AND $cat = 'stock' THEN $signed END), 0) AS stock,
                COALESCE(SUM(CASE WHEN m.period BETWEEN ? AND ? AND $cat = 'assets' THEN $signed END), 0) AS assets
            FROM funds f
            LEFT JOIN fund_movements m ON m.fund_id = f.id
            LEFT JOIN fund_movements o ON o.id = m.reverses_movement_id
            WHERE $where
            GROUP BY f.id, f.name
            ORDER BY FIELD(f.code, 'FUTURE_PLANS', 'EMERGENCY', 'TEAM_GROWTH', 'OPERATING', 'CAPITAL')", $types, $params);

        // CHANGED: two more columns, so the Capital Fund's stock and asset changes are shown and
        // Closing Balance = Opening + Contributions - Used + Stock change + Asset change.
        $cols = ['Fund', 'Opening Balance', 'Contributions', 'Amount Used', 'Stock Change', 'Asset Change', 'Closing Balance', 'Available Now'];
        $numeric = [1, 2, 3, 4, 5, 6, 7];
        $sum = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
        foreach ($data as $d) {
            $opening = (float) $d['opening'];
            $contrib = (float) $d['contrib'];
            $used = (float) $d['used'];
            $stock = (float) $d['stock'];
            $assets = (float) $d['assets'];
            $closing = $opening + $contrib - $used + $stock + $assets;
            $available = fund_available($conn, (int) $d['id']);
            $rows[] = [$d['name'], $opening, $contrib, $used, $stock, $assets, $closing, $available];
            foreach ([$opening, $contrib, $used, $stock, $assets, $closing, $available] as $i => $v) { $sum[$i] += $v; }
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

    // CHANGED: summary has 8 columns now; widths for the new capital tab.
    $widths = [
        'contributions' => [13, 9, 18, 17, 25, 9, 14],
        'expenditure'   => [11, 18, 9, 14, 28, 9, 14],
        'capital'       => [12, 9, 20, 36, 10, 13],
        'summary'       => [19, 11, 11, 11, 11, 11, 13, 13],
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
            ['Stock Value Change', 'RWF ' . $money($f[4])],
            ['Asset Value Change', 'RWF ' . $money($f[5])],
            ['Total Closing Balance', 'RWF ' . $money($f[6])],
            ['Total Available Now', 'RWF ' . $money($f[7])],
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
        // CHANGED: the label is the last text cell of the footer (works for every tab).
        $label = 'Total';
        foreach ($r['footer'] as $i => $cell) {
            if (!in_array($i, $r['numeric'], true) && is_string($cell) && $cell !== '') { $label = $cell; }
        }
        $totalsLabel = strtoupper($label);
    }

    $notes = [
        'summary' => ['Closing Balance = Opening Balance + Contributions - Amount Used + Stock Change + Asset Change. Only the RM Capital Fund holds stock and assets. Available Now is today\'s balance after expenses waiting for approval.'],
        'contributions' => ['Contributions are grouped by the month they were posted. Negative lines are reversals (cancelled sales, deleted income) or downward corrections.'],
        'expenditure' => ['Reversals (money returned to a Fund after a deleted or corrected expense) are shown as negative amounts.'],
        'capital' => ['Positive amounts add value to the RM Capital Fund (stock received, assets added); negative amounts remove it (cost of stock sold, depreciation, assets removed).'],
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

    // Profit Allocated per Month table (shown only if report_pdf.php supports 'extra_tables').
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