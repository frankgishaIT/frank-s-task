<?php
session_start();
require '../../config/db.php';
require '../../includes/product_unit_helpers.php';
require_once '../../includes/category_helpers.php'; // NEW (Task 2): managed units and categories

// CHANGED: problems are shown as errors (they were shown as success messages), and messages are encoded.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid product selected.')); exit; }

// Admin-only action
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?error=' . urlencode('You do not have permission to edit products or services.'));
    exit;
}

$productStatement = mysqli_prepare($conn, 'SELECT * FROM products WHERE id = ?');
mysqli_stmt_bind_param($productStatement, 'i', $id);
mysqli_stmt_execute($productStatement);
$product = mysqli_fetch_assoc(mysqli_stmt_get_result($productStatement));
if (!$product) { header('Location: index.php?error=' . urlencode('Product not found.')); exit; }

$isServiceNow = $product['item_type'] === 'Service';
$stock = (int) $product['quantity'];

// NEW (Task 2): what may change without breaking stock figures.
//   - Quantity: never typed over here (it changes through Restock, sales and purchase orders).
//   - Base unit and buying price: only while the stock is 0 (otherwise the stock would mean
//     another amount, or change value, with no record).
//   - Product <-> Service: only with no stock and no sales or purchase orders.
$usedCount = 0;
foreach ([['sale_items', 'product_id'], ['purchase_order_items', 'product_id'], ['purchases', 'product_id']] as [$t, $c]) {
    if (category_column_info($conn, $t, $c)) {
        $q = mysqli_prepare($conn, "SELECT COUNT(*) FROM `$t` WHERE `$c` = ?");
        mysqli_stmt_bind_param($q, 'i', $id);
        mysqli_stmt_execute($q);
        $usedCount += (int) mysqli_fetch_row(mysqli_stmt_get_result($q))[0];
    }
}
$canChangeUnitAndCost = $stock === 0;
$canChangeType = $stock === 0 && $usedCount === 0;

// Lists managed by Admins under Categories (the product's own values stay available even if deactivated).
$unitList = categories_active($conn, 'unit', $isServiceNow ? null : $product['unit']);
$productCategories = categories_active($conn, 'product', $isServiceNow ? null : ($product['category'] ?? null));
$serviceCategories = categories_active($conn, 'service', $isServiceNow ? ($product['category'] ?? null) : null);
$existingUnits = array_values(array_filter(product_units_for($conn, $id), function ($u) { return !$u['is_base']; }));
$existingUnitNames = array_column($existingUnits, 'unit_name');
$unitRows = array_map(function ($u) { return ['unit_name' => $u['unit_name'], 'pack_size' => (string) $u['pack_size']]; }, $existingUnits);

