<?php
/**
 * NEW FILE: modules/fin_accounts/activity.php — ACTIVITY & HISTORICAL BALANCES of the financial accounts.
 * Filters: date range, financial account, fund, transaction type, expense category, status. For each account:
 * balance at the start and at the end of the period, every entry with the running balance. ?account=ID shows the
 * account's details and its fund composition. Excel (CSV) export.
 */
require __DIR__ . '/common.php';
fa_require($conn, 'view');
$full = fac($conn, 'identifiers');
$accounts = fa_accounts($conn); $funds = fa_funds($conn);
$acc = (int) ($_GET['account'] ?? 0); $fund = (int) ($_GET['fund'] ?? 0);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if ($to < $from) { [$from, $to] = [$to, $from]; }
$types = ['Opening balance', 'Income', 'Expense', 'Transfer in', 'Transfer out', 'Transfer fee / bank charge', 'Adjustment', 'Reversal'];
$ft = in_array($_GET['type'] ?? '', $types, true) ? $_GET['type'] : ''; $fc = trim((string) ($_GET['category'] ?? '')); $fs = in_array($_GET['status'] ?? '', ['Posted', 'Reversed'], true) ? $_GET['status'] : '';
$sel = $acc ? array_values(array_filter($accounts, function ($a) use ($acc) { return (int) $a['id'] === $acc; })) : $accounts;
$prev = date('Y-m-d', strtotime($from . ' -1 day'));
$Mfrom = fa_matrix($conn, $prev); $Mto = fa_matrix($conn, $to);
$blocks = []; $cats = [];
foreach ($sel as $a) {
    $id = (int) $a['id'];
    $ob = $fund ? ($Mfrom[$id][$fund] ?? 0) : array_sum($Mfrom[$id] ?? []); $cb = $fund ? ($Mto[$id][$fund] ?? 0) : array_sum($Mto[$id] ?? []);
    $L = fa_ledger($conn, $id, $from, $to, $fund);
    foreach ($L as $e) { if ($e['category']) { $cats[$e['category']] = true; } }
    $run = $ob; foreach ($L as &$e) { $run += $e['amount']; $e['run'] = $run; } unset($e);
    $L = array_values(array_filter($L, function ($e) use ($ft, $fc, $fs) { return (!$ft || $e['type'] === $ft) && ($fc === '' || $e['category'] === $fc) && (!$fs || $e['status'] === $fs); }));
    $blocks[] = ['a' => $a, 'open' => $ob, 'close' => $cb, 'rows' => $L];
}
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="account-activity-' . $from . '-' . $to . '.csv"');
    $f = fopen('php://output', 'w'); fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Account', 'Date', 'Type', 'Description', 'Reference', 'Category', 'Status', 'In', 'Out', 'Balance']);
    foreach ($blocks as $b) { fputcsv($f, [$b['a']['name'], $prev, 'Balance brought forward', '', '', '', '', '', '', round($b['open'], 2)]);
        foreach ($b['rows'] as $e) { fputcsv($f, [$b['a']['name'], $e['date'], $e['type'], $e['description'], $e['reference'], $e['category'], $e['status'], $e['amount'] > 0 ? $e['amount'] : '', $e['amount'] < 0 ? -$e['amount'] : '', round($e['run'], 2)]); }
        fputcsv($f, [$b['a']['name'], $to, 'Closing balance', '', '', '', '', '', '', round($b['close'], 2)]); }
    exit;
}
$keep = ['account' => $acc ?: '', 'fund' => $fund ?: '', 'from' => $from, 'to' => $to, 'type' => $ft, 'category' => $fc, 'status' => $fs];
$one = $acc && $sel ? $sel[0] : null;
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
fa_tabs($conn, $one ? 'accounts.php' : 'activity.php', $one ? $one['name'] : 'Activity & historical balances');
?>
<?php if ($one) { $comp = fa_composition($conn, $acc); ?>
<div class="row g-2 mb-3">
    <div class="col-md-5"><div class="card border-0 shadow-sm h-100"><div class="card-body small">
        <div class="d-flex justify-content-between"><span class="fw-semibold"><?= fa_e($one['type']); ?> · <?= fa_e($one['provider']); ?></span><?= fa_status_pill($one['status']); ?></div>
        <div>Number: <span class="font-monospace"><?= fa_e(fa_mask($one['identifier'], $full) ?: '—'); ?></span> · <?= fa_e($one['currency']); ?></div>
        <div>Custodian: <?= fa_e($one['custodian'] ?: '—'); ?></div><div>Opening: <?= fa_money($one['opening_balance']); ?> on <?= fa_date($one['opening_date']); ?></div>
        <?php if ($one['notes']) { ?><div class="text-muted mt-1"><?= fa_e($one['notes']); ?></div><?php } ?>
        <div class="fw-bold fs-5 mt-2"><?= fa_money(array_sum($comp)); ?> <span class="small fw-normal text-muted">now</span></div></div></div></div>
    <div class="col-md-7"><div class="card border-0 shadow-sm h-100"><div class="card-body small"><div class="fw-semibold mb-1">Funds held in this account now</div>
        <?php foreach ($funds as $f) { $v = $comp[$f['id']] ?? 0; if (abs($v) < 0.5) { continue; } ?><div class="d-flex justify-content-between border-bottom py-1"><span><?= fa_e($f['name']); ?></span><strong><?= fa_money($v); ?></strong></div><?php } ?>
        <?php if (abs($comp[0] ?? 0) >= 0.5) { ?><div class="d-flex justify-content-between py-1 text-muted"><span>Not allocated to a fund</span><strong><?= fa_money($comp[0]); ?></strong></div><?php } ?>
        <?php if (!array_filter($comp, function ($v) { return abs($v) >= 0.5; })) { ?><div class="text-muted">Empty.</div><?php } ?></div></div></div>
</div>
<?php } ?>
<form class="card border-0 shadow-sm mb-3"><div class="card-body py-2 row g-2 small align-items-end">
    <div class="col-6 col-md-2"><label class="form-label mb-0">From</label><input type="date" name="from" value="<?= fa_e($from); ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label mb-0">To</label><input type="date" name="to" value="<?= fa_e($to); ?>" max="<?= date('Y-m-d'); ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label mb-0">Account</label><select name="account" class="form-select form-select-sm"><option value="">All accounts</option><?php foreach ($accounts as $a) { ?><option value="<?= (int) $a['id']; ?>" <?= $acc === (int) $a['id'] ? 'selected' : ''; ?>><?= fa_e($a['name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label mb-0">Fund</label><select name="fund" class="form-select form-select-sm"><option value="">All funds</option><?php foreach ($funds as $f) { ?><option value="<?= (int) $f['id']; ?>" <?= $fund === (int) $f['id'] ? 'selected' : ''; ?>><?= fa_e(fa_fund_short($f['name'])); ?></option><?php } ?></select></div>
    <div class="col-4 col-md-1"><label class="form-label mb-0">Type</label><select name="type" class="form-select form-select-sm"><option value="">All</option><?php foreach ($types as $x) { ?><option <?= $ft === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
    <div class="col-4 col-md-1"><label class="form-label mb-0">Category</label><select name="category" class="form-select form-select-sm"><option value="">All</option><?php foreach (array_keys($cats) as $x) { ?><option <?= $fc === $x ? 'selected' : ''; ?>><?= fa_e($x); ?></option><?php } ?></select></div>
    <div class="col-4 col-md-1"><label class="form-label mb-0">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><?php foreach (['Posted', 'Reversed'] as $x) { ?><option <?= $fs === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
    <div class="col-12 col-md-1 d-flex gap-1"><button class="btn btn-sm btn-primary w-100">Show</button></div>
</div></form>
<div class="d-flex justify-content-end mb-1 small"><a href="?<?= fa_e(http_build_query(array_filter($keep) + ['export' => 'csv'])); ?>">Export (Excel / CSV)</a></div>
<?php foreach ($blocks as $b) { $a = $b['a']; ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0">
    <div class="d-flex flex-wrap justify-content-between gap-2 p-2 px-3 small"><a href="activity.php?<?= fa_e(http_build_query(['account' => $a['id'], 'from' => $from, 'to' => $to])); ?>" class="fw-semibold text-decoration-none"><?= fa_e($a['name']); ?></a>
        <span>Balance on <?= fa_date($prev); ?>: <strong><?= fa_money($b['open']); ?></strong> → on <?= fa_date($to); ?>: <strong><?= fa_money($b['close']); ?></strong><?= $fund ? ' <span class="text-muted">(' . fa_e(fa_fund_short(fund_get($conn, $fund)['name'] ?? '')) . ' only)</span>' : ''; ?></span></div>
    <div class="table-responsive"><table class="table table-sm mb-0 fa-tbl">
        <tr class="table-light"><th class="ps-3">Date</th><th>Type</th><th>Description</th><th class="d-none d-md-table-cell">Reference</th><th class="text-end">Amount</th><th class="text-end d-none d-md-table-cell">Balance</th></tr>
        <?php foreach ($b['rows'] as $e) { ?><tr><td class="ps-3 text-nowrap small"><?= fa_date($e['date']); ?></td><td class="small"><?= fa_e($e['type']); ?><?= $e['status'] !== 'Posted' ? ' ' . fa_status_pill($e['status']) : ''; ?></td>
            <td class="small"><?= $e['tx'] ? '<a href="../transactions/view.php?id=' . (int) $e['tx'] . '" class="text-decoration-none">' . fa_e($e['description'] ?: 'Transaction #' . $e['tx']) . '</a>' : fa_e($e['description']); ?><div class="text-muted"><?= fa_e($e['source']); ?></div></td>
            <td class="small d-none d-md-table-cell"><?= fa_e($e['reference']); ?></td><td class="text-end text-nowrap"><?= $e['status'] === 'Posted' ? fa_amt($e['amount']) : '<s class="text-muted">' . number_format(abs($e['gross'] ?? 0)) . '</s>'; ?></td><td class="text-end d-none d-md-table-cell small"><?= number_format($e['run'], 0); ?></td></tr><?php } ?>
        <?php if (!$b['rows']) { ?><tr><td colspan="6" class="text-center text-muted py-3 small">No entry in this period.</td></tr><?php } ?>
    </table></div></div></div>
<?php } ?>
<?php if (!$blocks) { ?><div class="text-center text-muted small py-5">No financial account yet.</div><?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>