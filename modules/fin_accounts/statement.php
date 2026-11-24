<?php
/**
 * NEW FILE: modules/fin_accounts/statement.php — RECONCILIATION WORKSPACE of one imported bank statement.
 * MY MOTIVE compares the bank lines with the account's entries, suggests matches, and shows: unmatched bank lines,
 * entries in MY MOTIVE missing from the statement, amount / date differences, possible duplicates.
 * Actions per line: match · mark as bank fee (creates the Bank Charges expense) · create the missing transaction ·
 * flag for investigation · mark as duplicate · leave unresolved. Then submit for approval and complete.
 * Who imported, matched, resolved and approved each item is recorded.
 */
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/income_allocation_helpers.php';
require_once __DIR__ . '/../../includes/category_helpers.php';
fa_require($conn, 'reconcile');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$S = mysqli_fetch_assoc(mysqli_query($conn, "SELECT s.*, a.name aname FROM fa_statements s JOIN fa_accounts a ON a.id = s.account_id WHERE s.id = $id"));
if (!$S) { fa_go('reconcile.php', 'error', 'Statement not found.'); }
$self = 'statement.php?id=' . $id; $acc = (int) $S['account_id']; $u = fa_uid(); $isAdmin = current_user_role() === 'Admin';
$locked = in_array($S['status'], ['Submitted', 'Completed'], true);
$funds = fa_funds($conn);
$lineQ = function ($lid) use ($conn, $id) { return mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM fa_statement_lines WHERE id = " . (int) $lid . " AND statement_id = $id")); };
$set = function ($lid, $status, $key = null, $note = null, $tx = null) use ($conn, $u) {
    $now = date('Y-m-d H:i:s'); $s = mysqli_prepare($conn, 'UPDATE fa_statement_lines SET status = ?, match_key = ?, note = COALESCE(?, note), created_tx = COALESCE(?, created_tx), resolved_by = ?, resolved_at = ? WHERE id = ?');
    mysqli_stmt_bind_param($s, 'sssiisi', $status, $key, $note, $tx, $u, $now, $lid); mysqli_stmt_execute($s);
};
$from = date('Y-m-d', strtotime($S['period_from'] . ' -15 days')); $to = min(date('Y-m-d'), date('Y-m-d', strtotime($S['period_to'] . ' +15 days')));
$ledger = array_values(array_filter(fa_ledger($conn, $acc, $from, $to), function ($e) { return strpos($e['key'], 'open:') !== 0; })); $byKey = []; foreach ($ledger as $e) { $byKey[$e['key']] = $e; }
$used = []; foreach (fa_rows($conn, "SELECT l.match_key, l.statement_id, l.id FROM fa_statement_lines l JOIN fa_statements s ON s.id = l.statement_id WHERE s.account_id = $acc AND l.match_key IS NOT NULL") as $r) { $used[$r['match_key']] = (int) $r['id']; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fa_csrf_ok()) { fa_go($self, 'error', 'The page had expired.'); }
    $do = (string) ($_POST['do'] ?? '');
    try {
        if ($do === 'submit' || $do === 'approve' || $do === 'reopen') {
            $open = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM fa_statement_lines WHERE statement_id = $id AND status = 'Unmatched'"))[0];
            $now = date('Y-m-d H:i:s');
            if ($do === 'submit') { if ($locked) { throw new RuntimeException('Already submitted.'); } if ($open) { throw new RuntimeException($open . ' line(s) still need an action (match, fee, create, investigate, duplicate or leave unresolved).'); }
                mysqli_query($conn, "UPDATE fa_statements SET status = 'Submitted', submitted_by = " . (int) $u . ", submitted_at = '$now' WHERE id = $id"); fa_audit($conn, 'statement', $id, 'submitted', 'Submitted for approval');
                fa_go($self, 'success', 'Submitted for approval.'); }
            if (!fac($conn, 'approve')) { throw new RuntimeException('Only an approver can do this.'); }
            if ($do === 'approve') { if ($S['status'] !== 'Submitted') { throw new RuntimeException('Submit it first.'); } if ((int) $S['submitted_by'] === (int) $u && !$isAdmin) { throw new RuntimeException('Another person must approve your reconciliation.'); }
                mysqli_query($conn, "UPDATE fa_statements SET status = 'Completed', approved_by = " . (int) $u . ", approved_at = '$now' WHERE id = $id"); fa_audit($conn, 'statement', $id, 'completed', 'Approved and completed');
                fa_go($self, 'success', 'Reconciliation completed.'); }
            if ($do === 'reopen') { mysqli_query($conn, "UPDATE fa_statements SET status = 'In progress', approved_by = NULL, approved_at = NULL WHERE id = $id"); fa_audit($conn, 'statement', $id, 'reopened', mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 200)); fa_go($self, 'success', 'Reopened.'); }
        }
        if ($locked) { throw new RuntimeException('This reconciliation was submitted: reopen it to change it.'); }
        if ($do === 'automatch') {
            $lines = fa_rows($conn, "SELECT * FROM fa_statement_lines WHERE statement_id = $id AND status = 'Unmatched' ORDER BY line_date, id"); $n = 0;
            foreach ($lines as $l) { $sg = fa_suggest([$l], $ledger, $used)[(int) $l['id']] ?? [];
                if ($sg && $sg[0]['score'] >= 95 && (!isset($sg[1]) || $sg[1]['score'] < 95)) { $set((int) $l['id'], 'Matched', $sg[0]['key'], 'matched automatically (' . $sg[0]['why'] . ')'); $used[$sg[0]['key']] = (int) $l['id']; $n++; } }
            fa_audit($conn, 'statement', $id, 'automatch', $n . ' line(s) matched automatically (same amount, same or next day, only one candidate)');
            fa_go($self, 'success', $n . ' line(s) matched. Check the others one by one.');
        }
        $lid = (int) ($_POST['line'] ?? 0); $L = $lineQ($lid); if (!$L) { throw new RuntimeException('Line not found.'); }
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255) ?: null;
        if ($do === 'match') {
            $key = (string) ($_POST['key'] ?? ''); if (!isset($byKey[$key])) { throw new RuntimeException('Choose the MY MOTIVE entry.'); }
            if (isset($used[$key]) && $used[$key] !== $lid) { throw new RuntimeException('This entry is already matched to another bank line.'); }
            $diff = round((float) $L['amount'] - $byKey[$key]['amount'], 2); $dd = (int) round(abs(strtotime($byKey[$key]['date']) - strtotime($L['line_date'])) / 86400);
            $set($lid, 'Matched', $key, trim(($note ?? '') . ($diff ? ' amount difference ' . number_format($diff, 2) : '') . ($dd ? ' · date differs by ' . $dd . ' day(s)' : '')) ?: null);
            fa_audit($conn, 'statement_line', $lid, 'matched', 'Line ' . $L['line_no'] . ' ↔ ' . $key . ($diff ? ' (difference ' . $diff . ')' : ''));
        } elseif ($do === 'fee' || $do === 'create') {
            $amt = abs((float) $L['amount']); $desc = trim(($L['description'] ?: 'Bank statement line') . ($L['reference'] ? ' (' . $L['reference'] . ')' : ''));
            if ($L['line_date'] < (fa_account($conn, $acc)['opening_date'] ?? '')) { throw new RuntimeException('The line is before the opening date of the account.'); }
            mysqli_begin_transaction($conn);
            try {
                if ((float) $L['amount'] < 0) { // money out: an expense
                    $fund = fund_get($conn, (int) ($_POST['fund_id'] ?? 0)); if (!$fund) { throw new RuntimeException('Choose the fund charged.'); }
                    $cat = $do === 'fee' ? 'Bank Charges' : trim((string) ($_POST['category'] ?? ''));
                    if ($do === 'create' && !category_is_valid($conn, 'expense', $cat)) { throw new RuntimeException('Choose the expense category.'); }
                    $tx = fund_post_automatic_expense($conn, $fund['code'], 'Service', $amt, $L['line_date'], $desc, $u, $cat, 'FA_RECON', $lid);
                } else { // money in: an income, allocated to the funds by the policy
                    if ($do === 'fee') { throw new RuntimeException('Money received cannot be a bank fee: use "Create missing transaction".'); }
                    $type = 'Income'; $cat = 'Service'; $st = 'approved'; $t = mysqli_prepare($conn, 'INSERT INTO transactions (category, transaction_type, amount, transaction_date, description, recorded_by, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    mysqli_stmt_bind_param($t, 'ssdssis', $cat, $type, $amt, $L['line_date'], $desc, $u, $st); mysqli_stmt_execute($t); $tx = (int) mysqli_insert_id($conn);
                    allocate_income_transaction($conn, $tx, $amt, $u);
                }
                $ref = mb_substr((string) ($L['reference'] ?: 'Statement #' . $id . ' line ' . $L['line_no']), 0, 80);
                $s = mysqli_prepare($conn, 'UPDATE transactions SET account_id = ?, reference = ? WHERE id = ?'); mysqli_stmt_bind_param($s, 'isi', $acc, $ref, $tx); mysqli_stmt_execute($s);
                fa_sync($conn);
                $set($lid, $do === 'fee' ? 'Bank fee' : 'Created', 'tx:' . $tx, $note, $tx);
                fa_audit($conn, 'statement_line', $lid, $do === 'fee' ? 'bank_fee' : 'created', 'Line ' . $L['line_no'] . ': transaction #' . $tx . ' (' . ((float) $L['amount'] < 0 ? 'expense' : 'income') . ' ' . fa_money($amt) . ')');
                mysqli_commit($conn);
            } catch (Throwable $e) { mysqli_rollback($conn); throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage()); }
        } elseif (in_array($do, ['investigate', 'duplicate', 'unresolved', 'undo'], true)) {
            if ($do === 'undo' && $L['created_tx']) { throw new RuntimeException('A transaction (#' . $L['created_tx'] . ') was created for this line: reverse it in Transactions first.'); }
            if (in_array($do, ['investigate', 'unresolved'], true) && !$note) { throw new RuntimeException('Add a note.'); }
            $map = ['investigate' => 'Investigate', 'duplicate' => 'Duplicate', 'unresolved' => 'Unresolved', 'undo' => 'Unmatched'];
            $s = mysqli_prepare($conn, 'UPDATE fa_statement_lines SET status = ?, match_key = NULL, note = ?, resolved_by = ?, resolved_at = ? WHERE id = ?'); $st = $map[$do]; $now = date('Y-m-d H:i:s');
            mysqli_stmt_bind_param($s, 'ssisi', $st, $note, $u, $now, $lid); mysqli_stmt_execute($s);
            fa_audit($conn, 'statement_line', $lid, strtolower($st), 'Line ' . $L['line_no'] . ($note ? ': ' . $note : ''));
        }
        fa_go($self . '#l' . $lid, 'success', 'Line ' . $L['line_no'] . ' updated.');
    } catch (InsufficientFundException $e) { fa_go($self, 'error', $e->getMessage()); }
    catch (RuntimeException $e) { fa_go($self, 'error', $e->getMessage()); }
}
$lines = fa_rows($conn, "SELECT l.*, u.names uname FROM fa_statement_lines l LEFT JOIN users u ON u.id = l.resolved_by WHERE l.statement_id = $id ORDER BY l.line_date, l.line_no");
$sug = fa_suggest($lines, $ledger, $used);
$counts = array_count_values(array_column($lines, 'status'));
$inPeriod = array_filter($ledger, function ($e) use ($S) { return $e['date'] >= $S['period_from'] && $e['date'] <= $S['period_to'] && abs($e['amount']) >= 0.005; });
$missing = array_filter($inPeriod, function ($e) use ($used) { return !isset($used[$e['key']]); });
$sysOpen = fa_balance_at($conn, $acc, date('Y-m-d', strtotime($S['period_from'] . ' -1 day')));
$A0 = fa_account($conn, $acc); if ($A0 && $A0['opening_date'] >= $S['period_from'] && $A0['opening_date'] <= $S['period_to']) { $sysOpen += (float) $A0['opening_balance']; } // the account opened during the period
$sysClose = fa_balance_at($conn, $acc, $S['period_to']);
$bankIn = array_sum(array_map(function ($l) { return max(0, (float) $l['amount']); }, $lines)); $bankOut = -array_sum(array_map(function ($l) { return min(0, (float) $l['amount']); }, $lines));
$cats = categories_active($conn, 'expense');
$people = array_column(fa_rows($conn, 'SELECT id, names FROM users WHERE id IN (' . implode(',', array_filter([(int) $S['imported_by'], (int) $S['submitted_by'], (int) $S['approved_by'], 0])) . ')'), 'names', 'id');
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
fa_tabs($conn, 'reconcile.php', 'Reconciliation — ' . $S['aname']);
?>
<div class="row g-2 mb-3 small">
    <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <div class="d-flex justify-content-between"><strong><?= fa_date($S['period_from']); ?> – <?= fa_date($S['period_to']); ?></strong><?= fa_status_pill($S['status']); ?></div>
        <div class="text-muted"><?= fa_e($S['file_name']); ?> · imported by <?= fa_e($people[$S['imported_by']] ?? ''); ?> <?= fa_e(substr($S['imported_at'], 0, 16)); ?></div>
        <?php if ($S['submitted_by']) { ?><div class="text-muted">Submitted by <?= fa_e($people[$S['submitted_by']] ?? ''); ?> <?= fa_e(substr((string) $S['submitted_at'], 0, 16)); ?></div><?php } ?>
        <?php if ($S['approved_by']) { ?><div class="text-muted">Approved by <?= fa_e($people[$S['approved_by']] ?? ''); ?> <?= fa_e(substr((string) $S['approved_at'], 0, 16)); ?></div><?php } ?></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><table class="w-100">
        <tr><td></td><td class="text-end text-muted">Bank</td><td class="text-end text-muted">MY MOTIVE</td></tr>
        <tr><td>Opening</td><td class="text-end"><?= $S['opening_balance'] !== null ? number_format((float) $S['opening_balance'], 0) : '—'; ?></td><td class="text-end"><?= number_format($sysOpen, 0); ?></td></tr>
        <tr><td>Money in / out</td><td class="text-end"><?= number_format($bankIn, 0); ?> / <?= number_format($bankOut, 0); ?></td><td class="text-end"><?= number_format(array_sum(array_map(function ($e) { return max(0, $e['amount']); }, $inPeriod)), 0); ?> / <?= number_format(-array_sum(array_map(function ($e) { return min(0, $e['amount']); }, $inPeriod)), 0); ?></td></tr>
        <tr class="fw-semibold"><td>Closing</td><td class="text-end"><?= $S['closing_balance'] !== null ? number_format((float) $S['closing_balance'], 0) : '—'; ?></td><td class="text-end"><?= number_format($sysClose, 0); ?></td></tr>
        <?php if ($S['closing_balance'] !== null) { $dif = (float) $S['closing_balance'] - $sysClose; ?><tr><td colspan="3" class="<?= abs($dif) < 0.5 ? 'text-success' : 'text-danger'; ?>"><?= abs($dif) < 0.5 ? 'Balances agree ✓' : 'Difference: ' . number_format($dif, 0); ?></td></tr><?php } ?></table></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
        <?php foreach (['Unmatched', 'Matched', 'Bank fee', 'Created', 'Investigate', 'Duplicate', 'Unresolved'] as $k) { if (!empty($counts[$k])) { echo fa_status_pill($k) . ' ' . (int) $counts[$k] . ' &nbsp;'; } } ?>
        <div class="mt-1"><?= count($missing); ?> MY MOTIVE entr<?= count($missing) === 1 ? 'y' : 'ies'; ?> of the period not on the statement</div>
        <div class="d-flex flex-wrap gap-1 mt-2">
            <?php if (!$locked) { ?><form method="POST"><?= fa_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="automatch"><button class="btn btn-sm btn-outline-primary py-0">Match the obvious ones</button></form>
                <form method="POST" onsubmit="return confirm('Submit for approval?')"><?= fa_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="submit"><button class="btn btn-sm btn-primary py-0" <?= !empty($counts['Unmatched']) ? 'disabled title="Every line needs an action first"' : ''; ?>>Submit for approval</button></form><?php } ?>
            <?php if ($S['status'] === 'Submitted' && fac($conn, 'approve')) { ?><form method="POST"><?= fa_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="approve"><button class="btn btn-sm btn-success py-0">Approve &amp; complete</button></form><?php } ?>
            <?php if ($locked && fac($conn, 'approve')) { ?><form method="POST" onsubmit="var r=prompt('Reason for reopening?');if(!r)return false;this.reason.value=r;"><?= fa_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="reopen"><input type="hidden" name="reason"><button class="btn btn-sm btn-light border py-0">Reopen</button></form><?php } ?>
        </div></div></div></div>
