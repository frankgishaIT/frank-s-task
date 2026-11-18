<?php
/**
 * NEW FILE: modules/customers/registrations.php — ORIGINAL REGISTRATION BRANCH report and review.
 *   - New customers registered by each branch in a period, ranking, monthly trend, all-time distribution (charts + PDF).
 *   - Admins: review the customers whose original branch could not be established reliably (registered before branch
 *     tracking), confirm the suggestion or set the right branch, with a reason. Every change is logged.
 * Customers remain shared: every branch can find and serve every customer. This page never limits that.
 */
require '../../config/db.php';
require_once '../../includes/access_control.php';
require_once '../../includes/analytics_ui.php';
require_once '../../includes/customer_registration.php';
if (!in_array(current_user_role(), ['Admin', 'Manager'], true) || !access_can('customers.reports', $conn)) { http_response_code(403); include '../../includes/access_denied.php'; exit; }
cr_ensure($conn);
cr_backfill($conn);
$isAdmin = current_user_role() === 'Admin';
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
if (empty($_SESSION['cr_csrf'])) { $_SESSION['cr_csrf'] = bin2hex(random_bytes(16)); }
$go = function (string $q, string $k, string $m) { header('Location: registrations.php?' . $q . ($q ? '&' : '') . $k . '=' . urlencode($m)); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['export'] ?? '') !== 'pdf') {
    if (!hash_equals($_SESSION['cr_csrf'], (string) ($_POST['csrf'] ?? ''))) { $go('tab=review', 'error', 'The page had expired.'); }
    if (!$isAdmin) { http_response_code(403); include '../../includes/access_denied.php'; exit; }
    $back = !empty($_POST['back']) && preg_match('/^edit\.php\?id=\d+$/', $_POST['back']) ? $_POST['back'] : '';
    try {
        if (($_POST['do'] ?? '') === 'set') {
            cr_set($conn, (int) $_POST['customer_id'], (int) ($_POST['branch_id'] ?? 0) ?: null, ($_POST['date'] ?? '') !== '' ? (string) $_POST['date'] : null, (string) ($_POST['reason'] ?? ''), ($_POST['mode'] ?? '') === 'confirm' ? 'confirm' : 'correct');
            if ($back) { header('Location: ' . $back . '&success=' . urlencode('Original registration branch saved.')); exit; }
            $go('tab=review', 'success', 'Saved.');
        }
        if (($_POST['do'] ?? '') === 'confirm_many') {
            $ids = array_map('intval', (array) ($_POST['ids'] ?? [])); $why = (string) ($_POST['reason'] ?? ''); $n = 0;
            foreach ($ids as $cid) { $r = cr_get($conn, $cid); if ($r && $r['status'] === 'review' && $r['suggested_branch_id']) { cr_set($conn, $cid, (int) $r['suggested_branch_id'], null, $why, 'confirm'); $n++; } }
            $go('tab=review', 'success', $n . ' record(s) confirmed.');
        }
    } catch (RuntimeException $ex) {
        if ($back) { header('Location: ' . $back . '&error=' . urlencode($ex->getMessage())); exit; }
        $go('tab=review', 'error', $ex->getMessage());
    }
}

$tab = ($_GET['tab'] ?? '') === 'review' ? 'review' : 'report';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-01-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$branches = br_enabled($conn) ? br_all($conn) : [];
$bn = function ($id) use ($branches) { return $id === null ? 'Not established (to review)' : ($branches[(int) $id]['name'] ?? 'Branch #' . $id); };
$F = mysqli_real_escape_string($conn, $from); $T = mysqli_real_escape_string($conn, $to);
$per = mysqli_fetch_all(mysqli_query($conn, "SELECT r.branch_id, COUNT(*) n FROM customer_registrations r WHERE r.registered_at BETWEEN '$F' AND '$T 23:59:59' GROUP BY r.branch_id ORDER BY n DESC"), MYSQLI_ASSOC);
$totPer = array_sum(array_column($per, 'n'));
$unknownPer = (int) array_sum(array_map(function ($x) { return $x['branch_id'] === null ? (int) $x['n'] : 0; }, $per));
$per = array_values(array_filter($per, function ($x) { return $x['branch_id'] !== null; }));   // ranking: established branches only
$all = mysqli_fetch_all(mysqli_query($conn, 'SELECT r.branch_id, COUNT(*) n FROM customer_registrations r GROUP BY r.branch_id ORDER BY n DESC'), MYSQLI_ASSOC);
$totAll = array_sum(array_column($all, 'n'));
$review = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM customer_registrations WHERE status = 'review'"))[0];
$months = an_months($from, $to);
$trend = [];
foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT r.branch_id, DATE_FORMAT(r.registered_at, '%Y-%m') m, COUNT(*) n FROM customer_registrations r WHERE r.registered_at BETWEEN '$F' AND '$T 23:59:59' AND r.branch_id IS NOT NULL GROUP BY r.branch_id, m"), MYSQLI_ASSOC) as $r) { $trend[(int) $r['branch_id']][$r['m']] = (int) $r['n']; }

