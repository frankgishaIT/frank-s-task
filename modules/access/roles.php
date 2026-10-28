<?php
/**
 * NEW FILE: modules/access/roles.php
 * Task II: reusable roles (create, edit permissions, delete an unused custom role).
 */
require '_common.php';
$all = access_all_permissions();
$rid = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $name = mb_substr(trim($_POST['name'] ?? ''), 0, 80);
            if ($name === '') { throw new RuntimeException('Please enter the role name.'); }
            $s = mysqli_prepare($conn, 'INSERT INTO acl_roles (name, description) VALUES (?, ?)');
            $desc = mb_substr(trim($_POST['description'] ?? ''), 0, 255);
            mysqli_stmt_bind_param($s, 'ss', $name, $desc);
            try { mysqli_stmt_execute($s); } catch (mysqli_sql_exception $x) { throw new RuntimeException('A role with that name already exists.'); }
            $new = (int) mysqli_insert_id($conn);
            access_log($conn, $me, null, $new, 'role_created', 'Role "' . $name . '" created.');
            header('Location: roles.php?id=' . $new . '&success=' . urlencode('Role created. Now choose its permissions.')); exit;
        }
        if ($action === 'save' && $rid) {
            $perms = array_values(array_intersect((array) ($_POST['perms'] ?? []), $all));
            $old = array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT permission FROM acl_role_permissions WHERE role_id = ' . (int) $rid), MYSQLI_ASSOC), 'permission');
            if (access_managers_left($conn, null, null, (int) $rid, $perms) < 1) { throw new RuntimeException('At least one active employee must keep "Roles & Permissions: Manage".'); }
            mysqli_begin_transaction($conn);
            mysqli_query($conn, 'DELETE FROM acl_role_permissions WHERE role_id = ' . (int) $rid);
            $ins = mysqli_prepare($conn, 'INSERT INTO acl_role_permissions (role_id, permission) VALUES (?, ?)');
            foreach ($perms as $p) { mysqli_stmt_bind_param($ins, 'is', $rid, $p); mysqli_stmt_execute($ins); }
            $added = array_diff($perms, $old); $removed = array_diff($old, $perms);
            access_log($conn, $me, null, (int) $rid, 'role_changed', 'Added: ' . ($added ? implode(', ', array_map('access_label', $added)) : 'nothing')
                . '. Removed: ' . ($removed ? implode(', ', array_map('access_label', $removed)) : 'nothing') . '.');
            mysqli_commit($conn);
            header('Location: roles.php?id=' . (int) $rid . '&success=' . urlencode('Role saved: ' . count($added) . ' added, ' . count($removed) . ' removed. It applies at once to every employee with this role.')); exit;
        }
        if ($action === 'delete' && $rid) {
            $r = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM acl_roles WHERE id = ' . (int) $rid));
            if (!$r || $r['system_key']) { throw new RuntimeException('The starting roles cannot be deleted.'); }
            if ((int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM acl_user_roles WHERE role_id = ' . (int) $rid))[0] > 0) {
                throw new RuntimeException('This role is still given to employees. Remove it from them first.');
            }
            mysqli_query($conn, 'DELETE FROM acl_role_permissions WHERE role_id = ' . (int) $rid);
            mysqli_query($conn, 'DELETE FROM acl_roles WHERE id = ' . (int) $rid);
            access_log($conn, $me, null, (int) $rid, 'role_deleted', 'Role "' . $r['name'] . '" deleted.');
            header('Location: roles.php?success=' . urlencode('Role deleted.')); exit;
        }
    } catch (Throwable $ex) {
        @mysqli_rollback($conn);
        $error = $ex instanceof RuntimeException && !($ex instanceof mysqli_sql_exception) ? $ex->getMessage() : 'Unable to save. Reason: ' . $ex->getMessage();
    }
}
$roles = mysqli_fetch_all(mysqli_query($conn, 'SELECT r.*, (SELECT COUNT(*) FROM acl_user_roles x WHERE x.role_id = r.id) AS people,
    (SELECT COUNT(*) FROM acl_role_permissions p WHERE p.role_id = r.id) AS perms FROM acl_roles r ORDER BY r.system_key IS NULL, r.name'), MYSQLI_ASSOC);
$role = null; $rolePerms = [];
if ($rid) {
    $role = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM acl_roles WHERE id = ' . (int) $rid));
    $rolePerms = array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT permission FROM acl_role_permissions WHERE role_id = ' . (int) $rid), MYSQLI_ASSOC), 'permission');
}
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success"><?= $e($_GET['success']); ?></div><?php } ?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<h2 class="mb-1">Roles &amp; Permissions</h2>
<?php access_tabs('roles.php'); ?>
<div class="row g-3">
<div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-0">
    <table class="table table-hover bg-white mb-0 align-middle" style="font-size:13px;">
        <tr><th>Role</th><th class="text-end">People</th><th class="text-end">Permissions</th></tr>
        <?php foreach ($roles as $r) { ?>
        <tr<?= (int) $rid === (int) $r['id'] ? ' class="table-primary"' : ''; ?>><td><a href="roles.php?id=<?= (int) $r['id']; ?>"><?= $e($r['name']); ?></a><?= $r['system_key'] ? ' <span class="badge bg-secondary">starting</span>' : ''; ?></td>
            <td class="text-end"><?= (int) $r['people']; ?></td><td class="text-end"><?= (int) $r['perms']; ?></td></tr>
        <?php } ?>
    </table></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body">
        <h6>New role</h6>
        <form method="POST"><input type="hidden" name="action" value="create">
            <input type="text" name="name" class="form-control rm-input mb-2" maxlength="80" placeholder="e.g. Cashier" required>
            <input type="text" name="description" class="form-control rm-input mb-2" maxlength="255" placeholder="What this job does (optional)">
            <button class="rm-btn rm-btn-primary rm-btn-sm">Create role</button></form>
    </div></div>
</div>
<div class="col-lg-8">
<?php if ($role) { ?>
    <div class="card border-0 shadow-sm"><div class="card-body">
    <h5 class="mb-1"><?= $e($role['name']); ?></h5><p class="small text-muted"><?= $e($role['description']); ?></p>
    <form method="POST"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) $rid; ?>">
    <div class="table-responsive"><table class="table table-sm bg-white align-middle" style="font-size:12.5px;">
        <tr><th>Module</th><?php foreach (ACCESS_ACTION_LABELS as $a => $l) { ?><th class="text-center"><?= $e($l); ?></th><?php } ?></tr>
        <?php foreach (access_catalog() as $m => [$label, $actions]) { ?>
        <tr><td><?= $e($label); ?></td>
            <?php foreach (array_keys(ACCESS_ACTION_LABELS) as $a) { $p = $m . '.' . $a; ?>
            <td class="text-center"><?php if (in_array($a, $actions, true)) { ?><input type="checkbox" class="form-check-input<?= access_is_high_risk($p) ? ' border-danger' : ''; ?>" name="perms[]" value="<?= $e($p); ?>" <?= in_array($p, $rolePerms, true) ? 'checked' : ''; ?> title="<?= $e(access_label($p)); ?>"><?php } ?></td>
            <?php } ?></tr>
        <?php } ?>
    </table></div>
    <p class="small text-muted">Red-bordered boxes are high-risk. Changes apply at once to every employee with this role.</p>
    <div class="d-flex gap-2"><button class="rm-btn rm-btn-primary" onclick="return confirm('Save this role? It changes the access of everyone who has it.');">Save role</button></div>
    </form>
    <?php if (!$role['system_key']) { ?>
    <form method="POST" class="mt-2" onsubmit="return confirm('Delete this role?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $rid; ?>">
        <button class="btn btn-outline-danger btn-sm">Delete role</button></form>
    <?php } ?>
    </div></div>
<?php } else { ?><p class="text-muted">Choose a role to see and change its permissions, or create a new one.</p><?php } ?>
</div>
</div>
<?php include '../../includes/footer.php'; ?>