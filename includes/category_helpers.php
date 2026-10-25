<?php
/**
 * NEW FILE: includes/category_helpers.php
 * Administrator-managed categories (Task 3). One table for every category list in MY MOTIVE.
 *   - Only Admins create, rename, activate and deactivate categories (modules/categories/).
 *   - Forms offer ACTIVE categories only, and the server checks the choice (it cannot be typed or sent by hand).
 *   - Existing records keep their category: every value already used is added to the list automatically,
 *     a rename updates the records that use it, and a deactivated category stays on old records.
 * The table is created and filled automatically the first time it is needed.
 */

/** group key => [label, table, column] (table/column = where records store the category name). */
function category_groups(): array
{
    return [
        'expense'    => ['Expense Categories', 'transactions', 'expense_category'],
        'asset_type' => ['Asset Types', 'assets', 'asset_type'],
        'leave_type' => ['Leave Types', 'leave_requests', 'leave_type'],
        'product'    => ['Product Categories', 'products', 'category'],
        'service'    => ['Service Categories', 'products', 'category'],
        // NEW (Task 2): units of measure (base units and sub-units of products)
        'unit'       => ['Units', 'products', 'unit'],
    ];
}

/** The lists used until now: they become the starting categories. */
function category_defaults(): array
{
    return [
        'expense' => ['Rent', 'Electricity', 'Water', 'Internet', 'Telephone', 'Other utilities', 'Transport', 'Marketing',
                      'Office Expenses', 'Bank Charges', 'Taxes', 'Maintenance', 'Communication', 'Staff training',
                      'Learning materials', 'Employee development', 'Team building', 'Employee recognition'],
        'asset_type' => ['Vehicle', 'Equipment', 'Furniture', 'Computer & IT', 'Building', 'Land', 'Machinery', 'Other'],
        'leave_type' => ['Annual', 'Sick', 'Emergency', 'Unpaid', 'Short Leave', 'Other'],
        'product' => [],
        'service' => [],
        'unit' => ['Piece', 'Kilogram', 'Gram', 'Packet', 'Ream', 'Pack', 'Roll', 'Box', 'Carton', 'Bundle', 'Litre', 'Metre'],
    ];
}

function category_column_info(mysqli $conn, string $table, string $column): ?array
{
    $s = mysqli_prepare($conn, 'SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    mysqli_stmt_bind_param($s, 'ss', $table, $column);
    mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

/**
 * Creates the table if needed and makes sure every category already used by a record is in the list
 * (so no existing record ever loses its category). Runs once per page load.
 */
function category_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;

    // CHANGED: own table name (rm_categories). Some databases already have a table called
    // "categories" used by something else; it is never touched.
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS rm_categories (
        id INT(11) NOT NULL AUTO_INCREMENT,
        group_key VARCHAR(40) NOT NULL,
        name VARCHAR(100) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY group_name (group_key, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // NEW (Task 2): products get a category column (Product / Service Categories).
    if (category_column_info($conn, 'products', 'item_type') && !category_column_info($conn, 'products', 'category')) {
        mysqli_query($conn, 'ALTER TABLE products ADD COLUMN category VARCHAR(100) NULL');
    }

    $ins = mysqli_prepare($conn, 'INSERT IGNORE INTO rm_categories (group_key, name) VALUES (?, ?)');
    foreach (category_groups() as $key => [$label, $table, $column]) {
        $count = mysqli_prepare($conn, 'SELECT COUNT(*) FROM rm_categories WHERE group_key = ?');
        mysqli_stmt_bind_param($count, 's', $key);
        mysqli_stmt_execute($count);
        $empty = (int) mysqli_fetch_row(mysqli_stmt_get_result($count))[0] === 0;
        $names = $empty ? category_defaults()[$key] : [];

        if ($key === 'product' || $key === 'service') {
            // Product categories come from products, service categories from services.
            if (category_column_info($conn, 'products', 'category')) {
                $t = $key === 'service' ? 'Service' : 'Item';
                $r = mysqli_prepare($conn, "SELECT DISTINCT category FROM products WHERE item_type = ? AND category IS NOT NULL AND category <> ''");
                mysqli_stmt_bind_param($r, 's', $t);
                mysqli_stmt_execute($r);
                foreach (mysqli_fetch_all(mysqli_stmt_get_result($r), MYSQLI_NUM) as $row) { $names[] = $row[0]; }
            }
        } elseif ($key === 'unit') {
            // A fixed list (ENUM, e.g. only Pieces / Boxes) would refuse new units: it becomes plain text.
            if (($pi = category_column_info($conn, 'products', 'unit')) && strtolower($pi['DATA_TYPE']) === 'enum') {
                mysqli_query($conn, "ALTER TABLE products MODIFY unit VARCHAR(100) NOT NULL DEFAULT 'Pieces'");
            }
            // Base units of products, sub-units (product_units) and the old Units catalog: all kept.
            foreach ([['products', 'unit'], ['product_units', 'unit_name'], ['units', 'name']] as [$t, $c]) {
                if (category_column_info($conn, $t, $c)) {
                    $r = mysqli_query($conn, "SELECT DISTINCT `$c` FROM `$t` WHERE `$c` IS NOT NULL AND `$c` <> ''");
                    foreach (mysqli_fetch_all($r, MYSQLI_NUM) as $row) { $names[] = $row[0]; }
                }
            }
        } elseif ($table && ($info = category_column_info($conn, $table, $column))) {
            // A fixed list in the database (ENUM) would refuse new categories: it becomes plain text.
            // Every existing value is kept exactly as it is.
            if (strtolower($info['DATA_TYPE']) === 'enum') {
                mysqli_query($conn, "ALTER TABLE `$table` MODIFY `$column` VARCHAR(100) " . ($column === 'expense_category' ? 'NULL' : 'NOT NULL'));
            }
            $res = mysqli_query($conn, "SELECT DISTINCT `$column` FROM `$table` WHERE `$column` IS NOT NULL AND `$column` <> ''");
            foreach (mysqli_fetch_all($res, MYSQLI_NUM) as $r) { $names[] = $r[0]; }
        }
        foreach (array_unique($names) as $name) {
            $name = mb_substr(trim($name), 0, 100);
            if ($name === '') { continue; }
            mysqli_stmt_bind_param($ins, 'ss', $key, $name);
            mysqli_stmt_execute($ins);
        }
    }
}

/** Active category names of a group, for a dropdown. $keep = a record's current value, shown even if deactivated. */
function categories_active(mysqli $conn, string $group, ?string $keep = null): array
{
    category_ensure($conn);
    $s = mysqli_prepare($conn, 'SELECT name FROM rm_categories WHERE group_key = ? AND is_active = 1 ORDER BY name');
    mysqli_stmt_bind_param($s, 's', $group);
    mysqli_stmt_execute($s);
    $names = array_column(mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC), 'name');
    if ($keep !== null && $keep !== '' && !in_array($keep, $names, true)) { array_unshift($names, $keep); }
    return $names;
}

/** Server-side check: an active category of the group (or the record's own current value, when editing). */
function category_is_valid(mysqli $conn, string $group, string $name, ?string $current = null): bool
{
    if ($name === '') { return false; }
    if ($current !== null && $name === $current) { return true; }
    return in_array($name, categories_active($conn, $group), true);
}

/** How many records use each category of a group. */
function category_usage(mysqli $conn, string $group): array
{
    if ($group === 'product' || $group === 'service') {
        if (!category_column_info($conn, 'products', 'category')) { return []; }
        $t = $group === 'service' ? 'Service' : 'Item';
        $s = mysqli_prepare($conn, 'SELECT category AS n, COUNT(*) AS c FROM products WHERE item_type = ? AND category IS NOT NULL GROUP BY category');
        mysqli_stmt_bind_param($s, 's', $t);
        mysqli_stmt_execute($s);
        $out = [];
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC) as $r) { $out[$r['n']] = (int) $r['c']; }
        return $out;
    }
    [$label, $table, $column] = category_groups()[$group];
    if (!$table || !category_column_info($conn, $table, $column)) { return []; }
    $res = mysqli_query($conn, "SELECT `$column` AS n, COUNT(*) AS c FROM `$table` WHERE `$column` IS NOT NULL GROUP BY `$column`");
    $out = [];
    foreach (mysqli_fetch_all($res, MYSQLI_ASSOC) as $r) { $out[$r['n']] = (int) $r['c']; }
    return $out;
}

