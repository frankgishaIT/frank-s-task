<?php
/**
 * NEW FILE: modules/library/upload.php
 * Upload one or several documents (bulk) into a folder, with their details.
 * Someone without "Approve" uploads as "Pending Review"; a reviewer then approves it.
 */
require '../../config/db.php';
require_once '../../includes/library_helpers.php';
lib_ensure($conn);
lib_guard($conn, 'library.create');

$allowed = lib_allowed_folder_ids($conn);
$folderId = (int) ($_POST['folder_id'] ?? ($_GET['f'] ?? 0));
$canApprove = lib_can('approve');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $files = lib_files($_FILES['files'] ?? null);
    $title = trim((string) ($_POST['title'] ?? ''));
    $desc = trim((string) ($_POST['description'] ?? ''));
    $issue = (string) ($_POST['issue_date'] ?? '');
    $expiry = (string) ($_POST['expiry_date'] ?? '');
    $status = $canApprove && ($_POST['status'] ?? '') === 'Pending Review' ? 'Pending Review' : ($canApprove ? 'Active' : 'Pending Review');
    $validDate = function ($d) { return $d === '' || (bool) DateTime::createFromFormat('Y-m-d', $d); };
    if (!in_array($folderId, $allowed, true)) { $error = 'Choose a folder.'; }
    elseif (!$files) { $error = 'Choose at least one file.'; }
    elseif (count($files) > 20) { $error = 'Upload at most 20 files at a time.'; }
    elseif (count($files) === 1 && $title === '') { $error = 'Give the document a title.'; }
    elseif (!$validDate($issue) || !$validDate($expiry) || ($issue !== '' && $expiry !== '' && $expiry < $issue)) { $error = 'Check the dates (the expiry date cannot be before the issue date).'; }
    else {
        $done = 0; $failed = []; $refs = [];
        foreach ($files as $file) {
            // Bulk: each file becomes a document titled after its file name (the shared details apply to all).
            $t = count($files) === 1 ? $title : ($title !== '' ? $title . ' — ' : '') . pathinfo((string) $file['name'], PATHINFO_FILENAME);
            mysqli_begin_transaction($conn);
            try {
                $uid = (int) current_user_id();
                $issueV = $issue !== '' ? $issue : null; $expiryV = $expiry !== '' ? $expiry : null; $refV = null; $descV = $desc !== '' ? $desc : null;
                $t = mb_substr($t, 0, 200);
                $s = mysqli_prepare($conn, 'INSERT INTO lib_documents (folder_id, title, description, reference_no, issue_date, expiry_date, status, uploaded_by, current_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)');
                mysqli_stmt_bind_param($s, 'issssssi', $folderId, $t, $descV, $refV, $issueV, $expiryV, $status, $uid);
                mysqli_stmt_execute($s);
                $docId = (int) mysqli_insert_id($conn);
                $refs[] = lib_assign_reference($conn, $docId); // CHANGED: automatic reference number
                lib_add_version($conn, $docId, $file, 'Original');
                lib_log($conn, $docId, $folderId, 'uploaded', $file['name'] . ' into ' . lib_folder_path($conn, $folderId) . ' (' . $status . ')');
                mysqli_commit($conn);
                $done++;
            } catch (Throwable $ex) {
                mysqli_rollback($conn);
                $failed[] = $file['name'] . ': ' . ($ex instanceof RuntimeException && !($ex instanceof mysqli_sql_exception) ? $ex->getMessage() : 'could not be saved (' . $ex->getMessage() . ')');
            }
        }
        if ($done && !$failed) {
            header('Location: index.php?f=' . $folderId . '&success=' . urlencode($done . ' document(s) uploaded' . ($status === 'Pending Review' ? ' and waiting for review' : '')
                . ' · reference' . ($done > 1 ? 's ' . $refs[0] . ' to ' . end($refs) : ' ' . $refs[0]) . '.'));
            exit;
        }
        $error = ($done ? $done . ' uploaded. ' : '') . 'Not uploaded: ' . implode('; ', $failed);
    }
}
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
$modal_icon = 'bi-upload'; $modal_title = 'Upload documents'; $modal_subtitle = 'One file, or several at once (each becomes its own document).';
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<div class="rm-modal-backdrop"><div class="rm-modal">
<?php include '../../includes/model_header.php'; ?>
<div class="rm-modal-body">
    <?php if (isset($error)) { ?><div class="alert alert-danger small"><?= $e($error); ?></div><?php } ?>
    <form method="POST" enctype="multipart/form-data">
        <div class="mb-3"><label class="form-label small fw-semibold text-muted">Folder <span class="text-danger">*</span></label>
            <select name="folder_id" class="form-select rm-input" required><option value="">Choose a folder</option>
            <?php foreach ($allowed as $fid) { ?><option value="<?= $fid; ?>" <?= $folderId === $fid ? 'selected' : ''; ?>><?= $e(lib_folder_path($conn, $fid)); ?></option><?php } ?></select></div>
        <div class="mb-3"><label class="form-label small fw-semibold text-muted">File(s) <span class="text-danger">*</span></label>
            <input type="file" name="files[]" class="form-control rm-input" multiple required accept="<?= LIB_ACCEPT; ?>">
            <div class="form-text">PDF, Word, Excel, PowerPoint, JPG/PNG/WEBP, TXT, CSV · up to <?= LIB_MAX_BYTES / 1048576; ?> MB each · up to 20 files.</div></div>
        <div class="mb-3"><label class="form-label small fw-semibold text-muted">Title</label>
            <input type="text" name="title" class="form-control rm-input" maxlength="200" value="<?= $e($_POST['title'] ?? ''); ?>">
            <div class="form-text">Required for one file. For several files, each is titled after its file name (this title is put in front, if given).</div></div>
        <div class="mb-3"><label class="form-label small fw-semibold text-muted">Description</label>
            <textarea name="description" class="form-control rm-input" rows="2"><?= $e($_POST['description'] ?? ''); ?></textarea></div>
        <div class="row g-3 mb-1">
            <div class="col-md-6"><label class="form-label small fw-semibold text-muted">Issue date</label><input type="date" name="issue_date" class="form-control rm-input" value="<?= $e($_POST['issue_date'] ?? ''); ?>"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold text-muted">Expiry date</label><input type="date" name="expiry_date" class="form-control rm-input" value="<?= $e($_POST['expiry_date'] ?? ''); ?>"></div>
        </div>
        <div class="form-text mb-3">The reference number (e.g. RM-DOC-<?= date('Y'); ?>-00042) is created automatically.</div>
        <?php if ($canApprove) { ?>
        <div class="mb-3"><label class="form-label small fw-semibold text-muted">Status</label>
            <select name="status" class="form-select rm-input"><option value="Active">Active</option><option value="Pending Review">Pending review</option></select></div>
        <?php } else { ?><div class="small text-muted mb-3">Your upload will be <strong>Pending review</strong> until a reviewer approves it.</div><?php } ?>
        <div class="d-flex gap-2 justify-content-end"><button class="rm-btn rm-btn-primary"><i class="bi bi-upload me-1"></i>Upload</button><a href="index.php" class="rm-btn rm-btn-secondary">Cancel</a></div>
    </form>
</div></div></div>
<?php include '../../includes/footer.php'; ?>