if (($_GET['export'] ?? '') === 'pdf' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rank = []; foreach ($per as $i => $r) { $rank[] = [$i + 1, $bn($r['branch_id'] !== null ? (int) $r['branch_id'] : null), (int) $r['n'], $totPer ? round($r['n'] / $totPer * 100, 1) . '%' : '—']; }
    $dist = []; foreach ($all as $r) { $dist[] = [$bn($r['branch_id'] !== null ? (int) $r['branch_id'] : null), (int) $r['n'], $totAll ? round($r['n'] / $totAll * 100, 1) . '%' : '—']; }
    $tr = []; foreach ($months as $mk => $ml) { $row = [$ml]; foreach ($trend as $b => $v) { $row[] = $v[$mk] ?? 0; } $tr[] = $row; }
    an_render_pdf('New customers by registration branch', [['Period', $from . ' to ' . $to], ['Prepared by', $_SESSION['user_name'] ?? '']],
        [['New customers (period)', $totPer], ['Branches', count(array_filter(array_column($per, 'branch_id'), function ($x) { return $x !== null; }))], ['All customers', $totAll], ['To review', $review]],
        an_posted_charts(), [['title' => 'Ranking: new customers in the period', 'columns' => ['#', 'Branch', 'New customers', 'Share'], 'rows' => $rank],
            ['title' => 'Monthly trend', 'columns' => array_merge(['Month'], array_map(function ($b) use ($bn) { return $bn($b); }, array_keys($trend))), 'rows' => $tr],
            ['title' => 'Original registration branch of all customers', 'columns' => ['Branch', 'Customers', 'Share'], 'rows' => $dist]], 'customer-registration-branches-' . date('Ymd') . '.pdf');
}
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h3 class="mb-0">Customers by registration branch</h3><div class="small text-muted">Where each customer first registered. Every branch can still serve every customer.</div></div>
    <div class="d-flex flex-wrap gap-2"><a href="index.php" class="btn btn-sm btn-outline-secondary">Customers</a><a href="analytics.php" class="btn btn-sm btn-outline-secondary">Analytics</a><?php if ($tab === 'report') { an_pdf_buttons(); } ?></div>
</div>
<?php foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) { if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $c . ' py-2">' . $e($_GET[$k]) . '</div>'; } } ?>
<ul class="nav nav-pills small mb-3"><li class="nav-item"><a class="nav-link py-1 <?= $tab === 'report' ? 'active' : ''; ?>" href="?tab=report">Report</a></li>
    <li class="nav-item"><a class="nav-link py-1 <?= $tab === 'review' ? 'active' : ''; ?>" href="?tab=review">To review <?= $review ? '<span class="badge bg-warning text-dark">' . $review . '</span>' : ''; ?></a></li></ul>
