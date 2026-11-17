<?php
/** NEW FILE: modules/learning/applications.php — all applications (public website + Customer Portal: one system), filters, CSV. */
require __DIR__ . '/common.php';
lcs_require($conn, 'admissions');
$f = ['status' => (string) ($_GET['status'] ?? 'active'), 'course' => (int) ($_GET['course'] ?? 0), 'intake' => (int) ($_GET['intake'] ?? 0), 'branch' => (int) ($_GET['branch'] ?? 0),
      'source' => (string) ($_GET['source'] ?? ''), 'q' => trim((string) ($_GET['q'] ?? ''))];
$w = [lcs_branch_sql($conn, 'a.branch_id'), "a.status <> 'Draft'"];
if ($f['status'] === 'active') { $w[] = "a.status IN ('" . implode("','", LC_APP_OPEN) . "') AND a.registered_at IS NULL"; }
elseif ($f['status'] === 'registered') { $w[] = 'a.registered_at IS NOT NULL'; }
elseif (in_array($f['status'], LC_APP_STATUSES, true)) { $w[] = "a.status = '" . mysqli_real_escape_string($conn, $f['status']) . "'"; }
if ($f['course']) { $w[] = 'a.course_id = ' . $f['course']; }
if ($f['intake']) { $w[] = 'a.intake_id = ' . $f['intake']; }
if ($f['branch']) { $w[] = 'a.branch_id = ' . $f['branch']; }
if (isset(LC_SOURCES[$f['source']])) { $w[] = "a.source = '" . $f['source'] . "'"; }
if ($f['q'] !== '') { $e = mysqli_real_escape_string($conn, $f['q']); $k = lc_phone_key($f['q']); $w[] = "(a.ref LIKE '%$e%' OR a.full_name LIKE '%$e%'" . (strlen($k) === 9 ? " OR a.phone_key = '$k'" : '') . ')'; }
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT a.*, c.code, c.title, i.name intake_name FROM lc_applications a JOIN lc_courses c ON c.id = a.course_id LEFT JOIN lc_intakes i ON i.id = a.intake_id
    WHERE ' . implode(' AND ', $w) . ' ORDER BY a.id DESC LIMIT 500'), MYSQLI_ASSOC);
