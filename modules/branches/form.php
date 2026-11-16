<?php
/**
 * NEW FILE: modules/branches/form.php — branch registration / edit form (section 4.2), used by create.php and edit.php.
 * Branch Code is generated (RM-BR-0002 ...) and never typed; it cannot be changed later. The branch is registered
 * inside RISE MOTIVE Ltd: no company record is created. Every save is in the audit trail.
 */
const BR_FIELDS = ['name', 'branch_type', 'province', 'district', 'sector', 'cell', 'village', 'address', 'phone', 'email', 'manager_user_id', 'opening_date', 'status', 'description'];

/** Validates and saves. Returns [ok, message, id]. */
function br_form_save(mysqli $conn, array $in, ?array $old): array
{
    $d = [];
    foreach (BR_FIELDS as $f) { $d[$f] = trim((string) ($in[$f] ?? '')); }
    if ($d['name'] === '') { return [false, 'Branch Name is required.', 0]; }
    if (mb_strlen($d['name']) > 160) { return [false, 'Branch Name is too long.', 0]; }
    if (!in_array($d['branch_type'], BR_TYPES, true)) { return [false, 'Choose the branch type.', 0]; }
    if ($d['province'] === '' || $d['district'] === '' || $d['sector'] === '') { return [false, 'Choose the province, district and sector of the branch.', 0]; }
    if ($d['address'] === '') { return [false, 'Enter the physical address.', 0]; }
    if ($d['phone'] === '' || !preg_match('/^[0-9 +()-]{9,20}$/', $d['phone'])) { return [false, 'Enter a valid telephone number.', 0]; }
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) { return [false, 'The branch email is not valid.', 0]; }
    $mgr = (int) $d['manager_user_id'];
    $u = $mgr ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, is_active FROM users WHERE id = ' . $mgr)) : null;
    if (!$u || !(int) $u['is_active']) { return [false, 'Choose the Branch Manager from the existing (active) employees.', 0]; }
    $dt = DateTime::createFromFormat('Y-m-d', $d['opening_date']);
    if (!$dt || $dt->format('Y-m-d') !== $d['opening_date']) { return [false, 'Enter the opening date.', 0]; }
    if (!$old) { $d['status'] = in_array($d['status'], BR_STATUSES, true) ? $d['status'] : 'Active'; } else { $d['status'] = $old['status']; } // status changes have their own action
    $dup = mysqli_prepare($conn, 'SELECT COUNT(*) FROM branches WHERE name = ? AND id <> ?');
    $oid = (int) ($old['id'] ?? 0);
    mysqli_stmt_bind_param($dup, 'si', $d['name'], $oid);
    mysqli_stmt_execute($dup);
    if ((int) mysqli_fetch_row(mysqli_stmt_get_result($dup))[0]) { return [false, 'Another branch already has this name.', 0]; }
    if ($old && (int) $old['is_main'] === 1 && $d['branch_type'] !== 'Head Office') { return [false, 'The Main Branch stays the Head Office.', 0]; }
    foreach (['cell', 'village', 'email', 'description'] as $f) { if ($d[$f] === '') { $d[$f] = null; } }
    $actor = (int) current_user_id();

    mysqli_begin_transaction($conn);
    try {
        if ($old) {
            $s = mysqli_prepare($conn, 'UPDATE branches SET name = ?, branch_type = ?, province = ?, district = ?, sector = ?, cell = ?, village = ?, address = ?, phone = ?, email = ?,
                manager_user_id = ?, opening_date = ?, description = ?, updated_at = NOW() WHERE id = ?');
            mysqli_stmt_bind_param($s, 'ssssssssssissi', $d['name'], $d['branch_type'], $d['province'], $d['district'], $d['sector'], $d['cell'], $d['village'], $d['address'], $d['phone'], $d['email'], $mgr, $d['opening_date'], $d['description'], $oid);
            mysqli_stmt_execute($s);
            $id = $oid;
            $changes = [];
            foreach (BR_FIELDS as $f) { if ($f !== 'status' && (string) ($old[$f] ?? '') !== (string) ($f === 'manager_user_id' ? $mgr : ($d[$f] ?? ''))) { $changes[$f] = [$old[$f], $f === 'manager_user_id' ? $mgr : $d[$f]]; } }
            br_audit($conn, $id, 'updated', 'Branch details updated: ' . ($changes ? implode(', ', array_keys($changes)) : 'no change') . '.', array_map(function ($c) { return $c[0]; }, $changes), array_map(function ($c) { return $c[1]; }, $changes));
        } else {
            mysqli_query($conn, 'SELECT id FROM branches FOR UPDATE');
            $code = br_next_code($conn);
            $s = mysqli_prepare($conn, 'INSERT INTO branches (code, name, branch_type, province, district, sector, cell, village, address, phone, email, manager_user_id, opening_date, status, description, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'sssssssssssisssi', $code, $d['name'], $d['branch_type'], $d['province'], $d['district'], $d['sector'], $d['cell'], $d['village'], $d['address'], $d['phone'], $d['email'], $mgr, $d['opening_date'], $d['status'], $d['description'], $actor);
            mysqli_stmt_execute($s);
            $id = (int) mysqli_insert_id($conn);
            br_audit($conn, $id, 'registered', "Branch registered: {$d['name']} ($code), {$d['branch_type']}, {$d['sector']}, {$d['district']}, {$d['province']}. Status {$d['status']}.", null, $d + ['code' => $code]);
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('branch save: ' . $e->getMessage());
        return [false, 'The branch could not be saved. Nothing was changed.' . (current_user_role() === 'Admin' ? ' [' . $e->getMessage() . ']' : ''), 0];
    }
    // The manager needs access to the branch: an additional assignment if it is not already theirs.
    if (!in_array($id, br_assigned_ids($conn, $mgr), true) && !br_has_all_access($conn, $mgr, null)) {
        $today = date('Y-m-d');
        $i = mysqli_prepare($conn, "INSERT INTO employee_branch_assignments (user_id, branch_id, kind, start_date, note, created_by) VALUES (?, ?, 'additional', ?, 'Branch Manager', ?)");
        mysqli_stmt_bind_param($i, 'iisi', $mgr, $id, $today, $actor);
        mysqli_stmt_execute($i);
    }
    return [true, $old ? 'Branch details saved.' : 'Branch registered with code ' . br_get_fresh_code($conn, $id) . '.', $id];
}

function br_get_fresh_code(mysqli $conn, int $id): string
{
    return (string) (mysqli_fetch_row(mysqli_query($conn, 'SELECT code FROM branches WHERE id = ' . $id))[0] ?? '');
}

function br_form_render(mysqli $conn, array $v, ?array $old, array $users): void
{
    $cells = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT DISTINCT cell FROM branches WHERE cell IS NOT NULL AND cell <> '' ORDER BY cell"), MYSQLI_ASSOC), 'cell');
    $villages = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT DISTINCT village FROM branches WHERE village IS NOT NULL AND village <> '' ORDER BY village"), MYSQLI_ASSOC), 'village');
    $val = function ($k) use ($v) { return br_e($v[$k] ?? ''); };
    ?>
<form method="POST" class="card border-0 shadow-sm"><div class="card-body"><?= br_csrf_field(); ?>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label small fw-semibold">Branch Name *</label><input name="name" class="form-control" maxlength="160" required value="<?= $val('name'); ?>" placeholder="e.g. RISE MOTIVE – Kayonza Branch"></div>
        <div class="col-md-3"><label class="form-label small fw-semibold">Branch Code</label><input class="form-control" value="<?= br_e($old['code'] ?? br_next_code($conn)); ?>" readonly><div class="form-text"><?= $old ? 'Permanent.' : 'Generated automatically.'; ?></div></div>
        <div class="col-md-3"><label class="form-label small fw-semibold">Branch Type *</label><select name="branch_type" class="form-select" required>
            <?php foreach (BR_TYPES as $t) { if ($old && (int) $old['is_main'] !== 1 && $t === 'Head Office') { continue; } ?><option <?= ($v['branch_type'] ?? 'Branch') === $t ? 'selected' : ''; ?>><?= br_e($t); ?></option><?php } ?></select></div>

        <div class="col-md-4"><label class="form-label small fw-semibold">Province *</label><select name="province" id="brProv" class="form-select" required></select></div>
        <div class="col-md-4"><label class="form-label small fw-semibold">District *</label><select name="district" id="brDist" class="form-select" required></select></div>
        <div class="col-md-4"><label class="form-label small fw-semibold">Sector *</label><select name="sector" id="brSect" class="form-select" required></select></div>
        <div class="col-md-4"><label class="form-label small fw-semibold">Cell</label><input name="cell" class="form-control" list="brCells" maxlength="80" value="<?= $val('cell'); ?>"><datalist id="brCells"><?php foreach ($cells as $c) { ?><option value="<?= br_e($c); ?>"><?php } ?></datalist></div>
        <div class="col-md-4"><label class="form-label small fw-semibold">Village</label><input name="village" class="form-control" list="brVillages" maxlength="80" value="<?= $val('village'); ?>"><datalist id="brVillages"><?php foreach ($villages as $c) { ?><option value="<?= br_e($c); ?>"><?php } ?></datalist></div>
        <div class="col-md-4"><label class="form-label small fw-semibold">Physical Address *</label><input name="address" class="form-control" maxlength="255" required value="<?= $val('address'); ?>" placeholder="Street, building, landmark"></div>

        <div class="col-md-4"><label class="form-label small fw-semibold">Telephone *</label><input name="phone" class="form-control" maxlength="20" required value="<?= $val('phone'); ?>"></div>
        <div class="col-md-4"><label class="form-label small fw-semibold">Branch Email</label><input type="email" name="email" class="form-control" maxlength="150" value="<?= $val('email'); ?>"></div>
        <div class="col-md-4"><label class="form-label small fw-semibold">Branch Manager *</label><select name="manager_user_id" class="form-select" required><option value="">Choose an employee</option>
            <?php foreach ($users as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= (int) ($v['manager_user_id'] ?? 0) === (int) $u['id'] ? 'selected' : ''; ?>><?= br_e($u['names'] . ' · ' . $u['role'] . ($u['department'] ? ' · ' . $u['department'] : '')); ?></option><?php } ?></select></div>

        <div class="col-md-4"><label class="form-label small fw-semibold">Opening Date *</label><input type="date" name="opening_date" class="form-control" required value="<?= $val('opening_date'); ?>"></div>
        <div class="col-md-4"><label class="form-label small fw-semibold">Status</label>
            <?php if ($old) { ?><div class="pt-1"><?= br_badge($old['status']); ?> <a href="view.php?id=<?= (int) $old['id']; ?>#status" class="small ms-2">Change status</a></div>
            <?php } else { ?><select name="status" class="form-select"><?php foreach (BR_STATUSES as $s) { ?><option value="<?= $s; ?>" <?= ($v['status'] ?? 'Active') === $s ? 'selected' : ''; ?>><?= $s === 'Suspended' ? 'Temporarily Suspended' : $s; ?></option><?php } ?></select><?php } ?></div>
        <div class="col-12"><label class="form-label small fw-semibold">Description</label><textarea name="description" class="form-control" rows="2"><?= $val('description'); ?></textarea></div>
    </div>
    <div class="mt-3 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= $old ? 'Save changes' : 'Register branch'; ?></button><a href="<?= $old ? 'view.php?id=' . (int) $old['id'] : 'branches.php'; ?>" class="btn btn-link">Cancel</a></div>
    <div class="small text-muted mt-2">The branch belongs to RISE MOTIVE Ltd. Company identity (legal name, TIN) is not changed here.</div>
</div></form>
<script src="../../includes/rwanda_locations.js"></script>
<script>
(function () {
    var P = document.getElementById('brProv'), D = document.getElementById('brDist'), S = document.getElementById('brSect');
    var sel = <?= json_encode(['p' => $v['province'] ?? '', 'd' => $v['district'] ?? '', 's' => $v['sector'] ?? ''], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    function fill(el, opts, val, ph) { el.innerHTML = '<option value="">' + ph + '</option>'; opts.forEach(function (o) { var x = document.createElement('option'); x.value = o; x.textContent = o; if (o === val) { x.selected = true; } el.appendChild(x); }); }
    function prov() { fill(P, Object.keys(RWANDA_LOCATIONS), sel.p, 'Choose province'); }
    function dist() { fill(D, P.value && RWANDA_LOCATIONS[P.value] ? Object.keys(RWANDA_LOCATIONS[P.value]) : [], sel.d, 'Choose district'); }
    function sect() { fill(S, P.value && D.value && RWANDA_LOCATIONS[P.value][D.value] ? RWANDA_LOCATIONS[P.value][D.value] : [], sel.s, 'Choose sector'); }
    prov(); dist(); sect();
    P.addEventListener('change', function () { sel.d = ''; sel.s = ''; dist(); sect(); });
    D.addEventListener('change', function () { sel.s = ''; sect(); });
})();
</script>
    <?php
}