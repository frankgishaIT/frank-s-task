<?php
/**
 * Data layer for the Purchase Order reports (Purchase Orders module).
 * Same return shape as the other report modules, so the shared screen and PDF code render it.
 *
 * Tables: purchase_orders (status: Draft, Ordered, Received, Cancelled), purchase_order_items, users.
 * Rules:
 *  - Period filters on purchase_orders.order_date (Cancelled report: on the date it was cancelled).
 *  - "Ordered" = status 'Ordered', i.e. placed with the supplier and not yet received.
 *  - Supplier = the business party linked by supplier_party_id (business_parties, type Supplier);
 *    the name saved on the order is only used when an order has no linked party.
 *  - Payment: when an order is received the system posts ONE approved Expense transaction for the whole order
 *    (category 'Purchase (Re-stock)', description 'Purchase Order #N received ...'). Amount paid is the sum of
 *    those transactions, so Received orders normally show Paid and Ordered (not yet received) orders show Unpaid.
 *    Purchase orders themselves store no payments and there is no part-payment feature.
 */
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/sales_report_queries.php'; // reuses sales_report_fetch()

function purchase_report_types(): array
{
    return [
        'summary'   => 'Purchase Orders Summary',
        'ordered'   => 'Ordered Purchase Orders',
        'cancelled' => 'Cancelled Purchase Orders',
        'supplier'  => 'Purchase by Supplier',
    ];
}

function purchase_report_lookups(mysqli $conn): array
{
    return [
        'suppliers' => sales_report_fetch($conn, "SELECT id, COALESCE(NULLIF(business_name, ''), NULLIF(name, '')) AS label FROM business_parties WHERE type = 'Supplier' ORDER BY label"),
        'statuses'  => ['Draft', 'Ordered', 'Received', 'Cancelled'],
    ];
}

/** PO rows with item text, item count and user names. $where uses alias po. */
function purchase_report_rows(mysqli $conn, string $where, string $types, array $params, string $order = 'po.order_date, po.id'): array
{
    mysqli_query($conn, 'SET SESSION group_concat_max_len = 8192');
    $sql = "SELECT po.id, COALESCE(NULLIF(bp.business_name, ''), NULLIF(bp.name, ''), po.supplier) AS supplier_name, po.order_date, po.expected_delivery_date, po.status, po.total_amount,
                   po.ordered_at, po.cancelled_at, po.cancel_reason, po.notes,
                   cu.names AS created_by_name, ou.names AS ordered_by_name, xu.names AS cancelled_by_name,
                   (SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
                     WHERE t.transaction_type = 'Expense' AND t.status = 'approved' AND t.category = 'Purchase (Re-stock)'
                       AND t.description LIKE CONCAT('Purchase Order #', po.id, ' received%')) AS amount_paid,
                   (SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id = po.id) AS item_count,
                   (SELECT GROUP_CONCAT(CONCAT(COALESCE(p.product_name, 'Item'), ' x', poi.quantity,
                            IF(poi.pack_label IS NOT NULL AND poi.pack_label <> '', CONCAT(' ', poi.pack_label), ''))
                            ORDER BY poi.id SEPARATOR '; ')
                      FROM purchase_order_items poi LEFT JOIN products p ON p.id = poi.product_id
                     WHERE poi.purchase_order_id = po.id) AS items
              FROM purchase_orders po
              LEFT JOIN users cu ON cu.id = po.created_by
              LEFT JOIN users ou ON ou.id = po.ordered_by
              LEFT JOIN users xu ON xu.id = po.cancelled_by
              LEFT JOIN business_parties bp ON bp.id = po.supplier_party_id
             WHERE $where ORDER BY $order";
    $rows = sales_report_fetch($conn, $sql, $types, $params);
    $today = new DateTimeImmutable('today');
    foreach ($rows as &$r) {
        $r['po_ref'] = 'PO-' . str_pad((string) $r['id'], 5, '0', STR_PAD_LEFT);
        $r['outstanding'] = max((float) $r['total_amount'] - (float) $r['amount_paid'], 0);
        if ($r['outstanding'] <= 0.005) {
            $r['payment_status'] = 'Paid';
        } elseif ((float) $r['amount_paid'] > 0) {
            $r['payment_status'] = 'Partially Paid';
        } else {
            $r['payment_status'] = 'Unpaid';
        }
        $r['delivery'] = '';
        if ($r['status'] === 'Ordered' && !empty($r['expected_delivery_date'])) {
            $due = DateTimeImmutable::createFromFormat('Y-m-d', $r['expected_delivery_date']);
            if ($due && $due < $today) {
                $r['delivery'] = 'Overdue by ' . $due->diff($today)->days . ' day(s)';
            } else {
                $r['delivery'] = 'On schedule';
            }
        }
    }
    unset($r);
    return $rows;
}

function purchase_report_sum(array $rows, string $key): float
{
    $t = 0.0;
    foreach ($rows as $r) {
        $t += (float) $r[$key];
    }
    return $t;
}

