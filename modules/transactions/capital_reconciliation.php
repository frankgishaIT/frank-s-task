<?php
/**
 * NEW FILE: modules/transactions/capital_reconciliation.php
 * READ-ONLY. Reconciles the Balance Sheet "Cash & Bank" with the RM Funds ledgers and shows how much of
 * it genuinely belongs to the RM Capital Fund, BEFORE the new asset-purchase logic is activated.
 * Nothing is posted from this page.
 */
require '../../config/db.php';
require_role(['Admin']);

// NEW: if anything stops this page (even a fatal PHP error), the Admin sees the exact reason
// instead of a blank "HTTP ERROR 500", so problems on the live server can be fixed at once.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo '<div style="margin:20px;padding:14px;border:1px solid #E24B4A;background:#FCE9E9;color:#8A1C1B;font-family:sans-serif;">'
            . '<strong>The reconciliation page stopped with an error.</strong> Nothing was posted.<br>'
            . 'Technical detail for Admin: ' . htmlspecialchars($err['message'] . ' in ' . basename($err['file']) . ' line ' . $err['line'], ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
});
set_exception_handler(function (Throwable $e) {
    error_log('capital_reconciliation: ' . $e->getMessage());
    echo '<div style="margin:20px;padding:14px;border:1px solid #E24B4A;background:#FCE9E9;color:#8A1C1B;font-family:sans-serif;">'
        . '<strong>The reconciliation page stopped with an error.</strong> Nothing was posted.<br>'
        . 'Technical detail for Admin: ' . htmlspecialchars($e->getMessage() . ' in ' . basename($e->getFile()) . ' line ' . $e->getLine(), ENT_QUOTES, 'UTF-8')
        . '<br><br>If it mentions an unknown column or table, open <a href="../tools/migrations.php">Database Migrations</a> and run the missing migration(s).</div>';
});

require_once '../../includes/fund_report_helpers.php';
require_once '../../includes/financial_report_queries.php';
require_once '../../includes/asset_capital_helpers.php'; // NEW: the opening reconciliation
asset_capital_ensure_schema($conn); // NEW: adds any missing table / column of this feature automatically

$installed = asset_capital_installed($conn);

