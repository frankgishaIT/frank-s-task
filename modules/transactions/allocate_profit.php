<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require_once '../../includes/profit_rules.php';
require_once '../../includes/fund_helpers.php'; // also provides build_allocation(), sale_profit(), allocate_sale_profit()

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?error=' . urlencode('Only an admin can allocate Net Profit.'));
    exit;
}
$currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

/* ---------- helpers ---------- */

function valid_period($p): bool {
    return is_string($p) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p) === 1 && $p <= date('Y-m');
}

// Finds the date column of the sales table (names differ between systems).
function sales_date_column(mysqli $conn): ?string {
    $cols = [];
    $res = mysqli_query($conn, 'SHOW COLUMNS FROM sales');
    while ($r = mysqli_fetch_assoc($res)) { $cols[] = $r['Field']; }
    foreach (['sale_date', 'sales_date', 'created_at', 'date'] as $c) {
        if (in_array($c, $cols, true)) { return $c; }
    }
    return null;
}

// NEW: the first month in which profit is allocated AUTOMATICALLY, sale by sale (spec section 1).
// From this month on, the monthly allocation below is switched off, otherwise the same profit
// would go into the Funds twice. If no sale has been allocated yet, it is the current month.
function auto_allocation_start(mysqli $conn): string {
    $row = mysqli_fetch_row(mysqli_query($conn, "SELECT MIN(period) FROM fund_movements WHERE ref_type = 'SALE' AND movement_type = 'ALLOCATION'"));
    $first = $row[0] ?? null;
    $now = date('Y-m');
    return ($first && $first < $now) ? $first : $now;
}

// NEW: fully Paid sales of a month whose profit has not reached the Funds yet
// (for example sales paid before the automatic allocation was installed).
function unallocated_paid_sales(mysqli $conn, string $period, string $dateCol): array {
    $start = $period . '-01';
    $end = date('Y-m-d', strtotime($start . ' +1 month'));
    $s = mysqli_prepare($conn, "SELECT s.id FROM sales s
        WHERE s.status = 'Paid' AND s.`$dateCol` >= ? AND s.`$dateCol` < ?
          AND NOT EXISTS (SELECT 1 FROM fund_movements m
                          WHERE m.ref_type = 'SALE' AND m.ref_id = s.id AND m.movement_type = 'ALLOCATION')
        ORDER BY s.id");
    mysqli_stmt_bind_param($s, 'ss', $start, $end);
    mysqli_stmt_execute($s);
    return array_map('intval', array_column(mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC), 'id'));
}

