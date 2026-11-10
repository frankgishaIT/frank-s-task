<?php
/**
 * NEW FILE: modules/marketing/rules.php — COMMISSION RULES (Administrators): specific rates by employee, campaign,
 * product / service or role, with effective dates. Most specific wins; otherwise the default rate (Settings).
 * Rules apply by PAYMENT date, so a new rule never changes commissions on earlier payments.
 */
require __DIR__ . '/mkt_common.php';
mkt_ensure_team($conn);
if (!access_can('marketing.manage', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
$L = mkt_lists($conn);
$camps = mysqli_fetch_all(mysqli_query($conn, "SELECT id, name, created_at FROM mkt_campaigns WHERE status <> 'Archived' ORDER BY name"), MYSQLI_ASSOC);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'end') {
            $id = (int) $_POST['id']; $to = DateTime::createFromFormat('Y-m-d', (string) $_POST['effective_to']) ? $_POST['effective_to'] : date('Y-m-d');
            $s = mysqli_prepare($conn, 'UPDATE mkt_commission_rules SET effective_to = ? WHERE id = ? AND (effective_to IS NULL OR effective_to > ?)'); mysqli_stmt_bind_param($s, 'sis', $to, $id, $to); mysqli_stmt_execute($s);
            mkt_audit($conn, 'Commission rule ended', 'RULE-' . $id, null, $to, trim((string) ($_POST['note'] ?? '')) ?: null, $me); $msg = 'Rule ended on ' . $to . '.';
        } else {
            $scope = in_array($_POST['scope'] ?? '', ['employee', 'campaign', 'product', 'role'], true) ? $_POST['scope'] : '';
            $sid = in_array($scope, ['employee', 'campaign', 'product'], true) ? ((int) ($_POST['scope_id_' . $scope] ?? 0) ?: null) : null;
            $sval = $scope === 'role' ? (in_array($_POST['role'] ?? '', ['admin', 'manager', 'employee'], true) ? $_POST['role'] : null) : null;
            $rate = round((float) ($_POST['rate'] ?? -1), 2); $from = (string) ($_POST['effective_from'] ?? ''); $to = DateTime::createFromFormat('Y-m-d', (string) ($_POST['effective_to'] ?? '')) ? $_POST['effective_to'] : null;
            $note = trim((string) ($_POST['note'] ?? ''));
            if (!$scope || ($scope === 'role' ? !$sval : !$sid)) { throw new RuntimeException('Choose what the rule applies to.'); }
            if ($rate < 0 || $rate > 100) { throw new RuntimeException('The rate must be between 0 and 100%.'); }
            if (!DateTime::createFromFormat('Y-m-d', $from) || ($to && $to < $from)) { throw new RuntimeException('Check the effective dates.'); }
            if (mb_strlen($note) < 5) { throw new RuntimeException('Write the reason (e.g. management decision).'); }
            $s = mysqli_prepare($conn, 'INSERT INTO mkt_commission_rules (scope, scope_id, scope_value, rate, effective_from, effective_to, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'sisdsssi', $scope, $sid, $sval, $rate, $from, $to, $note, $me); mysqli_stmt_execute($s); $id = (int) mysqli_insert_id($conn);
            mkt_audit($conn, 'Commission rule created: ' . $scope . ' ' . ($sval ?? '#' . $sid) . ' = ' . $rate . '% from ' . $from, 'RULE-' . $id, null, $rate, $note, $me); $msg = 'Rule saved.';
        }
        header('Location: rules.php?success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
}
$rules = mysqli_fetch_all(mysqli_query($conn, 'SELECT r.*, u.names AS creator FROM mkt_commission_rules r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.scope, r.effective_from DESC'), MYSQLI_ASSOC);
$names = ['employee' => array_column($L['users'], 'names', 'id'), 'campaign' => array_column($camps, 'name', 'id'), 'product' => array_column($L['offerings'], 'product_name', 'id')];
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('rules.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<div class="small text-muted mb-2">Default rate: <strong><?= (float) mkt_setting($conn, 'commission_rate'); ?>%</strong> (Settings). A specific rule wins in this order: <strong>employee → campaign → product / service → role → default</strong>. Rules apply by payment date: a new rule never changes commissions on earlier payments.</div>
<form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 small align-items-end">
    <div class="col-md-2"><label class="text-muted">Applies to</label><select name="scope" id="rScope" class="form-select form-select-sm"><option value="employee">Employee</option><option value="campaign">Campaign</option><option value="product">Product / service</option><option value="role">Role</option></select></div>
    <div class="col-md-3 r-employee"><label class="text-muted">Employee</label><select name="scope_id_employee" class="form-select form-select-sm"><?php foreach ($L['users'] as $u) { ?><option value="<?= (int) $u['id']; ?>"><?= $e($u['names']); ?></option><?php } ?></select></div>
    <div class="col-md-3 r-campaign"><label class="text-muted">Campaign</label><select name="scope_id_campaign" class="form-select form-select-sm"><?php foreach ($camps as $c) { ?><option value="<?= (int) $c['id']; ?>"><?= $e(mkt_code('campaign', $c) . ' ' . $c['name']); ?></option><?php } ?></select></div>
    <div class="col-md-3 r-product"><label class="text-muted">Product / service</label><select name="scope_id_product" class="form-select form-select-sm"><?php foreach ($L['offerings'] as $o) { ?><option value="<?= (int) $o['id']; ?>"><?= $e($o['product_name']); ?></option><?php } ?></select></div>
    <div class="col-md-3 r-role"><label class="text-muted">Role</label><select name="role" class="form-select form-select-sm"><option value="employee">Employee</option><option value="manager">Manager</option><option value="admin">Administrator</option></select></div>
    <div class="col-6 col-md-1"><label class="text-muted">Rate %</label><input type="number" step="0.01" min="0" max="100" name="rate" class="form-control form-control-sm" required></div>
    <div class="col-6 col-md-2"><label class="text-muted">From</label><input type="date" name="effective_from" value="<?= date('Y-m-d'); ?>" class="form-control form-control-sm" required></div>
    <div class="col-6 col-md-2"><label class="text-muted">Until (optional)</label><input type="date" name="effective_to" class="form-control form-control-sm"></div>
    <div class="col-md-10"><input name="note" class="form-control form-control-sm" placeholder="Reason (e.g. management decision of 10 Nov 2026)" required></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Add rule</button></div>
</div></form>
<script>(function () { var s = document.getElementById('rScope'); function f() { ['employee', 'campaign', 'product', 'role'].forEach(function (k) { document.querySelectorAll('.r-' + k).forEach(function (el) { el.style.display = s.value === k ? '' : 'none'; }); }); } s.addEventListener('change', f); f(); })();</script>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Applies to</th><th class="text-end">Rate</th><th>From</th><th>Until</th><th>Reason</th><th>By</th><th></th></tr>
    <?php if (!$rules) { ?><tr><td colspan="7" class="text-center text-muted py-3">No specific rules: everyone gets the default rate.</td></tr><?php } ?>
    <?php foreach ($rules as $r) { $active = $r['effective_from'] <= date('Y-m-d') && (!$r['effective_to'] || $r['effective_to'] >= date('Y-m-d')); ?>
    <tr class="<?= $active ? '' : 'text-muted'; ?>"><td class="ps-3"><?= ucfirst($r['scope']); ?>: <strong><?= $e($r['scope'] === 'role' ? ucfirst($r['scope_value']) : ($names[$r['scope']][$r['scope_id']] ?? '#' . $r['scope_id'])); ?></strong></td>
        <td class="text-end"><?= (float) $r['rate']; ?>%</td><td><?= date('d M Y', strtotime($r['effective_from'])); ?></td><td><?= $r['effective_to'] ? date('d M Y', strtotime($r['effective_to'])) : '—'; ?></td><td class="small"><?= $e($r['note']); ?></td><td class="small"><?= $e($r['creator']); ?></td>
        <td><?php if (!$r['effective_to'] || $r['effective_to'] > date('Y-m-d')) { ?><form method="POST" class="d-flex gap-1"><input type="hidden" name="action" value="end"><input type="hidden" name="id" value="<?= (int) $r['id']; ?>"><input type="date" name="effective_to" value="<?= date('Y-m-d'); ?>" class="form-control form-control-sm" style="width:140px;"><button class="btn btn-sm btn-outline-secondary">End</button></form><?php } ?></td></tr><?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>