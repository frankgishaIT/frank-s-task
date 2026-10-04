<?php
/**
 * Data layer for the Transactions reports (Transactions Management module).
 * Same return shape as the Sales and Stock reports, so the shared screen and PDF code render it.
 *
 * Rules:
 *  - Reports read the `transactions` table exactly as recorded and filter on transaction_date.
 *  - Status filter: defaults to "Approved" when that status exists in the data, otherwise all statuses.
 *    The dropdown is filled from the statuses actually present, so nothing is hard-coded.
 *  - Employee = the user in transactions.recorded_by.
 *  - Party = the business party in transactions.party_id (RM Payee on expenses, RM Partner on income).
 *  - `category` is Product or Service for transactions entered on the Add Transaction form; transactions the
 *    system posts itself use other values, e.g. 'Purchase (Re-stock)' when a purchase order is received.
 */
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/sales_report_queries.php'; // reuses sales_report_fetch()

function transaction_report_types(): array
{
    return [
        'all'      => 'All Transactions Report',
        'income'   => 'Income Report',
        'expense'  => 'Expense Report',
        'employee' => 'Transactions by Employee',
    ];
}

function transaction_report_lookups(mysqli $conn): array
{
    $statuses = array_column(sales_report_fetch($conn, "SELECT DISTINCT status FROM transactions WHERE status IS NOT NULL AND status <> '' ORDER BY status"), 'status');
    $default = 'all';
    foreach ($statuses as $s) {
        if (strtolower($s) === 'approved') {
            $default = $s;
        }
    }
    return [
        'employees'      => sales_report_fetch($conn, "SELECT id, names FROM users ORDER BY names"),
        'statuses'       => $statuses,
        'default_status' => $default,
        'categories'     => array_column(sales_report_fetch($conn, "SELECT DISTINCT category FROM transactions WHERE category IS NOT NULL AND category <> '' ORDER BY category"), 'category'),
    ];
}