/** Renames a category AND the records that use it (one transaction), so no record loses its category. */
function category_rename(mysqli $conn, int $id, string $newName): void
{
    $newName = mb_substr(trim($newName), 0, 100);
    if ($newName === '') { throw new RuntimeException('Please enter a name.'); }
    mysqli_begin_transaction($conn);
    try {
        $s = mysqli_prepare($conn, 'SELECT * FROM rm_categories WHERE id = ? FOR UPDATE');
        mysqli_stmt_bind_param($s, 'i', $id);
        mysqli_stmt_execute($s);
        $cat = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if (!$cat) { throw new RuntimeException('Category not found.'); }
        if ($cat['name'] === $newName) { mysqli_rollback($conn); return; }

        $d = mysqli_prepare($conn, 'SELECT COUNT(*) FROM rm_categories WHERE group_key = ? AND name = ? AND id <> ?');
        mysqli_stmt_bind_param($d, 'ssi', $cat['group_key'], $newName, $id);
        mysqli_stmt_execute($d);
        if ((int) mysqli_fetch_row(mysqli_stmt_get_result($d))[0] > 0) { throw new RuntimeException('A category with that name already exists in this list.'); }

        $u = mysqli_prepare($conn, 'UPDATE rm_categories SET name = ? WHERE id = ?');
        mysqli_stmt_bind_param($u, 'si', $newName, $id);
        mysqli_stmt_execute($u);

        $targets = [];
        if ($cat['group_key'] === 'product' || $cat['group_key'] === 'service') {
            $t = $cat['group_key'] === 'service' ? 'Service' : 'Item';
            $r = mysqli_prepare($conn, 'UPDATE products SET category = ? WHERE category = ? AND item_type = ?');
            mysqli_stmt_bind_param($r, 'sss', $newName, $cat['name'], $t);
            mysqli_stmt_execute($r);
        } elseif ($cat['group_key'] === 'unit') {
            // A unit name is used as base unit, as sub-unit and in the old Units catalog.
            $targets = [['products', 'unit'], ['product_units', 'unit_name'], ['units', 'name']];
        } else {
            [$label, $table, $column] = category_groups()[$cat['group_key']] ?? [null, null, null];
            if ($table) { $targets = [[$table, $column]]; }
        }
        foreach ($targets as [$table, $column]) {
            if (category_column_info($conn, $table, $column)) {
                $r = mysqli_prepare($conn, "UPDATE `$table` SET `$column` = ? WHERE `$column` = ?");
                mysqli_stmt_bind_param($r, 'ss', $newName, $cat['name']);
                mysqli_stmt_execute($r);
            }
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}