// NEW: post the opening reconciliation (once, with a supporting note).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_reconciliation']) && $installed) {
    $note = trim($_POST['note'] ?? '');
    if (mb_strlen($note) < 20) {
        $postError = 'Please write the supporting note (at least 20 characters): who reviewed it, and which records support it.';
    } else {
        try {
            capital_post_reconciliation($conn, $note, (int) current_user_id());
            header('Location: capital_reconciliation.php?posted=1');
            exit;
        } catch (Throwable $e) {
            error_log('capital reconciliation failed: ' . $e->getMessage());
            $postError = ($e instanceof RuntimeException && !($e instanceof mysqli_sql_exception)) ? $e->getMessage() : 'Unable to post the reconciliation. Nothing was changed.';
        }
    }
}
$posted = null;
try { $posted = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT r.*, u.names FROM capital_reconciliations r LEFT JOIN users u ON u.id = r.posted_by ORDER BY r.id LIMIT 1')); }
catch (Throwable $e) { $posted = null; }
$recItems = capital_reconciliation_items($conn);
$recTotals = capital_reconciliation_totals($recItems);

$signed = "(CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END)";
$cat = fund_movement_category_sql();
$q = function (string $sql, string $t = '', array $p = []) use ($conn): float { return fin_value($conn, $sql, $t, $p); };
$today = date('Y-m-d');

// ---- 1. The Balance Sheet cash, and its parts ----
$pos = fin_position($conn, $today);
$bsCash = $pos['cash'];

// ---- 2. The money held in each fund's ledger (cash part = contributions - money used; NOT stock or asset values) ----
$breakdown = fund_breakdown($conn, date('Y-m'));
$funds = funds_all($conn);
$fundRows = [];
$fundsCash = 0.0;
foreach ($funds as $f) {
    $b = $breakdown[(int) $f['id']] ?? ['contrib' => 0, 'used' => 0, 'stock' => 0, 'assets' => 0];
    $cash = round($b['contrib'] - $b['used'], 2);
    $fundRows[] = ['name' => $f['name'], 'code' => $f['code'], 'cash' => $cash, 'stock' => $b['stock'], 'assets' => $b['assets'],
                   'ledger' => round($cash + $b['stock'] + $b['assets'], 2), 'pending' => fund_pending_total($conn, (int) $f['id'])];
    $fundsCash += $cash;
}
$fundsCash = round($fundsCash, 2);
$capital = null;
foreach ($fundRows as $r) { if ($r['code'] === 'CAPITAL') { $capital = $r; } }

// ---- 3. Why the Balance Sheet cash is larger than the cash in the funds ----
// a) Cost of goods recovered in cash on PAID sales: the stock left the Capital Fund, the cash came back, but it
//    was never put back into the Capital Fund. This part genuinely belongs to the Capital Fund.
$cogsPaid = -$q("SELECT COALESCE(SUM($signed), 0) FROM fund_movements m JOIN sales s ON s.id = m.ref_id
    WHERE m.ref_type = 'SALE' AND s.status = 'Paid' AND (m.movement_type = 'STOCK_OUT'
       OR (m.movement_type = 'REVERSAL' AND m.reverses_movement_id IN (SELECT id FROM fund_movements WHERE movement_type = 'STOCK_OUT')))");
// CHANGED: minus what has already returned to the Capital Fund (SALE_COST), which the funds now hold.
$cogsPaid = round($cogsPaid - $q("SELECT COALESCE(SUM($signed), 0) FROM fund_movements m WHERE m.ref_type = 'SALE_COST'"), 2);
// b) The rest of paid sales that went to no fund (mainly the cost part of services under the profit rule).
$paidSalesCash = $q("SELECT COALESCE(SUM(amount_paid), 0) FROM sales WHERE status = 'Paid'");
$profitAllocated = $q("SELECT COALESCE(SUM($signed), 0) FROM fund_movements m JOIN sales s ON s.id = m.ref_id
    WHERE m.ref_type = 'SALE' AND s.status = 'Paid' AND m.movement_type IN ('ALLOCATION', 'REVERSAL')
      AND (m.movement_type = 'ALLOCATION' OR m.reverses_movement_id IN (SELECT id FROM fund_movements WHERE movement_type = 'ALLOCATION'))");
$paidOther = round($paidSalesCash - $profitAllocated - $cogsPaid, 2);
// c) Cash received on invoices not fully paid yet (allocated only when the invoice is completed).
$unpaidCash = $q("SELECT COALESCE(SUM(amount_paid), 0) FROM sales WHERE status IN ('Credit', 'Partially Paid')");
// d) Income received but not allocated to any fund (e.g. recorded before the funds existed).
$incomeCash = $pos['cash_parts']['income'];
$incomeAllocated = $q("SELECT COALESCE(SUM($signed), 0) FROM fund_movements m WHERE m.ref_type = 'TX_INCOME'");
$incomeUnallocated = round($incomeCash - $incomeAllocated, 2);
// e) Loans received through the Loans module that never went into the Capital Fund.
$loansIn = $pos['cash_parts']['loans_in'] ?? 0.0;
$loansInFund = $q("SELECT COALESCE(SUM($signed), 0) FROM fund_movements m JOIN funds f ON f.id = m.fund_id AND f.code = 'CAPITAL'
    WHERE m.ref_type = 'loan' AND m.ref_id IN (SELECT l.id FROM loans l JOIN transactions t ON t.id = l.received_transaction_id
                                               WHERE t.status = 'approved' AND t.category = 'Loan Received')");
$loansOutside = round($loansIn - $loansInFund - $q("SELECT COALESCE(SUM($signed), 0) FROM fund_movements m WHERE m.ref_type = 'LOAN_TX'"), 2);
// f) Money paid out WITHOUT a fund (e.g. expenses recorded before the funds existed): lowers the cash, not the funds.
$paidNoFund = $q("SELECT COALESCE(SUM(amount), 0) FROM transactions
    WHERE transaction_type = 'Expense' AND status = 'approved' AND category <> 'Asset Loss' AND fund_id IS NULL");
$repayNoFund = $q("SELECT COALESCE(SUM(amount), 0) FROM transactions
    WHERE transaction_type = 'Expense' AND status = 'approved' AND category = 'Loan Repayment' AND fund_id IS NULL");
$restockNoFund = $q("SELECT COALESCE(SUM(amount), 0) FROM transactions
    WHERE transaction_type = 'Expense' AND status = 'approved' AND category = 'Purchase (Re-stock)' AND fund_id IS NULL");
$otherNoFund = round($paidNoFund - $repayNoFund - $restockNoFund, 2);

$difference = round($bsCash - $fundsCash, 2);
$explained = round($cogsPaid + $paidOther + $unpaidCash + $incomeUnallocated + $loansOutside - $paidNoFund, 2);
$unexplained = round($difference - $explained, 2);

// ---- 4. Assets and their funding ----
$assets = mysqli_fetch_all(mysqli_query($conn, "SELECT a.id, a.asset_code, a.asset_name, a.acquisition_date, a.acquisition_value, a.current_value, a.status,
        " . ($installed ? 'a.funding_type, a.funding_transaction_id,' : "NULL AS funding_type, NULL AS funding_transaction_id,") . "
        (SELECT COUNT(*) FROM fund_movements m WHERE m.ref_type = 'ASSET' AND m.ref_id = a.id AND m.movement_type = 'ASSET_IN') AS in_fund
    FROM assets a ORDER BY a.acquisition_date, a.id"), MYSQLI_ASSOC);

// ---- 5. Details behind the lines that need review ----
// a) Income not allocated to a fund, by category and month (largest first), and the biggest single entries.
$incomeByGroup = mysqli_fetch_all(mysqli_query($conn, "SELECT t.category, DATE_FORMAT(t.transaction_date, '%Y-%m') AS month,
        COUNT(*) AS n, SUM(t.amount) AS amount
    FROM transactions t LEFT JOIN sales s ON s.transaction_id = t.id
    WHERE t.transaction_type = 'Income' AND t.status = 'approved' AND s.id IS NULL
      AND (t.is_automatic = 0 OR t.category = 'Overdue Charges')
      AND NOT EXISTS (SELECT 1 FROM fund_movements m WHERE m.ref_type = 'TX_INCOME' AND m.ref_id = t.id)
    GROUP BY t.category, month ORDER BY amount DESC LIMIT 30"), MYSQLI_ASSOC);
$incomeTop = mysqli_fetch_all(mysqli_query($conn, "SELECT t.id, t.transaction_date, t.category, t.amount, t.description, u.names AS by_name
    FROM transactions t LEFT JOIN sales s ON s.transaction_id = t.id LEFT JOIN users u ON u.id = t.recorded_by
    WHERE t.transaction_type = 'Income' AND t.status = 'approved' AND s.id IS NULL
      AND (t.is_automatic = 0 OR t.category = 'Overdue Charges')
      AND NOT EXISTS (SELECT 1 FROM fund_movements m WHERE m.ref_type = 'TX_INCOME' AND m.ref_id = t.id)
    ORDER BY t.amount DESC LIMIT 20"), MYSQLI_ASSOC);
// b) Money put straight into a fund without passing through Transactions or a sale (e.g. opening balances):
//    the funds count it, the Balance Sheet cash does not. Usually explains a round "not explained" amount.
$direct = mysqli_fetch_all(mysqli_query($conn, "SELECT m.id, m.created_at, f.name AS fund, m.movement_type, m.direction, m.amount, m.source_type, m.description, m.ref_type
    FROM fund_movements m JOIN funds f ON f.id = m.fund_id
    WHERE f.code <> 'CAPITAL' AND m.transaction_id IS NULL
      AND (m.ref_type IS NULL OR m.ref_type NOT IN ('SALE', 'TX_INCOME', 'PAYROLL', 'REWARD'))
      AND m.movement_type NOT IN ('REVERSAL')
    ORDER BY m.amount DESC LIMIT 30"), MYSQLI_ASSOC);
// c) Biggest expenses paid out without a fund.
$noFundTop = mysqli_fetch_all(mysqli_query($conn, "SELECT t.id, t.transaction_date, t.category, t.expense_category, t.amount, t.description
    FROM transactions t WHERE t.transaction_type = 'Expense' AND t.status = 'approved' AND t.category <> 'Asset Loss' AND t.fund_id IS NULL
    ORDER BY t.amount DESC LIMIT 15"), MYSQLI_ASSOC);

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$m = function ($v) { return number_format((float) $v, 2); };
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-2">
    <h2>RM Capital Fund Reconciliation</h2>
    <a href="capital_check.php" class="rm-btn rm-btn-light">Capital Fund Check</a>
</div>
<p class="text-muted">Read-only. Nothing is posted from this page. As of <?= date('d M Y, H:i'); ?>.</p>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
<h5>1. Cash in the funds compared with the Balance Sheet</h5>
<table class="table table-bordered bg-white mb-0 align-middle" style="font-size:13px;">
    <tr><th>Fund</th><th class="text-end">Cash part (contributions - money used)</th><th class="text-end">Stock value</th><th class="text-end">Asset value</th><th class="text-end">Ledger balance today</th><th class="text-end">Pending expenses</th></tr>
    <?php foreach ($fundRows as $r) { ?>
    <tr<?= $r['code'] === 'CAPITAL' ? ' class="fw-semibold"' : ''; ?>>
        <td><?= $e($r['name']); ?></td>
        <td class="text-end"><?= $m($r['cash']); ?></td>
        <td class="text-end"><?= $m($r['stock']); ?></td>
        <td class="text-end"><?= $m($r['assets']); ?></td>
        <td class="text-end"><?= $m($r['ledger']); ?></td>
        <td class="text-end"><?= $m($r['pending']); ?></td>
    </tr>
    <?php } ?>
    <tr class="fw-bold" style="background:#E4E9F1;"><td>Cash held in all funds</td><td class="text-end"><?= $m($fundsCash); ?></td><td colspan="4"></td></tr>
    <tr class="fw-bold"><td>Balance Sheet Cash &amp; Bank</td><td class="text-end"><?= $m($bsCash); ?></td><td colspan="4" class="small text-muted fw-normal">money received - money paid out</td></tr>
    <tr class="fw-bold text-danger"><td>Cash not held in any fund</td><td class="text-end"><?= $m($difference); ?></td><td colspan="4"></td></tr>
</table>
<?php if ($capital) { ?>
<div class="alert alert-warning small mt-3 mb-0">
    <strong>RM Capital Fund "available" today:</strong> RWF <?= $m($capital['ledger'] - $capital['pending']); ?>, because the current rule counts the whole ledger,
    <strong>including stock (RWF <?= $m($capital['stock']); ?>) and assets (RWF <?= $m($capital['assets']); ?>)</strong>, which are not money.
    The money actually in the Capital Fund is its cash part: <strong>RWF <?= $m($capital['cash'] - $capital['pending']); ?></strong> after pending expenses.
</div>
<?php } ?>
</div></div>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
<h5>2. Where the cash not held in any fund comes from</h5>
<table class="table table-bordered bg-white mb-0 align-middle" style="font-size:13px;">
    <tr><th>Source</th><th class="text-end">RWF</th><th>Belongs to</th><th>Supported by</th></tr>
    <tr><td>Cost of goods recovered in cash on paid sales (stock turned back into cash)</td><td class="text-end fw-semibold"><?= $m($cogsPaid); ?></td>
        <td><span class="badge bg-success">RM Capital Fund</span></td><td class="small">The STOCK_OUT entries of every paid sale in the Capital Fund ledger</td></tr>
    <tr><td>Rest of paid sales not allocated (mainly the cost part of services, profit rule)</td><td class="text-end"><?= $m($paidOther); ?></td>
        <td><span class="badge bg-secondary">Operating money</span></td><td class="small">Paid sales minus allocated profit minus cost of goods</td></tr>
    <tr><td>Cash received on invoices not fully paid yet</td><td class="text-end"><?= $m($unpaidCash); ?></td>
        <td><span class="badge bg-secondary">Allocated when the invoice is completed</span></td><td class="small">amount_paid of Credit / Partially Paid sales</td></tr>
    <tr><td>Income received but not allocated to a fund (real income, loans excluded)</td><td class="text-end"><?= $m($incomeUnallocated); ?></td>
        <td><span class="badge bg-secondary">The four funds (to be allocated)</span></td><td class="small">Approved income minus its fund allocations</td></tr>
    <tr><td>Loans received through the Loans module, not put into the Capital Fund</td><td class="text-end fw-semibold"><?= $m($loansOutside); ?></td>
        <td><span class="badge bg-success">RM Capital Fund (financing)</span></td><td class="small">"Loan Received" transactions of the Loans module (borrowed money, owed back)</td></tr>
    <tr><td>Less: loan repayments paid without a fund</td><td class="text-end"><?= $m(-$repayNoFund); ?></td>
        <td><span class="badge bg-success">RM Capital Fund (principal)</span> <span class="badge bg-secondary">Operating (interest)</span></td><td class="small">Loan Repayment transactions with no fund</td></tr>
    <tr><td>Less: stock purchases paid without a fund</td><td class="text-end"><?= $m(-$restockNoFund); ?></td>
        <td><span class="badge bg-success">RM Capital Fund</span></td><td class="small">The stock bought is already in the Capital Fund's stock value</td></tr>
    <tr><td>Less: other expenses paid without a fund</td><td class="text-end"><?= $m(-$otherNoFund); ?></td>
        <td><span class="badge bg-secondary">Already spent</span></td><td class="small">Approved expenses with no fund</td></tr>
    <tr class="fw-bold" style="background:#E4E9F1;"><td>Explained</td><td class="text-end"><?= $m($explained); ?></td><td colspan="2"></td></tr>
    <tr class="fw-bold <?= abs($unexplained) > 1 ? 'text-danger' : 'text-success'; ?>"><td>Not explained by the records</td><td class="text-end"><?= $m($unexplained); ?></td>
        <td colspan="2" class="fw-normal small"><?= abs($unexplained) > 1 ? 'Needs a documented review before any adjustment.' : 'Fully reconciled.'; ?></td></tr>
</table>
<div class="alert alert-info small mt-3 mb-0">
    <strong>Proposal (not applied):</strong> RWF <?= $m($cogsPaid); ?> is supported by the Capital Fund's own records (the cost of goods it lost when they were sold),
    so it can be returned to the RM Capital Fund as one documented adjustment, and from now on each paid sale returns its cost of goods automatically.
    The other amounts are operating money and are <strong>not</strong> moved into the Capital Fund.
</div>
</div></div>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
<h5>Opening reconciliation</h5>
<?php if (isset($_GET['posted'])) { ?>
<div class="alert alert-success">
    <strong>Done. The opening reconciliation was posted.</strong> Asset purchases are now paid from the RM Capital Fund.
    If you came here from <strong>Register Asset</strong>, go back to that tab and click <strong>Register Asset</strong> again;
    your details are still there. You can close this tab.
    <div class="mt-2"><a href="../asset_management/create.php" class="btn btn-success btn-sm">Or register an asset now</a></div>
</div>
<?php } ?>
<?php if (!$installed) { ?>
    <!-- NEW: the database update for this feature has not been run on THIS database yet. -->
    <div class="alert alert-warning mb-0">
        <strong>One step first:</strong> this database does not have the update for asset purchases yet.
        Open <a href="../tools/migrations.php"><strong>Database Migrations</strong></a>, click <strong>Run the missing migration(s)</strong>,
        then come back to this page to post the opening reconciliation.
    </div>
<?php } elseif ($posted) { ?>
    <div class="alert alert-success small mb-2"><strong>Posted</strong> on <?= date('d M Y, H:i', strtotime($posted['posted_at'])); ?> by <?= $e($posted['names'] ?? '—'); ?>.
        Asset purchases are paid from the RM Capital Fund.</div>
    <pre class="small bg-light p-2 mb-2" style="white-space:pre-wrap;"><?= $e($posted['summary']); ?></pre>
    <div class="small"><strong>Supporting note:</strong> <?= nl2br($e($posted['note'])); ?></div>
<?php } else { ?>
    <p class="small text-muted">Posts ONLY amounts supported by records, each linked to its record. Nothing else is moved: the operating money,
        the income not allocated and anything not explained stay as they are. Asset purchases are blocked until this is posted.</p>
    <table class="table table-sm table-bordered bg-white align-middle" style="font-size:13px;">
        <tr><th>Line</th><th class="text-end">Records</th><th class="text-end">RM Capital Fund</th><th>Supported by</th></tr>
        <tr><td>Cost of goods recovered on paid sales</td><td class="text-end"><?= count($recItems['sales']); ?></td><td class="text-end">+<?= $m($recTotals['sales']); ?></td><td class="small">Each sale's STOCK_OUT entry</td></tr>
        <tr><td>Loans received outside the fund</td><td class="text-end"><?= count($recItems['loans']); ?></td><td class="text-end">+<?= $m($recTotals['loans']); ?></td><td class="small">Each "Loan Received" transaction (Loans module)</td></tr>
        <tr><td>Stock purchases paid outside the funds</td><td class="text-end"><?= count($recItems['restocks']); ?></td><td class="text-end">&minus;<?= $m($recTotals['restocks']); ?></td><td class="small">Each re-stock / PO transaction (linked to the fund)</td></tr>
        <tr><td>Loan principal repaid outside the funds</td><td class="text-end"><?= count($recItems['repayments']); ?></td><td class="text-end">&minus;<?= $m($recTotals['principal']); ?></td><td class="small">Each repayment transaction (principal part)</td></tr>
        <tr class="fw-bold" style="background:#E4E9F1;"><td>Net into the RM Capital Fund</td><td></td><td class="text-end"><?= $m($recTotals['capital_net']); ?></td><td></td></tr>
        <tr><td>Loan interest repaid outside the funds (RM Business Operating Fund)</td><td></td><td class="text-end small">Operating &minus;<?= $m($recTotals['interest']); ?></td><td class="small">Each repayment transaction (interest part)</td></tr>
    </table>
    <?php if (isset($postError)) { ?><div class="alert alert-danger small"><?= $e($postError); ?></div><?php } ?>
    <form method="POST" onsubmit="return confirm('Post the opening reconciliation? This is done once and recorded with your name.');">
        <label class="form-label small fw-semibold text-muted">Supporting note (who reviewed it, which records support it)</label>
        <textarea name="note" class="form-control mb-2" rows="3" required></textarea>
        <button type="submit" name="post_reconciliation" value="1" class="rm-btn rm-btn-primary">Post opening reconciliation</button>
    </form>
<?php } ?>
</div></div>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
<h5>3. Assets and their funding</h5>
<table class="table table-bordered bg-white mb-0 align-middle" style="font-size:13px;">
    <tr><th>Code</th><th>Asset</th><th>Acquired</th><th class="text-end">Acquisition (RWF)</th><th class="text-end">Value today</th><th>Status</th><th>Funding record</th></tr>
    <?php if (!$assets) { ?><tr><td colspan="7" class="text-center text-muted">No assets.</td></tr><?php } ?>
    <?php foreach ($assets as $a) { ?>
    <tr>
        <td><?= $e($a['asset_code']); ?></td><td><?= $e($a['asset_name']); ?></td>
        <td><?= date('d M Y', strtotime($a['acquisition_date'])); ?></td>
        <td class="text-end"><?= $m($a['acquisition_value']); ?></td><td class="text-end"><?= $m($a['current_value']); ?></td>
        <td><?= $e($a['status']); ?></td>
        <td><?= ($a['funding_type'] ?? '') === 'Capital Fund purchase'
            ? '<span class="badge bg-success">Paid from the Capital Fund (payment #' . (int) $a['funding_transaction_id'] . ')</span>'
            : ((int) $a['in_fund'] > 0
                ? '<span class="badge bg-warning text-dark">Opening balance: value added, purchase not paid from the fund</span>'
                : '<span class="badge bg-danger">No record in the Capital Fund</span>'); ?></td>
    </tr>
    <?php } ?>
</table>
<p class="small text-muted mt-2 mb-0">Assets registered so far were added to the Capital Fund as value only (no payment was taken from the fund).
They are kept as opening balances; only NEW acquisitions will be paid from the fund once the new logic is active.</p>
</div></div>
<div class="card border-0 shadow-sm mb-4"><div class="card-body">
<h5>4. Details for review</h5>

<h6 class="mt-3">a) Income not allocated to any fund (RWF <?= $m($incomeUnallocated); ?>): by category and month</h6>
<table class="table table-sm table-bordered bg-white align-middle" style="font-size:13px;">
    <tr><th>Category</th><th>Month</th><th class="text-end">Entries</th><th class="text-end">RWF</th></tr>
    <?php if (!$incomeByGroup) { ?><tr><td colspan="4" class="text-center text-muted">None.</td></tr><?php } ?>
    <?php foreach ($incomeByGroup as $g) { ?>
    <tr><td><?= $e($g['category']); ?></td><td><?= $e($g['month']); ?></td><td class="text-end"><?= (int) $g['n']; ?></td><td class="text-end"><?= $m($g['amount']); ?></td></tr>
    <?php } ?>
</table>
<div class="small text-muted mb-1">The 20 largest of these entries:</div>
<table class="table table-sm table-bordered bg-white align-middle" style="font-size:12px;">
    <tr><th>#</th><th>Date</th><th>Category</th><th class="text-end">RWF</th><th>Description</th><th>Recorded by</th></tr>
    <?php foreach ($incomeTop as $t) { ?>
    <tr><td><?= (int) $t['id']; ?></td><td><?= date('d M Y', strtotime($t['transaction_date'])); ?></td><td><?= $e($t['category']); ?></td>
        <td class="text-end"><?= $m($t['amount']); ?></td><td><?= $e($t['description']); ?></td><td><?= $e($t['by_name'] ?? '—'); ?></td></tr>
    <?php } ?>
</table>

<h6 class="mt-4">b) Money put straight into a fund without a transaction or a sale</h6>
<p class="small text-muted">The funds count these, but the Balance Sheet cash does not. A round "not explained" amount is usually one of these.</p>
<table class="table table-sm table-bordered bg-white align-middle" style="font-size:12px;">
    <tr><th>#</th><th>Date</th><th>Fund</th><th>Type</th><th class="text-end">RWF</th><th>Source</th><th>Description</th></tr>
    <?php if (!$direct) { ?><tr><td colspan="7" class="text-center text-muted">None.</td></tr><?php } ?>
    <?php foreach ($direct as $d) { ?>
    <tr><td><?= (int) $d['id']; ?></td><td><?= date('d M Y', strtotime($d['created_at'])); ?></td><td><?= $e($d['fund']); ?></td>
        <td><?= $e($d['movement_type'] . ' ' . $d['direction']); ?></td><td class="text-end"><?= $m($d['amount']); ?></td>
        <td><?= $e($d['source_type'] ?? ''); ?></td><td><?= $e($d['description']); ?></td></tr>
    <?php } ?>
</table>

<h6 class="mt-4">c) Largest expenses paid without a fund (RWF <?= $m($paidNoFund); ?> in total)</h6>
<table class="table table-sm table-bordered bg-white align-middle" style="font-size:12px;">
    <tr><th>#</th><th>Date</th><th>Category</th><th>Expense category</th><th class="text-end">RWF</th><th>Description</th></tr>
    <?php if (!$noFundTop) { ?><tr><td colspan="6" class="text-center text-muted">None.</td></tr><?php } ?>
    <?php foreach ($noFundTop as $t) { ?>
    <tr><td><?= (int) $t['id']; ?></td><td><?= date('d M Y', strtotime($t['transaction_date'])); ?></td><td><?= $e($t['category']); ?></td>
        <td><?= $e($t['expense_category'] ?? ''); ?></td><td class="text-end"><?= $m($t['amount']); ?></td><td><?= $e($t['description']); ?></td></tr>
    <?php } ?>
</table>

<h6 class="mt-4">d) Funds below zero</h6>
<ul class="small mb-0">
    <?php $anyNeg = false; foreach ($fundRows as $r) { if ($r['cash'] < -0.009) { $anyNeg = true; ?>
        <li><strong><?= $e($r['name']); ?>:</strong> RWF <?= $m($r['cash']); ?>. More was spent from this fund than was ever allocated to it.
            Check its expenses in Fund Reports (they may belong to another fund).</li>
    <?php } } if (!$anyNeg) { ?><li>None.</li><?php } ?>
</ul>
</div></div>
<?php include '../../includes/footer.php'; ?>