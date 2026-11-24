<?php
/**
 * NEW FILE: modules/fin_accounts/assign.php — LINK MONEY TO ACCOUNTS (Admin).
 * 1. Assignment rules: the account that receives / pays each kind of automatic posting (sales — optionally per
 *    payment method —, purchase orders, payroll, loans, investors, credit hub, assets, capital…). Transactions of
 *    the Transactions module use the account chosen on the transaction.
 * 2. Movements no rule could place: an authorised person links them (MY MOTIVE never guesses), or corrects a link.
 * Every change is in the audit trail.
 */
require __DIR__ . '/common.php';
fa_require($conn, 'manage');
$accounts = fa_accounts($conn, true); $accIds = array_map('intval', array_column($accounts, 'id'));
$methods = array_values(array_filter(array_column(fa_rows($conn, "SELECT DISTINCT payment_method FROM sales WHERE payment_method IS NOT NULL AND payment_method <> '' ORDER BY payment_method"), 'payment_method'), function ($m) { return $m !== 'Credit'; }));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fa_csrf_ok()) { fa_go('assign.php', 'error', 'The page had expired.'); }
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'rules') {
        $old = array_column(fa_rows($conn, 'SELECT source, account_id FROM fa_rules'), 'account_id', 'source'); $chg = [];
        $keys = array_merge(array_keys(FA_SOURCES), array_map(function ($m) { return 'sales:' . $m; }, $methods));
        foreach ($keys as $k) { if ($k === 'transaction') { continue; }
            $v = (int) ($_POST['rule'][$k] ?? 0); if ($v && !in_array($v, $accIds, true)) { continue; }
            if ((int) ($old[$k] ?? 0) === $v) { continue; }
            $s = mysqli_prepare($conn, 'DELETE FROM fa_rules WHERE source = ?'); mysqli_stmt_bind_param($s, 's', $k); mysqli_stmt_execute($s);
            if ($v) { $s = mysqli_prepare($conn, 'INSERT INTO fa_rules (source, account_id) VALUES (?, ?)'); mysqli_stmt_bind_param($s, 'si', $k, $v); mysqli_stmt_execute($s); }
            $chg[] = $k . ' → ' . ($v ? (fa_account($conn, $v)['name'] ?? $v) : 'none'); }
        if ($chg) { fa_audit($conn, 'rules', null, 'changed', implode('; ', $chg)); }
        $n = fa_sync($conn);
        fa_go('assign.php', 'success', ($chg ? 'Rules saved.' : 'Nothing changed.') . ($n ? ' ' . $n . ' movement(s) linked now.' : ''));
    }
    if ($do === 'link') {
        $acc = (int) ($_POST['account_id'] ?? 0); $ids = array_values(array_filter(array_map('intval', (array) ($_POST['m'] ?? []))));
        if (!in_array($acc, $accIds, true) || !$ids) { fa_go('assign.php', 'error', 'Choose the movements and the account.'); }
        $u = fa_uid(); $n = 0; $done = [];
        foreach ($ids as $mid) {
            $ok = mysqli_fetch_row(mysqli_query($conn, "SELECT m.id FROM fund_movements m LEFT JOIN fund_movements o ON o.id = m.reverses_movement_id LEFT JOIN fa_movement_accounts x ON x.movement_id = m.id WHERE m.id = $mid AND x.movement_id IS NULL AND " . fa_cash_sql()));
            if (!$ok) { continue; }
            $s = mysqli_prepare($conn, "INSERT INTO fa_movement_accounts (movement_id, account_id, rule, assigned_by) VALUES (?, ?, 'manual', ?)"); mysqli_stmt_bind_param($s, 'iii', $mid, $acc, $u); mysqli_stmt_execute($s); $n++; $done[] = $mid;
        }
        fa_audit($conn, 'link', $acc, 'linked', $n . ' movement(s) linked by hand to ' . (fa_account($conn, $acc)['name'] ?? '') . ': #' . implode(', #', $done));
        fa_go('assign.php', 'success', $n . ' movement(s) linked.');
    }
    if ($do === 'relink') {
        $mid = (int) $_POST['movement_id']; $acc = (int) ($_POST['account_id'] ?? 0); $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 200);
        $cur = mysqli_fetch_assoc(mysqli_query($conn, "SELECT x.*, a.name FROM fa_movement_accounts x JOIN fa_accounts a ON a.id = x.account_id WHERE x.movement_id = $mid"));
        if (!$cur || !in_array($acc, $accIds, true) || $reason === '') { fa_go('assign.php', 'error', 'Choose the new account and give the reason.'); }
        $u = fa_uid(); mysqli_query($conn, "UPDATE fa_movement_accounts SET account_id = $acc, rule = 'manual', assigned_by = " . (int) $u . " WHERE movement_id = $mid");
        fa_audit($conn, 'link', $mid, 'relinked', 'Movement #' . $mid . ': ' . $cur['name'] . ' → ' . (fa_account($conn, $acc)['name'] ?? '') . ' — ' . $reason);
        fa_go('assign.php', 'success', 'Movement #' . $mid . ' moved to another account.');
    }
}
$rules = array_column(fa_rows($conn, 'SELECT source, account_id FROM fa_rules'), 'account_id', 'source');
$un = fa_unassigned($conn, 500);
$recent = fa_rows($conn, 'SELECT x.*, m.amount, m.direction, m.description, m.ref_type, m.created_at mcreated, f.name fund, a.name aname FROM fa_movement_accounts x JOIN fund_movements m ON m.id = x.movement_id JOIN funds f ON f.id = m.fund_id JOIN fa_accounts a ON a.id = x.account_id ORDER BY x.movement_id DESC LIMIT 40');
$sel = function ($name, $val) use ($accounts) { $o = '<select name="' . $name . '" class="form-select form-select-sm"><option value="">— not linked —</option>'; foreach ($accounts as $a) { $o .= '<option value="' . (int) $a['id'] . '"' . ((int) $val === (int) $a['id'] ? ' selected' : '') . '>' . fa_e($a['name']) . '</option>'; } return $o . '</select>'; };
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
fa_tabs($conn, 'assign.php', 'Link money to accounts');
$start = fa_tracking_start($conn);
?>
<?php if (!$start) { ?><div class="alert alert-info small">Add the financial accounts first: money is linked to accounts from the earliest opening date.</div><?php } else { ?>
<div class="small text-muted mb-2">Money movements are linked from <strong><?= fa_date($start); ?></strong> (the earliest opening date). Earlier money is covered by the opening balances.</div>
<?php } ?>
<div class="row g-3">
<div class="col-xl-5"><form method="POST" class="card border-0 shadow-sm"><div class="card-body small"><?= fa_csrf_field(); ?><input type="hidden" name="do" value="rules">
    <div class="fw-semibold mb-1">Assignment rules</div><div class="text-muted mb-2">Which account receives or pays each kind of automatic posting.</div>
    <?php foreach (FA_SOURCES as $k => $l) { if ($k === 'transaction') { ?><div class="border-bottom py-1 text-muted"><?= fa_e($l); ?></div><?php continue; } ?>
        <div class="d-flex justify-content-between align-items-center gap-2 border-bottom py-1"><span><?= fa_e($l); ?></span><div style="width:190px"><?= $sel('rule[' . $k . ']', $rules[$k] ?? 0); ?></div></div>
        <?php if ($k === 'sales') { foreach ($methods as $m) { ?><div class="d-flex justify-content-between align-items-center gap-2 border-bottom py-1 ps-3"><span class="text-muted">Sales paid by <?= fa_e($m); ?></span><div style="width:190px"><?= $sel('rule[sales:' . $m . ']', $rules['sales:' . $m] ?? 0); ?></div></div><?php } } ?>
    <?php } ?>
    <button class="btn btn-primary btn-sm mt-2">Save the rules</button>