if (isset($_POST['update'])) {
    $itemType = in_array($_POST['item_type'] ?? '', ['Item', 'Service'], true) ? $_POST['item_type'] : $product['item_type'];
    if (!$canChangeType) { $itemType = $product['item_type']; } // the server enforces the lock too
    $product_name = trim($_POST['product_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');

    // Always the per-BASE-UNIT price — the JS converts whatever unit the admin typed the number in.
    $selling_price = filter_input(INPUT_POST, 'selling_price', FILTER_VALIDATE_FLOAT);

    $unitRows = [];
    foreach (($_POST['unit_name'] ?? []) as $i => $n) {
        $unitRows[] = ['unit_name' => trim((string) $n), 'pack_size' => trim((string) (($_POST['unit_pack_size'] ?? [])[$i] ?? ''))];
    }

    if ($itemType === 'Item') {
        $quantity = $stock; // CHANGED: never typed over here
        $unit = $canChangeUnitAndCost ? trim($_POST['unit'] ?? '') : $product['unit'];
        $buying_price = $canChangeUnitAndCost ? filter_input(INPUT_POST, 'buying_price', FILTER_VALIDATE_FLOAT) : (float) $product['buying_price'];
        $reorderLevel = filter_input(INPUT_POST, 'reorder_level', FILTER_VALIDATE_INT);
        $reorderLevel = ($reorderLevel === false || $reorderLevel === null || $reorderLevel < 0) ? 0 : $reorderLevel;
    } else {
        $buying_price = 0.00;
        $quantity = 0;
        $unit = 'Pieces';
        $reorderLevel = 0;
        $unitRows = [];
    }

    // Every sub-unit and conversion is checked on the server (a sub-unit this product already had
    // stays allowed even if the unit was deactivated since).
    $unitsToSave = [];
    $unitError = null;
    if ($itemType === 'Item') {
        $seen = [];
        foreach ($unitRows as $row) {
            if ($row['unit_name'] === '' && $row['pack_size'] === '') { continue; }
            $size = filter_var($row['pack_size'], FILTER_VALIDATE_INT);
            $known = in_array($row['unit_name'], $existingUnitNames, true) || category_is_valid($conn, 'unit', $row['unit_name']);
            if (!$known) { $unitError = 'Choose each sub-unit from the Units list.'; break; }
            if ($row['unit_name'] === $unit) { $unitError = 'A sub-unit cannot be the same as the base unit (' . $unit . ').'; break; }
            if (isset($seen[$row['unit_name']])) { $unitError = '"' . $row['unit_name'] . '" is listed twice.'; break; }
            if ($size === false || $size < 2) { $unitError = 'For "' . $row['unit_name'] . '", enter how many ' . $unit . ' it contains (a whole number of 2 or more).'; break; }
            $seen[$row['unit_name']] = true;
            $unitsToSave[] = ['unit_name' => $row['unit_name'], 'pack_size' => $size];
        }
    }
    $catGroup = $itemType === 'Service' ? 'service' : 'product';
    $currentCategory = $itemType === $product['item_type'] ? ($product['category'] ?? null) : null;

    if ($product_name === '' || $selling_price === false || $selling_price === null || $selling_price < 0
        || ($itemType === 'Item' && ($buying_price === false || $buying_price === null || $buying_price < 0))) {
        $error = 'Please provide valid details (name and prices).';
    } elseif ($itemType === 'Item' && !category_is_valid($conn, 'unit', $unit, $product['unit'])) {
        $error = 'Please choose the Base Unit from the list.';
    } elseif ($unitError) {
        $error = $unitError;
    } elseif ($category === '' || !category_is_valid($conn, $catGroup, $category, $currentCategory)) {
        $error = 'Please choose the ' . ($itemType === 'Service' ? 'Service' : 'Product') . ' Category from the list.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            $statement = mysqli_prepare($conn, "UPDATE products SET
                item_type = ?, product_name = ?, description = ?, category = ?,
                buying_price = ?, selling_price = ?, quantity = ?, unit = ?, reorder_level = ? WHERE id = ?");
            mysqli_stmt_bind_param($statement, 'ssssddisii', $itemType, $product_name, $description, $category, $buying_price, $selling_price, $quantity, $unit, $reorderLevel, $id);
            mysqli_stmt_execute($statement);

            // Product Units: the full set is replaced with what was submitted. Services have none.
            save_product_units($conn, $id, $itemType === 'Item' ? $unitsToSave : []);
            mysqli_commit($conn);
            header('Location: index.php?success=' . urlencode(($itemType === 'Service' ? 'Service' : 'Product') . ' updated successfully.'));
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('product edit failed: ' . $e->getMessage());
            $error = 'Unable to save. Nothing was changed. [Technical detail for Admin: ' . $e->getMessage() . ']';
        }
    }
    $product = array_merge($product, ['item_type' => $itemType, 'product_name' => $product_name, 'description' => $description, 'category' => $category,
        'buying_price' => $buying_price, 'selling_price' => $selling_price, 'unit' => $unit, 'reorder_level' => $reorderLevel]);
}

