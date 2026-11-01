<?php
/**
 * NEW FILE: modules/library/document.php
 * One document: details, preview, versions, activity, and its actions (each checked on the server).
 */
require '../../config/db.php';
require_once '../../includes/library_helpers.php';
lib_ensure($conn);
lib_guard($conn);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$doc = $id ? lib_document($conn, (int) $id, lib_can('delete')) : null;
if (!$doc) { header('Location: index.php?error=' . urlencode('That document does not exist or you do not have access to it.')); exit; }
$id = (int) $doc['id'];
$uid = (int) current_user_id();
$allowed = lib_allowed_folder_ids($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $need = ['edit' => 'edit', 'move' => 'edit', 'replace' => 'replace', 'share' => 'share', 'approve' => 'approve',
             'archive' => 'archive', 'unarchive' => 'archive', 'trash' => 'delete', 'restore' => 'delete', 'purge' => 'delete'][$action] ?? null;
    try {
        if (!$need || !lib_can($need)) { throw new RuntimeException('You are not allowed to do that.'); }
        if ($doc['deleted_at'] && !in_array($action, ['restore', 'purge'], true)) { throw new RuntimeException('Restore the document from the Trash first.'); }
        $msg = '';
        switch ($action) {
            case 'edit':
                $t = mb_substr(trim((string) $_POST['title']), 0, 200);
                $issue = (string) ($_POST['issue_date'] ?? ''); $expiry = (string) ($_POST['expiry_date'] ?? '');
                if ($t === '') { throw new RuntimeException('The title cannot be empty.'); }
                foreach ([$issue, $expiry] as $d) { if ($d !== '' && !DateTime::createFromFormat('Y-m-d', $d)) { throw new RuntimeException('Check the dates.'); } }
                if ($issue !== '' && $expiry !== '' && $expiry < $issue) { throw new RuntimeException('The expiry date cannot be before the issue date.'); }
                $desc = trim((string) $_POST['description']); $ref = mb_substr(trim((string) $_POST['reference_no']), 0, 80);
                $iV = $issue ?: null; $eV = $expiry ?: null; $dV = $desc ?: null; $rV = $ref ?: null;
                $s = mysqli_prepare($conn, 'UPDATE lib_documents SET title = ?, description = ?, reference_no = ?, issue_date = ?, expiry_date = ?, updated_at = NOW(),
                    reminded_30 = IF(expiry_date <=> ?, reminded_30, 0), reminded_0 = IF(expiry_date <=> ?, reminded_0, 0) WHERE id = ?');
                mysqli_stmt_bind_param($s, 'sssssssi', $t, $dV, $rV, $iV, $eV, $eV, $eV, $id);
                mysqli_stmt_execute($s);
                lib_log($conn, $id, (int) $doc['folder_id'], 'edited', 'Details changed');
                $msg = 'Details saved.';
                break;
            case 'replace':
                $files = lib_files($_FILES['file'] ?? null);
                if (!$files) { throw new RuntimeException('Choose the new file.'); }
                mysqli_begin_transaction($conn);
                $n = lib_add_version($conn, $id, $files[0], mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255) ?: null);
                lib_log($conn, $id, (int) $doc['folder_id'], 'replaced', 'Version ' . $n . ': ' . $files[0]['name']);
                mysqli_commit($conn);
                $msg = 'Version ' . $n . ' saved. Earlier versions are kept in the history.';
                break;
            case 'move':
                $to = (int) $_POST['folder_id'];
                if (!in_array($to, $allowed, true)) { throw new RuntimeException('Choose a folder you can use.'); }
                mysqli_query($conn, 'UPDATE lib_documents SET folder_id = ' . $to . ', updated_at = NOW() WHERE id = ' . $id);
                lib_log($conn, $id, $to, 'moved', lib_folder_path($conn, (int) $doc['folder_id']) . ' → ' . lib_folder_path($conn, $to));
                $msg = 'Moved to ' . lib_folder_path($conn, $to) . '.';
                break;
            case 'share':
                $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['users'] ?? [])))));
                $before = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT user_id FROM lib_shares WHERE document_id = ' . $id), MYSQLI_ASSOC), 'user_id'));
                mysqli_query($conn, 'DELETE FROM lib_shares WHERE document_id = ' . $id);
                $ins = mysqli_prepare($conn, 'INSERT INTO lib_shares (document_id, user_id, shared_by) VALUES (?, ?, ?)');
                foreach ($ids as $u) { mysqli_stmt_bind_param($ins, 'iii', $id, $u, $uid); mysqli_stmt_execute($ins); }
                foreach (array_diff($ids, $before) as $u) {
                    try { notifyUser($conn, (int) $u, 'Document shared with you', '"' . $doc['title'] . '" was shared with you in RM Digital Library.'); } catch (Throwable $x) {}
                }
                lib_log($conn, $id, (int) $doc['folder_id'], 'shared', 'Shared with ' . count($ids) . ' employee(s)');
                $msg = $ids ? 'Shared with ' . count($ids) . ' employee(s).' : 'No longer shared.';
                break;
            case 'approve':
                mysqli_query($conn, "UPDATE lib_documents SET status = 'Active', reviewed_by = $uid, reviewed_at = NOW() WHERE id = $id AND status = 'Pending Review'");
                lib_log($conn, $id, (int) $doc['folder_id'], 'approved', 'Review approved');
                if ($doc['uploaded_by'] && (int) $doc['uploaded_by'] !== $uid) { try { notifyUser($conn, (int) $doc['uploaded_by'], 'Document approved', '"' . $doc['title'] . '" was approved in RM Digital Library.'); } catch (Throwable $x) {} }
                $msg = 'Approved.';
                break;
            case 'archive': case 'unarchive':
                $st = $action === 'archive' ? "'Archived', archived_at = NOW()" : "'Active', archived_at = NULL";
                mysqli_query($conn, "UPDATE lib_documents SET status = $st WHERE id = $id");
                lib_log($conn, $id, (int) $doc['folder_id'], $action . 'd', '');
                $msg = $action === 'archive' ? 'Archived.' : 'Back in use.';
                break;
            case 'trash':
                mysqli_query($conn, "UPDATE lib_documents SET deleted_at = NOW(), deleted_by = $uid WHERE id = $id");
                lib_log($conn, $id, (int) $doc['folder_id'], 'deleted', 'Moved to the Trash');
                header('Location: index.php?f=' . (int) $doc['folder_id'] . '&success=' . urlencode('Moved to the Trash. It can be restored from there.')); exit;
            case 'restore':
                mysqli_query($conn, "UPDATE lib_documents SET deleted_at = NULL, deleted_by = NULL WHERE id = $id");
                lib_log($conn, $id, (int) $doc['folder_id'], 'restored', 'Restored from the Trash');
                $msg = 'Restored.';
                break;
            case 'purge':
                if (!$doc['deleted_at']) { throw new RuntimeException('Move it to the Trash first.'); }
                if (($_POST['confirm_text'] ?? '') !== 'DELETE') { throw new RuntimeException('Type DELETE to confirm the permanent deletion.'); }
                mysqli_begin_transaction($conn);
                foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT file_ref FROM lib_versions WHERE document_id = ' . $id), MYSQLI_ASSOC) as $v) {
                    if (strpos($v['file_ref'], 'db:') === 0) { $fid = (int) substr($v['file_ref'], 3); mysqli_query($conn, "DELETE FROM evidence_file_chunks WHERE file_id = $fid"); mysqli_query($conn, "DELETE FROM evidence_files WHERE id = $fid"); }
                }
                mysqli_query($conn, 'DELETE FROM lib_versions WHERE document_id = ' . $id);
                mysqli_query($conn, 'DELETE FROM lib_shares WHERE document_id = ' . $id);
                mysqli_query($conn, 'DELETE FROM lib_documents WHERE id = ' . $id);
                lib_log($conn, $id, (int) $doc['folder_id'], 'purged', 'Permanently deleted: "' . $doc['title'] . '"' . ($doc['reference_no'] ? ' (' . $doc['reference_no'] . ')' : ''));
                mysqli_commit($conn);
                header('Location: index.php?view=trash&success=' . urlencode('Permanently deleted. The activity log keeps a record of it.')); exit;
        }
        header('Location: document.php?id=' . $id . '&success=' . urlencode($msg)); exit;
    } catch (Throwable $ex) {
        @mysqli_rollback($conn);
        $error = $ex instanceof RuntimeException && !($ex instanceof mysqli_sql_exception) ? $ex->getMessage() : 'Unable to save. Nothing was changed. (' . $ex->getMessage() . ')';
    }
}

