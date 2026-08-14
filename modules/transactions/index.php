<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
$pageSearchScope = 'transactions'; // tells the topbar search what module we're in
require '../../includes/pagination.php';
include '../../includes/header.php'; include '../../includes/sidebar.php';
const PER_PAGE = 10;
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
$currentPage = get_current_page();
$totalRows = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM transactions'))['c'];
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;
$summary = mysqli_query($conn, "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'Income' THEN amount ELSE 0 END), 0) AS income, COALESCE(SUM(CASE WHEN transaction_type = 'Expense' THEN amount ELSE 0 END), 0) AS expense FROM transactions"); $totals = mysqli_fetch_assoc($summary);
$sql = 'SELECT transactions.*, users.names AS recorder_name FROM transactions LEFT JOIN users ON transactions.recorded_by = users.id ORDER BY transaction_date DESC, transactions.id DESC LIMIT ' . PER_PAGE . ' OFFSET ' . $offset; $transactions = mysqli_query($conn, $sql);
?>
<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Transactions Management</h2>
    <div class="d-flex gap-2"><a href="create.php" class="rm-btn rm-btn-primary">+ Add Transaction</a></div></div>
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-success">
            <div class="card-body">
                <small class="text-muted">Total Income</small>
                <h4 class="text-success mb-0">RWF <?= number_format((float) $totals['income'], 2); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-danger">
            <div class="card-body">
                <small class="text-muted">Total Expenses</small>
                <h4 class="text-danger mb-0">RWF <?= number_format((float) $totals['expense'], 2); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-primary">
            <div class="card-body">
                <small class="text-muted">Balance</small>
                <h4 class="text-primary mb-0">RWF <?= number_format((float) $totals['income'] - (float) $totals['expense'], 2); ?></h4>
            </div>
        </div>
    </div>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover bg-white mb-0">
    <tr>
        <th>Date</th>
        <th>Category</th>
        <th>Type</th>
        <th>Amount</th>
        <th>Description</th>
        <th>Recorded By</th>
        <th>Action</th>
    </tr><?php if (mysqli_num_rows($transactions) === 0) { ?>
    <tr><td colspan="7" class="text-center text-muted py-4">No transactions found. Click "Add Transaction" to record one.</td></tr><?php } ?>
    <?php while ($transaction = mysqli_fetch_assoc($transactions)) { ?>
    <tr><td><?= date('d M Y', strtotime($transaction['transaction_date'])); ?>
</td><td><?= htmlspecialchars($transaction['category'], ENT_QUOTES, 'UTF-8'); ?>
</td><td><span class="badge bg-<?= $transaction['transaction_type'] === 'Income' ? 'success' : 'danger'; ?>">
    <?= $transaction['transaction_type']; ?></span></td>
    <td>RWF <?= number_format((float) $transaction['amount'], 2); ?></td>
    <td><?= htmlspecialchars($transaction['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars($transaction['recorder_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
    <td class="text-nowrap">
        <a href="view.php?id=<?= (int) $transaction['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">View</a>
        <?php if ($isAdmin) { ?>
         <a href="edit.php?id=<?= (int) $transaction['id']; ?>" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a> 
         <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('Delete this transaction?')">
            <input type="hidden" name="id" value="<?= (int) $transaction['id']; ?>">
            <button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>
        </form>
        <?php } ?>
        </td></tr><?php } ?></table></div><?php render_pagination($currentPage, $totalPages); ?></div></div>
<?php include '../../includes/footer.php'; ?>