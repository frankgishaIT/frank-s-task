<?php
/**
 * NEW FILE: includes/party_report_queries.php
 * Receivables and Payables reports for RM Business Parties. Same return shape as the other reports,
 * so report_screen_result() and report_render_pdf() draw them.
 *
 * Built ONLY from existing records (no new amounts are stored):
 *   - transactions.party_id          the business party of a manual income or expense
 *   - purchase_orders.supplier_party_id   the supplier of a purchase-order payment (the payment is the
 *                                        transaction linked to the PO in the fund ledger)
 * Every transaction is read ONCE (its own party, otherwise the PO's supplier), so nothing is counted twice.
 * Rejected and deleted transactions are left out.
 *   Payables:    approved expense = Paid;   pending expense = Pending approval (outstanding)
 *   Receivables: approved income  = Received; pending income = Expected (outstanding)
 */
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/sales_report_queries.php'; // reuses sales_report_fetch()

function party_report_types(): array
{
    return [
        'payables'          => 'Payables Report',
        'payables_party'    => 'Payables by Business Party',
        'receivables'       => 'Receivables Report',
        'receivables_party' => 'Receivables by Business Party',
    ];
}

function party_report_lookups(mysqli $conn): array
{
    return [
        'parties' => sales_report_fetch($conn, "SELECT id, type, COALESCE(NULLIF(business_name, ''), name) AS label FROM business_parties ORDER BY label"),
    ];
}

function party_report_statuses(string $type): array
{
    return strpos($type, 'payables') === 0
        ? ['Paid' => 'approved', 'Pending approval' => 'pending']
        : ['Received' => 'approved', 'Expected' => 'pending'];
}

