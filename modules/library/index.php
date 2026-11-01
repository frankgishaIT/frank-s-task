<?php
/**
 * NEW FILE: modules/library/index.php
 * RM Digital Library: folders, views (all, recent, mine, shared, pending review, expiring, archived, trash) and search.
 */
require '../../config/db.php';
require_once '../../includes/library_helpers.php';
lib_ensure($conn);
lib_guard($conn);
lib_expiry_reminders($conn);

$uid = (int) current_user_id();
$folders = lib_folders($conn);
$allowed = lib_allowed_folder_ids($conn);
$view = $_GET['view'] ?? 'all';
$views = ['all' => 'All documents', 'recent' => 'Recently uploaded', 'mine' => 'My documents', 'shared' => 'Shared with me',
          'pending' => 'Pending review', 'expiring' => 'Expiring (60 days)', 'archived' => 'Archived'];
if (lib_can('delete')) { $views['trash'] = 'Trash'; }
if (!isset($views[$view])) { $view = 'all'; }
$folderId = (int) ($_GET['f'] ?? 0);
if ($folderId && !in_array($folderId, $allowed, true)) { $folderId = 0; }
$q = trim((string) ($_GET['q'] ?? ''));

// Visibility: documents in allowed folders, plus documents shared with me.
$in = $allowed ? implode(',', array_map('intval', $allowed)) : '0';
$where = ["(d.folder_id IN ($in) OR EXISTS (SELECT 1 FROM lib_shares s WHERE s.document_id = d.id AND s.user_id = $uid))"];
$types = ''; $params = [];
$where[] = $view === 'trash' ? 'd.deleted_at IS NOT NULL' : 'd.deleted_at IS NULL';
if ($view === 'archived') { $where[] = "d.status = 'Archived'"; }
elseif ($view !== 'trash') { $where[] = "d.status <> 'Archived'"; }
if ($view === 'mine') { $where[] = 'd.uploaded_by = ' . $uid; }
if ($view === 'shared') { $where[] = "EXISTS (SELECT 1 FROM lib_shares s WHERE s.document_id = d.id AND s.user_id = $uid)"; }
if ($view === 'pending') { $where[] = "d.status = 'Pending Review'"; }
if ($view === 'expiring') { $where[] = 'd.expiry_date IS NOT NULL AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)'; }
if ($folderId && $view !== 'trash') { $where[] = 'd.folder_id IN (' . implode(',', array_map('intval', lib_descendants($conn, $folderId))) . ')'; }
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(d.title LIKE ? OR d.reference_no LIKE ? OR d.description LIKE ? OR u.names LIKE ? OR f.name LIKE ?)';
    $types .= 'sssss'; array_push($params, $like, $like, $like, $like, $like);
}
foreach ([['from', 'd.created_at >= ?', ''], ['to', 'd.created_at < DATE_ADD(?, INTERVAL 1 DAY)', ''], ['issued_from', 'd.issue_date >= ?', ''], ['issued_to', 'd.issue_date <= ?', ''], ['expires_before', 'd.expiry_date <= ?', '']] as [$k, $cond]) {
    $v = (string) ($_GET[$k] ?? '');
    if ($v !== '' && DateTime::createFromFormat('Y-m-d', $v)) { $where[] = $cond; $types .= 's'; $params[] = $v; }
}
if (($_GET['uploader'] ?? '') !== '') { $where[] = 'd.uploaded_by = ?'; $types .= 'i'; $params[] = (int) $_GET['uploader']; }
$order = $view === 'expiring' ? 'd.expiry_date ASC' : ($view === 'trash' ? 'd.deleted_at DESC' : 'd.created_at DESC');
$sql = 'SELECT d.*, u.names AS uploader, f.name AS folder_name,
        (SELECT v.size_bytes FROM lib_versions v WHERE v.document_id = d.id AND v.version_no = d.current_version) AS size_bytes,
        (SELECT v.original_name FROM lib_versions v WHERE v.document_id = d.id AND v.version_no = d.current_version) AS file_name
    FROM lib_documents d LEFT JOIN users u ON u.id = d.uploaded_by LEFT JOIN lib_folders f ON f.id = d.folder_id
    WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ' LIMIT ' . ($view === 'recent' ? 30 : 300);
