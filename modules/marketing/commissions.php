<?php
/**
 * NEW FILE: modules/marketing/commissions.php — COMMISSIONS: everyone sees their own lines (the wallet);
 * Finance / Administrators see all, recalculate, adjust eligible profit (with a reason), verify, approve or reject.
 * Chain: Calculated → Verified (Finance) → Approved (Administrator) → In payroll → Paid.
 */
require __DIR__ . '/mkt_common.php';
mkt_ensure_team($conn);
$canFin = access_can('marketing.finance', $conn); $canApprove = access_can('marketing.approve', $conn); $seesTeam = $canFin || $canApprove || mkt_is_manager();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? ''); $note = trim((string) ($_POST['note'] ?? ''));
    try {
        if ($act === 'recalculate') {
            if (!$canFin && !$canApprove) { throw new RuntimeException('Only Finance / Administrators recalculate.'); }
            mkt_refresh_acquisitions($conn); $r = mkt_calculate_commissions($conn);
            $msg = "Recalculated: $r[made] new line(s), $r[updated] updated, $r[reversed] reversed, $r[clawbacks] clawback(s).";
        } elseif ($act === 'adjust') {
            if (!$canFin) { throw new RuntimeException('Only Finance adjusts the eligible profit.'); }
            $id = (int) $_POST['id']; $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_commissions WHERE id = $id"));
            $p = round((float) $_POST['profit'], 2);
            if (!$c || $c['status'] !== 'Calculated' || $c['rate'] === null) { throw new RuntimeException('Only a calculated (not yet verified) commission can be adjusted.'); }
            if ($p < 0 || mb_strlen($note) < 10) { throw new RuntimeException('Enter the corrected eligible profit and a clear reason (at least 10 characters).'); }
            $amount = round($p * (float) $c['rate'] / 100 * (float) $c['share_pct'] / 100, 2);
            $s = mysqli_prepare($conn, 'UPDATE mkt_commissions SET profit_adjusted = ?, adjust_reason = ?, amount = ? WHERE id = ? AND status = \'Calculated\'');
            mysqli_stmt_bind_param($s, 'dsdi', $p, $note, $amount, $id); mysqli_stmt_execute($s);
            mkt_audit($conn, 'Eligible profit adjusted by Finance', 'COM-' . $id, ['profit' => $c['profit_adjusted'] ?? $c['eligible_profit'], 'amount' => $c['amount']], ['profit' => $p, 'amount' => $amount], $note, $me);
            $msg = 'Adjusted: commission is now RWF ' . number_format($amount, 2) . '.';
        } else {
            $n = mkt_commission_decide($conn, (array) ($_POST['ids'] ?? []), $act, $me, $isAdmin, $note);
            $msg = $n . ' line(s) ' . ['verify' => 'verified', 'approve' => 'approved', 'reject' => 'rejected'][$act] . '.' . ($n === 0 ? ' (Nothing matched: check their status; your own lines need another approver.)' : '');
        }
        header('Location: commissions.php?' . http_build_query(['success' => $msg] + array_intersect_key($_GET, ['status' => 1, 'user' => 1, 'period' => 1]))); exit;
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
}
$st = in_array($_GET['status'] ?? '', array_merge(MKT_COMM_FLOW, ['Reversed', 'Rejected']), true) ? $_GET['status'] : '';
$user = $seesTeam ? (int) ($_GET['user'] ?? 0) : $me; $period = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['period'] ?? '')) ? $_GET['period'] : '';
$w = $seesTeam ? ($user ? ["c.user_id = $user"] : ['1 = 1']) : ["c.user_id = $me"];
if ($st !== '') { $w[] = "c.status = '" . mysqli_real_escape_string($conn, $st) . "'"; } if ($period) { $w[] = "c.period = '$period'"; }
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT c.*, u.names, cu.name AS customer, cp.name AS campaign FROM mkt_commissions c JOIN users u ON u.id = c.user_id LEFT JOIN customers cu ON cu.id = c.customer_id
    LEFT JOIN mkt_campaigns cp ON cp.id = c.campaign_id WHERE ' . implode(' AND ', $w) . ' ORDER BY c.id DESC LIMIT 500'), MYSQLI_ASSOC);
