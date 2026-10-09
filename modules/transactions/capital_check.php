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