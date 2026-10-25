<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require '../../includes/business_party_helpers.php';
require '../../includes/product_unit_helpers.php';
require_once '../../includes/fund_helpers.php';
// Shared stock/Capital Fund rules (weighted-average buying price, STOCK_IN posting).
require_once '../../includes/purchase_order_helpers.php';

// Admin-only action
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?error=' . urlencode('You do not have permission to restock products.'));
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid product selected.')); exit; }

$statement = mysqli_prepare($conn, "SELECT * FROM products WHERE id = ? AND item_type = 'Item'");
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$product = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (!$product) { header('Location: index.php?error=' . urlencode('Product not found, or it is a service (services have no stock).')); exit; }

$baseUnit = $product['unit'] ?: 'Piece';
$supplierList = business_parties_of_type($conn, 'Supplier');
$productUnits = product_units_for($conn, $id); // this product's base unit + its sub-units (Add/Edit Product)
// NEW: a supplier registered without a business name shows its name.
$supplierName = function (array $s): string { return trim((string) ($s['business_name'] ?? '')) !== '' ? $s['business_name'] : (string) ($s['name'] ?? ''); };

if (isset($_POST['save'])) {
    $packQuantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT); // how many of the chosen unit
    $costEntered = filter_input(INPUT_POST, 'unit_cost', FILTER_VALIDATE_FLOAT);
    // NEW (Task 2): the cost can be typed per base unit OR per the unit being bought (e.g. per Carton).
    $costBasis = ($_POST['cost_basis'] ?? 'base') === 'pack' ? 'pack' : 'base';
    $selectedUnitName = trim($_POST['unit_choice'] ?? '');
    $supplierPartyId = filter_input(INPUT_POST, 'supplier_party_id', FILTER_VALIDATE_INT) ?: null;
    $purchaseDate = $_POST['purchase_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $recordedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $validDate = DateTime::createFromFormat('Y-m-d', $purchaseDate);

    // The chosen unit is resolved against this product's own units, so the conversion can never be
    // changed in the browser.
    $matchedUnit = null;
    foreach ($productUnits as $u) { if ($u['unit_name'] === $selectedUnitName) { $matchedUnit = $u; break; } }
    $packLabel = $matchedUnit ? $matchedUnit['unit_name'] : $baseUnit;
    $packSize = $matchedUnit ? max(1, (int) $matchedUnit['pack_size']) : 1;

    // The supplier must be one of the real RM Suppliers.
    $selectedSupplier = null;
    foreach ($supplierList as $s) { if ((int) $s['id'] === $supplierPartyId) { $selectedSupplier = $s; break; } }

    if (!$packQuantity || $packQuantity <= 0 || $costEntered === false || $costEntered === null || !$validDate || $validDate->format('Y-m-d') !== $purchaseDate) {
        $error = 'Please enter a valid quantity, cost, and date.';
    } elseif ($purchaseDate > date('Y-m-d')) {
        // NEW: the money leaves the Capital Fund now, so the purchase cannot be dated in the future.
        $error = 'The purchase date cannot be in the future.';
    } elseif (!$supplierPartyId || !$selectedSupplier) {
        $error = 'Please select a valid Supplier.';
    } elseif (!$matchedUnit) {
        $error = 'Please select a valid unit for this product.';
    } elseif ($costEntered <= 0) {
        // Stock added at RWF 0 would enter the shop without any value in the Capital Fund
        // and pull the product's average buying price down.
        $error = 'The cost must be more than RWF 0.';
    } else {
        $supplier = $supplierName($selectedSupplier);

        // Convert what was bought into base units. The TOTAL is computed from what was actually
        // typed, so it is exact (10 Cartons at RWF 1,000 = RWF 10,000, never 10,000.80).
        $baseQuantity = $packQuantity * $packSize;
        if ($costBasis === 'pack' && $packSize > 1) {
            $totalCost = round($packQuantity * $costEntered, 2);
        } else {
            $totalCost = round($baseQuantity * $costEntered, 2);
        }
        $costPerBaseUnit = $totalCost / $baseQuantity;          // exact, for the weighted average
        $costPerBaseUnitStored = round($costPerBaseUnit, 2);    // shown on records

        mysqli_begin_transaction($conn);
        try {
            $insertPurchase = mysqli_prepare($conn, 'INSERT INTO purchases
                (product_id, quantity, unit_cost, supplier, supplier_party_id, purchase_date, notes, recorded_by, pack_label, pack_size, pack_quantity)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            // Columns: i i d s i s s i s i i
            mysqli_stmt_bind_param($insertPurchase, 'iidsissisii',
                $id, $baseQuantity, $costPerBaseUnitStored, $supplier, $supplierPartyId, $purchaseDate, $notes, $recordedBy, $packLabel, $packSize, $packQuantity);
            mysqli_stmt_execute($insertPurchase);

            // Adds the stock AND updates the buying price to the weighted average of the old and
            // new stock, so later sales take the right cost out of the Capital Fund.
            stock_add_at_average_cost($conn, (int) $id, $baseQuantity, (float) $costPerBaseUnit);

            // Also recorded as a Purchase Order (status: Received, since the stock has already
            // landed): same PDF as a regular PO, and the payment is linked to it (Payables report).
            $poNotes = trim('Recorded via Restock.' . ($notes !== '' ? ' ' . $notes : ''));
            $orderedAt = date('Y-m-d H:i:s');
            $insertPO = mysqli_prepare($conn, "INSERT INTO purchase_orders
                (supplier, supplier_party_id, order_date, expected_delivery_date, status, total_amount, notes, created_by, ordered_by, ordered_at)
                VALUES (?, ?, ?, NULL, 'Received', ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($insertPO, 'sisdsiis',
                $supplier, $supplierPartyId, $purchaseDate, $totalCost, $poNotes, $recordedBy, $recordedBy, $orderedAt);
            mysqli_stmt_execute($insertPO);
            $poId = mysqli_insert_id($conn);

            $insertPOItem = mysqli_prepare($conn, 'INSERT INTO purchase_order_items
                (purchase_order_id, product_id, quantity, unit_cost, line_total, pack_label, pack_size)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($insertPOItem, 'iiiddsi',
                $poId, $id, $packQuantity, $costPerBaseUnitStored, $totalCost, $packLabel, $packSize);
            mysqli_stmt_execute($insertPOItem);

            // The restock cost is posted to Transactions as an Expense paid from the RM Capital Fund
            // (no approval needed). If the fund cannot cover it, the whole restock is cancelled.
            $packSummary = $packQuantity . ' x ' . $packLabel . ($packSize > 1 ? ' (' . $packSize . ' ' . $baseUnit . ' each)' : '');
            $description = 'Restock: ' . $packSummary . ' of ' . $product['product_name'] . ($supplier !== '' ? ' from ' . $supplier : '');
            fund_post_automatic_expense(
                $conn, 'CAPITAL', 'Purchase (Re-stock)', (float) $totalCost,
                $purchaseDate, $description, $recordedBy, null, 'PO', (int) $poId
            );

            // The value of the stock added comes back into the RM Capital Fund as stock (STOCK_IN),
            // mirroring the expense above exactly, like a received Purchase Order.
            po_post_received_income($conn, (int) $poId, $supplier, $purchaseDate, $recordedBy);

            mysqli_commit($conn);
            header('Location: ../purchase_orders/view.php?id=' . $poId . '&success=' . urlencode(
                $packSummary . ' = ' . number_format($baseQuantity) . ' ' . $baseUnit . ' added to ' . $product['product_name']
                . ' (RWF ' . number_format($totalCost, 2) . ').'));
            exit;
        } catch (InsufficientFundException $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('restock failed for product #' . $id . ': ' . $e->getMessage());
            $error = 'Unable to record the restock. Nothing was saved. [Technical detail for Admin: ' . $e->getMessage() . ']';
        }
    }
}

// Shown on the form so the admin can see if the purchase will be blocked.
$capitalFund = fund_by_code($conn, 'CAPITAL');
$capitalAvailable = ($capitalFund && mm_has_feature('funds')) ? fund_available($conn, (int) $capitalFund['id']) : null; // PACKAGES

include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-box-arrow-in-down';
$modal_title = 'Restock Product';
$modal_subtitle = 'Add new stock and record the purchase.';
$h = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
?>

<div class="rm-modal-backdrop">
    <div class="rm-modal">
        <?php include '../../includes/model_header.php'; ?>

        <div class="rm-modal-body">
            <div class="d-flex align-items-center justify-content-between mb-3 p-3" style="background:#F8FAFC; border-radius:12px;">
                <div>
                    <div class="fw-semibold"><?= $h($product['product_name']); ?></div>
                    <div class="text-muted small">Code: <?= $h($product['product_code']); ?></div>
                </div>
                <div class="text-end">
                    <div class="text-muted small">Current stock</div>
                    <div class="fw-bold fs-5"><?= number_format((int) $product['quantity']); ?> <?= $h($baseUnit); ?></div>
                    <div class="text-muted small">Avg. buying price: RWF <?= number_format((float) $product['buying_price'], 2); ?> per <?= $h($baseUnit); ?></div>
                </div>
            </div>

            <?php if (isset($error)) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= $h($error); ?>
            </div>
            <?php } ?>

            <?php if ($capitalAvailable !== null) { ?>
            <div class="alert alert-info mb-3" style="border-radius:10px; border:none; font-size:13px; padding:10px 14px;">
                <i class="bi bi-wallet2 me-1"></i>
                Stock purchases are paid from the RM Capital Fund. Available now: <strong>RWF <?= number_format($capitalAvailable, 2); ?></strong>.
            </div>
            <?php } ?>

            <form method="POST" id="restockForm">
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Buying As</label>
                        <select name="unit_choice" id="unitChoice" class="form-select rm-input" required data-no-search>
                            <?php foreach ($productUnits as $u) { ?>
                            <option value="<?= $h($u['unit_name']); ?>" data-size="<?= max(1, (int) $u['pack_size']); ?>" <?= ($_POST['unit_choice'] ?? '') === $u['unit_name'] ? 'selected' : ''; ?>>
                                <?= $h($u['unit_name']); ?><?= $u['pack_size'] > 1 ? ' (' . (int) $u['pack_size'] . ' ' . $h($baseUnit) . ' each)' : ''; ?>
                            </option>
                            <?php } ?>
                        </select>
                        <?php if (count($productUnits) <= 1) { ?>
                        <small class="text-muted">Only the base unit is set up for this product. <a href="edit.php?id=<?= (int) $id; ?>">Add sub-units (Carton, Box, etc.) here</a>.</small>
                        <?php } ?>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Quantity to add (<span id="qtyUnitLabel"><?= $h($baseUnit); ?></span>)</label>
                        <input type="number" name="quantity" id="qtyInput" class="form-control rm-input" min="1" step="1" value="<?= $h($_POST['quantity'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="row g-3 mb-1">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Cost (RWF)</label>
                        <input type="number" name="unit_cost" id="costInput" class="form-control rm-input" min="0.01" step="0.01" value="<?= $h($_POST['unit_cost'] ?? ''); ?>" required>
                    </div>
                    <div class="col-6">
                        <!-- NEW (Task 2): which unit the cost is for -->
                        <label class="form-label small fw-semibold text-muted">Cost is per</label>
                        <select name="cost_basis" id="costBasis" class="form-select rm-input" data-no-search>
                            <option value="base" <?= ($_POST['cost_basis'] ?? 'base') === 'base' ? 'selected' : ''; ?>>1 <?= $h($baseUnit); ?> (base unit)</option>
                            <option value="pack" <?= ($_POST['cost_basis'] ?? '') === 'pack' ? 'selected' : ''; ?>>1 unit bought</option>
                        </select>
                    </div>
                </div>
                <!-- NEW: live summary, so the unit of every quantity and price is clear before saving -->
                <div id="restockSummary" class="small mb-3 p-2" style="background:#EEF3FF; border-radius:8px; display:none;"></div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Supplier</label>
                    <select name="supplier_party_id" class="form-select rm-input" required>
                        <option value="">Select supplier</option>
                        <?php foreach ($supplierList as $s) { ?>
                        <option value="<?= (int) $s['id']; ?>" <?= (string) ($_POST['supplier_party_id'] ?? '') === (string) $s['id'] ? 'selected' : ''; ?>><?= $h($supplierName($s)); ?></option>
                        <?php } ?>
                    </select>
                    <?php if (empty($supplierList)) { ?>
                    <small class="text-danger">No RM Suppliers found. <a href="../business_parties/create.php">Add one first</a>.</small>
                    <?php } ?>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Purchase Date</label>
                    <input type="date" name="purchase_date" class="form-control rm-input" max="<?= date('Y-m-d'); ?>" value="<?= $h($_POST['purchase_date'] ?? date('Y-m-d')); ?>" required>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Notes</label>
                    <textarea name="notes" class="form-control rm-input" rows="3" style="height:auto;"><?= $h($_POST['notes'] ?? ''); ?></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button type="submit" name="save" class="rm-btn rm-btn-primary">
                        <i class="bi bi-check-circle-fill me-2"></i>Add Stock
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-secondary">
                        <i class="bi bi-x-circle-fill me-2"></i>Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var BASE = <?= json_encode($baseUnit, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var unitChoice = document.getElementById('unitChoice'), qty = document.getElementById('qtyInput');
    var cost = document.getElementById('costInput'), basis = document.getElementById('costBasis');
    var summary = document.getElementById('restockSummary'), qtyLabel = document.getElementById('qtyUnitLabel');
    function money(n) { return 'RWF ' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function sync() {
        var opt = unitChoice.options[unitChoice.selectedIndex];
        var name = opt ? opt.value : BASE, size = opt ? parseInt(opt.getAttribute('data-size') || '1', 10) : 1;
        qtyLabel.textContent = name;
        // "per unit bought" only makes sense when buying in a sub-unit
        basis.options[1].textContent = '1 ' + name + (size > 1 ? ' (' + size + ' ' + BASE + ')' : '');
        basis.options[1].disabled = size <= 1;
        if (size <= 1) { basis.value = 'base'; }
        var q = parseInt(qty.value || '0', 10), c = parseFloat(cost.value || '0');
        if (!(q > 0) || !(c > 0)) { summary.style.display = 'none'; return; }
        var baseQty = q * size;
        var total = basis.value === 'pack' && size > 1 ? q * c : baseQty * c;
        var perBase = total / baseQty;
        var parts = [];
        parts.push('<strong>' + q.toLocaleString() + ' ' + name + (size > 1 ? ' × ' + size + ' = ' + baseQty.toLocaleString() + ' ' + BASE : '') + '</strong>');
        if (size > 1) { parts.push(money(perBase * size) + ' per ' + name + ' = ' + money(perBase) + ' per ' + BASE); }
        else { parts.push(money(perBase) + ' per ' + BASE); }
        parts.push('<strong>Total ' + money(total) + '</strong> (paid from the RM Capital Fund)');
        summary.innerHTML = parts.join(' &nbsp;·&nbsp; ');
        summary.style.display = '';
    }
    [unitChoice, qty, cost, basis].forEach(function (el) { el.addEventListener('input', sync); el.addEventListener('change', sync); });
    sync();
})();
</script>

<?php include '../../includes/footer.php'; ?>