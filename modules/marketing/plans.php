<?php
/**
 * NEW FILE: modules/marketing/plans.php — MARKETING PLANS & STRATEGY: list, create and edit (Draft only).
 * Workflow and monitoring: plan.php.
 */
require __DIR__ . '/mkt_common.php';
$L = mkt_lists($conn);
$canCreate = access_can('marketing.create', $conn);
$id = (int) ($_POST['id'] ?? ($_GET['edit'] ?? 0));
$editing = $id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_plans WHERE id = $id")) : null;
if ($editing && $editing['status'] !== 'Draft') { header('Location: plan.php?id=' . $id . '&error=' . urlencode('Only a draft can be edited.')); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canCreate) { throw new RuntimeException('You cannot create or edit marketing plans.'); }
        $d = ['title' => mb_substr(trim((string) $_POST['title']), 0, 160), 'description' => trim((string) ($_POST['description'] ?? '')) ?: null, 'objective' => trim((string) ($_POST['objective'] ?? '')) ?: null,
              'target_market' => mb_substr(trim((string) ($_POST['target_market'] ?? '')), 0, 255) ?: null, 'customer_type' => in_array($_POST['customer_type'] ?? '', $L['customer_types'], true) ? $_POST['customer_type'] : null,
              'start_date' => (string) ($_POST['start_date'] ?? ''), 'end_date' => (string) ($_POST['end_date'] ?? ''),
              'expected_leads' => max(0, (int) ($_POST['expected_leads'] ?? 0)), 'expected_customers' => max(0, (int) ($_POST['expected_customers'] ?? 0)),
              'expected_revenue' => max(0, round((float) ($_POST['expected_revenue'] ?? 0), 2)), 'expected_profit' => max(0, round((float) ($_POST['expected_profit'] ?? 0), 2)),
              'budget' => max(0, round((float) ($_POST['budget'] ?? 0), 2)), 'department_id' => (int) ($_POST['department_id'] ?? 0) ?: null, 'manager_id' => (int) ($_POST['manager_id'] ?? 0) ?: null,
              'kpis' => trim((string) ($_POST['kpis'] ?? '')) ?: null];
        $validIds = array_map('intval', array_column($L['offerings'], 'id'));
        $d['product_ids'] = implode(',', array_values(array_intersect(array_map('intval', (array) ($_POST['product_ids'] ?? [])), $validIds))) ?: null;
        $d['channels'] = implode(', ', array_values(array_intersect((array) ($_POST['channels'] ?? []), $L['channels']))) ?: null;
        if ($d['title'] === '') { throw new RuntimeException('Give the plan a title.'); }
        if (!DateTime::createFromFormat('Y-m-d', $d['start_date']) || !DateTime::createFromFormat('Y-m-d', $d['end_date']) || $d['end_date'] < $d['start_date']) { throw new RuntimeException('Check the start and end dates.'); }
        if (!$d['manager_id'] || !in_array($d['manager_id'], array_map('intval', array_column($L['users'], 'id')), true)) { throw new RuntimeException('Choose the marketing manager (from Employees).'); }
        if ($d['expected_profit'] > $d['expected_revenue'] && $d['expected_revenue'] > 0) { throw new RuntimeException('Expected profit cannot be higher than expected revenue.'); }
        $members = array_values(array_intersect(array_map('intval', (array) ($_POST['members'] ?? [])), array_map('intval', array_column($L['users'], 'id'))));
        mysqli_begin_transaction($conn);
        $cols = array_keys($d); $types = ''; foreach ($d as $v) { $types .= is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'); }
        if ($editing) {
            $s = mysqli_prepare($conn, 'UPDATE mkt_plans SET ' . implode(', ', array_map(function ($c) { return "$c = ?"; }, $cols)) . ', updated_at = NOW() WHERE id = ? AND status = \'Draft\'');
            $vals = array_values($d); $vals[] = $id; mysqli_stmt_bind_param($s, $types . 'i', ...$vals); mysqli_stmt_execute($s);
        } else {
            $s = mysqli_prepare($conn, 'INSERT INTO mkt_plans (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)');
            $vals = array_values($d); $vals[] = $me; mysqli_stmt_bind_param($s, $types . 'i', ...$vals); mysqli_stmt_execute($s); $id = (int) mysqli_insert_id($conn);
        }
        mysqli_query($conn, "DELETE FROM mkt_plan_members WHERE plan_id = $id");
        $ins = mysqli_prepare($conn, 'INSERT INTO mkt_plan_members (plan_id, user_id) VALUES (?, ?)');
        foreach ($members as $u) { mysqli_stmt_bind_param($ins, 'ii', $id, $u); mysqli_stmt_execute($ins); }
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_plans WHERE id = $id"));
        mkt_audit($conn, $editing ? 'Plan edited' : 'Plan created (Draft)', mkt_code('plan', $row), $editing ? array_intersect_key($editing, $d) : null, $d, null, $me);
        mysqli_commit($conn);
        header('Location: plan.php?id=' . $id . '&success=' . urlencode('Plan ' . mkt_code('plan', $row) . ' saved as a draft. Submit it for approval when ready.')); exit;
    } catch (RuntimeException $ex) { @mysqli_rollback($conn); $error = $ex->getMessage(); }
}
$st = in_array($_GET['status'] ?? '', MKT_PLAN_FLOW, true) ? $_GET['status'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$w = [mkt_plan_scope_sql($conn, $me)]; if ($st !== '') { $w[] = "p.status = '" . mysqli_real_escape_string($conn, $st) . "'"; }
if ($q !== '') { $w[] = "(p.title LIKE '%" . mysqli_real_escape_string($conn, $q) . "%' OR p.target_market LIKE '%" . mysqli_real_escape_string($conn, $q) . "%')"; }
$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 25;
$total = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM mkt_plans p WHERE ' . implode(' AND ', $w)))[0];
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT p.*, u.names AS manager FROM mkt_plans p LEFT JOIN users u ON u.id = p.manager_id WHERE ' . implode(' AND ', $w) . ' ORDER BY p.id DESC LIMIT ' . (($page - 1) * $per) . ", $per"), MYSQLI_ASSOC);
$v = function ($k, $default = '') use ($editing) { return $_POST[$k] ?? ($editing[$k] ?? $default); };
$selProducts = array_map('intval', (array) ($_POST['product_ids'] ?? explode(',', (string) ($editing['product_ids'] ?? ''))));
$selChannels = (array) ($_POST['channels'] ?? array_map('trim', explode(',', (string) ($editing['channels'] ?? ''))));
$selMembers = array_map('intval', (array) ($_POST['members'] ?? ($editing ? array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT user_id FROM mkt_plan_members WHERE plan_id = ' . (int) $editing['id']), MYSQLI_ASSOC), 'user_id') : [])));
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('plans.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<?php if ($canCreate) { ?>
<details class="card border-0 shadow-sm mb-3" <?= $editing || isset($error) ? 'open' : ''; ?>><summary class="card-body py-2 fw-semibold"><?= $editing ? 'Edit draft plan ' . mkt_code('plan', $editing) : '+ New marketing plan'; ?></summary>
<form method="POST" class="card-body pt-0 row g-2"><?php if ($editing) { ?><input type="hidden" name="id" value="<?= (int) $editing['id']; ?>"><?php } ?>
    <div class="col-md-8"><label class="form-label small text-muted mb-1">Plan title *</label><input name="title" class="form-control form-control-sm" maxlength="160" required value="<?= $e($v('title')); ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Start date *</label><input type="date" name="start_date" class="form-control form-control-sm" required value="<?= $e($v('start_date')); ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">End date *</label><input type="date" name="end_date" class="form-control form-control-sm" required value="<?= $e($v('end_date')); ?>"></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">Main objective</label><textarea name="objective" rows="2" class="form-control form-control-sm"><?= $e($v('objective')); ?></textarea></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">Description</label><textarea name="description" rows="2" class="form-control form-control-sm"><?= $e($v('description')); ?></textarea></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">Products / services to promote (RM Offerings)</label><select name="product_ids[]" multiple size="5" class="form-select form-select-sm"><?php foreach ($L['offerings'] as $o) { ?><option value="<?= (int) $o['id']; ?>" <?= in_array((int) $o['id'], $selProducts, true) ? 'selected' : ''; ?>><?= $e(($o['item_type'] === 'Service' ? 'Service · ' : 'Product · ') . $o['product_name']); ?></option><?php } ?></select><div class="form-text">Ctrl / Cmd + click to choose several.</div></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">Marketing channels</label><select name="channels[]" multiple size="5" class="form-select form-select-sm"><?php foreach ($L['channels'] as $c) { ?><option <?= in_array($c, $selChannels, true) ? 'selected' : ''; ?>><?= $e($c); ?></option><?php } ?></select></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">Target market</label><input name="target_market" maxlength="255" class="form-control form-control-sm" value="<?= $e($v('target_market')); ?>" placeholder="e.g. SMEs in Kicukiro and Gasabo"></div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Target customer type</label><select name="customer_type" class="form-select form-select-sm"><option value=""></option><?php foreach ($L['customer_types'] as $c) { ?><option <?= $v('customer_type') === $c ? 'selected' : ''; ?>><?= $e($c); ?></option><?php } ?></select></div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Responsible department</label><select name="department_id" class="form-select form-select-sm"><option value=""></option><?php foreach ($L['departments'] as $d) { ?><option value="<?= (int) $d['id']; ?>" <?= (int) $v('department_id') === (int) $d['id'] ? 'selected' : ''; ?>><?= $e($d['name']); ?></option><?php } ?></select></div>
    <?php foreach (['expected_leads' => 'Expected leads', 'expected_customers' => 'Expected paying customers', 'expected_revenue' => 'Expected revenue (RWF)', 'expected_profit' => 'Expected profit (RWF)', 'budget' => 'Available budget (RWF)'] as $k => $l) { ?>
    <div class="col-6 col-md"><label class="form-label small text-muted mb-1"><?= $l; ?></label><input type="number" min="0" step="1" name="<?= $k; ?>" class="form-control form-control-sm" value="<?= $e($v($k, '0')); ?>"></div><?php } ?>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Marketing manager *</label><select name="manager_id" class="form-select form-select-sm" required><option value=""></option><?php foreach ($L['users'] as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= (int) $v('manager_id') === (int) $u['id'] ? 'selected' : ''; ?>><?= $e($u['names']); ?></option><?php } ?></select></div>
    <div class="col-md-8"><label class="form-label small text-muted mb-1">Assigned team members (Employees)</label><select name="members[]" multiple size="4" class="form-select form-select-sm"><?php foreach ($L['users'] as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= in_array((int) $u['id'], $selMembers, true) ? 'selected' : ''; ?>><?= $e($u['names'] . ' · ' . $u['role']); ?></option><?php } ?></select></div>
    <div class="col-12"><label class="form-label small text-muted mb-1">Key Performance Indicators (KPIs)</label><textarea name="kpis" rows="2" class="form-control form-control-sm" placeholder="e.g. 20 qualified leads per month; 30% lead-to-customer conversion"><?= $e($v('kpis')); ?></textarea></div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-primary btn-sm">Save draft</button><?php if ($editing) { ?><a href="plan.php?id=<?= (int) $editing['id']; ?>" class="btn btn-link btn-sm">Cancel</a><?php } ?></div>
</form></details>
<?php } ?>
<form class="d-flex flex-wrap gap-2 mb-2"><input name="q" value="<?= $e($q); ?>" class="form-control form-control-sm" style="max-width:260px;" placeholder="Search title or market">
    <select name="status" class="form-select form-select-sm" style="max-width:220px;"><option value="">All statuses</option><?php foreach (MKT_PLAN_FLOW as $x) { ?><option <?= $st === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select><button class="btn btn-sm btn-primary">Filter</button></form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Plan</th><th>Period</th><th>Manager</th><th class="text-end">Target customers</th><th class="text-end">Target revenue</th><th class="text-end">Budget</th><th>Status</th><th></th></tr>
    <?php if (!$rows) { ?><tr><td colspan="8" class="text-center text-muted py-4">No marketing plans yet<?= $canCreate ? ': create the first one above.' : '.'; ?></td></tr><?php } ?>
    <?php foreach ($rows as $p) { ?><tr><td class="ps-3"><strong><?= $e($p['title']); ?></strong><div class="small text-muted"><?= mkt_code('plan', $p); ?></div></td>
        <td class="small text-nowrap"><?= date('d M Y', strtotime($p['start_date'])); ?> – <?= date('d M Y', strtotime($p['end_date'])); ?></td><td class="small"><?= $e($p['manager']); ?></td>
        <td class="text-end"><?= (int) $p['expected_customers']; ?></td><td class="text-end"><?= $rwf($p['expected_revenue']); ?></td><td class="text-end"><?= $rwf($p['budget']); ?></td><td><?= mkt_badge($p['status']); ?></td>
        <td><a href="plan.php?id=<?= (int) $p['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">Open</a></td></tr><?php } ?>
</table></div></div></div>
<?php if ($total > $per) { ?><nav class="mt-2"><ul class="pagination pagination-sm"><?php for ($i = 1; $i <= (int) ceil($total / $per); $i++) { ?><li class="page-item <?= $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?= $e(http_build_query(['page' => $i, 'status' => $st, 'q' => $q])); ?>"><?= $i; ?></a></li><?php } ?></ul></nav><?php } ?>
<?php include '../../includes/footer.php'; ?>