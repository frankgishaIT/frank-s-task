<?php
/** NEW FILE: learn/courses.php — public COURSE CATALOGUE: search and filter by category, duration, branch, training mode and intake. */
require __DIR__ . '/lc_public.php';
lcp_track_source();
$q = trim((string) ($_GET['q'] ?? '')); $cat = (string) ($_GET['category'] ?? ''); $mode = (string) ($_GET['mode'] ?? ''); $br = (int) ($_GET['branch'] ?? 0);
$dur = (string) ($_GET['duration'] ?? ''); $open = !empty($_GET['open']); $month = preg_match('/^\d{4}-\d{2}$/', $_GET['intake'] ?? '') ? $_GET['intake'] : '';
$all = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM lc_courses WHERE status IN ('" . implode("','", LC_PUBLIC_VISIBLE) . "') ORDER BY title"), MYSQLI_ASSOC);
$branches = array_filter(lc_training_branches($conn), function ($b) { return $b['enabled']; });
$weeks = function ($c) { $v = (int) $c['duration_value']; return ['hours' => $v / 40, 'days' => $v / 5, 'weeks' => $v, 'months' => $v * 4.3][$c['duration_unit']] ?? $v; };
$rows = array_filter($all, function ($c) use ($conn, $q, $cat, $mode, $br, $dur, $open, $month, $weeks) {
    if ($q !== '' && stripos($c['title'] . ' ' . $c['summary'] . ' ' . $c['code'], $q) === false) { return false; }
    if ($cat !== '' && $c['category'] !== $cat) { return false; }
    if ($mode !== '' && !in_array($mode, explode(',', (string) $c['modes']), true)) { return false; }
    if ($dur !== '') { $w = $weeks($c); if (($dur === 'short' && $w > 4) || ($dur === 'medium' && ($w <= 4 || $w > 13)) || ($dur === 'long' && $w <= 13)) { return false; } }
    $ints = lc_intakes($conn, (int) $c['id'], $open);
    if ($br && !array_filter($ints, function ($i) use ($br) { return (int) $i['branch_id'] === $br; })) { return false; }
    if ($month !== '' && !array_filter($ints, function ($i) use ($month) { return substr($i['start_date'], 0, 7) === $month; })) { return false; }
    if ($open && !lc_course_accepting($conn, $c)[0]) { return false; }
    return true;
});
$months = array_unique(array_map(function ($r) { return substr($r[0], 0, 7); }, mysqli_fetch_all(mysqli_query($conn, "SELECT start_date FROM lc_intakes WHERE status = 'Open' AND start_date >= '" . date('Y-m-d') . "' ORDER BY start_date"))));
lcp_header($conn, ['title' => 'Courses', 'description' => 'All RM Learning Centre courses: requirements, fees, intakes and online application.', 'url' => lc_public_base($conn) . '/courses.php']);
?>
<div class="container py-4">
    <h1 class="h3 fw-bold" style="color:var(--d)">Courses</h1>
    <form class="card mb-3"><div class="card-body row g-2">
        <div class="col-md-4"><input name="q" value="<?= lc_e($q); ?>" class="form-control" placeholder="Search a course"></div>
        <div class="col-6 col-md-2"><select name="category" class="form-select"><option value="">All categories</option><?php foreach (lc_categories($conn) as $x) { ?><option <?= $cat === $x ? 'selected' : ''; ?>><?= lc_e($x); ?></option><?php } ?></select></div>
        <div class="col-6 col-md-2"><select name="duration" class="form-select"><option value="">Any duration</option><option value="short" <?= $dur === 'short' ? 'selected' : ''; ?>>Up to 1 month</option><option value="medium" <?= $dur === 'medium' ? 'selected' : ''; ?>>1 to 3 months</option><option value="long" <?= $dur === 'long' ? 'selected' : ''; ?>>More than 3 months</option></select></div>
        <div class="col-6 col-md-2"><select name="branch" class="form-select"><option value="0">All branches</option><?php foreach ($branches as $b) { ?><option value="<?= (int) $b['id']; ?>" <?= $br === (int) $b['id'] ? 'selected' : ''; ?>><?= lc_e($b['name']); ?></option><?php } ?></select></div>
        <div class="col-6 col-md-2"><select name="mode" class="form-select"><option value="">Any mode</option><?php foreach (LC_MODES as $x) { ?><option <?= $mode === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
        <div class="col-6 col-md-3"><select name="intake" class="form-select"><option value="">Any intake</option><?php foreach ($months as $m) { ?><option value="<?= $m; ?>" <?= $month === $m ? 'selected' : ''; ?>>Starting <?= date('F Y', strtotime($m . '-01')); ?></option><?php } ?></select></div>
        <div class="col-6 col-md-3 pt-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="open" value="1" id="op" <?= $open ? 'checked' : ''; ?>><label class="form-check-label" for="op">Open for applications only</label></div></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Search</button></div><div class="col-md-2"><a href="courses.php" class="btn btn-light border w-100">Reset</a></div>
    </div></form>
    <div class="small text-muted mb-2"><?= count($rows); ?> course(s)</div>
    <div class="row g-3"><?php foreach ($rows as $c) { lcp_course_card($conn, $c); } ?><?php if (!$rows) { ?><div class="col-12"><div class="card"><div class="card-body text-muted">No course matches. <a href="courses.php">See all courses</a>.</div></div></div><?php } ?></div>
</div>
<?php lcp_footer($conn); ?>