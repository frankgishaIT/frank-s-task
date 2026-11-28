<?php
/**
 * NEW FILE: modules/delegations/tasker_edit.php — register or edit an RM Tasker.
 * Saved in business_parties (type 'Tasker', shown as "RM Tasker") + dl_taskers. RMST number = dl_taskers.id.
 */
require __DIR__ . '/common.php';
dl_require($conn, 'taskers');
$pid = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$t = $pid ? dl_tasker($conn, $pid) : null;
if ($pid && !$t) { dl_go('taskers.php', 'error', 'RM Tasker not found.'); }
$error = '';
$v = $t ?: ['name' => '', 'business_name' => '', 'representative_name' => '', 'tin' => '', 'phone' => '', 'email' => '', 'province' => '', 'district' => '', 'sector' => '', 'payment_account_type' => '',
    'payment_account_details' => '', 'specialization' => '', 'experience' => '', 'availability' => 'Available', 'availability_note' => '', 'quality_rating' => null, 'tasker_status' => 'Active', 'status_reason' => '', 'notes' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = function ($k, $max = 255) { return mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max); };
    $f = ['name' => $in('name', 150), 'business_name' => $in('business_name', 150), 'tin' => $in('tin', 30), 'phone' => preg_replace('/\D/', '', $in('phone', 20)), 'email' => $in('email', 150),
        'province' => $in('province', 60), 'district' => $in('district', 60), 'sector' => $in('sector', 60), 'payment_account_type' => in_array($_POST['payment_account_type'] ?? '', ['Bank', 'Phone'], true) ? $_POST['payment_account_type'] : '',
        'payment_account_details' => $in('payment_account_details', 150), 'specialization' => $in('specialization', 255), 'experience' => $in('experience', 2000),
        'availability' => in_array($_POST['availability'] ?? '', DL_AVAILABILITY, true) ? $_POST['availability'] : 'Available', 'availability_note' => $in('availability_note', 255),
        'quality_rating' => ($_POST['quality_rating'] ?? '') === '' ? null : max(1, min(5, (float) $_POST['quality_rating'])),
        'tasker_status' => in_array($_POST['status'] ?? '', DL_TASKER_STATUS, true) ? $_POST['status'] : 'Active', 'status_reason' => $in('status_reason', 255), 'notes' => $in('notes', 2000)];
    $v = array_merge($v, $f);
    $dup = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM business_parties WHERE type = 'Tasker' AND RIGHT(REPLACE(phone, ' ', ''), 9) = '" . mysqli_real_escape_string($conn, substr($f['phone'], -9)) . "' AND id <> " . $pid . ' LIMIT 1'));
    if (!dl_csrf_ok()) { $error = 'The form expired. Please try again.'; }
    elseif ($f['name'] === '') { $error = 'Give the full name (the individual, or the business representative).'; }
    elseif (!preg_match('/^\d{10}$/', $f['phone'])) { $error = 'The phone number must be exactly 10 digits (e.g. 0788123456).'; }
    elseif ($dup) { $error = 'Another RM Tasker already uses this phone number.'; }
    elseif ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) { $error = 'The email address is not valid.'; }
    elseif ($f['province'] === '' || $f['district'] === '') { $error = 'Choose the operating location (province and district at least).'; }
    elseif ($f['payment_account_type'] === '' || $f['payment_account_details'] === '') { $error = 'Give the approved payment destination (bank account or mobile money number).'; }
    elseif ($f['specialization'] === '') { $error = 'Give the specialization (for example Graphic design, Printing, IT support).'; }
    elseif ($f['tasker_status'] !== 'Active' && $f['status_reason'] === '') { $error = 'Give the reason for the status ' . $f['tasker_status'] . '.'; }
    else {
        $now = dl_now(); $u = dl_uid();
        $bn = $f['business_name'] ?: null; $tin = $f['tin'] ?: null; $em = $f['email'] ?: null; $rep = $f['business_name'] ? $f['name'] : null;
        $sec = $f['sector'] ?: null;
        $active = $f['tasker_status'] === 'Active' ? 1 : 0;
        $reason = $f['tasker_status'] === 'Active' ? null : $f['status_reason'];
        mysqli_begin_transaction($conn);
        try {
            if ($t) {
                $s = mysqli_prepare($conn, 'UPDATE business_parties SET tin = ?, business_name = ?, representative_name = ?, name = ?, phone = ?, email = ?, payment_account_type = ?, payment_account_details = ?, province = ?, district = ?, sector = ?, is_active = ? WHERE id = ?');
                mysqli_stmt_bind_param($s, 'sssssssssssii', $tin, $bn, $rep, $f['name'], $f['phone'], $em, $f['payment_account_type'], $f['payment_account_details'], $f['province'], $f['district'], $sec, $active, $pid);
                mysqli_stmt_execute($s);
                $s = mysqli_prepare($conn, 'UPDATE dl_taskers SET specialization = ?, experience = ?, availability = ?, availability_note = ?, quality_rating = ?, status = ?, status_reason = ?, notes = ?, updated_by = ?, updated_at = ? WHERE party_id = ?');
                mysqli_stmt_bind_param($s, 'ssssdsssisi', $f['specialization'], $f['experience'], $f['availability'], $f['availability_note'], $f['quality_rating'], $f['tasker_status'], $reason, $f['notes'], $u, $now, $pid);
                mysqli_stmt_execute($s);
                $chg = [];
                $map = ['name' => 'name', 'business_name' => 'business name', 'tin' => 'TIN', 'phone' => 'phone', 'email' => 'email', 'district' => 'district', 'sector' => 'sector', 'specialization' => 'specialization',
                    'availability' => 'availability', 'quality_rating' => 'quality rating', 'tasker_status' => 'status'];
                foreach ($map as $k => $l) { if ((string) ($t[$k] ?? '') !== (string) ($f[$k] ?? '')) { $chg[] = $l . ': ' . ($t[$k] ?? '—') . ' → ' . ($f[$k] ?? '—'); } }
                if ($t['payment_account_type'] !== $f['payment_account_type'] || $t['payment_account_details'] !== $f['payment_account_details']) {
                    $mask = function ($s) { $s = (string) $s; return strlen($s) > 4 ? str_repeat('•', strlen($s) - 4) . substr($s, -4) : $s; };
                    $chg[] = 'payment destination: ' . $t['payment_account_type'] . ' ' . $mask($t['payment_account_details']) . ' → ' . $f['payment_account_type'] . ' ' . $mask($f['payment_account_details']);
                }
                if ($chg) { dl_audit($conn, 'tasker', $pid, 'edit', $t['code'] . ' ' . dl_tasker_name($f) . ': ' . implode('; ', $chg), $reason, 'staff', $pid); }
            } else {
                $type = 'Tasker';
                $s = mysqli_prepare($conn, 'INSERT INTO business_parties (type, tin, business_name, representative_name, name, phone, email, payment_account_type, payment_account_details, province, district, sector, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($s, 'ssssssssssssii', $type, $tin, $bn, $rep, $f['name'], $f['phone'], $em, $f['payment_account_type'], $f['payment_account_details'], $f['province'], $f['district'], $sec, $active, $u);
                mysqli_stmt_execute($s);
                $pid = (int) mysqli_insert_id($conn);
                $s = mysqli_prepare($conn, 'INSERT INTO dl_taskers (party_id, specialization, experience, availability, availability_note, quality_rating, status, status_reason, notes, created_by, created_at, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($s, 'issssdsssisis', $pid, $f['specialization'], $f['experience'], $f['availability'], $f['availability_note'], $f['quality_rating'], $f['tasker_status'], $reason, $f['notes'], $u, $now, $u, $now);
                mysqli_stmt_execute($s);
                $no = (int) mysqli_insert_id($conn);
                if (is_file(__DIR__ . '/../../includes/branch_helpers.php')) { require_once __DIR__ . '/../../includes/branch_helpers.php'; if (function_exists('br_stamp')) { br_stamp($conn, 'business_parties', $pid); } }
                dl_audit($conn, 'tasker', $pid, 'create', dl_code($no) . ' ' . dl_tasker_name($f) . ' registered (' . $f['specialization'] . ')', null, 'staff', $pid);
            }
            mysqli_commit($conn);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('dl tasker save: ' . $e->getMessage());
            $error = 'The RM Tasker could not be saved. Please try again.';
        }
        if (!$error) { dl_go('tasker.php?id=' . $pid, 'success', $t ? 'Saved.' : 'RM Tasker registered. Now add the services and rates.'); }
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'taskers.php', $t ? 'Edit ' . $t['code'] . ' · ' . $t['display'] : 'Register an RM Tasker');
if ($error) { echo '<div class="alert alert-danger py-2 small">' . dl_e($error) . '</div>'; }
$val = function ($k) use ($v) { return dl_e($v[$k] ?? ''); };
?>
<form method="post" class="card"><div class="card-body"><?= dl_csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $pid; ?>">
<div class="row g-2" style="max-width:900px">
    <div class="col-12 small text-muted">An RM Tasker is a Business Party (like RM Suppliers and RM Payees). <?= $t ? 'RM Tasker ID: <b>' . dl_e($t['code']) . '</b>' : 'The RM Tasker ID (RMST00001…) is given when you save.'; ?></div>
    <div class="col-md-6"><label class="form-label small mb-0">Full name (individual or business representative) *</label><input name="name" class="form-control form-control-sm" value="<?= $val('name'); ?>" required></div>
    <div class="col-md-6"><label class="form-label small mb-0">Business name (optional)</label><input name="business_name" class="form-control form-control-sm" value="<?= $val('business_name'); ?>"></div>
    <div class="col-md-3"><label class="form-label small mb-0">TIN (optional)</label><input name="tin" class="form-control form-control-sm" value="<?= $val('tin'); ?>"></div>
    <div class="col-md-3"><label class="form-label small mb-0">Phone *</label><input name="phone" class="form-control form-control-sm" value="<?= $val('phone'); ?>" inputmode="numeric" maxlength="10" pattern="\d{10}" placeholder="0788123456" required></div>
    <div class="col-md-6"><label class="form-label small mb-0">Email (for notifications)</label><input type="email" name="email" class="form-control form-control-sm" value="<?= $val('email'); ?>"></div>
    <div class="col-md-4"><label class="form-label small mb-0">Province *</label><select name="province" id="provinceSelect" class="form-select form-select-sm" required></select></div>
    <div class="col-md-4"><label class="form-label small mb-0">District *</label><select name="district" id="districtSelect" class="form-select form-select-sm" required></select></div>
    <div class="col-md-4"><label class="form-label small mb-0">Sector</label><select name="sector" id="sectorSelect" class="form-select form-select-sm"></select></div>
    <div class="col-md-6"><label class="form-label small mb-0">Specialization *</label><input name="specialization" class="form-control form-control-sm" value="<?= $val('specialization'); ?>" placeholder="Graphic design" required></div>
    <div class="col-md-3"><label class="form-label small mb-0">Availability</label><select name="availability" class="form-select form-select-sm"><?php foreach (DL_AVAILABILITY as $a) { ?><option <?= $v['availability'] === $a ? 'selected' : ''; ?>><?= $a; ?></option><?php } ?></select></div>
    <div class="col-md-3"><label class="form-label small mb-0">Starting quality rating (1–5)</label><input type="number" step="0.1" min="1" max="5" name="quality_rating" class="form-control form-control-sm" value="<?= $v['quality_rating'] !== null ? dl_e($v['quality_rating']) : ''; ?>" placeholder="optional"></div>
    <div class="col-md-12"><label class="form-label small mb-0">Working availability (days, hours)</label><input name="availability_note" class="form-control form-control-sm" value="<?= $val('availability_note'); ?>" placeholder="Mon–Sat, 8:00–18:00; weekends on request"></div>
    <div class="col-12"><label class="form-label small mb-0">Experience</label><textarea name="experience" rows="2" class="form-control form-control-sm"><?= $val('experience'); ?></textarea></div>
    <div class="col-md-4"><label class="form-label small mb-0">Payment account *</label><select name="payment_account_type" class="form-select form-select-sm" required><option value="">Choose…</option><option value="Phone" <?= $v['payment_account_type'] === 'Phone' ? 'selected' : ''; ?>>Mobile Money</option><option value="Bank" <?= $v['payment_account_type'] === 'Bank' ? 'selected' : ''; ?>>Bank</option></select></div>
    <div class="col-md-8"><label class="form-label small mb-0">Approved payment destination (number, bank and account name) *</label><input name="payment_account_details" class="form-control form-control-sm" value="<?= $val('payment_account_details'); ?>" required></div>
    <div class="col-md-4"><label class="form-label small mb-0">Status</label><select name="status" class="form-select form-select-sm"><?php foreach (DL_TASKER_STATUS as $s) { ?><option <?= $v['tasker_status'] === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select></div>
    <div class="col-md-8"><label class="form-label small mb-0">Reason (when Inactive or Suspended)</label><input name="status_reason" class="form-control form-control-sm" value="<?= $val('status_reason'); ?>"></div>
    <div class="col-12"><label class="form-label small mb-0">Notes</label><textarea name="notes" rows="2" class="form-control form-control-sm"><?= $val('notes'); ?></textarea></div>
    <div class="col-12 small text-muted">A starting rating is only a first impression: until the RM Tasker has completed enough delegations, the comparison shows "New / limited history" and uses a neutral value.</div>
</div>
<div class="d-flex gap-2 mt-3"><button class="btn btn-primary btn-sm"><?= $t ? 'Save' : 'Register RM Tasker'; ?></button><a href="<?= $t ? 'tasker.php?id=' . (int) $pid : 'taskers.php'; ?>" class="btn btn-link btn-sm">Cancel</a></div>
</div></form>
<script src="../../includes/rwanda_locations.js"></script>
<script>
(function () {
    var P = document.getElementById('provinceSelect'), D = document.getElementById('districtSelect'), S = document.getElementById('sectorSelect');
    var sp = <?= json_encode($v['province'] ?? ''); ?>, sd = <?= json_encode($v['district'] ?? ''); ?>, ss = <?= json_encode($v['sector'] ?? ''); ?>;
    function fill(sel, opts, cur, ph) { sel.innerHTML = '<option value="">' + ph + '</option>'; opts.forEach(function (o) { var e = document.createElement('option'); e.value = e.textContent = o; if (o === cur) { e.selected = true; } sel.appendChild(e); }); }
    function prov() { fill(P, typeof RWANDA_LOCATIONS === 'object' ? Object.keys(RWANDA_LOCATIONS) : [], sp, 'Choose…'); }
    function dist() { var l = P.value && RWANDA_LOCATIONS[P.value] ? Object.keys(RWANDA_LOCATIONS[P.value]) : []; fill(D, l, sd, 'Choose…'); }
    function sect() { var l = P.value && D.value && RWANDA_LOCATIONS[P.value] ? (RWANDA_LOCATIONS[P.value][D.value] || []) : []; fill(S, l, ss, 'Choose…'); }
    P.addEventListener('change', function () { sd = ss = ''; dist(); sect(); }); D.addEventListener('change', function () { ss = ''; sect(); });
    prov(); dist(); sect();
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>