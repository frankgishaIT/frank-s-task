<?php
/**
 * NEW FILE: modules/branches/reports.php — Branch & consolidated reports (spec: branch reports, consolidated company
 * reports, branch comparison, filters, drill-down, PDF / CSV).
 *  - Company-wide users: all branches, consolidated totals and the comparison.
 *  - Branch managers: only the branch(es) they are assigned to (checked on the server).
 * Figures: Revenue = confirmed sales (Paid, Partially Paid, Credit). Running expenses = approved expense transactions,
 * without stock purchases and capital movements (same rules as the Dashboard). Records without a branch = Main Branch.
 */
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/report_helpers.php';
require_once __DIR__ . '/../../includes/analytics_ui.php';
br_require_setup($conn);
if (!access_can('branches.view', $conn) || !in_array(current_user_role(), ['Admin', 'Manager'], true)) { br_deny(); }

if (empty($_GET['period'])) { $_GET['period'] = 'this_month'; }
$period = report_period_from_request($_GET);
if (!empty($period['error'])) { $err = $period['error']; $_GET['period'] = 'this_month'; $period = report_period_from_request($_GET); }
$from = $period['start'] ?? '2000-01-01';
$to = min($period['end'] ?? date('Y-m-d'), date('Y-m-d'));

$allowed = br_allowed_ids($conn);
$visible = array_filter(br_all($conn), function ($b) use ($allowed) { return $allowed === null || in_array((int) $b['id'], $allowed, true); });
$status = $_GET['status'] ?? 'all';
$shown = array_filter($visible, function ($b) use ($status) { return $status === 'all' || $b['status'] === $status; });
$drill = (int) ($_GET['drill'] ?? 0);
if ($drill && !isset($visible[$drill])) {
    br_audit($conn, $drill, 'access_denied', 'Tried to open the report of a branch without access.');
    br_deny();
}
$main = br_main_id($conn);
$ids = array_keys($shown) ?: [0];
$in = implode(',', array_map('intval', $ids));
$f = mysqli_real_escape_string($conn, $from); $t = mysqli_real_escape_string($conn, $to);
$capital = "'Loan Received', 'Loan Principal Recovered', 'Investor Capital Contribution', 'Customer Loan Disbursed', 'Investor Loan Principal Repaid', 'Investor Capital Returned'";
$stockCat = 'Purchase (Re-stock)';
$valid = "('Paid', 'Partially Paid', 'Credit')";

/** One figure per branch: [branch_id => value]. Missing tables (older installs) give 0. */
$per = function (string $sql) use ($conn): array {
    $o = [];
    try { foreach (mysqli_fetch_all(mysqli_query($conn, $sql), MYSQLI_ASSOC) as $r) { $o[(int) $r['b']] = (float) $r['v']; } }
    catch (Throwable $e) { error_log('branch report: ' . $e->getMessage()); }
    return $o;
};
$B = function (string $col) use ($main) { return "COALESCE($col, $main)"; };
$m = [
    'revenue'   => $per("SELECT {$B('branch_id')} b, SUM(total_amount) v FROM sales WHERE status IN $valid AND sale_date BETWEEN '$f' AND '$t' GROUP BY b"),
    'collected' => $per("SELECT {$B('branch_id')} b, SUM(amount_paid) v FROM sales WHERE status IN $valid AND sale_date BETWEEN '$f' AND '$t' GROUP BY b"),
    'sales'     => $per("SELECT {$B('branch_id')} b, COUNT(*) v FROM sales WHERE status IN $valid AND sale_date BETWEEN '$f' AND '$t' GROUP BY b"),
    'expenses'  => $per("SELECT {$B('branch_id')} b, SUM(amount) v FROM transactions WHERE transaction_type = 'Expense' AND status = 'approved' AND category <> '$stockCat' AND category NOT IN ($capital) AND transaction_date BETWEEN '$f' AND '$t' GROUP BY b"),
    'stock_buy' => $per("SELECT {$B('branch_id')} b, SUM(amount) v FROM transactions WHERE transaction_type = 'Expense' AND status = 'approved' AND category = '$stockCat' AND transaction_date BETWEEN '$f' AND '$t' GROUP BY b"),
    'payroll'   => $per("SELECT {$B('branch_id')} b, SUM(net_salary) v FROM payroll WHERE pay_period BETWEEN '" . substr($f, 0, 7) . "-01' AND '$t' GROUP BY b"),
    'att_total' => $per("SELECT {$B('branch_id')} b, COUNT(*) v FROM attendance WHERE attendance_date BETWEEN '$f' AND '$t' GROUP BY b"),
    'att_ok'    => $per("SELECT {$B('branch_id')} b, COUNT(*) v FROM attendance WHERE attendance_date BETWEEN '$f' AND '$t' AND status IN ('Present', 'Late') GROUP BY b"),
    'tasks_done'=> $per("SELECT {$B('branch_id')} b, COUNT(*) v FROM tasks WHERE status IN ('Completed', 'Approved') AND DATE(created_at) BETWEEN '$f' AND '$t' GROUP BY b"),
    'tasks_all' => $per("SELECT {$B('branch_id')} b, COUNT(*) v FROM tasks WHERE DATE(created_at) BETWEEN '$f' AND '$t' GROUP BY b"),
    'pos'       => $per("SELECT {$B('branch_id')} b, SUM(total_amount) v FROM purchase_orders WHERE status <> 'Cancelled' AND order_date BETWEEN '$f' AND '$t' GROUP BY b"),
    'customers' => $per("SELECT {$B('created_branch_id')} b, COUNT(*) v FROM customers WHERE DATE(created_at) BETWEEN '$f' AND '$t' GROUP BY b"),
];
$t0 = date('Y-m-d');
$m['employees'] = $per("SELECT branch_id b, COUNT(DISTINCT user_id) v FROM employee_branch_assignments WHERE status = 'active' AND start_date <= '$t0' AND (end_date IS NULL OR end_date >= '$t0') GROUP BY branch_id");

