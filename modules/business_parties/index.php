<?php
$pageSearchScope = 'business_parties';
require '../../config/db.php';
require '../../includes/business_party_helpers.php';
require_role(['Admin', 'Manager']);
require '../../includes/pagination.php';
include '../../includes/header.php'; include '../../includes/sidebar.php';

const BP_PER_PAGE = 10;

$typeFilter = $_GET['type'] ?? '';
$whereClause = in_array($typeFilter, ['Supplier', 'Payee', 'Partner'], true) ? " WHERE type = '" . mysqli_real_escape_string($conn, $typeFilter) . "'" : '';

$currentPage = get_current_page();
$totalRows = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM business_parties' . $whereClause))['c'];
$totalPages = max(1, (int) ceil($totalRows / BP_PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * BP_PER_PAGE;

$sql = "SELECT * FROM business_parties" . $whereClause . " ORDER BY type, COALESCE(business_name, name) LIMIT " . BP_PER_PAGE . " OFFSET " . $offset;
$parties = mysqli_query($conn, $sql);
?>
<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>RM Business Parties</h2>
    <a href="create.php" class="rm-btn rm-btn-primary">+ Add Business Party</a>
</div>

<div class="mb-3 d-flex gap-2">
    <a href="index.php" class="rm-btn rm-btn-sm <?= $typeFilter === '' ? 'rm-btn-primary' : 'rm-btn-light'; ?>">All</a>
    <a href="index.php?type=Supplier" class="rm-btn rm-btn-sm <?= $typeFilter === 'Supplier' ? 'rm-btn-primary' : 'rm-btn-light'; ?>">RM Suppliers</a>
    <a href="index.php?type=Payee" class="rm-btn rm-btn-sm <?= $typeFilter === 'Payee' ? 'rm-btn-primary' : 'rm-btn-light'; ?>">RM Payees</a>
    <a href="index.php?type=Partner" class="rm-btn rm-btn-sm <?= $typeFilter === 'Partner' ? 'rm-btn-primary' : 'rm-btn-light'; ?>">RM Partners</a>
</div>

<div class="card border-0 shadow-sm">
<div class="card-body p-0">
<div id="pageResultsContainer">
<div class="table-responsive">
<table class="table table-bordered table-hover bg-white mb-0">
<tr><th>Type</th><th>Name</th><th>Phone</th><th>Email</th><th>Location</th><th>Status</th><th>Action</th></tr>
<?php if (mysqli_num_rows($parties) === 0) { ?>
<tr><td colspan="7" class="text-center text-muted py-4">No business parties yet.</td></tr>
<?php } ?>
<?php while ($party = mysqli_fetch_assoc($parties)) { ?>
<tr>
    <td><?= business_party_type_badge($party['type']); ?></td>
    <td><?= htmlspecialchars(business_party_display_name($party), ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars($party['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars($party['email'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars(implode(', ', array_filter([$party['sector'], $party['district'], $party['province']])), ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?php if ($party['is_active']) { ?><span class="badge bg-success">Active</span><?php } else { ?><span class="badge bg-danger">Inactive</span><?php } ?></td>
    <td>
        <a href="edit.php?id=<?= (int) $party['id']; ?>" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a>
        <a href="deactivate.php?id=<?= (int) $party['id']; ?>" class="rm-btn <?= $party['is_active'] ? 'rm-btn-danger' : 'rm-btn-success'; ?> rm-btn-sm" onclick="return confirm('<?= $party['is_active'] ? 'Deactivate this?' : 'Reactivate this?'; ?>')"><?= $party['is_active'] ? 'Deactivate' : 'Activate'; ?></a>
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