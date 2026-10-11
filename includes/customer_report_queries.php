<?php
/**
 * NEW FILE: includes/customer_report_queries.php
 * Data layer for the Customers & Credit reports. Same return shape as the Sales, Stock and
 * Transactions reports, so report_screen_result() and report_render_pdf() draw it.
 *
 * Rules (the same everywhere in the system):
 *  - A purchase = a sale that is not 'Pending Discount Approval' and not 'Cancelled'.
 *  - Owed = total_amount - amount_paid on sales that are 'Credit' or 'Partially Paid'.
 *  - Credit age = days since the sale date.
 *  - Customer ID = RMC + the customer number with 6 digits (as in the welcome SMS).
 *  - Invoice No = RM + the sale number with 6 digits (as on receipts and SMS).
 *  - Profit uses the shared rules in includes/profit_rules.php (same as the Sales reports).
 */
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/sales_report_queries.php'; // reuses sales_report_fetch()
require_once __DIR__ . '/profit_rules.php';

const CUSTOMER_REPORT_PURCHASE_STATUSES = "('Paid', 'Partially Paid', 'Credit')";
const CUSTOMER_REPORT_OWED_STATUSES = "('Credit', 'Partially Paid')";

function customer_report_types(): array
{
    return [
        'list'        => 'Customer List',
        'outstanding' => 'Outstanding Credit',
        'aging'       => 'Credit Aging',
        'statement'   => 'Customer Statement',
        'top'         => 'Top Customers',
        'loyalty'     => 'Loyalty Points',
    ];
}

// Reports that are "as of today" (no period) and the one that needs a customer.
function customer_report_uses_period(string $type): bool { return !in_array($type, ['outstanding', 'aging'], true); }
function customer_report_needs_customer(string $type): bool { return $type === 'statement'; }

function customer_report_code(int $customerId): string
{
    return 'RMC' . str_pad((string) $customerId, 6, '0', STR_PAD_LEFT);
}

function customer_report_invoice_no(int $saleId): string
{
    return 'RM' . str_pad((string) $saleId, 6, '0', STR_PAD_LEFT);
}

function customer_report_credit_threshold(): int
{
    return defined('LOYALTY_CREDIT_THRESHOLD') ? (int) LOYALTY_CREDIT_THRESHOLD : 500;
}

function customer_report_lookups(mysqli $conn): array
{
    return [
        'customers' => sales_report_fetch($conn, 'SELECT id, name, phone FROM customers WHERE is_active = 1 ORDER BY name'),
    ];
}

/** "s.sale_date BETWEEN ? AND ?" for the period, or nothing for All History. */
function customer_report_period_sql(array $period, string $alias = 's'): array
{
    if ($period['start'] === null) {
        return ['', '', []];
    }
    return [" AND $alias.sale_date BETWEEN ? AND ?", 'ss', [$period['start'], $period['end']]];
}

function customer_report_build(mysqli $conn, string $type, array $req): array
{
    $types = customer_report_types();
    $fail = function ($msg) use ($type, $types) {
        return ['error' => $msg, 'title' => $types[$type] ?? 'Customer Report', 'type' => $type];
    };
    if (!isset($types[$type])) {
        return $fail('Please choose a report.');
    }

    $filters = [];
    $period = ['start' => null, 'end' => null, 'label' => 'All History'];
    if (customer_report_uses_period($type)) {
        $period = report_period_from_request($req, true);
        if ($period['error']) {
            return $fail($period['error']);
        }
        $filters[] = ['Period', $period['label']];
    } else {
        $filters[] = ['As of', date('d M Y')];
    }

    // Customer (optional, required for the statement)
    $cid = (int) ($req['customer_id'] ?? 0);
    $customer = null;
    if ($cid > 0) {
        $c = sales_report_fetch($conn, 'SELECT id, name, phone, loyalty_points FROM customers WHERE id = ?', 'i', [$cid]);
        if (!$c) {
            return $fail('That customer was not found.');
        }
        $customer = $c[0];
        $filters[] = ['Customer', customer_report_code($cid) . ' — ' . $customer['name']];
    } elseif (customer_report_needs_customer($type)) {
        return $fail('Please select a customer.');
    }

    $report = [
        'error' => null, 'type' => $type, 'title' => $types[$type], 'filters' => $filters,
        'summary' => [], 'columns' => [], 'rows' => [], 'totals' => [], 'totals_label' => 'TOTAL',
        'footer_summary' => [], 'notes' => [],
    ];

    switch ($type) {
        case 'list':        return customer_report_list($conn, $report, $period, $cid);
        case 'outstanding': return customer_report_outstanding($conn, $report, $cid);
        case 'aging':       return customer_report_aging($conn, $report, $cid);
        case 'statement':   return customer_report_statement($conn, $report, $period, $customer);
        case 'top':         return customer_report_top($conn, $report, $period, $cid);
        case 'loyalty':     return customer_report_loyalty($conn, $report, $period, $cid);
    }
    return $fail('Please choose a report.');
}