$modal_icon = 'bi-box-seam';
$modal_title = 'Edit Product or Service';
$modal_subtitle = 'Update these catalog details.';
include '../../includes/header.php';
include '../../includes/sidebar.php';
$h = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<div class="rm-modal-backdrop">
    <div class="rm-modal">
        <?php include '../../includes/model_header.php'; ?>

        <div class="rm-modal-body">
            <?php if (isset($error)) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= $h($error); ?>
            </div>
            <?php } ?>

            <form method="POST" id="productForm">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold text-muted">Type</label>
                        <select name="item_type" id="itemTypeSelect" class="form-select rm-input" required <?= $canChangeType ? '' : 'disabled'; ?>>
                            <option value="Item" <?= $product['item_type'] === 'Item' ? 'selected' : ''; ?>>Product (physical, kept in stock)</option>
                            <option value="Service" <?= $product['item_type'] === 'Service' ? 'selected' : ''; ?>>Service (no stock)</option>
                        </select>
                        <?php if (!$canChangeType) { ?>
                            <input type="hidden" name="item_type" value="<?= $h($product['item_type']); ?>">
                            <div class="form-text">Locked: it has stock, sales or purchase orders.</div>
                        <?php } ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold text-muted" id="categoryLabel">Product Category</label>
                        <select name="category" id="categorySelect" class="form-select rm-input" required></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold text-muted">Code</label>
                        <input type="text" class="form-control rm-input" value="<?= $h($product['product_code']); ?>" disabled>
                        <div class="form-text">Generated automatically; cannot be changed.</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted" id="nameLabel">Product Name</label>
                    <input type="text" name="product_name" class="form-control rm-input" value="<?= $h($product['product_name']); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Description</label>
                    <textarea name="description" class="form-control rm-input" rows="3" style="height:auto;"><?= $h($product['description'] ?? ''); ?></textarea>
                </div>

                <div class="row g-3 mb-2">
                    <div class="col-md-4 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Base Unit</label>
                        <select name="unit" id="baseUnitSelect" class="form-select rm-input" data-no-search <?= $canChangeUnitAndCost ? '' : 'disabled'; ?>>
                            <?php foreach ($unitList as $u) { ?>
                            <option value="<?= $h($u); ?>" <?= ($product['unit'] ?? '') === $u ? 'selected' : ''; ?>><?= $h($u); ?></option>
                            <?php } ?>
                        </select>
                        <div class="form-text"><?= $canChangeUnitAndCost ? 'The smallest unit you sell. Stock is counted in it.' : 'Locked while there is stock (the quantity would mean another amount).'; ?></div>
                    </div>
                    <div class="col-md-4 item-only-field">
                        <!-- CHANGED: read-only; stock changes through Restock, sales and purchase orders -->
                        <label class="form-label small fw-semibold text-muted">Quantity in Stock</label>
                        <input type="text" class="form-control rm-input" value="<?= number_format($stock); ?> <?= $h($product['unit']); ?>" disabled>
                        <div class="form-text">Changes through <a href="restock.php?id=<?= (int) $id; ?>">Restock</a>, sales and purchase orders.</div>
                    </div>
                    <div class="col-md-4 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Reorder Level (<span class="base-unit-label"></span>)</label>
                        <input type="number" step="1" min="0" name="reorder_level" class="form-control rm-input" value="<?= (int) ($product['reorder_level'] ?? 0); ?>">
                        <div class="form-text">Low-stock alert at or below this quantity. 0 = default.</div>
                    </div>
                </div>

                <div class="mb-4 item-only-field" id="unitsSection">
                    <label class="form-label small fw-semibold text-muted">Sub-units (optional)</label>
                    <p class="small text-muted mb-2">Other units this product is bought or sold in, e.g. <strong>1 Carton = 24 <span class="base-unit-label"></span></strong>. Choose from the Units list.</p>
                    <table class="table table-bordered bg-white align-middle" id="unitsTable">
                        <thead>
                            <tr>
                                <th>Sub-unit</th>
                                <th style="width:220px;">1 sub-unit = how many <span class="base-unit-label"></span>?</th>
                                <th style="width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody id="unitsBody"></tbody>
                    </table>
                    <button type="button" id="addUnitRow" class="rm-btn rm-btn-outline-primary rm-btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Sub-unit</button>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Buying Price</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" id="buyingPriceInput" class="form-control rm-input" value="<?= $h($product['buying_price']); ?>" <?= $canChangeUnitAndCost ? '' : 'disabled'; ?>>
                            <select id="buyingPriceUnitSelect" class="form-select rm-input" style="max-width:150px;" data-no-search <?= $canChangeUnitAndCost ? '' : 'disabled'; ?>></select>
                        </div>
                        <small class="text-muted" id="buyingPricePreview"><?= $canChangeUnitAndCost ? '' : 'Locked while there is stock: it follows the average cost of restocks.'; ?></small>
                        <input type="hidden" name="buying_price" id="buyingPriceHidden">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted" id="priceLabel">Selling Price</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" id="sellingPriceInput" class="form-control rm-input" value="<?= $h($product['selling_price']); ?>" required>
                            <select id="sellingPriceUnitSelect" class="form-select rm-input item-only-field" style="max-width:150px;" data-no-search></select>
                        </div>
                        <small class="text-muted" id="sellingPricePreview"></small>
                        <input type="hidden" name="selling_price" id="sellingPriceHidden">
                    </div>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="rm-btn rm-btn-primary" type="submit" name="update">
                        <i class="bi bi-check-circle-fill me-2"></i>Update
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-light">Cancel</a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<script>
const UNITS = <?= json_encode(array_values(array_unique(array_merge($unitList, $existingUnitNames))), $jsonFlags); ?>;
const CATEGORIES = { Item: <?= json_encode(array_values($productCategories), $jsonFlags); ?>, Service: <?= json_encode(array_values($serviceCategories), $jsonFlags); ?> };
const CHOSEN_CATEGORY = <?= json_encode($product['category'] ?? '', $jsonFlags); ?>;
const SAVED_ROWS = <?= json_encode($unitRows, $jsonFlags); ?>;
const COST_LOCKED = <?= $canChangeUnitAndCost ? 'false' : 'true'; ?>;

