<?php
// NEW FILE: modules/kpi/settings.php — the performance rating levels (name and minimum score).
require __DIR__ . '/kpi_common.php';
kpi_guard($conn, 'kpi.manage');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rows = [];
    foreach ((array) ($_POST['name'] ?? []) as $i => $n) {
        $n = mb_substr(trim((string) $n), 0, 40); $min = (float) ($_POST['min'][$i] ?? 0);
        if ($n !== '') { $rows[] = [$n, max(0, min(200, $min))]; }
    }
    if (!$rows || min(array_column($rows, 1)) > 0) { header('Location: settings.php?error=' . urlencode('Keep at least one level, and one level must start at 0.')); exit; }
    mysqli_query($conn, 'DELETE FROM kpi_ratings');
    $s = mysqli_prepare($conn, 'INSERT INTO kpi_ratings (name, min_score) VALUES (?, ?)');
    foreach ($rows as [$n, $min]) { mysqli_stmt_bind_param($s, 'sd', $n, $min); mysqli_stmt_execute($s); }
    header('Location: settings.php?success=' . urlencode('Rating levels saved.')); exit;
}
$levels = kpi_ratings($conn);
include '../../includes/header.php'; include '../../includes/sidebar.php';
kpi_tabs('settings.php');
?>
<form method="POST" class="card border-0 shadow-sm" style="max-width:560px;"><div class="card-body">
    <p class="small text-muted">A score gets the highest level whose minimum it reaches. Scores are achievement percentages (up to 120%).</p>
    <?php foreach (array_merge($levels, [['name' => '', 'min_score' => '']]) as $l) { ?>
    <div class="d-flex gap-2 mb-2"><input name="name[]" class="form-control form-control-sm" maxlength="40" value="<?= $e($l['name']); ?>" placeholder="Level name"><input type="number" step="0.1" name="min[]" class="form-control form-control-sm" style="max-width:140px;" value="<?= $e($l['min_score']); ?>" placeholder="From %"></div>
    <?php } ?>
    <button class="btn btn-primary btn-sm">Save levels</button>
</div></form>
<?php include '../../includes/footer.php'; ?>