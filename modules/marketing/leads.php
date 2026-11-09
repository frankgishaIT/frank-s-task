<?php
/**
 * NEW FILE: modules/marketing/leads.php — LEADS & CUSTOMER ACQUISITION: register a lead (automatic Lead ID, no
 * duplicates), search and filter, overdue follow-ups highlighted. One lead: lead.php.
 */
require __DIR__ . '/mkt_common.php';
try { mkt_refresh_acquisitions($conn); mkt_followup_reminders($conn); } catch (Throwable $x) { error_log('leads: ' . $x->getMessage()); }
$L = mkt_lists($conn);
$canLeads = access_can('marketing.leads', $conn); $canManage = mkt_is_manager(); // choosing another owner: Managers and Admins only
$sources = mkt_list_setting($conn, 'lead_sources');
$camps = mysqli_fetch_all(mysqli_query($conn, "SELECT c.id, c.name, c.created_at FROM mkt_campaigns c WHERE c.status IN ('Approved', 'Active', 'Paused') AND " . mkt_campaign_scope_sql($conn, $me) . ' ORDER BY c.name'), MYSQLI_ASSOC);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canLeads) { throw new RuntimeException('You cannot register leads.'); }
        $d = ['name' => mb_substr(trim((string) $_POST['name']), 0, 160), 'contact_person' => mb_substr(trim((string) ($_POST['contact_person'] ?? '')), 0, 120) ?: null,
              'phone' => mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 30) ?: null, 'email' => mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 120) ?: null,
              'location' => mb_substr(trim((string) ($_POST['location'] ?? '')), 0, 160) ?: null, 'org_type' => in_array($_POST['org_type'] ?? '', $L['customer_types'], true) ? $_POST['org_type'] : null,
              'product_id' => (int) ($_POST['product_id'] ?? 0) ?: null, 'source' => in_array($_POST['source'] ?? '', $sources, true) ? $_POST['source'] : null,
              'campaign_id' => (int) ($_POST['campaign_id'] ?? 0) ?: null, 'owner_id' => $canManage ? ((int) ($_POST['owner_id'] ?? 0) ?: $me) : $me,
              'estimated_value' => ($_POST['estimated_value'] ?? '') === '' ? null : max(0, round((float) $_POST['estimated_value'], 2)),
              'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null, 'next_followup' => DateTime::createFromFormat('Y-m-d', (string) ($_POST['next_followup'] ?? '')) ? $_POST['next_followup'] : null];
        if ($d['name'] === '') { throw new RuntimeException('Enter the lead name (person or organization).'); }
        if (!$d['phone'] && !$d['email']) { throw new RuntimeException('Enter at least a phone number or an email address.'); }
        if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('The email address is not valid.'); }
        if ($d['phone'] && strlen(mkt_phone_key($d['phone'])) < 9) { throw new RuntimeException('The phone number looks incomplete.'); }
        if (!$d['source']) { throw new RuntimeException('Choose the lead source.'); }
        if ($d['campaign_id'] && !in_array($d['campaign_id'], array_map('intval', array_column($camps, 'id')), true)) { throw new RuntimeException('Choose one of your approved or active campaigns.'); }
        if ($d['product_id'] && !in_array($d['product_id'], array_map('intval', array_column($L['offerings'], 'id')), true)) { throw new RuntimeException('Choose a product or service from RM Offerings.'); }
        if (!in_array((int) $d['owner_id'], array_map('intval', array_column($L['users'], 'id')), true)) { throw new RuntimeException('Choose the lead owner.'); }
        if ($d['next_followup'] && $d['next_followup'] < date('Y-m-d')) { throw new RuntimeException('The next follow-up date cannot be in the past.'); }
        if ($dup = mkt_duplicate_lead($conn, $d['phone'], $d['email'])) { throw new RuntimeException('This contact is already an open lead: ' . mkt_code('lead', $dup) . ' (' . $dup['name'] . ', ' . $dup['status'] . '). Open it instead of creating a duplicate.'); }
        $supporters = array_values(array_diff(array_intersect(array_map('intval', (array) ($_POST['supporters'] ?? [])), array_map('intval', array_column($L['users'], 'id'))), [(int) $d['owner_id']]));
        mysqli_begin_transaction($conn);
        $cols = array_keys($d); $types = ''; foreach ($d as $x) { $types .= is_int($x) ? 'i' : (is_float($x) ? 'd' : 's'); }
        $s = mysqli_prepare($conn, 'INSERT INTO mkt_leads (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)');
        $vals = array_values($d); $vals[] = $me; mysqli_stmt_bind_param($s, $types . 'i', ...$vals); mysqli_stmt_execute($s); $id = (int) mysqli_insert_id($conn);
        $ins = mysqli_prepare($conn, "INSERT INTO mkt_lead_contributors (lead_id, user_id, role, reason, added_by) VALUES (?, ?, 'Other support', 'Supporting team member at registration', ?)");
        foreach ($supporters as $u) { mysqli_stmt_bind_param($ins, 'iii', $id, $u, $me); mysqli_stmt_execute($ins); }
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_leads WHERE id = $id"));
        mkt_audit($conn, 'Lead registered', mkt_code('lead', $row), null, array_filter($d, function ($x) { return $x !== null; }), null, $me);
        mysqli_commit($conn);
        if ((int) $d['owner_id'] !== $me) { mkt_notify($conn, [(int) $d['owner_id']], 'New lead allocated to you: ' . $d['name'], mkt_code('lead', $row)); }
        header('Location: lead.php?id=' . $id . '&success=' . urlencode('Lead ' . mkt_code('lead', $row) . ' registered.')); exit;
    } catch (RuntimeException $ex) { @mysqli_rollback($conn); $error = $ex->getMessage(); }
}
$f = ['status' => in_array($_GET['status'] ?? '', MKT_LEAD_FLOW, true) ? $_GET['status'] : '', 'campaign' => (int) ($_GET['campaign'] ?? 0), 'owner' => (int) ($_GET['owner'] ?? 0),
      'source' => in_array($_GET['source'] ?? '', $sources, true) ? $_GET['source'] : '', 'overdue' => !empty($_GET['overdue']), 'q' => trim((string) ($_GET['q'] ?? ''))];