const itemTypeSelect = document.getElementById('itemTypeSelect');
const itemOnlyFields = document.querySelectorAll('.item-only-field');
const nameLabel = document.getElementById('nameLabel');
const priceLabel = document.getElementById('priceLabel');
const categorySelect = document.getElementById('categorySelect');
const categoryLabel = document.getElementById('categoryLabel');
const baseUnitSelect = document.getElementById('baseUnitSelect');
const unitsBody = document.getElementById('unitsBody');

const buyingPriceInput = document.getElementById('buyingPriceInput');
const buyingPriceUnitSelect = document.getElementById('buyingPriceUnitSelect');
const buyingPricePreview = document.getElementById('buyingPricePreview');
const buyingPriceHidden = document.getElementById('buyingPriceHidden');
const sellingPriceInput = document.getElementById('sellingPriceInput');
const sellingPriceUnitSelect = document.getElementById('sellingPriceUnitSelect');
const sellingPricePreview = document.getElementById('sellingPricePreview');
const sellingPriceHidden = document.getElementById('sellingPriceHidden');

function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }

function fillCategories() {
    const list = CATEGORIES[itemTypeSelect.value] || [];
    categorySelect.innerHTML = '<option value="">' + (list.length ? 'Select category' : 'No categories yet (Admin: Categories)') + '</option>' +
        list.map(function (c) { return '<option value="' + esc(c) + '"' + (c === CHOSEN_CATEGORY ? ' selected' : '') + '>' + esc(c) + '</option>'; }).join('');
}

function toggleFields() {
    const isService = itemTypeSelect.value === 'Service';
    itemOnlyFields.forEach(function (el) {
        el.style.display = isService ? 'none' : '';
        el.querySelectorAll('input, select').forEach(function (input) {
            if (input.id === 'baseUnitSelect' || input.id === 'buyingPriceInput' || input.id === 'buyingPriceUnitSelect') {
                input.disabled = isService || COST_LOCKED;
            } else if (input.type !== 'text' || input.name) {
                input.disabled = isService;
            }
        });
    });
    nameLabel.textContent = isService ? 'Service Name' : 'Product Name';
    priceLabel.textContent = isService ? 'Price' : 'Selling Price';
    categoryLabel.textContent = isService ? 'Service Category' : 'Product Category';
    fillCategories();
}

function updateBaseUnitLabels() {
    document.querySelectorAll('.base-unit-label').forEach(function (el) { el.textContent = baseUnitSelect.value; });
}

function buildUnitRow(name, size) {
    const tr = document.createElement('tr');
    const options = UNITS.filter(function (u) { return u !== baseUnitSelect.value || u === name; })
        .map(function (u) { return '<option value="' + esc(u) + '"' + (u === name ? ' selected' : '') + '>' + esc(u) + '</option>'; }).join('');
    tr.innerHTML =
        '<td><select class="form-select rm-input unit-name-field" name="unit_name[]" data-no-search><option value="">Select unit</option>' + options + '</select></td>' +
        '<td><input type="number" class="form-control rm-input unit-size-field" name="unit_pack_size[]" min="2" step="1" placeholder="e.g. 24" value="' + esc(size || '') + '"></td>' +
        '<td><button type="button" class="btn btn-outline-danger btn-sm remove-unit-row">&times;</button></td>';
    tr.querySelector('.remove-unit-row').addEventListener('click', function () { tr.remove(); refreshUnitSelectors(); });
    tr.querySelector('.unit-name-field').addEventListener('change', refreshUnitSelectors);
    tr.querySelector('.unit-size-field').addEventListener('input', refreshUnitSelectors);
    return tr;
}

