<?php
/**
 * Data layer for the Stock & Inventory reports (RM Offerings module).
 * Same return shape as sales_report_build(), so the shared screen and PDF code render it.
 *
 * Stock rules:
 *  - Only products with item_type = 'Item' hold stock. Services are never listed.
 *  - products.quantity is the stock on hand in the product's base unit (products.unit).
 *  - Purchasing history reads the existing `purchases` table. quantity is in base units and
 *    unit_cost is per base unit, so total cost = quantity x unit_cost, except when the purchase came from a
 *    single-line purchase order, where that line's line_total is used (it is the exact amount, unrounded).
 *  - Low stock = quantity has reached or fallen below the product's reorder level; products with no
 *    level of their own use the default (see includes/stock_rules.php), same as the Purchase Order page.
 *  - Stock value at cost = quantity x buying_price; at selling price = quantity x selling_price.
 */
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/sales_report_queries.php'; // reuses sales_report_fetch()
require_once __DIR__ . '/stock_rules.php';          // the one shared low-stock rule

function stock_report_types(): array
{
    return [
        'current'    => 'Current Stock Report',
        'low_stock'  => 'Low Stock Items',
        'purchasing' => 'Purchasing History',
    ];
}

/** Lists used by the Purchasing History filters. */
function stock_report_lookups(mysqli $conn): array
{
    return [
        'suppliers' => sales_report_fetch($conn, "SELECT id, COALESCE(NULLIF(business_name, ''), NULLIF(name, '')) AS label FROM business_parties WHERE type = 'Supplier' ORDER BY label"),
        'products'  => sales_report_fetch($conn, "SELECT id, product_name, product_code FROM products WHERE item_type = 'Item' ORDER BY product_name"),
    ];
}

