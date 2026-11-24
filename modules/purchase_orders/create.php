<?php
require '../../config/db.php';
require '../../includes/purchase_order_helpers.php';
require '../../includes/business_party_helpers.php';
require '../../includes/product_unit_helpers.php';
require_once '../../includes/sms_messages.php'; // NEW: supplier SMS (MY MOTIVE SMS, message 6)
require_role(['Admin', 'Manager']);

$catalog = mysqli_query($conn, "SELECT id, product_name, product_code, buying_price, quantity, unit FROM products WHERE item_type = 'Item' AND is_active = 1 ORDER BY product_name");
$catalogList = [];
while ($p = mysqli_fetch_assoc($catalog)) { $catalogList[] = $p; }

$unitsMap = product_units_map($conn, array_map(function ($p) { return $p['id']; }, $catalogList));

$supplierList = business_parties_of_type($conn, 'Supplier');
$lowStockList = po_low_stock_products($conn);
$prefillLowStock = isset($_GET['from_low_stock']);
// NEW (RM Market Insights): "Create purchase order" from an approved opportunity — supplier, product, approved
// quantity and approved purchase price are filled in, and the order is linked back to the opportunity.
$miOpp = null; $miPrefill = null; $miSupplier = 0;
$miId = (int) ($_POST['mi_opportunity_id'] ?? $_GET['mi'] ?? 0);
if ($miId) {
    require_once __DIR__ . '/../../includes/market_ops.php';
    mi_ops_ensure($conn);
    $miOpp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mi_opportunities WHERE id = $miId AND kind = 'product' AND product_id IS NOT NULL AND status IN ('Approved', 'In Implementation')"));
    if ($miOpp) {
        $dec = mi_last_decision($conn, $miId, 'Approved');
        $q = $dec && $dec['quote_id'] ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM mi_quotes WHERE id = ' . (int) $dec['quote_id'])) : null;
        $miSupplier = $q && $q['supplier_party_id'] ? (int) $q['supplier_party_id'] : 0;
        foreach ($catalogList as $p) { if ((int) $p['id'] === (int) $miOpp['product_id']) {
            $miPrefill = ['id' => (int) $p['id'], 'name' => $p['product_name'], 'code' => $p['product_code'], 'stock' => (int) $p['quantity'],
                          'cost' => $q ? (float) $q['unit_price'] : (float) $p['buying_price'], 'qty' => max(1, (int) round((float) ($dec['approved_qty'] ?? 1)))];
        } }
        $miNote = 'RM Market Insights: approved opportunity "' . $miOpp['name'] . '" (#' . $miId . ')' . ($q && !$miSupplier ? ' — quoted supplier: ' . $q['supplier_name'] . ' (not yet in RM Suppliers)' : '') . '.';
    }
}

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
        $matchedUnit = product_unit_match($productUnits, $selectedUnitName); // CHANGED: ignores stray spaces / capitals
        if (!$matchedUnit) {
            $lineError = 'Please select a valid unit for "' . $found['product_name'] . '".';
            break;
        }

        if ($unitCost < 0) {
            $lineError = 'Cost cannot be negative for "' . $found['product_name'] . '".';
            break;
        }

        // Cost / Unit is always entered as the price per base unit (e.g. per
        // piece). When buying in a pack (e.g. a Box of 54), the line total
        // must scale by the pack size, not just by the number of packs.
        $packSize = max(1, (int) $matchedUnit['pack_size']);
        // CHANGED: rounded to 2 decimals, so the PO total and the Capital Fund expense
        // never carry tiny float leftovers (e.g. 50000.000000001).
        $lineTotal = round($qty * $packSize * $unitCost, 2);
        $total += $lineTotal;
        $lineItems[] = [
            'product_id' => $productId, 'quantity' => $qty, 'unit_cost' => $unitCost,
            'line_total' => $lineTotal, 'pack_label' => $matchedUnit['unit_name'], 'pack_size' => $packSize,
        ];
    }
    $total = round($total, 2);

    // CHANGED: the supplier must be one of the real RM Suppliers. Before, any id was accepted
    // and the PO was saved with an empty supplier name.
    $selectedSupplier = null;
    foreach ($supplierList as $s) { if ((int) $s['id'] === $supplierPartyId) { $selectedSupplier = $s; break; } }

    // NEW (Multi-branch): purchase orders (requesting and receiving branch) are recorded in the branch you are working in. A CLOSED or TEMPORARILY SUSPENDED branch cannot record new ones.
    require_once __DIR__ . '/../../includes/branch_helpers.php'; // NEW (Multi-branch)
    $brBranch = br_write_branch($conn);
    if (($brBlock = br_operation_block($conn, $brBranch, 'purchases')) !== null) {
        $error = $brBlock;
    } elseif (!$validOrderDate || $validOrderDate->format('Y-m-d') !== $orderDate) {
        $error = 'Please provide a valid order date.';
    } elseif ($expectedDelivery !== '' && (!$validExpectedDate || $validExpectedDate->format('Y-m-d') !== $expectedDelivery)) {
        $error = 'Please provide a valid expected delivery date.';
    } elseif (!$supplierPartyId || !$selectedSupplier) {
        $error = 'Please select a valid Supplier.';
    } elseif (empty($lineItems)) {
        $error = 'Add at least one product with a valid quantity.';
    } elseif ($lineError) {
        $error = $lineError;
    } elseif (!in_array($action, ['draft', 'order'], true)) {
        $error = 'Invalid action.';
    } elseif ($action === 'order' && $total <= 0) {
        // NEW: an "Ordered" PO worth RWF 0 would post nothing to the Capital Fund and
        // later bring stock in for free, which breaks the Capital Fund = Stock rule.
        $error = 'An order with a total of RWF 0 cannot be marked as Ordered. Please enter the cost of the products.';
    } else {
        $userId = current_user_id();
        $status = $action === 'order' ? 'Ordered' : 'Draft';
        $orderedBy = $action === 'order' ? $userId : null;
        $orderedAt = $action === 'order' ? date('Y-m-d H:i:s') : null;
        $expectedDeliveryValue = $expectedDelivery !== '' ? $expectedDelivery : null;

        $supplier = $selectedSupplier['business_name'];

        // CHANGED: the PO, its lines and (for "Mark as Ordered") the Capital Fund expense are
        // saved together. If the Capital Fund cannot cover the order, NOTHING is saved.
        $saved = false;
        mysqli_begin_transaction($conn);
        try {
            $poStatement = mysqli_prepare($conn, 'INSERT INTO purchase_orders
                (supplier, supplier_party_id, order_date, expected_delivery_date, status, total_amount, notes, created_by, ordered_by, ordered_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($poStatement, 'sisssdsiis',
                $supplier, $supplierPartyId, $orderDate, $expectedDeliveryValue, $status, $total, $notes, $userId, $orderedBy, $orderedAt);
            mysqli_stmt_execute($poStatement);
            $poId = mysqli_insert_id($conn);
            br_stamp($conn, 'purchase_orders', (int) $poId, $brBranch); // NEW (Multi-branch): requesting branch
            if ($brBranch) { mysqli_query($conn, 'UPDATE purchase_orders SET receiving_branch_id = ' . (int) $brBranch . ' WHERE id = ' . (int) $poId . ' AND receiving_branch_id IS NULL'); } // receiving branch (same by default)

            $itemStatement = mysqli_prepare($conn, 'INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, unit_cost, line_total, pack_label, pack_size) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($lineItems as $item) {
                mysqli_stmt_bind_param($itemStatement, 'iiiddsi', $poId, $item['product_id'], $item['quantity'], $item['unit_cost'], $item['line_total'], $item['pack_label'], $item['pack_size']);
                mysqli_stmt_execute($itemStatement);
            }

            // NEW (spec section 4): an order created as "Ordered" takes its total out of the
            // RM Capital Fund right now.
            if ($action === 'order') {
                po_post_ordered_expense($conn, (int) $poId, (float) $total, (string) $supplier, $userId ? (int) $userId : null);
            }

            if ($miOpp) { // NEW (RM Market Insights): link the order to the opportunity
                mi_link_add($conn, (int) $miOpp['id'], 'po', (int) $poId);
                mi_audit($conn, (int) $miOpp['id'], null, 'purchase_order', 'Purchase order #' . (int) $poId . ' (' . $status . ', ' . number_format($total, 2) . ')');
                if ($miOpp['status'] === 'Approved') { mysqli_query($conn, "UPDATE mi_opportunities SET status = 'In Implementation' WHERE id = " . (int) $miOpp['id']); }
            }
            mysqli_commit($conn);
            $saved = true;
        } catch (InsufficientFundException $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage() . ' The Purchase Order was not saved. You can save it as a Draft instead.';
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('new_purchase_order failed: ' . $e->getMessage());
            $error = 'Unable to save the Purchase Order. Nothing was saved. Please try again.';
        }

        if ($saved) {
            // NEW: tell the supplier about the order (only for "Mark as Ordered", not Drafts).
            if ($action === 'order') {
                sms_notify_po_ordered($conn, (int) $poId, $userId ? (int) $userId : null);
            }
            header('Location: view.php?id=' . $poId . '&success=' . urlencode('Purchase Order ' . ($action === 'order' ? 'created and marked as Ordered.' : 'saved as Draft.')));
            exit;
        }
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
            <?php if ($miOpp) { ?><input type="hidden" name="mi_opportunity_id" value="<?= (int) $miOpp['id']; ?>"><div class="alert alert-info small py-2" style="border-radius:10px"><?= htmlspecialchars($miNote, ENT_QUOTES, 'UTF-8'); ?> Check the details, then save.</div><?php } // NEW ?>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Supplier</label>
                    <select name="supplier_party_id" class="form-select rm-input" required>
                        <option value="">Select supplier</option>
                        <?php foreach ($supplierList as $s) { ?>
                        <option value="<?= (int) $s['id']; ?>" <?= (int) ($_POST['supplier_party_id'] ?? $miSupplier) === (int) $s['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($s['business_name'], ENT_QUOTES, 'UTF-8'); ?></option>
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
            <p class="small text-muted mb-2">Pick how you're buying each product — units are set up per item in <a href="../products/index.php">RM Offerings</a>. Cost / Unit is always the price per single piece; the Line Total scales automatically for boxes/packs.</p>
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
                <textarea name="notes" class="form-control rm-input" rows="2" style="height:auto;"><?= htmlspecialchars((string) ($_POST['notes'] ?? ($miOpp ? $miNote : '')), ENT_QUOTES, 'UTF-8'); ?></textarea>
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
// CHANGED: escape any text before it is placed into the page with innerHTML, so product
// or unit names containing < > " ' & cannot break the page or run script.
function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

const CATALOG = <?= json_encode(array_map(function ($p) {
    return ['id' => (int) $p['id'], 'name' => $p['product_name'], 'code' => $p['product_code'], 'cost' => (float) $p['buying_price'], 'stock' => (int) $p['quantity'], 'unit' => $p['unit']];
}, $catalogList), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const LOW_STOCK = <?= json_encode(array_map(function ($p) {
    return ['id' => (int) $p['id'], 'name' => $p['product_name'], 'code' => $p['product_code'], 'cost' => (float) $p['buying_price'], 'stock' => (int) $p['quantity'], 'unit' => $p['unit']];
}, $lowStockList), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const UNITS_MAP = <?= json_encode($unitsMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>; // { productId: [ {unit_name, pack_size, is_base}, ... ] }
const PREFILL_LOW_STOCK = <?= $prefillLowStock ? 'true' : 'false'; ?>;
const MI_PREFILL = <?= json_encode($miPrefill, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>; // NEW (RM Market Insights)

const itemsBody = document.querySelector('#itemsTable tbody');

function createSearchable(wrapper, options) {
    const { items, hiddenName, placeholder, initialLabel, initialValue, onSelect } = options;
    // CHANGED: all values escaped.
    wrapper.innerHTML =
        '<input type="text" class="form-control rm-input rs-input" autocomplete="off" placeholder="' + escapeHtml(placeholder) + '" value="' + escapeHtml(initialLabel || '') + '">' +
        '<input type="hidden" name="' + escapeHtml(hiddenName) + '" value="' + escapeHtml(initialValue !== undefined ? initialValue : '') + '">';
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
        // CHANGED: id, label and meta escaped.
        dropdown.innerHTML = filtered.length === 0
            ? '<div class="rm-searchable-empty">No matches found</div>'
            : filtered.map(function (it) {
                const meta = it.meta ? '<span class="opt-meta">' + escapeHtml(it.meta) + '</span>' : '';
                return '<div class="rm-searchable-option" data-id="' + escapeHtml(it.id) + '"><span>' + escapeHtml(it.label) + '</span>' + meta + '</div>';
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
        '<td><input type="number" class="form-control rm-input qty-input" name="quantity[]" min="1" value="' + (prefill && prefill.qty ? parseInt(prefill.qty) : 1) + '" required></td>' +
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

    // Tracks the pack size (units per pack) of whichever "Buying As" option
    // is currently selected, so the line total can scale correctly. Cost /
    // Unit is always the price of ONE base unit (e.g. one piece).
    let currentPackSize = 1;

    function packSizeForSelectedUnit() {
        const units = UNITS_MAP[productIdField.value] || [{ unit_name: 'Piece', pack_size: 1, is_base: true }];
        const match = units.find(function (u) { return u.unit_name === unitSelect.value; });
        return match ? Math.max(1, parseInt(match.pack_size) || 1) : 1;
    }

    function populateUnitSelect(productId) {
        const units = UNITS_MAP[productId] || [{ unit_name: 'Piece', pack_size: 1, is_base: true }];
        // CHANGED: unit names escaped (value and visible text).
        unitSelect.innerHTML = units.map(function (u) {
            return '<option value="' + escapeHtml(u.unit_name) + '">'
                + escapeHtml(u.unit_name) + (u.pack_size > 1 ? ' (' + escapeHtml(u.pack_size) + ' each)' : '') + '</option>';
        }).join('');
        currentPackSize = packSizeForSelectedUnit();
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
            currentPackSize = 1;
        }
        updateTotal();
    }

    const catalogWidget = createSearchable(catalogFieldEl, {
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
        lineTotalEl.textContent = (cost * q * currentPackSize).toFixed(2);
        recalcTotals();
    }

    // Switching "Buying As" (e.g. Piece -> Box of 54) must re-scale the
    // line total, since Cost / Unit stays priced per single piece.
    unitSelect.addEventListener('change', function () {
        currentPackSize = packSizeForSelectedUnit();
        updateTotal();
    });

    qty.addEventListener('input', updateTotal);
    costInput.addEventListener('input', updateTotal);
    removeBtn.addEventListener('click', function () {
        // CHANGED: also remove the row's dropdown, which lives outside the table.
        catalogWidget.destroy();
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
            const suggestedQty = Math.max(1, (<?= (int) PO_LOW_STOCK_THRESHOLD; ?> * 2) - p.stock);
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
if (MI_PREFILL) { // NEW (RM Market Insights): the approved product, quantity and price
    const miRow = buildRow(MI_PREFILL);
    itemsBody.appendChild(miRow);
    bindRow(miRow, MI_PREFILL);
} else if (PREFILL_LOW_STOCK && lowStockBtn) {
    lowStockBtn.click();
} else {
    document.getElementById('addRow').click();
}
</script>

<?php include '../../includes/footer.php'; ?>