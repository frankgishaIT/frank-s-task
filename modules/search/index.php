<?php
require '../../config/db.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

$q = trim($_GET['q'] ?? '');
$employees = [];
$products = [];
$customers = [];

if ($q !== '') {
    $like = '%' . $q . '%';

    $employeeStatement = mysqli_prepare($conn, "SELECT id, names, email, role FROM users WHERE (names LIKE ? OR email LIKE ?) AND is_active = 1 LIMIT 8");
    mysqli_stmt_bind_param($employeeStatement, 'ss', $like, $like);
    mysqli_stmt_execute($employeeStatement);
    $employees = mysqli_fetch_all(mysqli_stmt_get_result($employeeStatement), MYSQLI_ASSOC);

    $productStatement = mysqli_prepare($conn, "SELECT id, product_name, product_code, item_type FROM products WHERE (product_name LIKE ? OR product_code LIKE ?) AND is_active = 1 LIMIT 8");
    mysqli_stmt_bind_param($productStatement, 'ss', $like, $like);
    mysqli_stmt_execute($productStatement);
    $products = mysqli_fetch_all(mysqli_stmt_get_result($productStatement), MYSQLI_ASSOC);

    $customerStatement = mysqli_prepare($conn, "SELECT id, name, phone, email FROM customers WHERE (name LIKE ? OR phone LIKE ?) LIMIT 8");
    mysqli_stmt_bind_param($customerStatement, 'ss', $like, $like);
    mysqli_stmt_execute($customerStatement);
    $customers = mysqli_fetch_all(mysqli_stmt_get_result($customerStatement), MYSQLI_ASSOC);
}

$totalResults = count($employees) + count($products) + count($customers);
?>

<div class="mb-4">
    <h2>Search results</h2>
    <?php if ($q !== '') { ?>
        <p class="text-muted mb-0"><?= (int) $totalResults; ?> result<?= $totalResults == 1 ? '' : 's'; ?> for "<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>"</p>
    <?php } ?>
</div>

<?php if ($q === '') { ?>
    <div class="card shadow"><div class="card-body text-center text-muted py-5">Type something in the search bar above to get started.</div></div>
<?php } elseif ($totalResults === 0) { ?>
    <div class="card shadow"><div class="card-body text-center text-muted py-5">No matches found for "<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>".</div></div>
<?php } else { ?>

    <?php if (!empty($employees)) { ?>
    <div class="card shadow mb-4">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-people-fill"></i> Employees</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
            <table class="table table-hover mb-0">
                <?php foreach ($employees as $e) { ?>
                <tr>
                    <td><?= htmlspecialchars($e['names'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= htmlspecialchars($e['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($e['role'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="text-end"><a href="../users/view.php?id=<?= (int) $e['id']; ?>" class="btn btn-info btn-sm">View</a></td>
                </tr>
                <?php } ?>
            </table>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (!empty($products)) { ?>
    <div class="card shadow mb-4">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-box-seam"></i> Items &amp; Services</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
            <table class="table table-hover mb-0">
                <?php foreach ($products as $p) { ?>
                <tr>
                    <td><?= htmlspecialchars($p['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= htmlspecialchars($p['product_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge bg-primary"><?= htmlspecialchars($p['item_type'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="text-end"><a href="../products/view.php?id=<?= (int) $p['id']; ?>" class="btn btn-info btn-sm">View</a></td>
                </tr>
                <?php } ?>
            </table>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (!empty($customers)) { ?>
    <div class="card shadow mb-4">
        <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-person-lines-fill"></i> Customers</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
            <table class="table table-hover mb-0">
                <?php foreach ($customers as $c) { ?>
                <tr>
                    <td><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= htmlspecialchars($c['phone'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-muted"><?= htmlspecialchars($c['email'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-end"><a href="../customers/view.php?id=<?= (int) $c['id']; ?>" class="btn btn-info btn-sm">View</a></td>
                </tr>
                <?php } ?>
            </table>
            </div>
        </div>
    </div>
    <?php } ?>

<?php } ?>

<?php include '../../includes/footer.php'; ?>