</div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="p-2 px-3 fw-semibold small">Bank statement lines</div><div class="table-responsive"><table class="table table-sm mb-0 fa-tbl">
    <tr class="table-light"><th class="ps-3">Date</th><th>Bank description</th><th class="text-end">Amount</th><th>Status</th><th style="min-width:330px">MY MOTIVE / action</th></tr>
    <?php foreach ($lines as $l) { $lid = (int) $l['id']; $out = (float) $l['amount'] < 0; $m = $l['match_key'] && isset($byKey[$l['match_key']]) ? $byKey[$l['match_key']] : null; ?>
    <tr id="l<?= $lid; ?>" class="<?= $l['status'] === 'Unmatched' ? '' : 'text-muted'; ?>"><td class="ps-3 text-nowrap small"><?= fa_date($l['line_date']); ?><div class="text-muted">line <?= (int) $l['line_no']; ?></div></td>
        <td class="small"><?= fa_e($l['description']); ?><?= $l['reference'] ? '<div class="text-muted">' . fa_e($l['reference']) . '</div>' : ''; ?><?= $l['note'] ? '<div class="text-warning-emphasis">' . fa_e($l['note']) . '</div>' : ''; ?></td>
        <td class="text-end text-nowrap"><?= fa_amt((float) $l['amount']); ?></td><td><?= fa_status_pill($l['status']); ?><?= $l['uname'] && $l['status'] !== 'Unmatched' ? '<div class="small text-muted">' . fa_e($l['uname']) . '</div>' : ''; ?></td>
        <td class="small"><?php if ($m) { ?><?= fa_date($m['date']); ?> · <?= fa_e($m['type']); ?> · <?= fa_e(mb_strimwidth((string) $m['description'], 0, 50, '…')); ?> · <?= fa_amt($m['amount']); ?><?php } elseif ($l['created_tx']) { ?><a href="../transactions/view.php?id=<?= (int) $l['created_tx']; ?>">Transaction #<?= (int) $l['created_tx']; ?></a><?php } ?>
            <?php if (!$locked && $l['status'] === 'Unmatched') { foreach ($sug[$lid] ?? [] as $s) { ?><form method="POST" class="d-flex justify-content-between align-items-center gap-1 border rounded px-2 py-1 mb-1"><?= fa_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="line" value="<?= $lid; ?>"><input type="hidden" name="do" value="match"><input type="hidden" name="key" value="<?= fa_e($s['key']); ?>">
                <span><?= fa_date($s['date']); ?> · <?= fa_e(mb_strimwidth((string) $s['description'], 0, 40, '…')); ?> · <?= fa_amt($s['amount']); ?><div class="text-muted"><?= fa_e($s['why']); ?></div></span><button class="btn btn-sm btn-success py-0">Match</button></form><?php } ?>
            <details><summary class="text-primary">Other actions</summary><div class="mt-1 d-grid gap-1">
                <form method="POST" class="d-flex gap-1"><?= fa_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="line" value="<?= $lid; ?>"><input type="hidden" name="do" value="match">
                    <select name="key" class="form-select form-select-sm"><option value="">Match with another entry…</option><?php foreach ($ledger as $e) { if (isset($used[$e['key']]) || abs($e['amount']) < 0.005 || ($e['amount'] < 0) !== $out) { continue; } ?><option value="<?= fa_e($e['key']); ?>"><?= fa_e(date('d M', strtotime($e['date'])) . ' · ' . number_format($e['amount'], 0) . ' · ' . mb_strimwidth((string) $e['description'], 0, 40, '…')); ?></option><?php } ?></select><button class="btn btn-sm btn-light border py-0">Match</button></form>
                <form method="POST" class="d-flex gap-1"><?= fa_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="line" value="<?= $lid; ?>">
                    <?php if ($out) { ?><select name="fund_id" class="form-select form-select-sm" style="max-width:130px"><?php foreach ($funds as $f) { ?><option value="<?= (int) $f['id']; ?>" <?= $f['code'] === 'OPERATING' ? 'selected' : ''; ?>><?= fa_e(fa_fund_short($f['name'])); ?></option><?php } ?></select>
                        <select name="category" class="form-select form-select-sm" style="max-width:140px"><option value="">Category…</option><?php foreach ($cats as $c) { ?><option><?= fa_e($c); ?></option><?php } ?></select>
                        <button name="do" value="fee" class="btn btn-sm btn-light border py-0 text-nowrap">Bank fee</button><?php } ?>
                    <button name="do" value="create" class="btn btn-sm btn-light border py-0 text-nowrap" onclick="return confirm('Create the missing <?= $out ? 'expense' : 'income (allocated to the funds by the policy)'; ?> of <?= number_format(abs((float) $l['amount']), 0); ?>?')">Create missing <?= $out ? 'expense' : 'income'; ?></button></form>
                <form method="POST" class="d-flex gap-1"><?= fa_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="line" value="<?= $lid; ?>"><input name="note" class="form-control form-control-sm" placeholder="Note">
                    <button name="do" value="investigate" class="btn btn-sm btn-light border py-0">Investigate</button><button name="do" value="duplicate" class="btn btn-sm btn-light border py-0">Duplicate</button><button name="do" value="unresolved" class="btn btn-sm btn-light border py-0 text-nowrap">Leave unresolved</button></form>
            </div></details>
            <?php } elseif (!$locked) { ?><form method="POST" class="d-inline"><?= fa_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="line" value="<?= $lid; ?>"><input type="hidden" name="do" value="undo"><button class="btn btn-link btn-sm p-0">undo</button></form><?php } ?></td></tr>
    <?php } ?>
</table></div></div></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="p-2 px-3 fw-semibold small">In MY MOTIVE but not on the statement (<?= count($missing); ?>)</div><div class="table-responsive"><table class="table table-sm mb-0 fa-tbl">
    <?php foreach ($missing as $e) { ?><tr><td class="ps-3 small text-nowrap"><?= fa_date($e['date']); ?></td><td class="small"><?= fa_e($e['type']); ?> · <?= $e['tx'] ? '<a href="../transactions/view.php?id=' . (int) $e['tx'] . '">' . fa_e($e['description']) . '</a>' : fa_e($e['description']); ?><div class="text-muted"><?= fa_e($e['source']); ?></div></td><td class="text-end"><?= fa_amt($e['amount']); ?></td><td class="small text-muted">Not yet cleared by the bank, recorded in the wrong account, or a recording error.</td></tr><?php } ?>
    <?php if (!$missing) { ?><tr><td class="text-center text-muted py-3 small">Every MY MOTIVE entry of the period is on the statement.</td></tr><?php } ?>
</table></div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>