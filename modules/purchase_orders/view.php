<?php
require '../../config/db.php';
require '../../includes/purchase_order_helpers.php';
require '../../includes/business_party_helpers.php';
require_role(['Admin', 'Manager']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=Invalid purchase order.'); exit; }

$statement = mysqli_prepare($conn, 'SELECT purchase_orders.*, created.names AS created_by_name, ordered.names AS ordered_by_name, received.names AS received_by_name, cancelled.names AS cancelled_by_name
    FROM purchase_orders
    LEFT JOIN users created ON purchase_orders.created_by = created.id
    LEFT JOIN users ordered ON purchase_orders.ordered_by = ordered.id
    LEFT JOIN users received ON purchase_orders.received_by = received.id
    LEFT JOIN users cancelled ON purchase_orders.cancelled_by = cancelled.id
    WHERE purchase_orders.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$po = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$po) { header('Location: index.php?error=Purchase order not found.'); exit; }

$supplierParty = business_party_by_id($conn, $po['supplier_party_id']);

$itemsStatement = mysqli_prepare($conn, 'SELECT purchase_order_items.*, products.product_name, products.product_code, products.unit
    FROM purchase_order_items LEFT JOIN products ON purchase_order_items.product_id = products.id
    WHERE purchase_order_id = ? ORDER BY purchase_order_items.id');
mysqli_stmt_bind_param($itemsStatement, 'i', $id);
mysqli_stmt_execute($itemsStatement);
$items = mysqli_stmt_get_result($itemsStatement);

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php } ?>
<?php if (isset($_GET['error'])) { ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="index.php" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left"></i> Back to Purchase Orders</a>
        <h2 class="mt-1">Purchase Order RM<?= str_pad($po['id'], 5, '0', STR_PAD_LEFT); ?> <?= po_status_badge($po['status']); ?></h2>
    </div>
    <div class="d-flex gap-2">
        <a href="invoice.php?id=<?= (int) $po['id']; ?>" target="_blank" class="rm-btn rm-btn-info">View / Print PDF</a>
        <?php if ($po['status'] === 'Draft') { ?>
            <a href="mark_ordered.php?id=<?= (int) $po['id']; ?>" class="rm-btn rm-btn-primary" onclick="return confirm('Mark this Purchase Order as Ordered?')">Mark as Ordered</a>
            <a href="cancel.php?id=<?= (int) $po['id']; ?>" class="rm-btn rm-btn-danger">Cancel PO</a>
        <?php } elseif ($po['status'] === 'Ordered') { ?>
            <a href="receive.php?id=<?= (int) $po['id']; ?>" class="rm-btn rm-btn-success">Receive & Update Stock</a>
            <a href="cancel.php?id=<?= (int) $po['id']; ?>" class="rm-btn rm-btn-danger">Cancel PO</a>
        <?php } ?>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="row g-3 small">
                    <div class="col-6"><span class="text-muted">Supplier:</span> <strong><?= htmlspecialchars($po['supplier'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div class="col-6"><span class="text-muted">Order Date:</span> <strong><?= date('d M Y', strtotime($po['order_date'])); ?></strong></div>
                    <div class="col-6"><span class="text-muted">Expected Delivery:</span> <strong><?= $po['expected_delivery_date'] ? date('d M Y', strtotime($po['expected_delivery_date'])) : '—'; ?></strong></div>
                    <div class="col-6"><span class="text-muted">Total Amount:</span> <strong>RWF <?= number_format((float) $po['total_amount'], 2); ?></strong></div>
                    <?php if ($po['notes']) { ?>
                    <div class="col-12"><span class="text-muted">Notes:</span> <?= nl2br(htmlspecialchars($po['notes'], ENT_QUOTES, 'UTF-8')); ?></div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body small">
                <div class="mb-2"><span class="text-muted">Created By:</span> <strong><?= htmlspecialchars($po['created_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <?php if ($po['ordered_by_name']) { ?><div class="mb-2"><span class="text-muted">Ordered By:</span> <strong><?= htmlspecialchars($po['ordered_by_name'], ENT_QUOTES, 'UTF-8'); ?></strong> on <?= date('d M Y', strtotime($po['ordered_at'])); ?></div><?php } ?>
                <?php if ($po['received_by_name']) { ?><div class="mb-2"><span class="text-muted">Received By:</span> <strong><?= htmlspecialchars($po['received_by_name'], ENT_QUOTES, 'UTF-8'); ?></strong> on <?= date('d M Y', strtotime($po['received_at'])); ?></div><?php } ?>
                <?php if ($po['cancelled_by_name']) { ?><div class="mb-2"><span class="text-muted">Cancelled By:</span> <strong><?= htmlspecialchars($po['cancelled_by_name'], ENT_QUOTES, 'UTF-8'); ?></strong> on <?= date('d M Y', strtotime($po['cancelled_at'])); ?><?php if ($po['cancel_reason']) { ?><br><span class="text-muted">Reason: <?= htmlspecialchars($po['cancel_reason'], ENT_QUOTES, 'UTF-8'); ?></span><?php } ?></div><?php } ?>
            </div>
        </div>
    </div>
</div>

<?php if ($supplierParty) { ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body small">
        <h6 class="fw-semibold mb-2">Supplier Details</h6>
        <div class="row g-2">
            <div class="col-6"><span class="text-muted">TIN:</span> <strong><?= htmlspecialchars($supplierParty['tin'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="col-6"><span class="text-muted">Representative:</span> <strong><?= htmlspecialchars($supplierParty['representative_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="col-6"><span class="text-muted">Phone:</span> <strong><?= htmlspecialchars($supplierParty['phone'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="col-6"><span class="text-muted">Email:</span> <strong><?= htmlspecialchars($supplierParty['email'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <div class="col-12"><span class="text-muted">Location:</span> <strong><?= htmlspecialchars(implode(', ', array_filter([$supplierParty['sector'], $supplierParty['district'], $supplierParty['province']])), ENT_QUOTES, 'UTF-8'); ?></strong></div>
        </div>
    </div>
</div>
<?php } ?>

<div class="card border-0 shadow-sm">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-bordered table-hover bg-white mb-0">
<tr><th>Product</th><th>Code</th><th>Bought As</th><th>Total Units</th><th>Cost / Unit</th><th>Line Total</th></tr>
<?php while ($item = mysqli_fetch_assoc($items)) {
    $packSize = max(1, (int) $item['pack_size']);
    $totalUnits = (int) $item['quantity'] * $packSize;
?>
<tr>
    <td><?= htmlspecialchars($item['product_name'] ?? 'Deleted product', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars($item['product_code'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
    <td>
        <?= (int) $item['quantity']; ?> x <?= htmlspecialchars($item['pack_label'], ENT_QUOTES, 'UTF-8'); ?>
        <?php if ($packSize > 1) { ?>
        <br><small class="text-muted"><?= $packSize; ?> <?= htmlspecialchars($item['unit'] ?? 'units', ENT_QUOTES, 'UTF-8'); ?> each</small>
        <?php } ?>
    </td>
    <td><?= $totalUnits; ?> <?= htmlspecialchars($item['unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
    <td>RWF <?= number_format((float) $item['unit_cost'], 2); ?></td>
    <td>RWF <?= number_format((float) $item['line_total'], 2); ?></td>
</tr>
<?php } ?>
</table>
</div>
</div>
</div>

<?php if ($po['status'] === 'Received') { ?>
<div class="alert alert-success mt-4" style="border-radius:10px;">
    <i class="bi bi-check-circle-fill"></i> This Purchase Order has been received. Stock levels were updated automatically and an Expense transaction was posted to Transactions.
</div>
<?php } ?>

<?php include '../../includes/footer.php'; ?>