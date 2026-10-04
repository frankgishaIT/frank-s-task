<?php
/**
 * ONE definition of "low stock" for the whole system.
 *
 * A product is low on stock when quantity <= its reorder level.
 *  - If the product has its own reorder level (products.reorder_level > 0), that is used.
 *  - Otherwise the default below is used (the same number the Purchase Order page always used).
 *
 * Used by: the Low Stock Items report, the Purchase Order low-stock banner / "Add All Low-Stock Items".
 */
const STOCK_DEFAULT_REORDER_LEVEL = 5;

/** SQL expression for a product's effective reorder level. $alias = table alias with trailing dot, e.g. 'p.' */
function stock_reorder_level_sql(string $alias = ''): string
{
    return "IF({$alias}reorder_level > 0, {$alias}reorder_level, " . STOCK_DEFAULT_REORDER_LEVEL . ")";
}
