<?php
// NEW FILE: modules/kpi/kpi.php — one KPI: its history per period, manual values, explanations and comments.
require __DIR__ . '/kpi_common.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$rows = $id ? kpi_list($conn, [], false) : [];
$k = null; foreach ($rows as $r) { if ((int) $r['id'] === (int) $id) { $k = $r; } }
if (!$k || !kpi_can_see($conn, $k)) { header('Location: my.php?error=' . urlencode('That KPI does not exist or is not yours to see.')); exit; }
$canEnter = access_can('kpi.edit', $conn) || (int) $k['manager_id'] === $me;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $period = kpi_period_start((string) ($_POST['period_start'] ?? date('Y-m-d')), $k['frequency']);
    if ($action === 'entry' && $canEnter) {
        $actual = filter_input(INPUT_POST, 'actual', FILTER_VALIDATE_FLOAT);
        if ($actual === false || $actual === null) { header('Location: kpi.php?id=' . $id . '&error=' . urlencode('Enter a number.')); exit; }
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500) ?: null;
        $s = mysqli_prepare($conn, 'INSERT INTO kpi_entries (kpi_id, period_start, actual, note, entered_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE actual = VALUES(actual), note = VALUES(note), entered_by = VALUES(entered_by)');
        mysqli_stmt_bind_param($s, 'isdsi', $id, $period, $actual, $note, $me);
        mysqli_stmt_execute($s);
        header('Location: kpi.php?id=' . $id . '&success=' . urlencode('Value saved for ' . kpi_period_label($period, $k['frequency']) . '.')); exit;
    }
    if ($action === 'comment') {
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body !== '' && mb_strlen($body) <= 2000) {
            $kind = access_can('kpi.edit', $conn) || (int) $k['manager_id'] === $me ? 'manager' : 'explanation';
            $s = mysqli_prepare($conn, 'INSERT INTO kpi_comments (kpi_id, period_start, user_id, kind, body) VALUES (?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'isiss', $id, $period, $me, $kind, $body);
            mysqli_stmt_execute($s);
        }
        header('Location: kpi.php?id=' . $id . '#comments'); exit;
    }
}
$history = [];
foreach (kpi_periods($k, 12) as $p) { $a = kpi_actual($conn, $k, $p); $history[] = ['start' => $p, 'actual' => $a['value'], 'source' => $a['source'], 'ach' => kpi_achievement($k, $a['value'])]; }
$comments = mysqli_fetch_all(mysqli_query($conn, 'SELECT c.*, u.names FROM kpi_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.kpi_id = ' . (int) $id . ' ORDER BY c.id DESC'), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
kpi_tabs('');
?>
<h4 class="mb-1"><?= $e($k['name']); ?></h4>
<div class="small text-muted mb-3"><?= $e(KPI_LEVELS[$k['level']] . ': ' . kpi_scope_label($k)); ?> · <?= $e(KPI_TYPES[$k['measure_type']]); ?> · <?= $e(KPI_DIRECTIONS[$k['direction']]); ?> · <?= $e(KPI_FREQUENCIES[$k['frequency']]); ?> ·
    target <strong><?= $k['direction'] === 'range' ? $e(kpi_fmt((float) $k['range_min'], $k['unit']) . ' – ' . kpi_fmt((float) $k['range_max'], $k['unit'])) : $e(kpi_fmt((float) $k['target'], $k['unit'])); ?></strong> · weight <?= (float) $k['weight']; ?> ·
    <?= $e(KPI_SOURCES[$k['data_source']][0] ?? 'Manual'); ?> · manager <?= $e($k['manager'] ?? '—'); ?> · <?= $e($k['status']); ?></div>
<?php if ($k['description']) { ?><p class="small"><?= nl2br($e($k['description'])); ?></p><?php } ?>
<div class="row g-3">
<div class="col-lg-7"><div class="card border-0 shadow-sm"><div class="card-body p-0"><table class="table table-sm mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Period</th><th class="text-end">Actual</th><th style="width:35%;">Achievement</th><th>Rating</th><th>Data</th></tr>
    <?php foreach ($history as $h) { ?><tr><td class="ps-3"><?= $e(kpi_period_label($h['start'], $k['frequency'])); ?><?= $h['start'] === kpi_period_start(date('Y-m-d'), $k['frequency']) ? ' <span class="badge bg-primary">current</span>' : ''; ?></td>
        <td class="text-end fw-semibold"><?= $e(kpi_fmt($h['actual'], $k['unit'])); ?></td><td><?= kpi_bar($h['ach']); ?></td><td class="small"><?= $e(kpi_rating($conn, $h['ach']) ?? '—'); ?></td>
        <td class="small text-muted"><?= $h['source'] === 'automatic' ? 'automatic' : ($h['source'] === 'manual' ? 'entered' : '—'); ?></td></tr><?php } ?>
    <?php if (!$history) { ?><tr><td colspan="5" class="text-center text-muted py-3">The KPI has not started yet.</td></tr><?php } ?>
</table></div></div></div>
<div class="col-lg-5">
    <?php if ($canEnter && $history) { ?>
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body"><input type="hidden" name="id" value="<?= (int) $id; ?>"><input type="hidden" name="action" value="entry">
        <h6><?= $k['data_source'] === 'manual' ? 'Enter the actual value' : 'Enter a value (used only when no automatic data exists)'; ?></h6>
        <select name="period_start" class="form-select form-select-sm mb-2"><?php foreach ($history as $h) { ?><option value="<?= $h['start']; ?>"><?= $e(kpi_period_label($h['start'], $k['frequency'])); ?></option><?php } ?></select>
        <input type="number" step="0.01" name="actual" class="form-control form-control-sm mb-2" required placeholder="Actual value<?= $k['unit'] ? ' (' . $e($k['unit']) . ')' : ''; ?>">
        <input name="note" class="form-control form-control-sm mb-2" maxlength="500" placeholder="Note (evidence, how it was measured)">
        <button class="btn btn-primary btn-sm">Save value</button></div></form>
    <?php } ?>
    <div class="card border-0 shadow-sm" id="comments"><div class="card-body">
        <h6>Explanations &amp; comments</h6>
        <form method="POST" class="mb-2"><input type="hidden" name="id" value="<?= (int) $id; ?>"><input type="hidden" name="action" value="comment">
            <textarea name="body" class="form-control form-control-sm mb-2" rows="2" maxlength="2000" placeholder="<?= $canEnter ? 'Comment for the employee / team' : 'Explain the result (e.g. why the target was missed)'; ?>"></textarea>
            <button class="btn btn-outline-primary btn-sm">Add</button></form>
        <?php foreach ($comments as $c) { ?><div class="small border-top py-2"><strong><?= $e($c['names'] ?? '—'); ?></strong> <span class="badge <?= $c['kind'] === 'manager' ? 'bg-primary' : 'bg-secondary'; ?>"><?= $c['kind'] === 'manager' ? 'manager' : 'explanation'; ?></span>
            <span class="text-muted"><?= date('d M Y, H:i', strtotime($c['created_at'])); ?></span><div style="white-space:pre-wrap;"><?= $e($c['body']); ?></div></div><?php } ?>
    </div></div>
</div></div>
<?php include '../../includes/footer.php'; ?>