function purchase_report_build(mysqli $conn, string $type, array $req): array
{
    $types = purchase_report_types();
    $fail = function ($msg) use ($type, $types) {
        return ['error' => $msg, 'title' => $types[$type] ?? 'Purchase Order Report', 'type' => $type];
    };
    if (!isset($types[$type])) {
        return $fail('Please choose a report.');
    }
    $period = report_period_from_request($req);
    if ($period['error']) {
        return $fail($period['error']);
    }
    $lookups = purchase_report_lookups($conn);
    $filters = [['Period', $period['label']]];

    $report = [
        'error' => null, 'type' => $type, 'title' => $types[$type], 'filters' => [],
        'summary' => [], 'columns' => [], 'rows' => [], 'totals' => [], 'totals_label' => 'TOTAL',
        'footer_summary' => [], 'notes' => [],
    ];

    // Optional supplier filter (all reports): value is the business party id
    $supplierWhere = '';
    $supplierTypes = '';
    $supplierParams = [];
    $supplierId = (int) ($req['supplier'] ?? 0);
    if ($supplierId > 0) {
        $label = null;
        foreach ($lookups['suppliers'] as $sp) {
            if ((int) $sp['id'] === $supplierId) {
                $label = $sp['label'];
            }
        }
        if ($label === null) {
            return $fail('That supplier was not found.');
        }
        $supplierWhere = ' AND po.supplier_party_id = ?';
        $supplierTypes = 'i';
        $supplierParams = [$supplierId];
        $filters[] = ['Supplier', $label];
    } elseif ($type === 'supplier') {
        $filters[] = ['Supplier', 'All suppliers'];
    }

    $base = 'po.order_date BETWEEN ? AND ?';
    $bt = 'ss';
    $params = [$period['start'], $period['end']];

    switch ($type) {
        // ------------------------------------------------------------------
        case 'summary':
        case 'supplier':
            $status = (string) ($req['status'] ?? '');
            if ($status === '') {
                $status = $type === 'supplier' ? 'active' : 'all';
            }
            if ($type === 'supplier' && !in_array($status, ['Ordered', 'Received'], true)) {
                $status = 'active'; // Purchase by Supplier covers committed orders only: Ordered and Received
            }
            $statusWhere = '';
            if (in_array($status, $lookups['statuses'], true)) {
                $statusWhere = ' AND po.status = ?';
                $bt .= 's';
                $params[] = $status;
                $filters[] = ['Status', $status];
            } elseif ($status === 'active') {
                $statusWhere = " AND po.status IN ('Ordered','Received')";
                $filters[] = ['Status', 'Ordered and Received'];
            } else {
                $status = 'all';
                $filters[] = ['Status', 'All statuses'];
            }
            $order = $type === 'supplier' ? 'supplier_name, po.order_date, po.id' : 'po.order_date, po.id';
            $rows = purchase_report_rows($conn, $base . $supplierWhere . $statusWhere, $bt . $supplierTypes, array_merge($params, $supplierParams), $order);

            $byStatus = [];
            foreach ($rows as $r) {
                $byStatus[$r['status']]['n'] = ($byStatus[$r['status']]['n'] ?? 0) + 1;
                $byStatus[$r['status']]['v'] = ($byStatus[$r['status']]['v'] ?? 0) + (float) $r['total_amount'];
            }
            $valueExCancelled = 0.0;
            foreach ($rows as $r) {
                if ($r['status'] !== 'Cancelled' && $r['status'] !== 'Draft') {
                    $valueExCancelled += (float) $r['total_amount'];
                }
            }

            if ($type === 'summary') {
                $report['summary'] = [
                    ['Purchase Orders', number_format(count($rows))],
                    ['Total Value (all listed)', report_money(purchase_report_sum($rows, 'total_amount'))],
                    ['Value Ordered + Received', report_money($valueExCancelled)],
                    ['Cancelled Value', report_money($byStatus['Cancelled']['v'] ?? 0)],
                ];
                foreach ($lookups['statuses'] as $st) {
                    $report['footer_summary'][] = [$st . ' orders', number_format($byStatus[$st]['n'] ?? 0) . ' / ' . report_money($byStatus[$st]['v'] ?? 0)];
                }
                $report['columns'] = [
                    ['key' => 'po_ref', 'label' => 'PO #', 'w' => 7],
                    ['key' => 'order_date', 'label' => 'Order Date', 'w' => 9, 'type' => 'date'],
                    ['key' => 'supplier_name', 'label' => 'Supplier', 'w' => 18],
                    ['key' => 'expected_delivery_date', 'label' => 'Expected Delivery', 'w' => 10, 'type' => 'date'],
                    ['key' => 'status', 'label' => 'Status', 'w' => 8],
                    ['key' => 'item_count', 'label' => 'Items', 'w' => 5, 'align' => 'R', 'type' => 'int'],
                    ['key' => 'total_amount', 'label' => 'Total (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
                    ['key' => 'created_by_name', 'label' => 'Created By', 'w' => 12],
                ];
                $report['rows'] = $rows;
                $report['totals'] = ['item_count' => purchase_report_sum($rows, 'item_count'), 'total_amount' => purchase_report_sum($rows, 'total_amount')];
            } else {
                $paid = purchase_report_sum($rows, 'amount_paid');
                $outstanding = purchase_report_sum($rows, 'outstanding');
                $report['summary'] = [
                    ['Purchase Orders', number_format(count($rows))],
                    ['Total Purchased', report_money(purchase_report_sum($rows, 'total_amount'))],
                    ['Amount Paid', report_money($paid)],
                    ['Outstanding', report_money($outstanding)],
                ];
                $report['columns'] = [
                    ['key' => 'supplier_name', 'label' => 'Supplier', 'w' => 13],
                    ['key' => 'po_ref', 'label' => 'PO #', 'w' => 6],
                    ['key' => 'order_date', 'label' => 'Order Date', 'w' => 8, 'type' => 'date'],
                    ['key' => 'status', 'label' => 'Order Status', 'w' => 7],
                    ['key' => 'items', 'label' => 'Purchase Details', 'w' => 24],
                    ['key' => 'total_amount', 'label' => 'Total (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
                    ['key' => 'amount_paid', 'label' => 'Paid (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
                    ['key' => 'outstanding', 'label' => 'Outstanding (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
                    ['key' => 'payment_status', 'label' => 'Payment Status', 'w' => 8],
                ];
                $report['rows'] = $rows;
                $report['totals'] = ['total_amount' => purchase_report_sum($rows, 'total_amount'), 'amount_paid' => $paid, 'outstanding' => $outstanding];
                $report['notes'] = ['Covers Ordered and Received orders. Payment is taken from the Expense the system posts when an order is received, so received orders show as paid and orders not yet received show as unpaid. Orders do not support part payments.'];
            }
            break;

        // ------------------------------------------------------------------
        case 'ordered':
            $rows = purchase_report_rows($conn, $base . " AND po.status = 'Ordered'" . $supplierWhere, $bt . $supplierTypes, array_merge($params, $supplierParams));
            $overdue = 0;
            foreach ($rows as $r) {
                if (strpos($r['delivery'], 'Overdue') === 0) { $overdue++; }
            }
            $report['summary'] = [
                ['Orders Placed (not yet received)', number_format(count($rows))],
                ['Total Value', report_money(purchase_report_sum($rows, 'total_amount'))],
                ['Past Expected Delivery', number_format($overdue)],
            ];
            $report['columns'] = [
                ['key' => 'po_ref', 'label' => 'PO #', 'w' => 7],
                ['key' => 'order_date', 'label' => 'Order Date', 'w' => 9, 'type' => 'date'],
                ['key' => 'ordered_at', 'label' => 'Placed On', 'w' => 11, 'type' => 'datetime'],
                ['key' => 'supplier_name', 'label' => 'Supplier', 'w' => 15],
                ['key' => 'items', 'label' => 'Items', 'w' => 24],
                ['key' => 'expected_delivery_date', 'label' => 'Expected Delivery', 'w' => 9, 'type' => 'date'],
                ['key' => 'delivery', 'label' => 'Delivery', 'w' => 11],
                ['key' => 'total_amount', 'label' => 'Total (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
                ['key' => 'ordered_by_name', 'label' => 'Ordered By', 'w' => 10],
            ];
            $report['rows'] = $rows;
            $report['totals'] = ['total_amount' => purchase_report_sum($rows, 'total_amount')];
            $report['notes'] = ['Shows orders with status Ordered: placed with the supplier and not yet received.'];
            break;

        // ------------------------------------------------------------------
        case 'cancelled':
            $rows = purchase_report_rows($conn,
                "po.status = 'Cancelled' AND DATE(COALESCE(po.cancelled_at, po.order_date)) BETWEEN ? AND ?" . $supplierWhere,
                'ss' . $supplierTypes, array_merge([$period['start'], $period['end']], $supplierParams), 'po.cancelled_at, po.id');
            $report['summary'] = [
                ['Cancelled Orders', number_format(count($rows))],
                ['Total Value Cancelled', report_money(purchase_report_sum($rows, 'total_amount'))],
            ];
            $report['columns'] = [
                ['key' => 'po_ref', 'label' => 'PO #', 'w' => 7],
                ['key' => 'order_date', 'label' => 'Order Date', 'w' => 9, 'type' => 'date'],
                ['key' => 'supplier_name', 'label' => 'Supplier', 'w' => 14],
                ['key' => 'items', 'label' => 'Items', 'w' => 22],
                ['key' => 'total_amount', 'label' => 'Total (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
                ['key' => 'cancelled_at', 'label' => 'Cancelled On', 'w' => 11, 'type' => 'datetime'],
                ['key' => 'cancelled_by_name', 'label' => 'Cancelled By', 'w' => 10],
                ['key' => 'cancel_reason', 'label' => 'Cancellation Reason', 'w' => 18],
            ];
            $report['rows'] = $rows;
            $report['totals'] = ['total_amount' => purchase_report_sum($rows, 'total_amount')];
            $report['totals_label'] = 'TOTAL CANCELLED';
            $report['notes'] = ['Cancelled orders are listed by the date they were cancelled.'];
            break;
    }

    $report['filters'] = $filters;
    return $report;
}
