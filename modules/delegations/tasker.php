<?php
/** NEW FILE: modules/delegations/tasker.php — RM Tasker profile: details, what they can do for RISE MOTIVE (services, products, rates), rate history. */
require __DIR__ . '/common.php';
dl_require($conn, 'view');
$pid = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$t = dl_tasker($conn, $pid);
if (!$t) { dl_go('taskers.php', 'error', 'RM Tasker not found.'); }
$back = 'tasker.php?id=' . $pid;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dl_csrf_ok()) { dl_go($back, 'error', 'The form expired. Please try again.'); }
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'rate') {
        dl_require($conn, 'taskers');
        $oid = (int) ($_POST['oid'] ?? 0);
        $old = $oid ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM dl_tasker_offerings WHERE id = $oid AND party_id = $pid")) : null;
        $prod = (int) ($old['product_id'] ?? $_POST['product_id'] ?? 0);
        $p = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, product_name, item_type, is_active FROM products WHERE id = ' . $prod));
        $rate = (float) ($_POST['rate_pct'] ?? -1);
        $num = function ($k) { $x = trim((string) ($_POST[$k] ?? '')); return $x === '' ? null : max(0, (float) str_replace(',', '', $x)); };
        $min = $num('min_price'); $max = $num('max_price');
        $hours = $num('est_value');
        if ($hours !== null && ($_POST['est_unit'] ?? 'hours') === 'days') { $hours *= 24; }
        $avail = in_array($_POST['availability'] ?? '', DL_AVAILABILITY, true) ? $_POST['availability'] : 'Available';
        $vf = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['valid_from'] ?? '')) ? $_POST['valid_from'] : null;
        $vt = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['valid_to'] ?? '')) ? $_POST['valid_to'] : null;
        $st = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255);
        $err = '';
        if (!$p || !(int) $p['is_active']) { $err = 'Choose an active RM Offering.'; }
        elseif ($rate < 0 || $rate > 100) { $err = 'The rate is a percentage between 0 and 100.'; }
        elseif ($min !== null && $max !== null && $max > 0 && $max < $min) { $err = 'The maximum price is below the minimum.'; }
        elseif ($vf && $vt && $vt < $vf) { $err = 'The rate validity ends before it starts.'; }
        elseif (!$hours) { $err = 'Give the estimated completion time: deadlines and comparisons need it.'; }
        elseif ($old && abs((float) $old['rate_pct'] - $rate) >= 0.005 && $reason === '') { $err = 'Give the reason for changing the rate (kept in the rate history).'; }
        elseif (!$old && mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM dl_tasker_offerings WHERE party_id = $pid AND product_id = $prod AND status = 'Active'"))) { $err = 'This RM Tasker already has an active rate for ' . $p['product_name'] . ': edit it instead.'; }
        if ($err) { dl_go($back, 'error', $err); }
        $now = dl_now(); $u = dl_uid();
        if ($old) {
            $s = mysqli_prepare($conn, 'UPDATE dl_tasker_offerings SET rate_pct = ?, min_price = ?, max_price = ?, est_hours = ?, availability = ?, valid_from = ?, valid_to = ?, status = ?, updated_by = ?, updated_at = ? WHERE id = ?');
            mysqli_stmt_bind_param($s, 'ddddssssisi', $rate, $min, $max, $hours, $avail, $vf, $vt, $st, $u, $now, $oid);
            mysqli_stmt_execute($s);
            $chg = [];
            foreach (['rate_pct' => ['rate', $rate, 'dl_pct'], 'min_price' => ['minimum', $min, 'dl_money'], 'max_price' => ['maximum', $max, 'dl_money'], 'est_hours' => ['time', $hours, 'dl_hours_text']] as $k => [$l, $nv, $fm]) {
                if (abs((float) $old[$k] - (float) $nv) >= 0.005 || (($old[$k] === null) !== ($nv === null))) { $chg[] = $l . ' ' . ($old[$k] === null ? '—' : $fm($old[$k])) . ' → ' . ($nv === null ? '—' : $fm($nv)); }
            }
            foreach (['availability' => $avail, 'valid_from' => $vf, 'valid_to' => $vt, 'status' => $st] as $k => $nv) { if ((string) $old[$k] !== (string) $nv) { $chg[] = str_replace('_', ' ', $k) . ' ' . ($old[$k] ?: '—') . ' → ' . ($nv ?: '—'); } }
            if ($chg) {
                $txt = implode('; ', $chg); $or = (float) $old['rate_pct'];
                $s = mysqli_prepare($conn, 'INSERT INTO dl_rate_history (tasker_offering_id, party_id, product_id, old_rate, new_rate, change_text, reason, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($s, 'iiiddssis', $oid, $pid, $prod, $or, $rate, $txt, $reason, $u, $now);
                mysqli_stmt_execute($s);
                dl_audit($conn, 'rate', $oid, 'rate_change', $t['code'] . ' · ' . $p['product_name'] . ': ' . $txt, $reason ?: null, 'staff', $pid);
            }
            dl_go($back, 'success', 'Saved. Delegations already made keep the rate agreed at the time.');
        }
        $s = mysqli_prepare($conn, 'INSERT INTO dl_tasker_offerings (party_id, product_id, rate_pct, min_price, max_price, est_hours, availability, valid_from, valid_to, status, created_by, created_at, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'iiddddssssisis', $pid, $prod, $rate, $min, $max, $hours, $avail, $vf, $vt, $st, $u, $now, $u, $now);
        mysqli_stmt_execute($s);
        $oid = (int) mysqli_insert_id($conn);
        $txt = 'new: ' . dl_pct($rate) . ', ' . dl_hours_text($hours);
        $s = mysqli_prepare($conn, 'INSERT INTO dl_rate_history (tasker_offering_id, party_id, product_id, old_rate, new_rate, change_text, reason, user_id, created_at) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'iiidssis', $oid, $pid, $prod, $rate, $txt, $reason, $u, $now);
        mysqli_stmt_execute($s);
        dl_audit($conn, 'rate', $oid, 'rate_added', $t['code'] . ' · ' . $p['product_name'] . ': ' . $txt, $reason ?: null, 'staff', $pid);
        dl_go($back, 'success', $p['product_name'] . ' added at ' . dl_pct($rate) . '.');
    }
    if ($act === 'access_send') {
        dl_require($conn, 'taskers');
        if ($t['tasker_status'] !== 'Active') { dl_go($back, 'error', 'Only an Active RM Tasker can receive portal access.'); }
        $acc = dl_account($conn, $pid);
        if ($acc && $acc['status'] === 'blocked') { dl_go($back, 'error', 'Portal access is blocked: unblock it first.'); }
        $r = dl_send_access($conn, $pid);
        dl_go($back, 'success', 'Access link sent (SMS ' . ($r['sms'] ? 'sent' : 'NOT sent') . ', e-mail ' . ($r['email'] ? 'sent' : 'not sent') . '). It is valid 72 hours.');
    }
    if ($act === 'access_block') {
        dl_require($conn, 'taskers');
        $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255);
        $acc = dl_account($conn, $pid);
        if (!$acc) { dl_go($back, 'error', 'No portal account yet.'); }
        $to = $acc['status'] === 'blocked' ? ($acc['password_hash'] ? 'active' : 'pending') : 'blocked';
        if ($to === 'blocked' && $reason === '') { dl_go($back, 'error', 'Give the reason for blocking the access.'); }
        mysqli_query($conn, "UPDATE dl_tasker_accounts SET status = '$to', token_hash = NULL WHERE party_id = $pid");
        dl_audit($conn, 'tasker', $pid, $to === 'blocked' ? 'portal_blocked' : 'portal_unblocked', $t['code'] . ' portal access ' . ($to === 'blocked' ? 'blocked' : 'restored'), $reason ?: null, 'staff', $pid);
        dl_go($back, 'success', $to === 'blocked' ? 'Portal access blocked.' : 'Portal access restored.');
    }
    if ($act === 'preferred') {
        dl_require($conn, 'approve');
        $on = (int) !((int) $t['preferred']);
        $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255);
        if ($reason === '') { dl_go($back, 'error', 'Give the reason (for example consistent quality over the last months).'); }
        $now = dl_now(); $u = dl_uid();
        $s = mysqli_prepare($conn, 'UPDATE dl_taskers SET preferred = ?, preferred_reason = ?, updated_by = ?, updated_at = ? WHERE party_id = ?');
        mysqli_stmt_bind_param($s, 'isisi', $on, $reason, $u, $now, $pid);
        mysqli_stmt_execute($s);
        dl_audit($conn, 'tasker', $pid, $on ? 'preferred_on' : 'preferred_off', $t['code'] . ' ' . $t['display'] . ($on ? ' is now a PREFERRED RM TASKER' : ' is no longer a Preferred RM Tasker'), $reason, 'staff', $pid);
        dl_go($back, 'success', $on ? 'Marked as Preferred RM Tasker: recommendations give a small priority, cost and availability still count.' : 'Preferred status removed.');
    }
    dl_go($back, 'error', 'Unknown action.');
}

