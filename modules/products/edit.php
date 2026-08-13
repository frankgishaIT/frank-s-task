<?php
session_start();
require '../../config/db.php';

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

if (isset($_POST['update'])) {
    $itemType = in_array($_POST['item_type'] ?? '', ['Item', 'Service'], true) ? $_POST['item_type'] : 'Item';
    $product_name = trim($_POST['product_name'] ?? '');
    // product_code is not editable — always keep the original generated code.
    $product_code = $product['product_code'];
    $description = trim($_POST['description'] ?? '');
    $selling_price = filter_input(INPUT_POST, 'selling_price', FILTER_VALIDATE_FLOAT);

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
        $statement = mysqli_prepare($conn, "UPDATE products SET
            item_type = ?, product_name = ?, description = ?,
            buying_price = ?, selling_price = ?, quantity = ?, unit = ? WHERE id = ?");
        mysqli_stmt_bind_param($statement, 'sssddisi', $itemType, $product_name, $description, $buying_price, $selling_price, $quantity, $unit, $id);

        if (mysqli_stmt_execute($statement)) {
            header("Location: index.php?success=" . $itemType . " updated successfully.");
            exit;
        }

        $error = "Unable to update. Please try again.";
    }
    $product = array_merge($product, ['item_type' => $itemType, 'product_name' => $product_name, 'product_code' => $product_code, 'description' => $description, 'buying_price' => $buying_price, 'selling_price' => $selling_price, 'quantity' => $quantity, 'unit' => $unit]);
}

$modal_icon = 'bi-box-seam';
$modal_title = 'Edit Item or Service';
$modal_subtitle = 'Update these catalog details.';
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

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

                <div class="row g-3 mb-4">
                    <div class="col-3 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Buying Price</label>
                        <input type="number" step="0.01" min="0" name="buying_price" class="form-control rm-input" value="<?= htmlspecialchars((string) $product['buying_price'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-3">
                        <label class="form-label small fw-semibold text-muted" id="priceLabel">Selling Price</label>
                        <input type="number" step="0.01" min="0" name="selling_price" class="form-control rm-input" value="<?= htmlspecialchars((string) $product['selling_price'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="col-3 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Quantity</label>
                        <input type="number" step="1" min="0" name="quantity" class="form-control rm-input" value="<?= htmlspecialchars((string) ($product['quantity'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-3 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Unit</label>
                        <select name="unit" class="form-select rm-input">
                            <option value="Pieces" <?= ($product['unit'] ?? 'Pieces') === 'Pieces' ? 'selected' : ''; ?>>Pieces</option>
                            <option value="Boxes" <?= ($product['unit'] ?? '') === 'Boxes' ? 'selected' : ''; ?>>Boxes</option>
                        </select>
                    </div>
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

function toggleFields() {
    const isService = itemTypeSelect.value === 'Service';
    itemOnlyFields.forEach(function (el) {
        el.style.display = isService ? 'none' : '';
        el.querySelectorAll('input, select').forEach(function (input) { input.disabled = isService; });
    });
    nameLabel.textContent = isService ? 'Service Name' : 'Item Name';
    priceLabel.textContent = isService ? 'Price' : 'Selling Price';
}

itemTypeSelect.addEventListener('change', toggleFields);
toggleFields();
</script>

<?php include '../../includes/footer.php'; ?>