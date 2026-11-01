<?php
/**
 * NEW FILE: modules/library/folders.php
 * Manage folders (library.manage): create, rename, describe, move, restrict to chosen employees, delete empty ones.
 */
require '../../config/db.php';
require_once '../../includes/library_helpers.php';
lib_ensure($conn);
lib_guard($conn, 'library.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $fid = (int) ($_POST['folder_id'] ?? 0);
    $all = lib_folders($conn);
    try {
        if ($action !== 'create' && !isset($all[$fid])) { throw new RuntimeException('Folder not found.'); }
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
        $parent = (int) ($_POST['parent_id'] ?? 0);
        if (in_array($action, ['create', 'edit'], true) && $name === '') { throw new RuntimeException('Give the folder a name.'); }
        if ($parent && !isset($all[$parent])) { throw new RuntimeException('Choose a valid parent folder.'); }
        switch ($action) {
            case 'create':
                $p = $parent ?: null; $uid = (int) current_user_id(); $desc = trim((string) ($_POST['description'] ?? '')) ?: null;
                $s = mysqli_prepare($conn, 'INSERT INTO lib_folders (parent_id, name, description, created_by, sort_order) VALUES (?, ?, ?, ?, 100)');
                mysqli_stmt_bind_param($s, 'issi', $p, $name, $desc, $uid);
                mysqli_stmt_execute($s);
                $new = (int) mysqli_insert_id($conn);
                lib_log($conn, null, $new, 'folder_created', $name . ($parent ? ' inside ' . lib_folder_path($conn, $parent) : ''));
                $msg = 'Folder "' . $name . '" created.';
                break;
            case 'edit':
                if ($parent && in_array($parent, lib_descendants($conn, $fid), true)) { throw new RuntimeException('A folder cannot be moved inside itself or one of its sub-folders.'); }
                $p = $parent ?: null; $desc = trim((string) ($_POST['description'] ?? '')) ?: null;
                $s = mysqli_prepare($conn, 'UPDATE lib_folders SET name = ?, description = ?, parent_id = ? WHERE id = ?');
                mysqli_stmt_bind_param($s, 'ssii', $name, $desc, $p, $fid);
                mysqli_stmt_execute($s);
                lib_log($conn, null, $fid, 'folder_changed', $all[$fid]['name'] . ' → ' . $name . ((int) $all[$fid]['parent_id'] !== $parent ? ' (moved)' : ''));
                $msg = 'Folder saved.';
                break;
            case 'access':
                $restricted = !empty($_POST['is_restricted']) ? 1 : 0;
                $users = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['users'] ?? [])))));
                if ($restricted && !$users) { throw new RuntimeException('Choose at least one employee who may open this restricted folder.'); }
                mysqli_begin_transaction($conn);
                mysqli_query($conn, "UPDATE lib_folders SET is_restricted = $restricted WHERE id = $fid");
                mysqli_query($conn, "DELETE FROM lib_folder_access WHERE folder_id = $fid");
                $ins = mysqli_prepare($conn, 'INSERT INTO lib_folder_access (folder_id, user_id) VALUES (?, ?)');
                foreach ($users as $u) { mysqli_stmt_bind_param($ins, 'ii', $fid, $u); mysqli_stmt_execute($ins); }
                lib_log($conn, null, $fid, 'folder_access', $restricted ? 'Restricted to ' . count($users) . ' employee(s) (and Library managers)' : 'Open to everyone with Library access');
                mysqli_commit($conn);
                $msg = 'Access saved.';
                break;
            case 'delete':
                $ids = implode(',', lib_descendants($conn, $fid));
                if (count(lib_descendants($conn, $fid)) > 1) { throw new RuntimeException('Delete or move its sub-folders first.'); }
                if ((int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lib_documents WHERE folder_id IN ($ids)"))[0] > 0) {
                    throw new RuntimeException('This folder still holds documents (also counting the Trash). Move them, or delete them permanently, first.');
                }
                mysqli_query($conn, "DELETE FROM lib_folder_access WHERE folder_id = $fid");
                mysqli_query($conn, "DELETE FROM lib_folders WHERE id = $fid");
                lib_log($conn, null, $fid, 'folder_deleted', $all[$fid]['name']);
                $msg = 'Folder deleted.';
                break;
            default: throw new RuntimeException('Unknown action.');
        }
        header('Location: folders.php?success=' . urlencode($msg)); exit;
    } catch (Throwable $ex) {
        @mysqli_rollback($conn);
        $error = $ex instanceof RuntimeException && !($ex instanceof mysqli_sql_exception) ? $ex->getMessage() : 'Unable to save. (' . $ex->getMessage() . ')';
    }
}
$all = lib_folders($conn);
$counts = [];
foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT folder_id, COUNT(*) c FROM lib_documents WHERE deleted_at IS NULL GROUP BY folder_id'), MYSQLI_ASSOC) as $r) { $counts[(int) $r['folder_id']] = (int) $r['c']; }
$access = [];
foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT folder_id, user_id FROM lib_folder_access'), MYSQLI_ASSOC) as $r) { $access[(int) $r['folder_id']][] = (int) $r['user_id']; }
$people = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names'), MYSQLI_ASSOC);
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
$ordered = [];
$walk = function ($parent, $depth) use (&$walk, &$ordered, $all) { foreach ($all as $id => $f) { if ((int) $f['parent_id'] === $parent) { $ordered[] = [$id, $depth]; $walk($id, $depth + 1); } } };
$walk(0, 0);
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success"><?= $e($_GET['success']); ?></div><?php } ?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<div class="d-flex justify-content-between align-items-center mb-3"><h2 class="mb-0">Library folders</h2><a href="index.php" class="rm-btn rm-btn-light rm-btn-sm">Back to the Library</a></div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <form method="POST" class="row g-2 align-items-end"><input type="hidden" name="action" value="create">
        <div class="col-md-4"><label class="form-label small text-muted mb-1">New folder</label><input name="name" class="form-control form-control-sm" maxlength="150" required></div>
        <div class="col-md-4"><label class="form-label small text-muted mb-1">Inside</label><select name="parent_id" class="form-select form-select-sm"><option value="0">(top level)</option>
            <?php foreach ($ordered as [$id, $d]) { ?><option value="<?= $id; ?>"><?= str_repeat('— ', $d) . $e($all[$id]['name']); ?></option><?php } ?></select></div>
        <div class="col-md-3"><label class="form-label small text-muted mb-1">Description</label><input name="description" class="form-control form-control-sm" maxlength="255"></div>
        <div class="col-md-1"><button class="btn btn-primary btn-sm w-100">Add</button></div>
    </form>
