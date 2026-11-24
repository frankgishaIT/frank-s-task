<?php
/**
 * NEW FILE: modules/fin_accounts/accounts.php — FINANCIAL ACCOUNTS (Admin-managed): add, edit, view, deactivate.
 * Fields: name, type (Bank, Mobile Money, Cash, Other), bank / provider, account number (masked for people without
 * the identifiers permission), currency, opening balance and date (with its fund composition when known),
 * custodian, status, notes. An account with history is never deleted: it is deactivated.
 */
require __DIR__ . '/common.php';
fa_require($conn, 'view');
$canManage = fac($conn, 'manage'); $full = fac($conn, 'identifiers');
$funds = fa_funds($conn);
$hasHistory = function (int $id) use ($conn) {
    foreach (["SELECT 1 FROM fa_movement_accounts WHERE account_id = $id", "SELECT 1 FROM fa_transfers WHERE from_account = $id OR to_account = $id", "SELECT 1 FROM fa_adjustments WHERE account_id = $id", "SELECT 1 FROM fa_statements WHERE account_id = $id", "SELECT 1 FROM transactions WHERE account_id = $id"] as $q) {
        if (mysqli_fetch_row(mysqli_query($conn, $q . ' LIMIT 1'))) { return true; } }
    return false;
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!fa_csrf_ok()) { fa_go('accounts.php', 'error', 'The page had expired.'); }
    if (!$canManage) { fa_go('accounts.php', 'error', 'Only Administrators manage financial accounts.'); }
    $do = (string) ($_POST['do'] ?? ''); $id = (int) ($_POST['id'] ?? 0); $now = date('Y-m-d H:i:s');
    try {
        if ($do === 'status') {
            $a = fa_account($conn, $id); if (!$a) { throw new RuntimeException('Not found.'); }
            $new = $a['status'] === 'Active' ? 'Inactive' : 'Active';
            if ($new === 'Inactive' && abs(fa_balance_at($conn, $id)) >= 0.5 && empty($_POST['confirm'])) { throw new RuntimeException($a['name'] . ' still holds ' . fa_money(fa_balance_at($conn, $id)) . ': transfer the money first, or confirm.'); }
            mysqli_query($conn, "UPDATE fa_accounts SET status = '$new', updated_at = '$now' WHERE id = $id");
            if ($new === 'Inactive') { mysqli_query($conn, "DELETE FROM fa_rules WHERE account_id = $id"); }
            fa_audit($conn, 'account', $id, $new === 'Active' ? 'activated' : 'deactivated', $a['name'] . ($new === 'Inactive' ? ' (its assignment rules were removed)' : ''));
            fa_go('accounts.php', 'success', $a['name'] . ': ' . $new . '.');
        }
        if ($do === 'delete') {
            $a = fa_account($conn, $id); if (!$a) { throw new RuntimeException('Not found.'); }
            if ($hasHistory($id)) { throw new RuntimeException('This account has history: deactivate it instead.'); }
            mysqli_query($conn, "DELETE FROM fa_opening_lines WHERE account_id = $id"); mysqli_query($conn, "DELETE FROM fa_rules WHERE account_id = $id"); mysqli_query($conn, "DELETE FROM fa_accounts WHERE id = $id");
            fa_audit($conn, 'account', $id, 'deleted', $a['name'] . ' (no history)');
            fa_go('accounts.php', 'success', 'Deleted.');
        }
        // save (add / edit)
        $t = function ($k, $n) { return mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $n); };
        $name = $t('name', 120); $type = in_array($_POST['type'] ?? '', FA_TYPES, true) ? $_POST['type'] : '';
        if ($name === '' || $type === '') { throw new RuntimeException('Give the account name and type.'); }
        $dup = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM fa_accounts WHERE name = '" . mysqli_real_escape_string($conn, $name) . "' AND id <> $id"));
        if ($dup) { throw new RuntimeException('Another account already has this name.'); }
        $prov = $t('provider', 120) ?: null; $cur = strtoupper($t('currency', 8)) ?: 'RWF'; $notes = $t('notes', 2000) ?: null; $cust = (int) ($_POST['custodian_id'] ?? 0) ?: null;
        $old = $id ? fa_account($conn, $id) : null; if ($id && !$old) { throw new RuntimeException('Not found.'); }
        $ident = $t('identifier', 80); if ($old && !$full && $ident === fa_mask($old['identifier'], false)) { $ident = (string) $old['identifier']; } $ident = $ident !== '' ? $ident : null;
        $ob = trim((string) ($_POST['opening_balance'] ?? '')); $od = (string) ($_POST['opening_date'] ?? '');
        if (!is_numeric($ob) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $od) || $od > date('Y-m-d')) { throw new RuntimeException('Enter the opening balance and a valid opening date (not in the future).'); }
        $ob = round((float) $ob, 2);
        $lines = []; $sum = 0; foreach ((array) ($_POST['open'] ?? []) as $f => $v) { $v = trim((string) $v); if ($v === '' || !is_numeric($v)) { continue; } $v = round((float) $v, 2); if (abs($v) < 0.005) { continue; } $lines[(int) $f] = $v; $sum += $v; }
        if ($sum > $ob + 0.005 && $ob >= 0) { throw new RuntimeException('The fund composition (' . fa_money($sum) . ') is more than the opening balance (' . fa_money($ob) . ').'); }
        $locked = $old && $hasHistory($id) && ((float) $old['opening_balance'] !== $ob || $old['opening_date'] !== $od);
        if ($locked) { throw new RuntimeException('The opening balance and date cannot change once the account has activity: record an adjustment instead.'); }
        $u = fa_uid();
        if ($old) {
            $s = mysqli_prepare($conn, 'UPDATE fa_accounts SET name = ?, type = ?, provider = ?, identifier = ?, currency = ?, opening_balance = ?, opening_date = ?, custodian_id = ?, notes = ?, updated_at = ? WHERE id = ?');
            mysqli_stmt_bind_param($s, 'sssssdsissi', $name, $type, $prov, $ident, $cur, $ob, $od, $cust, $notes, $now, $id); mysqli_stmt_execute($s);
            $chg = []; foreach (['name' => $name, 'type' => $type, 'provider' => $prov, 'currency' => $cur, 'opening_balance' => $ob, 'opening_date' => $od, 'custodian_id' => $cust, 'notes' => $notes] as $k => $v) { if ((string) $old[$k] !== (string) $v && !($k === 'opening_balance' && (float) $old[$k] === (float) $v)) { $chg[] = $k; } }
            if ((string) $old['identifier'] !== (string) $ident) { $chg[] = 'account number'; }
        } else {
            $s = mysqli_prepare($conn, 'INSERT INTO fa_accounts (name, type, provider, identifier, currency, opening_balance, opening_date, custodian_id, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'sssssdsisi', $name, $type, $prov, $ident, $cur, $ob, $od, $cust, $notes, $u); mysqli_stmt_execute($s); $id = (int) mysqli_insert_id($conn); $chg = ['created'];
        }
        $prevLines = array_column(fa_rows($conn, "SELECT fund_id, amount FROM fa_opening_lines WHERE account_id = $id"), 'amount', 'fund_id');
        mysqli_query($conn, "DELETE FROM fa_opening_lines WHERE account_id = $id");
        foreach ($lines as $f => $v) { $s = mysqli_prepare($conn, 'INSERT INTO fa_opening_lines (account_id, fund_id, amount) VALUES (?, ?, ?)'); mysqli_stmt_bind_param($s, 'iid', $id, $f, $v); mysqli_stmt_execute($s); }
        if (array_map('floatval', $prevLines) != array_map('floatval', $lines)) { $chg[] = 'opening fund composition'; }
        $min = mysqli_fetch_row(mysqli_query($conn, 'SELECT MIN(opening_date) FROM fa_accounts'))[0];
        $ts = fa_tracking_start($conn); if (!$ts || $min < $ts) { fa_setting_set($conn, 'tracking_start', $min); fa_audit($conn, 'settings', null, 'tracking_start', 'Money movements are linked to accounts from ' . $min); }
        fa_audit($conn, 'account', $id, $old ? 'edited' : 'created', $name . ($chg ? ': ' . implode(', ', $chg) : '') . ' · opening ' . fa_money($ob) . ' on ' . $od);
        fa_go('accounts.php', 'success', $old ? 'Saved.' : 'Account added.');
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
}
$M = fa_matrix($conn); $bal = fa_balances($M);
$list = fa_accounts($conn);
$edit = null; if (isset($_GET['edit'])) { $edit = fa_account($conn, (int) $_GET['edit']); } if (isset($_GET['new']) || !empty($err)) { $edit = $edit ?: ['id' => (int) ($_POST['id'] ?? 0)]; }
$users = fa_rows($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names');
// money each fund held just before a date (to help fill the opening composition)
$fundAt = function (string $date) use ($conn) {
    return array_column(fa_rows($conn, "SELECT m.fund_id, SUM(CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END) v FROM fund_movements m LEFT JOIN fund_movements o ON o.id = m.reverses_movement_id
        WHERE m.created_at < '" . mysqli_real_escape_string($conn, $date) . " 00:00:00' AND " . fa_cash_sql() . ' GROUP BY m.fund_id'), 'v', 'fund_id');
};
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
fa_tabs($conn, 'accounts.php', 'Financial accounts', $canManage ? '<a href="accounts.php?new=1" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add an account</a>' : '');
?>
<?php if (!empty($err)) { ?><div class="alert alert-danger py-2 small"><?= fa_e($err); ?></div><?php } ?>
<?php if ($edit !== null && $canManage) { $E = $edit + ['name' => '', 'type' => 'Bank', 'provider' => '', 'identifier' => '', 'currency' => 'RWF', 'opening_balance' => '', 'opening_date' => fa_tracking_start($conn) ?: date('Y-m-d'), 'custodian_id' => '', 'notes' => ''];
    if (!empty($err)) { foreach ($E as $k => $v) { if (isset($_POST[$k]) && !is_array($_POST[$k])) { $E[$k] = $_POST[$k]; } } }
    $ol = !empty($err) ? (array) ($_POST['open'] ?? []) : array_column(fa_rows($conn, 'SELECT fund_id, amount FROM fa_opening_lines WHERE account_id = ' . (int) $E['id']), 'amount', 'fund_id');
    $hist = $E['id'] ? $hasHistory((int) $E['id']) : false; $fa = $fundAt((string) $E['opening_date']); ?>
<form method="POST" class="card border-0 shadow-sm mb-3" style="max-width:900px"><div class="card-body row g-2 small"><?= fa_csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $E['id']; ?>"><input type="hidden" name="do" value="save">
    <div class="col-12 fw-semibold"><?= $E['id'] ? 'Edit ' . fa_e($E['name']) : 'New financial account'; ?></div>
    <div class="col-md-5"><label class="form-label">Account name</label><input name="name" class="form-control form-control-sm" maxlength="120" required value="<?= fa_e($E['name']); ?>" placeholder="e.g. Equity Bank — main"></div>
    <div class="col-6 col-md-3"><label class="form-label">Type</label><select name="type" class="form-select form-select-sm"><?php foreach (FA_TYPES as $x) { ?><option <?= $E['type'] === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-4"><label class="form-label">Bank / provider</label><input name="provider" class="form-control form-control-sm" maxlength="120" value="<?= fa_e($E['provider']); ?>" placeholder="e.g. Equity Bank Rwanda, MTN"></div>
    <div class="col-md-5"><label class="form-label">Account number / identifier</label><input name="identifier" class="form-control form-control-sm" maxlength="80" value="<?= fa_e(fa_mask($E['identifier'], $full)); ?>" autocomplete="off"></div>
    <div class="col-6 col-md-2"><label class="form-label">Currency</label><input name="currency" class="form-control form-control-sm" maxlength="8" value="<?= fa_e($E['currency']); ?>"></div>
    <div class="col-6 col-md-5"><label class="form-label">Custodian (responsible person)</label><select name="custodian_id" class="form-select form-select-sm"><option value="">—</option><?php foreach ($users as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= (int) $E['custodian_id'] === (int) $u['id'] ? 'selected' : ''; ?>><?= fa_e($u['names']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label">Opening balance</label><input type="number" step="0.01" name="opening_balance" class="form-control form-control-sm" required value="<?= fa_e($E['opening_balance']); ?>" <?= $hist ? 'readonly' : ''; ?>></div>
    <div class="col-6 col-md-3"><label class="form-label">Opening date</label><input type="date" name="opening_date" class="form-control form-control-sm" required max="<?= date('Y-m-d'); ?>" value="<?= fa_e($E['opening_date']); ?>" <?= $hist ? 'readonly' : ''; ?>></div>
    <div class="col-md-6 text-muted align-self-end"><?= $hist ? 'The account has activity: correct its balance with an adjustment.' : 'The money in the account on that date, as on the bank statement / cash count.'; ?></div>
    <div class="col-12 mt-2"><div class="fw-semibold">Opening balance by fund <span class="fw-normal text-muted">(if known — what is not split stays "not allocated to a fund"; MY MOTIVE never guesses it)</span></div>
        <div class="row g-2 mt-1"><?php foreach ($funds as $f) { ?><div class="col-6 col-md"><label class="form-label mb-0"><?= fa_e(fa_fund_short($f['name'])); ?></label><input type="number" step="0.01" name="open[<?= (int) $f['id']; ?>]" class="form-control form-control-sm" value="<?= fa_e($ol[$f['id']] ?? ''); ?>" <?= $hist ? 'readonly' : ''; ?>>
            <div class="text-muted" style="font-size:11px">fund money then: <?= fa_money($fa[$f['id']] ?? 0); ?></div></div><?php } ?></div></div>
    <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" rows="2" maxlength="2000" class="form-control form-control-sm"><?= fa_e($E['notes']); ?></textarea></div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-primary btn-sm px-4">Save</button><a href="accounts.php" class="btn btn-light border btn-sm">Cancel</a></div>
</div></form>
<?php } ?>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0 fa-tbl">
    <tr class="table-light"><th class="ps-3">Account</th><th>Type</th><th class="d-none d-md-table-cell">Number</th><th class="text-end">Balance now</th><th class="d-none d-lg-table-cell">Opening</th><th class="d-none d-lg-table-cell">Custodian</th><th>Status</th><th></th></tr>
    <?php foreach ($list as $a) { $id = (int) $a['id']; ?><tr>
        <td class="ps-3"><a href="account.php?id=<?= $id; ?>" class="fw-semibold text-decoration-none"><?= fa_e($a['name']); ?></a><div class="small text-muted"><?= fa_e($a['provider']); ?><?= $a['currency'] !== 'RWF' ? ' · ' . fa_e($a['currency']) : ''; ?></div></td>
        <td class="small"><?= fa_e($a['type']); ?></td><td class="d-none d-md-table-cell small font-monospace"><?= fa_e(fa_mask($a['identifier'], $full)); ?></td>
        <td class="text-end fw-semibold"><?= fa_money($bal[$id] ?? 0); ?></td><td class="d-none d-lg-table-cell small"><?= fa_money($a['opening_balance']); ?> · <?= fa_date($a['opening_date']); ?></td>
        <td class="d-none d-lg-table-cell small"><?= fa_e($a['custodian']); ?></td><td><?= fa_status_pill($a['status']); ?></td>
        <td class="text-end text-nowrap"><?php if ($canManage) { ?><a href="accounts.php?edit=<?= $id; ?>" class="btn btn-sm btn-light border py-0">Edit</a>
            <form method="POST" class="d-inline" onsubmit="return confirm('<?= $a['status'] === 'Active' ? 'Deactivate' : 'Reactivate'; ?> this account?')"><?= fa_csrf_field(); ?><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= $id; ?>"><?php if ($a['status'] === 'Active' && abs($bal[$id] ?? 0) >= 0.5) { ?><input type="hidden" name="confirm" value="1"><?php } ?><button class="btn btn-sm btn-light border py-0"><?= $a['status'] === 'Active' ? 'Deactivate' : 'Reactivate'; ?></button></form>
            <?php if (!$hasHistory($id)) { ?><form method="POST" class="d-inline" onsubmit="return confirm('Delete this account? It has no history.')"><?= fa_csrf_field(); ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $id; ?>"><button class="btn btn-sm btn-link text-danger py-0">Delete</button></form><?php } } ?></td></tr><?php } ?>
    <?php if (!$list) { ?><tr><td colspan="8" class="text-center text-muted py-5">No financial account yet. <?= $canManage ? '<a href="accounts.php?new=1">Add the first one</a> (e.g. Equity Bank, BK, Mobile Money, Cash on Hand).' : ''; ?></td></tr><?php } ?>
</table></div></div></div>
<?php if (!$full) { ?><div class="small text-muted mt-2">Account numbers are masked. Authorised roles see them in full.</div><?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>