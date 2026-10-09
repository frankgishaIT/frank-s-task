<?php
/**
 * ONE place for the company's profit rules. Every report that shows profit
 * (Sales reports, and later Gross Profit, Net Profit, Profit & Loss, Profit Margin)
 * must use these, so the numbers agree everywhere.
 *
 * Rules:
 *  - Product line: profit = net amount - (quantity x pack_size x cost per base unit).
 *    Cost per base unit = sale_items.unit_cost (frozen when the sale was finalized),
 *    or products.buying_price for older sales that have no frozen cost.
 *  - Service line: profit = SALES_SERVICE_PROFIT_RATE (80%) of the net amount.
 *  - "Net amount" of a line = line_total minus that line's pro-rata share of the sale discount.
 */

/**
 * Share of a SERVICE's net amount that counts as profit. Services have no purchase cost,
 * so the remaining share is treated as the cost of delivering the service.
 * 0.80 = 80% profit. Change it here and every Sales report follows.
 */
const SALES_SERVICE_PROFIT_RATE = 0.80;

/** SQL fragment: net amount of one sale line after its share of the sale discount. $s = sales alias, $si = sale_items alias. */
function sales_report_line_net_sql(string $s = 's', string $si = 'si'): string
{
    return "($si.line_total - IF($s.subtotal > 0, $s.discount_amount * $si.line_total / $s.subtotal, 0))";
}

/** SQL fragment: cost of one sale line. Products: frozen unit cost (or live buying price for old sales) x base units. Services: the non-profit share of the net amount. */
function sales_report_line_cost_sql(string $s = 's', string $si = 'si', string $p = 'p'): string
{
    $svcCost = number_format(1 - SALES_SERVICE_PROFIT_RATE, 4, '.', '');
    return "IF($si.item_type = 'Service', $svcCost * " . sales_report_line_net_sql($s, $si)
        . ", $si.quantity * COALESCE(NULLIF($si.pack_size, 0), 1) * COALESCE($si.unit_cost, $p.buying_price, 0))";
}

function sales_report_types(): array
{
    return [
        'product'       => 'Sales by Product/Service',
        'customer'      => 'Sales by Customer',
        'employee'      => 'Sales by Employee',
        'payment'       => 'Sales by Payment Method',
        'cancellations' => 'Sales Cancellations',
        'summary'       => 'Sales Summary',
    ];
}