function party_report_build(mysqli $conn, string $type, array $req): array
{
    $types = party_report_types();
    $fail = function ($msg) use ($type, $types) { return ['error' => $msg, 'title' => $types[$type] ?? 'Business Party Report', 'type' => $type]; };
    if (!isset($types[$type])) { return $fail('Please choose a report.'); }

    $period = report_period_from_request($req);
    if ($period['error']) { return $fail($period['error']); }
    $filters = [['Period', $period['label']]];
    $isPay = strpos($type, 'payables') === 0;

    $where = ["t.transaction_type = ?", "t.status IN ('approved', 'pending')", 't.transaction_date BETWEEN ? AND ?'];
    $bt = 'sss';
    $params = [$isPay ? 'Expense' : 'Income', $period['start'], $period['end']];

    $partyId = (int) ($req['party_id'] ?? 0);
    if ($partyId > 0) {
        $p = sales_report_fetch($conn, "SELECT COALESCE(NULLIF(business_name, ''), name) AS label FROM business_parties WHERE id = ?", 'i', [$partyId]);
        if (!$p) { return $fail('That business party was not found.'); }
        $where[] = 'bp.id = ?'; $bt .= 'i'; $params[] = $partyId;
        $filters[] = ['Business Party', $p[0]['label']];
    }
    $statusMap = party_report_statuses($type);
    $status = (string) ($req['status'] ?? '');
    if (isset($statusMap[$status])) {
        $where[] = 't.status = ?'; $bt .= 's'; $params[] = $statusMap[$status];
        $filters[] = ['Status', $status];
    }

    // One row per transaction: its own party, otherwise (payments of purchase orders) the PO's supplier.
    $rows = sales_report_fetch($conn, "SELECT t.id, t.transaction_date, t.amount, t.status, t.category, t.expense_category, t.description,
            bp.id AS party_id, bp.type AS party_type, COALESCE(NULLIF(bp.business_name, ''), bp.name) AS party, pol.po_id
        FROM transactions t
        LEFT JOIN (SELECT transaction_id, MIN(ref_id) AS po_id FROM fund_movements
                   WHERE ref_type = 'PO' AND movement_type = 'EXPENSE' AND transaction_id IS NOT NULL GROUP BY transaction_id) pol
               ON pol.transaction_id = t.id
        LEFT JOIN purchase_orders po ON po.id = pol.po_id
        JOIN business_parties bp ON bp.id = COALESCE(t.party_id, po.supplier_party_id)
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.transaction_date, t.id", $bt, $params);

    $done = $isPay ? 'Paid' : 'Received';
    $open = $isPay ? 'Pending approval' : 'Expected';
    $tDone = 0.0; $tOpen = 0.0; $parties = [];
    foreach ($rows as &$r) {
        $isDone = $r['status'] === 'approved';
        $r['status_label'] = $isDone ? $done : $open;
        $r['done'] = $isDone ? (float) $r['amount'] : 0.0;
        $r['outstanding'] = $isDone ? 0.0 : (float) $r['amount'];
        $r['purpose'] = trim(($r['expense_category'] ?: $r['category']) . ($r['description'] ? ' — ' . $r['description'] : ''));
        $r['reference'] = 'TX-' . str_pad((string) $r['id'], 6, '0', STR_PAD_LEFT) . ($r['po_id'] ? ' / PO RM' . str_pad((string) $r['po_id'], 5, '0', STR_PAD_LEFT) : '');
        $tDone += $r['done']; $tOpen += $r['outstanding'];
        $parties[$r['party_id']] = true;
    }
    unset($r);

    $report = [
        'error' => null, 'type' => $type, 'title' => $types[$type], 'filters' => $filters,
        'summary' => [
            [$isPay ? 'Total Paid' : 'Total Received', report_money($tDone)],
            ['Outstanding', report_money($tOpen)],
            ['Total ' . ($isPay ? 'Payable' : 'Receivable'), report_money($tDone + $tOpen)],
            ['Business Parties', number_format(count($parties))],
        ],
        'columns' => [], 'rows' => [], 'totals' => [], 'totals_label' => 'TOTAL', 'footer_summary' => [], 'notes' => [],
    ];

    if ($type === 'payables' || $type === 'receivables') {
        $report['rows'] = $rows;
        $report['columns'] = [
            ['key' => 'transaction_date', 'label' => $isPay ? 'Payment Date' : 'Transaction Date', 'w' => 9, 'type' => 'date'],
            ['key' => 'party', 'label' => 'Business Party', 'w' => 15],
            ['key' => 'party_type', 'label' => 'Type', 'w' => 7],
            ['key' => 'purpose', 'label' => 'Payment Purpose', 'w' => 22],
            ['key' => 'reference', 'label' => 'Reference', 'w' => 13],
            ['key' => 'amount', 'label' => $isPay ? 'Amount Payable (RWF)' : 'Amount Receivable (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
            ['key' => 'done', 'label' => ($isPay ? 'Paid' : 'Received') . ' (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
            ['key' => 'outstanding', 'label' => 'Outstanding (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
            ['key' => 'status_label', 'label' => 'Status', 'w' => 9],
        ];
        $report['totals'] = ['amount' => round($tDone + $tOpen, 2), 'done' => round($tDone, 2), 'outstanding' => round($tOpen, 2)];
    } else {
        $by = [];
        foreach ($rows as $r) {
            $k = $r['party_id'];
            if (!isset($by[$k])) { $by[$k] = ['party' => $r['party'], 'party_type' => $r['party_type'], 'records' => 0, 'amount' => 0.0, 'done' => 0.0, 'outstanding' => 0.0, 'last' => null]; }
            $by[$k]['records']++;
            $by[$k]['amount'] += (float) $r['amount'];
            $by[$k]['done'] += $r['done'];
            $by[$k]['outstanding'] += $r['outstanding'];
            $by[$k]['last'] = max((string) $by[$k]['last'], $r['transaction_date']);
        }
        $out = array_values($by);
        usort($out, function ($a, $b) { return $b['amount'] <=> $a['amount']; });
        $report['rows'] = $out;
        $report['columns'] = [
            ['key' => 'party', 'label' => 'Business Party', 'w' => 22],
            ['key' => 'party_type', 'label' => 'Type', 'w' => 9],
            ['key' => 'records', 'label' => 'Records', 'w' => 7, 'align' => 'R', 'type' => 'int'],
            ['key' => 'amount', 'label' => 'Total ' . ($isPay ? 'Payable' : 'Receivable') . ' (RWF)', 'w' => 14, 'align' => 'R', 'type' => 'money'],
            ['key' => 'done', 'label' => ($isPay ? 'Paid' : 'Received') . ' (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
            ['key' => 'outstanding', 'label' => 'Outstanding (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
            ['key' => 'last', 'label' => 'Last Transaction', 'w' => 10, 'type' => 'date'],
        ];
        $report['totals'] = ['records' => count($rows), 'amount' => round($tDone + $tOpen, 2), 'done' => round($tDone, 2), 'outstanding' => round($tOpen, 2)];
    }

    $report['notes'][] = $isPay
        ? 'Payables are the expenses linked to a business party: manual expenses with that party, and payments of purchase orders to their supplier. Paid = approved; Pending approval = recorded but not approved yet, so still outstanding. Rejected and deleted records are not counted, and each transaction is counted once.'
        : 'Receivables are the income linked to a business party. Received = approved; Expected = recorded but not approved yet, so still outstanding. Rejected and deleted records are not counted, and each transaction is counted once. Customer credit sales are in the Customer reports.';
    return $report;
}