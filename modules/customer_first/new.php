<?php
/**
 * NEW FILE: modules/customer_first/new.php — GUIDED CUSTOMER REQUEST (the 9 steps on one page).
 * 1 need · 2 stock · 3 RM Offerings · 4 other employees · 5 alternatives · 6 sourcing / scheduling · 7 escalation ·
 * 8 what was said to the customer · 9 outcome. MY MOTIVE does the checks while the employee types; the employee
 * records what was found. Saving without a final outcome keeps the case open (or escalated).
 */
require __DIR__ . '/common.php';
cf_require($conn, 'record');
$users = cf_users($conn); $depts = cf_depts($conn); $uid = (int) cf_uid();
$pre = ['customer_id' => '', 'customer_label' => '', 'request_text' => '', 'kind' => 'product', 'portal_request_id' => ''];
if (!empty($_GET['portal_request'])) { // NEW: from a Customer Portal request
    $pr = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT r.*, c.name FROM portal_requests r JOIN customers c ON c.id = r.customer_id WHERE r.id = ' . (int) $_GET['portal_request']));
    if ($pr) { $pre = ['customer_id' => (int) $pr['customer_id'], 'customer_label' => $pr['name'] . ' · RMC' . str_pad((string) $pr['customer_id'], 6, '0', STR_PAD_LEFT), 'request_text' => $pr['subject'], 'kind' => 'product', 'portal_request_id' => (int) $pr['id']]; }
}
if (!empty($_GET['customer'])) { $c = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, name FROM customers WHERE id = ' . (int) $_GET['customer'])); if ($c) { $pre['customer_id'] = (int) $c['id']; $pre['customer_label'] = $c['name']; } }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cf_csrf_ok()) { cf_go('new.php', 'error', 'The page had expired. Please try again.'); }
    $t = function ($k, $n) { $v = trim((string) ($_POST[$k] ?? '')); return $v === '' ? null : mb_substr($v, 0, $n); };
    $kind = ($_POST['kind'] ?? '') === 'service' ? 'service' : 'product';
    $text = $t('request_text', 200); $cust = (int) ($_POST['customer_id'] ?? 0) ?: null;
    $outcome = isset(CF_OUTCOMES[$_POST['outcome'] ?? '']) ? $_POST['outcome'] : null; $reason = in_array($_POST['unresolved_reason'] ?? '', CF_REASONS, true) ? $_POST['unresolved_reason'] : null;
    $esc = !empty($_POST['escalate']); $escTo = (int) ($_POST['esc_to'] ?? 0);
    try {
        if (!$text) { throw new RuntimeException('Step 1: write what the customer wants.'); }
        if (!$cust && !$t('walkin_name', 120) && !$t('contact', 60)) { throw new RuntimeException('Step 1: choose the customer, or write a name or contact for a walk-in.'); }
        if (in_array($outcome, ['Unresolved', 'Customer Declined'], true) && !$reason) { throw new RuntimeException('Step 9: give the reason the request was not fulfilled.'); }
        if ($outcome === 'Unresolved' && !$esc && empty($_POST['confirm_unresolved'])) { throw new RuntimeException('Before recording "Unresolved", check alternatives, sourcing, other employees or escalate. Tick the confirmation if every option was explored.'); }
        if ($esc && (!$escTo || !in_array($escTo, array_map('intval', array_column($users, 'id')), true) || $escTo === $uid)) { throw new RuntimeException('Step 7: choose the person to escalate to (not yourself).'); }
        if (!$outcome && !$esc) { $outcome = null; }
        $pid = (int) ($_POST['product_id'] ?? 0) ?: null; $alt = (int) ($_POST['alternative_product_id'] ?? 0) ?: null;
        $budget = is_numeric($_POST['budget'] ?? '') ? (float) $_POST['budget'] : null;
        $need = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['needed_by'] ?? '') ? $_POST['needed_by'] : null; $exp = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['expected_date'] ?? '') ? $_POST['expected_date'] : null;
        $dept = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT department_id FROM users WHERE id = $uid"))[0] ?? 0) ?: null;
        $branch = br_enabled($conn) ? (br_current($conn) ?: br_primary_id($conn, $uid)) : null;
        $final = $outcome && in_array($outcome, CF_FINAL, true) && !$esc; $status = $esc ? 'Escalated' : ($final ? 'Closed' : 'Open'); $now = date('Y-m-d H:i:s');
        $f = ['walkin_name' => $t('walkin_name', 120), 'contact' => $t('contact', 60), 'quantity' => $t('quantity', 60), 'stock_check' => $t('stock_check', 255), 'offering_check' => $t('offering_check', 255), 'capability_check' => $t('capability_check', 255),
              'alternative' => $t('alternative', 255), 'sourcing' => $t('sourcing', 255), 'communication' => $t('communication', 500), 'outcome_note' => $t('outcome_note', 500)];
        $closedAt = $final ? $now : null; $closedBy = $final ? $uid : null; $portal = (int) ($_POST['portal_request_id'] ?? 0) ?: null;
        $s = mysqli_prepare($conn, 'INSERT INTO cf_cases (customer_id, walkin_name, contact, kind, request_text, product_id, quantity, budget, needed_by, handled_by, department_id, branch_id, stock_check, offering_check, capability_check, alternative, alternative_product_id,
            sourcing, expected_date, communication, status, outcome, unresolved_reason, outcome_note, portal_request_id, closed_at, closed_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'issssisdsiiissssisssssssisis', $cust, $f['walkin_name'], $f['contact'], $kind, $text, $pid, $f['quantity'], $budget, $need, $uid, $dept, $branch, $f['stock_check'], $f['offering_check'], $f['capability_check'], $f['alternative'], $alt,
            $f['sourcing'], $exp, $f['communication'], $status, $outcome, $reason, $f['outcome_note'], $portal, $closedAt, $closedBy, $now);
        mysqli_stmt_execute($s); $id = (int) mysqli_insert_id($conn);
        cf_log($conn, $id, 'created', 'Request recorded' . ($outcome ? ' — outcome: ' . $outcome . ($reason ? ' (' . $reason . ')' : '') : ''));
        if ($esc) { cf_escalate($conn, $id, $escTo, (int) ($_POST['esc_dept'] ?? 0) ?: null, in_array($_POST['esc_reason'] ?? '', CF_ESC_REASONS, true) ? $_POST['esc_reason'] : 'Other',
            trim(implode(' | ', array_filter([$f['stock_check'], $f['offering_check'], $f['capability_check'], $f['alternative'] ? 'Alternative: ' . $f['alternative'] : null, $f['sourcing'] ? 'Sourcing: ' . $f['sourcing'] : null, $t('esc_note', 500)]))),
            (string) ($_POST['esc_priority'] ?? 'Normal'), preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['esc_due'] ?? '') ? $_POST['esc_due'] . ' 17:00:00' : null); }
        if (!empty($_POST['market'])) { try { cf_link_market($conn, mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM cf_cases WHERE id = $id"))); } catch (Throwable $e) { error_log('cf market: ' . $e->getMessage()); } }
        cf_go('case.php?id=' . $id, 'success', cf_case_no($id) . ' recorded' . ($esc ? ' and escalated' : '') . '.');
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
}
$P = function ($k, $d = '') { return cf_e($_POST[$k] ?? $d); };
$kindSel = ($_POST['kind'] ?? $_GET['kind'] ?? $pre['kind']) === 'service' ? 'service' : 'product';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
cf_tabs($conn, 'new.php', 'New customer request');
?>
<style>.cf-step{position:relative;padding-left:38px}.cf-step .n{position:absolute;left:0;top:2px;width:26px;height:26px;border-radius:50%;background:#1E2FE0;color:#fff;font-size:12.5px;font-weight:700;display:flex;align-items:center;justify-content:center}
.cf-step .t{font-weight:600;font-size:14px;color:#1E2333}.cf-res button{display:block;width:100%;text-align:left;background:#fff;border:1px solid #E1E5EF;border-radius:8px;padding:6px 10px;margin-top:4px;font-size:13px}.cf-res button:hover{background:#F4F6FC}.cf-res .ok{color:#0A7A4B}.cf-res .no{color:#B42318}</style>
<?php if (!empty($err)) { ?><div class="alert alert-danger py-2 small"><?= cf_e($err); ?></div><?php } ?>
<form method="POST" id="cfForm" style="max-width:860px" autocomplete="off"><?= cf_csrf_field(); ?>
<input type="hidden" name="portal_request_id" value="<?= $P('portal_request_id', (string) $pre['portal_request_id']); ?>">
<input type="hidden" name="product_id" id="cfPid" value="<?= $P('product_id'); ?>"><input type="hidden" name="alternative_product_id" id="cfAlt" value="<?= $P('alternative_product_id'); ?>">
<div class="card border-0 shadow-sm mb-2"><div class="card-body small">
    <div class="cf-step"><span class="n">1</span><div class="t">Understand the customer's need</div>
        <div class="row g-2 mt-1">
            <div class="col-md-7"><input type="hidden" name="customer_id" id="cfCust" value="<?= $P('customer_id', (string) $pre['customer_id']); ?>"><input id="cfCustFind" class="form-control form-control-sm" placeholder="Customer: name, phone, RMC… (empty = walk-in)" value="<?= cf_e($pre['customer_label']); ?>"><div class="cf-res" id="cfCustRes"></div></div>
            <div class="col-6 col-md-3"><input name="walkin_name" class="form-control form-control-sm" maxlength="120" placeholder="Walk-in name" value="<?= $P('walkin_name'); ?>"></div>
            <div class="col-6 col-md-2"><input name="contact" class="form-control form-control-sm" maxlength="60" placeholder="Phone / email" value="<?= $P('contact'); ?>"></div>
            <div class="col-md-3"><select name="kind" id="cfKind" class="form-select form-select-sm"><option value="product">A product</option><option value="service" <?= $kindSel === 'service' ? 'selected' : ''; ?>>A service</option></select></div>
            <div class="col-md-9"><input name="request_text" id="cfText" class="form-control form-control-sm" maxlength="200" required placeholder="What does the customer want?" value="<?= $P('request_text', (string) $pre['request_text']); ?>"></div>
            <div class="col-12 col-sm-4"><input name="quantity" class="form-control form-control-sm" maxlength="60" placeholder="Quantity / scope" value="<?= $P('quantity'); ?>"></div>
            <div class="col-6 col-sm-4"><input type="number" name="budget" min="0" step="any" class="form-control form-control-sm" placeholder="Budget (RWF)" value="<?= $P('budget'); ?>"></div>
            <div class="col-6 col-sm-4"><input type="date" name="needed_by" class="form-control form-control-sm" title="Needed by" value="<?= $P('needed_by'); ?>"></div>
        </div></div>
</div></div>
<div class="card border-0 shadow-sm mb-2"><div class="card-body small">
    <div class="cf-step"><span class="n">2</span><div class="t">Check stock &amp; <span class="n" style="position:static;display:inline-flex;width:20px;height:20px;font-size:11px">3</span> RM Offerings</div>
        <div id="cfMatches" class="cf-res text-muted">Type the request in step 1: MY MOTIVE searches the stock and the services.</div>
        <div class="row g-2 mt-1"><div class="col-md-6"><input name="stock_check" id="cfStock" class="form-control form-control-sm" maxlength="255" placeholder="Stock check result" value="<?= $P('stock_check'); ?>"></div><div class="col-md-6"><input name="offering_check" id="cfOffer" class="form-control form-control-sm" maxlength="255" placeholder="RM Offerings check (requirements, price guidance)" value="<?= $P('offering_check'); ?>"></div></div>
        <div id="cfSale" class="mt-1" style="display:none"><a href="../sales/create.php" target="_blank" class="btn btn-sm btn-success py-0"><i class="bi bi-cart-check me-1"></i>Available — open a sale</a> <span class="text-muted">then record the outcome "Fulfilled".</span></div></div>
</div></div>
<div class="card border-0 shadow-sm mb-2"><div class="card-body small">
    <div class="cf-step"><span class="n">4</span><div class="t">Other employees who can deliver it</div>
        <div id="cfEmp" class="cf-res text-muted">For services, MY MOTIVE lists colleagues who have the skill (Employee skills) and whether they are available.</div>
        <input name="capability_check" id="cfCap" class="form-control form-control-sm mt-1" maxlength="255" placeholder="Capability check result (who can do it, when)" value="<?= $P('capability_check'); ?>"></div>
</div></div>
<div class="card border-0 shadow-sm mb-2"><div class="card-body small">
    <div class="cf-step"><span class="n">5</span><div class="t">Suitable alternative <span class="fw-normal text-muted">— only when it really serves the customer</span></div>
        <div id="cfAlts" class="cf-res text-muted"></div>
        <input name="alternative" id="cfAltText" class="form-control form-control-sm mt-1" maxlength="255" placeholder="Alternative offered (if any)" value="<?= $P('alternative'); ?>"></div>
    <div class="cf-step mt-3"><span class="n">6</span><div class="t">Sourcing or scheduling</div>
        <div class="row g-2 mt-1"><div class="col-md-8"><input name="sourcing" class="form-control form-control-sm" maxlength="255" placeholder="Can it be sourced / restocked, or the service scheduled? How?" value="<?= $P('sourcing'); ?>"></div><div class="col-md-4"><input type="date" name="expected_date" class="form-control form-control-sm" title="Expected date" value="<?= $P('expected_date'); ?>"></div></div></div>
</div></div>
<div class="card border-0 shadow-sm mb-2"><div class="card-body small">
    <div class="cf-step"><span class="n">7</span><div class="t">Escalate if you cannot resolve it</div>
        <label class="mt-1"><input type="checkbox" name="escalate" value="1" id="cfEsc" class="form-check-input me-1" <?= !empty($_POST['escalate']) ? 'checked' : ''; ?>>Send to a supervisor / department</label>
        <div class="row g-2 mt-1" id="cfEscBox" style="<?= !empty($_POST['escalate']) ? '' : 'display:none'; ?>">
            <div class="col-md-4"><select name="esc_to" class="form-select form-select-sm"><option value="">To…</option><?php foreach ($users as $u) { if ((int) $u['id'] === $uid) { continue; } ?><option value="<?= (int) $u['id']; ?>" <?= (int) ($_POST['esc_to'] ?? 0) === (int) $u['id'] ? 'selected' : ''; ?>><?= cf_e($u['names']); ?> (<?= cf_e($u['role']); ?>)</option><?php } ?></select></div>
            <div class="col-md-4"><select name="esc_dept" class="form-select form-select-sm"><option value="">Department…</option><?php foreach ($depts as $k => $d) { ?><option value="<?= (int) $k; ?>"><?= cf_e($d); ?></option><?php } ?></select></div>
            <div class="col-md-4"><select name="esc_reason" class="form-select form-select-sm"><?php foreach (CF_ESC_REASONS as $r) { ?><option><?= $r; ?></option><?php } ?></select></div>
            <div class="col-6 col-md-3"><select name="esc_priority" class="form-select form-select-sm"><?php foreach (CF_PRIORITY as $k => $h) { ?><option <?= $k === 'Normal' ? 'selected' : ''; ?>><?= $k; ?></option><?php } ?></select></div>
            <div class="col-6 col-md-3"><input type="date" name="esc_due" class="form-control form-control-sm" title="Answer needed by (default from the priority)"></div>
            <div class="col-md-6"><input name="esc_note" class="form-control form-control-sm" maxlength="500" placeholder="Anything else the supervisor should know"></div></div></div>
</div></div>
<div class="card border-0 shadow-sm mb-2"><div class="card-body small">
    <div class="cf-step"><span class="n">8</span><div class="t">What you told the customer</div><input name="communication" class="form-control form-control-sm mt-1" maxlength="500" placeholder="Options, prices, requirements, limitations, timelines explained" value="<?= $P('communication'); ?>"></div>
    <div class="cf-step mt-3"><span class="n">9</span><div class="t">Outcome</div>
        <div class="row g-2 mt-1"><div class="col-md-5"><select name="outcome" id="cfOut" class="form-select form-select-sm"><option value="">Not decided yet (keep open)</option><?php foreach (CF_OUTCOMES as $k => [$l]) { ?><option value="<?= cf_e($k); ?>" title="<?= cf_e($l); ?>" <?= ($_POST['outcome'] ?? '') === $k ? 'selected' : ''; ?>><?= cf_e($k); ?></option><?php } ?></select></div>
            <div class="col-md-7" id="cfReason" style="display:none"><select name="unresolved_reason" class="form-select form-select-sm"><option value="">Reason it was not fulfilled…</option><?php foreach (CF_REASONS as $r) { ?><option <?= ($_POST['unresolved_reason'] ?? '') === $r ? 'selected' : ''; ?>><?= $r; ?></option><?php } ?></select></div>
            <div class="col-12"><input name="outcome_note" class="form-control form-control-sm" maxlength="500" placeholder="Note on the outcome" value="<?= $P('outcome_note'); ?>"></div>
            <div class="col-12" id="cfConfirm" style="display:none"><label class="text-danger"><input type="checkbox" name="confirm_unresolved" value="1" class="form-check-input me-1">I checked stock, services, other employees, alternatives and sourcing: there is no acceptable solution.</label></div>
            <div class="col-12"><label><input type="checkbox" name="market" value="1" id="cfMarket" class="form-check-input me-1" <?= !empty($_POST['market']) ? 'checked' : ''; ?>>Record as unmet demand in RM Market Insights <span class="text-muted">(one request, no duplicate)</span></label></div></div></div>
</div></div>
<button class="btn btn-primary px-4 mb-4">Save the request</button>
</form>
<script>
(function () {
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    var text = document.getElementById('cfText'), kind = document.getElementById('cfKind'), t = null;
    function explore() { var q = text.value.trim(); if (q.length < 2) return;
        fetch('explore.php?kind=' + kind.value + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            var m = document.getElementById('cfMatches'); m.classList.remove('text-muted');
            m.innerHTML = d.matches.length ? d.matches.map(function (p) { return '<button type="button" data-p="' + p.id + '" data-s="' + esc(p.name + ': ' + p.status) + '" data-ok="' + (p.in_stock ? 1 : 0) + '"><strong>' + esc(p.name) + '</strong> <span class="' + (p.in_stock ? 'ok' : 'no') + '">' + esc(p.status) + '</span></button>'; }).join('') : '<span class="no">Not found in ' + (kind.value === 'service' ? 'the services (RM Offerings)' : 'stock') + '.</span>';
            if (!d.matches.length) { var f = kind.value === 'service' ? document.getElementById('cfOffer') : document.getElementById('cfStock'); if (!f.value) f.value = (kind.value === 'service' ? 'Service not offered' : 'Not in our catalogue') + ' ("' + q + '")'; }
            var a = document.getElementById('cfAlts'); a.innerHTML = d.alternatives.length ? '<div>In stock in the same category:</div>' + d.alternatives.map(function (p) { return '<button type="button" data-a="' + p.id + '" data-n="' + esc(p.name) + '">' + esc(p.name) + ' <span class="ok">' + esc(p.status) + '</span></button>'; }).join('') : '';
            var e = document.getElementById('cfEmp'); if (kind.value === 'service') { e.classList.remove('text-muted'); e.innerHTML = d.employees.length ? d.employees.map(function (x) { return '<button type="button" data-e="' + esc(x.names + ' (' + x.level + ')' + (x.available ? ' — available' : (x.on_leave ? ' — on leave' : ' — busy'))) + '">' + esc(x.names) + ' · ' + esc(x.level) + ' <span class="' + (x.available ? 'ok">available' : 'no">' + (x.on_leave ? 'on leave' : 'busy: ' + x.open_tasks + ' tasks')) + '</span></button>'; }).join('') : '<span class="no">Nobody recorded with this skill (Employee skills).</span>'; }
        }); }
    text.addEventListener('input', function () { clearTimeout(t); t = setTimeout(explore, 300); }); kind.addEventListener('change', explore);
    document.getElementById('cfMatches').addEventListener('click', function (ev) { var b = ev.target.closest('[data-p]'); if (!b) return; document.getElementById('cfPid').value = b.getAttribute('data-p');
        (kind.value === 'service' ? document.getElementById('cfOffer') : document.getElementById('cfStock')).value = b.getAttribute('data-s'); document.getElementById('cfSale').style.display = b.getAttribute('data-ok') === '1' ? '' : 'none'; });
    document.getElementById('cfAlts').addEventListener('click', function (ev) { var b = ev.target.closest('[data-a]'); if (!b) return; document.getElementById('cfAlt').value = b.getAttribute('data-a'); document.getElementById('cfAltText').value = b.getAttribute('data-n'); });
    document.getElementById('cfEmp').addEventListener('click', function (ev) { var b = ev.target.closest('[data-e]'); if (!b) return; document.getElementById('cfCap').value = 'Can be delivered by ' + b.getAttribute('data-e'); });
    var out = document.getElementById('cfOut'); function o() { var v = out.value; document.getElementById('cfReason').style.display = (v === 'Unresolved' || v === 'Customer Declined') ? '' : 'none'; document.getElementById('cfConfirm').style.display = v === 'Unresolved' && !document.getElementById('cfEsc').checked ? '' : 'none';
        if (['Unresolved', 'Product to Be Sourced', 'Customer Declined', 'Referred'].indexOf(v) >= 0) document.getElementById('cfMarket').checked = true; }
    out.addEventListener('change', o); document.getElementById('cfEsc').addEventListener('change', function () { document.getElementById('cfEscBox').style.display = this.checked ? '' : 'none'; o(); }); o();
    var cf = document.getElementById('cfCustFind'), cr = document.getElementById('cfCustRes'), ci = document.getElementById('cfCust'), t2 = null;
    cf.addEventListener('input', function () { ci.value = ''; clearTimeout(t2); var q = cf.value.trim(); if (q.length < 2) { cr.innerHTML = ''; return; } t2 = setTimeout(function () { fetch('explore.php?customer=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (l) {
        cr.innerHTML = l.map(function (c) { return '<button type="button" data-c="' + c.id + '" data-l="' + esc(c.name + ' · ' + c.code) + '">' + esc(c.name) + ' <span class="text-muted">' + esc(c.code + (c.phone ? ' · ' + c.phone : '')) + '</span></button>'; }).join('') || '<span class="text-muted">No customer found: fill the walk-in fields.</span>'; }); }, 250); });
    cr.addEventListener('click', function (ev) { var b = ev.target.closest('[data-c]'); if (!b) return; ci.value = b.getAttribute('data-c'); cf.value = b.getAttribute('data-l'); cr.innerHTML = ''; });
    if (text.value) explore();
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>