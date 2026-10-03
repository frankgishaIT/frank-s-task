<?php
/**
 * Data layer for the Stock & Inventory reports (RM Offerings module).
 * Same return shape as sales_report_build(), so the shared screen and PDF code render it.
 *
 * Stock rules:
 *  - Only products with item_type = 'Item' hold stock. Services are never listed.
 *  - products.quantity is the stock on hand in the product's base unit (products.unit).
 *  - Low stock = quantity has reached or fallen below products.reorder_level.
 *    Products whose reorder_level is 0 (not set) are not monitored.
 *  - Stock value at cost = quantity x buying_price; at selling price = quantity x selling_price.
 */
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/sales_report_queries.php'; // reuses sales_report_fetch()

function stock_report_types(): array
{
    return [
        'current'   => 'Current Stock Report',
        'low_stock' => 'Low Stock Items',
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
        $where  = ["item_type = 'Item'", 'reorder_level > 0', 'quantity <= reorder_level'];
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
                    GREATEST(reorder_level - quantity, 0) AS shortfall,
                    GREATEST(reorder_level - quantity, 0) * buying_price AS restock_cost
               FROM products WHERE " . implode(' AND ', $where) . "
              ORDER BY (quantity - reorder_level), product_name");
        $out = 0; $restock = 0;
        foreach ($rows as &$r) {
            if ($r['quantity'] <= 0) {
                $r['status_label'] = 'Out of stock'; $out++;
            } elseif ($r['quantity'] < $r['reorder_level']) {
                $r['status_label'] = 'Below reorder level';
            } else {
                $r['status_label'] = 'At reorder level';
            }
            $restock += (float) $r['restock_cost'];
        }
        unset($r);
        $unset = sales_report_fetch($conn, "SELECT COUNT(*) AS c FROM products WHERE item_type = 'Item' AND is_active = 1 AND reorder_level = 0");

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
            ['key' => 'product_code', 'label' => 'Code', 'w' => 9],
            ['key' => 'product_name', 'label' => 'Product', 'w' => 24],
            ['key' => 'unit', 'label' => 'Unit', 'w' => 7],
            ['key' => 'quantity', 'label' => 'Qty on Hand', 'w' => 9, 'align' => 'R', 'type' => 'int'],
            ['key' => 'reorder_level', 'label' => 'Reorder Level', 'w' => 9, 'align' => 'R', 'type' => 'int'],
            ['key' => 'shortfall', 'label' => 'Below Level By', 'w' => 9, 'align' => 'R', 'type' => 'int'],
            ['key' => 'buying_price', 'label' => 'Buying Price (RWF)', 'w' => 12, 'align' => 'R', 'type' => 'money'],
            ['key' => 'restock_cost', 'label' => 'Cost to Reach Level (RWF)', 'w' => 14, 'align' => 'R', 'type' => 'money'],
            ['key' => 'status_label', 'label' => 'Status', 'w' => 12],
        ];
        $report['rows'] = $rows;
        $report['totals'] = ['restock_cost' => $restock];
        $report['notes'] = [
            'An item is listed when its quantity has reached or fallen below its reorder level. Quantities are in each product\'s base unit.',
            number_format((int) $unset[0]['c']) . ' active item(s) have no reorder level set and are not monitored.',
        ];
    }
    return $report;
}