</div></form></div>
<div class="col-xl-7">
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body p-0 small"><?= fa_csrf_field(); ?><input type="hidden" name="do" value="link">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-2 px-3"><span class="fw-semibold">Not linked to an account (<?= count($un); ?>)</span>
            <?php if ($un) { ?><span class="d-flex gap-1"><select name="account_id" class="form-select form-select-sm" required style="width:auto"><option value="">Link the selected to…</option><?php foreach ($accounts as $a) { ?><option value="<?= (int) $a['id']; ?>"><?= fa_e($a['name']); ?></option><?php } ?></select><button class="btn btn-sm btn-primary">Link</button></span><?php } ?></div>
        <div class="table-responsive" style="max-height:420px"><table class="table table-sm mb-0 fa-tbl">
            <?php if ($un) { ?><tr class="table-light"><th class="ps-3"><input type="checkbox" class="form-check-input" onclick="document.querySelectorAll('.fa-m').forEach(function(c){c.checked=this.checked}.bind(this))"></th><th>Date</th><th>Fund</th><th>From</th><th class="text-end">Amount</th></tr><?php } ?>
            <?php foreach ($un as $m) { ?><tr><td class="ps-3"><input type="checkbox" name="m[]" value="<?= (int) $m['id']; ?>" class="form-check-input fa-m"></td><td class="text-nowrap"><?= fa_date($m['transaction_date'] ?: $m['created_at']); ?></td><td><?= fa_e(fa_fund_short($m['fund'])); ?></td>
                <td><?= fa_e(FA_SOURCES[fa_source_of($m)] ?? ''); ?><div class="text-muted"><?= fa_e(mb_strimwidth((string) ($m['tdesc'] ?: $m['description']), 0, 60, '…')); ?></div></td><td class="text-end"><?= fa_amt(($m['direction'] === 'IN' ? 1 : -1) * (float) $m['amount']); ?></td></tr><?php } ?>
            <?php if (!$un) { ?><tr><td class="text-center text-muted py-4">Every money movement is linked to an account.</td></tr><?php } ?>
        </table></div></div></form>
    <div class="card border-0 shadow-sm"><div class="card-body p-0 small"><div class="p-2 px-3 fw-semibold">Latest links</div><div class="table-responsive" style="max-height:360px"><table class="table table-sm mb-0 fa-tbl">
        <?php foreach ($recent as $r) { ?><tr><td class="ps-3 text-nowrap"><?= fa_date($r['mcreated']); ?></td><td><?= fa_e(fa_fund_short($r['fund'])); ?><div class="text-muted"><?= fa_e(mb_strimwidth((string) $r['description'], 0, 50, '…')); ?></div></td><td class="text-end"><?= fa_amt(($r['direction'] === 'IN' ? 1 : -1) * (float) $r['amount']); ?></td>
            <td><?= fa_e($r['aname']); ?><div class="text-muted"><?= fa_e($r['rule']); ?></div></td>
            <td class="text-end"><form method="POST" class="d-inline" onsubmit="var r=prompt('Reason for moving it to another account?');if(!r)return false;this.reason.value=r;"><?= fa_csrf_field(); ?><input type="hidden" name="do" value="relink"><input type="hidden" name="movement_id" value="<?= (int) $r['movement_id']; ?>"><input type="hidden" name="reason">
                <select name="account_id" class="form-select form-select-sm d-inline-block py-0" style="width:auto" onchange="if(this.value)this.form.requestSubmit()"><option value="">Move…</option><?php foreach ($accounts as $a) { if ((int) $a['id'] === (int) $r['account_id']) { continue; } ?><option value="<?= (int) $a['id']; ?>"><?= fa_e($a['name']); ?></option><?php } ?></select></form></td></tr><?php } ?>
        <?php if (!$recent) { ?><tr><td class="text-center text-muted py-4">Nothing linked yet.</td></tr><?php } ?>
    </table></div></div></div>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>