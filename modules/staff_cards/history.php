<?php
/** NEW FILE: modules/staff_cards/history.php — carousel display history: what was shown, when, its priority, how many times. */
require __DIR__ . '/common.php';
sdc_require($conn, 'any');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$cat = isset(SDC_CATS[$_GET['cat'] ?? '']) ? $_GET['cat'] : '';
$w = "shown_at >= '$from 00:00:00' AND shown_at <= '$to 23:59:59'" . ($cat ? " AND category = '$cat'" : '');
$agg = mysqli_fetch_all(mysqli_query($conn, "SELECT card_key, category, MAX(title) title, MAX(priority) priority, COUNT(*) n, COUNT(DISTINCT user_id) people, MIN(shown_at) first_at, MAX(shown_at) last_at FROM sdc_history WHERE $w GROUP BY card_key, category ORDER BY n DESC"), MYSQLI_ASSOC);
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="carousel_history_' . $from . '_' . $to . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Content item', 'Category', 'Display priority', 'Times displayed', 'Staff who saw it', 'First displayed', 'Last displayed']);
    foreach ($agg as $r) { fputcsv($o, [$r['title'], SDC_CATS[$r['category']][0] ?? $r['category'], $r['priority'], $r['n'], $r['people'], $r['first_at'], $r['last_at']]); }
    exit;
}
$recent = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM sdc_history WHERE $w ORDER BY id DESC LIMIT 50"), MYSQLI_ASSOC);
$names = sdc_user_names($conn);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
sdc_tabs($conn, 'history.php', 'Display history');
?>
<form class="d-flex flex-wrap gap-2 align-items-end mb-2">
    <div><label class="form-label small mb-0">From</label><input type="date" name="from" value="<?= sdc_e($from); ?>" class="form-control form-control-sm"></div>
    <div><label class="form-label small mb-0">To</label><input type="date" name="to" value="<?= sdc_e($to); ?>" class="form-control form-control-sm"></div>
    <div><label class="form-label small mb-0">Category</label><select name="cat" class="form-select form-select-sm"><option value="">All</option><?php foreach (SDC_CATS as $k => $v) { ?><option value="<?= $k; ?>" <?= $cat === $k ? 'selected' : ''; ?>><?= sdc_e($v[0]); ?></option><?php } ?></select></div>
    <button class="btn btn-outline-primary btn-sm">Show</button>
    <a class="btn btn-outline-secondary btn-sm" href="?<?= sdc_e(http_build_query(['from' => $from, 'to' => $to, 'cat' => $cat, 'export' => 'csv'])); ?>"><i class="bi bi-download"></i> Excel (CSV)</a>
</form>
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm sdc-tbl mb-0">
<thead><tr><th>Content item</th><th>Category</th><th>Display priority</th><th class="text-end">Times displayed</th><th class="text-end">Staff</th><th>Last displayed</th></tr></thead><tbody>
<?php foreach ($agg as $r) { ?><tr><td><?= sdc_e($r['title']); ?></td><td class="small"><?= sdc_e(SDC_CATS[$r['category']][0] ?? $r['category']); ?></td><td class="small"><?= sdc_e($r['priority']); ?></td><td class="text-end"><?= (int) $r['n']; ?></td><td class="text-end"><?= (int) $r['people']; ?></td><td class="text-nowrap small"><?= sdc_e(date('d M Y H:i', strtotime($r['last_at']))); ?></td></tr><?php } ?>
<?php if (!$agg) { ?><tr><td colspan="6" class="text-center text-muted py-4">Nothing was displayed in this period.</td></tr><?php } ?>
</tbody></table></div></div>
<?php if ($recent) { ?>
<h6>Latest displays</h6>
<div class="card"><div class="table-responsive"><table class="table table-sm sdc-tbl mb-0"><thead><tr><th>Date / time</th><th>Content item</th><th>Category</th><th>Priority</th><th>Seen by</th></tr></thead><tbody>
<?php foreach ($recent as $r) { ?><tr><td class="text-nowrap small"><?= sdc_e(date('d M Y H:i:s', strtotime($r['shown_at']))); ?></td><td><?= sdc_e($r['title']); ?></td><td class="small"><?= sdc_e(SDC_CATS[$r['category']][0] ?? $r['category']); ?></td><td class="small"><?= sdc_e($r['priority']); ?></td><td class="small"><?= sdc_e($names[(int) $r['user_id']] ?? '—'); ?></td></tr><?php } ?>
</tbody></table></div></div>
<?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>