$sum = mysqli_fetch_all(mysqli_query($conn, 'SELECT c.status, SUM(c.amount) s, COUNT(*) n FROM mkt_commissions c WHERE ' . ($seesTeam ? ($user ? "c.user_id = $user" : '1 = 1') : "c.user_id = $me") . ' GROUP BY c.status'), MYSQLI_ASSOC);
$tot = array_column($sum, 's', 'status');
$users = $seesTeam ? mysqli_fetch_all(mysqli_query($conn, 'SELECT DISTINCT u.id, u.names FROM mkt_commissions c JOIN users u ON u.id = c.user_id ORDER BY u.names'), MYSQLI_ASSOC) : [];
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('commissions.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<div class="row g-3 mb-3">
    <?php foreach (['Waiting for Finance' => $tot['Calculated'] ?? 0, 'Verified (waiting for approval)' => $tot['Verified'] ?? 0, 'Approved (for payroll)' => ($tot['Approved'] ?? 0) + ($tot['In payroll'] ?? 0), 'Paid' => $tot['Paid'] ?? 0] as $l => $v) { ?>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted"><?= $l; ?><?= $seesTeam && !$user ? ' (team)' : ''; ?></div><div class="fs-5 fw-bold <?= $v < 0 ? 'text-danger' : ''; ?>"><?= $rwf($v); ?></div></div></div></div><?php } ?>
</div>
<div class="small text-muted mb-2"><strong>Rule:</strong> <?= (float) mkt_setting($conn, 'commission_rate'); ?>% (default) of the eligible net profit of a newly acquired paying customer, for <?= (int) mkt_setting($conn, 'commission_months'); ?> month(s) after the first verified payment, <?= mkt_setting($conn, 'partial_policy') === 'full' ? 'fully paid sales only' : 'in proportion to verified payments'; ?>. The lead owner receives the share left after approved contributors. Paid in the monthly payroll.</div>
<form class="d-flex flex-wrap gap-2 mb-2">
    <select name="status" class="form-select form-select-sm" style="max-width:180px;"><option value="">All statuses</option><?php foreach (array_merge(MKT_COMM_FLOW, ['Reversed', 'Rejected']) as $x) { ?><option <?= $st === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select>
    <?php if ($seesTeam) { ?><select name="user" class="form-select form-select-sm" style="max-width:200px;"><option value="">Everyone</option><?php foreach ($users as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= $user === (int) $u['id'] ? 'selected' : ''; ?>><?= $e($u['names']); ?></option><?php } ?></select><?php } ?>
    <input type="month" name="period" value="<?= $e($period); ?>" class="form-control form-control-sm" style="max-width:170px;"><button class="btn btn-sm btn-primary">Filter</button>
    <?php if ($canFin || $canApprove) { ?></form><form method="POST" class="d-inline"><input type="hidden" name="action" value="recalculate"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Recalculate from verified payments</button><?php } ?>
</form>
<form method="POST"><div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle" style="font-size:12.5px;">
    <tr><?php if ($canFin || $canApprove) { ?><th class="ps-3"><input type="checkbox" class="form-check-input" onclick="document.querySelectorAll('.cbx').forEach(function (x) { x.checked = this.checked; }, this);"></th><?php } ?>
        <th class="<?= $canFin || $canApprove ? '' : 'ps-3'; ?>">Line</th><th>Person</th><th>Customer / campaign</th><th>Period</th><th class="text-end">Eligible profit</th><th class="text-end">Rate × share</th><th class="text-end">Amount</th><th>Status</th></tr>
    <?php if (!$rows) { ?><tr><td colspan="9" class="text-center text-muted py-4">No commission lines<?= $seesTeam ? '' : ' for you yet'; ?>.</td></tr><?php } ?>
    <?php foreach ($rows as $c) { $p = $c['profit_adjusted'] ?? $c['eligible_profit']; ?>
    <tr><?php if ($canFin || $canApprove) { ?><td class="ps-3"><?php if (in_array($c['status'], ['Calculated', 'Verified'], true)) { ?><input type="checkbox" name="ids[]" value="<?= (int) $c['id']; ?>" class="form-check-input cbx"><?php } ?></td><?php } ?>
        <td class="<?= $canFin || $canApprove ? '' : 'ps-3'; ?>">COM-<?= (int) $c['id']; ?><div class="small text-muted"><?= $e($c['kind']); ?><?= $c['sale_id'] ? ' · sale #' . (int) $c['sale_id'] : ''; ?></div></td>
        <td><?= $e($c['names']); ?></td><td class="small"><?= $e($c['customer']); ?><div class="text-muted"><?= $e($c['campaign'] ?? ''); ?></div></td><td class="small"><?= $e($c['period']); ?></td>
        <td class="text-end"><?= $p !== null ? $rwf($p) : '—'; ?><?= $c['profit_adjusted'] !== null ? '<div class="small text-primary" title="' . $e($c['adjust_reason']) . '">adjusted (was ' . number_format((float) $c['eligible_profit']) . ')</div>' : ''; ?>
            <?php if ($canFin && $c['status'] === 'Calculated' && $c['rate'] !== null) { ?><details class="small text-start"><summary class="text-primary">Adjust</summary><div class="d-flex flex-column gap-1 mt-1">
                <input type="number" step="0.01" min="0" form="adj<?= (int) $c['id']; ?>" name="profit" value="<?= $e($p); ?>" class="form-control form-control-sm"><input form="adj<?= (int) $c['id']; ?>" name="note" class="form-control form-control-sm" placeholder="Reason (required)">
                <button form="adj<?= (int) $c['id']; ?>" class="btn btn-sm btn-outline-primary">Save</button></div></details><?php } ?></td>
        <td class="text-end small"><?= $c['rate'] !== null ? (float) $c['rate'] . '% × ' . (float) $c['share_pct'] . '%<div class="text-muted">' . $e($c['rule_label']) . '</div>' : ''; ?></td>
        <td class="text-end fw-semibold <?= (float) $c['amount'] < 0 ? 'text-danger' : ''; ?>"><?= $rwf($c['amount']); ?></td>
        <td><?= mkt_badge($c['status']); ?><?= $c['payroll_id'] ? '<div class="small text-muted">payroll #' . (int) $c['payroll_id'] . '</div>' : ''; ?><?= $c['note'] ? '<div class="small text-muted" style="max-width:220px;">' . $e($c['note']) . '</div>' : ''; ?></td></tr><?php } ?>
</table></div></div></div>
<?php if ($canFin || $canApprove) { ?><div class="d-flex flex-wrap gap-2 mt-2 align-items-center"><input name="note" class="form-control form-control-sm" style="max-width:320px;" placeholder="Note (required to reject, or for your own lines)">
    <?php if ($canFin) { ?><button name="action" value="verify" class="btn btn-sm btn-primary">Verify selected (Finance)</button><?php } ?>
    <?php if ($canApprove) { ?><button name="action" value="approve" class="btn btn-sm btn-success">Approve selected</button><?php } ?>
    <button name="action" value="reject" class="btn btn-sm btn-outline-danger">Reject selected</button></div><?php } ?>
</form>
<?php foreach ($rows as $c) { if ($canFin && $c['status'] === 'Calculated' && $c['rate'] !== null) { ?><form method="POST" id="adj<?= (int) $c['id']; ?>"><input type="hidden" name="action" value="adjust"><input type="hidden" name="id" value="<?= (int) $c['id']; ?>"></form><?php } } ?>
<?php include '../../includes/footer.php'; ?>