$rates = mysqli_fetch_all(mysqli_query($conn, "SELECT o.*, p.product_name, p.item_type, p.selling_price, p.is_active p_active FROM dl_tasker_offerings o JOIN products p ON p.id = o.product_id WHERE o.party_id = $pid ORDER BY o.status, p.item_type DESC, p.product_name"), MYSQLI_ASSOC);
$hist = mysqli_fetch_all(mysqli_query($conn, "SELECT h.*, p.product_name FROM dl_rate_history h LEFT JOIN products p ON p.id = h.product_id WHERE h.party_id = $pid ORDER BY h.id DESC LIMIT 30"), MYSQLI_ASSOC);
$offers = mysqli_fetch_all(mysqli_query($conn, "SELECT id, product_name, item_type FROM products WHERE is_active = 1 ORDER BY item_type DESC, product_name"), MYSQLI_ASSOC);
$h = dl_tasker_history($conn)[$pid] ?? null;
$names = dl_users($conn);
$canEdit = dlc($conn, 'taskers');
$today = date('Y-m-d');

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'taskers.php', $t['code'] . ' · ' . $t['display'], $canEdit ? '<a href="tasker_edit.php?id=' . $pid . '" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i> Edit profile</a>' : '');
?>
<div class="row g-3 mb-3">
<div class="col-lg-7"><div class="card h-100"><div class="card-body py-2 small">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2"><span class="dl-st dl-blue">RM Tasker</span><?= dl_status_chip($t['tasker_status']); ?><?= dl_status_chip($t['availability']); ?>
        <?php if ((int) $t['preferred']) { ?><span class="dl-st dl-gold"><i class="bi bi-award"></i> PREFERRED RM TASKER</span><?php } ?>
        <?php if (!$h || $h['done'] < (int) dl_setting($conn, 'min_history')) { ?><span class="dl-st">New / limited history</span><?php } ?></div>
    <table class="table table-sm mb-0 dl-tbl"><tbody>
        <tr><th style="width:38%">Name</th><td><?= dl_e($t['name']); ?><?= $t['business_name'] ? ' · ' . dl_e($t['business_name']) : ''; ?><?= $t['tin'] ? ' · TIN ' . dl_e($t['tin']) : ''; ?></td></tr>
        <tr><th>Phone / email</th><td><?= dl_e($t['phone']); ?><?= $t['email'] ? ' · ' . dl_e($t['email']) : ' · <span class="text-danger">no email</span>'; ?></td></tr>
        <tr><th>Location</th><td><?= dl_e(implode(', ', array_filter([$t['sector'], $t['district'], $t['province']]))); ?></td></tr>
        <tr><th>Specialization</th><td><?= dl_e($t['specialization']); ?></td></tr>
        <tr><th>Experience</th><td><?= nl2br(dl_e($t['experience'])); ?></td></tr>
        <tr><th>Working availability</th><td><?= dl_e($t['availability_note'] ?: '—'); ?></td></tr>
        <tr><th>Payment destination</th><td><?= dl_e(($t['payment_account_type'] === 'Phone' ? 'Mobile Money' : $t['payment_account_type']) . ' · ' . $t['payment_account_details']); ?></td></tr>
        <?php if ($t['tasker_status'] !== 'Active') { ?><tr><th>Status reason</th><td><?= dl_e($t['status_reason']); ?></td></tr><?php } ?>
        <?php if ($t['notes']) { ?><tr><th>Notes</th><td><?= nl2br(dl_e($t['notes'])); ?></td></tr><?php } ?>
    </tbody></table>