$w = [mkt_lead_scope_sql($conn, $me)];
if ($f['status'] !== '') { $w[] = "l.status = '" . mysqli_real_escape_string($conn, $f['status']) . "'"; } elseif (empty($_GET['closed'])) { $w[] = "l.status NOT IN ('Won', 'Lost')"; }
if ($f['campaign']) { $w[] = 'l.campaign_id = ' . $f['campaign']; } if ($f['owner']) { $w[] = 'l.owner_id = ' . $f['owner']; }
if ($f['source'] !== '') { $w[] = "l.source = '" . mysqli_real_escape_string($conn, $f['source']) . "'"; }
if ($f['overdue']) { $w[] = "l.next_followup < CURDATE() AND l.status NOT IN ('Won', 'Lost')"; }
if ($f['q'] !== '') { $qq = mysqli_real_escape_string($conn, $f['q']); $w[] = "(l.name LIKE '%$qq%' OR l.contact_person LIKE '%$qq%' OR l.phone LIKE '%$qq%' OR l.email LIKE '%$qq%' OR CONCAT('LEAD-', YEAR(l.created_at), '-', LPAD(l.id, 5, '0')) LIKE '%$qq%')"; }
$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 30;
$total = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM mkt_leads l WHERE ' . implode(' AND ', $w)))[0];
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT l.*, u.names AS owner, c.name AS campaign FROM mkt_leads l LEFT JOIN users u ON u.id = l.owner_id LEFT JOIN mkt_campaigns c ON c.id = l.campaign_id WHERE '
    . implode(' AND ', $w) . ' ORDER BY (l.next_followup IS NULL), l.next_followup, l.id DESC LIMIT ' . (($page - 1) * $per) . ", $per"), MYSQLI_ASSOC);
