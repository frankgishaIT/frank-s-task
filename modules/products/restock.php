<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require '../../includes/business_party_helpers.php';
require '../../includes/product_unit_helpers.php';
require_once '../../includes/fund_helpers.php';
// NEW: shared stock/Capital Fund rules (weighted-average buying price, STOCK_IN posting).
require_once '../../includes/purchase_order_helpers.php';

// Admin-only action
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
// CHANGED: problems are sent as "error" (they were sent as "success"), and all messages are urlencoded.
if (!$isAdmin) {
    header('Location: index.php?error=' . urlencode('You do not have permission to restock items.'));
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid item selected.')); exit; }

$statement = mysqli_prepare($conn, "SELECT * FROM products WHERE id = ? AND item_type = 'Item'");
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$product = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (!$product) { header('Location: index.php?error=' . urlencode('Item not found or is a service.')); exit; }

$supplierList = business_parties_of_type($conn, 'Supplier');
$productUnits = product_units_for($conn, $id); // this product's Base unit + any Product Units defined in Add/Edit Item

if (isset($_POST['save'])) {
    $packQuantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT); // number of packs
    // "Cost per Unit" is always the price of ONE base unit (e.g. one piece),
    // regardless of what's picked in "Buying As" — matches Purchase Orders.
    $costPerBaseUnit = filter_input(INPUT_POST, 'unit_cost', FILTER_VALIDATE_FLOAT);
    $selectedUnitName = trim($_POST['unit_choice'] ?? '');
    $supplierPartyId = filter_input(INPUT_POST, 'supplier_party_id', FILTER_VALIDATE_INT) ?: null;
    $purchaseDate = $_POST['purchase_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $recordedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $validDate = DateTime::createFromFormat('Y-m-d', $purchaseDate);

    // Resolve the chosen unit against this product's defined units, so the
    // pack size can never be tampered with client-side.
    $matchedUnit = null;
    foreach ($productUnits as $u) { if ($u['unit_name'] === $selectedUnitName) { $matchedUnit = $u; break; } }
    $packLabel = $matchedUnit ? $matchedUnit['unit_name'] : ($product['unit'] ?: 'Piece');
    $packSize = $matchedUnit ? max(1, (int) $matchedUnit['pack_size']) : 1;

    // CHANGED: the supplier must be one of the real RM Suppliers.
    $selectedSupplier = null;
    foreach ($supplierList as $s) { if ((int) $s['id'] === $supplierPartyId) { $selectedSupplier = $s; break; } }

    if (!$packQuantity || $packQuantity <= 0 || $costPerBaseUnit === false || $costPerBaseUnit === null || $costPerBaseUnit < 0 || !$validDate || $validDate->format('Y-m-d') !== $purchaseDate) {
        $error = 'Please enter a valid quantity, cost, and date.';
    } elseif (!$supplierPartyId || !$selectedSupplier) {
        $error = 'Please select a valid Supplier.';
    } elseif (!$matchedUnit) {
        $error = 'Please select a valid unit for this item.';
    } elseif ($costPerBaseUnit <= 0) {
        // NEW: stock added at RWF 0 would enter the shop without any value in the
        // Capital Fund and pull the item's average buying price down.
        $error = 'Cost per Unit must be more than RWF 0.';
    } else {
        $supplier = $selectedSupplier['business_name'];

        // Convert what was actually bought (packs) into base stock units.
        // Cost per Unit is already priced per base unit, so the total just
        // scales with however many base units actually arrived.
        $baseQuantity = $packQuantity * $packSize;
        // CHANGED: rounded to 2 decimals (no float leftovers in the fund).
        $totalCost = round($baseQuantity * $costPerBaseUnit, 2);

        mysqli_begin_transaction($conn);
        try {
            $insertPurchase = mysqli_prepare($conn, 'INSERT INTO purchases
                (product_id, quantity, unit_cost, supplier, supplier_party_id, purchase_date, notes, recorded_by, pack_label, pack_size, pack_quantity)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            // CHANGED: the types were 'iidsisssiii', which bound pack_label (a text such as "Box")
            // as a number, so every restock saved its pack label as "0".
            // Columns: i i d s i s s i s i i
            mysqli_stmt_bind_param($insertPurchase, 'iidsissisii',
                $id, $baseQuantity, $costPerBaseUnit, $supplier, $supplierPartyId, $purchaseDate, $notes, $recordedBy, $packLabel, $packSize, $packQuantity);
            mysqli_stmt_execute($insertPurchase);

            // CHANGED: adds the stock AND updates the buying price to the weighted average of the
            // old and new stock, so later sales take the right cost out of the Capital Fund.
            stock_add_at_average_cost($conn, (int) $id, $baseQuantity, (float) $costPerBaseUnit);

            // Also record this restock as a Purchase Order (status: Received,
            // since the stock has already landed) so it gets the same
            // invoice-style PDF as a regular PO — no separate document type
            // needed for restocks.
            // CHANGED: this now happens BEFORE the fund postings, so they can be linked to it.
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
                $poId, $id, $packQuantity, $costPerBaseUnit, $totalCost, $packLabel, $packSize);
            mysqli_stmt_execute($insertPOItem);

            // Auto-post the restock cost to Transactions as an Expense, paid from the
            // RM Capital Fund (posted immediately, no approval needed).
            // fund_post_automatic_expense() checks the fund balance first. If the
            // Capital Fund cannot cover it, it throws and this whole restock is
            // cancelled below (no stock is added).
            $packSummary = $packQuantity . ' x ' . $packLabel . ($packSize > 1 ? ' (' . $packSize . ' ' . ($product['unit'] ?: 'units') . ' each)' : '');
            $description = 'Restock: ' . $packSummary . ' of ' . $product['product_name']
                . ($supplier !== '' ? ' from ' . $supplier : '');

            // CHANGED: linked to the restock's PO record ('PO' + id).
            fund_post_automatic_expense(
                $conn, 'CAPITAL', 'Purchase (Re-stock)', (float) $totalCost,
                $purchaseDate, $description, $recordedBy, null, 'PO', (int) $poId
            );

            // CHANGED (spec section 7): the buying price of the stock added comes back into the
            // RM Capital Fund as stock value. Recorded as STOCK_IN (was CAPITAL_INFLOW, which
            // the Capital Fund report counts as new money "Contributed"). It mirrors the
            // expense above exactly, the same way a received Purchase Order does.
            po_post_received_income($conn, (int) $poId, $supplier, $purchaseDate, $recordedBy);

            mysqli_commit($conn);
            header('Location: ../purchase_orders/view.php?id=' . $poId . '&success=' . urlencode($baseQuantity . ' ' . ($product['unit'] ?: 'units') . ' added to ' . $product['product_name'] . '.'));
            exit;
        } catch (InsufficientFundException $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('restock failed for product #' . $id . ': ' . $e->getMessage());
            $error = 'Unable to record restock. Please try again.';
        }
    }
}

// Shown on the form so the admin can see if the purchase will be blocked.
$capitalFund = fund_by_code($conn, 'CAPITAL');
$capitalAvailable = $capitalFund ? fund_available($conn, (int) $capitalFund['id']) : null;

include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-box-arrow-in-down';
$modal_title = 'Restock Item';
$modal_subtitle = 'Add new stock and record the purchase.';
?>

<div class="rm-modal-backdrop">
    <div class="rm-modal">
        <?php include '../../includes/model_header.php'; ?>

        <div class="rm-modal-body">
            <div class="d-flex align-items-center justify-content-between mb-3 p-3" style="background:#F8FAFC; border-radius:12px;">
                <div>
                    <div class="fw-semibold"><?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="text-muted small">Code: <?= htmlspecialchars($product['product_code'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="text-end">
                    <div class="text-muted small">Current stock</div>
                    <div class="fw-bold fs-5"><?= (int) $product['quantity']; ?> <?= htmlspecialchars($product['unit'] ?? 'Pieces', ENT_QUOTES, 'UTF-8'); ?></div>
                    <!-- NEW: shows the current (average) buying price that the new cost will be averaged with. -->
                    <div class="text-muted small">Avg. buying price: RWF <?= number_format((float) $product['buying_price'], 2); ?></div>
                </div>
            </div>

            <?php if (isset($error)) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
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
                        <select name="unit_choice" class="form-select rm-input" required>
                            <?php foreach ($productUnits as $u) { ?>
                            <option value="<?= htmlspecialchars($u['unit_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?= htmlspecialchars($u['unit_name'], ENT_QUOTES, 'UTF-8'); ?><?= $u['pack_size'] > 1 ? ' (' . (int) $u['pack_size'] . ' each)' : ''; ?>
                            </option>
                            <?php } ?>
                        </select>
                        <?php if (count($productUnits) <= 1) { ?>
                        <small class="text-muted">Only the base unit is set up for this item. <a href="edit.php?id=<?= (int) $id; ?>">Add more units (Carton, Box, etc.) here</a>.</small>
                        <?php } ?>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Quantity to add</label>
                        <input type="number" name="quantity" class="form-control rm-input" min="1" step="1" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Cost per Unit (RWF)</label>
                    <input type="number" name="unit_cost" class="form-control rm-input" min="0.01" step="0.01" required>
                    <small class="text-muted">Always the price of one <?= htmlspecialchars($product['unit'] ?: 'Piece', ENT_QUOTES, 'UTF-8'); ?>, not the whole pack. The total is calculated automatically.</small>
                </div>

                <div class="mb-3">
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

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Purchase Date</label>
                    <input type="date" name="purchase_date" class="form-control rm-input" value="<?= date('Y-m-d'); ?>" required>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Notes</label>
                    <textarea name="notes" class="form-control rm-input" rows="3" style="height:auto;"></textarea>
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

<?php include '../../includes/footer.php'; ?>