// NEW: profit allocated automatically to the four Funds and posted in this month
// (allocations minus reversals of cancelled sales). The RM Capital Fund is excluded.
function auto_allocated_total(mysqli $conn, string $period): float {
    $s = mysqli_prepare($conn, "SELECT COALESCE(SUM(CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END), 0) AS t
        FROM fund_movements m JOIN funds f ON f.id = m.fund_id
        WHERE m.ref_type = 'SALE' AND m.movement_type IN ('ALLOCATION', 'REVERSAL')
          AND f.code <> 'CAPITAL' AND m.period = ?");
    mysqli_stmt_bind_param($s, 's', $period);
    mysqli_stmt_execute($s);
    return round((float) mysqli_fetch_assoc(mysqli_stmt_get_result($s))['t'], 2);
}

// Net Profit = sales profit of the month - approved business expenses of the month.
// Expenses paid from the Capital Fund (stock purchases, loan principal) are NOT deducted:
// the cost of stock is already inside product profit (selling - buying price).
// Used only for months BEFORE the automatic allocation started.
function compute_period_profit(mysqli $conn, string $period, string $dateCol): array {
    $start = $period . '-01';
    $end = date('Y-m-d', strtotime($start . ' +1 month'));

    $lineNet  = sales_report_line_net_sql('sales', 'sale_items');
    $lineCost = sales_report_line_cost_sql('sales', 'sale_items', 'products');
    $sql = "SELECT
            COALESCE(SUM(CASE WHEN sale_items.item_type = 'Product' THEN $lineNet - $lineCost ELSE 0 END), 0) AS product_profit,
            COALESCE(SUM(CASE WHEN sale_items.item_type = 'Service' THEN $lineNet - $lineCost ELSE 0 END), 0) AS service_profit
        FROM sale_items
        JOIN sales ON sale_items.sale_id = sales.id
        LEFT JOIN products ON sale_items.product_id = products.id
        WHERE sales.status NOT IN ('Pending Discount Approval', 'Cancelled')
          AND sales.`$dateCol` >= ? AND sales.`$dateCol` < ?";
    $s = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($s, 'ss', $start, $end);
    mysqli_stmt_execute($s);
    $p = mysqli_fetch_assoc(mysqli_stmt_get_result($s));

    $e = mysqli_prepare($conn, "SELECT COALESCE(SUM(t.amount), 0) AS total
        FROM transactions t
        LEFT JOIN funds f ON t.fund_id = f.id
        WHERE t.transaction_type = 'Expense' AND t.status = 'approved'
          AND t.transaction_date >= ? AND t.transaction_date < ?
          AND (f.code IS NULL OR f.code <> 'CAPITAL')");
    mysqli_stmt_bind_param($e, 'ss', $start, $end);
    mysqli_stmt_execute($e);
    $expenses = (float) mysqli_fetch_assoc(mysqli_stmt_get_result($e))['total'];

    $product = round((float) $p['product_profit'], 2);
    $service = round((float) $p['service_profit'], 2);
    return [
        'product' => $product,
        'service' => $service,
        'expenses' => round($expenses, 2),
        'net' => round($product + $service - $expenses, 2),
    ];
}

// CHANGED: build_allocation() was removed from this page. It now lives in fund_helpers.php
// (shared with the automatic allocation). Declaring it twice stops PHP with
// "Cannot redeclare build_allocation()".

/* ---------- confirm & allocate ---------- */

$salesDateCol = sales_date_column($conn);
$funds = funds_all($conn);
$autoFrom = auto_allocation_start($conn);

if (isset($_POST['confirm'])) {
    $period = $_POST['period'] ?? '';
    if (!valid_period($period)) {
        $error = 'Invalid month selected.';
    } elseif ($period >= $autoFrom) {
        // NEW: blocked. This month's profit is allocated automatically, sale by sale.
        $error = 'Profit from ' . date('F Y', strtotime($autoFrom . '-01')) . ' onwards is allocated to the Funds automatically when each sale is paid. Allocating this month again would count the same profit twice.';
    } elseif (!$salesDateCol) {
        $error = 'Could not find the date column of the sales table.';
    } else {
        try {
            mysqli_begin_transaction($conn);

            $chk = mysqli_prepare($conn, 'SELECT id FROM profit_allocations WHERE period = ? FOR UPDATE');
            mysqli_stmt_bind_param($chk, 's', $period);
            mysqli_stmt_execute($chk);
            if (mysqli_fetch_assoc(mysqli_stmt_get_result($chk))) {
                throw new RuntimeException('This month has already been allocated.');
            }

            // Always recalculated on the server. The browser's numbers are never trusted.
            $calc = compute_period_profit($conn, $period, $salesDateCol);
            if ($calc['net'] <= 0) {
                throw new RuntimeException('There is no Net Profit for this month, so nothing can be allocated.');
            }

            $ins = mysqli_prepare($conn, "INSERT INTO profit_allocations (period, product_profit, service_profit, expenses_deducted, net_profit, status, confirmed_by) VALUES (?, ?, ?, ?, ?, 'CONFIRMED', ?)");
            mysqli_stmt_bind_param($ins, 'sddddi', $period, $calc['product'], $calc['service'], $calc['expenses'], $calc['net'], $currentUserId);
            mysqli_stmt_execute($ins);
            $allocId = (int) mysqli_insert_id($conn);

            $mv = mysqli_prepare($conn, "INSERT INTO fund_movements (fund_id, period, movement_type, direction, amount, description, created_by, allocation_id) VALUES (?, ?, 'ALLOCATION', 'IN', ?, ?, ?, ?)");
            foreach (build_allocation($funds, $calc['net']) as $row) {
                $fid = (int) $row['fund']['id'];
                $amt = $row['amount'];
                $pct = rtrim(rtrim(number_format((float) $row['fund']['allocation_percent'], 2), '0'), '.');
                $desc = 'Net Profit allocation ' . $period . ' (' . $pct . '%)';
                mysqli_stmt_bind_param($mv, 'isdsii', $fid, $period, $amt, $desc, $currentUserId, $allocId);
                mysqli_stmt_execute($mv);
            }

            mysqli_commit($conn);
            header('Location: allocate_profit.php?period=' . urlencode($period) . '&success=' . urlencode('Net Profit for ' . $period . ' was allocated to the Funds.'));
            exit;
        } catch (mysqli_sql_exception $e) {
            mysqli_rollback($conn);
            error_log('allocate_profit failed: ' . $e->getMessage());
            $error = (int) $e->getCode() === 1062 ? 'This month has already been allocated.' : 'Unable to allocate the Net Profit.';
        } catch (RuntimeException $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('allocate_profit failed: ' . $e->getMessage());
            $error = 'Unable to allocate the Net Profit.';
        }
    }
}

// NEW: catch-up for automatic months. Allocates the profit of fully Paid sales that never
// reached the Funds (e.g. paid before the automatic allocation was installed).
// allocate_sale_profit() skips any sale already allocated, so this can never double count.
if (isset($_POST['allocate_missing'])) {
    $period = $_POST['period'] ?? '';
    if (!valid_period($period) || $period < $autoFrom) {
        $error = 'Invalid month selected.';
    } elseif (!$salesDateCol) {
        $error = 'Could not find the date column of the sales table.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            $missing = unallocated_paid_sales($conn, $period, $salesDateCol);
            foreach ($missing as $saleId) {
                allocate_sale_profit($conn, $saleId, $currentUserId);
            }
            mysqli_commit($conn);
            header('Location: allocate_profit.php?period=' . urlencode($period) . '&success=' . urlencode(count($missing) . ' sale(s) allocated to the Funds.'));
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('allocate_missing failed: ' . $e->getMessage());
            $error = 'Unable to allocate these sales. Nothing was changed.';
        }
    }
}

/* ---------- page data ---------- */

$period = $_POST['period'] ?? $_GET['period'] ?? date('Y-m', strtotime('first day of last month'));
if (!valid_period($period)) { $period = date('Y-m', strtotime('first day of last month')); }

$existing = null;
$existingRows = [];
$calc = null;
$preview = [];
$isAutoMonth = $period >= $autoFrom;
$missingSales = [];
$missingProfit = 0.0;
$autoTotal = 0.0;

$q = mysqli_prepare($conn, 'SELECT pa.*, u.names AS confirmer FROM profit_allocations pa LEFT JOIN users u ON pa.confirmed_by = u.id WHERE pa.period = ?');
mysqli_stmt_bind_param($q, 's', $period);
mysqli_stmt_execute($q);
$existing = mysqli_fetch_assoc(mysqli_stmt_get_result($q)) ?: null;

if ($existing) {
    $q2 = mysqli_prepare($conn, "SELECT f.name, f.allocation_percent, m.amount FROM fund_movements m JOIN funds f ON f.id = m.fund_id WHERE m.allocation_id = ? ORDER BY FIELD(f.code, 'FUTURE_PLANS', 'EMERGENCY', 'TEAM_GROWTH', 'OPERATING')");
    $allocIdView = (int) $existing['id'];
    mysqli_stmt_bind_param($q2, 'i', $allocIdView);
    mysqli_stmt_execute($q2);
    $existingRows = mysqli_fetch_all(mysqli_stmt_get_result($q2), MYSQLI_ASSOC);
} elseif ($isAutoMonth) {
    $autoTotal = auto_allocated_total($conn, $period);
    if ($salesDateCol) {
        $missingSales = unallocated_paid_sales($conn, $period, $salesDateCol);
        foreach ($missingSales as $saleId) { $missingProfit += sale_profit($conn, $saleId); }
        $missingProfit = round($missingProfit, 2);
    }
} elseif ($salesDateCol) {
    $calc = compute_period_profit($conn, $period, $salesDateCol);
    if ($calc['net'] > 0) { $preview = build_allocation($funds, $calc['net']); }
}

$history = mysqli_fetch_all(mysqli_query($conn, 'SELECT pa.*, u.names AS confirmer FROM profit_allocations pa LEFT JOIN users u ON pa.confirmed_by = u.id ORDER BY pa.period DESC LIMIT 24'), MYSQLI_ASSOC);

include '../../includes/header.php'; include '../../includes/sidebar.php';
$periodLabel = date('F Y', strtotime($period . '-01'));
$autoFromLabel = date('F Y', strtotime($autoFrom . '-01'));
?>
<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<?php if (isset($error)) { ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Allocate Net Profit</h2>
    <a href="index.php" class="rm-btn rm-btn-secondary">&larr; Back to Transactions</a>
</div>

<!-- NEW -->
<div class="alert alert-info" style="border-radius:10px;">
    <i class="bi bi-lightning-charge-fill me-1"></i>
    Since <strong><?= htmlspecialchars($autoFromLabel, ENT_QUOTES, 'UTF-8'); ?></strong>, profit is allocated to the Funds <strong>automatically</strong> when each sale is paid,
    and business expenses come out of the fund that pays them. This page is only needed for months before that, which were never allocated.
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold text-muted">Month</label>
                <input type="month" name="period" class="form-control" value="<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8'); ?>" max="<?= date('Y-m'); ?>" onchange="this.form.submit()">
            </div>
            <div class="col-md-8 text-muted small">
                Net Profit = sales profit of the month &minus; approved business expenses of the month.
                Expenses paid from the Capital Fund are not deducted, because the cost of stock is already inside product profit.
                Allocating moves the profit into the Funds. It is <strong>not</strong> an expense.
            </div>
        </form>
    </div>
</div>

<?php if (!$salesDateCol && !$existing) { ?>
<div class="alert alert-warning">The date column of the <code>sales</code> table could not be found, so profit cannot be calculated.</div>
<?php } ?>

<?php if ($existing) { ?>
    <div class="alert alert-success">
        <strong><?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?></strong> was allocated on
        <?= date('d M Y H:i', strtotime($existing['confirmed_at'])); ?>
        by <?= htmlspecialchars($existing['confirmer'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?>. This allocation is final.
    </div>
<?php } ?>

<?php if (!$existing && $isAutoMonth) { ?>
<!-- NEW: automatic month -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h6 class="mb-3"><?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?>: allocated automatically</h6>
        <p class="mb-2">Profit posted to the four Funds this month: <strong>RWF <?= number_format($autoTotal, 2); ?></strong>
            <span class="text-muted small">(after reversals of cancelled sales)</span></p>

        <?php if ($missingSales) { ?>
        <div class="alert alert-warning mb-3">
            <?= count($missingSales); ?> paid sale(s) from this month have not reached the Funds yet
            (RWF <?= number_format($missingProfit, 2); ?> profit). This happens for sales paid before the automatic allocation was installed.
        </div>
        <form method="POST" onsubmit="return confirm('Allocate the profit of these <?= count($missingSales); ?> sale(s) to the Funds?');">
            <input type="hidden" name="period" value="<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" name="allocate_missing" value="1" class="rm-btn rm-btn-primary">
                <i class="bi bi-check-circle-fill me-2"></i>Allocate These Sales
            </button>
        </form>
        <?php } else { ?>
        <div class="text-success small"><i class="bi bi-check-circle-fill me-1"></i>Every paid sale of this month has been allocated. Nothing to do.</div>
        <?php } ?>
    </div>
</div>
<?php } ?>

<?php if ($existing || $calc) {
    $pp = $existing ? (float) $existing['product_profit'] : $calc['product'];
    $sp = $existing ? (float) $existing['service_profit'] : $calc['service'];
    $ex = $existing ? (float) $existing['expenses_deducted'] : $calc['expenses'];
    $np = $existing ? (float) $existing['net_profit'] : $calc['net'];
    ?>
<div class="row mb-4">
    <div class="col-md-3"><div class="card border-info"><div class="card-body">
        <small class="text-muted">Product Profit</small>
        <h5 class="text-info mb-0">RWF <?= number_format($pp, 2); ?></h5></div></div></div>
    <div class="col-md-3"><div class="card border-warning"><div class="card-body">
        <small class="text-muted">Service Profit</small>
        <h5 class="text-warning mb-0">RWF <?= number_format($sp, 2); ?></h5></div></div></div>
    <div class="col-md-3"><div class="card border-danger"><div class="card-body">
        <small class="text-muted">Less: Business Expenses</small>
        <h5 class="text-danger mb-0">RWF <?= number_format($ex, 2); ?></h5></div></div></div>
    <div class="col-md-3"><div class="card border-primary"><div class="card-body">
        <small class="text-muted">Net Profit</small>
        <h5 class="text-primary mb-0">RWF <?= number_format($np, 2); ?></h5></div></div></div>
</div>

<?php if (!$existing && $np <= 0) { ?>
<div class="alert alert-warning">
    <?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?> has no Net Profit (zero or a loss), so nothing can be allocated.
</div>
<?php } else { ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered bg-white mb-0">
                <tr><th>Fund</th><th>Share</th><th class="text-end">Amount (RWF)</th></tr>
                <?php if ($existing) { foreach ($existingRows as $r) { ?>
                <tr>
                    <td><?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?= rtrim(rtrim(number_format((float) $r['allocation_percent'], 2), '0'), '.'); ?>%</td>
                    <td class="text-end"><?= number_format((float) $r['amount'], 2); ?></td>
                </tr>
                <?php } } else { foreach ($preview as $row) { ?>
                <tr>
                    <td><?= htmlspecialchars($row['fund']['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?= rtrim(rtrim(number_format((float) $row['fund']['allocation_percent'], 2), '0'), '.'); ?>%</td>
                    <td class="text-end"><?= number_format($row['amount'], 2); ?></td>
                </tr>
                <?php } } ?>
                <tr class="fw-bold"><td>Total allocated</td><td>100%</td><td class="text-end"><?= number_format($np, 2); ?></td></tr>
            </table>
        </div>
    </div>
</div>

<?php if (!$existing) { ?>
<form method="POST" onsubmit="return confirm('Allocate RWF <?= number_format($np, 2); ?> for <?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?>? This is final and cannot be changed.');">
    <input type="hidden" name="period" value="<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8'); ?>">
    <button type="submit" name="confirm" class="rm-btn rm-btn-primary">
        <i class="bi bi-check-circle-fill me-2"></i>Confirm Net Profit &amp; Allocate
    </button>
</form>
<?php } ?>
<?php } ?>
<?php } ?>

<h5 class="mt-5 mb-3">Monthly Allocation History</h5>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover bg-white mb-0">
                <tr><th>Month</th><th class="text-end">Sales Profit</th><th class="text-end">Expenses</th><th class="text-end">Net Profit</th><th>Confirmed By</th><th>Date</th></tr>
                <?php if (!$history) { ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No month has been allocated manually.</td></tr>
                <?php } foreach ($history as $h) { ?>
                <tr>
                    <td><a href="allocate_profit.php?period=<?= htmlspecialchars($h['period'], ENT_QUOTES, 'UTF-8'); ?>"><?= date('F Y', strtotime($h['period'] . '-01')); ?></a></td>
                    <td class="text-end"><?= number_format((float) $h['product_profit'] + (float) $h['service_profit'], 2); ?></td>
                    <td class="text-end"><?= number_format((float) $h['expenses_deducted'], 2); ?></td>
                    <td class="text-end fw-semibold"><?= number_format((float) $h['net_profit'], 2); ?></td>
                    <td><?= htmlspecialchars($h['confirmer'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?= date('d M Y', strtotime($h['confirmed_at'])); ?></td>
                </tr>
                <?php } ?>
            </table>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>