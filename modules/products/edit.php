<?php
session_start();
require '../../config/db.php';
require '../../includes/product_unit_helpers.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid item selected.'); exit; }

$productStatement = mysqli_prepare($conn, 'SELECT * FROM products WHERE id = ?');
mysqli_stmt_bind_param($productStatement, 'i', $id);
mysqli_stmt_execute($productStatement);
$product = mysqli_fetch_assoc(mysqli_stmt_get_result($productStatement));
if (!$product) { header('Location: index.php?success=Item not found.'); exit; }

// Admin-only action
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?success=' . urlencode('You do not have permission to edit items or services.'));
    exit;
}

$unitCatalog = all_units($conn);
$existingUnits = array_values(array_filter(product_units_for($conn, $id), function ($u) { return !$u['is_base']; }));

if (isset($_POST['update'])) {
    $itemType = in_array($_POST['item_type'] ?? '', ['Item', 'Service'], true) ? $_POST['item_type'] : 'Item';
    $product_name = trim($_POST['product_name'] ?? '');
    // product_code is not editable — always keep the original generated code.
    $product_code = $product['product_code'];
    $description = trim($_POST['description'] ?? '');

    // Always the per-BASE-UNIT price — the JS converts whatever unit the
    // admin actually typed the number in before this form submits.
    $selling_price = filter_input(INPUT_POST, 'selling_price', FILTER_VALIDATE_FLOAT);

    $unitNames = $_POST['unit_name'] ?? [];
    $unitPackSizes = $_POST['unit_pack_size'] ?? [];

    if ($itemType === 'Item') {
        $buying_price = filter_input(INPUT_POST, 'buying_price', FILTER_VALIDATE_FLOAT);
        $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
        $quantity = $quantity === false || $quantity === null ? 0 : $quantity;
        $unit = in_array($_POST['unit'] ?? '', ['Pieces', 'Boxes'], true) ? $_POST['unit'] : 'Pieces';
    } else {
        $buying_price = 0.00;
        $quantity = 0;
        $unit = 'Pieces';
    }

    if ($product_name === '' || $selling_price === false || ($itemType === 'Item' && $buying_price === false)) {
        $error = "Please provide valid details.";
    } else {
        mysqli_begin_transaction($conn);

        $statement = mysqli_prepare($conn, "UPDATE products SET
            item_type = ?, product_name = ?, description = ?,
            buying_price = ?, selling_price = ?, quantity = ?, unit = ? WHERE id = ?");
        mysqli_stmt_bind_param($statement, 'sssddisi', $itemType, $product_name, $description, $buying_price, $selling_price, $quantity, $unit, $id);
        mysqli_stmt_execute($statement);

        // Product Units — replace the full set with whatever was submitted,
        // resolved against the shared Units catalog. Services get an empty
        // set (no packaging).
        if ($itemType === 'Item') {
            $unitsToSave = [];
            foreach ($unitNames as $index => $name) {
                $unitsToSave[] = ['unit_name' => $name, 'pack_size' => (int) ($unitPackSizes[$index] ?? 0)];
            }
            save_product_units($conn, $id, $unitsToSave);
        } else {
            save_product_units($conn, $id, []);
        }

        mysqli_commit($conn);

        header("Location: index.php?success=" . $itemType . " updated successfully.");
        exit;
    }
    $product = array_merge($product, ['item_type' => $itemType, 'product_name' => $product_name, 'product_code' => $product_code, 'description' => $description, 'buying_price' => $buying_price, 'selling_price' => $selling_price, 'quantity' => $quantity, 'unit' => $unit]);
}

$modal_icon = 'bi-box-seam';
$modal_title = 'Edit Item or Service';
$modal_subtitle = 'Update these catalog details.';
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<datalist id="allUnitsList">
    <?php foreach ($unitCatalog as $u) { ?>
    <option value="<?= htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php } ?>
</datalist>

<div class="rm-modal-backdrop">
    <div class="rm-modal">
        <?php include '../../includes/model_header.php'; ?>

        <div class="rm-modal-body">
            <?php if (isset($error)) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php } ?>

            <form method="POST" id="productForm">
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Type</label>
                        <select name="item_type" id="itemTypeSelect" class="form-select rm-input" required>
                            <option value="Item" <?= $product['item_type'] === 'Item' ? 'selected' : ''; ?>>Item</option>
                            <option value="Service" <?= $product['item_type'] === 'Service' ? 'selected' : ''; ?>>Service</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted" id="codeLabel">Code</label>
                        <input type="text" class="form-control rm-input" value="<?= htmlspecialchars($product['product_code'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
                        <div class="form-text">Code is generated automatically and can't be changed.</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted" id="nameLabel">Item Name</label>
                    <input type="text" name="product_name" class="form-control rm-input" value="<?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Description</label>
                    <textarea name="description" class="form-control rm-input" rows="3" style="height:auto;"><?= htmlspecialchars($product['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="row g-3 mb-2">
                    <div class="col-3 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Quantity</label>
                        <input type="number" step="1" min="0" name="quantity" class="form-control rm-input" value="<?= htmlspecialchars((string) ($product['quantity'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-3 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Base Unit</label>
                        <select name="unit" id="baseUnitSelect" class="form-select rm-input">
                            <option value="Pieces" <?= ($product['unit'] ?? 'Pieces') === 'Pieces' ? 'selected' : ''; ?>>Pieces</option>
                            <option value="Boxes" <?= ($product['unit'] ?? '') === 'Boxes' ? 'selected' : ''; ?>>Boxes</option>
                        </select>
                        <div class="form-text">Always your smallest sellable unit — e.g. one Piece, not a whole Box.</div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Buying Price</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" id="buyingPriceInput" class="form-control rm-input" value="<?= htmlspecialchars((string) $product['buying_price'], ENT_QUOTES, 'UTF-8'); ?>">
                            <select id="buyingPriceUnitSelect" class="form-select rm-input" style="max-width:130px;"></select>
                        </div>
                        <small class="text-muted" id="buyingPricePreview"></small>
                        <input type="hidden" name="buying_price" id="buyingPriceHidden">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted" id="priceLabel">Selling Price</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" id="sellingPriceInput" class="form-control rm-input" value="<?= htmlspecialchars((string) $product['selling_price'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            <select id="sellingPriceUnitSelect" class="form-select rm-input item-only-field" style="max-width:130px;"></select>
                        </div>
                        <small class="text-muted" id="sellingPricePreview"></small>
                        <input type="hidden" name="selling_price" id="sellingPriceHidden">
                    </div>
                </div>

                <div class="mb-4 item-only-field" id="unitsSection">
                    <label class="form-label small fw-semibold text-muted">Product Units</label>
                    <p class="small text-muted mb-2">Extra ways this item can be bought or sold, e.g. <strong>1 Carton = 200 <span class="base-unit-label"><?= htmlspecialchars($product['unit'] ?? 'Pieces', ENT_QUOTES, 'UTF-8'); ?></span></strong>. Pick from existing units below, or type a new name to add it to your Units catalog.</p>
                    <table class="table table-bordered bg-white align-middle" id="unitsTable">
                        <thead>
                            <tr>
                                <th>Unit Name</th>
                                <th style="width:180px;">1 Unit = How Many <span class="base-unit-label"><?= htmlspecialchars($product['unit'] ?? 'Pieces', ENT_QUOTES, 'UTF-8'); ?></span>?</th>
                                <th style="width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody id="unitsBody"></tbody>
                    </table>
                    <button type="button" id="addUnitRow" class="rm-btn rm-btn-outline-primary rm-btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Product Unit</button>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="rm-btn rm-btn-primary rm-btn-primary" type="submit" name="update">
                        <i class="bi bi-check-circle-fill me-2"></i>Update
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-light">Cancel</a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<script>
const itemTypeSelect = document.getElementById('itemTypeSelect');
const itemOnlyFields = document.querySelectorAll('.item-only-field');
const nameLabel = document.getElementById('nameLabel');
const priceLabel = document.getElementById('priceLabel');
const baseUnitSelect = document.getElementById('baseUnitSelect');
const baseUnitLabels = document.querySelectorAll('.base-unit-label');
const unitsBody = document.getElementById('unitsBody');
const EXISTING_UNITS = <?= json_encode(array_map(function ($u) {
    return ['unit_name' => $u['unit_name'], 'pack_size' => $u['pack_size']];
}, $existingUnits)); ?>;

const buyingPriceInput = document.getElementById('buyingPriceInput');
const buyingPriceUnitSelect = document.getElementById('buyingPriceUnitSelect');
const buyingPricePreview = document.getElementById('buyingPricePreview');
const buyingPriceHidden = document.getElementById('buyingPriceHidden');
const sellingPriceInput = document.getElementById('sellingPriceInput');
const sellingPriceUnitSelect = document.getElementById('sellingPriceUnitSelect');
const sellingPricePreview = document.getElementById('sellingPricePreview');
const sellingPriceHidden = document.getElementById('sellingPriceHidden');

function toggleFields() {
    const isService = itemTypeSelect.value === 'Service';
    itemOnlyFields.forEach(function (el) {
        el.style.display = isService ? 'none' : '';
        el.querySelectorAll('input, select').forEach(function (input) { input.disabled = isService; });
    });
    nameLabel.textContent = isService ? 'Service Name' : 'Item Name';
    priceLabel.textContent = isService ? 'Price' : 'Selling Price';
}

function updateBaseUnitLabels() {
    baseUnitLabels.forEach(function (el) { el.textContent = baseUnitSelect.value; });
}

function buildUnitRow(unit) {
    const tr = document.createElement('tr');
    tr.innerHTML =
        '<td><input type="text" class="form-control rm-input unit-name-field" name="unit_name[]" list="allUnitsList" autocomplete="off" placeholder="Pick or type a unit, e.g. Carton" value="' + (unit ? unit.unit_name.replace(/"/g, '&quot;') : '') + '"></td>' +
        '<td><input type="number" class="form-control rm-input unit-size-field" name="unit_pack_size[]" min="2" step="1" placeholder="e.g. 200" value="' + (unit ? unit.pack_size : '') + '"></td>' +
        '<td><button type="button" class="btn btn-outline-danger btn-sm remove-unit-row">&times;</button></td>';
    tr.querySelector('.remove-unit-row').addEventListener('click', function () { tr.remove(); refreshUnitSelectors(); });
    tr.querySelector('.unit-name-field').addEventListener('input', refreshUnitSelectors);
    tr.querySelector('.unit-size-field').addEventListener('input', refreshUnitSelectors);
    return tr;
}

document.getElementById('addUnitRow').addEventListener('click', function () {
    unitsBody.appendChild(buildUnitRow(null));
    refreshUnitSelectors();
});

// ---- Price-entry-unit picker (see create.php for full explanation) ----
function getCurrentUnits() {
    const units = [{ name: baseUnitSelect.value, packSize: 1 }];
    unitsBody.querySelectorAll('tr').forEach(function (row) {
        const name = row.querySelector('.unit-name-field').value.trim();
        const size = parseInt(row.querySelector('.unit-size-field').value || 0);
        if (name && size > 1) { units.push({ name: name, packSize: size }); }
    });
    return units;
}

function refreshSelect(selectEl, units) {
    const previousValue = selectEl.value;
    selectEl.innerHTML = units.map(function (u) {
        return '<option value="' + u.name.replace(/"/g, '&quot;') + '" data-pack-size="' + u.packSize + '">' + u.name + '</option>';
    }).join('');
    const stillExists = units.some(function (u) { return u.name === previousValue; });
    if (stillExists) { selectEl.value = previousValue; } else { selectEl.value = units[0].name; }
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
    return opt ? parseInt(opt.getAttribute('data-pack-size') || 1) : 1;
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
baseUnitSelect.addEventListener('change', function () { updateBaseUnitLabels(); refreshUnitSelectors(); });

document.getElementById('productForm').addEventListener('submit', function () {
    const buyingPackSize = selectedPackSize(buyingPriceUnitSelect);
    const sellingPackSize = selectedPackSize(sellingPriceUnitSelect);
    buyingPriceHidden.value = ((parseFloat(buyingPriceInput.value || 0)) / (buyingPackSize || 1)).toFixed(2);
    sellingPriceHidden.value = ((parseFloat(sellingPriceInput.value || 0)) / (sellingPackSize || 1)).toFixed(2);
});

itemTypeSelect.addEventListener('change', toggleFields);
toggleFields();
updateBaseUnitLabels();

// Pre-fill existing units for this product, then wire up the price pickers
EXISTING_UNITS.forEach(function (u) {
    unitsBody.appendChild(buildUnitRow(u));
});
refreshUnitSelectors();
</script>

<?php include '../../includes/footer.php'; ?>