$row = function (int $id) use ($m) {
    $g = function ($k) use ($m, $id) { return (float) ($m[$k][$id] ?? 0); };
    $r = ['revenue' => $g('revenue'), 'collected' => $g('collected'), 'sales' => (int) $g('sales'), 'expenses' => $g('expenses'), 'stock_buy' => $g('stock_buy'),
          'payroll' => $g('payroll'), 'employees' => (int) $g('employees'), 'customers' => (int) $g('customers'), 'pos' => $g('pos'),
          'att_total' => (int) $g('att_total'), 'att_ok' => (int) $g('att_ok'), 'tasks_done' => (int) $g('tasks_done'), 'tasks_all' => (int) $g('tasks_all')];
    $r['result'] = $r['revenue'] - $r['expenses'];
    $r['avg_sale'] = $r['sales'] ? $r['revenue'] / $r['sales'] : 0;
    $r['att_rate'] = $r['att_total'] ? round($r['att_ok'] * 100 / $r['att_total'], 1) : null;
    $r['task_rate'] = $r['tasks_all'] ? round($r['tasks_done'] * 100 / $r['tasks_all'], 1) : null;
    return $r;
};
$rows = []; $tot = null;
foreach ($shown as $id => $b) {
    $rows[$id] = $row($id);
    if ($tot === null) { $tot = array_map(function () { return 0; }, $rows[$id]); }
    foreach (['revenue', 'collected', 'sales', 'expenses', 'stock_buy', 'payroll', 'employees', 'customers', 'pos', 'att_total', 'att_ok', 'tasks_done', 'tasks_all', 'result'] as $k) { $tot[$k] += $rows[$id][$k]; }
}
$tot = $tot ?? array_fill_keys(['revenue', 'collected', 'sales', 'expenses', 'stock_buy', 'payroll', 'employees', 'customers', 'pos', 'att_total', 'att_ok', 'tasks_done', 'tasks_all', 'result'], 0);
$tot['avg_sale'] = $tot['sales'] ? $tot['revenue'] / $tot['sales'] : 0;
$tot['att_rate'] = $tot['att_total'] ? round($tot['att_ok'] * 100 / $tot['att_total'], 1) : null;
$tot['task_rate'] = $tot['tasks_all'] ? round($tot['tasks_done'] * 100 / $tot['tasks_all'], 1) : null;
$rank = $rows; uasort($rank, function ($a, $b) { return $b['revenue'] <=> $a['revenue']; });
$money = function ($v) { return 'RWF ' . number_format((float) $v, 0); };
$pct = function ($v) { return $v === null ? '—' : $v . '%'; };
$short = function ($n) { return trim(preg_replace('/^RISE MOTIVE\s*[–-]\s*/u', '', $n)); };

