<?php
/**
 * NEW FILE: includes/fin_accounts.php — FINANCIAL ACCOUNTS & FUND TRACKING (Task 2).
 *
 * Two separate dimensions:
 *   - FUNDS (existing): the PURPOSE of the money (Operating 75 %, Future Plans 20 %, Emergency 3 %, Team Growth 2 %,
 *     Capital). Their balances keep coming from the existing ledger (fund_movements) — nothing is copied or changed.
 *   - FINANCIAL ACCOUNTS (new): WHERE the money is held (Equity Bank, BK, Mobile Money, Cash on Hand…), managed by Admins.
 *
 * How money reaches an account (no second ledger is created):
 *   - Each money movement of the fund ledger since the tracking start date is linked to one account (fa_movement_accounts):
 *       · Income / expenses of the Transactions module: the account chosen on the transaction;
 *       · automatic postings (sales, purchase orders, payroll, loans, investors, credit hub, assets…): the default
 *         account an Admin set for that source (Assignment rules);
 *       · a reversal: the account of the entry it reverses.
 *     When no rule applies, the movement stays "Not yet assigned" for an authorised person: MY MOTIVE never guesses.
 *   - Opening balance of each account (with its fund composition, when known) covers the history before tracking.
 *   - TRANSFERS between accounts move money AND its fund composition: total money and fund totals do not change.
 *     The bank fee is a separate Expense transaction (Bank Charges) with its account and fund.
 *   - ADJUSTMENTS (Admin, with reason) correct an account; reversals instead of deletion everywhere.
 * Value of stock and assets (non-cash parts of the RM Capital Fund) is never money in an account.
 */
require_once __DIR__ . '/fund_helpers.php';

const FA_TYPES = ['Bank', 'Mobile Money', 'Cash', 'Other'];
const FA_SOURCES = [
    'transaction' => 'Transactions (income / expense) — the account chosen on the transaction',
    'manual' => 'Transactions recorded before accounts existed (no account chosen)',
    'sales' => 'Sales receipts', 'purchases' => 'Purchase order payments', 'payroll' => 'Payroll and rewards', 'company_loans' => 'RM Loan Intelligence (company loans)',
    'credit_hub' => 'RMC - Credit Hub (customer loans)', 'investors' => 'RMC - Investors Hub', 'capital' => 'Owner capital (capital inflows)', 'assets' => 'Asset purchases / sales',
    'fund_transfers' => 'Transfers between funds', 'other' => 'Other automatic postings',
];

function fa_q(mysqli $conn, string $sql) { return mysqli_query($conn, $sql); }
function fa_rows(mysqli $conn, string $sql): array { return mysqli_fetch_all(mysqli_query($conn, $sql), MYSQLI_ASSOC); }
function fa_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function fa_money($v): string { return $v === null ? '—' : 'RWF ' . number_format((float) $v, 0); }

