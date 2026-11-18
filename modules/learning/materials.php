<?php
/**
 * NEW FILE: modules/learning/materials.php — COURSE MATERIALS: instructors (and academic staff) publish learning materials,
 * assignments and course documents to the students of their courses / classes, through RM Digital Library.
 * Published at once (no approval, no access requests): enrolled students see them in the Customer Portal.
 * Instructors manage only the materials of the classes they teach. Every upload, change and replacement is logged.
 */
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/library_helpers.php';
require_once __DIR__ . '/../../includes/lib_access.php';
lib_ensure($conn);
lib_access_ensure($conn);
$academic = lcs_can($conn, 'academic');
$me = lc_my_instructor_id($conn);
$set = lib_aud_settings($conn);
if (!$academic && ($me === null || $set['instructors_publish'] !== '1')) { lcs_deny(); }
if (!rm_feature_enabled('digital_library')) { lcs_deny(); }
$uid = (int) current_user_id();
$scope = lib_instructor_scope($conn, $me, $academic);
$options = lib_course_options($conn, $scope);

/** May I manage this document? Every course / class it is published to must be mine. */
$mine = function (int $docId) use ($conn, $scope): ?array {
    $d = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lib_documents WHERE id = $docId AND deleted_at IS NULL"));
    if (!$d) { return null; }
    $links = lib_doc_courses($conn, $docId);
    if (!$links) { return null; }
    foreach ($links as [$c, $k]) {
        if (!isset($scope[$c])) { return null; }
        if ($scope[$c] !== ['*'] && (!$k || !in_array($k, $scope[$c], true))) { return null; }
    }
    return $d;
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { lcs_go('materials.php', 'error', 'The page had expired.'); }
    $do = (string) ($_POST['do'] ?? '');
    try {
        if ($do === 'new') {
            $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
            $files = lib_files($_FILES['files'] ?? null);
            if (!$files) { throw new RuntimeException('Choose the file(s).'); }
            if (count($files) > 10) { throw new RuntimeException('Upload at most 10 files at a time.'); }
            if (count($files) === 1 && $title === '') { throw new RuntimeException('Give it a title.'); }
            $acc = lib_access_from_post($conn, $_POST, $scope);
            $folder = lib_course_folder($conn, $acc['courses'][0][0], $uid);
            $desc = trim((string) ($_POST['description'] ?? '')) ?: null; $n = 0;
            foreach ($files as $f) {
                $t = count($files) === 1 ? $title : mb_substr(($title !== '' ? $title . ' — ' : '') . pathinfo((string) $f['name'], PATHINFO_FILENAME), 0, 200);
                mysqli_begin_transaction($conn);
                $s = mysqli_prepare($conn, "INSERT INTO lib_documents (folder_id, title, description, status, uploaded_by, current_version) VALUES (?, ?, ?, 'Active', ?, 0)");
                mysqli_stmt_bind_param($s, 'issi', $folder, $t, $desc, $uid); mysqli_stmt_execute($s);
                $docId = (int) mysqli_insert_id($conn);
                lib_assign_reference($conn, $docId);
                lib_add_version($conn, $docId, $f, 'Original');
                lib_log($conn, $docId, $folder, 'uploaded', $f['name'] . ' (course materials, published to students)');
                lib_access_save($conn, $docId, $acc, $uid);
                mysqli_commit($conn);
                lc_audit($conn, 'material_publish', 'library_document', $docId, ['title' => $t, 'kind' => $acc['kind'], 'courses' => $acc['courses']]);
                $n++;
            }
            lcs_go('materials.php', 'success', $n . ' item(s) published: the students see them now in the Customer Portal.');
        }
        $d = $mine((int) ($_POST['id'] ?? 0));
        if (!$d) { throw new RuntimeException('You cannot change this item (it is not published to your classes).'); }
        $id = (int) $d['id'];
        if ($do === 'edit') {
            $t = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
            if ($t === '') { throw new RuntimeException('The title cannot be empty.'); }
            $acc = lib_access_from_post($conn, $_POST, $scope);
            $desc = trim((string) ($_POST['description'] ?? '')) ?: null;
            $s = mysqli_prepare($conn, 'UPDATE lib_documents SET title = ?, description = ?, updated_at = ? WHERE id = ?'); $now = date('Y-m-d H:i:s');
            mysqli_stmt_bind_param($s, 'sssi', $t, $desc, $now, $id); mysqli_stmt_execute($s);
            lib_log($conn, $id, (int) $d['folder_id'], 'edited', 'Details changed (course materials)');
            lib_access_save($conn, $id, $acc, $uid);
            lc_audit($conn, 'material_edit', 'library_document', $id, ['title' => $t]);
            lcs_go('materials.php', 'success', 'Saved.');
        }
        if ($do === 'replace') {
            $files = lib_files($_FILES['file'] ?? null);
            if (!$files) { throw new RuntimeException('Choose the new file.'); }
            mysqli_begin_transaction($conn);
            $v = lib_add_version($conn, $id, $files[0], mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255) ?: null);
            lib_log($conn, $id, (int) $d['folder_id'], 'replaced', 'Version ' . $v . ': ' . $files[0]['name']);
            mysqli_commit($conn);
            lc_audit($conn, 'material_replace', 'library_document', $id, ['version' => $v]);
            lcs_go('materials.php', 'success', 'Version ' . $v . ' published (students now get the new file; earlier versions are kept).');
        }
        if ($do === 'unpublish' || $do === 'republish') {
            $st = $do === 'unpublish' ? 'Archived' : 'Active';
            mysqli_query($conn, "UPDATE lib_documents SET status = '$st', archived_at = " . ($st === 'Archived' ? "'" . date('Y-m-d H:i:s') . "'" : 'NULL') . " WHERE id = $id");
            lib_log($conn, $id, (int) $d['folder_id'], $do === 'unpublish' ? 'archived' : 'unarchived', 'Course materials: ' . ($do === 'unpublish' ? 'hidden from students' : 'shown to students again'));
            lc_audit($conn, 'material_' . $do, 'library_document', $id, null);
            lcs_go('materials.php', 'success', $do === 'unpublish' ? 'Hidden from students (kept in the Library).' : 'Visible to students again.');
        }
    } catch (Throwable $ex) {
        @mysqli_rollback($conn);
        lcs_go('materials.php', 'error', $ex instanceof RuntimeException && !($ex instanceof mysqli_sql_exception) ? $ex->getMessage() : 'Not saved: ' . $ex->getMessage());
    }
}
$fc = (int) ($_GET['course'] ?? 0);
$ids = $scope ? implode(',', array_keys($scope)) : '0';
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT DISTINCT d.*, v.original_name, v.size_bytes, u.names uploader FROM lib_documents d JOIN lib_doc_courses x ON x.document_id = d.id
    LEFT JOIN lib_versions v ON v.document_id = d.id AND v.version_no = d.current_version LEFT JOIN users u ON u.id = d.uploaded_by
    WHERE d.deleted_at IS NULL AND d.audience = 'courses' AND x.course_id IN ($ids)" . ($fc ? " AND x.course_id = $fc" : '') . ' ORDER BY d.id DESC LIMIT 200'), MYSQLI_ASSOC);