$overdueCount = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mkt_leads l WHERE l.next_followup < CURDATE() AND l.status NOT IN ('Won', 'Lost') AND " . mkt_lead_scope_sql($conn, $me)))[0];
$v = function ($k) { return $_POST[$k] ?? ''; };
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('leads.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<?php if ($overdueCount) { ?><div class="alert alert-danger small"><i class="bi bi-alarm"></i> <?= $overdueCount; ?> lead(s) with an overdue follow-up. <a href="leads.php?overdue=1" class="fw-semibold">Show them</a></div><?php } ?>
<?php if ($canLeads) { ?>
<details class="card border-0 shadow-sm mb-3" <?= isset($error) ? 'open' : ''; ?>><summary class="card-body py-2 fw-semibold">+ Register a lead</summary>
<form method="POST" class="card-body pt-0 row g-2">
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Lead name (person or organization) *</label><input name="name" maxlength="160" required class="form-control form-control-sm" value="<?= $e($v('name')); ?>"></div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Contact person</label><input name="contact_person" maxlength="120" class="form-control form-control-sm" value="<?= $e($v('contact_person')); ?>"></div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Organization type</label><select name="org_type" class="form-select form-select-sm"><option value=""></option><?php foreach ($L['customer_types'] as $t) { ?><option <?= $v('org_type') === $t ? 'selected' : ''; ?>><?= $e($t); ?></option><?php } ?></select></div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Phone *</label><input name="phone" maxlength="30" inputmode="tel" class="form-control form-control-sm" value="<?= $e($v('phone')); ?>" placeholder="07XXXXXXXX"></div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Email</label><input type="email" name="email" maxlength="120" class="form-control form-control-sm" value="<?= $e($v('email')); ?>"></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">Location</label><input name="location" maxlength="160" class="form-control form-control-sm" value="<?= $e($v('location')); ?>"></div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Product / service of interest</label><select name="product_id" class="form-select form-select-sm"><option value=""></option><?php foreach ($L['offerings'] as $o) { ?><option value="<?= (int) $o['id']; ?>" <?= (int) $v('product_id') === (int) $o['id'] ? 'selected' : ''; ?>><?= $e($o['product_name']); ?></option><?php } ?></select></div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Lead source *</label><select name="source" required class="form-select form-select-sm"><option value=""></option><?php foreach ($sources as $s) { ?><option <?= $v('source') === $s ? 'selected' : ''; ?>><?= $e($s); ?></option><?php } ?></select></div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Related campaign</label><select name="campaign_id" class="form-select form-select-sm"><option value="">— none —</option><?php foreach ($camps as $c) { ?><option value="<?= (int) $c['id']; ?>" <?= (int) $v('campaign_id') === (int) $c['id'] ? 'selected' : ''; ?>><?= $e(mkt_code('campaign', $c) . ' ' . $c['name']); ?></option><?php } ?></select></div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Lead owner<?= $canManage ? '' : ' (you)'; ?></label><select name="owner_id" class="form-select form-select-sm" <?= $canManage ? '' : 'disabled'; ?>><?php foreach ($L['users'] as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= (int) ($v('owner_id') ?: $me) === (int) $u['id'] ? 'selected' : ''; ?>><?= $e($u['names']); ?></option><?php } ?></select></div>
    <div class="col-md-8"><label class="form-label small text-muted mb-1">Supporting team members</label><select name="supporters[]" multiple size="3" class="form-select form-select-sm"><?php foreach ($L['users'] as $u) { ?><option value="<?= (int) $u['id']; ?>"><?= $e($u['names']); ?></option><?php } ?></select></div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Estimated value (RWF)</label><input type="number" min="0" name="estimated_value" class="form-control form-control-sm" value="<?= $e($v('estimated_value')); ?>"></div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Next follow-up</label><input type="date" min="<?= date('Y-m-d'); ?>" name="next_followup" class="form-control form-control-sm" value="<?= $e($v('next_followup')); ?>"></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">Notes</label><input name="notes" class="form-control form-control-sm" value="<?= $e($v('notes')); ?>"></div>
    <div class="col-12"><button class="btn btn-primary btn-sm">Register lead</button> <span class="small text-muted">A contact that is already an open lead (same phone or email) is refused, to avoid duplicates.</span></div>
</form></details>
<?php } ?>
<form class="card border-0 shadow-sm mb-2"><div class="card-body row g-2 small align-items-end">
    <div class="col-md-3"><input name="q" value="<?= $e($f['q']); ?>" class="form-control form-control-sm" placeholder="Name, phone, email or Lead ID"></div>
    <div class="col-6 col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Open leads</option><?php foreach (MKT_LEAD_FLOW as $x) { ?><option <?= $f['status'] === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><select name="campaign" class="form-select form-select-sm"><option value="">All campaigns</option><?php foreach ($camps as $c) { ?><option value="<?= (int) $c['id']; ?>" <?= $f['campaign'] === (int) $c['id'] ? 'selected' : ''; ?>><?= $e($c['name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><select name="owner" class="form-select form-select-sm"><option value="">All owners</option><?php foreach ($L['users'] as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= $f['owner'] === (int) $u['id'] ? 'selected' : ''; ?>><?= $e($u['names']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><select name="source" class="form-select form-select-sm"><option value="">All sources</option><?php foreach ($sources as $s) { ?><option <?= $f['source'] === $s ? 'selected' : ''; ?>><?= $e($s); ?></option><?php } ?></select></div>
    <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    <div class="col-12"><label class="me-3"><input type="checkbox" name="overdue" value="1" class="form-check-input" <?= $f['overdue'] ? 'checked' : ''; ?>> Overdue follow-ups only</label><label><input type="checkbox" name="closed" value="1" class="form-check-input" <?= !empty($_GET['closed']) ? 'checked' : ''; ?>> Include won and lost</label></div>
</div></form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Lead</th><th>Contact</th><th>Source</th><th>Campaign</th><th>Owner</th><th class="text-end">Est. value</th><th>Next follow-up</th><th>Status</th><th></th></tr>
    <?php if (!$rows) { ?><tr><td colspan="9" class="text-center text-muted py-4">No leads here.</td></tr><?php } ?>
    <?php foreach ($rows as $l) { $od = $l['next_followup'] && $l['next_followup'] < date('Y-m-d') && mkt_lead_open($l); $today = $l['next_followup'] === date('Y-m-d'); ?>
    <tr class="<?= $od ? 'table-danger' : ''; ?>"><td class="ps-3"><strong><?= $e($l['name']); ?></strong><div class="small text-muted"><?= mkt_code('lead', $l); ?><?= $l['org_type'] ? ' · ' . $e($l['org_type']) : ''; ?></div></td>
        <td class="small"><?= $e($l['contact_person']); ?><div><?= $e($l['phone']); ?></div></td><td class="small"><?= $e($l['source']); ?></td><td class="small"><?= $e($l['campaign']); ?></td><td class="small"><?= $e($l['owner']); ?></td>
        <td class="text-end"><?= $l['estimated_value'] !== null ? $rwf($l['estimated_value']) : '—'; ?></td>
        <td class="small text-nowrap <?= $od ? 'text-danger fw-bold' : ($today ? 'fw-bold' : ''); ?>"><?= $l['next_followup'] ? date('d M Y', strtotime($l['next_followup'])) . ($od ? ' · overdue' : ($today ? ' · today' : '')) : '—'; ?></td>
        <td><?= mkt_badge($l['status']); ?></td><td><a href="lead.php?id=<?= (int) $l['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">Open</a></td></tr><?php } ?>
</table></div></div></div>
<?php if ($total > $per) { ?><nav class="mt-2"><ul class="pagination pagination-sm flex-wrap"><?php for ($i = 1; $i <= (int) ceil($total / $per); $i++) { ?><li class="page-item <?= $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?= $e(http_build_query(['page' => $i] + $_GET)); ?>"><?= $i; ?></a></li><?php } ?></ul></nav><?php } ?>
<?php include '../../includes/footer.php'; ?>