</div></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm bg-white mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Folder</th><th class="text-end">Documents</th><th>Access</th><th></th></tr>
    <?php foreach ($ordered as [$id, $d]) { $f = $all[$id]; ?>
    <tr><td class="ps-3" style="padding-left:<?= 12 + 18 * $d; ?>px !important;"><i class="bi bi-folder me-1" style="color:#1E2FE0;"></i><?= $e($f['name']); ?>
            <?php if ($f['description']) { ?><div class="text-muted small"><?= $e($f['description']); ?></div><?php } ?></td>
        <td class="text-end"><?= $counts[$id] ?? 0; ?></td>
        <td><?= (int) $f['is_restricted'] ? '<span class="badge bg-dark"><i class="bi bi-lock-fill"></i> ' . count($access[$id] ?? []) . ' people</span>' : '<span class="text-muted small">Everyone with Library access</span>'; ?></td>
        <td class="text-nowrap">
            <details class="d-inline-block"><summary class="btn btn-sm btn-outline-primary">Manage</summary>
            <div class="border rounded p-2 mt-1 bg-white" style="min-width:300px;">
                <form method="POST" class="mb-2"><input type="hidden" name="action" value="edit"><input type="hidden" name="folder_id" value="<?= $id; ?>">
                    <input name="name" class="form-control form-control-sm mb-1" value="<?= $e($f['name']); ?>" maxlength="150" required>
                    <input name="description" class="form-control form-control-sm mb-1" value="<?= $e($f['description']); ?>" maxlength="255" placeholder="Description">
                    <select name="parent_id" class="form-select form-select-sm mb-1"><option value="0">(top level)</option>
                        <?php foreach ($ordered as [$oid, $od]) { if (in_array($oid, lib_descendants($conn, $id), true)) { continue; } ?><option value="<?= $oid; ?>" <?= (int) $f['parent_id'] === $oid ? 'selected' : ''; ?>><?= str_repeat('— ', $od) . $e($all[$oid]['name']); ?></option><?php } ?></select>
                    <button class="btn btn-primary btn-sm">Save</button></form>
                <form method="POST" class="mb-2"><input type="hidden" name="action" value="access"><input type="hidden" name="folder_id" value="<?= $id; ?>">
                    <label class="small"><input type="checkbox" name="is_restricted" value="1" class="form-check-input me-1" <?= (int) $f['is_restricted'] ? 'checked' : ''; ?>>Restricted to these people (and its sub-folders too):</label>
                    <div style="max-height:150px; overflow-y:auto;" class="small border rounded p-1 mb-1">
                    <?php foreach ($people as $p) { ?><label class="d-block"><input type="checkbox" name="users[]" value="<?= (int) $p['id']; ?>" class="form-check-input me-1" <?= in_array((int) $p['id'], $access[$id] ?? [], true) ? 'checked' : ''; ?>><?= $e($p['names']); ?></label><?php } ?>
                    </div><button class="btn btn-primary btn-sm">Save access</button></form>
                <form method="POST" onsubmit="return confirm('Delete the folder &quot;<?= $e($f['name']); ?>&quot;? (Only an empty folder can be deleted.)');"><input type="hidden" name="action" value="delete"><input type="hidden" name="folder_id" value="<?= $id; ?>">
                    <button class="btn btn-outline-danger btn-sm">Delete folder</button></form>
            </div></details>
        </td></tr>
    <?php } ?>
</table>
</div></div></div>
<p class="small text-muted mt-2">Library managers always see every folder. A restricted folder is hidden from everyone else not listed, and its documents cannot be opened (unless shared with them).</p>
<?php include '../../includes/footer.php'; ?>