/* ---------------------------------------------------------------------------------------- */

// Every active customer with purchases in the period and what they owe now.
function customer_report_list(mysqli $conn, array $report, array $period, int $cid): array
{
    [$pSql, $pTypes, $pParams] = customer_report_period_sql($period);
    $where = 'c.is_active = 1' . ($cid ? ' AND c.id = ?' : '');
    $types = $pTypes . ($cid ? 'i' : '');
    $params = array_merge($pParams, $cid ? [$cid] : []);

    $rows = sales_report_fetch($conn, "SELECT c.id, c.name, c.phone, c.sector, c.district, c.loyalty_points,
            COUNT(s.id) AS purchases, COALESCE(SUM(s.total_amount), 0) AS bought,
            COALESCE((SELECT SUM(o.total_amount - o.amount_paid) FROM sales o
                      WHERE o.customer_id = c.id AND o.status IN " . CUSTOMER_REPORT_OWED_STATUSES . "), 0) AS owed
        FROM customers c
        LEFT JOIN sales s ON s.customer_id = c.id AND s.status IN " . CUSTOMER_REPORT_PURCHASE_STATUSES . $pSql . "
        WHERE $where
        GROUP BY c.id, c.name, c.phone, c.sector, c.district, c.loyalty_points
        ORDER BY c.name", $types, $params);

    $threshold = customer_report_credit_threshold();
    $tBought = 0.0; $tOwed = 0.0; $tPurchases = 0; $active = 0;
    foreach ($rows as &$r) {
        $r['code'] = customer_report_code((int) $r['id']);
        $r['location'] = implode(', ', array_filter([$r['sector'] ?? '', $r['district'] ?? '']));
        $r['credit_status'] = (int) $r['loyalty_points'] >= $threshold ? 'Allowed' : 'Not Allowed';
        $tBought += (float) $r['bought'];
        $tOwed += (float) $r['owed'];
        $tPurchases += (int) $r['purchases'];
        if ((int) $r['purchases'] > 0) { $active++; }
    }
    unset($r);

    $report['rows'] = $rows;
    $report['summary'] = [
        ['Customers', number_format(count($rows))],
        ['Bought in Period', number_format($active)],
        ['Total Purchases', report_money($tBought)],
        ['Owed Now', report_money($tOwed)],
    ];
    $report['columns'] = [
        ['key' => 'code', 'label' => 'Customer ID', 'w' => 10],
        ['key' => 'name', 'label' => 'Name', 'w' => 16],
        ['key' => 'phone', 'label' => 'Phone', 'w' => 11],
        ['key' => 'location', 'label' => 'Location', 'w' => 16],
        ['key' => 'purchases', 'label' => 'Purchases', 'w' => 8, 'align' => 'R', 'type' => 'int'],
        ['key' => 'bought', 'label' => 'Bought (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
        ['key' => 'owed', 'label' => 'Owed Now (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
        ['key' => 'loyalty_points', 'label' => 'Points', 'w' => 7, 'align' => 'R', 'type' => 'int'],
        ['key' => 'credit_status', 'label' => 'Credit', 'w' => 9],
    ];
    $report['totals'] = ['purchases' => $tPurchases, 'bought' => $tBought, 'owed' => $tOwed];
    $report['notes'][] = 'Purchases and Bought count sales in the period that were completed (not pending approval, not cancelled). Owed Now is what the customer still owes today on all their sales.';
    return $report;
}

// Every unpaid invoice, oldest first per customer.
function customer_report_outstanding(mysqli $conn, array $report, int $cid): array
{
    $rows = sales_report_fetch($conn, "SELECT s.id AS sale_id, s.sale_date, s.payment_method, s.status, s.total_amount, s.amount_paid,
            (s.total_amount - s.amount_paid) AS balance, DATEDIFF(CURDATE(), s.sale_date) AS days,
            c.id AS customer_id, c.name, c.phone
        FROM sales s JOIN customers c ON c.id = s.customer_id
        WHERE s.status IN " . CUSTOMER_REPORT_OWED_STATUSES . ($cid ? ' AND c.id = ?' : '') . "
        ORDER BY c.name, s.sale_date, s.id", $cid ? 'i' : '', $cid ? [$cid] : []);

    $tTotal = 0.0; $tPaid = 0.0; $tBalance = 0.0; $customers = []; $oldest = 0;
    foreach ($rows as &$r) {
        $r['code'] = customer_report_code((int) $r['customer_id']);
        $r['invoice'] = customer_report_invoice_no((int) $r['sale_id']);
        $tTotal += (float) $r['total_amount'];
        $tPaid += (float) $r['amount_paid'];
        $tBalance += (float) $r['balance'];
        $customers[(int) $r['customer_id']] = true;
        $oldest = max($oldest, (int) $r['days']);
    }
    unset($r);

    $report['rows'] = $rows;
    $report['summary'] = [
        ['Customers Owing', number_format(count($customers))],
        ['Unpaid Invoices', number_format(count($rows))],
        ['Total Owed', report_money($tBalance)],
        ['Oldest Invoice', $rows ? $oldest . ' days' : '—'],
    ];
    $report['columns'] = [
        ['key' => 'code', 'label' => 'Customer ID', 'w' => 10],
        ['key' => 'name', 'label' => 'Customer', 'w' => 15],
        ['key' => 'phone', 'label' => 'Phone', 'w' => 10],
        ['key' => 'invoice', 'label' => 'Invoice No', 'w' => 10],
        ['key' => 'sale_date', 'label' => 'Date', 'w' => 9, 'type' => 'date'],
        ['key' => 'days', 'label' => 'Days', 'w' => 6, 'align' => 'R', 'type' => 'int'],
        ['key' => 'status', 'label' => 'Status', 'w' => 10],
        ['key' => 'total_amount', 'label' => 'Total (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'amount_paid', 'label' => 'Paid (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'balance', 'label' => 'Owed (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
    ];
    $report['totals'] = ['total_amount' => $tTotal, 'amount_paid' => $tPaid, 'balance' => $tBalance];
    $report['totals_label'] = 'TOTAL OWED';
    $report['notes'][] = 'Days = days since the sale date. Only Credit and Partially Paid sales are listed.';
    return $report;
}

// What each customer owes, by age of the debt.
function customer_report_aging(mysqli $conn, array $report, int $cid): array
{
    $bal = '(s.total_amount - s.amount_paid)';
    $age = 'DATEDIFF(CURDATE(), s.sale_date)';
    $rows = sales_report_fetch($conn, "SELECT c.id AS customer_id, c.name, c.phone,
            SUM(CASE WHEN $age <= 30 THEN $bal ELSE 0 END) AS d0_30,
            SUM(CASE WHEN $age BETWEEN 31 AND 60 THEN $bal ELSE 0 END) AS d31_60,
            SUM(CASE WHEN $age BETWEEN 61 AND 90 THEN $bal ELSE 0 END) AS d61_90,
            SUM(CASE WHEN $age > 90 THEN $bal ELSE 0 END) AS d90,
            SUM($bal) AS total, MAX($age) AS oldest
        FROM sales s JOIN customers c ON c.id = s.customer_id
        WHERE s.status IN " . CUSTOMER_REPORT_OWED_STATUSES . ($cid ? ' AND c.id = ?' : '') . "
        GROUP BY c.id, c.name, c.phone
        HAVING total > 0.009
        ORDER BY total DESC", $cid ? 'i' : '', $cid ? [$cid] : []);

    $t = ['d0_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90' => 0.0, 'total' => 0.0];
    foreach ($rows as &$r) {
        $r['code'] = customer_report_code((int) $r['customer_id']);
        foreach ($t as $k => $v) { $t[$k] += (float) $r[$k]; }
    }
    unset($r);

    $report['rows'] = $rows;
    $report['summary'] = [
        ['Total Owed', report_money($t['total'])],
        ['0-30 days', report_money($t['d0_30'])],
        ['31-90 days', report_money($t['d31_60'] + $t['d61_90'])],
        ['Over 90 days', report_money($t['d90'])],
    ];
    $report['columns'] = [
        ['key' => 'code', 'label' => 'Customer ID', 'w' => 10],
        ['key' => 'name', 'label' => 'Customer', 'w' => 16],
        ['key' => 'phone', 'label' => 'Phone', 'w' => 10],
        ['key' => 'd0_30', 'label' => '0-30 days', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'd31_60', 'label' => '31-60 days', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'd61_90', 'label' => '61-90 days', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'd90', 'label' => 'Over 90 days', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'total', 'label' => 'Total Owed', 'w' => 12, 'align' => 'R', 'type' => 'money'],
        ['key' => 'oldest', 'label' => 'Oldest (days)', 'w' => 8, 'align' => 'R', 'type' => 'int'],
    ];
    $report['totals'] = $t;
    $report['notes'][] = 'Each unpaid amount is placed by the age of its sale (days since the sale date). Debts over 90 days are the hardest to collect and should be followed up first.';
    return $report;
}

// One customer's invoices in the period, with what was paid and what is still owed.
function customer_report_statement(mysqli $conn, array $report, array $period, array $customer): array
{
    [$pSql, $pTypes, $pParams] = customer_report_period_sql($period);
    $rows = sales_report_fetch($conn, "SELECT s.id AS sale_id, s.sale_date, s.payment_method, s.status, s.total_amount, s.amount_paid,
            CASE WHEN s.status IN " . CUSTOMER_REPORT_OWED_STATUSES . " THEN s.total_amount - s.amount_paid ELSE 0 END AS balance,
            s.loyalty_points_earned
        FROM sales s
        WHERE s.customer_id = ? AND s.status <> 'Pending Discount Approval'" . $pSql . "
        ORDER BY s.sale_date, s.id", 'i' . $pTypes, array_merge([(int) $customer['id']], $pParams));

    $tTotal = 0.0; $tPaid = 0.0; $tBalance = 0.0; $cancelled = 0; $points = 0;
    foreach ($rows as &$r) {
        $r['invoice'] = customer_report_invoice_no((int) $r['sale_id']);
        if ($r['status'] === 'Cancelled') {
            // A cancelled invoice is shown for the history, but owes nothing and is not counted.
            $cancelled++;
            $r['note'] = 'Cancelled — not counted';
            continue;
        }
        $r['note'] = '';
        $tTotal += (float) $r['total_amount'];
        $tPaid += (float) $r['amount_paid'];
        $tBalance += (float) $r['balance'];
        $points += (int) $r['loyalty_points_earned'];
    }
    unset($r);

    $owedNow = sales_report_fetch($conn, "SELECT COALESCE(SUM(total_amount - amount_paid), 0) AS owed FROM sales
        WHERE customer_id = ? AND status IN " . CUSTOMER_REPORT_OWED_STATUSES, 'i', [(int) $customer['id']]);

    $report['title'] = 'Customer Statement — ' . $customer['name'];
    $report['rows'] = $rows;
    $report['summary'] = [
        ['Bought in Period', report_money($tTotal)],
        ['Paid on These', report_money($tPaid)],
        ['Still Owed on These', report_money($tBalance)],
        ['Owed Now (all invoices)', report_money($owedNow[0]['owed'] ?? 0)],
    ];
    $report['columns'] = [
        ['key' => 'sale_date', 'label' => 'Date', 'w' => 10, 'type' => 'date'],
        ['key' => 'invoice', 'label' => 'Invoice No', 'w' => 11],
        ['key' => 'payment_method', 'label' => 'Payment', 'w' => 11],
        ['key' => 'status', 'label' => 'Status', 'w' => 11],
        ['key' => 'total_amount', 'label' => 'Total (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
        ['key' => 'amount_paid', 'label' => 'Paid (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
        ['key' => 'balance', 'label' => 'Owed (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
        ['key' => 'loyalty_points_earned', 'label' => 'Points', 'w' => 7, 'align' => 'R', 'type' => 'int'],
        ['key' => 'note', 'label' => 'Note', 'w' => 14],
    ];
    $report['totals'] = ['total_amount' => $tTotal, 'amount_paid' => $tPaid, 'balance' => $tBalance, 'loyalty_points_earned' => $points];
    $report['footer_summary'] = [
        ['Customer ID', customer_report_code((int) $customer['id'])],
        ['Phone', (string) ($customer['phone'] ?? '—')],
        ['Loyalty Points Now', number_format((int) $customer['loyalty_points'])],
        ['Cancelled Invoices', number_format($cancelled)],
    ];
    $report['notes'][] = 'Totals leave out cancelled invoices. "Owed Now" includes unpaid invoices from before this period.';
    return $report;
}

// Customers ranked by what they bought in the period, with the profit they brought.
function customer_report_top(mysqli $conn, array $report, array $period, int $cid): array
{
    [$pSql, $pTypes, $pParams] = customer_report_period_sql($period);
    $cSql = $cid ? ' AND c.id = ?' : '';
    $types = $pTypes . ($cid ? 'i' : '');
    $params = array_merge($pParams, $cid ? [$cid] : []);

    $rows = sales_report_fetch($conn, "SELECT c.id AS customer_id, c.name, c.phone, c.loyalty_points,
            COUNT(s.id) AS purchases, SUM(s.total_amount) AS bought, SUM(s.amount_paid) AS paid
        FROM sales s JOIN customers c ON c.id = s.customer_id
        WHERE s.status IN " . CUSTOMER_REPORT_PURCHASE_STATUSES . $pSql . $cSql . "
        GROUP BY c.id, c.name, c.phone, c.loyalty_points
        ORDER BY bought DESC", $types, $params);

    // Profit per customer, with the same rules as the Sales reports.
    $lineNet = sales_report_line_net_sql('sales', 'sale_items');
    $lineCost = sales_report_line_cost_sql('sales', 'sale_items', 'products');
    $pSql2 = str_replace('s.sale_date', 'sales.sale_date', $pSql);
    $cSql2 = $cid ? ' AND sales.customer_id = ?' : '';
    $profitRows = sales_report_fetch($conn, "SELECT sales.customer_id, COALESCE(SUM($lineNet - $lineCost), 0) AS profit
        FROM sale_items JOIN sales ON sale_items.sale_id = sales.id
        LEFT JOIN products ON sale_items.product_id = products.id
        WHERE sales.customer_id IS NOT NULL AND sales.status IN " . CUSTOMER_REPORT_PURCHASE_STATUSES . $pSql2 . $cSql2 . "
        GROUP BY sales.customer_id", $types, $params);
    $profitBy = [];
    foreach ($profitRows as $p) { $profitBy[(int) $p['customer_id']] = (float) $p['profit']; }

    $rank = 0; $tBought = 0.0; $tPaid = 0.0; $tProfit = 0.0; $tPurchases = 0;
    foreach ($rows as &$r) {
        $r['rank'] = ++$rank;
        $r['code'] = customer_report_code((int) $r['customer_id']);
        $r['profit'] = round($profitBy[(int) $r['customer_id']] ?? 0, 2);
        $r['average'] = (int) $r['purchases'] > 0 ? (float) $r['bought'] / (int) $r['purchases'] : 0;
        $tBought += (float) $r['bought'];
        $tPaid += (float) $r['paid'];
        $tProfit += $r['profit'];
        $tPurchases += (int) $r['purchases'];
    }
    unset($r);

    $report['rows'] = $rows;
    $report['summary'] = [
        ['Customers', number_format(count($rows))],
        ['Total Bought', report_money($tBought)],
        ['Total Profit', report_money($tProfit)],
        ['Top Customer', $rows ? $rows[0]['name'] : '—'],
    ];
    $report['columns'] = [
        ['key' => 'rank', 'label' => '#', 'w' => 4, 'align' => 'R', 'type' => 'int'],
        ['key' => 'code', 'label' => 'Customer ID', 'w' => 10],
        ['key' => 'name', 'label' => 'Customer', 'w' => 16],
        ['key' => 'phone', 'label' => 'Phone', 'w' => 10],
        ['key' => 'purchases', 'label' => 'Purchases', 'w' => 8, 'align' => 'R', 'type' => 'int'],
        ['key' => 'bought', 'label' => 'Bought (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
        ['key' => 'average', 'label' => 'Average (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'paid', 'label' => 'Paid (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
        ['key' => 'profit', 'label' => 'Profit (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
        ['key' => 'loyalty_points', 'label' => 'Points', 'w' => 6, 'align' => 'R', 'type' => 'int'],
    ];
    $report['totals'] = ['purchases' => $tPurchases, 'bought' => $tBought, 'paid' => $tPaid, 'profit' => $tProfit];
    $report['notes'][] = 'Ranked by what each customer bought in the period (completed sales only). Profit uses the same rules as the Sales reports. Walk-in sales are not included.';
    return $report;
}

// Loyalty Points per customer: points now, earned in the period, and credit eligibility.
function customer_report_loyalty(mysqli $conn, array $report, array $period, int $cid): array
{
    [$pSql, $pTypes, $pParams] = customer_report_period_sql($period);
    $types = $pTypes . ($cid ? 'i' : '');
    $params = array_merge($pParams, $cid ? [$cid] : []);

    $rows = sales_report_fetch($conn, "SELECT c.id AS customer_id, c.name, c.phone, c.loyalty_points,
            COALESCE(SUM(s.loyalty_points_earned), 0) AS earned
        FROM customers c
        LEFT JOIN sales s ON s.customer_id = c.id AND s.status IN " . CUSTOMER_REPORT_PURCHASE_STATUSES . $pSql . "
        WHERE c.is_active = 1" . ($cid ? ' AND c.id = ?' : '') . "
        GROUP BY c.id, c.name, c.phone, c.loyalty_points
        ORDER BY c.loyalty_points DESC, c.name", $types, $params);

    $threshold = customer_report_credit_threshold();
    $tPoints = 0; $tEarned = 0; $eligible = 0;
    foreach ($rows as &$r) {
        $points = (int) $r['loyalty_points'];
        $r['code'] = customer_report_code((int) $r['customer_id']);
        $r['credit_status'] = $points >= $threshold ? 'Allowed' : 'Not Allowed';
        $r['to_credit'] = $points >= $threshold ? 0 : $threshold - $points;
        $tPoints += $points;
        $tEarned += (int) $r['earned'];
        if ($points >= $threshold) { $eligible++; }
    }
    unset($r);

    $report['rows'] = $rows;
    $report['summary'] = [
        ['Customers', number_format(count($rows))],
        ['Points Held Now', number_format($tPoints)],
        ['Earned in Period', number_format($tEarned)],
        ['Credit Allowed (' . $threshold . '+)', number_format($eligible)],
    ];
    $report['columns'] = [
        ['key' => 'code', 'label' => 'Customer ID', 'w' => 11],
        ['key' => 'name', 'label' => 'Customer', 'w' => 18],
        ['key' => 'phone', 'label' => 'Phone', 'w' => 11],
        ['key' => 'earned', 'label' => 'Earned in Period', 'w' => 11, 'align' => 'R', 'type' => 'int'],
        ['key' => 'loyalty_points', 'label' => 'Points Now', 'w' => 10, 'align' => 'R', 'type' => 'int'],
        ['key' => 'credit_status', 'label' => 'Credit', 'w' => 10],
        ['key' => 'to_credit', 'label' => 'Points to Credit', 'w' => 11, 'align' => 'R', 'type' => 'int'],
    ];
    $report['totals'] = ['earned' => $tEarned, 'loyalty_points' => $tPoints];
    $report['notes'][] = 'RWF 50 profit = 1 Loyalty Point. Customers with ' . $threshold . ' points or more can buy on Credit without approval. Points from cancelled sales are taken back.';
    return $report;
}