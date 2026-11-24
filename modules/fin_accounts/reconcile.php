<?php
/**
 * NEW FILE: modules/fin_accounts/reconcile.php — BANK STATEMENT RECONCILIATION: list + IMPORT.
 * 1 account → 2 statement period → 3 upload (CSV or Excel .xlsx) → 4 map the columns → 5 preview → 6 validate →
 * then the reconciliation workspace (statement.php). There is no live bank connection: statements are imported files.
 */
require __DIR__ . '/common.php';
fa_require($conn, 'reconcile');
$accounts = fa_accounts($conn, true);
$step = 1; $err = null; $imp = $_SESSION['fa_import'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fa_csrf_ok()) { fa_go('reconcile.php', 'error', 'The page had expired.'); }
    $do = (string) ($_POST['do'] ?? '');
    try {
        if ($do === 'upload') {
            $acc = (int) ($_POST['account_id'] ?? 0); $A = fa_account($conn, $acc); if (!$A || $A['status'] !== 'Active') { throw new RuntimeException('Choose the account.'); }
            $pf = (string) ($_POST['period_from'] ?? ''); $pt = (string) ($_POST['period_to'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pf) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pt) || $pt < $pf) { throw new RuntimeException('Enter the statement period.'); }
            if (empty($_FILES['file']['tmp_name']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK || $_FILES['file']['size'] > 5 * 1024 * 1024) { throw new RuntimeException('Upload the statement (CSV or Excel, max 5 MB).'); }
            $rows = fa_read_statement($_FILES['file']['tmp_name'], (string) $_FILES['file']['name']);
            if (count($rows) < 2) { throw new RuntimeException('The file has no lines.'); }
            if (count($rows) > 3000) { throw new RuntimeException('More than 3,000 lines: import the statement in smaller periods.'); }
            $_SESSION['fa_import'] = $imp = ['account' => $acc, 'from' => $pf, 'to' => $pt, 'name' => mb_substr((string) $_FILES['file']['name'], 0, 160), 'rows' => $rows];
            $step = 2;
        }
        if (in_array($do, ['preview', 'import'], true)) {
            if (!$imp) { throw new RuntimeException('Upload the statement again.'); }
            $map = []; foreach (['date', 'description', 'reference', 'amount', 'debit', 'credit', 'balance'] as $k) { $v = $_POST['col'][$k] ?? ''; $map[$k] = $v === '' ? null : (int) $v; }
            $hdr = !empty($_POST['header']); $fmt = in_array($_POST['datefmt'] ?? '', ['auto', 'dmy', 'mdy'], true) ? $_POST['datefmt'] : 'auto';
            if ($map['date'] === null || ($map['amount'] === null && $map['debit'] === null && $map['credit'] === null)) { throw new RuntimeException('Choose at least the date column and the amount (or debit / credit) columns.'); }
            $parsed = []; $errors = []; $seen = [];
            foreach ($imp['rows'] as $i => $r) {
                if ($hdr && $i === 0) { continue; }
                $cell = function ($k) use ($map, $r) { return $map[$k] === null ? '' : (string) ($r[$map[$k]] ?? ''); };
                $d = fa_parse_date($cell('date'), $fmt);
                if ($map['amount'] !== null) { $a = fa_parse_amount($cell('amount')); }
                else { $dr = fa_parse_amount($cell('debit')); $cr = fa_parse_amount($cell('credit')); $a = ($dr === null && $cr === null) ? null : (float) ($cr ?? 0) - abs((float) ($dr ?? 0)); }
                if ($a === null && $map['balance'] !== null && fa_parse_amount($cell('balance')) !== null) { // a balance-only row (opening / brought forward): not a movement
                    if (!$parsed && !isset($stmtOpening)) { $stmtOpening = fa_parse_amount($cell('balance')); } continue; }
                if ($d === null && $a === null && trim(implode('', $r)) !== '') { if (count($parsed) === 0 || preg_match('/total|balance|opening|closing/i', implode(' ', $r))) { continue; } }
                $flag = [];
                if ($d === null) { $flag[] = 'date not understood'; } if ($a === null) { $flag[] = 'amount not understood'; }
                if ($d && ($d < $imp['from'] || $d > $imp['to'])) { $flag[] = 'outside the period'; }
                $key = $d . '|' . $a . '|' . mb_strtolower(trim($cell('description')));
                if ($d && $a !== null && isset($seen[$key])) { $flag[] = 'possible duplicate of line ' . $seen[$key]; } $seen[$key] = $i + 1;
                $parsed[] = ['n' => $i + 1, 'date' => $d, 'description' => mb_substr($cell('description'), 0, 255), 'reference' => mb_substr($cell('reference'), 0, 120), 'amount' => $a, 'balance' => $map['balance'] !== null ? fa_parse_amount($cell('balance')) : null, 'flag' => $flag];
                if ($d === null || $a === null) { $errors[] = $i + 1; }
            }
            if (!$parsed) { throw new RuntimeException('No line could be read with this mapping.'); }
            $_SESSION['fa_import']['map'] = $map; $_SESSION['fa_import']['header'] = $hdr; $_SESSION['fa_import']['datefmt'] = $fmt; $imp = $_SESSION['fa_import'];
            if ($do === 'preview' || $errors) { $step = 3; if ($do === 'import' && $errors) { $err = count($errors) . ' line(s) could not be read (lines ' . implode(', ', array_slice($errors, 0, 10)) . '): fix the mapping or the file.'; } }
            else {
                // earlier imports of the same account: possible duplicates
                $prev = []; foreach (fa_rows($conn, 'SELECT l.line_date, l.amount, l.description FROM fa_statement_lines l JOIN fa_statements s ON s.id = l.statement_id WHERE s.account_id = ' . (int) $imp['account']) as $p) { $prev[$p['line_date'] . '|' . round((float) $p['amount'], 2) . '|' . mb_strtolower(trim((string) $p['description']))] = true; }
                $u = fa_uid(); $ob = is_numeric($_POST['opening_balance'] ?? '') ? (float) $_POST['opening_balance'] : ($stmtOpening ?? null); $cb = is_numeric($_POST['closing_balance'] ?? '') ? (float) $_POST['closing_balance'] : null;
                if ($cb === null) { $last = end($parsed); if ($last['balance'] !== null) { $cb = $last['balance']; } }
                mysqli_begin_transaction($conn);
                $s = mysqli_prepare($conn, 'INSERT INTO fa_statements (account_id, period_from, period_to, file_name, opening_balance, closing_balance, imported_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($s, 'isssddi', $imp['account'], $imp['from'], $imp['to'], $imp['name'], $ob, $cb, $u); mysqli_stmt_execute($s); $sid = (int) mysqli_insert_id($conn);
                $n = 0; $dups = 0;
                foreach ($parsed as $p) {
                    $note = $p['flag'] ? implode('; ', $p['flag']) : null; $st = 'Unmatched';
                    if (isset($prev[$p['date'] . '|' . round($p['amount'], 2) . '|' . mb_strtolower(trim($p['description']))])) { $note = trim(($note ? $note . '; ' : '') . 'already in an earlier imported statement'); $dups++; }
                    $n++; $s = mysqli_prepare($conn, 'INSERT INTO fa_statement_lines (statement_id, line_no, line_date, description, reference, amount, balance, status, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    mysqli_stmt_bind_param($s, 'iisssddss', $sid, $p['n'], $p['date'], $p['description'], $p['reference'], $p['amount'], $p['balance'], $st, $note); mysqli_stmt_execute($s);
                }
                fa_audit($conn, 'statement', $sid, 'imported', (fa_account($conn, (int) $imp['account'])['name'] ?? '') . ' ' . $imp['from'] . ' → ' . $imp['to'] . ': ' . $n . ' line(s) from ' . $imp['name'] . ($dups ? ', ' . $dups . ' possibly imported before' : ''));
                mysqli_commit($conn);
                unset($_SESSION['fa_import']);
                fa_go('statement.php?id=' . $sid, 'success', $n . ' line(s) imported. MY MOTIVE suggests matches below.');
            }
        }
        if ($do === 'cancel') { unset($_SESSION['fa_import']); fa_go('reconcile.php', 'success', 'Import cancelled.'); }
    } catch (RuntimeException $e) { $err = $e->getMessage(); if (in_array($do, ['preview', 'import'], true) && $imp) { $step = 2; } }
}
if ($step === 1 && isset($_GET['map']) && $imp) { $step = 2; }
$list = fa_rows($conn, "SELECT s.*, a.name aname, u.names uname, (SELECT COUNT(*) FROM fa_statement_lines l WHERE l.statement_id = s.id) n, (SELECT COUNT(*) FROM fa_statement_lines l WHERE l.statement_id = s.id AND l.status IN ('Unmatched', 'Investigate')) open_n
    FROM fa_statements s JOIN fa_accounts a ON a.id = s.account_id LEFT JOIN users u ON u.id = s.imported_by ORDER BY s.id DESC LIMIT 200");
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
fa_tabs($conn, 'reconcile.php', 'Bank reconciliation');
?>
<?php if ($err) { ?><div class="alert alert-danger py-2 small"><?= fa_e($err); ?></div><?php } ?>
<?php if ($step === 1) { ?>
<div class="row g-3"><div class="col-xl-4"><form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm"><div class="card-body small row g-2"><?= fa_csrf_field(); ?><input type="hidden" name="do" value="upload">
    <div class="col-12 fw-semibold">Import a bank statement</div>
    <div class="col-12"><label class="form-label">1. Account</label><select name="account_id" class="form-select form-select-sm" required><option value="">Choose…</option><?php foreach ($accounts as $a) { ?><option value="<?= (int) $a['id']; ?>"><?= fa_e($a['name']); ?></option><?php } ?></select></div>
    <div class="col-6"><label class="form-label">2. Period from</label><input type="date" name="period_from" class="form-control form-control-sm" required value="<?= date('Y-m-01', strtotime('first day of last month')); ?>"></div>
    <div class="col-6"><label class="form-label">to</label><input type="date" name="period_to" class="form-control form-control-sm" required value="<?= date('Y-m-t', strtotime('last day of last month')); ?>"></div>
    <div class="col-12"><label class="form-label">3. Statement file (CSV or Excel .xlsx)</label><input type="file" name="file" accept=".csv,.txt,.xlsx" class="form-control form-control-sm" required></div>
    <div class="col-12"><button class="btn btn-primary btn-sm px-4">Next: map the columns</button></div>
    <div class="col-12 text-muted">Download the statement from your bank / mobile money portal. MY MOTIVE has no live connection to banks.</div>
</div></form></div>
<div class="col-xl-8"><div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0 fa-tbl">
    <tr class="table-light"><th class="ps-3">Account</th><th>Period</th><th class="text-end">Lines</th><th class="text-end">To resolve</th><th>Status</th><th class="d-none d-md-table-cell">Imported</th></tr>
    <?php foreach ($list as $s) { ?><tr style="cursor:pointer" onclick="location.href='statement.php?id=<?= (int) $s['id']; ?>'"><td class="ps-3"><a href="statement.php?id=<?= (int) $s['id']; ?>" class="fw-semibold text-decoration-none"><?= fa_e($s['aname']); ?></a><div class="small text-muted"><?= fa_e($s['file_name']); ?></div></td>
        <td class="small"><?= fa_date($s['period_from']); ?> – <?= fa_date($s['period_to']); ?></td><td class="text-end"><?= (int) $s['n']; ?></td><td class="text-end"><?= (int) $s['open_n'] ?: '<span class="text-success">0</span>'; ?></td><td><?= fa_status_pill($s['status']); ?></td><td class="small d-none d-md-table-cell"><?= fa_e($s['uname']); ?><div class="text-muted"><?= fa_e(substr($s['imported_at'], 0, 16)); ?></div></td></tr><?php } ?>
    <?php if (!$list) { ?><tr><td colspan="6" class="text-center text-muted py-5">No statement imported yet.</td></tr><?php } ?>
</table></div></div></div></div></div>
<?php } else { $rows = $imp['rows']; $cols = max(array_map('count', array_slice($rows, 0, 30))); $head = $rows[0];
    $guess = function ($re) use ($head) { foreach ($head as $i => $h) { if (preg_match($re, (string) $h)) { return $i; } } return null; };
    $m = $imp['map'] ?? ['date' => $guess('/date/i'), 'description' => $guess('/desc|narrat|detail|particular|memo/i'), 'reference' => $guess('/ref|cheque|transaction id|txn/i'), 'amount' => $guess('/^amount$/i'), 'debit' => $guess('/debit|withdraw|money out|paid out/i'), 'credit' => $guess('/credit|deposit|money in|paid in/i'), 'balance' => $guess('/balance/i')];
    $opt = function ($k) use ($m, $cols, $head) { $o = '<select name="col[' . $k . ']" class="form-select form-select-sm"><option value="">—</option>'; for ($i = 0; $i < $cols; $i++) { $o .= '<option value="' . $i . '"' . ($m[$k] !== null && (int) $m[$k] === $i ? ' selected' : '') . '>Column ' . ($i + 1) . ': ' . fa_e(mb_strimwidth((string) ($head[$i] ?? ''), 0, 22, '…')) . '</option>'; } return $o . '</select>'; }; ?>
<form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body small row g-2"><?= fa_csrf_field(); ?>
    <div class="col-12 fw-semibold">4. Map the columns — <?= fa_e(fa_account($conn, (int) $imp['account'])['name'] ?? ''); ?>, <?= fa_date($imp['from']); ?> – <?= fa_date($imp['to']); ?> (<?= fa_e($imp['name']); ?>, <?= count($rows); ?> rows)</div>
    <?php foreach (['date' => 'Date', 'description' => 'Description', 'reference' => 'Reference', 'amount' => 'Amount (+ in / − out)', 'debit' => 'or Debit (money out)', 'credit' => 'and Credit (money in)', 'balance' => 'Balance (optional)'] as $k => $l) { ?><div class="col-6 col-md-3"><label class="form-label mb-0"><?= $l; ?></label><?= $opt($k); ?></div><?php } ?>
    <div class="col-6 col-md-3"><label class="form-label mb-0">Date format</label><select name="datefmt" class="form-select form-select-sm"><?php foreach (['auto' => 'Detect', 'dmy' => 'Day/Month/Year', 'mdy' => 'Month/Day/Year'] as $k => $l) { ?><option value="<?= $k; ?>" <?= ($imp['datefmt'] ?? 'auto') === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label mb-0">Statement opening balance</label><input type="number" step="0.01" name="opening_balance" class="form-control form-control-sm" value="<?= fa_e($_POST['opening_balance'] ?? ''); ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label mb-0">Statement closing balance</label><input type="number" step="0.01" name="closing_balance" class="form-control form-control-sm" value="<?= fa_e($_POST['closing_balance'] ?? ''); ?>" placeholder="from the last line if empty"></div>
    <div class="col-12"><label><input type="checkbox" name="header" value="1" class="form-check-input me-1" <?= ($imp['header'] ?? true) ? 'checked' : ''; ?>>The first row contains the column titles</label></div>
    <div class="col-12 d-flex gap-2"><button name="do" value="preview" class="btn btn-outline-primary btn-sm">5. Preview and validate</button><button name="do" value="import" class="btn btn-primary btn-sm">6. Import</button><button name="do" value="cancel" class="btn btn-light border btn-sm" formnovalidate>Cancel</button></div>
</div></form>
<?php if ($step === 3 && !empty($parsed)) { $bad = array_filter($parsed, function ($p) { return $p['flag']; }); ?>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="p-2 px-3 small"><strong><?= count($parsed); ?></strong> line(s) read · in <?= fa_money(array_sum(array_map(function ($p) { return max(0, (float) $p['amount']); }, $parsed))); ?> · out <?= fa_money(array_sum(array_map(function ($p) { return min(0, (float) $p['amount']); }, $parsed)) * -1); ?> · <span class="<?= $bad ? 'text-danger' : 'text-success'; ?>"><?= count($bad); ?> line(s) with a remark</span></div>
    <div class="table-responsive" style="max-height:460px"><table class="table table-sm mb-0 fa-tbl"><tr class="table-light"><th class="ps-3">Line</th><th>Date</th><th>Description</th><th>Reference</th><th class="text-end">Amount</th><th class="text-end">Balance</th><th>Remark</th></tr>
        <?php foreach (array_slice($parsed, 0, 400) as $p) { ?><tr class="<?= $p['flag'] ? 'table-warning' : ''; ?>"><td class="ps-3"><?= (int) $p['n']; ?></td><td class="text-nowrap"><?= $p['date'] ? fa_date($p['date']) : '<span class="text-danger">?</span>'; ?></td><td><?= fa_e($p['description']); ?></td><td><?= fa_e($p['reference']); ?></td><td class="text-end"><?= $p['amount'] === null ? '<span class="text-danger">?</span>' : fa_amt($p['amount']); ?></td><td class="text-end"><?= $p['balance'] !== null ? number_format($p['balance'], 0) : ''; ?></td><td class="small text-danger"><?= fa_e(implode('; ', $p['flag'])); ?></td></tr><?php } ?>
    </table></div></div></div>
<?php } else { ?>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="p-2 px-3 small fw-semibold">First rows of the file</div><div class="table-responsive"><table class="table table-sm mb-0 fa-tbl">
    <?php foreach (array_slice($rows, 0, 8) as $r) { ?><tr><?php for ($i = 0; $i < $cols; $i++) { ?><td class="small"><?= fa_e($r[$i] ?? ''); ?></td><?php } ?></tr><?php } ?></table></div></div></div>
<?php } } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>