</div></div></div>
<div class="col-lg-5"><div class="card h-100"><div class="card-body py-2 small">
    <div class="fw-semibold mb-1">Performance</div>
    <?php if ($h && $h['done']) { ?>
        <div>Completed and verified: <b><?= (int) $h['done']; ?></b> of <?= (int) $h['assigned']; ?> assigned</div>
        <div>On time: <b><?= round(($h['ontime'] ?? 0) * 100); ?>%</b> · accepted: <?= $h['accepted'] !== null ? round($h['accepted'] * 100) . '%' : '—'; ?></div>
        <div>Verified quality: <b><?= $h['quality'] !== null ? number_format($h['quality'], 1) . '/5' : '—'; ?></b> · issues found to be the Tasker's: <?= (int) $h['complaints']; ?></div>
    <?php } else { ?><div class="text-muted">No completed delegation yet<?= $t['quality_rating'] !== null ? '. Starting rating: ' . dl_e(number_format((float) $t['quality_rating'], 1)) . '/5' : ''; ?>.</div><?php } ?>
    <?php if (dlc($conn, 'approve')) { ?>
    <form method="post" class="d-flex gap-1 mt-2" onsubmit="var r=prompt('Reason?');if(!r){return false;}this.reason.value=r;"><?= dl_csrf_field(); ?><input type="hidden" name="id" value="<?= $pid; ?>"><input type="hidden" name="act" value="preferred"><input type="hidden" name="reason">
        <button class="btn btn-sm <?= (int) $t['preferred'] ? 'btn-outline-secondary' : 'btn-outline-warning'; ?> py-0"><?= (int) $t['preferred'] ? 'Remove Preferred status' : 'Mark as Preferred RM Tasker'; ?></button></form>
    <?php if ((int) $t['preferred'] && $t['preferred_reason']) { ?><div class="text-muted mt-1">Why preferred: <?= dl_e($t['preferred_reason']); ?></div><?php } ?>
    <?php } ?>
    <?php $acc = dl_account($conn, $pid); ?>
    <div class="fw-semibold mt-3 mb-1">Customers Portal access (RM Tasker view)</div>
    <div><?= !$acc ? 'Not sent yet' : ($acc['status'] === 'active' ? 'Active · last sign-in ' . ($acc['last_login_at'] ? dl_e(date('d M Y H:i', strtotime($acc['last_login_at']))) : 'never') : ($acc['status'] === 'blocked' ? '<span class="text-danger">Blocked</span>' : 'Link sent, password not set yet')); ?></div>
    <?php if (dlc($conn, 'taskers')) { ?><div class="d-flex flex-wrap gap-1 mt-1">
        <?php if (!$acc || $acc['status'] !== 'blocked') { ?><form method="post"><?= dl_csrf_field(); ?><input type="hidden" name="id" value="<?= $pid; ?>"><input type="hidden" name="act" value="access_send"><button class="btn btn-outline-primary btn-sm py-0"><?= $acc && $acc['status'] === 'active' ? 'Send a new password link' : 'Send portal access link'; ?></button></form><?php } ?>
        <?php if ($acc) { ?><form method="post" onsubmit="<?= $acc['status'] === 'blocked' ? '' : "var r=prompt('Reason?');if(!r){return false;}this.reason.value=r;"; ?>"><?= dl_csrf_field(); ?><input type="hidden" name="id" value="<?= $pid; ?>"><input type="hidden" name="act" value="access_block"><input type="hidden" name="reason"><button class="btn btn-outline-<?= $acc['status'] === 'blocked' ? 'success' : 'danger'; ?> btn-sm py-0"><?= $acc['status'] === 'blocked' ? 'Unblock' : 'Block access'; ?></button></form><?php } ?>
    </div><?php } ?>
    <div class="text-muted mt-1">The RM Tasker signs in with <?= dl_e($t['code']); ?> and sees only their own tasks and payments, never customer or business information.</div>
