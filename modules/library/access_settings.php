<?php
/**
 * NEW FILE: modules/library/access_settings.php — CUSTOMER ACCESS SETTINGS of RM Digital Library (Library managers).
 * Library in the Customer Portal on/off, which access settings are offered when publishing, the default, which Learning Centre
 * enrollment statuses count as "enrolled", the default for downloads, and whether instructors publish course materials.
 */
require '../../config/db.php';
require_once '../../includes/library_helpers.php';
require_once '../../includes/lib_access.php';
lib_ensure($conn);
lib_access_ensure($conn);
lib_guard($conn, 'library.manage');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aud = array_values(array_intersect(array_keys(LIB_AUDIENCES), (array) ($_POST['audiences'] ?? [])));
    if (!in_array('staff', $aud, true)) { array_unshift($aud, 'staff'); }
    $def = in_array($_POST['default_audience'] ?? '', $aud, true) ? $_POST['default_audience'] : 'staff';
    $st = array_values(array_intersect(LIB_ENROLL_STATUSES, (array) ($_POST['statuses'] ?? []))) ?: ['Registered'];
    $before = lib_aud_settings($conn);
    $new = ['portal_enabled' => !empty($_POST['portal_enabled']) ? '1' : '0', 'audiences' => implode(',', $aud), 'default_audience' => $def,
            'enrolled_statuses' => implode(',', $st), 'download_default' => !empty($_POST['download_default']) ? '1' : '0', 'instructors_publish' => !empty($_POST['instructors_publish']) ? '1' : '0'];
    $changes = [];
    foreach ($new as $k => $v) { if ((string) $before[$k] !== $v) { lib_aud_setting_save($conn, $k, $v); $changes[] = $k . ': ' . $before[$k] . ' → ' . $v; } }
    if ($changes) { lib_access_log($conn, null, null, (int) current_user_id(), null, 'access_settings', implode('; ', $changes)); }
    header('Location: access_settings.php?success=' . urlencode($changes ? 'Settings saved.' : 'Nothing changed.')); exit;
}
$set = lib_aud_settings($conn);
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
$counts = [];
foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT audience, COUNT(*) n FROM lib_documents WHERE deleted_at IS NULL GROUP BY audience'), MYSQLI_ASSOC) as $r) { $counts[$r['audience']] = (int) $r['n']; }
$log = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, u.names FROM lib_activity a LEFT JOIN users u ON u.id = a.user_id WHERE a.action IN ('access_settings', 'access_changed') ORDER BY a.id DESC LIMIT 25"), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success py-2"><?= $e($_GET['success']); ?></div><?php } ?>
<a href="index.php" class="small text-muted text-decoration-none"><i class="bi bi-arrow-left"></i> RM Digital Library</a>
<h3 class="mt-1 mb-3">Customer &amp; student access</h3>
<div class="row g-3">
<div class="col-lg-7"><form method="POST" class="card border-0 shadow-sm"><div class="card-body" style="font-size:13.5px;">
    <label class="d-block mb-3"><input type="checkbox" class="form-check-input me-1" name="portal_enabled" value="1" <?= $set['portal_enabled'] === '1' ? 'checked' : ''; ?>> <strong>Show RM Digital Library in the Customer Portal</strong>
        <div class="small text-muted ms-4">Customers and students see only the documents opened to them. Off: no customer sees any library document.</div></label>
    <div class="fw-semibold mb-1">Access settings offered when publishing a document</div>
    <table class="table table-sm align-middle mb-3"><tr class="table-light"><th>Offered</th><th>Setting</th><th>Meaning</th><th class="text-end">Documents</th><th class="text-center">Default</th></tr>
    <?php foreach (LIB_AUDIENCES as $k => $l) { ?><tr>
        <td><input type="checkbox" class="form-check-input" name="audiences[]" value="<?= $k; ?>" <?= in_array($k, $set['audiences_list'], true) ? 'checked' : ''; ?> <?= $k === 'staff' ? 'checked disabled' : ''; ?>></td>
        <td class="fw-semibold"><?= $e($l); ?></td><td class="small text-muted"><?= $e(LIB_AUD_HELP[$k]); ?></td><td class="text-end"><?= (int) ($counts[$k] ?? 0); ?></td>
        <td class="text-center"><input type="radio" class="form-check-input" name="default_audience" value="<?= $k; ?>" <?= $set['default_audience'] === $k ? 'checked' : ''; ?>></td></tr><?php } ?>
    </table>
    <div class="small text-muted mb-3">Turning a setting off hides the documents that use it from customers at once (they are not changed, and come back if you turn it on again).</div>
    <div class="fw-semibold mb-1">Who counts as an "enrolled student"</div>
    <div class="mb-1"><?php foreach (LIB_ENROLL_STATUSES as $s) { ?><label class="me-3"><input type="checkbox" class="form-check-input me-1" name="statuses[]" value="<?= $s; ?>" <?= in_array($s, $set['statuses_list'], true) ? 'checked' : ''; ?>><?= $e($s); ?></label><?php } ?></div>
    <div class="small text-muted mb-3">Learning Centre enrollment statuses that give access to "Enrolled students" and "Selected courses" documents (e.g. keep past students' access by ticking Completed).</div>
    <label class="d-block mb-2"><input type="checkbox" class="form-check-input me-1" name="download_default" value="1" <?= $set['download_default'] === '1' ? 'checked' : ''; ?>> New documents opened to customers may be <strong>downloaded</strong> (can be changed per document)</label>
    <label class="d-block mb-3"><input type="checkbox" class="form-check-input me-1" name="instructors_publish" value="1" <?= $set['instructors_publish'] === '1' ? 'checked' : ''; ?>> <strong>Instructors</strong> publish materials and assignments to the students of their own classes (RM Learning Centre → Course materials)</label>
    <div class="alert alert-light border small mb-3">There is no request-and-approval step for customers: what you open to them is available at once. Documents still need to be <strong>Active</strong> (approved), not archived and not expired.</div>
    <button class="btn btn-primary btn-sm">Save</button>
</div></form></div>
<div class="col-lg-5"><div class="card border-0 shadow-sm"><div class="card-body small"><div class="fw-semibold mb-2">Recent access changes</div>
    <?php foreach ($log as $a) { ?><div class="border-bottom py-1"><span class="text-muted"><?= $e(substr($a['created_at'], 0, 16)); ?></span> · <strong><?= $e($a['names'] ?? '—'); ?></strong>
        <?= $a['document_id'] ? '<a href="document.php?id=' . (int) $a['document_id'] . '">document #' . (int) $a['document_id'] . '</a>' : 'settings'; ?> · <?= $e($a['details']); ?></div><?php } ?>
    <?php if (!$log) { ?><div class="text-muted">No change yet.</div><?php } ?></div></div></div>
</div>
<?php include '../../includes/footer.php'; ?>