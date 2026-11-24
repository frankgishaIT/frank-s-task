<?php
/**
 * NEW FILE: modules/fin_accounts/adjustments.php — ACCOUNT ADJUSTMENTS (Admin): correct an account balance with a
 * reason (e.g. a difference found when counting cash). Optional fund: where the corrected money belongs. Posted
 * adjustments are never edited or deleted: they are reversed with a reason. Everything is in the audit trail.
 * Note: an adjustment corrects WHERE money is; income and expenses themselves are recorded in Transactions.
 */
require __DIR__ . '/common.php';
fa_require($conn, 'adjust');
$accounts = fa_accounts($conn, true); $funds = fa_funds($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fa_csrf_ok()) { fa_go('adjustments.php', 'error', 'The page had expired.'); }
    $u = fa_uid(); $now = date('Y-m-d H:i:s');
    try {
        if (($_POST['do'] ?? '') === 'reverse') {
            $id = (int) $_POST['id']; $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255);
            if ($reason === '') { throw new RuntimeException('Give the reason for the reversal.'); }
            $a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM fa_adjustments WHERE id = $id AND status = 'Posted'")); if (!$a) { throw new RuntimeException('Not found or already reversed.'); }
            if (mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM fa_statement_lines WHERE match_key = 'adj:$id' LIMIT 1"))) { throw new RuntimeException('This adjustment is matched to a bank statement line: undo the match first.'); }
            $s = mysqli_prepare($conn, "UPDATE fa_adjustments SET status = 'Reversed', reversed_by = ?, reversed_at = ?, reversal_reason = ? WHERE id = ?"); mysqli_stmt_bind_param($s, 'issi', $u, $now, $reason, $id); mysqli_stmt_execute($s);
            fa_audit($conn, 'adjustment', $id, 'reversed', $reason);
            fa_go('adjustments.php', 'success', 'Adjustment #' . $id . ' reversed.');
        }
        $acc = (int) ($_POST['account_id'] ?? 0); $A = fa_account($conn, $acc); if (!$A || $A['status'] !== 'Active') { throw new RuntimeException('Choose an active account.'); }
        $dir = ($_POST['direction'] ?? '') === 'OUT' ? 'OUT' : 'IN'; $amt = round((float) ($_POST['amount'] ?? 0), 2); $date = (string) ($_POST['adj_date'] ?? '');
        $fund = (int) ($_POST['fund_id'] ?? 0) ?: null; $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255);
        if ($amt <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d') || $date < $A['opening_date']) { throw new RuntimeException('Enter the amount and a date between the opening date and today.'); }
        if (mb_strlen($reason) < 5) { throw new RuntimeException('Explain the reason for the adjustment.'); }
        if ($dir === 'OUT') { $c = fa_composition($conn, $acc, $date); $have = $fund ? ($c[$fund] ?? 0) : array_sum($c); if ($amt > $have + 0.005) { throw new RuntimeException('The account holds only ' . fa_money($have) . ($fund ? ' of this fund' : '') . ' on that date.'); } }
        $s = mysqli_prepare($conn, 'INSERT INTO fa_adjustments (account_id, fund_id, direction, amount, adj_date, reason, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'iisdssi', $acc, $fund, $dir, $amt, $date, $reason, $u); mysqli_stmt_execute($s); $id = (int) mysqli_insert_id($conn);
        fa_audit($conn, 'adjustment', $id, 'posted', $A['name'] . ': ' . ($dir === 'IN' ? '+' : '−') . fa_money($amt) . ($fund ? ' (' . (fund_get($conn, $fund)['name'] ?? '') . ')' : '') . ' — ' . $reason);
        fa_go('adjustments.php', 'success', 'Adjustment #' . $id . ' posted.');
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
}
$list = fa_rows($conn, 'SELECT j.*, a.name aname, f.name fname, u.names uname, r.names rname FROM fa_adjustments j JOIN fa_accounts a ON a.id = j.account_id LEFT JOIN funds f ON f.id = j.fund_id LEFT JOIN users u ON u.id = j.created_by LEFT JOIN users r ON r.id = j.reversed_by ORDER BY j.adj_date DESC, j.id DESC LIMIT 300');
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
fa_tabs($conn, 'adjustments.php', 'Account adjustments');
?>
<?php if (!empty($err)) { ?><div class="alert alert-danger py-2 small"><?= fa_e($err); ?></div><?php } ?>
<div class="row g-3">
<div class="col-xl-4"><form method="POST" class="card border-0 shadow-sm"><div class="card-body small row g-2"><?= fa_csrf_field(); ?>
    <div class="col-12 fw-semibold">New adjustment</div>
    <div class="col-12"><select name="account_id" class="form-select form-select-sm" required><option value="">Account</option><?php foreach ($accounts as $a) { ?><option value="<?= (int) $a['id']; ?>"><?= fa_e($a['name']); ?></option><?php } ?></select></div>
    <div class="col-6"><select name="direction" class="form-select form-select-sm"><option value="IN">Increase (+)</option><option value="OUT">Decrease (−)</option></select></div>
    <div class="col-6"><input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" placeholder="Amount" required></div>
    <div class="col-6"><input type="date" name="adj_date" max="<?= date('Y-m-d'); ?>" value="<?= date('Y-m-d'); ?>" class="form-control form-control-sm" required></div>
    <div class="col-6"><select name="fund_id" class="form-select form-select-sm"><option value="">Fund: not specified</option><?php foreach ($funds as $f) { ?><option value="<?= (int) $f['id']; ?>"><?= fa_e(fa_fund_short($f['name'])); ?></option><?php } ?></select></div>
    <div class="col-12"><textarea name="reason" rows="2" maxlength="255" class="form-control form-control-sm" required placeholder="Reason (e.g. cash count on 30 Nov showed 2,000 less)"></textarea></div>
    <div class="col-12"><button class="btn btn-primary btn-sm px-4">Post the adjustment</button></div>
    <div class="col-12 text-muted">Income and expenses are recorded in Transactions. Use an adjustment only to correct the balance of an account.</div>
</div></form></div>
<div class="col-xl-8"><div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fa-tbl">
    <tr class="table-light"><th class="ps-3">Date</th><th>Account</th><th class="text-end">Amount</th><th>Reason</th><th>Status</th><th></th></tr>
    <?php foreach ($list as $j) { ?><tr><td class="ps-3 small text-nowrap"><?= fa_date($j['adj_date']); ?><div class="text-muted">#<?= (int) $j['id']; ?></div></td><td class="small"><?= fa_e($j['aname']); ?><div class="text-muted"><?= fa_e($j['fname'] ? fa_fund_short($j['fname']) : 'no fund'); ?></div></td>
        <td class="text-end"><?= fa_amt(($j['direction'] === 'IN' ? 1 : -1) * (float) $j['amount']); ?></td><td class="small"><?= fa_e($j['reason']); ?><div class="text-muted"><?= fa_e($j['uname']); ?></div></td>
        <td><?= fa_status_pill($j['status']); ?><?= $j['status'] === 'Reversed' ? '<div class="small text-muted">' . fa_e($j['rname'] . ': ' . $j['reversal_reason']) . '</div>' : ''; ?></td>
        <td class="text-end"><?php if ($j['status'] === 'Posted') { ?><form method="POST" class="d-inline" onsubmit="var r=prompt('Reason for the reversal?');if(!r)return false;this.reason.value=r;"><?= fa_csrf_field(); ?><input type="hidden" name="do" value="reverse"><input type="hidden" name="id" value="<?= (int) $j['id']; ?>"><input type="hidden" name="reason"><button class="btn btn-sm btn-link text-danger py-0">Reverse</button></form><?php } ?></td></tr><?php } ?>
    <?php if (!$list) { ?><tr><td colspan="6" class="text-center text-muted py-5">No adjustment.</td></tr><?php } ?>
</table></div></div></div></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>