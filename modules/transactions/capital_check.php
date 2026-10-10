<?php
/**
 * NEW FILE (same folder as Transactions index.php): RM Capital Fund Check.
 * Spec section 13: the RM Capital Fund must match the current Stock + Assets.
 * Shows real values next to what the fund holds, and fixes the stock part with one click.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require_once '../../includes/fund_report_helpers.php';
require_once '../../includes/asset_helpers.php'; // brings asset values up to today first

$role = strtolower($_SESSION['user_role'] ?? '');
if (!in_array($role, ['admin', 'manager'], true)) {
    header('Location: index.php?error=' . urlencode('You do not have permission to view the Capital Fund check.'));
    exit;
}
$isAdmin = $role === 'admin';
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

if ($isAdmin && isset($_POST['post_stock'])) {
    $reason = trim($_POST['reason'] ?? '');
    if (capital_has_opening_stock($conn) && $reason === '') {
        $error = 'Please give a reason for the stock correction (e.g. "Stock count 30 Sept").';
    } else {
        $result = capital_post_stock_difference($conn, $userId, $reason);
        if ($result['ok']) {
            header('Location: capital_check.php?success=' . urlencode('Stock value in the RM Capital Fund updated by RWF ' . number_format($result['amount'], 2) . '.'));
            exit;
        }
        $error = $result['error'];
    }
}

asset_run_depreciation_catchup_all($conn);

$fund = fund_by_code($conn, 'CAPITAL');
$b = $fund ? (fund_breakdown($conn, date('Y-m'))[(int) $fund['id']] ?? ['contrib' => 0, 'used' => 0, 'stock' => 0, 'assets' => 0]) : null;

$stockActual = capital_actual_stock_value($conn);
$assetActual = capital_actual_asset_value($conn);
$stockInFund = round((float) ($b['stock'] ?? 0), 2);
$assetInFund = round((float) ($b['assets'] ?? 0), 2);
$cashInFund = round((float) ($b['contrib'] ?? 0) - (float) ($b['used'] ?? 0), 2);
$balance = $fund ? round(fund_balance($conn, (int) $fund['id']), 2) : 0.0;
$stockDiff = round($stockActual - $stockInFund, 2);
$assetDiff = round($assetActual - $assetInFund, 2);
$hasOpening = capital_has_opening_stock($conn);
// NEW: loans (what was borrowed, repaid and is still owed).
$loans = capital_loan_summary($conn);
$ownerCapital = round($balance - $loans['outstanding'], 2);
// CHANGED: only loans taken after the RM Capital Fund started must be in the fund.
$loanFundDiff = round($loans['expected_in_fund'] - $loans['expected'], 2);

include '../../includes/header.php'; include '../../includes/sidebar.php';

function cc_diff_cell(float $d): string {
    if (abs($d) < 0.01) { return '<span class="badge bg-success">Matches</span>'; }
    return '<span class="badge bg-warning text-dark">' . ($d > 0 ? '+' : '') . number_format($d, 2) . '</span>';
}
?>
<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success"><?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?></div>
<?php } ?>
<?php if (isset($error)) { ?>
<div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>RM Capital Fund Check</h2>
    <a href="index.php" class="rm-btn rm-btn-secondary">&larr; Back to Transactions</a>
</div>

<?php if (!$fund) { ?>
<div class="alert alert-danger">RM Capital Fund was not found.</div>
<?php } else { ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <p class="text-muted small mb-3">
            The RM Capital Fund must always reflect the current value of the business's Stock and Assets, plus capital cash not yet turned into stock or assets.
            "Real value" comes from the products and assets themselves; "In the fund" is what the fund has recorded.
        </p>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th></th><th class="text-end">Real value</th><th class="text-end">In the fund</th><th class="text-end">Difference</th><th></th></tr></thead>
                <tbody>
                    <tr>
                        <td><strong>Stock</strong><div class="small text-muted">Quantity &times; buying price of every item</div></td>
                        <td class="text-end">RWF <?= number_format($stockActual, 2); ?></td>
                        <td class="text-end">RWF <?= number_format($stockInFund, 2); ?></td>
                        <td class="text-end"><?= cc_diff_cell($stockDiff); ?></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td><strong>Assets</strong><div class="small text-muted">Current value of Active and Under Maintenance assets</div></td>
                        <td class="text-end">RWF <?= number_format($assetActual, 2); ?></td>
                        <td class="text-end">RWF <?= number_format($assetInFund, 2); ?></td>
                        <td class="text-end"><?= cc_diff_cell($assetDiff); ?></td>
                        <td><?php if (abs($assetDiff) >= 0.01 && $isAdmin) { ?><a href="../assets/sync_capital_fund.php" class="btn btn-sm btn-outline-primary">Fix assets</a><?php } ?></td>
                    </tr>
                    <tr>
                        <td><strong>Cash &amp; other capital</strong><div class="small text-muted">Capital put in, minus money spent, not yet in stock or assets</div></td>
                        <td class="text-end text-muted">—</td>
                        <td class="text-end">RWF <?= number_format($cashInFund, 2); ?></td>
                        <td></td><td></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="fw-bold"><td>RM Capital Fund balance</td><td></td><td class="text-end">RWF <?= number_format($balance, 2); ?></td><td></td><td></td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- NEW: Loans -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h6 class="mb-1">Loans</h6>
        <p class="text-muted small mb-3">
            Part of the RM Capital Fund is borrowed money. What is still owed to lenders is not the business's own capital.
            Figures come from the Loans module (cancelled loans are left out).
        </p>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <tbody>
                    <tr>
                        <td>Loans received <div class="small text-muted"><?= (int) $loans['loans']; ?> loan(s), <?= (int) $loans['open_loans']; ?> still open</div></td>
                        <td class="text-end">RWF <?= number_format($loans['received'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Principal repaid</td>
                        <td class="text-end">&minus; RWF <?= number_format($loans['principal_repaid'], 2); ?></td>
                    </tr>
                    <tr class="fw-semibold">
                        <td>Outstanding loans <div class="small text-muted fw-normal">Still owed to lenders (Active and Defaulted loans)</div></td>
                        <td class="text-end text-danger">RWF <?= number_format($loans['outstanding'], 2); ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Interest paid so far <div class="small">A cost of borrowing, not a repayment of the loan</div></td>
                        <td class="text-end text-muted">RWF <?= number_format($loans['interest_paid'], 2); ?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td>Owner's capital <div class="small text-muted fw-normal">RM Capital Fund balance &minus; outstanding loans</div></td>
                        <td class="text-end">RWF <?= number_format($ownerCapital, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if ($loans['before_count'] > 0) { ?>
        <div class="alert alert-info mt-3 mb-0 small" style="border-radius:10px;">
            <i class="bi bi-info-circle-fill me-1"></i>
            <strong><?= (int) $loans['before_count']; ?> loan(s) (RWF <?= number_format($loans['before_amount'], 2); ?>)</strong> were taken before the RM Capital Fund started
            (<?= htmlspecialchars(date('d M Y', strtotime($loans['fund_start'])), ENT_QUOTES, 'UTF-8'); ?>). Their money was received and used before the fund existed;
            what it bought is already in the opening balances (stock and assets). They are <strong>not</strong> expected in the fund, but what is still owed on them
            is counted in Outstanding loans.
        </div>
        <?php } ?>

        <?php if ($loans['missing_count'] > 0 && abs($loanFundDiff) >= 0.01) { ?>
        <div class="alert alert-warning mt-3 mb-0 small" style="border-radius:10px;">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong><?= (int) $loans['missing_count']; ?> loan(s) taken after the RM Capital Fund started</strong> did not bring all their money into the fund
            (expected RWF <?= number_format($loans['expected'], 2); ?>, received RWF <?= number_format($loans['expected_in_fund'], 2); ?>).
            See the loans marked <span class="badge bg-warning text-dark">Missing</span> below, and check how they were recorded in the Loans module.
        </div>
        <?php } elseif ($loans['details']) { ?>
        <div class="alert alert-success mt-3 mb-0 small" style="border-radius:10px;">
            <i class="bi bi-check-circle-fill me-1"></i>
            Every loan taken since the RM Capital Fund started has its money in the fund.
        </div>
        <?php } ?>

        <?php if ($loans['details']) { ?>
        <!-- NEW: each loan and how it relates to the fund -->
        <details class="mt-3">
            <summary class="small fw-semibold" style="cursor:pointer;">Show each loan</summary>
            <div class="table-responsive mt-2">
                <table class="table table-sm align-middle mb-0" style="font-size:13px;">
                    <thead><tr><th>Lender</th><th>Start date</th><th>Status</th><th class="text-end">Borrowed</th><th class="text-end">In the fund</th><th class="text-end">Still owed</th><th>Check</th></tr></thead>
                    <tbody>
                    <?php foreach ($loans['details'] as $d) { ?>
                        <tr>
                            <td><?= htmlspecialchars($d['lender'] . ($d['loan_type'] ? ' (' . $d['loan_type'] . ')' : ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-nowrap"><?= htmlspecialchars(date('d M Y', strtotime($d['loan_start_date'])), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?= htmlspecialchars($d['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-end"><?= number_format((float) $d['loan_amount'], 2); ?></td>
                            <td class="text-end"><?= number_format((float) $d['in_fund'], 2); ?></td>
                            <td class="text-end"><?= in_array($d['status'], ['Active', 'Defaulted'], true) ? number_format((float) $d['outstanding_balance'], 2) : '0.00'; ?></td>
                            <td>
                                <?php if ($d['check'] === 'ok') { ?><span class="badge bg-success">In the fund</span>
                                <?php } elseif ($d['check'] === 'before') { ?><span class="badge bg-secondary" title="Taken before the RM Capital Fund started">Before the fund</span>
                                <?php } else { ?><span class="badge bg-warning text-dark">Missing</span><?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php } ?>

        <?php if ($loans['manual_count'] > 0) { ?>
        <div class="alert alert-warning mt-3 mb-0 small" style="border-radius:10px;">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong><?= (int) $loans['manual_count']; ?> "Business Loan" entr<?= $loans['manual_count'] === 1 ? 'y' : 'ies'; ?> (RWF <?= number_format($loans['manual_amount'], 2); ?>)</strong>
            were typed on the Add Capital page, not recorded in the Loans module. They have no repayment schedule and are not counted in Outstanding loans.
            If the same loan is also in the Loans module, it is counted twice in the fund: delete the Add Capital entry.
            If it is a real loan that is only here, record it in the Loans module instead, then delete the Add Capital entry.
            <?php if ($isAdmin) { ?><a href="capital_inflow.php">Review capital entries &rarr;</a><?php } ?>
        </div>
        <?php } ?>
    </div>
</div>

<?php if ($isAdmin && abs($stockDiff) >= 0.01) { ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <?php if (!$hasOpening) { ?>
            <h6>Opening stock balance</h6>
            <p class="small text-muted">
                The stock that was already on the shelves before the Capital Fund integration has never been added to the fund.
                Posting the opening balance adds the difference above <strong>once</strong>. After that, sales, purchase orders and re-stocks keep it up to date automatically.
            </p>
        <?php } else { ?>
            <h6>Stock correction</h6>
            <p class="small text-muted">
                The fund's stock value no longer matches the real stock. This happens when quantities or buying prices are changed by hand, or stock is damaged or lost outside a sale.
                Check the products first, then post the correction.
            </p>
        <?php } ?>
        <form method="POST" onsubmit="return confirm('Post RWF <?= number_format($stockDiff, 2); ?> to the RM Capital Fund as <?= $hasOpening ? 'a stock correction' : 'the opening stock balance'; ?>?');">
            <?php if ($hasOpening) { ?>
            <label class="form-label small fw-semibold text-muted">Reason</label>
            <input type="text" name="reason" class="form-control rm-input mb-3" maxlength="200" placeholder="e.g. Stock count 30 Sept, 3 damaged notebooks written off" required>
            <?php } ?>
            <button type="submit" name="post_stock" value="1" class="rm-btn rm-btn-primary">
                <i class="bi bi-check-circle-fill me-2"></i><?= $hasOpening ? 'Post Stock Correction' : 'Post Opening Stock Balance'; ?>
                (<?= ($stockDiff > 0 ? '+' : '') . number_format($stockDiff, 2); ?>)
            </button>
        </form>
    </div>
</div>
<?php } ?>

<?php } ?>
<?php include '../../includes/footer.php'; ?>