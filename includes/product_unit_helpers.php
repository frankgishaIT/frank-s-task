<?php
/**
 * Product Units of Measure. Unit NAMES live in a shared `units` catalog
 * (Pieces, Boxes, Cartons, etc.) used company-wide — a product just picks
 * which ones apply to it and sets its own conversion rate to its base unit.
 * This keeps naming consistent (no "Carton" vs "carton" vs "Ctn" drift)
 * while still letting conversion rates vary product to product.
 *
 * CHANGED (Task 2): units are managed by Admins (Categories > Units). A product can only use a unit
 * from that list (or one it already had), and every conversion is checked here as well as on the forms.
 */
require_once __DIR__ . '/category_helpers.php';

/** Every unit in the shared catalog, for populating a searchable picker. */
function all_units($conn) {
    $result = mysqli_query($conn, 'SELECT id, name FROM units ORDER BY name');
    $list = [];
    while ($row = mysqli_fetch_assoc($result)) { $list[] = $row; }
    return $list;
}

/**
 * Finds a unit by name (case-insensitive) in the shared catalog, adding it there if it is not in the
 * catalog yet. CHANGED: only called for names that passed the managed-units check in
 * save_product_units(), so it can no longer be used to invent new units.
 */
function find_or_create_unit($conn, $name) {
    $name = trim($name);
    if ($name === '') { return null; }

    $statement = mysqli_prepare($conn, 'SELECT id FROM units WHERE LOWER(name) = LOWER(?)');
    mysqli_stmt_bind_param($statement, 's', $name);
    mysqli_stmt_execute($statement);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    if ($existing) {
        return (int) $existing['id'];
    }

    $insert = mysqli_prepare($conn, 'INSERT INTO units (name) VALUES (?)');
    mysqli_stmt_bind_param($insert, 's', $name);
    mysqli_stmt_execute($insert);
    return mysqli_insert_id($conn);
}

/**
 * All units available for a product: its own base unit (from products.unit,
 * conversion rate 1, always first) plus every extra unit defined for it,
 * resolved against the shared catalog.
 */
function product_units_for($conn, $productId) {
    $productStatement = mysqli_prepare($conn, 'SELECT unit FROM products WHERE id = ?');
    mysqli_stmt_bind_param($productStatement, 'i', $productId);
    mysqli_stmt_execute($productStatement);
    $product = mysqli_fetch_assoc(mysqli_stmt_get_result($productStatement));
    $baseUnitName = ($product && $product['unit']) ? $product['unit'] : 'Pieces';

    $units = [['unit_id' => null, 'unit_name' => $baseUnitName, 'pack_size' => 1, 'is_base' => true]];

    $statement = mysqli_prepare($conn, 'SELECT product_units.unit_id, units.name AS unit_name, product_units.conversion_rate
        FROM product_units JOIN units ON product_units.unit_id = units.id
        WHERE product_units.product_id = ? ORDER BY units.name');
    mysqli_stmt_bind_param($statement, 'i', $productId);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    while ($row = mysqli_fetch_assoc($result)) {
        $units[] = ['unit_id' => (int) $row['unit_id'], 'unit_name' => $row['unit_name'], 'pack_size' => (int) $row['conversion_rate'], 'is_base' => false];
    }

    return $units;
}

/** Every product's units in one call, keyed by product_id. */
function product_units_map($conn, array $productIds) {
    $map = [];
    foreach ($productIds as $id) {
        $map[$id] = product_units_for($conn, (int) $id);
    }
    return $map;
}

/**
 * Replaces a product's full set of extra units — called from Add/Edit Product.
 * $units is an array of ['unit_name' => ..., 'pack_size' => ...].
 *
 * CHANGED (Task 2): each unit must be in the Admin-managed Units list (or already be a sub-unit of this
 * product), must not be the base unit, must not repeat, and must convert to a whole number of 2 or more
 * base units. Anything else throws RuntimeException and NOTHING is changed (call inside a transaction).
 * Blank rows are skipped.
 */
function save_product_units($conn, $productId, array $units) {
    $current = product_units_for($conn, $productId);
    $baseUnit = $current[0]['unit_name'];
    $alreadyHad = array_map('mb_strtolower', array_column(array_slice($current, 1), 'unit_name'));
    $allowed = array_map('mb_strtolower', categories_active($conn, 'unit'));

    // 1. Check everything first, so an invalid row never leaves the product half-saved.
    $clean = [];
    $seen = [];
    foreach ($units as $unit) {
        $unitName = trim((string) ($unit['unit_name'] ?? ''));
        $rawSize = $unit['pack_size'] ?? '';
        if ($unitName === '' && ($rawSize === '' || $rawSize === null || (int) $rawSize === 0)) {
            continue; // empty row
        }
        $key = mb_strtolower($unitName);
        $packSize = filter_var($rawSize, FILTER_VALIDATE_INT);
        if (!in_array($key, $allowed, true) && !in_array($key, $alreadyHad, true)) {
            throw new RuntimeException('"' . $unitName . '" is not in the Units list. An Admin can add it under Categories > Units.');
        }
        if ($key === mb_strtolower($baseUnit)) {
            throw new RuntimeException('A sub-unit cannot be the same as the base unit (' . $baseUnit . ').');
        }
        if (isset($seen[$key])) {
            throw new RuntimeException('"' . $unitName . '" is listed twice.');
        }
        if ($packSize === false || $packSize < 2) {
            throw new RuntimeException('"' . $unitName . '" must contain a whole number of 2 or more ' . $baseUnit . '.');
        }
        $seen[$key] = true;
        $clean[] = [$unitName, $packSize];
    }

    // 2. Replace the product's set.
    $delete = mysqli_prepare($conn, 'DELETE FROM product_units WHERE product_id = ?');
    mysqli_stmt_bind_param($delete, 'i', $productId);
    mysqli_stmt_execute($delete);

    foreach ($clean as [$unitName, $packSize]) {
        $unitId = find_or_create_unit($conn, $unitName);
        if (!$unitId) { continue; }
        $insert = mysqli_prepare($conn, 'INSERT INTO product_units (product_id, unit_id, conversion_rate) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE conversion_rate = VALUES(conversion_rate)');
        mysqli_stmt_bind_param($insert, 'iii', $productId, $unitId, $packSize);
        mysqli_stmt_execute($insert);
    }
}