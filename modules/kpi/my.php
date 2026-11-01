<?php
// NEW FILE: modules/kpi/my.php — My Performance: the employee's own KPIs (own, team, department), score, reviews, plans, recognitions.
require __DIR__ . '/kpi_common.php';
$dep = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT department_id FROM users WHERE id = ' . $me))[0] ?? 0);
$teams = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT team_id FROM kpi_team_members WHERE user_id = ' . $me), MYSQLI_ASSOC), 'team_id'));
$mine = array_values(array_filter(kpi_list($conn), function ($k) use ($me, $dep, $teams) {
    return ($k['level'] === 'employee' && (int) $k['user_id'] === $me) || ($k['level'] === 'team' && in_array((int) $k['team_id'], $teams, true)) || ($k['level'] === 'department' && $dep && (int) $k['department_id'] === $dep); }));
$res = kpi_results($conn, $mine);
$score = kpi_weighted($res);
$reviews = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM kpi_reviews WHERE user_id = $me ORDER BY id DESC LIMIT 20"), MYSQLI_ASSOC);
$pips = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM kpi_pips WHERE user_id = $me ORDER BY id DESC"), MYSQLI_ASSOC);
$awards = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM kpi_recognitions WHERE user_id = $me ORDER BY id DESC"), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
kpi_tabs('my.php');
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted">My score now</div><div class="fs-4 fw-bold"><?= $score !== null ? $score . '%' : '—'; ?></div><div class="small"><?= $e(kpi_rating($conn, $score) ?? 'No data yet'); ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted">My KPIs</div><div class="fs-4 fw-bold"><?= count($res); ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Open reviews</div><div class="fs-4 fw-bold"><?= count(array_filter($reviews, function ($r) { return $r['status'] !== 'Completed'; })); ?></div><a href="reviews.php" class="small">Open</a></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Recognitions</div><div class="fs-4 fw-bold"><?= count($awards); ?></div></div></div></div>
</div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">KPI</th><th>Level</th><th>Period</th><th class="text-end">Target</th><th class="text-end">Actual</th><th style="width:25%;">Achievement</th><th></th></tr>
    <?php if (!$res) { ?><tr><td colspan="7" class="text-center text-muted py-4">No KPIs assigned to you yet.</td></tr><?php } ?>
    <?php foreach ($res as $r) { ?><tr><td class="ps-3"><?= $e($r['name']); ?></td><td class="small"><?= $e(KPI_LEVELS[$r['level']] . ($r['level'] !== 'employee' ? ': ' . kpi_scope_label($r) : '')); ?></td><td class="small"><?= $e($r['period_label']); ?></td>
        <td class="text-end"><?= $r['direction'] === 'range' ? $e(kpi_fmt((float) $r['range_min'], $r['unit']) . ' – ' . kpi_fmt((float) $r['range_max'], $r['unit'])) : $e(kpi_fmt((float) $r['target'], $r['unit'])); ?></td>
        <td class="text-end fw-semibold"><?= $e(kpi_fmt($r['actual'], $r['unit'])); ?></td><td><?= kpi_bar($r['achievement']); ?></td>
        <td><a href="kpi.php?id=<?= (int) $r['id']; ?>" class="btn btn-sm btn-outline-primary">History &amp; explain</a></td></tr><?php } ?>
</table></div></div></div>
<div class="row g-3">
    <div class="col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body"><h6>My reviews</h6>
        <?php if (!$reviews) { ?><div class="small text-muted">None yet.</div><?php } foreach ($reviews as $r) { ?><div class="small border-top py-1"><?= $e(ucfirst($r['review_type'])); ?> · <?= $e(kpi_period_label($r['period_start'], $r['review_type'])); ?> · <?= $e($r['status']); ?><?= $r['manager_rating'] ? ' · <strong>' . $e($r['manager_rating']) . '</strong>' : ''; ?></div><?php } ?>
        <a href="reviews.php" class="small">Self-assessment and details</a></div></div></div>
    <div class="col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body"><h6>Plans &amp; recognition</h6>
        <?php foreach ($pips as $p) { ?><div class="small border-top py-1">Plan: <strong><?= $e($p['title']); ?></strong> · until <?= date('d M Y', strtotime($p['end_date'])); ?> · <?= $e($p['status']); ?></div><?php } ?>
        <?php foreach ($awards as $w) { ?><div class="small border-top py-1"><i class="bi bi-award text-success"></i> <strong><?= $e($w['title']); ?></strong><?= $w['reward'] ? ' · ' . $e($w['reward']) : ''; ?> · <?= date('d M Y', strtotime($w['created_at'])); ?></div><?php } ?>
        <?php if (!$pips && !$awards) { ?><div class="small text-muted">None.</div><?php } ?></div></div></div>
</div>
<?php include '../../includes/footer.php'; ?>