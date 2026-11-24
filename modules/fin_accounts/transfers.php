<?php
/**
 * NEW FILE: modules/fin_accounts/transfers.php — TRANSFERS BETWEEN ACCOUNTS.
 * A transfer is neither income nor expense: the source account decreases, the destination increases, total company
 * money and the fund totals stay the same. The fund composition moves with the money: it is pre-filled from what
 * the source account holds and must add up to the amount (the part "not allocated to a fund" moves as it is — MY
 * MOTIVE never invents a split). A bank fee is recorded separately as an Expense (Bank Charges) with its account and fund.
 * Corrections: reversal with a reason (never deleted).
 */
require __DIR__ . '/common.php';
fa_require($conn, 'view');
$can = fac($conn, 'transfer');
$accounts = fa_accounts($conn, true); $funds = fa_funds($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fa_csrf_ok()) { fa_go('transfers.php', 'error', 'The page had expired.'); }
    if (!$can) { fa_go('transfers.php', 'error', 'You may not record transfers.'); }
    try {
        if (($_POST['do'] ?? '') === 'reverse') {
            $id = (int) $_POST['id']; $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255);
            $t = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM fa_transfers WHERE id = $id AND status = 'Posted'"));
            if (!$t) { throw new RuntimeException('Not found or already reversed.'); }
            if ($reason === '') { throw new RuntimeException('Give the reason for the reversal.'); }
            if (mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM fa_statement_lines WHERE match_key IN ('tr:$id:in', 'tr:$id:out') LIMIT 1"))) { throw new RuntimeException('This transfer is matched to a bank statement line: undo the match in Bank reconciliation first.'); }
            $comp = fa_composition($conn, (int) $t['to_account']);
            foreach (fa_rows($conn, "SELECT fund_id, amount FROM fa_transfer_lines WHERE transfer_id = $id") as $l) { if ((float) $l['amount'] > ($comp[$l['fund_id']] ?? 0) + 0.005) { throw new RuntimeException('The destination account no longer holds this money: record a new transfer back instead.'); } }
            $now = date('Y-m-d H:i:s'); $u = fa_uid();
            $s = mysqli_prepare($conn, "UPDATE fa_transfers SET status = 'Reversed', reversed_by = ?, reversed_at = ?, reversal_reason = ? WHERE id = ?"); mysqli_stmt_bind_param($s, 'issi', $u, $now, $reason, $id); mysqli_stmt_execute($s);
            fa_audit($conn, 'transfer', $id, 'reversed', $reason . ($t['fee_transaction_id'] ? ' (the fee, transaction #' . $t['fee_transaction_id'] . ', is corrected in Transactions if it was refunded)' : ''));
            fa_go('transfers.php', 'success', 'Transfer #' . $id . ' reversed.');
        }
        $lines = []; foreach ((array) ($_POST['line'] ?? []) as $f => $v) { if (is_numeric($v) && (float) $v > 0) { $lines[(int) $f] = (float) $v; } }
        $date = (string) ($_POST['transfer_date'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { throw new RuntimeException('Enter the transfer date.'); }
        $ref = mb_substr(trim((string) ($_POST['reference'] ?? '')), 0, 80); $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255);
        if ($ref === '') { throw new RuntimeException('Enter the reference (bank slip / transaction ID).'); }
        $fee = (float) ($_POST['fee'] ?? 0);
        $id = fa_transfer($conn, (int) $_POST['from_account'], (int) $_POST['to_account'], (float) $_POST['amount'], $date, $ref, $desc, $lines, max(0, $fee), (int) ($_POST['fee_fund'] ?? 0));
        fa_go('transfers.php', 'success', 'Transfer #' . $id . ' recorded' . ($fee > 0 ? ', with the fee as a Bank Charges expense' : '') . '.');
    } catch (InsufficientFundException $e) { $err = 'Fee: ' . $e->getMessage(); }
    catch (RuntimeException $e) { $err = $e->getMessage(); }
}
$comp = []; foreach ($accounts as $a) { $c = fa_composition($conn, (int) $a['id']); $comp[(int) $a['id']] = array_map(function ($v) { return round($v, 2); }, $c); }
$list = fa_rows($conn, 'SELECT t.*, fa.name fname, ta.name tname, u.names uname FROM fa_transfers t JOIN fa_accounts fa ON fa.id = t.from_account JOIN fa_accounts ta ON ta.id = t.to_account LEFT JOIN users u ON u.id = t.created_by ORDER BY t.transfer_date DESC, t.id DESC LIMIT 300');
$lineRows = []; foreach (fa_rows($conn, 'SELECT l.*, f.name FROM fa_transfer_lines l LEFT JOIN funds f ON f.id = l.fund_id') as $l) { $lineRows[(int) $l['transfer_id']][] = $l; }
$P = function ($k, $d = '') { return fa_e($_POST[$k] ?? $d); };
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
fa_tabs($conn, 'transfers.php', 'Transfers between accounts');
?>
<?php if (!empty($err)) { ?><div class="alert alert-danger py-2 small"><?= fa_e($err); ?></div><?php } ?>
<div class="row g-3">
<?php if ($can) { ?>
<div class="col-xl-5"><form method="POST" class="card border-0 shadow-sm" id="faTr"><div class="card-body small row g-2"><?= fa_csrf_field(); ?>
    <div class="col-12 fw-semibold">New transfer</div>
    <div class="col-6"><label class="form-label">From</label><select name="from_account" id="faFrom" class="form-select form-select-sm" required><option value="">Source account</option><?php foreach ($accounts as $a) { ?><option value="<?= (int) $a['id']; ?>" <?= (int) ($_POST['from_account'] ?? 0) === (int) $a['id'] ? 'selected' : ''; ?>><?= fa_e($a['name']); ?></option><?php } ?></select></div>
    <div class="col-6"><label class="form-label">To</label><select name="to_account" class="form-select form-select-sm" required><option value="">Destination account</option><?php foreach ($accounts as $a) { ?><option value="<?= (int) $a['id']; ?>" <?= (int) ($_POST['to_account'] ?? 0) === (int) $a['id'] ? 'selected' : ''; ?>><?= fa_e($a['name']); ?></option><?php } ?></select></div>
    <div class="col-6"><label class="form-label">Amount</label><input type="number" step="0.01" min="0.01" name="amount" id="faAmt" class="form-control form-control-sm" required value="<?= $P('amount'); ?>"></div>
    <div class="col-6"><label class="form-label">Date</label><input type="date" name="transfer_date" max="<?= date('Y-m-d'); ?>" class="form-control form-control-sm" required value="<?= $P('transfer_date', date('Y-m-d')); ?>"></div>
    <div class="col-6"><label class="form-label">Reference</label><input name="reference" maxlength="80" class="form-control form-control-sm" required value="<?= $P('reference'); ?>" placeholder="Slip / transaction ID"></div>
    <div class="col-6"><label class="form-label">Description</label><input name="description" maxlength="255" class="form-control form-control-sm" value="<?= $P('description'); ?>"></div>
    <div class="col-12"><div class="d-flex justify-content-between align-items-center"><span class="fw-semibold">Funds moved</span><button type="button" class="btn btn-sm btn-light border py-0" id="faProp">Fill from the source account</button></div>
        <div class="text-muted" id="faHold">Choose the source account to see what it holds.</div>
        <div class="row g-1 mt-1"><?php foreach (array_merge([['id' => 0, 'name' => 'Not allocated to a fund']], $funds) as $f) { ?><div class="col-6 col-md-4"><label class="form-label mb-0" style="font-size:11.5px"><?= fa_e(fa_fund_short($f['name'])); ?> <span class="text-muted" data-hold="<?= (int) $f['id']; ?>"></span></label><input type="number" step="0.01" min="0" name="line[<?= (int) $f['id']; ?>]" data-fund="<?= (int) $f['id']; ?>" class="form-control form-control-sm fa-line" value="<?= fa_e($_POST['line'][$f['id']] ?? ''); ?>"></div><?php } ?></div>
        <div class="mt-1">Total of the funds: <strong id="faSum">0</strong> <span id="faChk"></span></div></div>
    <div class="col-12 mt-1 pt-2 border-top"><span class="fw-semibold">Transfer fee</span> <span class="text-muted">(recorded separately as an expense "Bank Charges" from the source account)</span></div>
    <div class="col-6"><input type="number" step="0.01" min="0" name="fee" class="form-control form-control-sm" placeholder="0" value="<?= $P('fee'); ?>"></div>
    <div class="col-6"><select name="fee_fund" class="form-select form-select-sm"><option value="">Fund paying the fee</option><?php foreach ($funds as $f) { ?><option value="<?= (int) $f['id']; ?>" <?= (int) ($_POST['fee_fund'] ?? 0) === (int) $f['id'] || (!isset($_POST['fee_fund']) && $f['code'] === 'OPERATING') ? 'selected' : ''; ?>><?= fa_e($f['name']); ?></option><?php } ?></select></div>
    <div class="col-12"><button class="btn btn-primary btn-sm px-4">Record the transfer</button></div>
</div></form></div>
<?php } ?>
<div class="<?= $can ? 'col-xl-7' : 'col-12'; ?>"><div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fa-tbl">
    <tr class="table-light"><th class="ps-3">Date</th><th>From → To</th><th class="text-end">Amount</th><th class="d-none d-md-table-cell">Funds</th><th>Status</th><th></th></tr>
    <?php foreach ($list as $t) { ?><tr><td class="ps-3 small text-nowrap"><?= fa_date($t['transfer_date']); ?><div class="text-muted">#<?= (int) $t['id']; ?> · <?= fa_e($t['reference']); ?></div></td>
        <td class="small"><?= fa_e($t['fname']); ?> → <?= fa_e($t['tname']); ?><div class="text-muted"><?= fa_e($t['description']); ?><?= $t['fee_transaction_id'] ? ' · fee: <a href="../transactions/view.php?id=' . (int) $t['fee_transaction_id'] . '">#' . (int) $t['fee_transaction_id'] . '</a>' : ''; ?> · <?= fa_e($t['uname']); ?></div></td>
        <td class="text-end fw-semibold"><?= number_format((float) $t['amount'], 0); ?></td>
        <td class="small d-none d-md-table-cell"><?= fa_e(implode(', ', array_map(function ($l) { return ($l['fund_id'] ? fa_fund_short((string) $l['name']) : 'Not allocated') . ' ' . number_format((float) $l['amount'], 0); }, $lineRows[(int) $t['id']] ?? []))); ?></td>
        <td><?= fa_status_pill($t['status']); ?><?= $t['status'] === 'Reversed' ? '<div class="small text-muted">' . fa_e($t['reversal_reason']) . '</div>' : ''; ?></td>
        <td class="text-end"><?php if ($can && $t['status'] === 'Posted') { ?><form method="POST" class="d-inline" onsubmit="var r=prompt('Reason for the reversal?');if(!r)return false;this.reason.value=r;"><?= fa_csrf_field(); ?><input type="hidden" name="do" value="reverse"><input type="hidden" name="id" value="<?= (int) $t['id']; ?>"><input type="hidden" name="reason"><button class="btn btn-sm btn-link text-danger py-0">Reverse</button></form><?php } ?></td></tr><?php } ?>
    <?php if (!$list) { ?><tr><td colspan="6" class="text-center text-muted py-5">No transfer yet.</td></tr><?php } ?>