<?php if (!$branches) { ?><div class="alert alert-info small">Branch management is not set up: every customer belongs to the single RISE MOTIVE branch for now.</div><?php } ?>
<?php if ($tab === 'report') { ?>
<form class="row g-2 mb-3 align-items-end"><input type="hidden" name="tab" value="report"><div class="col-auto"><label class="form-label small mb-0">From</label><input type="date" name="from" value="<?= $from; ?>" class="form-control form-control-sm"></div>
    <div class="col-auto"><label class="form-label small mb-0">To</label><input type="date" name="to" value="<?= $to; ?>" class="form-control form-control-sm"></div><div class="col-auto"><button class="btn btn-sm btn-primary">Show</button></div></form>
<div class="row g-2 mb-3">
    <?php foreach (['New customers in the period' => $totPer, 'Top branch' => $per ? $bn((int) $per[0]['branch_id']) : '—', 'All customers' => $totAll, 'Origin to review' => $review] as $l => $v) { ?>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="small text-muted"><?= $l; ?></div><div class="fw-bold" style="font-size:1.1rem"><?= $e($v); ?></div></div></div></div><?php } ?>
</div>
<div class="row g-3 mb-3">
    <div class="col-lg-7"><?php an_chart_card('crTrend', 'New customers per month, by branch', 'Registration date, branch that registered the customer', 260); ?></div>
    <div class="col-lg-5"><?php an_chart_card('crRank', 'Branches attracting new customers (period)', '', 260); ?></div>
</div>
<div class="row g-3">
    <div class="col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="p-2 fw-semibold">Ranking: new customers in the period</div><table class="table table-sm mb-0" style="font-size:13px;">
        <tr class="table-light"><th class="ps-3">#</th><th>Branch</th><th class="text-end">New customers</th><th class="text-end pe-3">Share</th></tr>
        <?php foreach ($per as $i => $r) { ?><tr><td class="ps-3"><?= $i + 1; ?></td><td><?= $e($bn($r['branch_id'] !== null ? (int) $r['branch_id'] : null)); ?></td><td class="text-end"><?= (int) $r['n']; ?></td><td class="text-end pe-3"><?= $totPer ? round($r['n'] / $totPer * 100, 1) : 0; ?>%</td></tr><?php } ?>
        <?php if ($unknownPer) { ?><tr class="text-muted"><td class="ps-3">—</td><td>Not established (to review)</td><td class="text-end"><?= $unknownPer; ?></td><td class="text-end pe-3"><?= $totPer ? round($unknownPer / $totPer * 100, 1) : 0; ?>%</td></tr><?php } ?>
        <?php if (!$per && !$unknownPer) { ?><tr><td colspan="4" class="ps-3 text-muted">No new customer in this period.</td></tr><?php } ?></table></div></div></div>
    <div class="col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="p-2 fw-semibold">Original registration branch of all customers</div><table class="table table-sm mb-0" style="font-size:13px;">
        <tr class="table-light"><th class="ps-3">Branch</th><th class="text-end">Customers</th><th class="text-end pe-3">Share</th></tr>
        <?php foreach ($all as $r) { ?><tr><td class="ps-3"><?= $e($bn($r['branch_id'] !== null ? (int) $r['branch_id'] : null)); ?></td><td class="text-end"><?= (int) $r['n']; ?></td><td class="text-end pe-3"><?= $totAll ? round($r['n'] / $totAll * 100, 1) : 0; ?>%</td></tr><?php } ?></table></div></div></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') { return; }
    var months = <?= json_encode(array_values($months)); ?>, keys = <?= json_encode(array_keys($months)); ?>, trend = <?= json_encode($trend); ?>, names = <?= json_encode(array_map(function ($b) { return $b['name']; }, $branches)); ?>;
    var ds = Object.keys(trend).map(function (b, i) { return { label: names[b] || ('Branch ' + b), data: keys.map(function (k) { return trend[b][k] || 0; }), backgroundColor: rmPalette[i % rmPalette.length], borderColor: rmPalette[i % rmPalette.length] }; });
    new Chart(document.getElementById('crTrend'), { type: 'bar', data: { labels: months, datasets: ds }, options: { maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } } });
    var per = <?= json_encode(array_map(function ($r) use ($bn) { return [$bn($r['branch_id'] !== null ? (int) $r['branch_id'] : null), (int) $r['n']]; }, $per)); ?>;
    new Chart(document.getElementById('crRank'), { type: 'bar', data: { labels: per.map(function (x) { return x[0]; }), datasets: [{ label: 'New customers', data: per.map(function (x) { return x[1]; }), backgroundColor: '#1E2FE0' }] }, options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } } });
});
</script>
<?php } else {
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT r.*, c.name, c.phone, c.created_at FROM customer_registrations r JOIN customers c ON c.id = r.customer_id WHERE r.status = 'review' ORDER BY c.created_at LIMIT 500"), MYSQLI_ASSOC);
    $log = mysqli_fetch_all(mysqli_query($conn, 'SELECT l.*, u.names, c.name cname FROM customer_registration_log l LEFT JOIN users u ON u.id = l.user_id LEFT JOIN customers c ON c.id = l.customer_id ORDER BY l.id DESC LIMIT 50'), MYSQLI_ASSOC);
    ?>
<div class="alert alert-light border small">These customers were registered before branch tracking started, so MY MOTIVE cannot know their branch for sure. The branch setup attached them to the Main Branch: that is shown as a <em>suggestion</em>, not as a fact. <?= $isAdmin ? 'Confirm it (for customers you know came from that branch) or set the right branch for each customer.' : 'Only an Admin can confirm or change them.'; ?></div>
<form method="POST" id="crMany"><input type="hidden" name="csrf" value="<?= $e($_SESSION['cr_csrf']); ?>"><input type="hidden" name="do" value="confirm_many">
<div class="card border-0 shadow-sm mb-2"><div class="card-body p-0"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
    <tr class="table-light"><?php if ($isAdmin) { ?><th class="ps-3"><input type="checkbox" class="form-check-input" onclick="document.querySelectorAll('.crc').forEach(function(c){c.checked=this.checked}.bind(this))"></th><?php } ?><th class="ps-2">Customer</th><th>Created in MY MOTIVE</th><th>Suggestion</th><th>Why to review</th><?= $isAdmin ? '<th>Set branch</th>' : ''; ?></tr>
    <?php foreach ($rows as $r) { ?><tr><?php if ($isAdmin) { ?><td class="ps-3"><input type="checkbox" class="form-check-input crc" name="ids[]" value="<?= (int) $r['customer_id']; ?>" <?= $r['suggested_branch_id'] ? '' : 'disabled'; ?>></td><?php } ?>
        <td class="ps-2"><a href="edit.php?id=<?= (int) $r['customer_id']; ?>"><?= $e($r['name']); ?></a><div class="small text-muted"><?= $e($r['phone']); ?></div></td><td class="small"><?= $e(substr((string) $r['created_at'], 0, 10)); ?></td>
        <td class="small"><?= $e($r['suggested_branch_id'] ? $bn((int) $r['suggested_branch_id']) : '—'); ?></td><td class="small text-muted"><?= $e($r['basis']); ?></td>
        <?php if ($isAdmin) { ?><td><a class="small" href="edit.php?id=<?= (int) $r['customer_id']; ?>#origin">Set…</a></td><?php } ?></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="6" class="ps-3 text-muted py-3">Nothing to review.</td></tr><?php } ?>
</table></div></div>
<?php if ($isAdmin && $rows) { ?><div class="d-flex gap-2 align-items-center mb-3"><input name="reason" class="form-control form-control-sm" style="max-width:420px" placeholder="Reason / source (e.g. checked with the Main Branch register)" required><button class="btn btn-sm btn-primary">Confirm the suggestion for the ticked customers</button></div><?php } ?>
</form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="p-2 fw-semibold">Changes (audit trail)</div><table class="table table-sm mb-0" style="font-size:12.5px;">
    <tr class="table-light"><th class="ps-3">When</th><th>Customer</th><th>Action</th><th>Before</th><th>After</th><th>Reason</th><th>By</th></tr>
    <?php foreach ($log as $l) { ?><tr><td class="ps-3 small"><?= $e($l['created_at']); ?></td><td><?= $e($l['cname']); ?></td><td><?= $e($l['action']); ?></td><td class="small"><?= $e($l['old_value']); ?></td><td class="small"><?= $e($l['new_value']); ?></td><td class="small"><?= $e($l['reason']); ?></td><td class="small"><?= $e($l['names']); ?></td></tr><?php } ?>
    <?php if (!$log) { ?><tr><td colspan="7" class="ps-3 text-muted">No change yet.</td></tr><?php } ?></table></div></div>
<?php }
an_js();
include '../../includes/footer.php';