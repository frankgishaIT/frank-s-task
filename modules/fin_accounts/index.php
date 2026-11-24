<?php
/**
 * NEW FILE: modules/fin_accounts/index.php — FINANCIAL DASHBOARD.
 * A. total company money (sum of the accounts; transfers are never counted twice), B. account balances,
 * C. fund balances, D. fund distribution by account (matrix), E. recent activity (income, expenses, transfers,
 * fees, adjustments, unreconciled), F. any past date ("as at").
 */
require __DIR__ . '/common.php';
fa_require($conn, 'view');
$asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['as_of'] ?? '') && $_GET['as_of'] <= date('Y-m-d') ? $_GET['as_of'] : date('Y-m-d');
$isToday = $asOf === date('Y-m-d');
$accounts = fa_accounts($conn); $funds = fa_funds($conn);
$M = fa_matrix($conn, $asOf); $bal = fa_balances($M); $total = array_sum($bal);
$fundCash = fa_fund_cash_at($conn, $asOf);
$start = fa_tracking_start($conn);
$unassigned = $start ? count(fa_unassigned($conn, 1000)) : 0;
$unrec = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM fa_statement_lines l JOIN fa_statements s ON s.id = l.statement_id WHERE s.status <> 'Completed' AND l.status IN ('Unmatched', 'Investigate')"))[0];
// recent activity (last 30 days up to the date)
$from = date('Y-m-d', strtotime($asOf . ' -30 days')); $recent = [];
foreach ($accounts as $a) { foreach (fa_ledger($conn, (int) $a['id'], $from, $asOf) as $e) { $recent[] = $e + ['account' => $a['name']]; } }
usort($recent, function ($x, $y) { return [$y['date'], $y['key']] <=> [$x['date'], $x['key']]; });
$recent = array_values(array_filter($recent, function ($e) { return strpos($e['key'], ':in') === false; })); // a transfer shows once
$sumBy = function ($type) use ($recent) { return array_sum(array_map(function ($e) { return abs($e['amount']); }, array_filter($recent, function ($e) use ($type) { return $e['type'] === $type && $e['status'] === 'Posted'; }))); };
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
fa_tabs($conn, 'index.php', 'Financial overview', '<form class="d-flex gap-1 align-items-center small"><span class="text-muted">As at</span><input type="date" name="as_of" value="' . fa_e($asOf) . '" max="' . date('Y-m-d') . '" class="form-control form-control-sm" onchange="this.form.submit()">' . ($isToday ? '' : '<a href="index.php" class="btn btn-sm btn-light border py-0">Today</a>') . '</form>');
?>
<?php if (!$accounts) { ?><div class="alert alert-info small">No financial account yet. <?= fac($conn, 'manage') ? '<a href="accounts.php?new=1">Add your accounts</a> (Equity Bank, BK, Mobile Money, Cash on Hand…) with their opening balances, then set the <a href="assign.php">assignment rules</a>.' : 'An Administrator adds them.'; ?></div><?php } ?>
<?php if ($unassigned && fac($conn, 'manage')) { ?><div class="alert alert-warning py-2 small"><strong><?= (int) $unassigned; ?></strong> money movement(s) are not linked to an account yet (no rule applies). <a href="assign.php">Link them</a> — MY MOTIVE does not guess.</div><?php } ?>
<div class="row g-2 mb-3">
    <div class="col-12 col-md-4"><div class="card border-0 shadow-sm h-100 fa-k" style="background:linear-gradient(135deg,#0B1A8C,#1E2FE0);"><div class="card-body"><div class="lbl text-white-50">Total company money<?= $isToday ? '' : ' on ' . fa_date($asOf); ?></div><div class="val text-white" style="font-size:1.7rem"><?= fa_money($total); ?></div><div class="sub text-white-50"><?= count(array_filter($accounts, function ($a) { return $a['status'] === 'Active'; })); ?> active account(s) · transfers are not counted twice</div></div></div></div>
    <?php foreach (['Income' => 'Income (30 days)', 'Expense' => 'Expenses (30 days)', 'Transfer out' => 'Transfers (30 days)'] as $t => $l) { ?><div class="col-4 col-md-2"><div class="card border-0 shadow-sm h-100 fa-k"><div class="card-body py-2"><div class="lbl"><?= $l; ?></div><div class="val" style="font-size:1rem"><?= fa_money($sumBy($t)); ?></div></div></div></div><?php } ?>
    <div class="col-12 col-md-2"><div class="card border-0 shadow-sm h-100 fa-k"><div class="card-body py-2"><div class="lbl">Unreconciled bank lines</div><div class="val" style="font-size:1rem"><?= $unrec; ?></div><div class="sub"><?= fac($conn, 'reconcile') ? '<a href="reconcile.php">Reconcile</a>' : ''; ?></div></div></div></div>