function fa_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $T = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    fa_q($conn, "CREATE TABLE IF NOT EXISTS fa_accounts (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(120) NOT NULL, type VARCHAR(20) NOT NULL, provider VARCHAR(120) NULL, identifier VARCHAR(80) NULL,
        currency VARCHAR(8) NOT NULL DEFAULT 'RWF', opening_balance DECIMAL(16,2) NOT NULL DEFAULT 0, opening_date DATE NOT NULL, custodian_id INT(11) NULL, status VARCHAR(10) NOT NULL DEFAULT 'Active',
        notes TEXT NULL, created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL, PRIMARY KEY (id)) $T");
    fa_q($conn, "CREATE TABLE IF NOT EXISTS fa_opening_lines (account_id INT(11) NOT NULL, fund_id INT(11) NOT NULL, amount DECIMAL(16,2) NOT NULL, PRIMARY KEY (account_id, fund_id)) $T");
    fa_q($conn, "CREATE TABLE IF NOT EXISTS fa_movement_accounts (movement_id INT(11) NOT NULL, account_id INT(11) NOT NULL, rule VARCHAR(30) NOT NULL, assigned_by INT(11) NULL,
        assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (movement_id), KEY acc (account_id)) $T");
    fa_q($conn, "CREATE TABLE IF NOT EXISTS fa_rules (source VARCHAR(40) NOT NULL, account_id INT(11) NOT NULL, PRIMARY KEY (source)) $T");
    fa_q($conn, "CREATE TABLE IF NOT EXISTS fa_transfers (id INT(11) NOT NULL AUTO_INCREMENT, from_account INT(11) NOT NULL, to_account INT(11) NOT NULL, amount DECIMAL(16,2) NOT NULL, transfer_date DATE NOT NULL,
        reference VARCHAR(80) NULL, description VARCHAR(255) NULL, fee_transaction_id INT(11) NULL, status VARCHAR(10) NOT NULL DEFAULT 'Posted', created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reversed_by INT(11) NULL, reversed_at DATETIME NULL, reversal_reason VARCHAR(255) NULL, PRIMARY KEY (id)) $T");
    fa_q($conn, "CREATE TABLE IF NOT EXISTS fa_transfer_lines (transfer_id INT(11) NOT NULL, fund_id INT(11) NOT NULL, amount DECIMAL(16,2) NOT NULL, PRIMARY KEY (transfer_id, fund_id)) $T");
    fa_q($conn, "CREATE TABLE IF NOT EXISTS fa_adjustments (id INT(11) NOT NULL AUTO_INCREMENT, account_id INT(11) NOT NULL, fund_id INT(11) NULL, direction VARCHAR(3) NOT NULL, amount DECIMAL(16,2) NOT NULL,
        adj_date DATE NOT NULL, reason VARCHAR(255) NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'Posted', created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reversed_by INT(11) NULL, reversed_at DATETIME NULL, reversal_reason VARCHAR(255) NULL, PRIMARY KEY (id)) $T");
    fa_q($conn, "CREATE TABLE IF NOT EXISTS fa_statements (id INT(11) NOT NULL AUTO_INCREMENT, account_id INT(11) NOT NULL, period_from DATE NOT NULL, period_to DATE NOT NULL, file_name VARCHAR(160) NULL,
        opening_balance DECIMAL(16,2) NULL, closing_balance DECIMAL(16,2) NULL, status VARCHAR(12) NOT NULL DEFAULT 'In progress', imported_by INT(11) NULL, imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        submitted_by INT(11) NULL, submitted_at DATETIME NULL, approved_by INT(11) NULL, approved_at DATETIME NULL, notes TEXT NULL, PRIMARY KEY (id)) $T");
    fa_q($conn, "CREATE TABLE IF NOT EXISTS fa_statement_lines (id INT(11) NOT NULL AUTO_INCREMENT, statement_id INT(11) NOT NULL, line_no INT(11) NOT NULL, line_date DATE NOT NULL, description VARCHAR(255) NULL,
        reference VARCHAR(120) NULL, amount DECIMAL(16,2) NOT NULL, balance DECIMAL(16,2) NULL, status VARCHAR(14) NOT NULL DEFAULT 'Unmatched', match_key VARCHAR(60) NULL, created_tx INT(11) NULL,
        note VARCHAR(255) NULL, resolved_by INT(11) NULL, resolved_at DATETIME NULL, PRIMARY KEY (id), KEY st (statement_id)) $T");
    fa_q($conn, "CREATE TABLE IF NOT EXISTS fa_settings (k VARCHAR(40) NOT NULL, v VARCHAR(255) NULL, PRIMARY KEY (k)) $T");
    fa_q($conn, "CREATE TABLE IF NOT EXISTS fa_audit (id INT(11) NOT NULL AUTO_INCREMENT, entity VARCHAR(20) NOT NULL, entity_id INT(11) NULL, action VARCHAR(30) NOT NULL, details TEXT NULL, user_id INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY en (entity, entity_id)) $T");
    try { // the Transactions module identifies the account and a reference (two optional columns, added once)
        $c = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transactions' AND column_name = 'account_id'"));
        if ($c && !(int) $c[0]) { fa_q($conn, 'ALTER TABLE transactions ADD COLUMN account_id INT(11) NULL, ADD COLUMN reference VARCHAR(80) NULL'); }
    } catch (Throwable $e) { error_log('fa_ensure: ' . $e->getMessage()); }
}

function fa_uid(): ?int { return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null; }

