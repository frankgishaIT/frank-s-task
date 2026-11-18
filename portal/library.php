<?php
/**
 * NEW FILE: portal/library.php — RM DIGITAL LIBRARY in the Customer Portal.
 * Shows only the documents RISE MOTIVE opened to this customer (All customers, Enrolled students, their courses / classes,
 * or chosen for them). No access requests: what is published for them is here at once. Search, course and type filters.
 */
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/lib_access.php';
if (empty($GLOBALS['PORTAL_LIBRARY_ON'])) { header('Location: index.php'); exit; }
lib_access_ensure($conn);
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$course = (int) ($_GET['course'] ?? 0);
$kind = (string) ($_GET['kind'] ?? '');
$docs = lib_customer_documents($conn, $cid, $q, $course, $kind);
$myCourses = [];
foreach (lib_customer_enrollments($conn, $cid) as [$c]) { $myCourses[$c] = true; }
$courses = $myCourses ? mysqli_fetch_all(mysqli_query($conn, 'SELECT id, code, title FROM lc_courses WHERE id IN (' . implode(',', array_keys($myCourses)) . ') ORDER BY code'), MYSQLI_ASSOC) : [];
$codes = [];
if ($docs) { foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT x.document_id, c.code FROM lib_doc_courses x JOIN lc_courses c ON c.id = x.course_id WHERE x.document_id IN (' . implode(',', array_map('intval', array_column($docs, 'id'))) . ')'), MYSQLI_ASSOC) as $r) { $codes[(int) $r['document_id']][$r['code']] = true; } }
$due = array_values(array_filter($docs, function ($d) { return $d['kind'] === 'assignment' && $d['due_date'] && $d['due_date'] >= date('Y-m-d'); }));
usort($due, function ($a, $b) { return strcmp($a['due_date'], $b['due_date']); });
$icon = function (string $name): string {
    $x = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return ['pdf' => 'file-earmark-pdf', 'doc' => 'file-earmark-word', 'docx' => 'file-earmark-word', 'xls' => 'file-earmark-excel', 'xlsx' => 'file-earmark-excel', 'csv' => 'file-earmark-spreadsheet',
            'ppt' => 'file-earmark-slides', 'pptx' => 'file-earmark-slides', 'jpg' => 'file-earmark-image', 'jpeg' => 'file-earmark-image', 'png' => 'file-earmark-image', 'webp' => 'file-earmark-image'][$x] ?? 'file-earmark-text';
};
portal_log($conn, $cid, 'library_opened', $q !== '' ? 'search: ' . $q : '');
portal_header('RM Digital Library', $me);
?>
<style>
    .lb-item { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-bottom: 1px solid #EEF1F7; }
    .lb-item:last-child { border-bottom: 0; }
    .lb-ico { width: 40px; height: 40px; border-radius: 10px; background: var(--rm-soft); color: var(--rm-blue); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
    .lb-t { font-weight: 700; color: var(--rm-ink); text-decoration: none; } .lb-t:hover { color: var(--rm-blue); }
    .lb-meta { font-size: var(--fs-small); color: #6B7280; }
    .lb-chip { display: inline-block; font-size: .72rem; padding: 1px 8px; border-radius: 20px; background: var(--rm-soft); color: var(--rm-deep); font-weight: 700; margin-right: 4px; }
    @media (max-width: 575.98px) { .lb-actions .btn { padding: .2rem .55rem; font-size: .78rem; } .lb-item { padding: 10px; gap: 10px; } .lb-ico { width: 34px; height: 34px; font-size: 1rem; } }
</style>
<h1 class="p-title">RM Digital Library</h1>
<form class="card mb-3"><div class="card-body py-2"><div class="row g-2 align-items-center">
    <div class="col-12 col-md-6"><input type="search" name="q" value="<?= portal_e($q); ?>" class="form-control" placeholder="Search documents…" aria-label="Search"></div>
    <?php if ($courses) { ?><div class="col-6 col-md-3"><select name="course" class="form-select" onchange="this.form.submit()" aria-label="Course"><option value="0">All courses</option><?php foreach ($courses as $c) { ?><option value="<?= (int) $c['id']; ?>" <?= $course === (int) $c['id'] ? 'selected' : ''; ?>><?= portal_e($c['code']); ?></option><?php } ?></select></div><?php } ?>
    <div class="col-6 col-md-3"><select name="kind" class="form-select" onchange="this.form.submit()" aria-label="Type"><option value="">All types</option><?php foreach (LIB_KINDS as $k => $l) { ?><option value="<?= $k; ?>" <?= $kind === $k ? 'selected' : ''; ?>><?= portal_e($l); ?></option><?php } ?></select></div>
</div></div></form>
<?php if ($due && $q === '' && $kind === '') { ?>
<div class="card mb-3" style="border-left:4px solid #E8A33D;"><div class="card-body py-2"><div class="fw-bold mb-1"><i class="bi bi-calendar-check me-1"></i>Assignments due</div>
    <?php foreach (array_slice($due, 0, 5) as $d) { ?><div class="small"><a href="library_doc.php?id=<?= (int) $d['id']; ?>"><?= portal_e($d['title']); ?></a> · due <strong><?= date('d M Y', strtotime($d['due_date'])); ?></strong></div><?php } ?>
</div></div>
<?php } ?>
<div class="card"><div class="card-body p-0">
    <?php foreach ($docs as $d) { $view = in_array(strtolower(pathinfo((string) $d['original_name'], PATHINFO_EXTENSION)), LIB_VIEWABLE, true); ?>
    <div class="lb-item">
        <div class="lb-ico"><i class="bi bi-<?= $icon((string) $d['original_name']); ?>"></i></div>
        <div class="flex-grow-1" style="min-width:0;">
            <a class="lb-t" href="library_doc.php?id=<?= (int) $d['id']; ?>"><?= portal_e($d['title']); ?></a>
            <div class="mt-1"><?php if ($d['kind']) { ?><span class="lb-chip"><?= portal_e(LIB_KINDS[$d['kind']] ?? ''); ?></span><?php } ?><?php foreach (array_keys($codes[(int) $d['id']] ?? []) as $cc) { ?><span class="lb-chip"><?= portal_e($cc); ?></span><?php } ?></div>
            <div class="lb-meta mt-1"><?= date('d M Y', strtotime($d['published_at'] ?: $d['created_at'])); ?> · <?= strtoupper(portal_e(pathinfo((string) $d['original_name'], PATHINFO_EXTENSION))); ?> · <?= lib_size((int) $d['size_bytes']); ?><?= $d['due_date'] ? ' · due ' . date('d M Y', strtotime($d['due_date'])) : ''; ?></div>
        </div>
        <div class="lb-actions d-flex gap-1 flex-shrink-0">
            <?php if ($view) { ?><a href="library_doc.php?id=<?= (int) $d['id']; ?>" class="btn btn-sm btn-outline-primary">Open</a><?php } ?>
            <?php if ($d['allow_download']) { ?><a href="library_file.php?id=<?= (int) $d['id']; ?>&amp;mode=download" class="btn btn-sm btn-primary" aria-label="Download"><i class="bi bi-download"></i><span class="d-none d-sm-inline"> Download</span></a><?php } ?>
        </div>
    </div>
    <?php } ?>
    <?php if (!$docs) { ?><div class="text-center text-muted py-5 px-3"><i class="bi bi-journal-bookmark d-block mb-2" style="font-size:2rem;"></i><?= $q !== '' || $course || $kind !== '' ? 'No document matches your search.' : 'No document has been published for you yet. Course materials appear here as soon as your instructors publish them.'; ?></div><?php } ?>
</div></div>
<?php portal_footer();