function stock_report_build(mysqli $conn, string $type, array $req): array
{
    $types = stock_report_types();
    if (!isset($types[$type])) {
        return ['error' => 'Please choose a report.', 'title' => 'Stock & Inventory Report', 'type' => $type];
    }

    $report = [
        'error' => null, 'type' => $type, 'title' => $types[$type], 'filters' => [],
        'summary' => [], 'columns' => [], 'rows' => [], 'totals' => [], 'totals_label' => 'TOTAL',
        'footer_summary' => [], 'notes' => [],
    ];

    if ($type === 'current') {
        $status = (string) ($req['status'] ?? 'active');
        $level  = (string) ($req['level'] ?? 'all');
        $where  = ["item_type = 'Item'"];
        if ($status === 'active') {
            $where[] = 'is_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'is_active = 0';
        } else {
            $status = 'all';
        }
        if ($level === 'in_stock') {
            $where[] = 'quantity > 0';
        } elseif ($level === 'out_of_stock') {
            $where[] = 'quantity <= 0';
        } else {
            $level = 'all';
        }

        $rows = sales_report_fetch($conn,
            "SELECT product_code, product_name, unit, quantity, buying_price, selling_price,
                    quantity * buying_price AS cost_value, quantity * selling_price AS retail_value, is_active
               FROM products WHERE " . implode(' AND ', $where) . " ORDER BY product_name");
        foreach ($rows as &$r) {
            $r['status_label'] = $r['quantity'] <= 0 ? 'Out of stock' : ($r['is_active'] ? 'In stock' : 'Inactive');
            if ($r['quantity'] > 0 && !$r['is_active']) { $r['status_label'] = 'In stock (inactive)'; }
        }
        unset($r);

        $cost = 0; $retail = 0; $out = 0;
        foreach ($rows as $r) {
            $cost += (float) $r['cost_value'];
            $retail += (float) $r['retail_value'];
            if ($r['quantity'] <= 0) { $out++; }
        }

        $report['filters'] = [
            ['As at', date('d M Y, H:i')],
            ['Products', ['active' => 'Active only', 'inactive' => 'Inactive only', 'all' => 'Active and inactive'][$status]],
            ['Stock Level', ['all' => 'All', 'in_stock' => 'In stock only', 'out_of_stock' => 'Out of stock only'][$level]],
        ];
        $report['summary'] = [
            ['Products Listed', number_format(count($rows))],
            ['Out of Stock', number_format($out)],
            ['Stock Value (at cost)', report_money($cost)],
            ['Stock Value (at selling price)', report_money($retail)],
        ];
        $report['columns'] = [
            ['key' => 'product_code', 'label' => 'Code', 'w' => 9],
            ['key' => 'product_name', 'label' => 'Product', 'w' => 24],
            ['key' => 'unit', 'label' => 'Unit', 'w' => 7],
            ['key' => 'quantity', 'label' => 'Qty on Hand', 'w' => 9, 'align' => 'R', 'type' => 'int'],
            ['key' => 'buying_price', 'label' => 'Buying Price (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
            ['key' => 'cost_value', 'label' => 'Value at Cost (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
            ['key' => 'selling_price', 'label' => 'Selling Price (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
            ['key' => 'retail_value', 'label' => 'Value at Selling Price (RWF)', 'w' => 14, 'align' => 'R', 'type' => 'money'],
            ['key' => 'status_label', 'label' => 'Status', 'w' => 11],
        ];
        $report['rows'] = $rows;
        $report['totals'] = ['cost_value' => $cost, 'retail_value' => $retail];
        $report['notes'] = ['Quantities are shown in each product\'s base unit. Services do not hold stock and are not listed. Totals are not added across units.'];
    }

    if ($type === 'low_stock') {
        $hasCol = sales_report_fetch($conn, "SHOW COLUMNS FROM products LIKE 'reorder_level'");
        if (!$hasCol) {
            return ['error' => 'The reorder level column is missing. Run: ALTER TABLE products ADD COLUMN reorder_level INT NOT NULL DEFAULT 0 AFTER quantity;',
                    'title' => $types[$type], 'type' => $type];
        }
        $status = (string) ($req['status'] ?? 'active');
        $level  = stock_reorder_level_sql();
        $where  = ["item_type = 'Item'", "quantity <= $level"];
        if ($status === 'inactive') {
            $where[] = 'is_active = 0';
        } elseif ($status === 'all') {
            // no extra condition
        } else {
            $status = 'active';
            $where[] = 'is_active = 1';
        }
        $rows = sales_report_fetch($conn,
            "SELECT product_code, product_name, unit, quantity, reorder_level, buying_price,
                    $level AS level_used,
                    GREATEST($level - quantity, 0) AS shortfall,
                    GREATEST($level - quantity, 0) * buying_price AS restock_cost
               FROM products WHERE " . implode(' AND ', $where) . "
              ORDER BY (quantity - $level), product_name");
        $out = 0; $restock = 0; $defaults = 0;
        foreach ($rows as &$r) {
            if ($r['quantity'] <= 0) {
                $r['status_label'] = 'Out of stock'; $out++;
            } elseif ($r['quantity'] < $r['level_used']) {
                $r['status_label'] = 'Below reorder level';
            } else {
                $r['status_label'] = 'At reorder level';
            }
            $r['level_source'] = (int) $r['reorder_level'] > 0 ? 'Product' : 'Default';
            if ((int) $r['reorder_level'] <= 0) { $defaults++; }
            $restock += (float) $r['restock_cost'];
        }
        unset($r);

        $report['filters'] = [
            ['As at', date('d M Y, H:i')],
            ['Products', ['active' => 'Active only', 'inactive' => 'Inactive only', 'all' => 'Active and inactive'][$status]],
        ];
        $report['summary'] = [
            ['Low Stock Items', number_format(count($rows))],
            ['Out of Stock', number_format($out)],
            ['Cost to Restock to Reorder Level', report_money($restock)],
        ];
        $report['columns'] = [
            ['key' => 'product_code', 'label' => 'Code', 'w' => 8],
            ['key' => 'product_name', 'label' => 'Product', 'w' => 21],
            ['key' => 'unit', 'label' => 'Unit', 'w' => 6],
            ['key' => 'quantity', 'label' => 'Qty on Hand', 'w' => 8, 'align' => 'R', 'type' => 'int'],
            ['key' => 'level_used', 'label' => 'Reorder Level', 'w' => 8, 'align' => 'R', 'type' => 'int'],
            ['key' => 'level_source', 'label' => 'Level Set By', 'w' => 8],
            ['key' => 'shortfall', 'label' => 'Below Level By', 'w' => 8, 'align' => 'R', 'type' => 'int'],
            ['key' => 'buying_price', 'label' => 'Buying Price (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
            ['key' => 'restock_cost', 'label' => 'Cost to Reach Level (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
            ['key' => 'status_label', 'label' => 'Status', 'w' => 11],
        ];
        $report['rows'] = $rows;
        $report['totals'] = ['restock_cost' => $restock];
        $report['notes'] = [
            'An item is listed when its quantity has reached or fallen below its reorder level. Quantities are in each product\'s base unit.',
            'Products without a reorder level of their own use the default of ' . STOCK_DEFAULT_REORDER_LEVEL . ' (' . number_format($defaults) . ' listed item(s) use it). This is the same rule the Purchase Order page uses.',
        ];
    }

    if ($type === 'purchasing') {
        $period = report_period_from_request($req);
        if ($period['error']) {
            return ['error' => $period['error'], 'title' => $types[$type], 'type' => $type];
        }
        $lookups = stock_report_lookups($conn);
        $filters = [['Period', $period['label']]];
        $where = ['pu.purchase_date BETWEEN ? AND ?'];
        $bt = 'ss';
        $params = [$period['start'], $period['end']];

        $sid = (int) ($req['supplier'] ?? 0);
        if ($sid > 0) {
            $label = null;
            foreach ($lookups['suppliers'] as $sp) {
                if ((int) $sp['id'] === $sid) { $label = $sp['label']; }
            }
            if ($label === null) {
                return ['error' => 'That supplier was not found.', 'title' => $types[$type], 'type' => $type];
            }
            $where[] = 'pu.supplier_party_id = ?';
            $bt .= 'i';
            $params[] = $sid;
            $filters[] = ['Supplier', $label];
        } else {
            $filters[] = ['Supplier', 'All suppliers'];
        }
        $pid = (int) ($req['product_id'] ?? 0);
        if ($pid > 0) {
            $name = null;
            foreach ($lookups['products'] as $pr) {
                if ((int) $pr['id'] === $pid) { $name = $pr['product_name'] . ' (' . $pr['product_code'] . ')'; }
            }
            if ($name === null) {
                return ['error' => 'That product was not found.', 'title' => $types[$type], 'type' => $type];
            }
            $where[] = 'pu.product_id = ?';
            $bt .= 'i';
            $params[] = $pid;
            $filters[] = ['Product', $name];
        } else {
            $filters[] = ['Product', 'All products'];
        }

        $rows = sales_report_fetch($conn,
            "SELECT pu.id, pu.purchase_date, pu.quantity, pu.unit_cost, pu.pack_label, pu.pack_size, pu.pack_quantity,
                    pu.purchase_order_id, p.product_code, p.product_name, p.unit,
                    COALESCE(NULLIF(bp.business_name, ''), NULLIF(bp.name, ''), pu.supplier) AS supplier_name,
                    u.names AS recorded_by_name,
                    COALESCE(
                        (SELECT IF(COUNT(*) = 1, SUM(poi.line_total), NULL) FROM purchase_order_items poi
                          WHERE poi.purchase_order_id = pu.purchase_order_id AND poi.product_id = pu.product_id),
                        pu.quantity * pu.unit_cost) AS total_cost
               FROM purchases pu
               LEFT JOIN products p ON p.id = pu.product_id
               LEFT JOIN business_parties bp ON bp.id = pu.supplier_party_id
               LEFT JOIN users u ON u.id = pu.recorded_by
              WHERE " . implode(' AND ', $where) . " ORDER BY pu.purchase_date, pu.id", $bt, $params);

        $total = 0.0; $bySupplier = []; $suppliers = []; $productsSeen = [];
        foreach ($rows as &$r) {
            $r['product'] = trim(($r['product_code'] ? $r['product_code'] . ' - ' : '') . ($r['product_name'] ?? 'Unknown product'));
            $r['source'] = $r['purchase_order_id'] ? 'PO-' . str_pad((string) $r['purchase_order_id'], 5, '0', STR_PAD_LEFT) : 'Direct purchase';
            $label = trim((string) $r['pack_label']);
            $hasLabel = $label !== '' && $label !== '0';
            $r['pack'] = ((int) $r['pack_size'] > 1 && ((int) $r['pack_quantity'] > 1 || $hasLabel))
                ? (int) $r['pack_quantity'] . ' x ' . (int) $r['pack_size'] . ($hasLabel ? ' (' . $label . ')' : '')
                : '';
            $total += (float) $r['total_cost'];
            $key = $r['supplier_name'] ?: 'Unknown supplier';
            $bySupplier[$key] = ($bySupplier[$key] ?? 0) + (float) $r['total_cost'];
            $suppliers[$key] = true;
            $productsSeen[$r['product_code'] . '|' . $r['product_name']] = true;
        }
        unset($r);
        arsort($bySupplier);

        $report['filters'] = $filters;
        $report['summary'] = [
            ['Purchases', number_format(count($rows))],
            ['Total Cost', report_money($total)],
            ['Suppliers', number_format(count($suppliers))],
            ['Products', number_format(count($productsSeen))],
        ];
        $report['columns'] = [
            ['key' => 'purchase_date', 'label' => 'Date', 'w' => 9, 'type' => 'date'],
            ['key' => 'product', 'label' => 'Product', 'w' => 20],
            ['key' => 'supplier_name', 'label' => 'Supplier', 'w' => 13],
            ['key' => 'source', 'label' => 'Source', 'w' => 9],
            ['key' => 'pack', 'label' => 'Packs', 'w' => 9],
            ['key' => 'quantity', 'label' => 'Qty (base units)', 'w' => 8, 'align' => 'R', 'type' => 'int'],
            ['key' => 'unit', 'label' => 'Unit', 'w' => 6],
            ['key' => 'unit_cost', 'label' => 'Unit Cost (RWF)', 'w' => 9, 'align' => 'R', 'type' => 'money'],
            ['key' => 'total_cost', 'label' => 'Total Cost (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
            ['key' => 'recorded_by_name', 'label' => 'Recorded By', 'w' => 10],
        ];
        $report['rows'] = $rows;
        $report['totals'] = ['total_cost' => $total];
        $i = 0;
        foreach ($bySupplier as $name => $sum) {
            if (++$i > 8) { break; }
            $report['footer_summary'][] = ['Spent with ' . $name, report_money($sum)];
        }
        $report['notes'] = [
            'Quantities are in base units and unit cost is per base unit. Total cost is the purchase order line total when the purchase came from a single-line order, otherwise quantity x unit cost (unit cost is stored to 2 decimals, so the total can differ from the amount paid by a few francs).',
        ];
    }
    return $report;
}
