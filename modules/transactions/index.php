<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require '../../includes/profit_rules.php'; // shared profit rules (same as the Sales reports)
require '../../includes/fund_helpers.php'; // RM Funds
$pageSearchScope = 'transactions'; // tells the topbar search what module we're in
require '../../includes/pagination.php';
include '../../includes/header.php'; include '../../includes/sidebar.php';
const PER_PAGE = 10;
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
$canSeeFundReports = in_array(strtolower($_SESSION['user_role'] ?? ''), ['admin', 'manager'], true);
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

// Visibility: admin sees every transaction. Non-admin sees approved ones
// plus their own pending/rejected submissions.
$visibilityWhere = $isAdmin
    ? '1=1'
    : "(transactions.status = 'approved' OR transactions.recorded_by = $currentUserId)";

$currentPage = get_current_page();
$totalRows = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM transactions WHERE $visibilityWhere"))['c'];
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;

// Totals only ever count approved transactions.
$summary = mysqli_query($conn, "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'Income' THEN amount ELSE 0 END), 0) AS income, COALESCE(SUM(CASE WHEN transaction_type = 'Expense' THEN amount ELSE 0 END), 0) AS expense FROM transactions WHERE status = 'approved'");
$totals = mysqli_fetch_assoc($summary);

// Profit Overview — uses the shared rules in includes/profit_rules.php so it
// always agrees with the Sales reports:
//   Product profit = net amount (after discount) - buying price x base units sold
//   Service profit = SALES_SERVICE_PROFIT_RATE (80%) of the net amount
// Only counts sales that actually completed (excludes pending and cancelled).
$lineNet  = sales_report_line_net_sql('sales', 'sale_items');
$lineCost = sales_report_line_cost_sql('sales', 'sale_items', 'products');
$profitSummary = mysqli_query($conn, "SELECT
        COALESCE(SUM(CASE WHEN sale_items.item_type = 'Product' THEN $lineNet - $lineCost ELSE 0 END), 0) AS product_profit,
        COALESCE(SUM(CASE WHEN sale_items.item_type = 'Service' THEN $lineNet - $lineCost ELSE 0 END), 0) AS service_profit
    FROM sale_items
    JOIN sales ON sale_items.sale_id = sales.id
    LEFT JOIN products ON sale_items.product_id = products.id
    WHERE sales.status NOT IN ('Pending Discount Approval', 'Cancelled')");
$profitTotals = mysqli_fetch_assoc($profitSummary);
$productProfit = (float) $profitTotals['product_profit'];
$serviceProfit = (float) $profitTotals['service_profit'];
$totalProfit = $productProfit + $serviceProfit;

// ---- RM Fund cards ----
// Contributions = money put in by profit allocation or capital inflows.
// Used = expenses paid out of the fund (minus any reversals).
// Available = ledger balance minus expenses still waiting for admin approval.
$currentPeriod = date('Y-m');
$fundSql = "SELECT f.id, f.code, f.name, f.allocation_percent,
        COALESCE(SUM(CASE WHEN m.direction = 'IN' AND m.movement_type IN ('ALLOCATION','CAPITAL_INFLOW') THEN m.amount WHEN m.direction = 'OUT' AND m.movement_type = 'ADJUSTMENT' THEN -m.amount END), 0) AS total_contrib,
        COALESCE(SUM(CASE WHEN m.period = ? THEN CASE WHEN m.direction = 'IN' AND m.movement_type IN ('ALLOCATION','CAPITAL_INFLOW') THEN m.amount WHEN m.direction = 'OUT' AND m.movement_type = 'ADJUSTMENT' THEN -m.amount END END), 0) AS period_contrib,
        COALESCE(SUM(CASE WHEN m.direction = 'OUT' AND m.movement_type <> 'ADJUSTMENT' THEN m.amount END), 0)
          - COALESCE(SUM(CASE WHEN m.direction = 'IN' AND m.movement_type = 'REVERSAL' THEN m.amount END), 0) AS total_used,
        MAX(m.created_at) AS last_date
    FROM funds f
    LEFT JOIN fund_movements m ON m.fund_id = f.id
    WHERE f.is_active = 1
    GROUP BY f.id, f.code, f.name, f.allocation_percent
    ORDER BY FIELD(f.code, 'FUTURE_PLANS', 'EMERGENCY', 'TEAM_GROWTH', 'OPERATING', 'CAPITAL')";
$fundStmt = mysqli_prepare($conn, $fundSql);
mysqli_stmt_bind_param($fundStmt, 's', $currentPeriod);
mysqli_stmt_execute($fundStmt);
$fundRows = mysqli_fetch_all(mysqli_stmt_get_result($fundStmt), MYSQLI_ASSOC);

// The colour is only a thin bar at the top of each card. Balances stay dark and neutral.
$fundColor = [
    'FUTURE_PLANS' => '#4f46e5',
    'EMERGENCY'    => '#dc3545',
    'TEAM_GROWTH'  => '#f59e0b',
    'OPERATING'    => '#198754',
    'CAPITAL'      => '#0dcaf0',
];

// Add the numbers each card needs, then split: Operating is the featured card.
$operatingCard = null;
$otherCards = [];
foreach ($fundRows as $fc) {
    $fc['available'] = fund_available($conn, (int) $fc['id']);
    $fc['pending'] = fund_pending_total($conn, (int) $fc['id']);
    $fc['is_empty'] = ((float) $fc['total_contrib'] == 0.0 && (float) $fc['total_used'] == 0.0);
    $fc['color'] = $fundColor[$fc['code']] ?? '#6c757d';
    $fc['share'] = $fc['allocation_percent'] !== null
        ? rtrim(rtrim(number_format((float) $fc['allocation_percent'], 2), '0'), '.') . '% of Net Profit'
        : 'Non-profit funding (no fixed %)';
    $fc['hint'] = $fc['code'] === 'CAPITAL' ? 'No capital recorded yet' : 'No allocation yet';
    if ($fc['code'] === 'OPERATING') {
        $operatingCard = $fc;
    } else {
        $otherCards[] = $fc;
    }
}

$sql = "SELECT transactions.*, users.names AS recorder_name, funds.name AS fund_name FROM transactions LEFT JOIN users ON transactions.recorded_by = users.id LEFT JOIN funds ON transactions.fund_id = funds.id WHERE $visibilityWhere ORDER BY transaction_date DESC, transactions.id DESC LIMIT " . PER_PAGE . ' OFFSET ' . $offset;
$transactions = mysqli_query($conn, $sql);

$statusBadge = [
    'approved' => 'success',
    'pending' => 'warning text-dark',
    'rejected' => 'danger',
];
?>
<style>
    .rm-fund-card { border: 0; border-top: 4px solid var(--fund-color, #6c757d); box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .rm-fund-title { min-height: 3.6rem; }
    .rm-fund-name { font-weight: 600; font-size: 14px; line-height: 1.25; }
    .rm-fund-share { font-size: 12px; color: #6c757d; }
    .rm-fund-label { font-size: 11px; color: #6c757d; margin-top: 6px; }
    .rm-fund-balance { font-size: 1.35rem; font-weight: 700; color: #212529; white-space: nowrap; }
    .rm-fund-hint { font-size: 11px; color: #8a8f98; font-style: italic; min-height: 16px; }
    .rm-fund-stat { display: flex; justify-content: space-between; font-size: 12px; white-space: nowrap; gap: 8px; }
    .rm-fund-stat span:first-child { color: #6c757d; }
    .rm-fund-main .rm-fund-balance { font-size: 1.9rem; }
    .rm-fund-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; }
    .rm-fund-grid .k { font-size: 11px; color: #6c757d; }
    .rm-fund-grid .v { font-size: 14px; font-weight: 600; white-space: nowrap; }
</style>

<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<?php if (isset($_GET['error'])) { ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Transactions Management</h2>
    <div class="d-flex gap-2 align-items-center">
        <?php if (in_array(current_user_role(), ['Admin', 'Manager'], true)) { ?>
            <a href="transactions_reports.php" class="btn btn-outline-primary">Reports</a>
        <?php } ?>
        <?php if ($isAdmin || $canSeeFundReports) { ?>
        <div class="dropdown">
            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Funds</button>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php if ($isAdmin) { ?>
                <li><a class="dropdown-item" href="allocate_profit.php">Allocate Net Profit</a></li>
                <li><a class="dropdown-item" href="capital_inflow.php">Add Capital</a></li>
                <?php } ?>
                <?php if ($canSeeFundReports) { ?>
                <li><a class="dropdown-item" href="fund_reports.php">Fund Reports</a></li>
                <?php } ?>
            </ul>
        </div>
        <?php } ?>
        <a href="create.php" class="rm-btn rm-btn-primary">+ Add Transaction</a>
    </div>
</div>

<!-- RM FUND CARDS -->
<?php if ($operatingCard) { $fc = $operatingCard; ?>
<div class="card rm-fund-card rm-fund-main mb-3" style="--fund-color: <?= $fc['color']; ?>;">
    <div class="card-body">
        <div class="row g-3 align-items-center">
            <div class="col-lg-5">
                <div class="rm-fund-name"><?= htmlspecialchars($fc['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="rm-fund-share"><?= $fc['share']; ?> &middot; used for normal business expenses</div>
                <div class="rm-fund-label">Available Balance Now</div>
                <div class="rm-fund-balance">RWF <?= number_format($fc['available'], 2); ?></div>
                <div class="rm-fund-hint">
                    <?php if ($fc['pending'] > 0) { ?>Includes RWF <?= number_format($fc['pending'], 2); ?> reserved for pending approval
                    <?php } elseif ($fc['is_empty']) { echo $fc['hint']; } ?>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="rm-fund-grid">
                    <div><div class="k">This period</div><div class="v"><?= number_format((float) $fc['period_contrib'], 2); ?></div></div>
                    <div><div class="k">Contributed</div><div class="v"><?= number_format((float) $fc['total_contrib'], 2); ?></div></div>
                    <div><div class="k">Used</div><div class="v"><?= number_format((float) $fc['total_used'], 2); ?></div></div>
                    <div><div class="k">Last activity</div><div class="v"><?= $fc['last_date'] ? date('d M Y', strtotime($fc['last_date'])) : '—'; ?></div></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php } ?>

<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4">
    <?php foreach ($otherCards as $fc) { ?>
    <div class="col">
        <div class="card rm-fund-card h-100" style="--fund-color: <?= $fc['color']; ?>;">
            <div class="card-body">
                <div class="rm-fund-title">
                    <div class="rm-fund-name"><?= htmlspecialchars($fc['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="rm-fund-share"><?= $fc['share']; ?></div>
                </div>
                <div class="rm-fund-label">Available Balance Now</div>
                <div class="rm-fund-balance">RWF <?= number_format($fc['available'], 2); ?></div>
                <div class="rm-fund-hint">
                    <?php if ($fc['pending'] > 0) { ?>Includes <?= number_format($fc['pending'], 2); ?> reserved for pending approval
                    <?php } elseif ($fc['is_empty']) { echo $fc['hint']; } ?>
                </div>
                <hr class="my-2">
                <div class="rm-fund-stat"><span>This period</span><span><?= number_format((float) $fc['period_contrib'], 2); ?></span></div>
                <div class="rm-fund-stat"><span>Contributed</span><span><?= number_format((float) $fc['total_contrib'], 2); ?></span></div>
                <div class="rm-fund-stat"><span>Used</span><span><?= number_format((float) $fc['total_used'], 2); ?></span></div>
                <div class="rm-fund-stat"><span>Last activity</span><span><?= $fc['last_date'] ? date('d M Y', strtotime($fc['last_date'])) : '—'; ?></span></div>
            </div>
        </div>
    </div>
    <?php } ?>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-success">
            <div class="card-body">
                <small class="text-muted">Total Income</small>
                <h4 class="text-success mb-0">RWF <?= number_format((float) $totals['income'], 2); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-danger">
            <div class="card-body">
                <small class="text-muted">Total Expenses</small>
                <h4 class="text-danger mb-0">RWF <?= number_format((float) $totals['expense'], 2); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-primary">
            <div class="card-body">
                <small class="text-muted">Balance</small>
                <h4 class="text-primary mb-0">RWF <?= number_format((float) $totals['income'] - (float) $totals['expense'], 2); ?></h4>
            </div>
        </div>
    </div>
</div>

<h5 class="mb-3">Profit Overview</h5>
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-info">
            <div class="card-body">
                <small class="text-muted">Product Profit</small>
                <h4 class="text-info mb-0">RWF <?= number_format($productProfit, 2); ?></h4>
                <small class="text-muted">Selling Price − Buying Price</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-warning">
            <div class="card-body">
                <small class="text-muted">Service Profit</small>
                <h4 class="text-warning mb-0">RWF <?= number_format($serviceProfit, 2); ?></h4>
                <small class="text-muted"><?= (int) round(SALES_SERVICE_PROFIT_RATE * 100); ?>% of the service amount (after discount)</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-primary">
            <div class="card-body">
                <small class="text-muted">Total Profit</small>
                <h4 class="text-primary mb-0">RWF <?= number_format($totalProfit, 2); ?></h4>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <!-- Live-search wires up against this container: it caches this exact
             markup on page load and swaps it out for filtered results as you
             type in the topbar search box, restoring it when the box is
             cleared. The income/expense/balance summary cards above stay
             outside since they aren't search results. -->
        <div id="pageResultsContainer">
        <div class="table-responsive">
            <table class="table table-bordered table-hover bg-white mb-0">
    <tr>
        <th>Date</th>
        <th>Category</th>
        <th>Type</th>
        <th>Fund</th>
        <th>Amount</th>
        <th>Description</th>
        <th>Recorded By</th>
        <th>Status</th>
        <th>Action</th>
    </tr><?php if (mysqli_num_rows($transactions) === 0) { ?>
    <tr><td colspan="9" class="text-center text-muted py-4">No transactions found. Click "Add Transaction" to record one.</td></tr><?php } ?>
    <?php while ($transaction = mysqli_fetch_assoc($transactions)) { ?>
    <tr><td><?= date('d M Y', strtotime($transaction['transaction_date'])); ?>
</td><td><?= htmlspecialchars($transaction['category'], ENT_QUOTES, 'UTF-8'); ?>
</td><td><span class="badge bg-<?= $transaction['transaction_type'] === 'Income' ? 'success' : 'danger'; ?>">
    <?= $transaction['transaction_type']; ?></span></td>
    <td><?= htmlspecialchars($transaction['fund_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
    <td>RWF <?= number_format((float) $transaction['amount'], 2); ?></td>
    <td><?= htmlspecialchars($transaction['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><?= htmlspecialchars($transaction['recorder_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
    <td>
        <span class="badge bg-<?= $statusBadge[$transaction['status']] ?? 'secondary'; ?>">
            <?= ucfirst($transaction['status']); ?>
        </span>
        <?php if ($transaction['status'] === 'rejected' && !empty($transaction['rejection_reason'])) { ?>
            <i class="bi bi-info-circle" title="<?= htmlspecialchars($transaction['rejection_reason'], ENT_QUOTES, 'UTF-8'); ?>"></i>
        <?php } ?>
    </td>
    <td class="text-nowrap">
        <a href="view.php?id=<?= (int) $transaction['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">View</a>
        <?php if ($isAdmin && $transaction['status'] === 'pending') { ?>
            <a href="approve.php?id=<?= (int) $transaction['id']; ?>" class="rm-btn rm-btn-success rm-btn-sm">Approve</a>
            <button type="button" class="rm-btn rm-btn-danger rm-btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal<?= (int) $transaction['id']; ?>">Reject</button>

            <div class="modal fade" id="rejectModal<?= (int) $transaction['id']; ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="reject.php">
                            <div class="modal-header">
                                <h5 class="modal-title">Reject Transaction #<?= (int) $transaction['id']; ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="id" value="<?= (int) $transaction['id']; ?>">
                                <label class="form-label small fw-semibold text-muted">Reason (optional)</label>
                                <textarea name="reason" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="rm-btn rm-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="rm-btn rm-btn-danger">Reject</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php } ?>
        <?php if ($isAdmin) { ?>
         <a href="edit.php?id=<?= (int) $transaction['id']; ?>" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a> 
         <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('Delete this transaction?')">
            <input type="hidden" name="id" value="<?= (int) $transaction['id']; ?>">
            <button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>
        </form>
        <?php } ?>
        </td></tr><?php } ?></table></div>
        </div>
        <div id="pageResultsPagination">
        <?php render_pagination($currentPage, $totalPages); ?>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>