$versions = mysqli_fetch_all(mysqli_query($conn, "SELECT v.*, u.names FROM lib_versions v LEFT JOIN users u ON u.id = v.uploaded_by WHERE v.document_id = $id ORDER BY v.version_no DESC"), MYSQLI_ASSOC);
$current = $versions[0] ?? null;
$ext = $current ? strtolower(pathinfo($current['original_name'], PATHINFO_EXTENSION)) : '';
$previewable = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'txt'], true);
$showLog = lib_can('manage') || lib_can('edit');
$activity = $showLog ? mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, u.names FROM lib_activity a LEFT JOIN users u ON u.id = a.user_id WHERE a.document_id = $id ORDER BY a.id DESC LIMIT 100"), MYSQLI_ASSOC) : [];
$people = lib_can('share') ? mysqli_fetch_all(mysqli_query($conn, "SELECT id, names FROM users WHERE is_active = 1 AND id <> $uid ORDER BY names"), MYSQLI_ASSOC) : [];
$sharedWith = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT user_id FROM lib_shares WHERE document_id = $id"), MYSQLI_ASSOC), 'user_id'));
if (!$_POST && $current && !isset($_GET['success'])) { lib_log($conn, $id, (int) $doc['folder_id'], 'opened', ''); }
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
$size = function ($b) { $b = (int) $b; return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB'; };
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success alert-dismissible fade show"><?= $e($_GET['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<a href="index.php?f=<?= (int) $doc['folder_id']; ?>" class="small text-muted text-decoration-none"><i class="bi bi-arrow-left"></i> <?= $e(lib_folder_path($conn, (int) $doc['folder_id'])); ?></a>
<h2 class="mt-1 mb-1"><?= $e($doc['title']); ?></h2>
<div class="small text-muted mb-3">
    <?= $doc['deleted_at'] ? '<span class="badge bg-danger">In trash</span>' : ($doc['status'] === 'Pending Review' ? '<span class="badge bg-warning">Pending review</span>' : ($doc['status'] === 'Archived' ? '<span class="badge bg-secondary">Archived</span>' : '<span class="badge bg-success">Active</span>')); ?>
    · Version <?= (int) $doc['current_version']; ?> · uploaded by <?= $e($doc['uploader'] ?? '—'); ?> on <?= date('d M Y, H:i', strtotime($doc['created_at'])); ?>
</div>
<div class="row g-3">
<div class="col-lg-8">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-2">
        <?php if ($current && $previewable && !$doc['deleted_at']) { ?>
            <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { ?>
                <img src="file.php?v=<?= (int) $current['id']; ?>" alt="" style="max-width:100%; display:block; margin:auto;">
            <?php } else { ?>
                <iframe src="file.php?v=<?= (int) $current['id']; ?>" style="width:100%; height:70vh; border:0;" title="Preview"></iframe>
            <?php } ?>
        <?php } else { ?>
            <div class="text-center text-muted py-5"><i class="bi bi-file-earmark fs-1 d-block mb-2"></i><?= $e($current['original_name'] ?? ''); ?><br>
                <?= $doc['deleted_at'] ? 'In the Trash.' : 'This type of file cannot be shown in the browser.' . (lib_can('download') ? ' Use Download.' : ''); ?></div>
        <?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-0">
        <table class="table table-sm mb-0 align-middle" style="font-size:13px;">
            <tr><th class="ps-3">Version</th><th>File</th><th>Note</th><th>By</th><th>Date</th><th></th></tr>
            <?php foreach ($versions as $v) { ?>
            <tr><td class="ps-3">v<?= (int) $v['version_no']; ?><?= (int) $v['version_no'] === (int) $doc['current_version'] ? ' <span class="badge bg-primary">current</span>' : ''; ?></td>
                <td><?= $e($v['original_name']); ?> <span class="text-muted">(<?= $size($v['size_bytes']); ?>)</span></td><td><?= $e($v['note']); ?></td>
                <td><?= $e($v['names']); ?></td><td class="text-nowrap"><?= date('d M Y, H:i', strtotime($v['created_at'])); ?></td>
                <td class="text-nowrap"><?php if (lib_can('download')) { ?><a href="file.php?v=<?= (int) $v['id']; ?>&mode=download" class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i> Download</a><?php } ?></td></tr>
            <?php } ?>
        </table>
    </div></div>
    <?php if ($showLog) { ?>
    <details class="card border-0 shadow-sm"><summary class="card-body py-2 small fw-semibold">Activity log (<?= count($activity); ?>)</summary>
        <div class="px-3 pb-3 small" style="max-height:300px; overflow-y:auto;">
        <?php foreach ($activity as $a) { ?><div class="border-bottom py-1"><span class="text-muted"><?= date('d M Y, H:i', strtotime($a['created_at'])); ?></span> · <strong><?= $e($a['names'] ?? '—'); ?></strong> <?= $e($a['action']); ?> <?= $e($a['details']); ?></div><?php } ?>
        </div></details>
    <?php } ?>
</div>
<div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
        <div><strong>Reference:</strong> <?= $e($doc['reference_no'] ?: '—'); ?></div>
        <div><strong>Issue date:</strong> <?= $doc['issue_date'] ? date('d M Y', strtotime($doc['issue_date'])) : '—'; ?></div>
        <div><strong>Expiry date:</strong> <?= $doc['expiry_date'] ? date('d M Y', strtotime($doc['expiry_date'])) . ($doc['expiry_date'] < date('Y-m-d') ? ' <span class="badge bg-danger">Expired</span>' : '') : '—'; ?></div>
        <div class="mt-2"><?= nl2br($e($doc['description'] ?: 'No description.')); ?></div>
        <?php if ($current && lib_can('download') && !$doc['deleted_at']) { ?><a href="file.php?v=<?= (int) $current['id']; ?>&mode=download" class="btn btn-primary btn-sm mt-3"><i class="bi bi-download me-1"></i>Download</a><?php } ?>
    </div></div>

    <?php if ($doc['deleted_at']) { ?>
        <?php if (lib_can('delete')) { ?>
        <div class="card border-0 shadow-sm"><div class="card-body">
            <form method="POST" class="mb-3"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="restore"><button class="btn btn-success btn-sm w-100">Restore from the Trash</button></form>
            <form method="POST" onsubmit="return confirm('Delete this document and all its versions permanently?');"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="purge">
                <label class="small text-muted">Type DELETE to remove it for good</label><input name="confirm_text" class="form-control form-control-sm mb-2" autocomplete="off">
                <button class="btn btn-outline-danger btn-sm w-100">Delete permanently</button></form>
        </div></div>
        <?php } ?>
    <?php } else { ?>
    <div class="accordion" id="docActions">
        <?php if ($doc['status'] === 'Pending Review' && lib_can('approve')) { ?>
        <form method="POST" class="mb-2"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="approve"><button class="btn btn-success btn-sm w-100"><i class="bi bi-check2-circle me-1"></i>Approve</button></form>
        <?php } ?>
        <?php if (lib_can('edit')) { ?>
        <details class="card border-0 shadow-sm mb-2"><summary class="card-body py-2 small fw-semibold">Edit details</summary><div class="card-body pt-0">
            <form method="POST"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="edit">
                <input name="title" class="form-control form-control-sm mb-2" maxlength="200" value="<?= $e($doc['title']); ?>" required>
                <textarea name="description" class="form-control form-control-sm mb-2" rows="2" placeholder="Description"><?= $e($doc['description']); ?></textarea>
                <input name="reference_no" class="form-control form-control-sm mb-2" maxlength="80" value="<?= $e($doc['reference_no']); ?>" placeholder="Reference no.">
                <div class="row g-2 mb-2"><div class="col"><label class="small text-muted">Issue</label><input type="date" name="issue_date" class="form-control form-control-sm" value="<?= $e($doc['issue_date']); ?>"></div>
                <div class="col"><label class="small text-muted">Expiry</label><input type="date" name="expiry_date" class="form-control form-control-sm" value="<?= $e($doc['expiry_date']); ?>"></div></div>
                <button class="btn btn-primary btn-sm">Save</button></form></div></details>
        <details class="card border-0 shadow-sm mb-2"><summary class="card-body py-2 small fw-semibold">Move to another folder</summary><div class="card-body pt-0">
            <form method="POST"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="move">
                <select name="folder_id" class="form-select form-select-sm mb-2"><?php foreach ($allowed as $fid) { ?><option value="<?= $fid; ?>" <?= (int) $doc['folder_id'] === $fid ? 'selected' : ''; ?>><?= $e(lib_folder_path($conn, $fid)); ?></option><?php } ?></select>
                <button class="btn btn-primary btn-sm">Move</button></form></div></details>
        <?php } ?>
        <?php if (lib_can('replace')) { ?>
        <details class="card border-0 shadow-sm mb-2"><summary class="card-body py-2 small fw-semibold">Upload a new version</summary><div class="card-body pt-0">
            <form method="POST" enctype="multipart/form-data"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="replace">
                <input type="file" name="file" class="form-control form-control-sm mb-2" accept="<?= LIB_ACCEPT; ?>" required>
                <input name="note" class="form-control form-control-sm mb-2" maxlength="255" placeholder="What changed? e.g. Final signed agreement">
                <button class="btn btn-primary btn-sm">Save as version <?= (int) $doc['current_version'] + 1; ?></button></form></div></details>
        <?php } ?>
        <?php if (lib_can('share')) { ?>
        <details class="card border-0 shadow-sm mb-2"><summary class="card-body py-2 small fw-semibold">Share (<?= count($sharedWith); ?>)</summary><div class="card-body pt-0">
            <form method="POST"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="share">
                <div class="small text-muted mb-1">Shared people can open it even without access to its folder.</div>
                <div style="max-height:180px; overflow-y:auto;" class="small mb-2">
                <?php foreach ($people as $p) { ?><label class="d-block"><input type="checkbox" class="form-check-input me-1" name="users[]" value="<?= (int) $p['id']; ?>" <?= in_array((int) $p['id'], $sharedWith, true) ? 'checked' : ''; ?>><?= $e($p['names']); ?></label><?php } ?>
                </div><button class="btn btn-primary btn-sm">Save sharing</button></form></div></details>
        <?php } ?>
        <?php if (lib_can('archive')) { ?>
        <form method="POST" class="mb-2"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="<?= $doc['status'] === 'Archived' ? 'unarchive' : 'archive'; ?>">
            <button class="btn btn-outline-secondary btn-sm w-100"><?= $doc['status'] === 'Archived' ? 'Take out of the archive' : 'Archive'; ?></button></form>
        <?php } ?>
        <?php if (lib_can('delete')) { ?>
        <form method="POST" onsubmit="return confirm('Move this document to the Trash? It can be restored from there.');"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="trash">
            <button class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-trash me-1"></i>Move to Trash</button></form>
        <?php } ?>
    </div>
    <?php } ?>
</div>
</div>
<?php include '../../includes/footer.php'; ?>