$rows = array_values(array_filter($rows, function ($r) use ($mine) { return $mine((int) $r['id']) !== null; }));
$stats = [];
if ($rows) { foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT document_id, COUNT(DISTINCT customer_id) n, SUM(action = \'portal_downloaded\') dl FROM lib_activity WHERE customer_id IS NOT NULL AND document_id IN (' . implode(',', array_column($rows, 'id')) . ') GROUP BY document_id'), MYSQLI_ASSOC) as $r) { $stats[(int) $r['document_id']] = $r; } }
$students = function (array $d) use ($conn, $set): int {
    $st = "'" . implode("','", $set['statuses_list']) . "'"; $n = 0;
    foreach (lib_doc_courses($conn, (int) $d['id']) as [$c, $k]) { $n += (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(DISTINCT customer_id) FROM lc_enrollments WHERE course_id = $c AND status IN ($st)" . ($k ? " AND class_id = $k" : '')))[0]; }
    return $n;
};
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'materials.php', 'Course materials');
?>
<div class="row g-3">
<div class="col-xl-8">
    <form class="d-flex gap-2 mb-2 small align-items-center"><span class="text-muted">Course</span><select name="course" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()"><option value="0">All my courses</option><?php foreach ($options as $c => [$l]) { ?><option value="<?= $c; ?>" <?= $fc === $c ? 'selected' : ''; ?>><?= lc_e($l); ?></option><?php } ?></select></form>
    <div class="card border-0 shadow-sm"><div class="card-body p-0"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
        <tr class="table-light"><th class="ps-3">Item</th><th>For</th><th>Status</th><th class="text-end" title="Students who opened it / students who can see it">Opened</th><th></th></tr>
        <?php foreach ($rows as $d) { $s = $stats[(int) $d['id']] ?? ['n' => 0, 'dl' => 0]; ?>
        <tr><td class="ps-3"><div class="fw-semibold"><?= lc_e($d['title']); ?></div><div class="text-muted small"><?= lc_e(LIB_KINDS[$d['kind']] ?? 'Document'); ?><?= $d['due_date'] ? ' · due ' . date('d M Y', strtotime($d['due_date'])) : ''; ?> · <?= lc_e($d['original_name']); ?> · v<?= (int) $d['current_version']; ?><?= $d['allow_download'] ? '' : ' · view only'; ?></div></td>
            <td class="small"><?= lc_e(substr(lib_access_label($conn, $d), strlen('Selected courses: '))); ?></td>
            <td><?= $d['status'] === 'Active' ? '<span class="badge bg-success">Published</span>' : '<span class="badge bg-secondary">Hidden</span>'; ?></td>
            <td class="text-end"><?= (int) $s['n']; ?> / <?= $students($d); ?><div class="text-muted small"><?= (int) $s['dl']; ?> download(s)</div></td>
            <td class="text-end pe-2 text-nowrap"><button type="button" class="btn btn-sm btn-light border py-0" data-bs-toggle="collapse" data-bs-target="#m<?= (int) $d['id']; ?>">Manage</button></td></tr>
        <tr class="collapse" id="m<?= (int) $d['id']; ?>"><td colspan="5" class="bg-light"><div class="row g-3 p-2">
            <div class="col-md-7"><form method="POST"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="edit"><input type="hidden" name="id" value="<?= (int) $d['id']; ?>">
                <input name="title" class="form-control form-control-sm mb-1" value="<?= lc_e($d['title']); ?>" maxlength="200" required><textarea name="description" class="form-control form-control-sm mb-2" rows="2" placeholder="Instructions for the students"><?= lc_e($d['description']); ?></textarea>
                <?= lib_access_fields($conn, $d, $options, true); ?><button class="btn btn-sm btn-primary">Save</button></form></div>
            <div class="col-md-5"><form method="POST" enctype="multipart/form-data" class="mb-3"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="replace"><input type="hidden" name="id" value="<?= (int) $d['id']; ?>">
                <div class="small fw-semibold mb-1">Replace the file</div><input type="file" name="file" class="form-control form-control-sm mb-1" accept="<?= LIB_ACCEPT; ?>" required><input name="note" class="form-control form-control-sm mb-1" placeholder="What changed">
                <button class="btn btn-sm btn-outline-primary">Publish version <?= (int) $d['current_version'] + 1; ?></button></form>
                <form method="POST"><?= lcs_csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $d['id']; ?>"><input type="hidden" name="do" value="<?= $d['status'] === 'Active' ? 'unpublish' : 'republish'; ?>"><button class="btn btn-sm btn-outline-secondary"><?= $d['status'] === 'Active' ? 'Hide from students' : 'Show to students again'; ?></button></form>
                <?php if (lib_can('view')) { ?><a class="small d-inline-block mt-2" href="../library/document.php?id=<?= (int) $d['id']; ?>">Open in RM Digital Library</a><?php } ?></div>
        </div></td></tr>
        <?php } ?>
        <?php if (!$rows) { ?><tr><td colspan="5" class="text-center text-muted py-4">Nothing published yet.</td></tr><?php } ?>
    </table></div></div>
</div>
<div class="col-xl-4">
    <?php if ($options) { ?>
    <form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm"><div class="card-body"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="new">
        <div class="fw-semibold mb-2">Publish to students</div>
        <input name="title" class="form-control form-control-sm mb-2" maxlength="200" placeholder="Title (e.g. Week 3 — CSS layouts)">
        <textarea name="description" class="form-control form-control-sm mb-2" rows="2" placeholder="Short instructions (optional)"></textarea>
        <input type="file" name="files[]" multiple class="form-control form-control-sm mb-1" accept="<?= LIB_ACCEPT; ?>" required>
        <div class="form-text mb-2">PDF, Word, Excel, PowerPoint, images · up to <?= LIB_MAX_BYTES / 1048576; ?> MB each. Several files: each becomes its own item.</div>
        <?= lib_access_fields($conn, null, $options, true); ?>
        <button class="btn btn-sm btn-primary">Publish</button>
        <div class="small text-muted mt-2">Published at once: the students of the course or class see it in their Customer Portal → RM Digital Library. Nobody needs to approve it, and students do not need to request access.</div>
    </div></form>
    <?php } else { ?><div class="alert alert-light border small">You are not assigned to any class yet. When you teach a class, you can publish materials to its students here.</div><?php } ?>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>