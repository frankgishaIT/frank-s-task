<?php
/**
 * NEW FILE: modules/marketing/team.php — TEAM PERFORMANCE: each person's verified results for a period and their
 * performance points (weights in Settings, shown here). Everyone sees their own line; Managers / Admins the team.
 */
require __DIR__ . '/mkt_common.php';
mkt_ensure_team($conn);
$from = DateTime::createFromFormat('Y-m-d', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
$to = DateTime::createFromFormat('Y-m-d', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$perf = mkt_team_performance($conn, $from, $to); $w = mkt_score_weights($conn);
if (!mkt_is_manager()) { $perf = array_intersect_key($perf, [$me => 1]); }
uasort($perf, function ($a, $b) { return $b['points'] <=> $a['points']; });
$names = array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names FROM users'), MYSQLI_ASSOC), 'names', 'id');
$cols = ['lead' => 'Leads', 'followup' => 'Follow-ups', 'qualified' => 'Qualified', 'conversion' => 'New customers', 'paying' => 'Paying customers', 'activity_on_time' => 'Activities on time', 'activity_late' => 'Activities late'];
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('team.php');
?>
<form class="d-flex flex-wrap gap-2 mb-2 align-items-end"><div><label class="small text-muted">From</label><input type="date" name="from" value="<?= $e($from); ?>" class="form-control form-control-sm"></div>
    <div><label class="small text-muted">To</label><input type="date" name="to" value="<?= $e($to); ?>" class="form-control form-control-sm"></div><button class="btn btn-sm btn-primary">Show</button></form>
<div class="small text-muted mb-2"><strong>Points:</strong> <?= $e(implode(' · ', array_map(function ($k) use ($w, $cols) { return ($cols[$k] ?? $k) . ' ' . $w[$k]; }, array_keys($cols)))); ?>. The average review quality adjusts the total (3/5 neutral, 5/5 = +20%, 1/5 = −20%). Only verified records count.<?= mkt_is_manager() ? '' : ' You see your own results.'; ?></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Team member</th><?php foreach ($cols as $l) { ?><th class="text-end"><?= $l; ?></th><?php } ?><th class="text-end">Quality</th><th class="text-end">Points</th></tr>
    <?php if (!$perf) { ?><tr><td colspan="<?= count($cols) + 3; ?>" class="text-center text-muted py-4">No marketing results in this period.</td></tr><?php } ?>
    <?php foreach ($perf as $u => $r) { ?><tr class="<?= $u === $me ? 'table-primary' : ''; ?>"><td class="ps-3"><?= $e($names[$u] ?? '#' . $u); ?></td><?php foreach (array_keys($cols) as $k) { ?><td class="text-end"><?= (int) ($r[$k] ?? 0); ?></td><?php } ?>
        <td class="text-end"><?= isset($r['quality']) ? $r['quality'] . '/5' : '—'; ?></td><td class="text-end fw-bold"><?= $r['points']; ?></td></tr><?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>