document.getElementById('addUnitRow').addEventListener('click', function () {
    unitsBody.appendChild(buildUnitRow('', ''));
    refreshUnitSelectors();
});

function getCurrentUnits() {
    const units = [{ name: baseUnitSelect.value, packSize: 1 }];
    unitsBody.querySelectorAll('tr').forEach(function (row) {
        const name = row.querySelector('.unit-name-field').value.trim();
        const size = parseInt(row.querySelector('.unit-size-field').value || 0, 10);
        if (name && size > 1) { units.push({ name: name, packSize: size }); }
    });
    return units;
}

function refreshSelect(selectEl, units) {
    const previousValue = selectEl.value;
    selectEl.innerHTML = units.map(function (u) {
        return '<option value="' + esc(u.name) + '" data-pack-size="' + u.packSize + '">' + esc(u.name) + (u.packSize > 1 ? ' (' + u.packSize + ')' : '') + '</option>';
    }).join('');
    selectEl.value = units.some(function (u) { return u.name === previousValue; }) ? previousValue : units[0].name;
}

function refreshUnitSelectors() {
    const units = getCurrentUnits();
    refreshSelect(buyingPriceUnitSelect, units);
    refreshSelect(sellingPriceUnitSelect, units);
    if (!COST_LOCKED) { updatePreview(buyingPriceInput, buyingPriceUnitSelect, buyingPricePreview); }
    updatePreview(sellingPriceInput, sellingPriceUnitSelect, sellingPricePreview);
}

function selectedPackSize(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    return opt ? parseInt(opt.getAttribute('data-pack-size') || 1, 10) : 1;
}

function updatePreview(priceInput, unitSelect, previewEl) {
    const packSize = selectedPackSize(unitSelect);
    const entered = parseFloat(priceInput.value || 0);
    const perBase = packSize > 0 ? entered / packSize : entered;
    previewEl.textContent = packSize > 1 ? ('= RWF ' + perBase.toFixed(2) + ' per ' + baseUnitSelect.value) : '';
}

buyingPriceInput.addEventListener('input', function () { updatePreview(buyingPriceInput, buyingPriceUnitSelect, buyingPricePreview); });
sellingPriceInput.addEventListener('input', function () { updatePreview(sellingPriceInput, sellingPriceUnitSelect, sellingPricePreview); });
buyingPriceUnitSelect.addEventListener('change', function () { updatePreview(buyingPriceInput, buyingPriceUnitSelect, buyingPricePreview); });
sellingPriceUnitSelect.addEventListener('change', function () { updatePreview(sellingPriceInput, sellingPriceUnitSelect, sellingPricePreview); });
baseUnitSelect.addEventListener('change', function () {
    updateBaseUnitLabels();
    const rows = Array.prototype.map.call(unitsBody.querySelectorAll('tr'), function (r) {
        return [r.querySelector('.unit-name-field').value, r.querySelector('.unit-size-field').value];
    });
    unitsBody.innerHTML = '';
    rows.forEach(function (r) { if (r[0] !== baseUnitSelect.value) { unitsBody.appendChild(buildUnitRow(r[0], r[1])); } });
    refreshUnitSelectors();
});

document.getElementById('productForm').addEventListener('submit', function () {
    const buyingPackSize = selectedPackSize(buyingPriceUnitSelect);
    const sellingPackSize = selectedPackSize(sellingPriceUnitSelect);
    buyingPriceHidden.value = ((parseFloat(buyingPriceInput.value || 0)) / (buyingPackSize || 1)).toFixed(2);
    sellingPriceHidden.value = ((parseFloat(sellingPriceInput.value || 0)) / (sellingPackSize || 1)).toFixed(2);
});

itemTypeSelect.addEventListener('change', toggleFields);
SAVED_ROWS.forEach(function (r) { if (r.unit_name || r.pack_size) { unitsBody.appendChild(buildUnitRow(r.unit_name, r.pack_size)); } });
toggleFields();
updateBaseUnitLabels();
refreshUnitSelectors();
</script>

<?php include '../../includes/footer.php'; ?>