</div></div></div>
</div>

<h6 class="mb-1">What they can do for RISE MOTIVE</h6>
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0">
<thead><tr><th>Service / product</th><th class="text-end">Rate</th><th class="text-end">Customer price accepted</th><th>Completion time</th><th>Availability</th><th>Valid</th><th>Status</th><?php if ($canEdit) { ?><th></th><?php } ?></tr></thead><tbody>
<?php foreach ($rates as $r) { $expired = $r['valid_to'] && $r['valid_to'] < $today; ?>
<tr><td><a href="offering.php?id=<?= (int) $r['product_id']; ?>"><?= dl_e($r['product_name']); ?></a> <span class="text-muted small"><?= $r['item_type'] === 'Service' ? 'service' : 'product'; ?></span><?= (int) $r['p_active'] ? '' : ' <span class="dl-st dl-bad">offering inactive</span>'; ?></td>
    <td class="text-end fw-semibold"><?= dl_pct($r['rate_pct']); ?></td>
    <td class="text-end small"><?= $r['min_price'] !== null || $r['max_price'] !== null ? ($r['min_price'] !== null ? dl_money($r['min_price']) : '…') . ' – ' . ($r['max_price'] ? dl_money($r['max_price']) : '…') : 'any'; ?></td>
    <td><?= dl_hours_text($r['est_hours']); ?></td><td><?= dl_status_chip($r['availability']); ?></td>
    <td class="small text-nowrap"><?= $r['valid_from'] || $r['valid_to'] ? ($r['valid_from'] ? date('d M Y', strtotime($r['valid_from'])) : '…') . ' – ' . ($r['valid_to'] ? date('d M Y', strtotime($r['valid_to'])) : '…') : 'open'; ?><?= $expired ? ' <span class="dl-st dl-bad">expired</span>' : ''; ?></td>
    <td><?= dl_status_chip($r['status']); ?></td>
    <?php if ($canEdit) { ?><td class="text-end"><button type="button" class="btn btn-outline-primary btn-sm py-0" onclick='dlEdit(<?= json_encode(['oid' => (int) $r['id'], 'product_id' => (int) $r['product_id'], 'rate_pct' => (float) $r['rate_pct'], 'min_price' => $r['min_price'], 'max_price' => $r['max_price'], 'est' => (float) $r['est_hours'], 'availability' => $r['availability'], 'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to'], 'status' => $r['status']], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Edit</button></td><?php } ?></tr>
<?php } ?>
<?php if (!$rates) { ?><tr><td colspan="8" class="text-center text-muted py-4">No service or product yet.</td></tr><?php } ?>
</tbody></table></div></div>

<?php if ($canEdit) { ?>
<form method="post" class="card mb-3" id="rateForm"><div class="card-body py-2"><?= dl_csrf_field(); ?><input type="hidden" name="id" value="<?= $pid; ?>"><input type="hidden" name="act" value="rate"><input type="hidden" name="oid" value="0">
<div class="fw-semibold small mb-1" id="rateTitle">Add a service or product</div>
<div class="row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label small mb-0">RM Offering</label><select name="product_id" class="form-select form-select-sm" required><option value="">Choose…</option><?php foreach ($offers as $o) { ?><option value="<?= (int) $o['id']; ?>"><?= dl_e($o['product_name']); ?><?= $o['item_type'] === 'Service' ? '' : ' (product)'; ?></option><?php } ?></select></div>
    <div class="col-md-2"><label class="form-label small mb-0">Rate (% of profit pool)</label><input type="number" step="0.01" min="0" max="100" name="rate_pct" class="form-control form-control-sm" required></div>
    <div class="col-md-3"><label class="form-label small mb-0">Completion time</label><div class="input-group input-group-sm"><input type="number" step="0.5" min="0.5" name="est_value" class="form-control" required><select name="est_unit" class="form-select" style="max-width:90px"><option value="hours">hours</option><option value="days">days</option></select></div></div>
    <div class="col-md-3"><label class="form-label small mb-0">Availability</label><select name="availability" class="form-select form-select-sm"><?php foreach (DL_AVAILABILITY as $a) { ?><option><?= $a; ?></option><?php } ?></select></div>
    <div class="col-md-2"><label class="form-label small mb-0">Min. price (RWF)</label><input name="min_price" inputmode="numeric" class="form-control form-control-sm" placeholder="optional"></div>
    <div class="col-md-2"><label class="form-label small mb-0">Max. price (RWF)</label><input name="max_price" inputmode="numeric" class="form-control form-control-sm" placeholder="optional"></div>
    <div class="col-md-2"><label class="form-label small mb-0">Valid from</label><input type="date" name="valid_from" class="form-control form-control-sm"></div>
    <div class="col-md-2"><label class="form-label small mb-0">Valid until</label><input type="date" name="valid_to" class="form-control form-control-sm"></div>
    <div class="col-md-2"><label class="form-label small mb-0">Status</label><select name="status" class="form-select form-select-sm"><option>Active</option><option>Inactive</option></select></div>
    <div class="col-md-8"><label class="form-label small mb-0">Reason (required when a rate changes)</label><input name="reason" class="form-control form-control-sm"></div>
    <div class="col-md-4 d-flex gap-1"><button class="btn btn-primary btn-sm">Save</button><button type="button" class="btn btn-link btn-sm" onclick="dlReset()">Clear</button></div>
</div>
<div class="small text-muted mt-1">The rate is the Tasker's share of the profit pool (customer price minus cost of sales), not of the full price.</div>
</div></form>
<script>
function dlEdit(r) {
    var f = document.getElementById('rateForm');
    f.oid.value = r.oid; f.product_id.value = r.product_id; f.product_id.disabled = true; f.rate_pct.value = r.rate_pct;
    if (r.est % 24 === 0) { f.est_value.value = r.est / 24; f.est_unit.value = 'days'; } else { f.est_value.value = r.est; f.est_unit.value = 'hours'; }
    f.min_price.value = r.min_price === null ? '' : Math.round(r.min_price); f.max_price.value = r.max_price === null ? '' : Math.round(r.max_price);
    f.availability.value = r.availability; f.valid_from.value = r.valid_from || ''; f.valid_to.value = r.valid_to || ''; f.status.value = r.status; f.reason.value = '';
    document.getElementById('rateTitle').textContent = 'Edit ' + f.product_id.options[f.product_id.selectedIndex].text;
    f.scrollIntoView({behavior: 'smooth'});
}
function dlReset() { var f = document.getElementById('rateForm'); f.reset(); f.oid.value = 0; f.product_id.disabled = false; document.getElementById('rateTitle').textContent = 'Add a service or product'; }
</script>
<?php } ?>

<?php $mine = mysqli_fetch_all(mysqli_query($conn, "SELECT d.id, d.status, d.response, d.deadline, d.pay_exp, d.pay_due, d.perf_score, p.product_name FROM dl_delegations d LEFT JOIN products p ON p.id = d.product_id WHERE d.party_id = $pid ORDER BY d.id DESC LIMIT 50"), MYSQLI_ASSOC); ?>
<?php if ($mine) { ?>
<h6 class="mb-1">Delegations</h6>
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0"><thead><tr><th>Delegation</th><th>Service</th><th>Response</th><th>Status</th><th>Deadline</th><th class="text-end">Payment</th><th class="text-end">Performance</th></tr></thead><tbody>
<?php foreach ($mine as $r) { ?><tr><td><a href="view.php?id=<?= (int) $r['id']; ?>"><?= dl_ref((int) $r['id']); ?></a></td><td class="small"><?= dl_e($r['product_name']); ?></td><td class="small"><?= dl_e(dl_response_text($r['response'])); ?></td><td><?= dl_status_badge($r['status']); ?></td><td class="small"><?= dl_e(dl_deadline_text($r['deadline'])); ?></td><td class="text-end small"><?= dl_money($r['pay_due'] ?? $r['pay_exp']); ?></td><td class="text-end small"><?= $r['perf_score'] !== null ? dl_e((float) $r['perf_score']) . '/100' : '—'; ?></td></tr><?php } ?>
</tbody></table></div></div>
<?php } ?>
<h6 class="mb-1">Rate history</h6>
<div class="card"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0"><thead><tr><th>When</th><th>Service / product</th><th>Change</th><th>Reason</th><th>By</th></tr></thead><tbody>
<?php foreach ($hist as $r) { ?><tr><td class="text-nowrap small"><?= dl_e(date('d M Y H:i', strtotime($r['created_at']))); ?></td><td><?= dl_e($r['product_name']); ?></td><td class="small"><?= dl_e($r['change_text']); ?></td><td class="small"><?= dl_e($r['reason']); ?></td><td class="small"><?= dl_e($names[(int) $r['user_id']] ?? '—'); ?></td></tr><?php } ?>
<?php if (!$hist) { ?><tr><td colspan="5" class="text-center text-muted py-3">No rate recorded yet.</td></tr><?php } ?>
</tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>