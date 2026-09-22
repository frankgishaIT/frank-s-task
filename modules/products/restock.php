<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require '../../includes/business_party_helpers.php';
require '../../includes/product_unit_helpers.php';

// Admin-only action
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?success=' . urlencode('You do not have permission to restock items.'));
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid item selected.'); exit; }

$statement = mysqli_prepare($conn, "SELECT * FROM products WHERE id = ? AND item_type = 'Item'");
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$product = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (!$product) { header('Location: index.php?success=Item not found or is a service.'); exit; }

$supplierList = business_parties_of_type($conn, 'Supplier');
$productUnits = product_units_for($conn, $id); // this product's Base unit + any Product Units defined in Add/Edit Item

if (isset($_POST['save'])) {
    $packQuantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT); // number of packs
    $costPerPack = filter_input(INPUT_POST, 'unit_cost', FILTER_VALIDATE_FLOAT);
    $selectedUnitName = trim($_POST['unit_choice'] ?? '');
    $supplierPartyId = filter_input(INPUT_POST, 'supplier_party_id', FILTER_VALIDATE_INT) ?: null;
    $purchaseDate = $_POST['purchase_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $recordedBy = $_SESSION['user_id'] ?? null;
    $validDate = DateTime::createFromFormat('Y-m-d', $purchaseDate);

    // Resolve the chosen unit against this product's defined units, so the
    // pack size can never be tampered with client-side.
    $matchedUnit = null;
    foreach ($productUnits as $u) { if ($u['unit_name'] === $selectedUnitName) { $matchedUnit = $u; break; } }
    $packLabel = $matchedUnit ? $matchedUnit['unit_name'] : ($product['unit'] ?: 'Piece');
    $packSize = $matchedUnit ? max(1, (int) $matchedUnit['pack_size']) : 1;

    if (!$packQuantity || $packQuantity <= 0 || $costPerPack === false || $costPerPack < 0 || !$validDate || $validDate->format('Y-m-d') !== $purchaseDate) {
        $error = 'Please enter a valid quantity, cost, and date.';
    } elseif (!$supplierPartyId) {
        $error = 'Please select a Supplier.';
    } elseif (!$matchedUnit) {
        $error = 'Please select a valid unit for this item.';
    } else {
        $selectedSupplier = null;
        foreach ($supplierList as $s) { if ((int) $s['id'] === $supplierPartyId) { $selectedSupplier = $s; break; } }
        $supplier = $selectedSupplier ? $selectedSupplier['business_name'] : '';

        // Convert what was actually bought (packs) into base stock units,
        // and derive the cost per base unit so buying_price/profit math
        // downstream stays consistent regardless of how it was packaged.
        $baseQuantity = $packQuantity * $packSize;
        $costPerBaseUnit = $costPerPack / $packSize;
        $totalCost = $packQuantity * $costPerPack;

        mysqli_begin_transaction($conn);
        try {
            $insertPurchase = mysqli_prepare($conn, 'INSERT INTO purchases
                (product_id, quantity, unit_cost, supplier, supplier_party_id, purchase_date, notes, recorded_by, pack_label, pack_size, pack_quantity)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($insertPurchase, 'iidsisssiii',
                $id, $baseQuantity, $costPerBaseUnit, $supplier, $supplierPartyId, $purchaseDate, $notes, $recordedBy, $packLabel, $packSize, $packQuantity);
            mysqli_stmt_execute($insertPurchase);

            $updateStock = mysqli_prepare($conn, 'UPDATE products SET quantity = quantity + ? WHERE id = ?');
            mysqli_stmt_bind_param($updateStock, 'ii', $baseQuantity, $id);
            mysqli_stmt_execute($updateStock);

            // Auto-post the restock cost to Transactions as an Expense.
            // No approval needed — mirrors how sales_finalize() posts income
            // for Sales, so it appears in Transactions immediately.
            $packSummary = $packQuantity . ' x ' . $packLabel . ($packSize > 1 ? ' (' . $packSize . ' ' . ($product['unit'] ?: 'units') . ' each)' : '');
            $description = 'Restock: ' . $packSummary . ' of ' . $product['product_name']
                . ($supplier !== '' ? ' from ' . $supplier : '');
            $insertTransaction = mysqli_prepare($conn, "INSERT INTO transactions
                (category, transaction_type, amount, transaction_date, description, recorded_by, status)
                VALUES ('Purchase (Re-stock)', 'Expense', ?, ?, ?, ?, 'approved')");
            mysqli_stmt_bind_param($insertTransaction, 'dssi', $totalCost, $purchaseDate, $description, $recordedBy);
            mysqli_stmt_execute($insertTransaction);

            // Also record this restock as a Purchase Order (status: Received,
            // since the stock has already landed) so it gets the same
            // invoice-style PDF as a regular PO — no separate document type
            // needed for restocks.
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

            mysqli_commit($conn);
            header('Location: ../purchase_orders/view.php?id=' . $poId . '&success=' . urlencode($baseQuantity . ' ' . ($product['unit'] ?: 'units') . ' added to ' . $product['product_name'] . '.'));
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = 'Unable to record restock. Please try again.';
        }
    }
}

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
                </div>
            </div>

            <?php if (isset($error)) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php } ?>

            <form method="POST" id="restockForm">
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Buying As</label>
                        <select name="unit_choice" class="form-select rm-input" required>
                            <?php foreach ($productUnits as $u) { ?>
                            <option value="<?= htmlspecialchars($u['unit_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?= htmlspecialchars($u['unit_name'], ENT_QUOTES, 'UTF-8'); ?><?= $u['pack_size'] > 1 ? ' (' . $u['pack_size'] . ' each)' : ''; ?>
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
                    <input type="number" name="unit_cost" class="form-control rm-input" min="0" step="0.01" required>
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