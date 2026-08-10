<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';

function generate_product_code($conn, $itemType) {
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

    $next = $max + 1;
    return $prefix . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
}

if (isset($_POST['save'])) {

    $itemType = in_array($_POST['item_type'] ?? '', ['Item', 'Service'], true) ? $_POST['item_type'] : 'Item';
    $product_name = trim($_POST['product_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $selling_price = filter_input(INPUT_POST, 'selling_price', FILTER_VALIDATE_FLOAT);

    if ($itemType === 'Item') {
        $buying_price = filter_input(INPUT_POST, 'buying_price', FILTER_VALIDATE_FLOAT);
        $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
        $quantity = $quantity === false || $quantity === null ? 0 : $quantity;
        $unit = in_array($_POST['unit'] ?? '', ['Pieces', 'Boxes'], true) ? $_POST['unit'] : 'Pieces';
    } else {
        // Services carry no cost price, stock, or unit of measure.
        $buying_price = 0.00;
        $quantity = 0;
        $unit = 'Pieces';
    }

    if ($product_name === '' || $selling_price === false || ($itemType === 'Item' && $buying_price === false)) {
        $error = "Please provide valid details.";
    } else {
        $product_code = generate_product_code($conn, $itemType);

        $statement = mysqli_prepare($conn, "INSERT INTO products
            (item_type, product_name, product_code, description, buying_price, selling_price, quantity, unit)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($statement, 'ssssddis', $itemType, $product_name, $product_code, $description, $buying_price, $selling_price, $quantity, $unit);

        if (mysqli_stmt_execute($statement)) {
            notify(
    $conn,
    'Product Added',
    '"' . $product_name . '" (' . $product_code . ') has been added.'
);
            header("Location: index.php?success=" . $itemType . " added successfully with code " . $product_code . ".");
            exit;
        }

        $error = "Unable to add. Please try again.";
    }
}

$modal_icon = 'bi-box-seam';
$modal_title = 'Add Item or Service';
$modal_subtitle = 'Add a new item or service to your catalog.';
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
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Type</label>
                    <select name="item_type" id="itemTypeSelect" class="form-select rm-input" required>
                        <option value="Item" <?= ($itemType ?? 'Item') === 'Item' ? 'selected' : ''; ?>>Item</option>
                        <option value="Service" <?= ($itemType ?? '') === 'Service' ? 'selected' : ''; ?>>Service</option>
                    </select>
                    <div class="form-text">Code will be generated automatically (e.g. ITM-001 / SRV-001).</div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted" id="nameLabel">Item Name</label>
                    <input type="text" name="product_name" class="form-control rm-input" value="<?= htmlspecialchars($product_name ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Description</label>
                    <textarea name="description" class="form-control rm-input" rows="3" style="height:auto;"><?= htmlspecialchars($description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-3 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Buying Price</label>
                        <input type="number" step="0.01" min="0" name="buying_price" class="form-control rm-input">
                    </div>
                    <div class="col-3">
                        <label class="form-label small fw-semibold text-muted" id="priceLabel">Selling Price</label>
                        <input type="number" step="0.01" min="0" name="selling_price" class="form-control rm-input" required>
                    </div>
                    <div class="col-3 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Quantity</label>
                        <input type="number" step="1" min="0" name="quantity" class="form-control rm-input" value="0">
                    </div>
                    <div class="col-3 item-only-field">
                        <label class="form-label small fw-semibold text-muted">Unit</label>
                        <select name="unit" class="form-select rm-input">
                            <option value="Pieces">Pieces</option>
                            <option value="Boxes">Boxes</option>
                        </select>
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