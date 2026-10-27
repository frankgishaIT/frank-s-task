<?php
/**
 * NEW FILE: includes/category_profit_queries.php
 * Task 6B: Sales Profitability by Category. Same report shape as the other reports (screen + PDF).
 *
 * Per sale line, from the recorded sales (never "revenue = profit"):
 *   revenue = line total minus its share of the sale's discount
 *   cost    = products: quantity x units per pack x the buying price recorded at the sale (else the product's)
 *             services: the service-cost share of the profit rule (SALES_SERVICE_PROFIT_RATE, 80% profit)
 *   profit  = revenue - cost;  margin = profit / revenue
 * Cancelled sales are left out (a cancellation reverses the whole sale). Unpaid credit sales count: the sale was made.
 */
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/category_helpers.php';
if (is_file(__DIR__ . '/profit_rules.php')) { require_once __DIR__ . '/profit_rules.php'; }

/** The period: a specific day, month, year, or a custom range. Returns [start, end, label, error]. */
function category_profit_period(array $req): array
{
    $mode = $req['mode'] ?? 'month';
    $bad = function ($msg) { return [null, null, null, $msg]; };
    if ($mode === 'day') {
        $d = DateTime::createFromFormat('Y-m-d', (string) ($req['day'] ?? date('Y-m-d')));
        if (!$d) { return $bad('Choose a valid day.'); }
        return [$d->format('Y-m-d'), $d->format('Y-m-d'), 'Day: ' . $d->format('d M Y'), null];
    }
    if ($mode === 'year') {
        $y = (int) ($req['year'] ?? date('Y'));
        if ($y < 2000 || $y > 2100) { return $bad('Choose a valid year.'); }
        return ["$y-01-01", "$y-12-31", 'Year: ' . $y, null];
    }
    if ($mode === 'custom') {
        $f = DateTime::createFromFormat('Y-m-d', (string) ($req['from'] ?? ''));
        $t = DateTime::createFromFormat('Y-m-d', (string) ($req['to'] ?? ''));
        if (!$f || !$t) { return $bad('Choose both dates of the custom range.'); }
        if ($f > $t) { return $bad('The start date must be on or before the end date.'); }
        return [$f->format('Y-m-d'), $t->format('Y-m-d'), 'From ' . $f->format('d M Y') . ' to ' . $t->format('d M Y'), null];
    }
    $m = DateTime::createFromFormat('Y-m-d', (string) ($req['month'] ?? date('Y-m')) . '-01');
    if (!$m) { return $bad('Choose a valid month.'); }
    return [$m->format('Y-m-01'), $m->format('Y-m-t'), 'Month: ' . $m->format('F Y'), null];
}

function category_profit_build(mysqli $conn, array $req): array
{
    $title = 'Sales Profitability by Category';
    [$start, $end, $label, $err] = category_profit_period($req);
    if ($err) { return ['error' => $err, 'title' => $title, 'type' => 'category_profit']; }
    category_ensure($conn); // makes sure products.category exists

    $rate = defined('SALES_SERVICE_PROFIT_RATE') ? (float) SALES_SERVICE_PROFIT_RATE : 0.8;
    $isService = "(si.item_type = 'Service' OR p.item_type = 'Service')";
    $net = "(si.line_total * CASE WHEN s.subtotal > 0 THEN (1 - COALESCE(s.discount_amount, 0) / s.subtotal) ELSE 1 END)";
    $cost = "CASE WHEN $isService THEN $net * " . (1 - $rate) . "
                  ELSE si.quantity * GREATEST(COALESCE(si.pack_size, 1), 1) * COALESCE(si.unit_cost, p.buying_price, 0) END";

    $s = mysqli_prepare($conn, "SELECT COALESCE(NULLIF(TRIM(p.category), ''), 'Uncategorised') AS category,
            CASE WHEN $isService THEN 'Service' ELSE 'Product' END AS kind,
            COUNT(DISTINCT s.id) AS sales_count,
            SUM(si.quantity) AS qty,
            SUM($net) AS revenue,
            SUM($cost) AS cost
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        LEFT JOIN products p ON p.id = si.product_id
        WHERE s.status <> 'Cancelled' AND s.sale_date BETWEEN ? AND ?
        GROUP BY category, kind");
    mysqli_stmt_bind_param($s, 'ss', $start, $end);
    mysqli_stmt_execute($s);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);

    $tRev = 0.0; $tCost = 0.0;
    foreach ($rows as &$r) {
        $r['revenue'] = round((float) $r['revenue'], 2);
        $r['cost'] = round((float) $r['cost'], 2);
        $r['profit'] = round($r['revenue'] - $r['cost'], 2);
        $r['margin'] = $r['revenue'] > 0 ? round(100 * $r['profit'] / $r['revenue'], 1) . '%' : '—';
        $tRev += $r['revenue']; $tCost += $r['cost'];
    }
    unset($r);
    usort($rows, function ($a, $b) { return $b['profit'] <=> $a['profit']; });
    $tProfit = round($tRev - $tCost, 2);

    $summary = [
        ['Total Sales Revenue', report_money($tRev)],
        ['Cost of Goods / Service Cost', report_money($tCost)],
        ['Total Profit', report_money($tProfit)],
        ['Profit Margin', $tRev > 0 ? round(100 * $tProfit / $tRev, 1) . '%' : '—'],
    ];
    if ($rows) {
        $top = $rows[0]; $low = $rows[count($rows) - 1];
        $summary[] = ['Highest profit', $top['category'] . ' (' . report_money($top['profit']) . ')'];
        $summary[] = ['Lowest profit', $low['category'] . ' (' . report_money($low['profit']) . ')'];
    }

    return [
        'error' => null, 'type' => 'category_profit', 'title' => $title,
        'filters' => [['Period', $label]],
        'summary' => $summary,
        'columns' => [
            ['key' => 'category', 'label' => 'Category', 'w' => 20],
            ['key' => 'kind', 'label' => 'Type', 'w' => 8],
            ['key' => 'sales_count', 'label' => 'Sales', 'w' => 7, 'align' => 'R', 'type' => 'int'],
            ['key' => 'revenue', 'label' => 'Sales Revenue (RWF)', 'w' => 14, 'align' => 'R', 'type' => 'money'],
            ['key' => 'cost', 'label' => 'Cost of Goods / Service (RWF)', 'w' => 15, 'align' => 'R', 'type' => 'money'],
            ['key' => 'profit', 'label' => 'Profit (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
            ['key' => 'margin', 'label' => 'Margin', 'w' => 8, 'align' => 'R'],
        ],
        'rows' => $rows,
        'totals' => ['revenue' => round($tRev, 2), 'cost' => round($tCost, 2), 'profit' => $tProfit],
        'totals_label' => 'TOTAL',
        'footer_summary' => [],
        'notes' => [
            'Revenue is each line after its share of the sale\'s discount. Product cost = the buying price recorded at the sale (per base unit) x quantity; '
            . 'service cost = ' . round(100 * (1 - $rate)) . '% of its revenue (profit rule). Cancelled sales are excluded; unpaid credit sales are included. '
            . 'Products and services without a category are shown as "Uncategorised" (an Admin can set categories in Inventory).',
        ],
    ];
}