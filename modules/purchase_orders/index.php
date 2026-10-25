<?php
$pageSearchScope = 'purchase_orders';
require '../../config/db.php';
require '../../includes/purchase_order_helpers.php';
require_role(['Admin', 'Manager']);
require '../../includes/pagination.php';
include '../../includes/header.php'; include '../../includes/sidebar.php';

const PO_PER_PAGE = 10;
$isAdmin = current_user_role() === 'Admin'; // NEW: only an Admin can delete

$currentPage = get_current_page();
$totalRows = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM purchase_orders'))['c'];
$totalPages = max(1, (int) ceil($totalRows / PO_PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PO_PER_PAGE;

$sql = "SELECT purchase_orders.*, users.names AS created_by_name
        FROM purchase_orders LEFT JOIN users ON purchase_orders.created_by = users.id
        ORDER BY purchase_orders.created_at DESC LIMIT " . PO_PER_PAGE . " OFFSET " . $offset;
$purchaseOrders = mysqli_query($conn, $sql);

$lowStockCount = count(po_low_stock_products($conn));
?>
<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php } ?>
<?php if (isset($_GET['error'])) { ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="../products/index.php" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left"></i> Back to Inventory</a>
        <h2 class="mt-1">Purchase Orders</h2>
    </div>
    <a href="create.php" class="rm-btn rm-btn-primary">+ New Purchase Order</a>
</div>

<?php if ($lowStockCount > 0) { ?>
<div class="alert alert-warning d-flex align-items-center justify-content-between gap-2 mb-4" style="border-radius:10px;">
    <span><i class="bi bi-exclamation-triangle-fill"></i> <?= $lowStockCount; ?> item<?= $lowStockCount == 1 ? '' : 's'; ?> currently low on stock.</span>
    <a href="create.php?from_low_stock=1" class="rm-btn rm-btn-warning rm-btn-sm">Create PO from Low Stock</a>
</div>
<?php } ?>

<div class="card border-0 shadow-sm">
<div class="card-body p-0">
<div id="pageResultsContainer">
<div class="table-responsive">
<table class="table table-bordered table-hover bg-white mb-0">
<tr>
    <th>PO #</th><th>Order Date</th><th>Expected Delivery</th><th>Supplier</th><th>Total</th><th>Status</th><th>Created By</th><th>Action</th>
</tr>
<?php if (mysqli_num_rows($purchaseOrders) === 0) { ?>
<tr><td colspan="8" class="text-center text-muted py-4">No purchase orders yet.</td></tr>
<?php } ?>
<?php while ($po = mysqli_fetch_assoc($purchaseOrders)) {
    $poNo = company_profile()['invoice_prefix'] . str_pad($po['id'], 5, '0', STR_PAD_LEFT);
?>
<tr>
    <td><?= $poNo; ?></td>
    <td><?= date('d M Y', strtotime($po['order_date'])); ?></td>
    <td><?= $po['expected_delivery_date'] ? date('d M Y', strtotime($po['expected_delivery_date'])) : '<span class="text-muted">—</span>'; ?></td>
    <td><?= htmlspecialchars($po['supplier'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
    <td>RWF <?= number_format((float) $po['total_amount'], 2); ?></td>
    <td><?= po_status_badge($po['status']); ?></td>
    <td><?= htmlspecialchars($po['created_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
    <td class="text-nowrap">
        <a href="view.php?id=<?= (int) $po['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">View</a>
        <a href="invoice.php?id=<?= (int) $po['id']; ?>" target="_blank" class="rm-btn rm-btn-secondary rm-btn-sm">PDF</a>
        <?php if ($isAdmin) { ?>
            <?php if ($po['status'] === 'Ordered') { ?>
                <!-- NEW: an Ordered PO must be cancelled first (that returns its money to the fund). -->
                <span class="text-muted small ms-1" title="Cancel this order first (its money goes back to the RM Capital Fund), then it can be deleted."><i class="bi bi-lock-fill"></i></span>
            <?php } else { ?>
                <!-- NEW: Delete (Draft, Cancelled, or Received while all its goods are still in stock). -->
                <form method="POST" action="delete.php" class="d-inline"
                      onsubmit="return confirm('Delete Purchase Order <?= $poNo; ?>?<?= $po['status'] === 'Received' ? ' Its goods will be taken back out of stock and its cost returned to the RM Capital Fund.' : ''; ?> This cannot be undone.');">
                    <input type="hidden" name="id" value="<?= (int) $po['id']; ?>">
                    <button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>
                </form>
            <?php } ?>
        <?php } ?>
    </td>
</tr>
<?php } ?>
</table>
</div>
</div>
<div id="pageResultsPagination">
<?php render_pagination($currentPage, $totalPages); ?>
</div>
</div>
</div>
<?php include '../../includes/footer.php'; ?>