/* Monthly trend per branch (revenue) */
$months = an_months($from, $to);
$trend = [];
try {
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT {$B('branch_id')} b, DATE_FORMAT(sale_date, '%Y-%m') ym, SUM(total_amount) v FROM sales WHERE status IN $valid AND sale_date BETWEEN '$f' AND '$t' GROUP BY b, ym"), MYSQLI_ASSOC) as $r) {
        $trend[(int) $r['b']][$r['ym']] = (float) $r['v'];
    }
} catch (Throwable $e) { error_log('branch trend: ' . $e->getMessage()); }

/* Drill-down: one branch in detail */
$d = null;
if ($drill) {
    $q = function (string $sql) use ($conn) { try { return mysqli_fetch_all(mysqli_query($conn, $sql), MYSQLI_ASSOC); } catch (Throwable $e) { error_log('branch drill: ' . $e->getMessage()); return []; } };
    $w = "{$B('s.branch_id')} = $drill";
    $d = [
        'products' => $q("SELECT COALESCE(p.product_name, si.service_name, 'Item') name, si.item_type, SUM(si.quantity) qty, SUM(si.line_total) total FROM sale_items si JOIN sales s ON s.id = si.sale_id LEFT JOIN products p ON p.id = si.product_id
                          WHERE $w AND s.status IN $valid AND s.sale_date BETWEEN '$f' AND '$t' GROUP BY name, si.item_type ORDER BY total DESC LIMIT 10"),
        'staff'    => $q("SELECT u.names name, COUNT(*) n, SUM(s.total_amount) total FROM sales s LEFT JOIN users u ON u.id = s.recorded_by WHERE $w AND s.status IN $valid AND s.sale_date BETWEEN '$f' AND '$t' GROUP BY u.names ORDER BY total DESC LIMIT 10"),
        'methods'  => $q("SELECT s.payment_method name, COUNT(*) n, SUM(s.total_amount) total FROM sales s WHERE $w AND s.status IN $valid AND s.sale_date BETWEEN '$f' AND '$t' GROUP BY s.payment_method ORDER BY total DESC"),
        'expcat'   => $q("SELECT COALESCE(NULLIF(t.expense_category, ''), t.category) name, COUNT(*) n, SUM(t.amount) total FROM transactions t WHERE {$B('t.branch_id')} = $drill AND t.transaction_type = 'Expense' AND t.status = 'approved'
                          AND t.category NOT IN ($capital) AND t.transaction_date BETWEEN '$f' AND '$t' GROUP BY name ORDER BY total DESC LIMIT 10"),
        'recent'   => $q("SELECT s.id, s.sale_date, s.total_amount, s.status, c.name customer FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE $w AND s.sale_date BETWEEN '$f' AND '$t' ORDER BY s.sale_date DESC, s.id DESC LIMIT 15"),
    ];
}

$branchLabel = $drill ? $visible[$drill]['name'] : (count($shown) === count(br_all($conn)) ? 'All branches (consolidated)' : implode(', ', array_map(function ($b) use ($short) { return $short($b['name']); }, $shown)));
$filters = [['Period', $period['label'] ?? ($from . ' to ' . $to)], ['Branches', $branchLabel], ['Status', $status === 'all' ? 'All' : $status]];
$cols = ['Branch', 'Code', 'Status', 'Revenue', 'Collected', 'Sales', 'Avg sale', 'Running expenses', 'Result', 'Stock purchases', 'Payroll (net)', 'Staff', 'Attendance', 'Tasks done', 'New customers'];
$tableRows = [];
foreach ($rank as $id => $r) {
    $b = $shown[$id];
    $tableRows[] = [$b['name'], $b['code'], $b['status'], number_format($r['revenue'], 0), number_format($r['collected'], 0), $r['sales'], number_format($r['avg_sale'], 0), number_format($r['expenses'], 0),
        number_format($r['result'], 0), number_format($r['stock_buy'], 0), number_format($r['payroll'], 0), $r['employees'], $pct($r['att_rate']), $pct($r['task_rate']), $r['customers']];
}
$tableRows[] = ['TOTAL (consolidated)', '', count($shown) . ' branches', number_format($tot['revenue'], 0), number_format($tot['collected'], 0), $tot['sales'], number_format($tot['avg_sale'], 0), number_format($tot['expenses'], 0),
    number_format($tot['result'], 0), number_format($tot['stock_buy'], 0), number_format($tot['payroll'], 0), $tot['employees'], $pct($tot['att_rate']), $pct($tot['task_rate']), $tot['customers']];

/* Exports */
if (($_GET['export'] ?? '') === 'csv') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="branch-report-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    foreach ($filters as $x) { fputcsv($o, $x); }
    fputcsv($o, []);
    fputcsv($o, $cols);
    foreach ($tableRows as $r) { fputcsv($o, array_map(function ($c) { return is_string($c) && preg_match('/^-?[\d,]+$/', $c) ? str_replace(',', '', $c) : $c; }, $r)); } // plain numbers for Excel
    fclose($o);
    br_audit($conn, $drill ?: null, 'report_export', 'Branch report exported (CSV): ' . $branchLabel . ', ' . $from . ' to ' . $to . '.');
    exit;
}
$src = $drill ? $rows[$drill] ?? $row($drill) : $tot;
$summary = [['Revenue', $money($src['revenue'])], ['Collected', $money($src['collected'])], ['Running expenses', $money($src['expenses'])], ['Result', $money($src['result'])],
    ['Sales', number_format($src['sales'])], ['Average sale', $money($src['avg_sale'])], ['Stock purchases', $money($src['stock_buy'])], ['Payroll (net)', $money($src['payroll'])],
    ['Attendance rate', $pct($src['att_rate'])], ['Tasks done', $pct($src['task_rate'])]];
if (($_GET['export'] ?? '') === 'pdf') {
    $tables = [['title' => 'Branch comparison', 'columns' => $cols, 'rows' => $tableRows]];
    if ($d) {
        $tables[] = ['title' => 'Best-selling products & services', 'columns' => ['Product / service', 'Type', 'Quantity', 'Amount (RWF)'], 'rows' => array_map(function ($r) { return [$r['name'], $r['item_type'], (float) $r['qty'], number_format($r['total'], 0)]; }, $d['products'])];
        $tables[] = ['title' => 'Sales by employee', 'columns' => ['Employee', 'Sales', 'Amount (RWF)'], 'rows' => array_map(function ($r) { return [$r['name'] ?: '—', $r['n'], number_format($r['total'], 0)]; }, $d['staff'])];
        $tables[] = ['title' => 'Running expenses by category', 'columns' => ['Category', 'Entries', 'Amount (RWF)'], 'rows' => array_map(function ($r) { return [$r['name'], $r['n'], number_format($r['total'], 0)]; }, $d['expcat'])];
    }
    br_audit($conn, $drill ?: null, 'report_export', 'Branch report exported (PDF): ' . $branchLabel . ', ' . $from . ' to ' . $to . '.');
    an_render_pdf($drill ? 'Branch Report · ' . $visible[$drill]['name'] : 'Branch & Consolidated Report', $filters, $summary, an_posted_charts(), $tables, 'branch-report-' . date('Ymd') . '.pdf');
}

$qs = $_GET; unset($qs['export']);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
br_tabs($conn, 'reports.php');
?>
<?php if (!empty($err)) { ?><div class="alert alert-danger"><?= br_e($err); ?></div><?php } ?>
<form class="row g-2 mb-3 align-items-end" method="GET">
    <?php if ($drill) { ?><input type="hidden" name="drill" value="<?= $drill; ?>"><?php } ?>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Period</label><select name="period" class="form-select form-select-sm" id="brP">
        <?php foreach (report_period_options() as $k => $l) { ?><option value="<?= $k; ?>" <?= ($period['preset'] ?? '') === $k ? 'selected' : ''; ?>><?= br_e($l); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2 br-c"><label class="form-label small mb-0">From</label><input type="date" name="from" value="<?= br_e($_GET['from'] ?? ''); ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2 br-c"><label class="form-label small mb-0">To</label><input type="date" name="to" value="<?= br_e($_GET['to'] ?? ''); ?>" class="form-control form-control-sm"></div>
    <?php if (!$drill) { ?><div class="col-6 col-md-2"><label class="form-label small mb-0">Branch status</label><select name="status" class="form-select form-select-sm">
        <?php foreach (['all' => 'All', 'Active' => 'Active', 'Suspended' => 'Temporarily Suspended', 'Closed' => 'Closed'] as $k => $l) { ?><option value="<?= $k; ?>" <?= $status === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div><?php } ?>
    <div class="col-6 col-md-1"><button class="btn btn-sm btn-primary w-100">Show</button></div>
</form>
<div class="d-flex gap-2 justify-content-end mb-2" style="margin-top:-6px;">
    <a class="btn btn-sm btn-outline-secondary" href="?<?= br_e(http_build_query($qs + ['export' => 'csv'])); ?>"><i class="bi bi-filetype-csv"></i> CSV / Excel</a>
    <?php an_pdf_button('PDF'); ?>
</div>
<script>(function(){var p=document.getElementById('brP');function f(){document.querySelectorAll('.br-c').forEach(function(e){e.style.display=p.value==='custom'?'':'none';});}p.addEventListener('change',f);f();})();</script>

<?php if ($drill) { $b = $visible[$drill]; ?>
<div class="d-flex align-items-center gap-2 mb-2"><a href="?<?= br_e(http_build_query(array_diff_key($qs, ['drill' => 1]))); ?>" class="btn btn-sm btn-light border"><i class="bi bi-arrow-left"></i> All branches</a>
    <h5 class="mb-0"><?= br_e($b['name']); ?> <span class="text-muted small"><?= br_e($b['code']); ?></span> <?= br_badge($b['status']); ?></h5>
    <a href="view.php?id=<?= $drill; ?>" class="small ms-2">Branch profile</a></div>
<?php } else { ?>
<div class="small text-muted mb-2">Consolidated = the sum of the branches shown (<?= count($shown); ?>). Click a branch to see its details.</div>
<?php } ?>

<div class="row row-cols-2 row-cols-md-5 g-2 mb-3">
    <?php foreach ($summary as [$k, $v]) { ?><div class="col"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2 px-3"><div class="small text-muted"><?= br_e($k); ?></div><div class="fw-bold" style="font-size:1.05rem;"><?= br_e($v); ?></div></div></div></div><?php } ?>
</div>

<?php if (!$drill) { ?>
<div class="row g-3 mb-3">
    <div class="col-lg-7"><?php an_chart_card('brCmp', 'Revenue, expenses and result by branch', br_e($period['label'] ?? ''), 260); ?></div>
    <div class="col-lg-5"><?php an_chart_card('brShare', 'Share of company revenue', 'Each branch\'s part of the consolidated revenue', 260); ?></div>
    <div class="col-12"><?php an_chart_card('brTrend', 'Monthly revenue by branch', 'Confirmed sales per month', 240); ?></div>
</div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0" style="font-size:12.5px;">
    <tr class="table-light"><?php foreach ($cols as $i => $c) { if ($i === 1) { continue; } ?><th class="<?= $i > 2 ? 'text-end' : ''; ?> <?= $i === 0 ? 'ps-3' : ''; ?>"><?= br_e($c); ?></th><?php } ?></tr>
    <?php $i = 0; foreach ($rank as $id => $r) { $b = $shown[$id]; $i++; ?>
    <tr style="cursor:pointer;" onclick="location.href='?<?= br_e(http_build_query($qs + ['drill' => $id])); ?>'">
        <td class="ps-3"><span class="text-muted small"><?= $i; ?>.</span> <a href="?<?= br_e(http_build_query($qs + ['drill' => $id])); ?>" class="text-nowrap"><?= br_e($short($b['name'])); ?></a><div class="small text-muted"><?= br_e($b['code']); ?></div></td>
        <td><?= br_badge($b['status']); ?></td>
        <td class="text-end fw-semibold"><?= number_format($r['revenue'], 0); ?></td><td class="text-end"><?= number_format($r['collected'], 0); ?></td><td class="text-end"><?= $r['sales']; ?></td>
        <td class="text-end"><?= number_format($r['avg_sale'], 0); ?></td><td class="text-end"><?= number_format($r['expenses'], 0); ?></td>
        <td class="text-end fw-semibold <?= $r['result'] < 0 ? 'text-danger' : 'text-success'; ?>"><?= number_format($r['result'], 0); ?></td>
        <td class="text-end"><?= number_format($r['stock_buy'], 0); ?></td><td class="text-end"><?= number_format($r['payroll'], 0); ?></td><td class="text-end"><?= $r['employees']; ?></td>
        <td class="text-end"><?= $pct($r['att_rate']); ?></td><td class="text-end"><?= $pct($r['task_rate']); ?></td><td class="text-end"><?= $r['customers']; ?></td>
    </tr>
    <?php } ?>
    <tr class="table-light fw-bold"><td class="ps-3">TOTAL (consolidated)</td><td><?= count($shown); ?></td>
        <td class="text-end"><?= number_format($tot['revenue'], 0); ?></td><td class="text-end"><?= number_format($tot['collected'], 0); ?></td><td class="text-end"><?= $tot['sales']; ?></td>
        <td class="text-end"><?= number_format($tot['avg_sale'], 0); ?></td><td class="text-end"><?= number_format($tot['expenses'], 0); ?></td>
        <td class="text-end <?= $tot['result'] < 0 ? 'text-danger' : ''; ?>"><?= number_format($tot['result'], 0); ?></td><td class="text-end"><?= number_format($tot['stock_buy'], 0); ?></td>
        <td class="text-end"><?= number_format($tot['payroll'], 0); ?></td><td class="text-end"><?= $tot['employees']; ?></td><td class="text-end"><?= $pct($tot['att_rate']); ?></td><td class="text-end"><?= $pct($tot['task_rate']); ?></td><td class="text-end"><?= $tot['customers']; ?></td></tr>
</table></div></div></div>
<div class="small text-muted mt-2">Amounts in RWF. Revenue = confirmed sales (Paid, Partially Paid, Credit). Running expenses exclude stock purchases and capital movements (loans, investor capital). Staff = employees assigned to the branch today (an employee working in two branches is counted in both; the total counts each assignment). Records created before the multi-branch setup belong to the Main Branch.</div>
<?php } else { ?>
<div class="row g-3">
    <div class="col-lg-6"><?php an_chart_card('brOne', 'Monthly revenue', br_e($visible[$drill]['name']), 240); ?></div>
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6>Best-selling products & services</h6>
        <table class="table table-sm small mb-0"><tr><th>Product / service</th><th class="text-end">Qty</th><th class="text-end">Amount</th></tr>
        <?php foreach ($d['products'] as $r) { ?><tr><td><?= br_e($r['name']); ?> <span class="text-muted"><?= br_e($r['item_type']); ?></span></td><td class="text-end"><?= (float) $r['qty']; ?></td><td class="text-end"><?= number_format($r['total'], 0); ?></td></tr><?php } ?>
        <?php if (!$d['products']) { ?><tr><td colspan="3" class="text-muted">No sales in this period.</td></tr><?php } ?></table></div></div></div>
    <div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6>Sales by employee</h6>
        <table class="table table-sm small mb-0"><?php foreach ($d['staff'] as $r) { ?><tr><td><?= br_e($r['name'] ?: '—'); ?></td><td class="text-end"><?= (int) $r['n']; ?></td><td class="text-end"><?= number_format($r['total'], 0); ?></td></tr><?php } ?>
        <?php if (!$d['staff']) { ?><tr><td class="text-muted">—</td></tr><?php } ?></table></div></div></div>
    <div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6>Payment methods</h6>
        <table class="table table-sm small mb-0"><?php foreach ($d['methods'] as $r) { ?><tr><td><?= br_e($r['name']); ?></td><td class="text-end"><?= (int) $r['n']; ?></td><td class="text-end"><?= number_format($r['total'], 0); ?></td></tr><?php } ?>
        <?php if (!$d['methods']) { ?><tr><td class="text-muted">—</td></tr><?php } ?></table></div></div></div>
    <div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6>Running expenses by category</h6>
        <table class="table table-sm small mb-0"><?php foreach ($d['expcat'] as $r) { ?><tr><td><?= br_e($r['name']); ?></td><td class="text-end"><?= (int) $r['n']; ?></td><td class="text-end"><?= number_format($r['total'], 0); ?></td></tr><?php } ?>
        <?php if (!$d['expcat']) { ?><tr><td class="text-muted">No expenses in this period.</td></tr><?php } ?></table></div></div></div>
    <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body"><h6>Latest sales in this branch</h6>
        <table class="table table-sm small mb-0"><tr><th>Sale</th><th>Date</th><th>Customer</th><th>Status</th><th class="text-end">Amount</th></tr>
        <?php foreach ($d['recent'] as $r) { ?><tr><td><a href="../sales/invoice.php?id=<?= (int) $r['id']; ?>" target="_blank">#<?= (int) $r['id']; ?></a></td><td><?= br_e($r['sale_date']); ?></td><td><?= br_e($r['customer'] ?: 'Walk-in Customer'); ?></td><td><?= br_e($r['status']); ?></td><td class="text-end"><?= number_format($r['total_amount'], 0); ?></td></tr><?php } ?>
        <?php if (!$d['recent']) { ?><tr><td colspan="5" class="text-muted">No sales in this period.</td></tr><?php } ?></table>
        <div class="small text-muted mt-2">To open the full Sales, Expenses, Attendance or Payroll lists of this branch, choose it with the branch selector at the top: every list then shows this branch only.</div>
    </div></div></div>
</div>
<?php } ?>

<?php an_js(); ?>
<script>
(function () {
    var P = window.rmPalette || ['#1E2FE0', '#0FA968', '#F59E0B', '#7C5CE0', '#EF4444', '#06B6D4', '#EC4899', '#84CC16'];
    var months = <?= json_encode(array_values($months)); ?>, keys = <?= json_encode(array_keys($months)); ?>;
    var trend = <?= json_encode($trend); ?>;
    var names = <?= json_encode(array_map(function ($b) use ($short) { return $short($b['name']); }, $shown)); ?>;
    var money = function (v) { return 'RWF ' + Math.round(v).toLocaleString(); };
    <?php if (!$drill) { $ord = array_keys($rank); ?>
    var ids = <?= json_encode($ord); ?>, R = <?= json_encode(array_map(function ($id) use ($rows) { return [$rows[$id]['revenue'], $rows[$id]['expenses'], $rows[$id]['result']]; }, $ord)); ?>;
    if (document.getElementById('brCmp')) new Chart(document.getElementById('brCmp'), { type: 'bar', data: { labels: ids.map(function (i) { return names[i]; }), datasets: [
        { label: 'Revenue', data: R.map(function (x) { return x[0]; }), backgroundColor: '#1E2FE0' },
        { label: 'Running expenses', data: R.map(function (x) { return x[1]; }), backgroundColor: '#F59E0B' },
        { label: 'Result', data: R.map(function (x) { return x[2]; }), backgroundColor: '#0FA968' }] },
        options: { maintainAspectRatio: false, onClick: function (e, el) { if (el.length) { location.href = '?<?= http_build_query($qs); ?>&drill=' + ids[el[0].index]; } },
            plugins: { tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + money(c.raw); } } } } } });
    if (document.getElementById('brShare')) new Chart(document.getElementById('brShare'), { type: 'doughnut', data: { labels: ids.map(function (i) { return names[i]; }),
        datasets: [{ data: R.map(function (x) { return x[0]; }), backgroundColor: P }] }, options: { maintainAspectRatio: false, plugins: { legend: { position: 'right' } } } });
    if (document.getElementById('brTrend')) new Chart(document.getElementById('brTrend'), { type: 'line', data: { labels: months, datasets: ids.map(function (id, i) {
        return { label: names[id], data: keys.map(function (k) { return (trend[id] || {})[k] || 0; }), borderColor: P[i % P.length], backgroundColor: P[i % P.length], tension: .3 }; }) },
        options: { maintainAspectRatio: false } });
    <?php } else { ?>
    if (document.getElementById('brOne')) new Chart(document.getElementById('brOne'), { type: 'bar', data: { labels: months, datasets: [{ label: 'Revenue', data: keys.map(function (k) { return (trend[<?= $drill; ?>] || {})[k] || 0; }), backgroundColor: '#1E2FE0' }] },
        options: { maintainAspectRatio: false, plugins: { legend: { display: false } } } });
    <?php } ?>
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>