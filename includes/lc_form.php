<?php
/**
 * NEW FILE: includes/lc_form.php — the course APPLICATION FORM, shared by the public website (learn/apply.php)
 * and the Customer Portal (portal/learning_apply.php). The form fields are the ones chosen for the course
 * (Learning Centre → course → Application form). $customer = signed-in portal customer: name and telephone come
 * from the customer record and cannot be changed here.
 */
require_once __DIR__ . '/lc_helpers.php';

function lc_form_render(mysqli $conn, array $course, array $old, ?array $customer, string $csrfField, string $action): void
{
    $fields = lc_form_fields($course);
    $intakes = array_filter(lc_intakes($conn, (int) $course['id'], true), function ($i) { return $i['can_apply']; });
    $mods = array_filter(lc_modules($conn, (int) $course['id']), function ($m) { return $m['status'] !== 'Retired'; });
    $docs = lc_lines($course['required_documents']);
    $v = function ($k, $d = '') use ($old) { return lc_e($old[$k] ?? $d); };
    $has = function ($f) use ($fields) { return in_array($f, $fields, true); };
    $pre = (int) ($old['intake_id'] ?? (count($intakes) === 1 ? array_key_first($intakes) : 0));
    ?>
<form method="POST" action="<?= lc_e($action); ?>" enctype="multipart/form-data" id="lcApply" data-draft="lcdraft-<?= lc_e($course['code']); ?>">
    <?= $csrfField; ?>
    <div style="position:absolute;left:-5000px;" aria-hidden="true"><label>Leave empty<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <h2 class="h6 fw-bold text-uppercase text-muted mt-1">1. Intake and branch</h2>
    <?php if (!$intakes) { ?><div class="alert alert-warning small">No intake is open for applications at the moment.</div><?php } ?>
    <div class="row g-2 mb-2"><?php foreach ($intakes as $i) { ?>
        <div class="col-md-6"><label class="border rounded p-2 d-flex gap-2 h-100 bg-white" style="cursor:pointer;"><input class="form-check-input mt-1" type="radio" name="intake_id" value="<?= (int) $i['id']; ?>" required <?= $pre === (int) $i['id'] ? 'checked' : ''; ?> data-modes="<?= lc_e($i['modes'] ?: $course['modes']); ?>" data-acc="<?= (int) $i['accommodation']; ?>">
            <span class="small"><strong><?= lc_e($i['name']); ?></strong> · <?= lc_e($i['branch_name']); ?><br>Starts <?= date('d M Y', strtotime($i['start_date'])); ?><?= $i['apply_close'] ? ' · apply by ' . date('d M Y', strtotime($i['apply_close'])) : ''; ?>
            <?= $i['seats_left'] !== null ? '<br><span class="text-muted">' . (int) $i['seats_left'] . ' place(s) left</span>' : ''; ?>
            <?php $f = lc_intake_fees($course, $i); if ($course['fees_public'] && (float) $f['application'] > 0) { ?><br>Application fee: <?= lc_money($f['application']); ?><?php } ?></span></label></div>
    <?php } ?></div>
    <?php $allModes = array_filter(explode(',', (string) $course['modes'])); if (count($allModes) > 1) { ?>
    <div class="mb-3"><label class="form-label small fw-semibold">Training mode *</label><select name="mode" class="form-select" id="lcMode"><option value="">Choose…</option><?php foreach ($allModes as $m) { ?><option <?= ($old['mode'] ?? '') === $m ? 'selected' : ''; ?>><?= lc_e($m); ?></option><?php } ?></select></div>
    <?php } ?>
    <?php if ($has('modules') && $mods) { ?><div class="mb-3"><label class="form-label small fw-semibold">Modules you are interested in</label>
        <?php foreach ($mods as $m) { ?><div class="form-check"><input class="form-check-input" type="checkbox" name="modules[]" value="<?= (int) $m['id']; ?>" id="m<?= (int) $m['id']; ?>" <?= in_array((string) $m['id'], array_map('strval', (array) ($old['modules'] ?? [])), true) || (!$old && !$m['optional']) ? 'checked' : ''; ?>>
            <label class="form-check-label small" for="m<?= (int) $m['id']; ?>"><?= lc_e($m['name']); ?><?= $m['optional'] ? ' (optional)' : ''; ?><?= $course['fees_public'] && !$m['in_bundle'] && (float) $m['fee'] > 0 ? ' · ' . lc_money($m['fee']) : ''; ?></label></div><?php } ?></div><?php } ?>

    <h2 class="h6 fw-bold text-uppercase text-muted mt-3">2. About you</h2>
    <div class="row g-2">
        <?php if ($customer) { ?>
        <div class="col-md-6"><label class="form-label small fw-semibold">Full name</label><input class="form-control" value="<?= lc_e($customer['name']); ?>" readonly></div>
        <div class="col-md-6"><label class="form-label small fw-semibold">Telephone</label><input class="form-control" value="<?= lc_e($customer['phone']); ?>" readonly><div class="form-text">From your customer account.</div></div>
        <?php } else { ?>
        <div class="col-md-6"><label class="form-label small fw-semibold">Full name *</label><input name="full_name" class="form-control" required maxlength="150" value="<?= $v('full_name'); ?>" autocomplete="name"></div>
        <div class="col-md-6"><label class="form-label small fw-semibold">Telephone *</label><input name="phone" class="form-control" required maxlength="30" value="<?= $v('phone'); ?>" inputmode="tel" autocomplete="tel" placeholder="078 123 4567"><div class="form-text">Used to track your application.</div></div>
        <?php } ?>
        <?php if ($has('email')) { ?><div class="col-md-6"><label class="form-label small fw-semibold">Email</label><input type="email" name="email" class="form-control" maxlength="150" value="<?= $v('email', $customer['email'] ?? ''); ?>" autocomplete="email"></div><?php } ?>
        <?php if ($has('dob')) { ?><div class="col-md-3 col-6"><label class="form-label small fw-semibold">Date of birth *</label><input type="date" name="dob" class="form-control" required max="<?= date('Y-m-d', strtotime('-10 years')); ?>" value="<?= $v('dob'); ?>"></div><?php } ?>
        <?php if ($has('gender')) { ?><div class="col-md-3 col-6"><label class="form-label small fw-semibold">Gender</label><select name="gender" class="form-select"><option value="">—</option><?php foreach (['Female', 'Male'] as $g) { ?><option <?= ($old['gender'] ?? '') === $g ? 'selected' : ''; ?>><?= $g; ?></option><?php } ?></select></div><?php } ?>
        <?php if ($has('national_id')) { ?><div class="col-md-6"><label class="form-label small fw-semibold">National ID / passport number</label><input name="national_id" class="form-control" maxlength="30" value="<?= $v('national_id'); ?>"></div><?php } ?>
        <?php if ($has('address')) { ?>
        <div class="col-md-4"><label class="form-label small fw-semibold">Province</label><input name="province" class="form-control" maxlength="60" value="<?= $v('province', $customer['province'] ?? ''); ?>"></div>
        <div class="col-md-4"><label class="form-label small fw-semibold">District</label><input name="district" class="form-control" maxlength="60" value="<?= $v('district', $customer['district'] ?? ''); ?>"></div>
        <div class="col-md-4"><label class="form-label small fw-semibold">Sector</label><input name="sector" class="form-control" maxlength="60" value="<?= $v('sector', $customer['sector'] ?? ''); ?>"></div>
        <div class="col-12"><label class="form-label small fw-semibold">Address</label><input name="address" class="form-control" maxlength="200" value="<?= $v('address', $customer['address'] ?? ''); ?>"></div>
        <?php } ?>
        <?php if ($has('education')) { ?><div class="col-md-6"><label class="form-label small fw-semibold">Educational background</label><textarea name="education" class="form-control" rows="2" maxlength="2000" placeholder="Highest level, school, year"><?= $v('education'); ?></textarea></div><?php } ?>
        <?php if ($has('employment')) { ?><div class="col-md-6"><label class="form-label small fw-semibold">Current occupation</label><textarea name="employment" class="form-control" rows="2" maxlength="200"><?= $v('employment'); ?></textarea></div><?php } ?>
        <?php if ($has('motivation')) { ?><div class="col-12"><label class="form-label small fw-semibold">Why do you want to take this course?</label><textarea name="motivation" class="form-control" rows="3" maxlength="2000"><?= $v('motivation'); ?></textarea></div><?php } ?>
        <?php if ($has('accommodation')) { ?><div class="col-12" id="lcAccWrap"><div class="form-check"><input class="form-check-input" type="checkbox" name="accommodation" value="1" id="lcAcc" <?= !empty($old['accommodation']) ? 'checked' : ''; ?>><label class="form-check-label small" for="lcAcc">I am interested in accommodation (where available for the intake)</label></div></div><?php } ?>
        <?php foreach (lc_lines($course['custom_questions']) as $qi => $qq) { $req = substr($qq, -1) === '*'; ?>
        <div class="col-12"><label class="form-label small fw-semibold"><?= lc_e(rtrim($qq, '*')); ?><?= $req ? ' *' : ''; ?></label><textarea name="q[<?= $qi; ?>]" class="form-control" rows="2" maxlength="1000" <?= $req ? 'required' : ''; ?>><?= lc_e($old['q'][$qi] ?? ''); ?></textarea></div>
        <?php } ?>
    </div>

    <?php if ($docs) { ?>
    <h2 class="h6 fw-bold text-uppercase text-muted mt-3">3. Documents</h2>
    <div class="small text-muted mb-2">PDF or photo (JPG, PNG), maximum 5 MB each. <?= $old ? '<strong>Please attach the files again.</strong>' : ''; ?></div>
    <?php foreach ($docs as $di => $d) { $opt = substr($d, -1) === '?'; ?>
    <div class="mb-2"><label class="form-label small fw-semibold"><?= lc_e(rtrim($d, '?')); ?><?= $opt ? ' (optional)' : ' *'; ?></label><input type="file" name="docs[<?= $di; ?>]" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/*" <?= $opt ? '' : 'required'; ?>></div>
    <?php } ?>
    <?php } ?>

    <div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="consent" value="1" id="lcCons" required><label class="form-check-label small" for="lcCons">I confirm the information is correct and I agree that RISE MOTIVE Ltd uses it to process my application and contact me about it.</label></div>
    <button class="btn btn-primary btn-lg mt-3" <?= $intakes ? '' : 'disabled'; ?> id="lcSubmit">Submit application</button>
</form>
<script>
(function () {
    var f = document.getElementById('lcApply'); if (!f) return;
    var key = f.dataset.draft, skip = ['csrf', 'token', 'website', 'consent'];
    // Draft kept on THIS device only (files are never kept).
    try { var d = JSON.parse(localStorage.getItem(key) || 'null'); if (d && !<?= $old ? 'true' : 'false'; ?>) { Object.keys(d).forEach(function (n) { var el = f.elements[n]; if (el && el.type !== 'file' && el.type !== 'checkbox' && el.type !== 'radio' && !el.readOnly && !el.value) { el.value = d[n]; } }); } } catch (e) {}
    f.addEventListener('input', function () { try { var o = {}; Array.prototype.forEach.call(f.elements, function (el) { if (el.name && el.type !== 'file' && el.type !== 'checkbox' && el.type !== 'radio' && el.type !== 'hidden' && skip.indexOf(el.name) < 0) { o[el.name] = el.value; } }); localStorage.setItem(key, JSON.stringify(o)); } catch (e) {} });
    f.addEventListener('submit', function () { var b = document.getElementById('lcSubmit'); setTimeout(function () { b.disabled = true; b.innerHTML = 'Sending…'; }, 10); });
    // Only the modes / accommodation of the chosen intake.
    function sync() { var r = f.querySelector('input[name=intake_id]:checked'); if (!r) return; var ms = (r.dataset.modes || '').split(','), sel = document.getElementById('lcMode');
        if (sel) { Array.prototype.forEach.call(sel.options, function (o) { if (o.value) { o.hidden = ms.indexOf(o.value) < 0; } }); if (sel.value && ms.indexOf(sel.value) < 0) sel.value = ''; sel.required = true; }
        var a = document.getElementById('lcAccWrap'); if (a) { a.style.display = r.dataset.acc === '1' ? '' : 'none'; } }
    f.addEventListener('change', function (e) { if (e.target.name === 'intake_id') sync(); }); sync();
    window.lcClearDraft = function () { try { localStorage.removeItem(key); } catch (e) {} };
})();
</script>
<?php
}

/** Values posted to the form (to show them again after an error). */
function lc_form_old(array $post): array
{
    $keep = ['intake_id', 'mode', 'modules', 'full_name', 'phone', 'email', 'dob', 'gender', 'national_id', 'province', 'district', 'sector', 'address', 'education', 'employment', 'motivation', 'accommodation', 'q'];
    return array_intersect_key($post, array_flip($keep));
}