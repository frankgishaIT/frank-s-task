<?php
require '../../config/db.php';
require '../../includes/purchase_order_helpers.php';
require '../../includes/business_party_helpers.php';
require '../../includes/product_unit_helpers.php';
require_role(['Admin', 'Manager']);

$catalog = mysqli_query($conn, "SELECT id, product_name, product_code, buying_price, quantity, unit FROM products WHERE item_type = 'Item' AND is_active = 1 ORDER BY product_name");
$catalogList = [];
while ($p = mysqli_fetch_assoc($catalog)) { $catalogList[] = $p; }

$unitsMap = product_units_map($conn, array_map(function ($p) { return $p['id']; }, $catalogList));

$supplierList = business_parties_of_type($conn, 'Supplier');
$lowStockList = po_low_stock_products($conn);
$prefillLowStock = isset($_GET['from_low_stock']);

if (isset($_POST['save'])) {
    $supplierPartyId = filter_input(INPUT_POST, 'supplier_party_id', FILTER_VALIDATE_INT) ?: null;
    $orderDate = $_POST['order_date'] ?? date('Y-m-d');
    $expectedDelivery = trim($_POST['expected_delivery_date'] ?? '');
    $action = $_POST['action'] ?? 'draft'; // 'draft' or 'order'
    $notes = trim($_POST['notes'] ?? '');

    $productIds = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unitCosts = $_POST['unit_cost'] ?? [];
    $unitChoices = $_POST['unit_choice'] ?? [];

    $validOrderDate = DateTime::createFromFormat('Y-m-d', $orderDate);
    $validExpectedDate = $expectedDelivery !== '' ? DateTime::createFromFormat('Y-m-d', $expectedDelivery) : true;

    $lineItems = [];
    $total = 0;
    $lineError = null;

    foreach ($productIds as $index => $productId) {
        $productId = (int) $productId;
        $qty = (int) ($quantities[$index] ?? 0);
        $unitCost = (float) ($unitCosts[$index] ?? 0);
        if ($productId <= 0 || $qty <= 0) { continue; }

        $found = null;
        foreach ($catalogList as $p) { if ((int) $p['id'] === $productId) { $found = $p; break; } }
        if (!$found) { continue; }

        // Re-validate the chosen unit server-side against this product's
        // actual defined units — never trust a client-submitted pack size.
        $productUnits = $unitsMap[$productId] ?? [];
        $selectedUnitName = trim($unitChoices[$index] ?? '');
        $matchedUnit = null;
        foreach ($productUnits as $u) { if ($u['unit_name'] === $selectedUnitName) { $matchedUnit = $u; break; } }
        if (!$matchedUnit) {
            $lineError = 'Please select a valid unit for "' . $found['product_name'] . '".';
            break;
        }

        if ($unitCost < 0) {
            $lineError = 'Cost cannot be negative for "' . $found['product_name'] . '".';
            break;
        }

        $lineTotal = $qty * $unitCost;
        $total += $lineTotal;
        $lineItems[] = [
            'product_id' => $productId, 'quantity' => $qty, 'unit_cost' => $unitCost,
            'line_total' => $lineTotal, 'pack_label' => $matchedUnit['unit_name'], 'pack_size' => $matchedUnit['pack_size'],
        ];
    }

    if (!$validOrderDate || $validOrderDate->format('Y-m-d') !== $orderDate) {
        $error = 'Please provide a valid order date.';
    } elseif ($expectedDelivery !== '' && (!$validExpectedDate || $validExpectedDate->format('Y-m-d') !== $expectedDelivery)) {
        $error = 'Please provide a valid expected delivery date.';
    } elseif (!$supplierPartyId) {
        $error = 'Please select a Supplier.';
    } elseif (empty($lineItems)) {
        $error = 'Add at least one product with a valid quantity.';
    } elseif ($lineError) {
        $error = $lineError;
    } elseif (!in_array($action, ['draft', 'order'], true)) {
        $error = 'Invalid action.';
    } else {
        $userId = current_user_id();
        $status = $action === 'order' ? 'Ordered' : 'Draft';
        $orderedBy = $action === 'order' ? $userId : null;
        $orderedAt = $action === 'order' ? date('Y-m-d H:i:s') : null;
        $expectedDeliveryValue = $expectedDelivery !== '' ? $expectedDelivery : null;

        $selectedSupplier = null;
        foreach ($supplierList as $s) { if ((int) $s['id'] === $supplierPartyId) { $selectedSupplier = $s; break; } }
        $supplier = $selectedSupplier ? $selectedSupplier['business_name'] : '';

        mysqli_begin_transaction($conn);

        $poStatement = mysqli_prepare($conn, 'INSERT INTO purchase_orders
            (supplier, supplier_party_id, order_date, expected_delivery_date, status, total_amount, notes, created_by, ordered_by, ordered_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($poStatement, 'sissdssiis',
            $supplier, $supplierPartyId, $orderDate, $expectedDeliveryValue, $status, $total, $notes, $userId, $orderedBy, $orderedAt);
        mysqli_stmt_execute($poStatement);
        $poId = mysqli_insert_id($conn);

        foreach ($lineItems as $item) {
            $itemStatement = mysqli_prepare($conn, 'INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_cost, line_total, pack_label, pack_size) VALUES (?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($itemStatement, 'iiiddsi', $poId, $item['product_id'], $item['quantity'], $item['unit_cost'], $item['line_total'], $item['pack_label'], $item['pack_size']);
            mysqli_stmt_execute($itemStatement);
        }

        mysqli_commit($conn);

        header('Location: view.php?id=' . $poId . '&success=' . urlencode('Purchase Order ' . ($action === 'order' ? 'created and marked as Ordered.' : 'saved as Draft.')));
        exit;
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<style>
.rm-searchable { position: relative; }
.rm-searchable-dropdown { display: none; position: absolute; z-index: 30; top: calc(100% + 4px); left: 0; right: 0; background: #fff; border: 1px solid #E2E5EF; border-radius: 10px; max-height: 230px; overflow-y: auto; box-shadow: 0 10px 30px rgba(30,35,51,.10); padding: 4px; }
.rm-searchable-option { padding: 8px 10px; border-radius: 7px; cursor: pointer; font-size: 14px; display: flex; justify-content: space-between; gap: 8px; }
.rm-searchable-option:hover, .rm-searchable-option.active { background: #EEF1FE; }
.rm-searchable-option .opt-meta { color: #8A90A3; font-size: 12px; white-space: nowrap; }
.rm-searchable-empty { padding: 10px; font-size: 13px; color: #8A90A3; text-align: center; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>New Purchase Order</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Purchase Orders</a>
</div>

<?php if (isset($error)) { ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
    <i class="bi bi-exclamation-circle-fill"></i>
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php } ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="POST" id="poForm">
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Supplier</label>
                    <select name="supplier_party_id" class="form-select rm-input" required>
                        <option value="">Select supplier</option>
                        <?php foreach ($supplierList as $s) { ?>
                        <option value="<?= (int) $s['id']; ?>"><?= htmlspecialchars($s['business_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                    <?php if (empty($supplierList)) { ?>
                    <small class="text-danger">No RM Suppliers found. <a href="../business_parties/create.php">Add one first</a>.</small>
                    <?php } ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Order Date</label>
                    <input type="date" name="order_date" class="form-control rm-input" value="<?= date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Expected Delivery Date</label>
                    <input type="date" name="expected_delivery_date" class="form-control rm-input">
                </div>
            </div>

            <?php if (!empty($lowStockList)) { ?>
            <div class="mb-3 p-3" style="background:#FFF8E6; border-radius:10px;">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small fw-semibold"><i class="bi bi-exclamation-triangle-fill text-warning me-1"></i> <?= count($lowStockList); ?> item(s) are currently low on stock.</span>
                    <button type="button" id="addLowStockBtn" class="rm-btn rm-btn-warning rm-btn-sm">Add All Low-Stock Items</button>
                </div>
            </div>
            <?php } ?>

            <label class="form-label small fw-semibold text-muted">Products</label>
            <p class="small text-muted mb-2">Pick how you're buying each product — units are set up per item in <a href="../products/index.php">RM Offerings</a>.</p>
            <table class="table table-bordered bg-white align-middle" id="itemsTable">
                <thead>
                    <tr>
                        <th style="min-width:180px;">Product</th>
                        <th style="width:90px;">In Stock</th>
                        <th style="width:160px;">Buying As</th>
                        <th style="width:80px;">Qty</th>
                        <th style="width:130px;">Cost / Unit</th>
                        <th style="width:110px;">Line Total</th>
                        <th style="width:40px;"></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <button type="button" id="addRow" class="rm-btn rm-btn-outline-primary rm-btn-sm mb-4"><i class="bi bi-plus-circle me-1"></i>Add Product</button>

            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">Notes</label>
                <textarea name="notes" class="form-control rm-input" rows="2" style="height:auto;"></textarea>
            </div>

            <div class="text-end mb-4" style="font-size:14px;">
                <div>Total: <strong id="totalDisplay" style="font-size:18px; color:var(--accent-blue);">RWF 0.00</strong></div>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-end">
                <button type="submit" name="save" value="1" id="saveDraftBtn" class="rm-btn rm-btn-secondary"><i class="bi bi-save2 me-2"></i>Save as Draft</button>
                <button type="submit" name="save" value="1" id="saveOrderBtn" class="rm-btn rm-btn-primary"><i class="bi bi-send-check-fill me-2"></i>Save & Mark as Ordered</button>
                <a href="index.php" class="rm-btn rm-btn-light"><i class="bi bi-x-circle-fill me-2"></i>Cancel</a>
            </div>
            <input type="hidden" name="action" id="actionField" value="draft">
        </form>
    </div>
</div>

<script>
const CATALOG = <?= json_encode(array_map(function ($p) {
    return ['id' => (int) $p['id'], 'name' => $p['product_name'], 'code' => $p['product_code'], 'cost' => (float) $p['buying_price'], 'stock' => (int) $p['quantity'], 'unit' => $p['unit']];
}, $catalogList)); ?>;
const LOW_STOCK = <?= json_encode(array_map(function ($p) {
    return ['id' => (int) $p['id'], 'name' => $p['product_name'], 'code' => $p['product_code'], 'cost' => (float) $p['buying_price'], 'stock' => (int) $p['quantity'], 'unit' => $p['unit']];
}, $lowStockList)); ?>;
const UNITS_MAP = <?= json_encode($unitsMap); ?>; // { productId: [ {unit_name, pack_size, is_base}, ... ] }
const PREFILL_LOW_STOCK = <?= $prefillLowStock ? 'true' : 'false'; ?>;

const itemsBody = document.querySelector('#itemsTable tbody');

function createSearchable(wrapper, options) {
    const { items, hiddenName, placeholder, initialLabel, initialValue, onSelect } = options;
    wrapper.innerHTML =
        '<input type="text" class="form-control rm-input rs-input" autocomplete="off" placeholder="' + placeholder + '" value="' + (initialLabel || '') + '">' +
        '<input type="hidden" name="' + hiddenName + '" value="' + (initialValue !== undefined ? initialValue : '') + '">';
    const input = wrapper.querySelector('.rs-input');
    const hidden = wrapper.querySelector('input[type=hidden]');
    let currentItems = items;
    const dropdown = document.createElement('div');
    dropdown.className = 'rm-searchable-dropdown';
    dropdown.style.position = 'fixed';
    dropdown.style.display = 'none';
    document.body.appendChild(dropdown);

    function positionDropdown() {
        const rect = input.getBoundingClientRect();
        dropdown.style.left = rect.left + 'px';
        dropdown.style.top = (rect.bottom + 4) + 'px';
        dropdown.style.width = rect.width + 'px';
    }
    function renderList(filterText) {
        const f = (filterText || '').trim().toLowerCase();
        const filtered = f === '' ? currentItems : currentItems.filter(function (it) { return it.label.toLowerCase().includes(f); });
        dropdown.innerHTML = filtered.length === 0
            ? '<div class="rm-searchable-empty">No matches found</div>'
            : filtered.map(function (it) {
                const meta = it.meta ? '<span class="opt-meta">' + it.meta + '</span>' : '';
                return '<div class="rm-searchable-option" data-id="' + it.id + '"><span>' + it.label + '</span>' + meta + '</div>';
            }).join('');
        positionDropdown();
        dropdown.style.display = 'block';
    }
    input.addEventListener('focus', function () { renderList(input.value === (initialLabel || '') ? '' : input.value); });
    input.addEventListener('input', function () { hidden.value = ''; if (onSelect) { onSelect(null); } renderList(input.value); });
    dropdown.addEventListener('mousedown', function (e) {
        const optEl = e.target.closest('.rm-searchable-option');
        if (!optEl) { return; }
        const id = optEl.getAttribute('data-id');
        const item = currentItems.find(function (it) { return String(it.id) === String(id); });
        if (!item) { return; }
        input.value = item.label;
        hidden.value = item.id;
        dropdown.style.display = 'none';
        if (onSelect) { onSelect(item); }
    });
    document.addEventListener('click', function (e) { if (!wrapper.contains(e.target) && !dropdown.contains(e.target)) { dropdown.style.display = 'none'; } });
    window.addEventListener('scroll', function () { if (dropdown.style.display === 'block') { positionDropdown(); } }, true);
    window.addEventListener('resize', function () { if (dropdown.style.display === 'block') { positionDropdown(); } });
    return { destroy: function () { dropdown.remove(); } };
}

function catalogOptions() {
    return CATALOG.map(function (p) { return { id: p.id, label: p.name + ' (' + p.code + ')', meta: p.stock + ' ' + p.unit + ' in stock', cost: p.cost, stock: p.stock }; });
}

function buildRow(prefill) {
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML =
        '<td class="target-cell"><div class="rm-searchable catalog-field"></div></td>' +
        '<td class="stock-cell text-center">—</td>' +
        '<td><select class="form-select rm-input unit-select" name="unit_choice[]"><option value="">Select product first</option></select></td>' +
        '<td><input type="number" class="form-control rm-input qty-input" name="quantity[]" min="1" value="' + (prefill && prefill.qty ? prefill.qty : 1) + '" required></td>' +
        '<td><input type="number" class="form-control rm-input cost-input" name="unit_cost[]" min="0" step="0.01" value="' + (prefill ? prefill.cost.toFixed(2) : '0.00') + '"></td>' +
        '<td><span class="line-total">0.00</span></td>' +
        '<td><input type="hidden" name="product_id[]" class="row-product-id"><button type="button" class="btn btn-outline-danger btn-sm remove-row">&times;</button></td>';
    return tr;
}

function bindRow(row, prefill) {
    const catalogFieldEl = row.querySelector('.catalog-field');
    const stockCell = row.querySelector('.stock-cell');
    const qty = row.querySelector('.qty-input');
    const costInput = row.querySelector('.cost-input');
    const unitSelect = row.querySelector('.unit-select');
    const lineTotalEl = row.querySelector('.line-total');
    const removeBtn = row.querySelector('.remove-row');
    const productIdField = row.querySelector('.row-product-id');

    function populateUnitSelect(productId) {
        const units = UNITS_MAP[productId] || [{ unit_name: 'Piece', pack_size: 1, is_base: true }];
        unitSelect.innerHTML = units.map(function (u) {
            return '<option value="' + u.unit_name.replace(/"/g, '&quot;') + '">'
                + u.unit_name + (u.pack_size > 1 ? ' (' + u.pack_size + ' each)' : '') + '</option>';
        }).join('');
    }

    function handleSelect(item) {
        if (item) {
            costInput.value = item.cost.toFixed(2);
            stockCell.textContent = item.stock;
            productIdField.value = item.id;
            populateUnitSelect(item.id);
        } else {
            stockCell.textContent = '—';
            productIdField.value = '';
            unitSelect.innerHTML = '<option value="">Select product first</option>';
        }
        updateTotal();
    }

    createSearchable(catalogFieldEl, {
        items: catalogOptions(),
        hiddenName: '',
        placeholder: 'Select product',
        initialLabel: prefill ? prefill.name + ' (' + prefill.code + ')' : '',
        initialValue: '',
        onSelect: handleSelect,
    });

    if (prefill) {
        productIdField.value = prefill.id;
        stockCell.textContent = prefill.stock;
        populateUnitSelect(prefill.id);
    }

    function updateTotal() {
        const cost = parseFloat(costInput.value || 0);
        const q = parseInt(qty.value || 0);
        lineTotalEl.textContent = (cost * q).toFixed(2);
        recalcTotals();
    }

    qty.addEventListener('input', updateTotal);
    costInput.addEventListener('input', updateTotal);
    removeBtn.addEventListener('click', function () {
        row.remove();
        recalcTotals();
    });

    updateTotal();
}

function recalcTotals() {
    let total = 0;
    itemsBody.querySelectorAll('.item-row').forEach(function (row) {
        total += parseFloat(row.querySelector('.line-total').textContent || 0);
    });
    document.getElementById('totalDisplay').textContent = 'RWF ' + total.toFixed(2);
}

document.getElementById('addRow').addEventListener('click', function () {
    const row = buildRow(null);
    itemsBody.appendChild(row);
    bindRow(row, null);
});

const lowStockBtn = document.getElementById('addLowStockBtn');
if (lowStockBtn) {
    lowStockBtn.addEventListener('click', function () {
        LOW_STOCK.forEach(function (p) {
            const suggestedQty = Math.max(1, (<?= PO_LOW_STOCK_THRESHOLD; ?> * 2) - p.stock);
            const row = buildRow({ id: p.id, name: p.name, code: p.code, cost: p.cost, stock: p.stock, qty: suggestedQty });
            itemsBody.appendChild(row);
            bindRow(row, { id: p.id, name: p.name, code: p.code, cost: p.cost, stock: p.stock });
        });
    });
}

['saveDraftBtn', 'saveOrderBtn'].forEach(function (btnId) {
    document.getElementById(btnId).addEventListener('click', function () {
        document.getElementById('actionField').value = btnId === 'saveOrderBtn' ? 'order' : 'draft';
    });
});

// Start state
if (PREFILL_LOW_STOCK && lowStockBtn) {
    lowStockBtn.click();
} else {
    document.getElementById('addRow').click();
}
</script>

<?php include '../../includes/footer.php'; ?>