function transaction_report_build(mysqli $conn, string $type, array $req): array
{
    $types = transaction_report_types();
    $fail = function ($msg) use ($type, $types) {
        return ['error' => $msg, 'title' => $types[$type] ?? 'Transactions Report', 'type' => $type];
    };
    if (!isset($types[$type])) {
        return $fail('Please choose a report.');
    }
    $period = report_period_from_request($req);
    if ($period['error']) {
        return $fail($period['error']);
    }
    $lookups = transaction_report_lookups($conn);

    $where = ['t.transaction_date BETWEEN ? AND ?'];
    $bt = 'ss';
    $params = [$period['start'], $period['end']];
    $filters = [['Period', $period['label']]];

    // Type
    if ($type === 'income') {
        $where[] = "t.transaction_type = 'Income'";
    } elseif ($type === 'expense') {
        $where[] = "t.transaction_type = 'Expense'";
    } else {
        $typeReq = (string) ($req['type'] ?? '');
        if ($type === 'all' && in_array($typeReq, ['Income', 'Expense'], true)) {
            $where[] = 't.transaction_type = ?';
            $bt .= 's';
            $params[] = $typeReq;
            $filters[] = ['Transaction Type', $typeReq];
        } elseif ($type === 'all') {
            $filters[] = ['Transaction Type', 'All'];
        }
    }

    // Employee
    $eid = (int) ($req['employee_id'] ?? 0);
    if ($type === 'employee' && $eid <= 0) {
        return $fail('Please select an employee.');
    }
    if ($eid > 0) {
        $e = sales_report_fetch($conn, 'SELECT names FROM users WHERE id = ?', 'i', [$eid]);
        if (!$e) {
            return $fail('That employee was not found.');
        }
        $where[] = 't.recorded_by = ?';
        $bt .= 'i';
        $params[] = $eid;
        $filters[] = ['Employee', $e[0]['names']];
    }

    // Status
    $status = (string) ($req['status'] ?? $lookups['default_status']);
    if ($status !== 'all' && in_array($status, $lookups['statuses'], true)) {
        $where[] = 't.status = ?';
        $bt .= 's';
        $params[] = $status;
        $filters[] = ['Status', ucfirst($status)];
    } else {
        $filters[] = ['Status', 'All statuses'];
    }

    // Category
    $cat = (string) ($req['category'] ?? '');
    if ($cat !== '' && in_array($cat, $lookups['categories'], true)) {
        $where[] = 't.category = ?';
        $bt .= 's';
        $params[] = $cat;
        $filters[] = ['Category', $cat];
    }

    $rows = sales_report_fetch($conn,
        "SELECT t.id, t.transaction_date, t.transaction_type, t.category, t.description, t.amount, t.status, u.names AS employee,
                COALESCE(NULLIF(bp.name, ''), NULLIF(bp.business_name, '')) AS party_name
           FROM transactions t LEFT JOIN users u ON u.id = t.recorded_by
           LEFT JOIN business_parties bp ON bp.id = t.party_id
          WHERE " . implode(' AND ', $where) . " ORDER BY t.transaction_date, t.id", $bt, $params);

    $income = 0.0; $expense = 0.0; $byCategory = [];
    foreach ($rows as &$r) {
        $r['status'] = ucfirst((string) $r['status']);
        $isIncome = $r['transaction_type'] === 'Income';
        $r['income']  = $isIncome ? (float) $r['amount'] : null;
        $r['expense'] = $isIncome ? null : (float) $r['amount'];
        if ($isIncome) { $income += (float) $r['amount']; } else { $expense += (float) $r['amount']; }
        $key = $r['category'] !== null && $r['category'] !== '' ? $r['category'] : 'Uncategorised';
        $byCategory[$key] = ($byCategory[$key] ?? 0) + (float) $r['amount'];
    }
    unset($r);
    arsort($byCategory);

    $report = [
        'error' => null, 'type' => $type, 'title' => $types[$type], 'filters' => $filters,
        'summary' => [], 'columns' => [], 'rows' => $rows, 'totals' => [], 'totals_label' => 'TOTAL',
        'footer_summary' => [], 'notes' => [],
    ];

    $common = [
        ['key' => 'id', 'label' => 'Ref #', 'w' => 5, 'type' => 'int'],
        ['key' => 'transaction_date', 'label' => 'Date', 'w' => 9, 'type' => 'date'],
    ];

    if ($type === 'income' || $type === 'expense') {
        $isInc = $type === 'income';
        $total = $isInc ? $income : $expense;
        $report['summary'] = [
            [$isInc ? 'Income Transactions' : 'Expense Transactions', number_format(count($rows))],
            [$isInc ? 'Total Income' : 'Total Expenses', report_money($total)],
        ];
        $report['columns'] = array_merge($common, [
            ['key' => 'category', 'label' => 'Category', 'w' => 9],
            ['key' => 'party_name', 'label' => 'Party', 'w' => 13],
            ['key' => 'description', 'label' => 'Description', 'w' => 26],
            ['key' => 'amount', 'label' => 'Amount (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
            ['key' => 'status', 'label' => 'Status', 'w' => 9],
            ['key' => 'employee', 'label' => 'Recorded By', 'w' => 13],
        ]);
        $report['totals'] = ['amount' => $total];
        $report['totals_label'] = $isInc ? 'TOTAL INCOME' : 'TOTAL EXPENSES';
        $i = 0;
        foreach ($byCategory as $name => $sum) {
            if (++$i > 8) { break; }
            $report['footer_summary'][] = [$name, report_money($sum)];
        }
    } else {
        $report['summary'] = [
            ['Transactions', number_format(count($rows))],
            ['Total Income', report_money($income)],
            ['Total Expenses', report_money($expense)],
            ['Net (Income - Expenses)', report_money($income - $expense)],
        ];
        $report['columns'] = array_merge($common, [
            ['key' => 'transaction_type', 'label' => 'Type', 'w' => 8],
            ['key' => 'category', 'label' => 'Category', 'w' => 8],
            ['key' => 'party_name', 'label' => 'Party', 'w' => 11],
            ['key' => 'description', 'label' => 'Description', 'w' => 22],
            ['key' => 'income', 'label' => 'Income (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
            ['key' => 'expense', 'label' => 'Expense (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
            ['key' => 'status', 'label' => 'Status', 'w' => 8],
            ['key' => 'employee', 'label' => 'Recorded By', 'w' => 12],
        ]);
        $report['totals'] = ['income' => $income, 'expense' => $expense];
    }
    return $report;
}