if (($_GET['export'] ?? '') === 'csv') {
    lc_audit($conn, 'export', 'applications', null, $f);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="lc-applications-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Reference', 'Submitted', 'Applicant', 'Telephone', 'Course', 'Intake', 'Branch', 'Mode', 'Source', 'Channel', 'Status', 'Offer', 'Customer ID', 'Registered']);
    foreach ($rows as $r) { fputcsv($o, [$r['ref'], $r['submitted_at'], $r['full_name'], $r['phone'], $r['code'], $r['intake_name'], lc_branch_name($conn, (int) $r['branch_id'] ?: null), $r['mode'], LC_SOURCES[$r['source']] ?? $r['source'], $r['channel'], $r['status'], $r['offer_status'], $r['customer_id'] ? lc_customer_id_label($conn, (int) $r['customer_id']) : '', $r['registered_at']]); }
    fclose($o); exit;
}
$courses = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, code, title FROM lc_courses ORDER BY title'), MYSQLI_ASSOC);
$counts = []; foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT status, COUNT(*) n FROM lc_applications a WHERE " . lcs_branch_sql($conn, 'a.branch_id') . " AND registered_at IS NULL GROUP BY status"), MYSQLI_ASSOC) as $r) { $counts[$r['status']] = (int) $r['n']; }
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
$qs = $_GET; unset($qs['export']);
lcs_tabs($conn, 'applications.php', 'Applications', '<a href="?' . lc_e(http_build_query($qs + ['export' => 'csv'])) . '" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>');
?>
<div class="d-flex flex-wrap gap-1 mb-2 small"><?php foreach (['Submitted', 'Under Review', 'Additional Information Required', 'Application Fee Pending', 'Accepted', 'Waitlisted'] as $s) { ?><a href="?status=<?= urlencode($s); ?>" class="badge text-decoration-none bg-light text-dark border"><?= $s; ?>: <?= $counts[$s] ?? 0; ?></a><?php } ?></div>
<form class="row g-2 mb-3">
    <div class="col-md-3"><input name="q" value="<?= lc_e($f['q']); ?>" class="form-control form-control-sm" placeholder="Reference, name or telephone"></div>
    <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="active" <?= $f['status'] === 'active' ? 'selected' : ''; ?>>To process</option><option value="registered" <?= $f['status'] === 'registered' ? 'selected' : ''; ?>>Registered students</option><option value="all" <?= $f['status'] === 'all' ? 'selected' : ''; ?>>All</option>
        <?php foreach (LC_APP_STATUSES as $s) { if ($s === 'Draft') { continue; } ?><option <?= $f['status'] === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select></div>
    <div class="col-md-3"><select name="course" class="form-select form-select-sm"><option value="0">All courses</option><?php foreach ($courses as $c) { ?><option value="<?= (int) $c['id']; ?>" <?= $f['course'] === (int) $c['id'] ? 'selected' : ''; ?>><?= lc_e($c['code'] . ' · ' . $c['title']); ?></option><?php } ?></select></div>
    <?php if (br_enabled($conn)) { ?><div class="col-md-2"><select name="branch" class="form-select form-select-sm"><option value="0">All branches</option><?php foreach (lc_training_branches($conn) as $b) { if (lcs_branch_ok($conn, $b['id'])) { ?><option value="<?= (int) $b['id']; ?>" <?= $f['branch'] === (int) $b['id'] ? 'selected' : ''; ?>><?= lc_e($b['name']); ?></option><?php } } ?></select></div><?php } ?>
    <div class="col-md-1"><select name="source" class="form-select form-select-sm"><option value="">Source</option><?php foreach (LC_SOURCES as $k => $l) { ?><option value="<?= $k; ?>" <?= $f['source'] === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
    <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
</form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:13px;">
    <tr class="table-light"><th class="ps-3">Reference</th><th>Applicant</th><th>Course · intake</th><th>Branch</th><th>Source</th><th>Submitted</th><th>Status</th><th>Customer</th></tr>
    <?php foreach ($rows as $r) { ?><tr style="cursor:pointer" onclick="location.href='application.php?id=<?= (int) $r['id']; ?>'">
        <td class="ps-3"><a href="application.php?id=<?= (int) $r['id']; ?>" class="fw-semibold"><?= lc_e($r['ref']); ?></a></td><td><?= lc_e($r['full_name']); ?><div class="small text-muted"><?= lc_e($r['phone']); ?></div></td>
        <td><?= lc_e($r['code']); ?> <span class="small text-muted"><?= lc_e($r['intake_name']); ?></span></td><td class="small"><?= lc_e(lc_branch_name($conn, (int) $r['branch_id'] ?: null)); ?></td>
        <td class="small"><?= lc_e(LC_SOURCES[$r['source']] ?? $r['source']); ?><?= $r['channel'] === 'portal' ? ' <i class="bi bi-person-check" title="Customer Portal"></i>' : ''; ?></td><td class="small"><?= lc_e(substr((string) $r['submitted_at'], 0, 16)); ?></td>
        <td><?= $r['registered_at'] ? lcs_badge('Registered') : lcs_badge($r['status']); ?><?= $r['status'] === 'Accepted' && !$r['registered_at'] ? ' <span class="small text-muted">offer: ' . lc_e($r['offer_status'] ?: 'pending') . '</span>' : ''; ?></td>
        <td class="small"><?= $r['customer_id'] ? lc_e(lc_customer_id_label($conn, (int) $r['customer_id'])) : '<span class="text-muted">guest</span>'; ?></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="8" class="text-center text-muted py-4">No application.</td></tr><?php } ?>
</table></div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>