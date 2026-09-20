<?php
/**
 * Product Units of Measure. Unit NAMES live in a shared `units` catalog
 * (Pieces, Boxes, Cartons, etc.) used company-wide — a product just picks
 * which ones apply to it and sets its own conversion rate to its base unit.
 * This keeps naming consistent (no "Carton" vs "carton" vs "Ctn" drift)
 * while still letting conversion rates vary product to product.
 */

/** Every unit in the shared catalog, for populating a searchable picker. */
function all_units($conn) {
    $result = mysqli_query($conn, 'SELECT id, name FROM units ORDER BY name');
    $list = [];
    while ($row = mysqli_fetch_assoc($result)) { $list[] = $row; }
    return $list;
}

/**
 * Finds a unit by name (case-insensitive) or creates it if it doesn't
 * exist yet — this is what powers "+ Create new unit" inline instead of
 * requiring a separate admin screen just to add "Sacks" to the list.
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
 * Replaces a product's full set of extra units — called from Add/Edit Item.
 * $units is an array of ['unit_name' => ..., 'pack_size' => ...]; each name
 * is resolved against the shared catalog (created there if it's new).
 * Blank names or a pack_size <= 1 are skipped.
 */
function save_product_units($conn, $productId, array $units) {
    $delete = mysqli_prepare($conn, 'DELETE FROM product_units WHERE product_id = ?');
    mysqli_stmt_bind_param($delete, 'i', $productId);
    mysqli_stmt_execute($delete);

    foreach ($units as $unit) {
        $unitName = trim($unit['unit_name'] ?? '');
        $packSize = (int) ($unit['pack_size'] ?? 0);
        if ($unitName === '' || $packSize <= 1) {
            continue;
        }
        $unitId = find_or_create_unit($conn, $unitName);
        if (!$unitId) { continue; }

        $insert = mysqli_prepare($conn, 'INSERT INTO product_units (product_id, unit_id, conversion_rate) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE conversion_rate = VALUES(conversion_rate)');
        mysqli_stmt_bind_param($insert, 'iii', $productId, $unitId, $packSize);
        mysqli_stmt_execute($insert);
    }
}