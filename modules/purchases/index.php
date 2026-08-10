<?php
require '../../config/db.php';
require '../../includes/pagination.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

const PER_PAGE = 10;
$currentPage = get_current_page();
$totalRows = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM purchases'))['c'];
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;

$totalSpent = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COALESCE(SUM(quantity * unit_cost), 0) AS total FROM purchases'))['total'];

$sql = "SELECT purchases.*, products.product_name, products.product_code, users.names AS recorder_name
        FROM purchases
        INNER JOIN products ON purchases.product_id = products.id
        LEFT JOIN users ON purchases.recorded_by = users.id
        ORDER BY purchases.purchase_date DESC, purchases.id DESC
        LIMIT " . PER_PAGE . " OFFSET " . $offset;
$purchases = mysqli_query($conn, $sql);
?>

<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="../products/index.php" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left"></i> Back to Items &amp; Services</a>
        <h2 class="mt-1">Purchase History</h2>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-primary">
            <div class="card-body">
                <small class="text-muted">Total Spent on Restocking</small>
                <h4 class="text-primary mb-0">RWF <?= number_format((float) $totalSpent, 2); ?></h4>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-bordered table-hover bg-white mb-0">
<tr>
    <th>Date</th><th>Item</th><th>Code</th><th>Quantity</th><th>Unit Cost</th><th>Total</th><th>Supplier</th><th>Recorded By</th>
</tr>
<?php if (mysqli_num_rows($purchases) === 0) { ?>
<tr><td colspan="8" class="text-center text-muted py-4">No restock history yet.</td></tr>
<?php } ?>
<?php while ($p = mysqli_fetch_assoc($purchases)) { ?>
<tr>
    <td><?= date('d M Y', strtotime($p['purchase_date'])); ?></td>
    <td><?= htmlspecialchars($p['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars($p['product_code'], ENT_QUOTES, 'UTF-8'); ?></td>
    <td>+<?= (int) $p['quantity']; ?></td>
    <td>RWF <?= number_format((float) $p['unit_cost'], 2); ?></td>
    <td>RWF <?= number_format((float) $p['quantity'] * (float) $p['unit_cost'], 2); ?></td>
    <td><?= htmlspecialchars($p['supplier'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars($p['recorder_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
</tr>
<?php } ?>
</table>
</div>
<?php render_pagination($currentPage, $totalPages); ?>
</div>
</div>

<?php include '../../includes/footer.php'; ?>