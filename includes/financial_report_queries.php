<?php
/**
 * NEW FILE: includes/financial_report_queries.php
 * Financial Reports module: the calculation engine. Nothing is typed in by hand; every figure is
 * read from the existing modules, so the reports change by themselves whenever a sale, payment,
 * purchase order, re-stock, payroll, loan, asset, expense or capital entry is added, edited,
 * cancelled or deleted.
 *
 * Accounting treatment (the same everywhere in these reports):
 *  REVENUE        Sales that are Paid, Partially Paid or Credit (accrual: on the sale date), net of
 *                 discount. Pending-approval and Cancelled sales are not revenue.
 *  COST OF SALES  Products: buying price frozen at the sale (sale_items.unit_cost).
 *                 Services: the system's service rule (profit_rules.php), the same as the Sales reports.
 *  OTHER INCOME   Manual Income transactions (approved, not created by a sale) and gains on assets sold.
 *  EXPENSES       Payroll by pay month (Paid and Draft; Draft is still owed), manual expenses by
 *                 category (approved only), depreciation, losses on assets removed, stock corrections,
 *                 goods lost on sales cancelled without return. Purchases of stock are NOT expenses
 *                 (they become inventory and are expensed as cost of goods when sold).
 *  FINANCE COSTS  Loan interest paid (the principal repaid is not an expense; it reduces the loan).
 *  BALANCE SHEET  Cash = all money received - all money paid out; Receivables = what customers owe;
 *                 Inventory = stock at buying price; Goods ordered = paid purchase orders not received;
 *                 Fixed assets at net book value; Liabilities = loan principal outstanding and salaries
 *                 not paid yet; Equity = capital put in + accumulated profit + opening balances.
 */
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/sales_report_queries.php';   // sales_report_fetch()
require_once __DIR__ . '/profit_rules.php';           // sales_report_line_net_sql(), sales_report_line_cost_sql()
require_once __DIR__ . '/fund_report_helpers.php';    // fund_movement_category_sql(), capital_actual_stock_value()

const FIN_VALID_SALES = "sales.status IN ('Paid', 'Partially Paid', 'Credit')";

function fin_report_types(): array
{
    return [
        'pnl'      => 'Profit & Loss Statement',
        'balance'  => 'Balance Sheet',
        'income'   => 'Income Statement',
        'expenses' => 'Expense Analysis',
        'gross'    => 'Gross Profit Report',
        'net'      => 'Net Profit Report',
        'margin'   => 'Profit Margin Report',
        'position' => 'Financial Position Summary',
    ];
}

// Reports shown "as of" a date (the others cover a period).
function fin_report_is_as_of(string $type): bool { return in_array($type, ['balance', 'position'], true); }

/* =========================================================================================
 * Small helpers
 * ========================================================================================= */

/** " AND col >= ? AND col <= ?" for an optional start and end date. */
function fin_range(string $col, ?string $start, ?string $end): array
{
    $sql = ''; $t = ''; $p = [];
    if ($start !== null) { $sql .= " AND $col >= ?"; $t .= 's'; $p[] = $start; }
    if ($end !== null)   { $sql .= " AND $col <= ?"; $t .= 's'; $p[] = $end; }
    return [$sql, $t, $p];
}

/** First column of the first row, as a number (0 when the table does not exist yet). */
function fin_value(mysqli $conn, string $sql, string $types = '', array $params = []): float
{
    try {
        $rows = sales_report_fetch($conn, $sql, $types, $params);
    } catch (mysqli_sql_exception $e) {
        error_log('financial report query failed: ' . $e->getMessage());
        return 0.0;
    }
    return $rows ? round((float) reset($rows[0]), 2) : 0.0;
}

function fin_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    try {
        return sales_report_fetch($conn, $sql, $types, $params);
    } catch (mysqli_sql_exception $e) {
        error_log('financial report query failed: ' . $e->getMessage());
        return [];
    }
}

function fin_pct(float $part, float $whole): string
{
    return abs($whole) > 0.009 ? number_format(100 * $part / $whole, 1) . '%' : '—';
}

function fin_change(float $now, float $before): string
{
    if (abs($before) < 0.01) { return abs($now) < 0.01 ? '—' : 'new'; }
    $c = 100 * ($now - $before) / abs($before);
    return ($c > 0 ? '+' : '') . number_format($c, 1) . '%';
}

function fin_service_cost_label(): string
{
    $rate = defined('SALES_SERVICE_PROFIT_RATE') ? (float) SALES_SERVICE_PROFIT_RATE : 0.8;
    return 'Cost of services (' . round((1 - $rate) * 100) . '% of service sales, system rule)';
}

/** The period of the same length just before [start, end]. */
function fin_previous_period(string $start, string $end): array
{
    $s = new DateTimeImmutable($start);
    $e = new DateTimeImmutable($end);
    $days = (int) $s->diff($e)->days + 1;
    $pEnd = $s->modify('-1 day');
    $pStart = $pEnd->modify('-' . ($days - 1) . ' days');
    return [$pStart->format('Y-m-d'), $pEnd->format('Y-m-d')];
}

/** Months between two dates: [['Y-m', start, end], ...] clamped to the range. */
function fin_months(string $start, string $end): array
{
    $out = [];
    $cur = new DateTimeImmutable(substr($start, 0, 7) . '-01');
    $last = new DateTimeImmutable($end);
    while ($cur <= $last) {
        $mStart = max($cur->format('Y-m-d'), $start);
        $mEnd = min($cur->format('Y-m-t'), $end);
        $out[] = [$cur->format('M Y'), $mStart, $mEnd];
        $cur = $cur->modify('first day of next month');
    }
    return $out;
}

/* =========================================================================================
 * Building blocks, each read from its own module
 * ========================================================================================= */

