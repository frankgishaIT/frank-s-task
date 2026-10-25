<?php
session_start();
require '../../config/db.php';
require '../../includes/notification_helper.php';
require '../../includes/product_unit_helpers.php';
require_once '../../includes/category_helpers.php'; // NEW (Task 2): managed units and categories

// Admin-only action
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?error=' . urlencode('You do not have permission to add products or services.'));
    exit;
}

// CHANGED (Task 2): units and categories come from the lists Admins manage under Categories.
// Users choose from them; nobody types a new unit or category here.
$unitList = categories_active($conn, 'unit');
$productCategories = categories_active($conn, 'product');
$serviceCategories = categories_active($conn, 'service');

function generate_product_code($conn, $itemType) {
    // The codes stay ITM-xxx / SRV-xxx so existing codes, invoices and reports keep matching.
    $prefix = $itemType === 'Service' ? 'SRV' : 'ITM';
    $statement = mysqli_prepare($conn, "SELECT product_code FROM products WHERE product_code LIKE ?");
    $likePattern = $prefix . '-%';
    mysqli_stmt_bind_param($statement, 's', $likePattern);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);

    $max = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        if (preg_match('/^' . $prefix . '-(\d+)$/', $row['product_code'], $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return $prefix . '-' . str_pad($max + 1, 3, '0', STR_PAD_LEFT);
}

$unitRows = [];
if (isset($_POST['save'])) {
    // The database value stays 'Item' for products (all existing records use it); on screen it is "Product".
    $itemType = in_array($_POST['item_type'] ?? '', ['Item', 'Service'], true) ? $_POST['item_type'] : 'Item';
    $product_name = trim($_POST['product_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');

    // These fields are always the per-BASE-UNIT price, regardless of what
    // unit the person actually typed the number in on screen — the JS
    // converts it before submit. See the price-unit picker in the form.
    $selling_price = filter_input(INPUT_POST, 'selling_price', FILTER_VALIDATE_FLOAT);

    $unitNames = $_POST['unit_name'] ?? [];
    $unitPackSizes = $_POST['unit_pack_size'] ?? [];
    foreach ($unitNames as $i => $n) {
        $unitRows[] = ['unit_name' => trim((string) $n), 'pack_size' => trim((string) ($unitPackSizes[$i] ?? ''))];
    }

    if ($itemType === 'Item') {
        $buying_price = filter_input(INPUT_POST, 'buying_price', FILTER_VALIDATE_FLOAT);
        $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
        $quantity = $quantity === false || $quantity === null ? 0 : $quantity;
        $unit = trim($_POST['unit'] ?? '');
        // Reorder level: the Low Stock report flags this product once its quantity reaches or falls
        // below this number. 0 = use the system default (see includes/stock_rules.php).
        $reorderLevel = filter_input(INPUT_POST, 'reorder_level', FILTER_VALIDATE_INT);
        $reorderLevel = ($reorderLevel === false || $reorderLevel === null || $reorderLevel < 0) ? 0 : $reorderLevel;
    } else {
        // Services carry no cost price, stock, or unit of measure.
        $buying_price = 0.00;
        $quantity = 0;
        $unit = 'Pieces';
        $reorderLevel = 0;
        $unitRows = [];
    }

    // NEW (Task 2): every unit and conversion is checked on the server.
    $unitsToSave = [];
    $unitError = null;
    if ($itemType === 'Item') {
        $seen = [];
        foreach ($unitRows as $row) {
            if ($row['unit_name'] === '' && $row['pack_size'] === '') { continue; } // empty row
            $size = filter_var($row['pack_size'], FILTER_VALIDATE_INT);
            if (!category_is_valid($conn, 'unit', $row['unit_name'])) { $unitError = 'Choose each sub-unit from the Units list.'; break; }
            if ($row['unit_name'] === $unit) { $unitError = 'A sub-unit cannot be the same as the base unit (' . $unit . ').'; break; }
            if (isset($seen[$row['unit_name']])) { $unitError = '"' . $row['unit_name'] . '" is listed twice.'; break; }
            if ($size === false || $size < 2) { $unitError = 'For "' . $row['unit_name'] . '", enter how many ' . $unit . ' it contains (a whole number of 2 or more).'; break; }
            $seen[$row['unit_name']] = true;
            $unitsToSave[] = ['unit_name' => $row['unit_name'], 'pack_size' => $size];
        }
    }

    if ($product_name === '' || $selling_price === false || $selling_price === null || $selling_price < 0
        || ($itemType === 'Item' && ($buying_price === false || $buying_price === null || $buying_price < 0))) {
        $error = 'Please provide valid details (name and prices).';
    } elseif ($itemType === 'Item' && !category_is_valid($conn, 'unit', $unit)) {
        $error = 'Please choose the Base Unit from the list.';
    } elseif ($itemType === 'Item' && $quantity < 0) {
        $error = 'The opening quantity cannot be negative.';
    } elseif ($unitError) {
        $error = $unitError;
    } elseif ($category === '' || !category_is_valid($conn, $itemType === 'Service' ? 'service' : 'product', $category)) {
        $error = 'Please choose the ' . ($itemType === 'Service' ? 'Service' : 'Product') . ' Category from the list'
            . ((($itemType === 'Service' ? $serviceCategories : $productCategories) === []) ? ' (an Admin must first add categories under Categories).' : '.');
    } else {
        $product_code = generate_product_code($conn, $itemType);

        mysqli_begin_transaction($conn);
        try {
            $statement = mysqli_prepare($conn, "INSERT INTO products
                (item_type, product_name, product_code, description, buying_price, selling_price, quantity, unit, reorder_level, category)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($statement, 'ssssddisis', $itemType, $product_name, $product_code, $description, $buying_price, $selling_price, $quantity, $unit, $reorderLevel, $category);
            mysqli_stmt_execute($statement);
            $newProductId = mysqli_insert_id($conn);

            // Product Units: other ways this product is bought / sold (Carton, Box ...), each with a
            // fixed conversion to the base unit. Stock is always kept in the base unit.
            if ($itemType === 'Item') {
                save_product_units($conn, $newProductId, $unitsToSave);
            }
            mysqli_commit($conn);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('product create failed: ' . $e->getMessage());
            $error = 'Unable to save. Nothing was saved. [Technical detail for Admin: ' . $e->getMessage() . ']';
        }

        if (!isset($error)) {
            notify($conn, ($itemType === 'Service' ? 'Service' : 'Product') . ' Added', '"' . $product_name . '" (' . $product_code . ') has been added.');
            header('Location: index.php?success=' . urlencode(($itemType === 'Service' ? 'Service' : 'Product') . ' added successfully with code ' . $product_code . '.'));
            exit;
        }
    }
}

$modal_icon = 'bi-box-seam';
$modal_title = 'Add Product or Service';
$modal_subtitle = 'Add a new product or service to your catalog.';
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
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-muted">Type</label>
                        <select name="item_type" id="itemTypeSelect" class="form-select rm-input" required>
                            <option value="Item" <?= ($itemType ?? 'Item') === 'Item' ? 'selected' : ''; ?>>Product (physical, kept in stock)</option>
                            <option value="Service" <?= ($itemType ?? '') === 'Service' ? 'selected' : ''; ?>>Service (no stock)</option>
                        </select>
                        <div class="form-text">Code will be generated automatically (e.g. ITM-001 / SRV-001).</div>
                    </div>
                    <div class="col-md-6">
                        <!-- NEW (Task 2 D): category, from the lists Admins manage -->
                        <label class="form-label small fw-semibold text-muted" id="categoryLabel">Product Category</label>
                        <select name="category" id="categorySelect" class="form-select rm-input" required></select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted" id="nameLabel">Product Name</label>
                    <input type="text" name="product_name" class="form-control rm-input" value="<?= $h($product_name ?? ''); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Description</label>
                    <textarea name="description" class="form-control rm-input" rows="3" style="height:auto;"><?= $h($description ?? ''); ?></textarea>
                </div>

                <div class="row g-3 mb-2">
                    <div class="col-md-4 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Base Unit</label>
                        <select name="unit" id="baseUnitSelect" class="form-select rm-input" data-no-search>
                            <?php foreach ($unitList as $u) { ?>
                            <option value="<?= $h($u); ?>" <?= ($unit ?? 'Piece') === $u ? 'selected' : ''; ?>><?= $h($u); ?></option>
                            <?php } ?>
                        </select>
                        <div class="form-text">The smallest unit you sell (e.g. Piece, Gram). Stock is counted in this unit.</div>
                    </div>
                    <div class="col-md-4 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Opening Quantity (<span class="base-unit-label">Piece</span>)</label>
                        <input type="number" step="1" min="0" name="quantity" class="form-control rm-input" value="<?= (int) ($quantity ?? 0); ?>">
                    </div>
                    <div class="col-md-4 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Reorder Level (<span class="base-unit-label">Piece</span>)</label>
                        <input type="number" step="1" min="0" name="reorder_level" class="form-control rm-input" value="<?= (int) ($reorderLevel ?? 0); ?>">
                        <div class="form-text">Low-stock alert at or below this quantity. 0 = default.</div>
                    </div>
                </div>

                <div class="mb-4 item-only-field" id="unitsSection">
                    <label class="form-label small fw-semibold text-muted">Sub-units (optional)</label>
                    <p class="small text-muted mb-2">Other units this product is bought or sold in, each converted to the base unit,
                        e.g. <strong>1 Carton = 24 <span class="base-unit-label">Piece</span></strong>. Choose from the Units list (Admins add new units under Categories).</p>
                    <table class="table table-bordered bg-white align-middle" id="unitsTable">
                        <thead>
                            <tr>
                                <th>Sub-unit</th>
                                <th style="width:220px;">1 sub-unit = how many <span class="base-unit-label">Piece</span>?</th>
                                <th style="width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <button type="button" id="addUnitRow" class="rm-btn rm-btn-outline-primary rm-btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Sub-unit</button>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Buying Price</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" id="buyingPriceInput" class="form-control rm-input" placeholder="0.00">
                            <select id="buyingPriceUnitSelect" class="form-select rm-input" style="max-width:150px;" data-no-search></select>
                        </div>
                        <small class="text-muted" id="buyingPricePreview"></small>
                        <input type="hidden" name="buying_price" id="buyingPriceHidden">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted" id="priceLabel">Selling Price</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" id="sellingPriceInput" class="form-control rm-input" placeholder="0.00" required>
                            <select id="sellingPriceUnitSelect" class="form-select rm-input item-only-field" style="max-width:150px;" data-no-search></select>
                        </div>
                        <small class="text-muted" id="sellingPricePreview"></small>
                        <input type="hidden" name="selling_price" id="sellingPriceHidden">
                    </div>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button type="submit" name="save" class="rm-btn rm-btn-primary">
                        <i class="bi bi-check-circle-fill me-2"></i>Save
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-secondary">Cancel</a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<script>
const UNITS = <?= json_encode(array_values($unitList), $jsonFlags); ?>;
const CATEGORIES = { Item: <?= json_encode(array_values($productCategories), $jsonFlags); ?>, Service: <?= json_encode(array_values($serviceCategories), $jsonFlags); ?> };
const CHOSEN_CATEGORY = <?= json_encode($category ?? '', $jsonFlags); ?>;
const SAVED_ROWS = <?= json_encode($unitRows, $jsonFlags); ?>;

const itemTypeSelect = document.getElementById('itemTypeSelect');
const itemOnlyFields = document.querySelectorAll('.item-only-field');
const nameLabel = document.getElementById('nameLabel');
const priceLabel = document.getElementById('priceLabel');
const categorySelect = document.getElementById('categorySelect');
const categoryLabel = document.getElementById('categoryLabel');
const baseUnitSelect = document.getElementById('baseUnitSelect');
const unitsBody = document.querySelector('#unitsTable tbody');

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
        el.querySelectorAll('input, select').forEach(function (input) { input.disabled = isService; });
    });
    nameLabel.textContent = isService ? 'Service Name' : 'Product Name';
    priceLabel.textContent = isService ? 'Price' : 'Selling Price';
    categoryLabel.textContent = isService ? 'Service Category' : 'Product Category';
    fillCategories();
}

function updateBaseUnitLabels() {
    document.querySelectorAll('.base-unit-label').forEach(function (el) { el.textContent = baseUnitSelect.value; });
}

// NEW: sub-units are chosen from the Units list (no free typing); the base unit is not offered.
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

// ---- Price-entry-unit picker: Buying / Selling Price can be typed in any defined unit (the base unit
// or a sub-unit) and are converted to the price per base unit automatically.
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
    if (units.some(function (u) { return u.name === previousValue; })) { selectEl.value = previousValue; }
}

function refreshUnitSelectors() {
    const units = getCurrentUnits();
    refreshSelect(buyingPriceUnitSelect, units);
    refreshSelect(sellingPriceUnitSelect, units);
    updatePreview(buyingPriceInput, buyingPriceUnitSelect, buyingPricePreview);
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
    // Rebuild the sub-unit rows so the new base unit is not offered as a sub-unit.
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