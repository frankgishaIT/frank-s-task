<?php
require '../../config/db.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid item selected.'); exit; }
$statement = mysqli_prepare($conn, 'SELECT * FROM products WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$product = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$product) { header('Location: index.php?success=Item not found.'); exit; }
$isService = $product['item_type'] === 'Service';
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<div class="card shadow border-0">
    <div class="card-header bg-white"><h3 class="mb-0"><?= $isService ? 'Service' : 'Item'; ?> Details</h3></div>
    <div class="card-body">
        <table class="table table-bordered mb-4">
            <tr><th width="220">Type</th><td><span class="badge bg-<?= $isService ? 'info text-dark' : 'primary'; ?>"><?= htmlspecialchars($product['item_type'], ENT_QUOTES, 'UTF-8'); ?></span></td></tr>
            <tr><th>Name</th><td><?= htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><th>Code</th><td><?= htmlspecialchars($product['product_code'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><th>Description</th><td><?= nl2br(htmlspecialchars($product['description'] ?? '—', ENT_QUOTES, 'UTF-8')); ?></td></tr>
            <?php if (!$isService) { ?>
            <tr><th>Buying Price</th><td>RWF <?= number_format($product['buying_price'], 2); ?></td></tr>
            <?php } ?>
            <tr><th><?= $isService ? 'Price' : 'Selling Price'; ?></th><td>RWF <?= number_format($product['selling_price'], 2); ?></td></tr>
            <?php if (!$isService) { ?>
            <tr><th>Quantity in Stock</th><td><?= (int) $product['quantity']; ?></td></tr>
            <tr><th>Unit</th><td><?= htmlspecialchars($product['unit'] ?? 'Pieces', ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <?php } ?>
            <tr>
                <th>Status</th>
                <td><?= $product['is_active'] ? 'Active' : 'Inactive'; ?></td></tr>
        </table>
        <a href="index.php" class="rm-btn rm-btn-secondary">Back</a>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