$s = mysqli_prepare($conn, $sql);
if ($types !== '') { mysqli_stmt_bind_param($s, $types, ...$params); }
mysqli_stmt_execute($s);
$docs = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
$counts = mysqli_fetch_assoc(mysqli_query($conn, "SELECT
    SUM(d.deleted_at IS NULL AND d.status = 'Pending Review') AS pending,
    SUM(d.deleted_at IS NULL AND d.status <> 'Archived' AND d.expiry_date IS NOT NULL AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)) AS expiring
    FROM lib_documents d WHERE d.folder_id IN ($in)"));
$uploaders = mysqli_fetch_all(mysqli_query($conn, 'SELECT DISTINCT u.id, u.names FROM lib_documents d JOIN users u ON u.id = d.uploaded_by ORDER BY u.names'), MYSQLI_ASSOC);

$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
$size = function ($b) { $b = (int) $b; return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB'; };
$badge = function (array $d) {
    if ($d['status'] === 'Pending Review') { return '<span class="badge bg-warning">Pending review</span>'; }
    if ($d['status'] === 'Archived') { return '<span class="badge bg-secondary">Archived</span>'; }
    if ($d['expiry_date'] && $d['expiry_date'] < date('Y-m-d')) { return '<span class="badge bg-danger">Expired</span>'; }
    return '<span class="badge bg-success">Active</span>';
};
$tree = function ($parent, $depth) use (&$tree, $folders, $allowed, $folderId, $view, $e) {
    foreach ($folders as $id => $f) {
        if ((int) $f['parent_id'] !== (int) $parent || !in_array($id, $allowed, true)) { continue; }
        echo '<a href="index.php?f=' . $id . ($view !== 'trash' ? '&view=' . $e($view) : '') . '" class="d-block py-1 text-decoration-none small' . ($folderId === $id ? ' fw-bold' : ' text-body') . '" style="padding-left:' . (8 + 14 * $depth) . 'px;">'
            . '<i class="bi bi-folder' . ($folderId === $id ? '2-open' : '') . ' me-1" style="color:#1E2FE0;"></i>' . $e($f['name'])
            . ((int) $f['is_restricted'] ? ' <i class="bi bi-lock-fill text-muted" title="Restricted"></i>' : '') . '</a>';
        $tree($id, $depth + 1);
    }
};
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success alert-dismissible fade show"><?= $e($_GET['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<?php if (isset($_GET['error'])) { ?><div class="alert alert-danger alert-dismissible fade show"><?= $e($_GET['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h2 class="mb-0">RM Digital Library</h2>
    <div class="d-flex flex-wrap gap-2">
        <?php if (lib_can('manage')) { ?><a href="folders.php" class="rm-btn rm-btn-secondary rm-btn-sm"><i class="bi bi-folder-plus me-1"></i>Folders</a><?php } ?>
        <?php if (lib_can('create')) { ?><a href="upload.php<?= $folderId ? '?f=' . $folderId : ''; ?>" class="rm-btn rm-btn-primary rm-btn-sm"><i class="bi bi-upload me-1"></i>Upload</a><?php } ?>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-3">
        <div class="card border-0 shadow-sm mb-3"><div class="card-body py-2">
            <?php foreach ($views as $k => $l) { $c = $k === 'pending' ? (int) $counts['pending'] : ($k === 'expiring' ? (int) $counts['expiring'] : 0); ?>
            <a href="index.php?view=<?= $k; ?>" class="d-flex justify-content-between py-1 text-decoration-none small <?= $view === $k && !$folderId ? 'fw-bold' : 'text-body'; ?>">
                <span><?= $e($l); ?></span><?php if ($c) { ?><span class="badge bg-primary"><?= $c; ?></span><?php } ?></a>
            <?php } ?>
        </div></div>
        <div class="card border-0 shadow-sm"><div class="card-body py-2" style="max-height:60vh; overflow-y:auto;">
            <div class="small text-muted fw-semibold mb-1">Folders</div>
            <?php $tree(0, 0); ?>
        </div></div>
    </div>
    <div class="col-lg-9">
        <form method="GET" class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 align-items-end">
            <input type="hidden" name="view" value="<?= $e($view); ?>"><?php if ($folderId) { ?><input type="hidden" name="f" value="<?= $folderId; ?>"><?php } ?>
            <div class="col-md-5"><label class="form-label small text-muted mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm" value="<?= $e($q); ?>" placeholder="Title, reference no., description, folder, uploader"></div>
            <div class="col-md-3"><label class="form-label small text-muted mb-1">Uploaded by</label>
                <select name="uploader" class="form-select form-select-sm"><option value="">Anyone</option>
                <?php foreach ($uploaders as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= (string) ($_GET['uploader'] ?? '') === (string) $u['id'] ? 'selected' : ''; ?>><?= $e($u['names']); ?></option><?php } ?></select></div>
            <div class="col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Search</button></div>
            <div class="col-md-2"><a href="index.php" class="btn btn-light btn-sm w-100">Clear</a></div>
            <details class="col-12 small"><summary>More filters (dates)</summary><div class="row g-2 mt-1">
                <?php foreach (['from' => 'Uploaded from', 'to' => 'Uploaded to', 'issued_from' => 'Issued from', 'issued_to' => 'Issued to', 'expires_before' => 'Expires before'] as $k => $l) { ?>
                <div class="col-md"><label class="form-label small text-muted mb-1"><?= $l; ?></label><input type="date" name="<?= $k; ?>" class="form-control form-control-sm" value="<?= $e($_GET[$k] ?? ''); ?>"></div>
                <?php } ?></div></details>
        </div></form>
        <div class="small text-muted mb-2"><?= $e($folderId ? lib_folder_path($conn, $folderId) : $views[$view]); ?> · <?= count($docs); ?> document(s)</div>
        <div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-hover bg-white mb-0 align-middle" style="font-size:13px;">
            <tr><th>Document</th><th>Reference</th><th>Folder</th><th>Status</th><th>Expiry</th><th>Uploaded</th><th></th></tr>
            <?php if (!$docs) { ?><tr><td colspan="7" class="text-center text-muted py-4">No documents here.</td></tr><?php } ?>
            <?php foreach ($docs as $d) { ?>
            <tr>
                <td><a href="document.php?id=<?= (int) $d['id']; ?>" class="fw-semibold text-decoration-none"><i class="bi bi-file-earmark-text me-1"></i><?= $e($d['title']); ?></a>
                    <div class="text-muted" style="font-size:11.5px;"><?= $e($d['file_name']); ?> · <?= $size($d['size_bytes']); ?> · v<?= (int) $d['current_version']; ?></div></td>
                <td><?= $e($d['reference_no'] ?: '—'); ?></td>
                <td class="small"><?= $e($d['folder_name']); ?></td>
                <td><?= $view === 'trash' ? '<span class="badge bg-danger">In trash</span>' : $badge($d); ?></td>
                <td class="text-nowrap"><?= $d['expiry_date'] ? date('d M Y', strtotime($d['expiry_date'])) : '—'; ?></td>
                <td class="small text-nowrap"><?= $e($d['uploader'] ?? '—'); ?><div class="text-muted"><?= date('d M Y', strtotime($d['created_at'])); ?></div></td>
                <td><a href="document.php?id=<?= (int) $d['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">Open</a></td>
            </tr>
            <?php } ?>
        </table>
        </div></div></div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>