</table></div></div></div></div>
</div>
<script>
(function () {
    var C = <?= json_encode($comp); ?>, from = document.getElementById('faFrom'), amt = document.getElementById('faAmt'); if (!from) return;
    var lines = document.querySelectorAll('.fa-line'), fmt = function (v) { return Math.round(v).toLocaleString(); };
    function hold() { var c = C[from.value] || {}, tot = 0; document.querySelectorAll('[data-hold]').forEach(function (s) { var v = c[s.getAttribute('data-hold')] || 0; tot += v; s.textContent = v ? '(holds ' + fmt(v) + ')' : ''; });
        document.getElementById('faHold').textContent = from.value ? 'The source account holds ' + fmt(tot) + ' in total.' : 'Choose the source account to see what it holds.'; }
    function sum() { var s = 0; lines.forEach(function (i) { s += parseFloat(i.value || 0); }); document.getElementById('faSum').textContent = fmt(s); var a = parseFloat(amt.value || 0);
        document.getElementById('faChk').innerHTML = a && Math.abs(s - a) < 0.01 ? '<span class="text-success">= amount ✓</span>' : (a ? '<span class="text-danger">must equal ' + fmt(a) + '</span>' : ''); }
    document.getElementById('faProp').addEventListener('click', function () { var c = C[from.value] || {}, tot = 0, a = parseFloat(amt.value || 0); for (var k in c) { if (c[k] > 0) tot += c[k]; } if (!tot || !a) { alert('Choose the source account and the amount first.'); return; }
        var done = 0, last = null; lines.forEach(function (i) { var v = c[i.getAttribute('data-fund')] || 0; if (v > 0) { var x = Math.floor(a * v / tot * 100) / 100; i.value = x.toFixed(2); done += x; last = i; } else { i.value = ''; } });
        if (last) last.value = (parseFloat(last.value) + (a - done)).toFixed(2); sum(); });
    from.addEventListener('change', hold); lines.forEach(function (i) { i.addEventListener('input', sum); }); amt.addEventListener('input', sum); hold(); sum();
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>