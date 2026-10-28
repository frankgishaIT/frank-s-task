<?php
/**
 * NEW FILE: modules/access/user.php
 * Task II: one employee's roles and individual exceptions, with a preview of the effective permissions.
 */
require '_common.php';
$uid = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$s = mysqli_prepare($conn, 'SELECT id, names, role, is_active FROM users WHERE id = ?');
mysqli_stmt_bind_param($s, 'i', $uid);
mysqli_stmt_execute($s);
$user = $uid ? mysqli_fetch_assoc(mysqli_stmt_get_result($s)) : null;
if (!$user) { header('Location: index.php'); exit; }
$roles = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name, description FROM acl_roles ORDER BY system_key IS NULL, name'), MYSQLI_ASSOC);
$current = access_user_breakdown($conn, (int) $uid);
$all = access_all_permissions();

// What the form says (or the current state).
$selRoles = $current['roles'] ? array_keys($current['roles']) : [];
$grants = $current['grants']; $denies = $current['denies'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selRoles = array_values(array_intersect(array_map('intval', (array) ($_POST['roles'] ?? [])), array_map('intval', array_column($roles, 'id'))));
    $grants = []; $denies = [];
    foreach ((array) ($_POST['ov'] ?? []) as $perm => $effect) {
        if (!in_array($perm, $all, true)) { continue; }
        if ($effect === 'grant') { $grants[] = $perm; } elseif ($effect === 'deny') { $denies[] = $perm; }
    }
}
$preview = access_user_breakdown($conn, (int) $uid, $selRoles, $grants, $denies);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $before = $current['effective'];
    $after = $preview['effective'];
    if ((int) $uid === $me && !in_array('access.manage', $after, true)) {
        $error = 'You cannot remove your own access to Roles & Permissions (another Administrator must do it).';
    } elseif (access_managers_left($conn, (int) $uid, $after) < 1) {
        $error = 'At least one active employee must keep "Roles & Permissions: Manage".';
    } else {
        mysqli_begin_transaction($conn);
        try {
            mysqli_query($conn, 'DELETE FROM acl_user_roles WHERE user_id = ' . (int) $uid);
            $ins = mysqli_prepare($conn, 'INSERT INTO acl_user_roles (user_id, role_id) VALUES (?, ?)');
            foreach ($selRoles as $rid) { mysqli_stmt_bind_param($ins, 'ii', $uid, $rid); mysqli_stmt_execute($ins); }
            mysqli_query($conn, 'DELETE FROM acl_user_overrides WHERE user_id = ' . (int) $uid);
            $ov = mysqli_prepare($conn, 'INSERT INTO acl_user_overrides (user_id, permission, effect) VALUES (?, ?, ?)');
            foreach ([['grant', $grants], ['deny', $denies]] as [$eff, $list]) {
                foreach ($list as $perm) { mysqli_stmt_bind_param($ov, 'iss', $uid, $perm, $eff); mysqli_stmt_execute($ov); }
            }
            $added = array_diff($after, $before); $removed = array_diff($before, $after);
            $details = 'Roles: ' . ($preview['roles'] ? implode(', ', $preview['roles']) : 'none')
                . '. Added: ' . ($added ? implode(', ', array_map('access_label', $added)) : 'nothing')
                . '. Removed: ' . ($removed ? implode(', ', array_map('access_label', $removed)) : 'nothing') . '.';
            access_log($conn, $me, (int) $uid, null, 'user_access_changed', $details);
            mysqli_commit($conn);
            header('Location: index.php?success=' . urlencode('Access of ' . $user['names'] . ' saved. ' . count($added) . ' added, ' . count($removed) . ' removed.'));
            exit;
        } catch (Throwable $ex) {
            mysqli_rollback($conn);
            $error = 'Unable to save. Nothing was changed. Reason: ' . $ex->getMessage();
        }
    }
}
$ovOf = function ($perm) use ($grants, $denies) { return in_array($perm, $denies, true) ? 'deny' : (in_array($perm, $grants, true) ? 'grant' : ''); };
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<h2 class="mb-1"><?= $e($user['names']); ?></h2>
<p class="text-muted small">Account type: <?= $e(ucfirst(strtolower($user['role']))); ?>. Effective access = the selected roles + permissions granted below − permissions denied below (a denial always wins).</p>
<?php access_tabs(''); ?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<form method="POST">
<input type="hidden" name="id" value="<?= (int) $uid; ?>">
<div class="row g-3">
<div class="col-lg-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h6>Roles</h6>
        <?php foreach ($roles as $r) { ?>
        <label class="d-block mb-1"><input type="checkbox" class="form-check-input me-2" name="roles[]" value="<?= (int) $r['id']; ?>" <?= in_array((int) $r['id'], $selRoles, true) ? 'checked' : ''; ?>>
            <strong><?= $e($r['name']); ?></strong> <span class="small text-muted"><?= $e($r['description']); ?></span></label>
        <?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive" style="max-height:520px;">
    <table class="table table-sm bg-white mb-0 align-middle" style="font-size:12.5px;">
        <tr class="sticky-top bg-white"><th>Permission</th><th>From roles</th><th style="width:150px;">Exception</th></tr>
        <?php foreach (access_catalog() as $m => [$label, $actions]) { ?>
            <tr class="table-light"><td colspan="3" class="fw-semibold"><?= $e($label); ?></td></tr>
            <?php foreach ($actions as $a) { $p = $m . '.' . $a; ?>
            <tr><td class="ps-3"><?= $e(ACCESS_ACTION_LABELS[$a]); ?><?= access_is_high_risk($p) ? ' <span class="badge bg-danger">high-risk</span>' : ''; ?></td>
                <td><?= in_array($p, $preview['from_roles'], true) ? '<i class="bi bi-check-lg text-success"></i>' : '<span class="text-muted">—</span>'; ?></td>
                <td><select name="ov[<?= $e($p); ?>]" class="form-select form-select-sm" data-no-search>
                    <option value="">Follow roles</option>
                    <option value="grant" <?= $ovOf($p) === 'grant' ? 'selected' : ''; ?>>Grant</option>
                    <option value="deny" <?= $ovOf($p) === 'deny' ? 'selected' : ''; ?>>Deny</option>
                </select></td></tr>
            <?php } ?>
        <?php } ?>
    </table>
    </div></div></div>
</div>
<div class="col-lg-5">
    <div class="card border-0 shadow-sm" style="position:sticky; top:80px;"><div class="card-body">
        <h6>Effective permissions <?= $_SERVER['REQUEST_METHOD'] === 'POST' ? '(preview, not saved yet)' : ''; ?></h6>
        <div class="small mb-2"><?= count($preview['effective']); ?> permission(s), <?= count(array_filter($preview['effective'], 'access_is_high_risk')); ?> high-risk.</div>
        <div style="max-height:380px; overflow-y:auto;" class="small">
            <?php foreach ($preview['effective'] as $p) { ?><div><?= access_is_high_risk($p) ? '<span class="text-danger">●</span> ' : '• '; ?><?= $e(access_label($p)); ?></div><?php } ?>
            <?php if (!$preview['effective']) { ?><div class="text-muted">No access to any module page (only Dashboard, own payslips, leave and notifications).</div><?php } ?>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button type="submit" name="preview" value="1" class="rm-btn rm-btn-secondary">Preview</button>
            <button type="submit" name="save" value="1" class="rm-btn rm-btn-primary" onclick="return confirm('Save the access of <?= $e($user['names']); ?>?');">Save</button>
            <a href="index.php" class="rm-btn rm-btn-light">Cancel</a>
        </div>
    </div></div>
</div>
</div>
</form>
<?php include '../../includes/footer.php'; ?>