<?php
/**
 * NEW FILE: modules/categories/index.php
 * Admin: manage every category list (Expense Categories, Asset Types, Leave Types, Product and
 * Service Categories): add, rename, activate, deactivate. Users only pick from active categories.
 */
require '../../config/db.php';
require_once '../../includes/category_helpers.php';
require_role(['Admin']);
category_ensure($conn);

$groups = category_groups();
$group = isset($groups[$_GET['group'] ?? '']) ? $_GET['group'] : 'expense';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $group = isset($groups[$_POST['group'] ?? '']) ? $_POST['group'] : $group;
    $back = 'index.php?group=' . urlencode($group);
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $name = mb_substr(trim($_POST['name'] ?? ''), 0, 100);
            if ($name === '') { throw new RuntimeException('Please enter a name.'); }
            $s = mysqli_prepare($conn, 'INSERT INTO rm_categories (group_key, name, created_by) VALUES (?, ?, ?)');
            $uid = (int) current_user_id();
            mysqli_stmt_bind_param($s, 'ssi', $group, $name, $uid);
            try { mysqli_stmt_execute($s); }
            catch (mysqli_sql_exception $e) { throw new RuntimeException((int) $e->getCode() === 1062 ? 'That category already exists in this list.' : 'Unable to add the category.'); }
            $msg = '"' . $name . '" added.';
        } elseif ($action === 'rename') {
            category_rename($conn, (int) ($_POST['id'] ?? 0), (string) ($_POST['name'] ?? ''));
            $msg = 'Category renamed. Records using it were updated too.';
        } elseif ($action === 'toggle') {
            $s = mysqli_prepare($conn, 'UPDATE rm_categories SET is_active = 1 - is_active WHERE id = ? AND group_key = ?');
            $id = (int) ($_POST['id'] ?? 0);
            mysqli_stmt_bind_param($s, 'is', $id, $group);
            mysqli_stmt_execute($s);
            $msg = 'Category updated.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
        header('Location: ' . $back . '&success=' . urlencode($msg));
    } catch (RuntimeException $e) {
        header('Location: ' . $back . '&error=' . urlencode($e->getMessage()));
    }
    exit;
}

$s = mysqli_prepare($conn, 'SELECT * FROM rm_categories WHERE group_key = ? ORDER BY is_active DESC, name');
mysqli_stmt_bind_param($s, 's', $group);
mysqli_stmt_execute($s);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
$usage = category_usage($conn, $group);
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success alert-dismissible fade show"><?= $e($_GET['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<?php if (isset($_GET['error'])) { ?><div class="alert alert-danger alert-dismissible fade show"><?= $e($_GET['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>

<h2 class="mb-1">Categories</h2>
<p class="text-muted">Users choose from these lists; only Admins can change them. A deactivated category is hidden from new entries but stays on existing records.</p>

<ul class="nav nav-tabs mb-3">
    <?php foreach ($groups as $key => [$label]) { ?>
    <li class="nav-item"><a class="nav-link <?= $group === $key ? 'active' : ''; ?>" href="index.php?group=<?= $e($key); ?>"><?= $e($label); ?></a></li>
    <?php } ?>
</ul>

<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <form method="POST" class="row g-2 align-items-end">
        <input type="hidden" name="group" value="<?= $e($group); ?>"><input type="hidden" name="action" value="add">
        <div class="col-md-6"><label class="form-label small fw-semibold text-muted">New <?= $e(rtrim($groups[$group][0], 's')); ?></label>
            <input type="text" name="name" class="form-control rm-input" maxlength="100" required></div>
        <div class="col-md-3"><button class="rm-btn rm-btn-primary">+ Add</button></div>
    </form>
</div></div>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-bordered table-hover bg-white mb-0 align-middle">
    <tr><th>Name</th><th class="text-end">Used by</th><th>Status</th><th>Action</th></tr>
    <?php if (!$rows) { ?><tr><td colspan="4" class="text-center text-muted py-4">No categories yet. Add the first one above.</td></tr><?php } ?>
    <?php foreach ($rows as $r) { $id = (int) $r['id']; ?>
    <tr>
        <td>
            <form method="POST" class="d-flex gap-2 align-items-center rename-form">
                <input type="hidden" name="group" value="<?= $e($group); ?>"><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= $id; ?>">
                <input type="text" name="name" value="<?= $e($r['name']); ?>" class="form-control form-control-sm" maxlength="100" required style="max-width:320px;">
                <button class="btn btn-outline-secondary btn-sm" title="Save the new name">Rename</button>
            </form>
        </td>
        <td class="text-end"><?= number_format($usage[$r['name']] ?? 0); ?> record(s)</td>
        <td><?= $r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
        <td>
            <form method="POST" class="d-inline">
                <input type="hidden" name="group" value="<?= $e($group); ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $id; ?>">
                <button class="btn btn-sm <?= $r['is_active'] ? 'btn-outline-danger' : 'btn-outline-success'; ?>"><?= $r['is_active'] ? 'Deactivate' : 'Activate'; ?></button>
            </form>
        </td>
    </tr>
    <?php } ?>
</table>
</div></div></div>
<script>
document.querySelectorAll('.rename-form').forEach(function (f) {
    var input = f.querySelector('[name=name]'), original = input.value;
    f.addEventListener('submit', function (e) {
        if (input.value.trim() === original) { e.preventDefault(); return; }
        if (!confirm('Rename "' + original + '" to "' + input.value.trim() + '"? Every record using it will show the new name.')) { e.preventDefault(); }
    });
});
</script>
<?php include '../../includes/footer.php'; ?>