</div>
<div class="row g-3">
<div class="col-xl-4">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="fw-semibold mb-1">Account balances</div>
        <?php foreach ($accounts as $a) { if ($a['status'] !== 'Active' && abs($bal[$a['id']] ?? 0) < 0.5) { continue; } ?><div class="d-flex justify-content-between border-bottom py-1"><a href="account.php?id=<?= (int) $a['id']; ?>" class="text-decoration-none"><?= fa_e($a['name']); ?> <span class="text-muted"><?= fa_e($a['type']); ?></span></a><strong><?= fa_money($bal[$a['id']] ?? 0); ?></strong></div><?php } ?>
        <div class="d-flex justify-content-between pt-1"><span class="fw-semibold">Total</span><strong><?= fa_money($total); ?></strong></div></div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="fw-semibold mb-1">Fund balances <span class="fw-normal text-muted">(money; stock and asset value excluded)</span></div>
        <?php foreach ($funds as $f) { ?><div class="d-flex justify-content-between border-bottom py-1"><span><?= fa_e($f['name']); ?><?= $f['allocation_percent'] !== null ? ' <span class="text-muted">' . (float) $f['allocation_percent'] . '%</span>' : ''; ?></span><strong><?= fa_money($fundCash[$f['id']] ?? 0); ?></strong></div><?php } ?>
        <div class="text-muted mt-1">Allocation percentages are set in <a href="../budget/funds.php">Budget &amp; Fund Control → Funds</a>.</div></div></div>
</div>
<div class="col-xl-8">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="p-2 px-3 fw-semibold">Fund distribution by account</div><div class="table-responsive"><table class="table table-sm mb-0 fa-tbl">
        <tr class="table-light"><th class="ps-3">Fund</th><?php foreach ($accounts as $a) { if ($a['status'] !== 'Active' && abs($bal[$a['id']] ?? 0) < 0.5) { continue; } ?><th class="text-end"><?= fa_e($a['name']); ?></th><?php } ?><th class="text-end">In accounts</th><th class="text-end" title="Fund money not located in an account yet: history before tracking not covered by the opening compositions, or movements not linked">Not located</th><th class="text-end">Fund total</th></tr>
        <?php foreach ($funds as $f) { $fid = (int) $f['id']; $in = 0; ?><tr><td class="ps-3"><?= fa_e(fa_fund_short($f['name'])); ?></td>
            <?php foreach ($accounts as $a) { if ($a['status'] !== 'Active' && abs($bal[$a['id']] ?? 0) < 0.5) { continue; } $v = $M[$a['id']][$fid] ?? 0; $in += $v; ?><td class="text-end"><?= abs($v) >= 0.5 ? number_format($v, 0) : '<span class="text-muted">–</span>'; ?></td><?php } ?>
            <td class="text-end fw-semibold"><?= number_format($in, 0); ?></td><td class="text-end <?= abs(($fundCash[$fid] ?? 0) - $in) >= 0.5 ? 'text-warning' : 'text-muted'; ?>"><?= number_format(($fundCash[$fid] ?? 0) - $in, 0); ?></td><td class="text-end"><?= number_format($fundCash[$fid] ?? 0, 0); ?></td></tr><?php } ?>
        <?php $na = 0; foreach ($M as $row) { $na += $row[0] ?? 0; } if (abs($na) >= 0.5) { ?><tr><td class="ps-3 text-muted">Not allocated to a fund</td><?php foreach ($accounts as $a) { if ($a['status'] !== 'Active' && abs($bal[$a['id']] ?? 0) < 0.5) { continue; } $v = $M[$a['id']][0] ?? 0; ?><td class="text-end text-muted"><?= abs($v) >= 0.5 ? number_format($v, 0) : '–'; ?></td><?php } ?><td class="text-end text-muted"><?= number_format($na, 0); ?></td><td></td><td></td></tr><?php } ?>
        <tr class="table-light fw-semibold"><td class="ps-3">Total</td><?php foreach ($accounts as $a) { if ($a['status'] !== 'Active' && abs($bal[$a['id']] ?? 0) < 0.5) { continue; } ?><td class="text-end"><?= number_format($bal[$a['id']] ?? 0, 0); ?></td><?php } ?><td class="text-end"><?= number_format($total, 0); ?></td><td></td><td class="text-end"><?= number_format(array_sum($fundCash), 0); ?></td></tr>
    </table></div><div class="p-2 px-3 small text-muted">"Not located" shrinks to zero once opening balances are split by fund and every movement is linked. "Not allocated to a fund" = opening money whose fund was not given.</div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="d-flex justify-content-between p-2 px-3"><span class="fw-semibold">Recent activity</span><a href="activity.php" class="small">All activity</a></div><div class="table-responsive"><table class="table table-sm mb-0 fa-tbl">
        <?php foreach (array_slice($recent, 0, 12) as $e) { ?><tr><td class="ps-3 text-nowrap small"><?= fa_date($e['date']); ?></td><td class="small"><?= fa_e($e['type']); ?><div class="text-muted"><?= fa_e($e['account']); ?></div></td><td class="small"><?= fa_e(mb_strimwidth((string) $e['description'], 0, 70, '…')); ?><?= $e['status'] !== 'Posted' ? ' ' . fa_status_pill($e['status']) : ''; ?></td><td class="text-end text-nowrap"><?= $e['status'] === 'Posted' ? fa_amt($e['amount']) : '<s class="text-muted">' . number_format(abs($e['gross'] ?? 0)) . '</s>'; ?></td></tr><?php } ?>
        <?php if (!$recent) { ?><tr><td class="text-center text-muted py-4">No activity in the 30 days before <?= fa_date($asOf); ?>.</td></tr><?php } ?>
    </table></div></div></div>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>