/** Sales: revenue (net of discount), gross line totals, discounts and cost of sales. */
function fin_sales(mysqli $conn, ?string $start, ?string $end): array
{
    [$r, $t, $p] = fin_range('sales.sale_date', $start, $end);
    $net = sales_report_line_net_sql('sales', 'sale_items');
    $cost = sales_report_line_cost_sql('sales', 'sale_items', 'products');
    $rows = fin_rows($conn, "SELECT
            COALESCE(SUM(CASE WHEN sale_items.item_type = 'Product' THEN $net ELSE 0 END), 0) AS product_net,
            COALESCE(SUM(CASE WHEN sale_items.item_type = 'Service' THEN $net ELSE 0 END), 0) AS service_net,
            COALESCE(SUM(CASE WHEN sale_items.item_type = 'Product' THEN $cost ELSE 0 END), 0) AS product_cost,
            COALESCE(SUM(CASE WHEN sale_items.item_type = 'Service' THEN $cost ELSE 0 END), 0) AS service_cost,
            COALESCE(SUM(CASE WHEN sale_items.item_type = 'Product' THEN sale_items.line_total ELSE 0 END), 0) AS product_gross,
            COALESCE(SUM(CASE WHEN sale_items.item_type = 'Service' THEN sale_items.line_total ELSE 0 END), 0) AS service_gross
        FROM sale_items JOIN sales ON sale_items.sale_id = sales.id
        LEFT JOIN products ON sale_items.product_id = products.id
        WHERE " . FIN_VALID_SALES . $r, $t, $p);
    $h = fin_rows($conn, "SELECT COUNT(*) AS n, COALESCE(SUM(discount_amount), 0) AS discounts,
            COALESCE(SUM(total_amount), 0) AS total, COALESCE(SUM(amount_paid), 0) AS paid
        FROM sales WHERE " . FIN_VALID_SALES . $r, $t, $p);
    $x = $rows[0] ?? [];
    $y = $h[0] ?? [];
    return [
        'product_net' => round((float) ($x['product_net'] ?? 0), 2),
        'service_net' => round((float) ($x['service_net'] ?? 0), 2),
        'product_cost' => round((float) ($x['product_cost'] ?? 0), 2),
        'service_cost' => round((float) ($x['service_cost'] ?? 0), 2),
        'product_gross' => round((float) ($x['product_gross'] ?? 0), 2),
        'service_gross' => round((float) ($x['service_gross'] ?? 0), 2),
        'count' => (int) ($y['n'] ?? 0),
        'discounts' => round((float) ($y['discounts'] ?? 0), 2),
        'total' => round((float) ($y['total'] ?? 0), 2),
        'paid' => round((float) ($y['paid'] ?? 0), 2),
    ];
}

/** Manual Income transactions (approved, not created by a sale), by category. */
function fin_other_income(mysqli $conn, ?string $start, ?string $end): array
{
    [$r, $t, $p] = fin_range('t.transaction_date', $start, $end);
    $rows = fin_rows($conn, "SELECT COALESCE(NULLIF(t.category, ''), 'Other') AS cat, SUM(t.amount) AS amount
        FROM transactions t LEFT JOIN sales s ON s.transaction_id = t.id
        WHERE t.transaction_type = 'Income' AND t.status = 'approved' AND t.is_automatic = 0 AND s.id IS NULL" . $r . "
        GROUP BY cat", $t, $p);
    $out = [];
    foreach ($rows as $row) { $out['Other income — ' . $row['cat']] = round((float) $row['amount'], 2); }
    return $out;
}

/** NEW: overdue charges on credit invoices, by the day they apply (charges minus waivers). */
function fin_overdue_charges(mysqli $conn, ?string $start, ?string $end): float
{
    [$r1, $t1, $p1] = fin_range('charge_date', $start, $end);
    [$r2, $t2, $p2] = fin_range('DATE(created_at)', $start, $end);
    $charged = fin_value($conn, "SELECT COALESCE(SUM(amount), 0) FROM credit_ledger WHERE entry_type = 'CHARGE'" . $r1, $t1, $p1);
    $waived = fin_value($conn, "SELECT COALESCE(SUM(amount), 0) FROM credit_ledger WHERE entry_type = 'WAIVER'" . $r2, $t2, $p2);
    return round($charged - $waived, 2);
}

/** Gains and losses on assets sold / stolen / damaged / disposed (Remove Asset page). */
function fin_asset_results(mysqli $conn, ?string $start, ?string $end): array
{
    [$r, $t, $p] = fin_range('disposal_date', $start, $end);
    $rows = fin_rows($conn, "SELECT COALESCE(SUM(CASE WHEN gain_loss > 0 THEN gain_loss ELSE 0 END), 0) AS gains,
            COALESCE(SUM(CASE WHEN gain_loss < 0 THEN -gain_loss ELSE 0 END), 0) AS losses
        FROM asset_disposals WHERE 1 = 1" . $r, $t, $p);
    return ['gains' => round((float) ($rows[0]['gains'] ?? 0), 2), 'losses' => round((float) ($rows[0]['losses'] ?? 0), 2)];
}

/** Payroll by pay month (Paid and Draft). */
function fin_payroll(mysqli $conn, ?string $start, ?string $end): array
{
    [$r, $t, $p] = fin_range('pay_period', $start !== null ? date('Y-m-01', strtotime($start)) : null, $end);
    $rows = fin_rows($conn, "SELECT COALESCE(SUM(net_salary), 0) AS total,
            COALESCE(SUM(CASE WHEN status = 'Draft' THEN net_salary ELSE 0 END), 0) AS draft
        FROM payroll WHERE 1 = 1" . $r, $t, $p);
    return ['total' => round((float) ($rows[0]['total'] ?? 0), 2), 'draft' => round((float) ($rows[0]['draft'] ?? 0), 2)];
}

/** Manual expenses (approved), by expense category. Automatic ones (purchases, payroll, loans, assets) are excluded. */
function fin_manual_expenses(mysqli $conn, ?string $start, ?string $end): array
{
    [$r, $t, $p] = fin_range('t.transaction_date', $start, $end);
    $rows = fin_rows($conn, "SELECT COALESCE(NULLIF(t.expense_category, ''), 'Uncategorised expenses') AS cat, SUM(t.amount) AS amount
        FROM transactions t
        WHERE t.transaction_type = 'Expense' AND t.status = 'approved' AND t.is_automatic = 0" . $r . "
        GROUP BY cat ORDER BY amount DESC", $t, $p);
    $out = [];
    foreach ($rows as $row) { $out[$row['cat']] = round((float) $row['amount'], 2); }
    return $out;
}

/** NEW: Loyalty rewards paid to customers (Operating Fund expenses, category 'Loyalty Reward'). */
function fin_rewards_paid(mysqli $conn, ?string $start, ?string $end): float
{
    [$r, $t, $p] = fin_range('transaction_date', $start, $end);
    return fin_value($conn, "SELECT COALESCE(SUM(amount), 0) FROM transactions
        WHERE transaction_type = 'Expense' AND status = 'approved' AND category = 'Loyalty Reward'" . $r, $t, $p);
}

function fin_depreciation(mysqli $conn, ?string $start, ?string $end): float
{
    [$r, $t, $p] = fin_range('valuation_date', $start, $end);
    return fin_value($conn, "SELECT COALESCE(SUM(daily_depreciation), 0) FROM asset_valuation_history WHERE 1 = 1" . $r, $t, $p);
}

function fin_interest(mysqli $conn, ?string $start, ?string $end): float
{
    [$r, $t, $p] = fin_range('payment_date', $start, $end);
    return fin_value($conn, "SELECT COALESCE(SUM(interest_portion), 0) FROM loan_payments WHERE 1 = 1" . $r, $t, $p);
}

/** Stock corrections posted on the Capital Fund Check (positive = stock lost). */
function fin_stock_corrections(mysqli $conn, ?string $start, ?string $end): float
{
    [$r, $t, $p] = fin_range('DATE(m.created_at)', $start, $end);
    $v = -fin_value($conn, "SELECT COALESCE(SUM(CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END), 0)
        FROM fund_movements m WHERE m.ref_type = 'STOCK_RECONCILE'" . $r, $t, $p);
    return abs($v) < 0.005 ? 0.0 : $v; // never "-0.00"
}

/** Cost of goods that left on sales later cancelled WITHOUT returning them to stock. */
function fin_cancelled_sale_losses(mysqli $conn, ?string $start, ?string $end): float
{
    [$r, $t, $p] = fin_range('DATE(s.cancelled_at)', $start, $end);
    return fin_value($conn, "SELECT COALESCE(SUM(m.amount), 0)
        FROM fund_movements m JOIN sales s ON s.id = m.ref_id
        LEFT JOIN fund_movements rv ON rv.reverses_movement_id = m.id
        WHERE m.ref_type = 'SALE' AND m.movement_type = 'STOCK_OUT' AND rv.id IS NULL AND s.status = 'Cancelled'" . $r, $t, $p);
}

/* =========================================================================================
 * Profit & Loss: the one calculation every period report uses
 * ========================================================================================= */

function fin_pnl(mysqli $conn, ?string $start, ?string $end): array
{
    $s = fin_sales($conn, $start, $end);
    $other = fin_other_income($conn, $start, $end);
    $assets = fin_asset_results($conn, $start, $end);
    $payroll = fin_payroll($conn, $start, $end);
    $manual = fin_manual_expenses($conn, $start, $end);

    $revenue = round($s['product_net'] + $s['service_net'], 2);
    $cos = round($s['product_cost'] + $s['service_cost'], 2);
    $gross = round($revenue - $cos, 2);

    $otherIncome = $other;
    $charges = fin_overdue_charges($conn, $start, $end);
    if (abs($charges) > 0.009) { $otherIncome['Overdue credit charges'] = $charges; }
    if ($assets['gains'] > 0) { $otherIncome['Gain on assets sold'] = $assets['gains']; }
    $otherTotal = round(array_sum($otherIncome), 2);

    $opex = ['Salaries & wages (payroll)' => $payroll['total']] + $manual;
    $opex['Customer loyalty rewards'] = fin_rewards_paid($conn, $start, $end);
    $opex['Depreciation of assets'] = fin_depreciation($conn, $start, $end);
    $opex['Loss on assets removed'] = $assets['losses'];
    $opex['Stock corrections (stock lost)'] = fin_stock_corrections($conn, $start, $end);
    $opex['Goods lost on cancelled sales'] = fin_cancelled_sale_losses($conn, $start, $end);
    $opexTotal = round(array_sum($opex), 2);

    $operating = round($gross + $otherTotal - $opexTotal, 2);
    $interest = fin_interest($conn, $start, $end);
    $net = round($operating - $interest, 2);

    return [
        'sales' => $s,
        'revenue' => $revenue, 'product_revenue' => $s['product_net'], 'service_revenue' => $s['service_net'],
        'cos' => $cos, 'product_cost' => $s['product_cost'], 'service_cost' => $s['service_cost'],
        'gross' => $gross,
        'other_income' => $otherIncome, 'other_total' => $otherTotal,
        'opex' => $opex, 'opex_total' => $opexTotal, 'payroll_draft' => $payroll['draft'],
        'operating' => $operating,
        'interest' => $interest,
        'net' => $net,
    ];
}

/**
 * Fixed assets at net book value on a date: the last valuation on or before that date, for assets
 * acquired by then and not yet removed (sold, stolen, damaged, disposed) by then.
 * Falls back to the asset status when the asset_disposals table is not installed yet.
 */
function fin_fixed_assets(mysqli $conn, string $asOf): float
{
    $value = "COALESCE((SELECT h.value FROM asset_valuation_history h WHERE h.asset_id = a.id AND h.valuation_date <= ?
                        ORDER BY h.valuation_date DESC LIMIT 1), a.acquisition_value)";
    try {
        $rows = sales_report_fetch($conn, "SELECT COALESCE(SUM($value), 0) AS v FROM assets a
            LEFT JOIN asset_disposals x ON x.asset_id = a.id
            WHERE a.acquisition_date <= ? AND (x.id IS NULL OR x.disposal_date > ?)
              AND (x.id IS NOT NULL OR a.status IN ('Active', 'Under Maintenance'))", 'sss', [$asOf, $asOf, $asOf]);
    } catch (mysqli_sql_exception $e) {
        $rows = fin_rows($conn, "SELECT COALESCE(SUM($value), 0) AS v FROM assets a
            WHERE a.acquisition_date <= ? AND a.status IN ('Active', 'Under Maintenance')", 'ss', [$asOf, $asOf]);
    }
    return round((float) ($rows[0]['v'] ?? 0), 2);
}

/* =========================================================================================
 * Balance sheet figures as of a date
 * ========================================================================================= */

function fin_position(mysqli $conn, string $asOf): array
{
    $today = date('Y-m-d');
    [$rd, $td, $pd] = fin_range('sales.sale_date', null, $asOf);
    $cat = fund_movement_category_sql();
    $signed = "(CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END)";
    $capitalBase = "FROM fund_movements m LEFT JOIN fund_movements o ON o.id = m.reverses_movement_id
        JOIN funds f ON f.id = m.fund_id AND f.code = 'CAPITAL' WHERE DATE(m.created_at) <= ?";

    // --- Cash: everything received minus everything paid out, up to the date ---
    $salesCash = fin_value($conn, "SELECT COALESCE(SUM(amount_paid), 0) FROM sales WHERE " . FIN_VALID_SALES . $rd, $td, $pd);
    $manualIncome = fin_value($conn, "SELECT COALESCE(SUM(t.amount), 0) FROM transactions t LEFT JOIN sales s ON s.transaction_id = t.id
        WHERE t.transaction_type = 'Income' AND t.status = 'approved' AND t.is_automatic = 0 AND s.id IS NULL AND t.transaction_date <= ?", 's', [$asOf]);
    $capitalIn = fin_value($conn, "SELECT COALESCE(SUM($signed), 0) $capitalBase AND $cat = 'contrib'", 's', [$asOf]);
    $loanIn = fin_value($conn, "SELECT COALESCE(SUM($signed), 0) $capitalBase AND $cat = 'contrib'
        AND (m.ref_type = 'loan' OR m.source_type = 'Business Loan')", 's', [$asOf]);
    $assetSaleIn = fin_value($conn, "SELECT COALESCE(SUM($signed), 0) $capitalBase AND $cat = 'contrib' AND m.ref_type = 'ASSET_SALE'", 's', [$asOf]);
    $paidOut = fin_value($conn, "SELECT COALESCE(SUM(amount), 0) FROM transactions
        WHERE transaction_type = 'Expense' AND status = 'approved' AND category <> 'Asset Loss' AND transaction_date <= ?", 's', [$asOf]);
    // NEW: overdue charges collected (automatic Income "Overdue Charges").
    $chargesCash = fin_value($conn, "SELECT COALESCE(SUM(amount), 0) FROM transactions
        WHERE transaction_type = 'Income' AND status = 'approved' AND category = 'Overdue Charges' AND transaction_date <= ?", 's', [$asOf]);
    $manualIncome = round($manualIncome + $chargesCash, 2);
    $cash = round($salesCash + $manualIncome + $capitalIn - $paidOut, 2);

    // --- Receivables, goods ordered, inventory, fixed assets ---
    $receivables = fin_value($conn, "SELECT COALESCE(SUM(total_amount - amount_paid + charges_accrued - charges_paid), 0) FROM sales
        WHERE status IN ('Credit', 'Partially Paid') AND sale_date <= ?", 's', [$asOf]);
    $goodsOrdered = fin_value($conn, "SELECT COALESCE(SUM(total_amount), 0) FROM purchase_orders WHERE status = 'Ordered' AND order_date <= ?", 's', [$asOf]);
    if ($asOf >= $today) {
        $inventory = capital_actual_stock_value($conn);
    } else {
        $inventory = fin_value($conn, "SELECT COALESCE(SUM($signed), 0) $capitalBase AND $cat = 'stock'", 's', [$asOf]);
    }
    $fixedAssets = fin_fixed_assets($conn, $asOf);

    // --- Liabilities ---
    // CHANGED: for today, the loans' own totals are used (the same figures as the Loans page):
    // Fully Paid and Cancelled loans owe nothing; open loans owe the amount minus the principal repaid.
    // Before, the payment rows were added up, and a loan marked paid without a matching payment row
    // still showed as owed. For a past date the payment rows are the only record of WHEN each payment
    // was made, so they are used then (a loan already Fully Paid never shows more than it really owed).
    if ($asOf >= $today) {
        $loanPrincipal = fin_value($conn, "SELECT COALESCE(SUM(GREATEST(0, loan_amount - principal_repaid)), 0)
            FROM loans WHERE status IN ('Active', 'Defaulted') AND loan_start_date <= ?", 's', [$asOf]);
    } else {
        $loanPrincipal = fin_value($conn, "SELECT COALESCE(SUM(GREATEST(0, l.loan_amount - COALESCE(
                (SELECT SUM(lp.principal_portion) FROM loan_payments lp WHERE lp.loan_id = l.id AND lp.payment_date <= ?), 0))), 0)
            FROM loans l WHERE l.status <> 'Cancelled' AND l.loan_start_date <= ?", 'ss', [$asOf, $asOf]);
    }
    $loanCurrent = fin_value($conn, "SELECT COALESCE(SUM(GREATEST(0, s.principal_due - GREATEST(0, s.paid_amount - s.interest_due))), 0)
        FROM loan_schedule s JOIN loans l ON l.id = s.loan_id
        WHERE l.status IN ('Active', 'Defaulted') AND s.status <> 'Paid' AND s.due_date <= DATE_ADD(?, INTERVAL 12 MONTH)", 's', [$asOf]);
    $loanCurrent = min($loanCurrent, $loanPrincipal);
    $salariesPayable = fin_value($conn, "SELECT COALESCE(SUM(net_salary), 0) FROM payroll WHERE status = 'Draft' AND pay_period <= ?", 's', [$asOf]);

    // --- Equity ---
    $contributed = round($capitalIn - $loanIn - $assetSaleIn, 2);
    $retained = fin_pnl($conn, null, $asOf)['net'];

    $currentAssets = round($cash + $receivables + $inventory + $goodsOrdered, 2);
    $totalAssets = round($currentAssets + $fixedAssets, 2);
    $currentLiabilities = round($salariesPayable + $loanCurrent, 2);
    $totalLiabilities = round($salariesPayable + $loanPrincipal, 2);
    $netAssets = round($totalAssets - $totalLiabilities, 2);

    $funds = fin_rows($conn, "SELECT f.name, f.code, COALESCE(SUM(CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END), 0) AS balance
        FROM funds f LEFT JOIN fund_movements m ON m.fund_id = f.id AND DATE(m.created_at) <= ?
        WHERE f.is_active = 1 GROUP BY f.id, f.name, f.code
        ORDER BY FIELD(f.code, 'OPERATING', 'FUTURE_PLANS', 'EMERGENCY', 'TEAM_GROWTH', 'CAPITAL')", 's', [$asOf]);

    return [
        'as_of' => $asOf,
        'cash' => $cash, 'cash_parts' => ['sales' => $salesCash, 'income' => $manualIncome, 'capital' => $capitalIn, 'paid_out' => $paidOut],
        'receivables' => $receivables, 'inventory' => round($inventory, 2), 'goods_ordered' => $goodsOrdered,
        'current_assets' => $currentAssets, 'fixed_assets' => $fixedAssets, 'total_assets' => $totalAssets,
        'salaries_payable' => $salariesPayable, 'loan_current' => round($loanCurrent, 2),
        'loan_long' => round($loanPrincipal - $loanCurrent, 2), 'loan_principal' => $loanPrincipal,
        'current_liabilities' => $currentLiabilities, 'total_liabilities' => $totalLiabilities,
        'net_assets' => $netAssets,
        'contributed' => $contributed, 'retained' => $retained,
        'opening' => round($netAssets - $contributed - $retained, 2),
        'funds' => $funds,
    ];
}

/* =========================================================================================
 * Report builder
 * ========================================================================================= */

function fin_report_build(mysqli $conn, string $type, array $req): array
{
    $types = fin_report_types();
    $fail = function ($msg) use ($type, $types) {
        return ['error' => $msg, 'title' => $types[$type] ?? 'Financial Report', 'type' => $type];
    };
    if (!isset($types[$type])) {
        return $fail('Please choose a report.');
    }

    $report = [
        'error' => null, 'type' => $type, 'title' => $types[$type], 'filters' => [],
        'summary' => [], 'columns' => [], 'rows' => [], 'totals' => [], 'totals_label' => 'TOTAL',
        'footer_summary' => [], 'notes' => [],
    ];

    if (fin_report_is_as_of($type)) {
        $asOf = (string) ($req['as_of'] ?? date('Y-m-d'));
        $d = DateTime::createFromFormat('Y-m-d', $asOf);
        if (!$d || $d->format('Y-m-d') !== $asOf) {
            return $fail('Please choose a valid "As of" date.');
        }
        if ($asOf > date('Y-m-d')) {
            return $fail('The "As of" date cannot be in the future.');
        }
        $report['filters'][] = ['As of', date('d M Y', strtotime($asOf))];
        $pos = fin_position($conn, $asOf);
        return $type === 'balance' ? fin_report_balance($report, $pos) : fin_report_position($conn, $report, $pos);
    }

    $period = report_period_from_request($req);
    if ($period['error']) {
        return $fail($period['error']);
    }
    $report['filters'][] = ['Period', $period['label']];
    [$s, $e] = [$period['start'], $period['end']];

    switch ($type) {
        case 'pnl':      return fin_report_pnl($conn, $report, $s, $e);
        case 'income':   return fin_report_income($conn, $report, $s, $e);
        case 'expenses': return fin_report_expenses($conn, $report, $s, $e);
        case 'gross':    return fin_report_gross($conn, $report, $s, $e);
        case 'net':      return fin_report_monthly($conn, $report, $s, $e, false);
        case 'margin':   return fin_report_monthly($conn, $report, $s, $e, true);
    }
    return $fail('Please choose a report.');
}

/** A statement line. */
function fin_line(string $section, string $item, ?float $amount, ?float $prev = null, float $base = 0.0): array
{
    return [
        'section' => $section, 'item' => $item, 'amount' => $amount,
        'pct' => $amount === null ? '' : fin_pct($amount, $base),
        'prev' => $prev,
        'change' => ($amount === null || $prev === null) ? '' : fin_change($amount, $prev),
    ];
}

/* ---------------------------------------------------------------------------------------- */
// 1. Profit & Loss Statement (with the previous period of the same length for comparison)

function fin_report_pnl(mysqli $conn, array $report, string $s, string $e): array
{
    $c = fin_pnl($conn, $s, $e);
    [$ps, $pe] = fin_previous_period($s, $e);
    $p = fin_pnl($conn, $ps, $pe);
    $base = $c['revenue'];
    $L = function ($sec, $item, $a, $b) use ($base) { return fin_line($sec, $item, $a, $b, $base); };

    $rows = [];
    $rows[] = $L('Revenue', 'Product sales (net of discounts)', $c['product_revenue'], $p['product_revenue']);
    $rows[] = $L('Revenue', 'Service sales (net of discounts)', $c['service_revenue'], $p['service_revenue']);
    $rows[] = $L('', 'TOTAL REVENUE', $c['revenue'], $p['revenue']);
    $rows[] = $L('Cost of sales', 'Cost of goods sold (buying price)', $c['product_cost'], $p['product_cost']);
    $rows[] = $L('Cost of sales', fin_service_cost_label(), $c['service_cost'], $p['service_cost']);
    $rows[] = $L('', 'TOTAL COST OF SALES', $c['cos'], $p['cos']);
    $rows[] = $L('', 'GROSS PROFIT', $c['gross'], $p['gross']);
    foreach (array_unique(array_merge(array_keys($c['other_income']), array_keys($p['other_income']))) as $k) {
        $rows[] = $L('Other income', $k, $c['other_income'][$k] ?? 0.0, $p['other_income'][$k] ?? 0.0);
    }
    $rows[] = $L('', 'TOTAL OTHER INCOME', $c['other_total'], $p['other_total']);
    foreach (array_unique(array_merge(array_keys($c['opex']), array_keys($p['opex']))) as $k) {
        $a = $c['opex'][$k] ?? 0.0; $b = $p['opex'][$k] ?? 0.0;
        if (abs($a) < 0.01 && abs($b) < 0.01) { continue; }
        $rows[] = $L('Operating expenses', $k, $a, $b);
    }
    $rows[] = $L('', 'TOTAL OPERATING EXPENSES', $c['opex_total'], $p['opex_total']);
    $rows[] = $L('', 'OPERATING PROFIT', $c['operating'], $p['operating']);
    $rows[] = $L('Finance costs', 'Loan interest', $c['interest'], $p['interest']);
    $rows[] = $L('', $c['net'] >= 0 ? 'NET PROFIT' : 'NET LOSS', $c['net'], $p['net']);

    $report['rows'] = $rows;
    $report['summary'] = [
        ['Revenue', report_money($c['revenue'])],
        ['Gross Profit', report_money($c['gross'])],
        ['Operating Profit', report_money($c['operating'])],
        [$c['net'] >= 0 ? 'Net Profit' : 'Net Loss', report_money($c['net'])],
    ];
    $report['columns'] = [
        ['key' => 'section', 'label' => 'Section', 'w' => 14],
        ['key' => 'item', 'label' => 'Item', 'w' => 36],
        ['key' => 'amount', 'label' => 'This Period (RWF)', 'w' => 14, 'align' => 'R', 'type' => 'money'],
        ['key' => 'pct', 'label' => '% of Revenue', 'w' => 9, 'align' => 'R'],
        ['key' => 'prev', 'label' => 'Previous Period (RWF)', 'w' => 14, 'align' => 'R', 'type' => 'money'],
        ['key' => 'change', 'label' => 'Change', 'w' => 8, 'align' => 'R'],
    ];
    $report['filters'][] = ['Compared with', date('d M Y', strtotime($ps)) . ' - ' . date('d M Y', strtotime($pe))];
    $report['footer_summary'] = [
        ['Gross Margin', fin_pct($c['gross'], $c['revenue'])],
        ['Net Margin', fin_pct($c['net'], $c['revenue'])],
        ['Sales Counted', number_format($c['sales']['count'])],
        ['Payroll Not Paid Yet', report_money($c['payroll_draft'])],
    ];
    $report['notes'][] = 'Revenue is recognised on the sale date (paid and credit sales), net of discounts. Buying stock is not an expense: it is expensed as cost of goods when sold. Payroll is counted by pay month (Paid and Draft). Loan principal repaid is not an expense; only interest is.';
    return $report;
}

/* ---------------------------------------------------------------------------------------- */
// 2. Balance Sheet

function fin_report_balance(array $report, array $p): array
{
    $L = function ($sec, $item, $amount) { return ['section' => $sec, 'item' => $item, 'amount' => $amount]; };
    $rows = [
        $L('Assets', 'Cash & bank (money received - money paid out)', $p['cash']),
        $L('Assets', 'Accounts receivable (customers\' credit)', $p['receivables']),
        $L('Assets', 'Inventory (stock at buying price)', $p['inventory']),
        $L('Assets', 'Goods ordered, not yet received (paid)', $p['goods_ordered']),
        $L('', 'TOTAL CURRENT ASSETS', $p['current_assets']),
        $L('Assets', 'Property & equipment (net book value)', $p['fixed_assets']),
        $L('', 'TOTAL NON-CURRENT ASSETS', $p['fixed_assets']),
        $L('', 'TOTAL ASSETS', $p['total_assets']),
        $L('Liabilities', 'Salaries payable (payroll not paid yet)', $p['salaries_payable']),
        $L('Liabilities', 'Loan principal due within 12 months', $p['loan_current']),
        $L('', 'TOTAL CURRENT LIABILITIES', $p['current_liabilities']),
        $L('Liabilities', 'Loans (principal due after 12 months)', $p['loan_long']),
        $L('', 'TOTAL NON-CURRENT LIABILITIES', $p['loan_long']),
        $L('', 'TOTAL LIABILITIES', $p['total_liabilities']),
        $L('Equity', 'Capital put in by the owners', $p['contributed']),
        $L('Equity', 'Retained earnings (accumulated profit / loss)', $p['retained']),
        $L('Equity', 'Opening balances & reconciling items', $p['opening']),
        $L('', 'TOTAL EQUITY', $p['net_assets']),
        $L('', 'TOTAL LIABILITIES & EQUITY', round($p['total_liabilities'] + $p['net_assets'], 2)),
    ];
    $report['rows'] = $rows;
    $report['summary'] = [
        ['Total Assets', report_money($p['total_assets'])],
        ['Total Liabilities', report_money($p['total_liabilities'])],
        ['Equity (Net Worth)', report_money($p['net_assets'])],
        ['Working Capital', report_money($p['current_assets'] - $p['current_liabilities'])],
    ];
    $report['columns'] = [
        ['key' => 'section', 'label' => 'Section', 'w' => 14],
        ['key' => 'item', 'label' => 'Item', 'w' => 50],
        ['key' => 'amount', 'label' => 'Amount (RWF)', 'w' => 16, 'align' => 'R', 'type' => 'money'],
    ];
    $report['footer_summary'] = [
        ['Cash: Sales Collected', report_money($p['cash_parts']['sales'])],
        ['Cash: Other Income', report_money($p['cash_parts']['income'])],
        ['Cash: Capital & Loans In', report_money($p['cash_parts']['capital'])],
        ['Cash: Paid Out', report_money($p['cash_parts']['paid_out'])],
    ];
    $report['notes'][] = 'Total assets always equal liabilities + equity. "Opening balances & reconciling items" holds what existed before the system recorded it (opening stock, assets and loans taken before the system, cash on hand at the start) and the estimated service cost rule. If Cash is negative, record the business\'s starting cash once with Add Capital (source: Initial Share Capital or Owner Funding).';
    if ($p['as_of'] < date('Y-m-d')) {
        $report['notes'][] = 'For a past date, customer credit and purchase orders are shown as they stand today for invoices and orders dated on or before that date.';
    }
    return $report;
}

/* ---------------------------------------------------------------------------------------- */
// 3. Income Statement: all income of the period, where it came from

function fin_report_income(mysqli $conn, array $report, string $s, string $e): array
{
    $c = fin_pnl($conn, $s, $e);
    $sl = $c['sales'];
    $netSales = round($sl['product_gross'] + $sl['service_gross'] - $sl['discounts'], 2);
    $totalIncome = round($netSales + $c['other_total'], 2);
    $L = function ($sec, $item, $amount) use ($totalIncome) {
        return ['section' => $sec, 'item' => $item, 'amount' => $amount, 'pct' => fin_pct((float) $amount, $totalIncome)];
    };
    $rows = [
        $L('Sales', 'Product sales (before discount)', $sl['product_gross']),
        $L('Sales', 'Service sales (before discount)', $sl['service_gross']),
        $L('Sales', 'Less: discounts given', -$sl['discounts']),
        $L('', 'NET SALES', $netSales),
    ];
    foreach ($c['other_income'] as $k => $v) { $rows[] = $L('Other income', $k, $v); }
    $rows[] = $L('', 'TOTAL OTHER INCOME', $c['other_total']);
    $rows[] = $L('', 'TOTAL INCOME', $totalIncome);

    // By payment method
    [$r, $t, $pp] = fin_range('sale_date', $s, $e);
    $methods = fin_rows($conn, "SELECT payment_method, SUM(total_amount) AS amount FROM sales
        WHERE status IN ('Paid', 'Partially Paid', 'Credit')" . $r . " GROUP BY payment_method ORDER BY amount DESC", $t, $pp);

    $report['rows'] = $rows;
    $report['summary'] = [
        ['Total Income', report_money($totalIncome)],
        ['Net Sales', report_money($netSales)],
        ['Sales', number_format($sl['count'])],
        ['Average Sale', report_money($sl['count'] ? $sl['total'] / $sl['count'] : 0)],
    ];
    $report['columns'] = [
        ['key' => 'section', 'label' => 'Section', 'w' => 14],
        ['key' => 'item', 'label' => 'Income', 'w' => 44],
        ['key' => 'amount', 'label' => 'Amount (RWF)', 'w' => 16, 'align' => 'R', 'type' => 'money'],
        ['key' => 'pct', 'label' => '% of Income', 'w' => 10, 'align' => 'R'],
    ];
    $report['footer_summary'] = [
        ['Collected on These Sales', report_money($sl['paid'])],
        ['Still Owed on These Sales', report_money($sl['total'] - $sl['paid'])],
    ];
    foreach ($methods as $m) { $report['footer_summary'][] = ['Sales by ' . $m['payment_method'], report_money($m['amount'])]; }
    $report['notes'][] = 'Income is counted when it is earned: a credit sale is income on its sale date, even before it is paid. Capital and loans received are not income.';
    return $report;
}

/* ---------------------------------------------------------------------------------------- */
// 4. Expense Analysis: every cost by category, share, and change from the previous period

function fin_report_expenses(mysqli $conn, array $report, string $s, string $e): array
{
    $c = fin_pnl($conn, $s, $e);
    [$ps, $pe] = fin_previous_period($s, $e);
    $p = fin_pnl($conn, $ps, $pe);

    $groups = [
        'Cost of sales' => ['Cost of goods sold (buying price)' => [$c['product_cost'], $p['product_cost']],
                            fin_service_cost_label() => [$c['service_cost'], $p['service_cost']]],
        'Operating expenses' => [],
        'Finance costs' => ['Loan interest' => [$c['interest'], $p['interest']]],
    ];
    foreach (array_unique(array_merge(array_keys($c['opex']), array_keys($p['opex']))) as $k) {
        $groups['Operating expenses'][$k] = [$c['opex'][$k] ?? 0.0, $p['opex'][$k] ?? 0.0];
    }
    uasort($groups['Operating expenses'], function ($a, $b) { return $b[0] <=> $a[0]; });

    $total = 0.0; $prevTotal = 0.0;
    foreach ($groups as $items) { foreach ($items as $v) { $total += $v[0]; $prevTotal += $v[1]; } }

    $rows = []; $largest = ['—', 0.0];
    foreach ($groups as $g => $items) {
        $gNow = 0.0; $gPrev = 0.0;
        foreach ($items as $name => $v) {
            if (abs($v[0]) < 0.01 && abs($v[1]) < 0.01) { continue; }
            $rows[] = ['group' => $g, 'item' => $name, 'amount' => $v[0], 'share' => fin_pct($v[0], $total),
                       'of_revenue' => fin_pct($v[0], $c['revenue']), 'prev' => $v[1], 'change' => fin_change($v[0], $v[1])];
            $gNow += $v[0]; $gPrev += $v[1];
            if ($g === 'Operating expenses' && $v[0] > $largest[1]) { $largest = [$name, $v[0]]; }
        }
        $rows[] = ['group' => '', 'item' => 'TOTAL ' . strtoupper($g), 'amount' => $gNow, 'share' => fin_pct($gNow, $total),
                   'of_revenue' => fin_pct($gNow, $c['revenue']), 'prev' => $gPrev, 'change' => fin_change($gNow, $gPrev)];
    }

    $report['filters'][] = ['Compared with', date('d M Y', strtotime($ps)) . ' - ' . date('d M Y', strtotime($pe))];
    $report['rows'] = $rows;
    $report['summary'] = [
        ['Total Costs', report_money($total)],
        ['Operating Expenses', report_money($c['opex_total'])],
        ['Costs as % of Revenue', fin_pct($total, $c['revenue'])],
        ['Largest Operating Cost', $largest[0]],
    ];
    $report['columns'] = [
        ['key' => 'group', 'label' => 'Group', 'w' => 14],
        ['key' => 'item', 'label' => 'Expense', 'w' => 32],
        ['key' => 'amount', 'label' => 'This Period (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
        ['key' => 'share', 'label' => '% of Costs', 'w' => 8, 'align' => 'R'],
        ['key' => 'of_revenue', 'label' => '% of Revenue', 'w' => 8, 'align' => 'R'],
        ['key' => 'prev', 'label' => 'Previous (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
        ['key' => 'change', 'label' => 'Change', 'w' => 7, 'align' => 'R'],
    ];
    $report['totals'] = ['amount' => round($total, 2), 'prev' => round($prevTotal, 2)];
    $report['totals_label'] = 'TOTAL COSTS';
    $report['notes'][] = 'Manual expenses are grouped by the expense category chosen when they were recorded (pending, rejected and deleted ones are not counted). Stock purchases are not expenses; their cost appears here as cost of goods sold when the goods are sold.';
    return $report;
}

/* ---------------------------------------------------------------------------------------- */
// 5. Gross Profit Report: revenue - cost, for every product and service sold

function fin_report_gross(mysqli $conn, array $report, string $s, string $e): array
{
    [$r, $t, $p] = fin_range('sales.sale_date', $s, $e);
    $net = sales_report_line_net_sql('sales', 'sale_items');
    $cost = sales_report_line_cost_sql('sales', 'sale_items', 'products');
    $rows = fin_rows($conn, "SELECT sale_items.item_type, COALESCE(products.product_name, sale_items.service_name, 'Item') AS item,
            SUM(sale_items.quantity * GREATEST(COALESCE(sale_items.pack_size, 1), 1)) AS units,
            SUM($net) AS revenue, SUM($cost) AS cost
        FROM sale_items JOIN sales ON sale_items.sale_id = sales.id
        LEFT JOIN products ON sale_items.product_id = products.id
        WHERE " . FIN_VALID_SALES . $r . "
        GROUP BY sale_items.item_type, sale_items.product_id, item
        ORDER BY (SUM($net) - SUM($cost)) DESC", $t, $p);

    $tRev = 0.0; $tCost = 0.0; $byType = ['Product' => [0.0, 0.0], 'Service' => [0.0, 0.0]];
    foreach ($rows as &$row) {
        $row['revenue'] = round((float) $row['revenue'], 2);
        $row['cost'] = round((float) $row['cost'], 2);
        $row['gross'] = round($row['revenue'] - $row['cost'], 2);
        $row['margin'] = fin_pct($row['gross'], $row['revenue']);
        $tRev += $row['revenue']; $tCost += $row['cost'];
        $byType[$row['item_type']][0] += $row['revenue'];
        $byType[$row['item_type']][1] += $row['cost'];
    }
    unset($row);

    $gross = round($tRev - $tCost, 2);
    $report['rows'] = $rows;
    $report['summary'] = [
        ['Revenue', report_money($tRev)],
        ['Cost of Sales', report_money($tCost)],
        ['Gross Profit', report_money($gross)],
        ['Gross Margin', fin_pct($gross, $tRev)],
    ];
    $report['columns'] = [
        ['key' => 'item', 'label' => 'Product / Service', 'w' => 28],
        ['key' => 'item_type', 'label' => 'Type', 'w' => 8],
        ['key' => 'units', 'label' => 'Units Sold', 'w' => 8, 'align' => 'R', 'type' => 'int'],
        ['key' => 'revenue', 'label' => 'Revenue (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
        ['key' => 'cost', 'label' => 'Cost (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
        ['key' => 'gross', 'label' => 'Gross Profit (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
        ['key' => 'margin', 'label' => 'Margin', 'w' => 8, 'align' => 'R'],
    ];
    $report['totals'] = ['revenue' => round($tRev, 2), 'cost' => round($tCost, 2), 'gross' => $gross, 'margin' => fin_pct($gross, $tRev)];
    $report['footer_summary'] = [
        ['Products: Gross Profit', report_money($byType['Product'][0] - $byType['Product'][1])],
        ['Products: Margin', fin_pct($byType['Product'][0] - $byType['Product'][1], $byType['Product'][0])],
        ['Services: Gross Profit', report_money($byType['Service'][0] - $byType['Service'][1])],
        ['Services: Margin', fin_pct($byType['Service'][0] - $byType['Service'][1], $byType['Service'][0])],
    ];
    $report['notes'][] = 'Gross Profit = revenue (net of discount) - cost of sales. Product cost is the buying price frozen when sold; service cost follows the system service rule. Units are in base units (e.g. pieces).';
    return $report;
}

/* ---------------------------------------------------------------------------------------- */
// 6. Net Profit Report and 7. Profit Margin Report: month by month

function fin_report_monthly(mysqli $conn, array $report, string $s, string $e, bool $margins): array
{
    $rows = [];
    $t = ['revenue' => 0.0, 'gross' => 0.0, 'other' => 0.0, 'opex' => 0.0, 'interest' => 0.0, 'operating' => 0.0, 'net' => 0.0];
    foreach (fin_months($s, $e) as [$label, $ms, $me]) {
        $c = fin_pnl($conn, $ms, $me);
        $rows[] = [
            'month' => $label, 'revenue' => $c['revenue'], 'gross' => $c['gross'], 'other' => $c['other_total'],
            'opex' => $c['opex_total'], 'operating' => $c['operating'], 'interest' => $c['interest'], 'net' => $c['net'],
            'gross_m' => fin_pct($c['gross'], $c['revenue']), 'op_m' => fin_pct($c['operating'], $c['revenue']),
            'net_m' => fin_pct($c['net'], $c['revenue']),
        ];
        $t['revenue'] += $c['revenue']; $t['gross'] += $c['gross']; $t['other'] += $c['other_total'];
        $t['opex'] += $c['opex_total']; $t['interest'] += $c['interest']; $t['operating'] += $c['operating']; $t['net'] += $c['net'];
    }
    foreach ($t as $k => $v) { $t[$k] = round($v, 2); }

    $report['rows'] = $rows;
    if ($margins) {
        $whole = fin_pnl($conn, $s, $e);
        $report['summary'] = [
            ['Gross Margin', fin_pct($t['gross'], $t['revenue'])],
            ['Operating Margin', fin_pct($t['operating'], $t['revenue'])],
            ['Net Margin', fin_pct($t['net'], $t['revenue'])],
            ['Costs per RWF 100 of Sales', $t['revenue'] > 0 ? report_money(100 * ($t['revenue'] - $t['net']) / $t['revenue']) : '—'],
        ];
        $report['columns'] = [
            ['key' => 'month', 'label' => 'Month', 'w' => 10],
            ['key' => 'revenue', 'label' => 'Revenue (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
            ['key' => 'gross', 'label' => 'Gross Profit (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
            ['key' => 'gross_m', 'label' => 'Gross Margin', 'w' => 8, 'align' => 'R'],
            ['key' => 'operating', 'label' => 'Operating Profit (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
            ['key' => 'op_m', 'label' => 'Operating Margin', 'w' => 8, 'align' => 'R'],
            ['key' => 'net', 'label' => 'Net Profit (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
            ['key' => 'net_m', 'label' => 'Net Margin', 'w' => 8, 'align' => 'R'],
        ];
        $report['totals'] = ['revenue' => $t['revenue'], 'gross' => $t['gross'], 'gross_m' => fin_pct($t['gross'], $t['revenue']),
            'operating' => $t['operating'], 'op_m' => fin_pct($t['operating'], $t['revenue']), 'net' => $t['net'], 'net_m' => fin_pct($t['net'], $t['revenue'])];
        $report['footer_summary'] = [
            ['Product Margin', fin_pct($whole['product_revenue'] - $whole['product_cost'], $whole['product_revenue'])],
            ['Service Margin', fin_pct($whole['service_revenue'] - $whole['service_cost'], $whole['service_revenue'])],
        ];
        $report['notes'][] = 'Gross Margin = gross profit / revenue. Operating Margin = operating profit (after operating expenses) / revenue. Net Margin = net profit (after loan interest) / revenue.';
    } else {
        $report['summary'] = [
            ['Revenue', report_money($t['revenue'])],
            ['Gross Profit', report_money($t['gross'])],
            ['Expenses & Interest', report_money($t['opex'] + $t['interest'])],
            [$t['net'] >= 0 ? 'Net Profit' : 'Net Loss', report_money($t['net'])],
        ];
        $report['columns'] = [
            ['key' => 'month', 'label' => 'Month', 'w' => 10],
            ['key' => 'revenue', 'label' => 'Revenue (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
            ['key' => 'gross', 'label' => 'Gross Profit (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
            ['key' => 'other', 'label' => '+ Other Income (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
            ['key' => 'opex', 'label' => '- Operating Exp. (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
            ['key' => 'interest', 'label' => '- Interest (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
            ['key' => 'net', 'label' => '= Net Profit (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
            ['key' => 'net_m', 'label' => 'Net Margin', 'w' => 8, 'align' => 'R'],
        ];
        $report['totals'] = ['revenue' => $t['revenue'], 'gross' => $t['gross'], 'other' => $t['other'], 'opex' => $t['opex'],
            'interest' => $t['interest'], 'net' => $t['net'], 'net_m' => fin_pct($t['net'], $t['revenue'])];
        $best = $rows ? array_reduce($rows, function ($a, $r) { return ($a === null || $r['net'] > $a['net']) ? $r : $a; }) : null;
        $report['footer_summary'] = [
            ['Best Month', $best ? $best['month'] . ' (' . report_money($best['net']) . ')' : '—'],
            ['Months with a Loss', number_format(count(array_filter($rows, function ($r) { return $r['net'] < 0; })))],
        ];
        $report['notes'][] = 'Net Profit = Gross Profit + Other Income - Operating Expenses - Loan Interest, month by month. The same calculation as the Profit & Loss Statement.';
    }
    return $report;
}

/* ---------------------------------------------------------------------------------------- */
// 8. Financial Position Summary: the whole picture on one page

function fin_report_position(mysqli $conn, array $report, array $p): array
{
    $year = fin_pnl($conn, date('Y-01-01', strtotime($p['as_of'])), $p['as_of']);
    $ratio = $p['current_liabilities'] > 0 ? number_format($p['current_assets'] / $p['current_liabilities'], 2) : '—';
    $debt = fin_pct($p['total_liabilities'], $p['total_assets']);
    $R = function ($area, $item, $value, $note = '') { return ['area' => $area, 'item' => $item, 'value' => $value, 'note' => $note]; };

    $rows = [
        $R('What we own', 'Cash & bank', report_money($p['cash']), $p['cash'] < 0 ? 'Negative: record the starting cash with Add Capital' : ''),
        $R('What we own', 'Customers owe us', report_money($p['receivables']), 'Credit and partially paid sales'),
        $R('What we own', 'Stock (at buying price)', report_money($p['inventory'])),
        $R('What we own', 'Goods ordered, not received', report_money($p['goods_ordered'])),
        $R('What we own', 'Equipment & other assets', report_money($p['fixed_assets']), 'Net book value after depreciation'),
        $R('', 'TOTAL ASSETS', report_money($p['total_assets'])),
        $R('What we owe', 'Loans (principal)', report_money($p['loan_principal']), report_money($p['loan_current']) . ' due within 12 months'),
        $R('What we owe', 'Salaries not paid yet', report_money($p['salaries_payable']), 'Draft payroll'),
        $R('', 'TOTAL LIABILITIES', report_money($p['total_liabilities'])),
        $R('', 'NET WORTH (EQUITY)', report_money($p['net_assets']), 'Total assets - total liabilities'),
        $R('Health', 'Working capital', report_money($p['current_assets'] - $p['current_liabilities']), 'Current assets - current liabilities'),
        $R('Health', 'Current ratio', $ratio, 'Above 1.0: short-term debts are covered'),
        $R('Health', 'Debt ratio', $debt, 'Share of assets financed by debt'),
        $R('This year', 'Revenue (year to date)', report_money($year['revenue'])),
        $R('This year', 'Net profit (year to date)', report_money($year['net']), 'Net margin ' . fin_pct($year['net'], $year['revenue'])),
    ];
    foreach ($p['funds'] as $f) {
        $rows[] = $R('RM Funds', $f['name'], report_money($f['balance']));
    }

    $report['rows'] = $rows;
    $report['summary'] = [
        ['Net Worth', report_money($p['net_assets'])],
        ['Cash & Bank', report_money($p['cash'])],
        ['Total Debt', report_money($p['total_liabilities'])],
        ['Profit This Year', report_money($year['net'])],
    ];
    $report['columns'] = [
        ['key' => 'area', 'label' => 'Area', 'w' => 12],
        ['key' => 'item', 'label' => 'Item', 'w' => 28],
        ['key' => 'value', 'label' => 'Value', 'w' => 16, 'align' => 'R'],
        ['key' => 'note', 'label' => 'Note', 'w' => 30],
    ];
    $report['notes'][] = 'The same figures as the Balance Sheet, with the year-to-date result, the key health ratios and the balance of each RM Fund on that date.';
    return $report;
}