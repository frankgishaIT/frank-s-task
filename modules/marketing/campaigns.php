<?php
/**
 * NEW FILE: modules/marketing/campaigns.php — CAMPAIGN MANAGEMENT: list, create and edit (Draft only).
 * The campaign code is generated automatically (CMP-YYYY-0001). Workflow, tracking and performance: campaign.php.
 */
require __DIR__ . '/mkt_common.php';
$L = mkt_lists($conn);
$canCreate = access_can('marketing.create', $conn);
$plans = mysqli_fetch_all(mysqli_query($conn, "SELECT id, title, created_at FROM mkt_plans WHERE status IN ('Approved', 'Active') ORDER BY title"), MYSQLI_ASSOC);
$id = (int) ($_POST['id'] ?? ($_GET['edit'] ?? 0));
$editing = $id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_campaigns WHERE id = $id")) : null;
if ($editing && !mkt_may_run($editing, $me)) { header('Location: campaign.php?id=' . $id . '&error=' . urlencode('Only its creator, its manager, or a Manager / Administrator can edit this draft.')); exit; }
if ($editing && $editing['status'] !== 'Draft') { header('Location: campaign.php?id=' . $id . '&error=' . urlencode('Only a draft can be edited.')); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canCreate) { throw new RuntimeException('You cannot create or edit campaigns.'); }
        $d = ['name' => mb_substr(trim((string) $_POST['name']), 0, 160), 'plan_id' => (int) ($_POST['plan_id'] ?? 0) ?: null,
              'objective' => trim((string) ($_POST['objective'] ?? '')) ?: null, 'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
              'target_audience' => mb_substr(trim((string) ($_POST['target_audience'] ?? '')), 0, 255) ?: null, 'target_orgs' => trim((string) ($_POST['target_orgs'] ?? '')) ?: null,
              'message' => trim((string) ($_POST['message'] ?? '')) ?: null, 'start_date' => (string) ($_POST['start_date'] ?? ''), 'end_date' => (string) ($_POST['end_date'] ?? ''),
              'budget' => max(0, round((float) ($_POST['budget'] ?? 0), 2)), 'expected_leads' => max(0, (int) ($_POST['expected_leads'] ?? 0)),
              'expected_customers' => max(0, (int) ($_POST['expected_customers'] ?? 0)), 'expected_revenue' => max(0, round((float) ($_POST['expected_revenue'] ?? 0), 2)),
              'expected_profit' => max(0, round((float) ($_POST['expected_profit'] ?? 0), 2)), 'manager_id' => (int) ($_POST['manager_id'] ?? 0) ?: null,
              'kpis' => trim((string) ($_POST['kpis'] ?? '')) ?: null];
        $d['product_ids'] = implode(',', array_values(array_intersect(array_map('intval', (array) ($_POST['product_ids'] ?? [])), array_map('intval', array_column($L['offerings'], 'id'))))) ?: null;
        $d['channels'] = implode(', ', array_values(array_intersect((array) ($_POST['channels'] ?? []), $L['channels']))) ?: null;
        if ($d['name'] === '') { throw new RuntimeException('Give the campaign a name.'); }
        if ($d['plan_id'] && !in_array($d['plan_id'], array_map('intval', array_column($plans, 'id')), true)) { throw new RuntimeException('A campaign can only be linked to an approved or active plan.'); }
        if (!DateTime::createFromFormat('Y-m-d', $d['start_date']) || !DateTime::createFromFormat('Y-m-d', $d['end_date']) || $d['end_date'] < $d['start_date']) { throw new RuntimeException('Check the start and end dates.'); }
        if (!$d['manager_id'] || !in_array($d['manager_id'], array_map('intval', array_column($L['users'], 'id')), true)) { throw new RuntimeException('Choose the campaign manager (from Employees).'); }
        if ($d['expected_customers'] > $d['expected_leads'] && $d['expected_leads'] > 0) { throw new RuntimeException('Expected paying customers cannot be more than expected leads.'); }
        if ($d['expected_profit'] > $d['expected_revenue'] && $d['expected_revenue'] > 0) { throw new RuntimeException('Expected profit cannot be higher than expected revenue.'); }
        $members = array_values(array_intersect(array_map('intval', (array) ($_POST['members'] ?? [])), array_map('intval', array_column($L['users'], 'id'))));
        mysqli_begin_transaction($conn);
        $cols = array_keys($d); $types = ''; foreach ($d as $x) { $types .= is_int($x) ? 'i' : (is_float($x) ? 'd' : 's'); }
        if ($editing) {
            $s = mysqli_prepare($conn, 'UPDATE mkt_campaigns SET ' . implode(', ', array_map(function ($c) { return "$c = ?"; }, $cols)) . ', updated_at = NOW() WHERE id = ? AND status = \'Draft\'');
            $vals = array_values($d); $vals[] = $id; mysqli_stmt_bind_param($s, $types . 'i', ...$vals); mysqli_stmt_execute($s);
        } else {
            $s = mysqli_prepare($conn, 'INSERT INTO mkt_campaigns (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)');
            $vals = array_values($d); $vals[] = $me; mysqli_stmt_bind_param($s, $types . 'i', ...$vals); mysqli_stmt_execute($s); $id = (int) mysqli_insert_id($conn);
        }
        mysqli_query($conn, "DELETE FROM mkt_campaign_members WHERE campaign_id = $id");
        $ins = mysqli_prepare($conn, 'INSERT INTO mkt_campaign_members (campaign_id, user_id) VALUES (?, ?)');
        foreach ($members as $u) { mysqli_stmt_bind_param($ins, 'ii', $id, $u); mysqli_stmt_execute($ins); }
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_campaigns WHERE id = $id"));
        mkt_audit($conn, $editing ? 'Campaign edited' : 'Campaign created (Draft)', mkt_code('campaign', $row), $editing ? array_intersect_key($editing, $d) : null, $d, null, $me);
        mysqli_commit($conn);
        if (!$editing && (int) $d['manager_id'] !== $me) { mkt_notify($conn, [(int) $d['manager_id']], 'You manage a new campaign: ' . $d['name'], mkt_code('campaign', $row) . ' (draft).'); }
        header('Location: campaign.php?id=' . $id . '&success=' . urlencode('Campaign ' . mkt_code('campaign', $row) . ' saved as a draft.')); exit;
    } catch (RuntimeException $ex) { @mysqli_rollback($conn); $error = $ex->getMessage(); }
}
$st = in_array($_GET['status'] ?? '', MKT_CAMPAIGN_FLOW, true) ? $_GET['status'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$w = [mkt_campaign_scope_sql($conn, $me)]; if ($st !== '') { $w[] = "c.status = '" . mysqli_real_escape_string($conn, $st) . "'"; } elseif (empty($_GET['all'])) { $w[] = "c.status <> 'Archived'"; }
if ($q !== '') { $qq = mysqli_real_escape_string($conn, $q); $w[] = "(c.name LIKE '%$qq%' OR c.target_audience LIKE '%$qq%')"; }
$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 25;
$total = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM mkt_campaigns c WHERE ' . implode(' AND ', $w)))[0];
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT c.*, u.names AS manager FROM mkt_campaigns c LEFT JOIN users u ON u.id = c.manager_id WHERE ' . implode(' AND ', $w) . ' ORDER BY c.id DESC LIMIT ' . (($page - 1) * $per) . ", $per"), MYSQLI_ASSOC);
$v = function ($k, $default = '') use ($editing) { return $_POST[$k] ?? ($editing[$k] ?? $default); };
$selProducts = array_map('intval', (array) ($_POST['product_ids'] ?? explode(',', (string) ($editing['product_ids'] ?? ''))));
$selChannels = (array) ($_POST['channels'] ?? array_map('trim', explode(',', (string) ($editing['channels'] ?? ''))));
$selMembers = array_map('intval', (array) ($_POST['members'] ?? ($editing ? array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT user_id FROM mkt_campaign_members WHERE campaign_id = ' . (int) $editing['id']), MYSQLI_ASSOC), 'user_id') : [])));
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('campaigns.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<?php if ($canCreate) { ?>
<details class="card border-0 shadow-sm mb-3" <?= $editing || isset($error) ? 'open' : ''; ?>><summary class="card-body py-2 fw-semibold"><?= $editing ? 'Edit draft campaign ' . mkt_code('campaign', $editing) : '+ New campaign'; ?></summary>
<form method="POST" class="card-body pt-0 row g-2"><?php if ($editing) { ?><input type="hidden" name="id" value="<?= (int) $editing['id']; ?>"><?php } ?>
    <div class="col-md-5"><label class="form-label small text-muted mb-1">Campaign name *</label><input name="name" maxlength="160" required class="form-control form-control-sm" value="<?= $e($v('name')); ?>"></div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Marketing plan</label><select name="plan_id" class="form-select form-select-sm"><option value="">— none —</option><?php foreach ($plans as $pl) { ?><option value="<?= (int) $pl['id']; ?>" <?= (int) $v('plan_id') === (int) $pl['id'] ? 'selected' : ''; ?>><?= $e(mkt_code('plan', $pl) . ' ' . $pl['title']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Start *</label><input type="date" name="start_date" required class="form-control form-control-sm" value="<?= $e($v('start_date')); ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">End *</label><input type="date" name="end_date" required class="form-control form-control-sm" value="<?= $e($v('end_date')); ?>"></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">Campaign objective</label><textarea name="objective" rows="2" class="form-control form-control-sm"><?= $e($v('objective')); ?></textarea></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">Description</label><textarea name="description" rows="2" class="form-control form-control-sm"><?= $e($v('description')); ?></textarea></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">Products / services (RM Offerings)</label><select name="product_ids[]" multiple size="5" class="form-select form-select-sm"><?php foreach ($L['offerings'] as $o) { ?><option value="<?= (int) $o['id']; ?>" <?= in_array((int) $o['id'], $selProducts, true) ? 'selected' : ''; ?>><?= $e(($o['item_type'] === 'Service' ? 'Service · ' : 'Product · ') . $o['product_name']); ?></option><?php } ?></select></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">Marketing channels</label><select name="channels[]" multiple size="5" class="form-select form-select-sm"><?php foreach ($L['channels'] as $c) { ?><option <?= in_array($c, $selChannels, true) ? 'selected' : ''; ?>><?= $e($c); ?></option><?php } ?></select></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">Target audience</label><input name="target_audience" maxlength="255" class="form-control form-control-sm" value="<?= $e($v('target_audience')); ?>"></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">Target organizations</label><input name="target_orgs" class="form-control form-control-sm" value="<?= $e($v('target_orgs')); ?>" placeholder="e.g. schools in Kicukiro, health centres"></div>
    <div class="col-12"><label class="form-label small text-muted mb-1">Marketing message</label><textarea name="message" rows="2" class="form-control form-control-sm"><?= $e($v('message')); ?></textarea></div>
    <?php foreach (['budget' => 'Budget (RWF)', 'expected_leads' => 'Expected leads', 'expected_customers' => 'Expected paying customers', 'expected_revenue' => 'Expected revenue (RWF)', 'expected_profit' => 'Expected profit (RWF)'] as $k => $l) { ?>
    <div class="col-6 col-md"><label class="form-label small text-muted mb-1"><?= $l; ?></label><input type="number" min="0" step="1" name="<?= $k; ?>" class="form-control form-control-sm" value="<?= $e($v($k, '0')); ?>"></div><?php } ?>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Campaign manager *</label><select name="manager_id" required class="form-select form-select-sm"><option value=""></option><?php foreach ($L['users'] as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= (int) $v('manager_id') === (int) $u['id'] ? 'selected' : ''; ?>><?= $e($u['names']); ?></option><?php } ?></select></div>
    <div class="col-md-8"><label class="form-label small text-muted mb-1">Assigned team members (Employees)</label><select name="members[]" multiple size="4" class="form-select form-select-sm"><?php foreach ($L['users'] as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= in_array((int) $u['id'], $selMembers, true) ? 'selected' : ''; ?>><?= $e($u['names'] . ' · ' . $u['role']); ?></option><?php } ?></select></div>
    <div class="col-12"><label class="form-label small text-muted mb-1">Campaign KPIs</label><textarea name="kpis" rows="2" class="form-control form-control-sm"><?= $e($v('kpis')); ?></textarea></div>
    <div class="col-12 small text-muted">Supporting marketing materials are attached in the Creative Studio (stage 4), linked to this campaign.</div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-primary btn-sm">Save draft</button><?php if ($editing) { ?><a href="campaign.php?id=<?= (int) $editing['id']; ?>" class="btn btn-link btn-sm">Cancel</a><?php } ?></div>
</form></details>
<?php } ?>
<form class="d-flex flex-wrap gap-2 mb-2"><input name="q" value="<?= $e($q); ?>" class="form-control form-control-sm" style="max-width:260px;" placeholder="Search name or audience">
    <select name="status" class="form-select form-select-sm" style="max-width:200px;"><option value="">All (except archived)</option><?php foreach (MKT_CAMPAIGN_FLOW as $x) { ?><option <?= $st === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select><button class="btn btn-sm btn-primary">Filter</button></form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Campaign</th><th>Period</th><th>Manager</th><th class="text-end">Budget</th><th class="text-end">Leads</th><th class="text-end">Paying</th><th class="text-end">Achievement</th><th>Status</th><th></th></tr>
    <?php if (!$rows) { ?><tr><td colspan="9" class="text-center text-muted py-4">No campaigns yet<?= $canCreate ? ': create the first one above.' : '.'; ?></td></tr><?php } ?>
    <?php foreach ($rows as $c) { $a = mkt_actuals($conn, [(int) $c['id']]); $pf = mkt_performance($c, $a); ?>
    <tr><td class="ps-3"><strong><?= $e($c['name']); ?></strong><div class="small text-muted"><?= mkt_code('campaign', $c); ?><?= (int) $c['ai_generated'] ? ' · <span class="badge bg-dark">AI-generated</span>' : ''; ?></div></td>
        <td class="small text-nowrap"><?= date('d M', strtotime($c['start_date'])); ?> – <?= date('d M Y', strtotime($c['end_date'])); ?></td><td class="small"><?= $e($c['manager']); ?></td><td class="text-end"><?= $rwf($c['budget']); ?></td>
        <td class="text-end"><?= $a['leads']; ?> / <?= (int) $c['expected_leads']; ?></td><td class="text-end"><?= $a['paying']; ?> / <?= (int) $c['expected_customers']; ?></td>
        <td class="text-end"><?= mkt_pct_text($pf['overall']); ?></td><td><?= mkt_badge($c['status']); ?></td><td><a href="campaign.php?id=<?= (int) $c['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">Open</a></td></tr><?php } ?>
</table></div></div></div>
<?php if ($total > $per) { ?><nav class="mt-2"><ul class="pagination pagination-sm"><?php for ($i = 1; $i <= (int) ceil($total / $per); $i++) { ?><li class="page-item <?= $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?= $e(http_build_query(['page' => $i, 'status' => $st, 'q' => $q])); ?>"><?= $i; ?></a></li><?php } ?></ul></nav><?php } ?>
<?php include '../../includes/footer.php'; ?>