function fa_audit(mysqli $conn, string $entity, ?int $id, string $action, string $details = ''): void
{
    $u = fa_uid(); $s = mysqli_prepare($conn, 'INSERT INTO fa_audit (entity, entity_id, action, details, user_id) VALUES (?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'sissi', $entity, $id, $action, $details, $u); mysqli_stmt_execute($s);
}

function fa_setting(mysqli $conn, string $k, string $d = ''): string
{
    $r = mysqli_fetch_row(mysqli_query($conn, "SELECT v FROM fa_settings WHERE k = '" . mysqli_real_escape_string($conn, $k) . "'"));
    return $r ? (string) $r[0] : $d;
}
function fa_setting_set(mysqli $conn, string $k, string $v): void
{
    $s = mysqli_prepare($conn, 'DELETE FROM fa_settings WHERE k = ?'); mysqli_stmt_bind_param($s, 's', $k); mysqli_stmt_execute($s);
    $s = mysqli_prepare($conn, 'INSERT INTO fa_settings (k, v) VALUES (?, ?)'); mysqli_stmt_bind_param($s, 'ss', $k, $v); mysqli_stmt_execute($s);
}

/** The date from which money movements are linked to accounts (earlier money = opening balances). Set with the first account. */
function fa_tracking_start(mysqli $conn): ?string { $v = fa_setting($conn, 'tracking_start'); return $v !== '' ? $v : null; }

function fa_accounts(mysqli $conn, bool $activeOnly = false): array
{
    return fa_rows($conn, 'SELECT a.*, u.names custodian FROM fa_accounts a LEFT JOIN users u ON u.id = a.custodian_id' . ($activeOnly ? " WHERE a.status = 'Active'" : '') . " ORDER BY a.status = 'Active' DESC, a.name");
}
function fa_account(mysqli $conn, int $id): ?array { return mysqli_fetch_assoc(mysqli_query($conn, "SELECT a.*, u.names custodian FROM fa_accounts a LEFT JOIN users u ON u.id = a.custodian_id WHERE a.id = $id")) ?: null; }
function fa_has_accounts(mysqli $conn): bool { return (bool) mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM fa_accounts WHERE status = 'Active' LIMIT 1")); }

/** Masks an account number for people without the identifiers permission: ••••1234. */
function fa_mask(?string $id, bool $full): string
{
    $id = (string) $id; if ($id === '' || $full) { return $id; }
    return strlen($id) <= 4 ? '••••' : '••••' . substr($id, -4);
}

/* ---------------------------------------------------------------- linking the fund ledger to accounts ---------------------------------------------------------------- */

/** SQL: a movement that is MONEY (not the value of stock or assets) — same rule as fund_cash_balance(). Uses aliases m (movement) and o (reversed movement). */
function fa_cash_sql(): string
{
    $eff = "(CASE WHEN m.movement_type = 'REVERSAL' THEN o.movement_type ELSE m.movement_type END)";
    return "NOT (COALESCE(m.ref_type, '') = 'ASSET' OR COALESCE($eff, '') IN ('STOCK_IN', 'STOCK_OUT') OR COALESCE(m.ref_type, '') IN ('STOCK_OPENING', 'STOCK_RECONCILE')
        OR (COALESCE($eff, '') = 'CAPITAL_INFLOW' AND COALESCE(m.ref_type, '') = 'PO'))";
}

/** Which source a movement comes from (for the default-account rules). */
function fa_source_of(array $m): string
{
    $rt = (string) $m['ref_type']; $mt = (string) $m['movement_type'];
    if (in_array($mt, ['FUND_TRANSFER', 'TRANSFER_IN', 'TRANSFER_OUT'], true)) { return 'fund_transfers'; }
    if (in_array($rt, ['SALE', 'SALE_COST'], true)) { return 'sales'; }
    if ($rt === 'PO') { return 'purchases'; }
    if (in_array($rt, ['PAYROLL', 'PAYROLL_PAID', 'REWARD'], true)) { return 'payroll'; }
    if (in_array($rt, ['LOAN', 'LOAN_TX'], true) || strpos($rt, 'PAYMENT_') === 0) { return 'company_loans'; }
    if (strpos($rt, 'CL_') === 0) { return 'credit_hub'; }
    if (strpos($rt, 'INV_') === 0) { return 'investors'; }
    if (strpos($rt, 'ASSET') === 0) { return 'assets'; }
    if (strpos($rt, 'TX_') === 0 || ($rt === '' && !empty($m['transaction_id']) && $mt !== 'CAPITAL_INFLOW')) { return 'manual'; }
    if (strpos($rt, 'CAPITAL') === 0 || $mt === 'CAPITAL_INFLOW') { return 'capital'; }
    return 'other';
}

/**
 * Links every money movement since the tracking start to its account, by the rules above (idempotent: only
 * movements not linked yet). Returns how many were linked now.
 */
function fa_sync(mysqli $conn): int
{
    $start = fa_tracking_start($conn); if (!$start) { return 0; }
    $rules = []; foreach (fa_rows($conn, "SELECT r.source, r.account_id FROM fa_rules r JOIN fa_accounts a ON a.id = r.account_id AND a.status = 'Active'") as $r) { $rules[$r['source']] = (int) $r['account_id']; }
    $txAcc = function (?int $tx) use ($conn) { if (!$tx) { return null; } $r = mysqli_fetch_row(mysqli_query($conn, "SELECT account_id FROM transactions WHERE id = $tx")); return $r && $r[0] ? (int) $r[0] : null; };
    $saleMethod = function (?int $sid) use ($conn) { if (!$sid) { return ''; } $r = mysqli_fetch_row(mysqli_query($conn, "SELECT payment_method FROM sales WHERE id = $sid")); return $r ? (string) $r[0] : ''; };
    // (dated by the transaction date when there is one: an entry recorded later but dated before the start is covered by the opening balances)
    $rows = fa_rows($conn, "SELECT m.*, o.movement_type o_type FROM fund_movements m LEFT JOIN fund_movements o ON o.id = m.reverses_movement_id LEFT JOIN fa_movement_accounts x ON x.movement_id = m.id LEFT JOIN transactions t ON t.id = m.transaction_id
        WHERE x.movement_id IS NULL AND m.created_at >= '" . mysqli_real_escape_string($conn, $start) . " 00:00:00' AND " . fa_mdate_sql() . " >= '" . mysqli_real_escape_string($conn, $start) . "' AND " . fa_cash_sql() . ' ORDER BY m.id LIMIT 5000');
    $n = 0;
    foreach ($rows as $m) {
        $acc = null; $rule = '';
        if ($m['movement_type'] === 'REVERSAL' && $m['reverses_movement_id']) {
            $o = mysqli_fetch_row(mysqli_query($conn, 'SELECT account_id FROM fa_movement_accounts WHERE movement_id = ' . (int) $m['reverses_movement_id']));
            if ($o) { $acc = (int) $o[0]; $rule = 'reversal'; }
            else { $orig = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM fund_movements WHERE id = ' . (int) $m['reverses_movement_id'])); if ($orig) { $m['ref_type'] = $orig['ref_type']; $m['movement_type'] = $orig['movement_type']; $m['transaction_id'] = $orig['transaction_id'] ?: $m['transaction_id']; } }
        }
        if (!$acc && ($a = $txAcc($m['transaction_id'] ? (int) $m['transaction_id'] : (($m['ref_type'] === 'TX_INCOME') ? (int) $m['ref_id'] : null)))) { $acc = $a; $rule = 'transaction'; }
        if (!$acc) {
            $src = fa_source_of($m);
            if ($src === 'sales') { $pm = $saleMethod((int) $m['ref_id']); if ($pm !== '' && isset($rules['sales:' . $pm])) { $acc = $rules['sales:' . $pm]; $rule = 'sales:' . $pm; } }
            if (!$acc && isset($rules[$src])) { $acc = $rules[$src]; $rule = $src; }
        }
        if ($acc) { $s = mysqli_prepare($conn, 'INSERT IGNORE INTO fa_movement_accounts (movement_id, account_id, rule) VALUES (?, ?, ?)'); $mid = (int) $m['id']; mysqli_stmt_bind_param($s, 'iis', $mid, $acc, $rule); mysqli_stmt_execute($s); $n++; }
    }
    return $n;
}

/** Money movements since the tracking start that no rule could link to an account. */
function fa_unassigned(mysqli $conn, int $limit = 500): array
{
    $start = fa_tracking_start($conn); if (!$start) { return []; }
    return fa_rows($conn, "SELECT m.*, f.name fund, t.transaction_date, t.description tdesc FROM fund_movements m LEFT JOIN fund_movements o ON o.id = m.reverses_movement_id LEFT JOIN fa_movement_accounts x ON x.movement_id = m.id
        JOIN funds f ON f.id = m.fund_id LEFT JOIN transactions t ON t.id = m.transaction_id
        WHERE x.movement_id IS NULL AND m.created_at >= '" . mysqli_real_escape_string($conn, $start) . " 00:00:00' AND " . fa_mdate_sql() . " >= '" . mysqli_real_escape_string($conn, $start) . "' AND " . fa_cash_sql() . " ORDER BY m.id DESC LIMIT $limit");
}

/* ---------------------------------------------------------------- balances ---------------------------------------------------------------- */

/** Date expression of a linked movement: the transaction date when there is one, otherwise the day it was recorded. */
function fa_mdate_sql(): string { return 'COALESCE(t.transaction_date, DATE(m.created_at))'; }

/**
 * The account × fund MATRIX as at a date (null = today): opening composition + linked movements + transfers
 * (composition moved) + adjustments. Column 0 = money of the account not allocated to a fund (opening balance not
 * split, adjustments without a fund). Returns [account_id => [fund_id|0 => amount]].
 */
function fa_matrix(mysqli $conn, ?string $asOf = null): array
{
    $asOf = $asOf ?: date('Y-m-d'); $M = [];
    $add = function ($a, $f, $v) use (&$M) { $a = (int) $a; $f = (int) $f; $M[$a][$f] = ($M[$a][$f] ?? 0) + (float) $v; };
    $acc = fa_rows($conn, 'SELECT * FROM fa_accounts');
    foreach ($acc as $a) {
        if ($a['opening_date'] > $asOf) { continue; }
        $lines = fa_rows($conn, 'SELECT fund_id, amount FROM fa_opening_lines WHERE account_id = ' . (int) $a['id']); $sum = 0;
        foreach ($lines as $l) { $add($a['id'], $l['fund_id'], $l['amount']); $sum += (float) $l['amount']; }
        if (abs((float) $a['opening_balance'] - $sum) >= 0.005) { $add($a['id'], 0, (float) $a['opening_balance'] - $sum); }
    }
    $d = fa_mdate_sql(); $q = mysqli_real_escape_string($conn, $asOf);
    foreach (fa_rows($conn, "SELECT x.account_id, m.fund_id, SUM(CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END) v FROM fa_movement_accounts x JOIN fund_movements m ON m.id = x.movement_id
        LEFT JOIN transactions t ON t.id = m.transaction_id JOIN fa_accounts a ON a.id = x.account_id WHERE $d <= '$q' AND $d >= a.opening_date GROUP BY x.account_id, m.fund_id") as $r) { $add($r['account_id'], $r['fund_id'], $r['v']); }
    foreach (fa_rows($conn, "SELECT t.from_account, t.to_account, l.fund_id, l.amount FROM fa_transfers t JOIN fa_transfer_lines l ON l.transfer_id = t.id WHERE t.status = 'Posted' AND t.transfer_date <= '$q'") as $r) {
        $add($r['from_account'], $r['fund_id'], -$r['amount']); $add($r['to_account'], $r['fund_id'], $r['amount']); }
    foreach (fa_rows($conn, "SELECT account_id, fund_id, direction, amount FROM fa_adjustments WHERE status = 'Posted' AND adj_date <= '$q'") as $r) { $add($r['account_id'], $r['fund_id'] ?: 0, $r['direction'] === 'IN' ? $r['amount'] : -$r['amount']); }
    return $M;
}

function fa_balances(array $M): array { $b = []; foreach ($M as $a => $row) { $b[$a] = array_sum($row); } return $b; }

/** Fund composition of one account now (for transfers): [fund_id|0 => amount]. */
function fa_composition(mysqli $conn, int $accountId, ?string $asOf = null): array { return fa_matrix($conn, $asOf)[$accountId] ?? []; }

/**
 * The account's ENTRIES (grouped as they happened: one sale, one expense, one transfer…) between two dates — for the
 * account page, the activity list and bank reconciliation. Each: key, date, amount (+ in / − out), type, description, reference, status.
 */
function fa_ledger(mysqli $conn, int $accountId, string $from, string $to, int $fundId = 0): array
{
    $ff = $fundId ? ' AND m.fund_id = ' . $fundId : '';
    $f = mysqli_real_escape_string($conn, $from); $t2 = mysqli_real_escape_string($conn, $to); $d = fa_mdate_sql(); $out = [];
    foreach (fa_rows($conn, "SELECT CASE WHEN m.transaction_id IS NOT NULL THEN CONCAT('tx:', m.transaction_id) WHEN m.ref_type IS NOT NULL THEN CONCAT('ref:', m.ref_type, ':', COALESCE(m.ref_id, 0), ':', $d) ELSE CONCAT('mv:', m.id) END k,
        MIN($d) dt, SUM(CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END) v, MIN(m.description) descr, MIN(m.ref_type) rt, MIN(m.movement_type) mt, MIN(m.transaction_id) tx, MIN(x.rule) rule, COUNT(*) n
        FROM fa_movement_accounts x JOIN fund_movements m ON m.id = x.movement_id LEFT JOIN transactions t ON t.id = m.transaction_id
        WHERE x.account_id = $accountId AND $d BETWEEN '$f' AND '$t2'$ff GROUP BY k") as $r) {
        if (abs((float) $r['v']) < 0.005) { continue; }
        $tx = $r['tx'] ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT transaction_type, expense_category, description, reference, status FROM transactions WHERE id = ' . (int) $r['tx'])) : null;
        $type = $tx ? $tx['transaction_type'] : ($r['mt'] === 'REVERSAL' ? 'Reversal' : ((float) $r['v'] >= 0 ? 'Income' : 'Expense'));
        if ($tx && $tx['expense_category'] === 'Bank Charges') { $type = 'Transfer fee / bank charge'; }
        $out[] = ['key' => $r['k'], 'date' => $r['dt'], 'amount' => round((float) $r['v'], 2), 'type' => $type, 'source' => fa_rule_label((string) $r['rule']),
            'description' => $tx ? trim(($tx['expense_category'] ? $tx['expense_category'] . ' — ' : '') . $tx['description']) : $r['descr'], 'reference' => $tx['reference'] ?? '', 'category' => $tx['expense_category'] ?? '', 'tx' => $r['tx'] ? (int) $r['tx'] : null, 'status' => 'Posted'];
    }
    $tl = function ($tid) use ($conn, $fundId) { return $fundId ? (float) (mysqli_fetch_row(mysqli_query($conn, "SELECT amount FROM fa_transfer_lines WHERE transfer_id = $tid AND fund_id = $fundId"))[0] ?? 0) : null; };
    foreach (fa_rows($conn, "SELECT t.*, fa.name fname, ta.name tname FROM fa_transfers t JOIN fa_accounts fa ON fa.id = t.from_account JOIN fa_accounts ta ON ta.id = t.to_account WHERE (t.from_account = $accountId OR t.to_account = $accountId) AND t.transfer_date BETWEEN '$f' AND '$t2'") as $r) {
        $outg = (int) $r['from_account'] === $accountId; if ($fundId) { $r['amount'] = $tl((int) $r['id']); if ($r['amount'] <= 0) { continue; } }
        $out[] = ['key' => 'tr:' . $r['id'] . ($outg ? ':out' : ':in'), 'date' => $r['transfer_date'], 'amount' => $r['status'] === 'Posted' ? ($outg ? -1 : 1) * (float) $r['amount'] : 0, 'type' => $outg ? 'Transfer out' : 'Transfer in', 'source' => 'Transfer #' . $r['id'],
            'description' => ($outg ? 'To ' . $r['tname'] : 'From ' . $r['fname']) . ($r['description'] ? ' — ' . $r['description'] : ''), 'reference' => $r['reference'], 'category' => '', 'tx' => null, 'status' => $r['status'], 'gross' => ($outg ? -1 : 1) * (float) $r['amount']];
    }
    foreach (fa_rows($conn, "SELECT * FROM fa_adjustments WHERE account_id = $accountId AND adj_date BETWEEN '$f' AND '$t2'" . ($fundId ? " AND fund_id = $fundId" : '')) as $r) {
        $out[] = ['key' => 'adj:' . $r['id'], 'date' => $r['adj_date'], 'amount' => $r['status'] === 'Posted' ? ($r['direction'] === 'IN' ? 1 : -1) * (float) $r['amount'] : 0, 'type' => 'Adjustment', 'source' => 'Adjustment #' . $r['id'],
            'description' => $r['reason'], 'reference' => '', 'category' => '', 'tx' => null, 'status' => $r['status'], 'gross' => ($r['direction'] === 'IN' ? 1 : -1) * (float) $r['amount']];
    }
    $A = mysqli_fetch_assoc(mysqli_query($conn, "SELECT opening_date, opening_balance FROM fa_accounts WHERE id = $accountId"));
    if ($A && $A['opening_date'] >= $from && $A['opening_date'] <= $to) { // the opening balance, as the first entry
        $ov = $fundId ? (float) (mysqli_fetch_row(mysqli_query($conn, "SELECT amount FROM fa_opening_lines WHERE account_id = $accountId AND fund_id = $fundId"))[0] ?? 0) : (float) $A['opening_balance'];
        if (abs($ov) >= 0.005) { array_unshift($out, ['key' => 'open:' . $accountId, 'date' => $A['opening_date'], 'amount' => $ov, 'type' => 'Opening balance', 'source' => 'Account opened', 'description' => 'Opening balance', 'reference' => '', 'category' => '', 'tx' => null, 'status' => 'Posted']); }
    }
    usort($out, function ($a, $b) { return [$a['date'], strpos($a['key'], 'open:') === 0 ? 0 : 1, $a['key']] <=> [$b['date'], strpos($b['key'], 'open:') === 0 ? 0 : 1, $b['key']]; });
    return $out;
}

/** Readable name of the rule that linked an entry to its account. */
function fa_rule_label(string $rule): string
{
    if ($rule === 'transaction') { return 'Transactions'; } if ($rule === 'manual') { return 'Linked by hand'; } if ($rule === 'reversal') { return 'Reversal'; }
    if (strpos($rule, 'sales:') === 0) { return 'Sales paid by ' . substr($rule, 6); }
    return preg_replace('/\s+—.*$/', '', FA_SOURCES[$rule] ?? $rule);
}

/** Balance of one account at the END of a date (null = today). */
function fa_balance_at(mysqli $conn, int $accountId, ?string $date = null): float { return array_sum(fa_matrix($conn, $date)[$accountId] ?? []); }

/* ---------------------------------------------------------------- transfers ---------------------------------------------------------------- */

/**
 * Records a transfer between two accounts with its fund composition ($lines fund_id => amount, must add up to the
 * amount and not exceed what each fund has in the source account) and an optional bank fee (separate Expense
 * transaction "Bank Charges" from the source account and the fund chosen). Returns the transfer id.
 */
function fa_transfer(mysqli $conn, int $from, int $to, float $amount, string $date, string $ref, string $desc, array $lines, float $fee = 0, int $feeFund = 0): int
{
    $amount = round($amount, 2);
    if ($from === $to) { throw new RuntimeException('Choose two different accounts.'); }
    $A = fa_account($conn, $from); $B = fa_account($conn, $to);
    if (!$A || !$B || $A['status'] !== 'Active' || $B['status'] !== 'Active') { throw new RuntimeException('Both accounts must be active.'); }
    if ($amount <= 0) { throw new RuntimeException('Enter the amount transferred.'); }
    if ($date > date('Y-m-d') || $date < $A['opening_date'] || $date < $B['opening_date']) { throw new RuntimeException('The date must be after both opening dates and not in the future.'); }
    $comp = fa_composition($conn, $from, $date); $bal = array_sum($comp);
    if ($amount > $bal + 0.005) { throw new RuntimeException($A['name'] . ' holds only ' . fa_money($bal) . ' on that date.'); }
    $sum = 0; $clean = [];
    foreach ($lines as $f => $v) { $v = round((float) $v, 2); if ($v <= 0) { continue; } $f = (int) $f;
        if ($v > ($comp[$f] ?? 0) + 0.005) { throw new RuntimeException('The source account holds only ' . fa_money($comp[$f] ?? 0) . ' of ' . ($f ? (fund_get($conn, $f)['name'] ?? 'this fund') : 'money not allocated to a fund') . '.'); }
        $clean[$f] = $v; $sum += $v; }
    if (abs($sum - $amount) >= 0.01) { throw new RuntimeException('The fund composition (' . fa_money($sum) . ') must add up to the amount transferred (' . fa_money($amount) . ').'); }
    $u = fa_uid();
    mysqli_begin_transaction($conn);
    try {
        $feeTx = null;
        if ($fee > 0) {
            $fund = fund_get($conn, $feeFund); if (!$fund) { throw new RuntimeException('Choose the fund that pays the transfer fee.'); }
            $feeTx = fund_post_automatic_expense($conn, $fund['code'], 'Service', round($fee, 2), $date, 'Transfer fee: ' . $A['name'] . ' → ' . $B['name'] . ($ref ? ' (' . $ref . ')' : ''), $u, 'Bank Charges', 'FA_FEE', null);
            $s = mysqli_prepare($conn, 'UPDATE transactions SET account_id = ?, reference = ? WHERE id = ?'); mysqli_stmt_bind_param($s, 'isi', $from, $ref, $feeTx); mysqli_stmt_execute($s);
        }
        $s = mysqli_prepare($conn, 'INSERT INTO fa_transfers (from_account, to_account, amount, transfer_date, reference, description, fee_transaction_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'iidsssii', $from, $to, $amount, $date, $ref, $desc, $feeTx, $u); mysqli_stmt_execute($s); $id = (int) mysqli_insert_id($conn);
        foreach ($clean as $f => $v) { $s = mysqli_prepare($conn, 'INSERT INTO fa_transfer_lines (transfer_id, fund_id, amount) VALUES (?, ?, ?)'); mysqli_stmt_bind_param($s, 'iid', $id, $f, $v); mysqli_stmt_execute($s); }
        if ($feeTx) { fa_sync($conn); }
        fa_audit($conn, 'transfer', $id, 'created', $A['name'] . ' → ' . $B['name'] . ': ' . fa_money($amount) . ($fee > 0 ? ', fee ' . fa_money($fee) . ' (transaction #' . $feeTx . ')' : '') . ($ref ? ', ref ' . $ref : ''));
        mysqli_commit($conn);
        return $id;
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
}

/* ---------------------------------------------------------------- statement import ---------------------------------------------------------------- */

/** Reads a CSV or Excel (.xlsx) bank statement into rows of cells (first sheet). */
function fa_read_statement(string $path, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') {
        $raw = (string) file_get_contents($path); $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $first = strtok($raw, "\n"); $delim = substr_count((string) $first, ';') > substr_count((string) $first, ',') ? ';' : (substr_count((string) $first, "\t") > substr_count((string) $first, ',') ? "\t" : ',');
        $rows = []; $h = fopen('php://memory', 'r+'); fwrite($h, $raw); rewind($h);
        while (($r = fgetcsv($h, 0, $delim)) !== false) { if (array_filter($r, function ($c) { return trim((string) $c) !== ''; })) { $rows[] = array_map('trim', $r); } }
        return $rows;
    }
    if ($ext === 'xlsx') {
        $files = fa_unzip($path, ['xl/sharedStrings.xml', 'xl/worksheets/sheet1.xml']);
        $ss = []; if (($x = $files['xl/sharedStrings.xml'] ?? false) !== false) { $sx = simplexml_load_string($x); foreach ($sx->si as $si) { $ss[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]'))); } }
        $wb = $files['xl/worksheets/sheet1.xml'] ?? false; if ($wb === false) { throw new RuntimeException('The first sheet of the Excel file is empty.'); }
        $sx = simplexml_load_string($wb); $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                preg_match('/^([A-Z]+)/', (string) $c['r'], $mm); $col = 0; foreach (str_split($mm[1] ?? 'A') as $ch) { $col = $col * 26 + (ord($ch) - 64); }
                $v = isset($c->v) ? (string) $c->v : (isset($c->is->t) ? (string) $c->is->t : '');
                if ((string) $c['t'] === 's') { $v = $ss[(int) $v] ?? ''; }
                $cells[$col - 1] = trim($v);
            }
            if ($cells && array_filter($cells, function ($c) { return $c !== ''; })) { $line = []; for ($i = 0; $i <= max(array_keys($cells)); $i++) { $line[] = $cells[$i] ?? ''; } $rows[] = $line; }
        }
        return $rows;
    }
    throw new RuntimeException('Upload a CSV or an Excel (.xlsx) file.');
}

/** Reads some files from a ZIP (an .xlsx is a ZIP): ZipArchive when installed, otherwise a small built-in reader. */
function fa_unzip(string $path, array $names): array
{
    $out = [];
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive(); if ($z->open($path) !== true) { throw new RuntimeException('The Excel file could not be opened.'); }
        foreach ($names as $n) { $v = $z->getFromName($n); if ($v !== false) { $out[$n] = $v; } } $z->close(); return $out;
    }
    $d = (string) file_get_contents($path); $eocd = strrpos($d, "PK\x05\x06");
    if ($eocd === false || !function_exists('gzinflate')) { throw new RuntimeException('The Excel file could not be read: save the statement as CSV.'); }
    $e = unpack('vdisk/vcd_disk/ventries_disk/ventries/Vcd_size/Vcd_offset', substr($d, $eocd + 4, 16)); $p = $e['cd_offset'];
    for ($i = 0; $i < $e['entries']; $i++) {
        if (substr($d, $p, 4) !== "PK\x01\x02") { break; }
        $c = unpack('vver/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen/vdisk/viattr/Veattr/Voffset', substr($d, $p + 4, 42));
        $name = substr($d, $p + 46, $c['nlen']); $p += 46 + $c['nlen'] + $c['elen'] + $c['clen'];
        if (!in_array($name, $names, true)) { continue; }
        $l = unpack('vnlen/velen', substr($d, $c['offset'] + 26, 4)); $raw = substr($d, $c['offset'] + 30 + $l['nlen'] + $l['elen'], $c['csize']);
        $out[$name] = $c['method'] === 8 ? (string) gzinflate($raw) : $raw;
    }
    return $out;
}

/** Parses a statement date (various bank formats, Excel serial numbers). */
function fa_parse_date(string $v, string $fmt = 'auto'): ?string
{
    $v = trim($v); if ($v === '') { return null; }
    if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) { return date('Y-m-d', (int) round(((float) $v - 25569) * 86400)); }
    $v = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?.*$/', '', $v);
    $try = $fmt === 'dmy' ? ['d/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'd-M-Y', 'd M Y', 'Y-m-d'] : ($fmt === 'mdy' ? ['m/d/Y', 'm-d-Y', 'm/d/y', 'Y-m-d'] : ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd-M-Y', 'd M Y', 'd/m/y', 'Y/m/d', 'M d, Y']);
    foreach ($try as $f) { $d = DateTime::createFromFormat('!' . $f, $v); if ($d && $d->format($f) === $v) { return $d->format('Y-m-d'); } }
    return null;
}

function fa_parse_amount(string $v): ?float
{
    $v = trim(str_ireplace(['RWF', 'FRW', ' '], '', $v)); if ($v === '' || $v === '-') { return null; }
    $neg = false; if (preg_match('/^\((.*)\)$/', $v, $m)) { $v = $m[1]; $neg = true; } if (substr($v, -2) === 'DR' || substr($v, -2) === 'Dr') { $v = substr($v, 0, -2); $neg = true; } if (substr($v, -2) === 'CR' || substr($v, -2) === 'Cr') { $v = substr($v, 0, -2); }
    if (preg_match('/^-?[\d.]+,\d{1,2}$/', $v)) { $v = str_replace(['.', ','], ['', '.'], $v); } else { $v = str_replace(',', '', $v); }
    if (!is_numeric($v)) { return null; }
    return ($neg ? -1 : 1) * (float) $v;
}

/**
 * Suggests MY MOTIVE entries for each statement line: same amount and close date (±3 days) first, then same
 * amount further away, then a close amount with the same reference (amount / date differences). Entries already
 * matched to another line are not offered twice.
 */
function fa_suggest(array $lines, array $ledger, array $used): array
{
    $out = [];
    foreach ($lines as $l) {
        if ($l['status'] !== 'Unmatched') { continue; }
        $c = [];
        foreach ($ledger as $e) {
            if (isset($used[$e['key']]) || abs($e['amount']) < 0.005 || strpos($e['key'], 'open:') === 0) { continue; }
            $dd = abs((strtotime($e['date']) - strtotime($l['line_date'])) / 86400); $da = abs($e['amount'] - (float) $l['amount']);
            $refHit = $l['reference'] && $e['reference'] && (stripos($l['description'] . ' ' . $l['reference'], $e['reference']) !== false || stripos($e['reference'], (string) $l['reference']) !== false);
            if ($da < 0.005 && $dd <= 3) { $c[] = $e + ['score' => 100 - $dd * 5 + ($refHit ? 10 : 0), 'why' => $dd ? 'same amount, ' . $dd . ' day(s) apart' : 'same amount and date']; }
            elseif ($da < 0.005 && $dd <= 15) { $c[] = $e + ['score' => 60 - $dd, 'why' => 'same amount, date differs by ' . $dd . ' days']; }
            elseif (($refHit || $dd <= 2) && $da <= max(1, abs((float) $l['amount']) * 0.02) && ($e['amount'] > 0) === ((float) $l['amount'] > 0)) { $c[] = $e + ['score' => 50 - $dd, 'why' => 'amount differs by ' . number_format($da, 2)]; }
        }
        usort($c, function ($a, $b) { return $b['score'] <=> $a['score']; });
        if ($c) { $out[(int) $l['id']] = array_slice($c, 0, 3); }
    }
    return $out;
}

/** Money of each fund at the end of a date (the fund ledger without stock / asset value): [fund_id => amount]. */
function fa_fund_cash_at(mysqli $conn, ?string $date = null): array
{
    $d = mysqli_real_escape_string($conn, $date ?: date('Y-m-d'));
    return array_map('floatval', array_column(fa_rows($conn, "SELECT m.fund_id, SUM(CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END) v FROM fund_movements m LEFT JOIN fund_movements o ON o.id = m.reverses_movement_id
        WHERE m.created_at <= '$d 23:59:59' AND " . fa_cash_sql() . ' GROUP BY m.fund_id'), 'v', 'fund_id'));
}