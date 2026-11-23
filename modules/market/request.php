<?php
/**
 * NEW FILE: modules/market/request.php — RECORD A CUSTOMER REQUEST in under a minute (product or service).
 * While typing, MY MOTIVE shows the matching RM Offerings items (with their stock) and the products / services already
 * requested: choosing one adds this request to the existing record (no duplicates). Recording demand never creates a
 * stock item. Date, employee and branch are filled automatically.
 */
require __DIR__ . '/common.php';
mi_require($conn, 'request');
$kind = ($_REQUEST['kind'] ?? '') === 'service' ? 'service' : 'product';
$uid = (int) current_user_id();
$warn = null; $similar = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mi_csrf_ok()) { mi_go('request.php?kind=' . $kind, 'error', 'The page had expired. Please try again.'); }
    try {
        $target = (string) ($_POST['target'] ?? ''); $name = trim((string) ($_POST['name'] ?? ''));
        $oppId = 0;
        if (preg_match('/^opp:(\d+)$/', $target, $m)) {
            $o = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, kind, merged_into FROM mi_opportunities WHERE id = ' . (int) $m[1]));
            if (!$o || $o['kind'] !== $kind) { throw new RuntimeException('Choose the product again.'); }
            $oppId = (int) ($o['merged_into'] ?: $o['id']);
        } elseif (preg_match('/^prod:(\d+)$/', $target, $m)) {
            $p = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, item_type FROM products WHERE id = ' . (int) $m[1]));
            if (!$p || ($p['item_type'] === 'Service') !== ($kind === 'service')) { throw new RuntimeException('Choose the item again.'); }
            $oppId = mi_opportunity_for_product($conn, (int) $p['id'], $kind);
        } else {
            if (mb_strlen($name) < 2) { throw new RuntimeException('Write what the customer asked for.'); }
            if (empty($_POST['confirm_new'])) {
                $l = mi_lookup($conn, $name, $kind);
                $similar = array_merge(array_map(function ($p) { return ['t' => 'prod:' . $p['id'], 'name' => $p['product_name'], 'what' => 'RM Offerings item', 'score' => $p['score']]; }, array_filter($l['catalogue'], function ($p) { return $p['score'] >= 70; })),
                                       array_map(function ($o) { return ['t' => 'opp:' . $o['id'], 'name' => $o['name'], 'what' => $o['requests'] . ' request(s) already', 'score' => $o['score']]; }, array_filter($l['opportunities'], function ($o) { return $o['score'] >= 70; })));
                if ($similar) { throw new DomainException('similar'); }
            }
            $cat = trim((string) ($_POST['category'] ?? ''));
            if ($cat !== '' && !in_array($cat, mi_categories($conn, $kind), true)) { $cat = ''; }
            $oppId = mi_create_opportunity($conn, ['kind' => $kind, 'name' => $name, 'category' => $cat, 'unit' => trim((string) ($_POST['unit'] ?? '')), 'origin' => 'request',
                'description' => trim((string) ($_POST['description'] ?? ''))]);
        }
        $branch = null;
        if (br_enabled($conn)) { $branch = br_current($conn) ?: br_primary_id($conn, $uid); }
        $rid = mi_add_request($conn, $oppId, $_POST + ['branch_id' => $branch]);
        mi_go('request.php?kind=' . $kind . '&saved=' . $rid, 'success', 'Request recorded.');
    } catch (DomainException $e) {
        $warn = 'similar';
    } catch (RuntimeException $e) {
        $warn = $e->getMessage();
    }
}
$saved = null;
if (!empty($_GET['saved'])) {
    $saved = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT r.*, o.name, o.unit, o.id oid, o.product_id, o.kind, o.origin FROM mi_requests r JOIN mi_opportunities o ON o.id = r.opportunity_id WHERE r.id = ' . (int) $_GET['saved']));
    if ($saved) { $st = mi_stats($conn, [(int) $saved['oid']])[(int) $saved['oid']] ?? null; [$lvl, $why] = mi_classify($conn, $st); $prod = $saved['product_id'] ? (mi_products($conn, [(int) $saved['product_id']])[(int) $saved['product_id']] ?? null) : null; [, $sitLabel, $sitColor] = mi_situation($saved, $prod); }
}
$cats = mi_categories($conn, $kind);
$v = function ($k, $d = '') { return mi_e($_POST[$k] ?? (in_array($k, ['target', 'name'], true) ? ($_GET[$k] ?? $d) : $d)); };
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, 'request.php', 'Record a customer request');
?>
<style>
    .mi-form { max-width: 760px; } .mi-form .form-label { font-size: 12.5px; font-weight: 600; color: #1E2333; margin-bottom: 3px; }
    .mi-seg { display: inline-flex; background: #EEF1F8; border-radius: 10px; padding: 3px; } .mi-seg a { padding: 6px 16px; border-radius: 8px; font-size: 13.5px; font-weight: 600; color: #5B6275; text-decoration: none; }
    .mi-seg a.on { background: #fff; color: #1E2FE0; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
    .mi-sug { border: 1px solid #DCE2F0; border-radius: 10px; margin-top: 4px; overflow: hidden; display: none; background: #fff; }
    .mi-sug .h { font-size: 11px; font-weight: 700; color: #8A90A2; text-transform: uppercase; letter-spacing: .05em; padding: 6px 10px 2px; }
    .mi-sug button { display: block; width: 100%; text-align: left; background: none; border: 0; border-top: 1px solid #F0F2F7; padding: 7px 10px; font-size: 13.5px; }
    .mi-sug button:hover { background: #F6F8FD; } .mi-sug .m { font-size: 12px; color: #6B7280; }
    .mi-chosen { display: none; background: #EEF3FF; border: 1px solid #C9D6FF; border-radius: 10px; padding: 8px 12px; font-size: 13.5px; }
    .mi-chips input { display: none; } .mi-chips label { display: inline-block; border: 1px solid #D5DBEA; border-radius: 20px; padding: 4px 12px; font-size: 13px; margin: 0 4px 4px 0; cursor: pointer; background: #fff; }
    .mi-chips input:checked + label { background: #1E2FE0; color: #fff; border-color: #1E2FE0; }
    @media (max-width: 575.98px) { .mi-form .card-body { padding: 14px; } }
</style>
<?php if ($saved) { ?>
<div class="card border-0 shadow-sm mb-3 mi-form" style="border-left:4px solid #0FA968 !important;"><div class="card-body">
    <div class="d-flex justify-content-between flex-wrap gap-2"><div><div class="small text-muted">Recorded · <?= mi_e($saved['kind'] === 'service' ? 'service' : 'product'); ?></div><div class="fw-bold fs-5"><a href="opportunity.php?id=<?= (int) $saved['oid']; ?>" class="text-decoration-none"><?= mi_e($saved['name']); ?></a></div>
        <div class="small"><span class="badge bg-<?= $sitColor; ?> <?= in_array($sitColor, ['warning', 'light'], true) ? 'text-dark' : ''; ?>"><?= mi_e($sitLabel); ?></span> <?= mi_level_badge($lvl); ?></div></div>
        <a href="request.php?kind=<?= $kind; ?>" class="btn btn-primary btn-sm align-self-start">Record another request</a></div>
    <?php if ($st) { ?><div class="row g-2 mt-1 text-center small">
        <div class="col-4 col-md-2"><div class="fw-bold fs-5"><?= (int) $st['requests']; ?></div>requests</div>
        <div class="col-4 col-md-2"><div class="fw-bold fs-5"><?= (int) $st['customers']; ?></div>customers</div>
        <div class="col-4 col-md-2"><div class="fw-bold fs-5"><?= mi_num($st['quantity']); ?></div><?= mi_e($saved['unit'] ?: 'units'); ?></div>
        <div class="col-6 col-md-3"><div class="fw-semibold"><?= date('d M Y', strtotime($st['first'])); ?></div>first request</div>
        <div class="col-6 col-md-3"><div class="fw-semibold"><?= date('d M Y', strtotime($st['last'])); ?></div>latest request</div></div>
    <div class="small text-muted mt-2"><?= mi_e($why); ?></div><?php } ?>
</div></div>
<?php } ?>
<form method="POST" class="mi-form" id="miForm" autocomplete="off"><?= mi_csrf_field(); ?><input type="hidden" name="kind" value="<?= $kind; ?>"><input type="hidden" name="target" id="miTarget" value="<?= $v('target'); ?>">
<div class="mb-3"><div class="mi-seg"><a href="request.php?kind=product" class="<?= $kind === 'product' ? 'on' : ''; ?>"><i class="bi bi-box-seam me-1"></i>Product</a><a href="request.php?kind=service" class="<?= $kind === 'service' ? 'on' : ''; ?>"><i class="bi bi-tools me-1"></i>Service</a></div></div>
<?php if ($warn === 'similar') { ?>
<div class="alert alert-warning py-2 small"><strong>Is it one of these?</strong> Similar <?= $kind; ?>s already exist. Choose one to add this request to it, or confirm that it is really new.
    <div class="mt-2 d-flex flex-wrap gap-1"><?php foreach ($similar as $s) { ?><button type="button" class="btn btn-sm btn-light border" data-pick="<?= mi_e($s['t']); ?>" data-name="<?= mi_e($s['name']); ?>"><?= mi_e($s['name']); ?> <span class="text-muted">· <?= mi_e($s['what']); ?></span></button><?php } ?></div>
    <label class="mt-2 d-block"><input type="checkbox" name="confirm_new" value="1" class="form-check-input me-1">No — "<?= mi_e($_POST['name'] ?? ''); ?>" is a different <?= $kind; ?>, record it as new</label></div>
<?php } elseif ($warn) { ?><div class="alert alert-danger py-2 small"><?= mi_e($warn); ?></div><?php } ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <label class="form-label"><?= $kind === 'service' ? 'Which service did the customer ask for?' : 'What did the customer ask for?'; ?></label>
    <input name="name" id="miName" class="form-control form-control-lg" maxlength="160" value="<?= $v('name'); ?>" placeholder="<?= $kind === 'service' ? 'e.g. Graphic design, website, computer repair' : 'e.g. A3 paper, printer toner'; ?>" required>
    <div class="mi-sug" id="miSug"></div>
    <div class="mi-chosen mt-2" id="miChosen"></div>
    <div class="row g-2 mt-1" id="miNewFields">
        <div class="col-sm-6"><label class="form-label">Category</label><select name="category" class="form-select"><option value="">Not sure</option><?php foreach ($cats as $c) { ?><option <?= ($_POST['category'] ?? '') === $c ? 'selected' : ''; ?>><?= mi_e($c); ?></option><?php } ?></select></div>
        <div class="col-sm-6"><label class="form-label">Description <span class="text-muted fw-normal">(optional)</span></label><input name="description" class="form-control" maxlength="255" value="<?= $v('description'); ?>" placeholder="Size, type, use…"></div>
    </div>
    <div class="row g-2 mt-1">
        <div class="col-6 col-sm-3"><label class="form-label">Quantity</label><input type="number" name="quantity" min="0.01" step="any" value="<?= $v('quantity', '1'); ?>" class="form-control" required></div>
        <div class="col-6 col-sm-3"><label class="form-label">Unit</label><input name="unit" id="miUnit" class="form-control" maxlength="30" value="<?= $v('unit'); ?>" placeholder="<?= $kind === 'service' ? 'times' : 'pieces, reams…'; ?>"></div>
        <div class="col-sm-6"><label class="form-label">Price the customer would pay <span class="text-muted fw-normal">(if known, per unit)</span></label><input type="number" name="expected_price" min="0" step="any" value="<?= $v('expected_price'); ?>" class="form-control" placeholder="RWF"></div>
    </div>
</div></div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <label class="form-label">Customer</label>
    <input type="hidden" name="customer_id" id="miCust" value="<?= $v('customer_id'); ?>">
    <input class="form-control" id="miCustFind" placeholder="Search a customer (name, phone, RMC…) — or leave empty for a walk-in">
    <div class="mi-sug" id="miCustSug"></div><div class="mi-chosen mt-2" id="miCustChosen"></div>
    <div class="row g-2 mt-1" id="miWalkin"><div class="col-sm-6"><input name="walkin_name" class="form-control form-control-sm" maxlength="120" value="<?= $v('walkin_name'); ?>" placeholder="Walk-in name (optional)"></div><div class="col-sm-6"><input name="walkin_phone" class="form-control form-control-sm" maxlength="30" value="<?= $v('walkin_phone'); ?>" placeholder="Phone (optional, to call back)"></div></div>
    <label class="form-label mt-3">How did the request come?</label>
    <div class="mi-chips"><?php foreach (MI_SOURCES as $i => $s) { $id = 'src' . $i; ?><input type="radio" name="source" id="<?= $id; ?>" value="<?= $s; ?>" <?= ($_POST['source'] ?? 'Walk-in') === $s ? 'checked' : ''; ?>><label for="<?= $id; ?>"><?= $s; ?></label><?php } ?></div>
    <div class="row g-2 mt-1">
        <div class="col-sm-6"><label class="form-label">Why could we not supply it?</label><select name="reason" class="form-select"><option value="">—</option><?php foreach ($kind === 'service' ? MI_SERVICE_REASONS : MI_PRODUCT_REASONS as $r) { ?><option <?= ($_POST['reason'] ?? '') === $r ? 'selected' : ''; ?>><?= $r; ?></option><?php } ?></select></div>
        <div class="col-sm-6"><label class="form-label">Outcome</label><select name="outcome" class="form-select"><?php foreach ($kind === 'service' ? MI_SERVICE_OUTCOMES : MI_PRODUCT_OUTCOMES as $r) { ?><option <?= ($_POST['outcome'] ?? '') === $r ? 'selected' : ''; ?>><?= $r; ?></option><?php } ?></select></div>
    </div>
    <div class="mi-chips mt-2"><input type="checkbox" name="urgency" id="urg" value="Urgent" <?= ($_POST['urgency'] ?? '') === 'Urgent' ? 'checked' : ''; ?>><label for="urg"><i class="bi bi-lightning-charge"></i> Urgent</label></div>
    <details class="mt-2" <?= !empty($_POST['specification']) || !empty($_POST['notes']) ? 'open' : ''; ?>><summary class="small text-primary fw-semibold">More details (optional)</summary><div class="row g-2 mt-1">
        <?php if ($kind === 'service') { ?>
        <div class="col-sm-6"><label class="form-label">Skills required</label><input name="skills_required" class="form-control" maxlength="255" value="<?= $v('skills_required'); ?>" placeholder="e.g. Adobe Illustrator, web development"></div>
        <div class="col-sm-6"><label class="form-label">Equipment / software required</label><input name="equipment_required" class="form-control" maxlength="255" value="<?= $v('equipment_required'); ?>"></div>
        <div class="col-sm-6"><label class="form-label">Can another employee or department deliver it?</label><select name="other_employee_can" class="form-select"><option value="">—</option><?php foreach (['Yes', 'No', 'Not sure'] as $x) { ?><option <?= ($_POST['other_employee_can'] ?? '') === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
        <?php } else { ?>
        <div class="col-sm-6"><label class="form-label">Brand / specification</label><input name="specification" class="form-control" maxlength="255" value="<?= $v('specification'); ?>" placeholder="e.g. 80 g/m², Double A"></div>
        <div class="col-sm-6"><label class="form-label">Preferred quality</label><input name="quality" class="form-control" maxlength="80" value="<?= $v('quality'); ?>"></div>
        <?php } ?>
        <div class="col-6 col-sm-3"><label class="form-label">Ready to buy?</label><select name="ready_to_buy" class="form-select"><option value="">—</option><?php foreach (['Yes', 'No', 'Not sure'] as $x) { ?><option <?= ($_POST['ready_to_buy'] ?? '') === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
        <div class="col-6 col-sm-3"><label class="form-label">When?</label><input type="date" name="expected_purchase_date" class="form-control" value="<?= $v('expected_purchase_date'); ?>"></div>
        <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2" maxlength="2000"><?= $v('notes'); ?></textarea></div>
    </div></details>
</div></div>
<div class="d-flex gap-2 align-items-center mb-4"><button class="btn btn-primary px-4 text-nowrap">Save request</button><span class="small text-muted">Date, your name<?= br_enabled($conn) ? ' and branch' : ''; ?> are recorded automatically.</span></div>
</form>
<script>
(function () {
    var KIND = <?= json_encode($kind); ?>, name = document.getElementById('miName'), sug = document.getElementById('miSug'), tgt = document.getElementById('miTarget'), chosen = document.getElementById('miChosen'), nf = document.getElementById('miNewFields'), unit = document.getElementById('miUnit');
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function choose(t, label, sub, u) {
        tgt.value = t; sug.style.display = 'none'; name.value = label; name.readOnly = true; nf.style.display = 'none'; if (u && !unit.value) unit.value = u;
        chosen.style.display = 'block'; chosen.innerHTML = '<div class="d-flex justify-content-between gap-2"><div><strong>' + esc(label) + '</strong><div class="small text-muted">' + esc(sub) + '</div></div><a href="#" id="miUndo" class="small">change</a></div>';
        document.getElementById('miUndo').onclick = function (e) { e.preventDefault(); tgt.value = ''; name.readOnly = false; chosen.style.display = 'none'; nf.style.display = ''; name.focus(); };
    }
    var t = null;
    name.addEventListener('input', function () {
        clearTimeout(t); var q = name.value.trim(); if (q.length < 2) { sug.style.display = 'none'; return; }
        t = setTimeout(function () { fetch('lookup.php?kind=' + KIND + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            var h = '';
            if (d.catalogue.length) { h += '<div class="h">In RM Offerings</div>'; d.catalogue.forEach(function (p) { h += '<button type="button" data-t="prod:' + p.id + '" data-l="' + esc(p.name) + '" data-s="' + esc(p.situation + (p.stock ? ' · stock ' + p.stock : '')) + '" data-u="' + esc(p.unit || '') + '"><strong>' + esc(p.name) + '</strong> <span class="m">' + esc(p.code || '') + '</span><div class="m">' + esc(p.situation) + (p.stock ? ' · stock: ' + esc(p.stock) : '') + '</div></button>'; }); }
            if (d.opportunities.length) { h += '<div class="h">Already requested</div>'; d.opportunities.forEach(function (o) { h += '<button type="button" data-t="opp:' + o.id + '" data-l="' + esc(o.name) + '" data-s="' + esc(o.requests + ' request(s), ' + o.qty + ' ' + (o.unit || 'units') + ' · ' + o.status) + '" data-u="' + esc(o.unit || '') + '"><strong>' + esc(o.name) + '</strong><div class="m">' + o.requests + ' request(s) · ' + esc(o.qty) + ' ' + esc(o.unit || 'units') + ' · ' + esc(o.status) + '</div></button>'; }); }
            if (h) h += '<div class="m px-2 py-1">Not in the list? Continue typing: it will be recorded as a new ' + KIND + '.</div>';
            sug.innerHTML = h; sug.style.display = h ? 'block' : 'none';
        }); }, 220);
    });
    sug.addEventListener('click', function (e) { var b = e.target.closest('button[data-t]'); if (b) choose(b.getAttribute('data-t'), b.getAttribute('data-l'), b.getAttribute('data-s'), b.getAttribute('data-u')); });
    document.querySelectorAll('[data-pick]').forEach(function (b) { b.addEventListener('click', function () { choose(b.getAttribute('data-pick'), b.getAttribute('data-name'), 'existing record'); }); });
    // customer search
    var cf = document.getElementById('miCustFind'), cs = document.getElementById('miCustSug'), cid = document.getElementById('miCust'), cc = document.getElementById('miCustChosen'), wk = document.getElementById('miWalkin'), t2 = null;
    cf.addEventListener('input', function () { clearTimeout(t2); var q = cf.value.trim(); if (q.length < 2) { cs.style.display = 'none'; return; }
        t2 = setTimeout(function () { fetch('lookup.php?customer=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (l) {
            cs.innerHTML = l.map(function (c) { return '<button type="button" data-c="' + c.id + '" data-l="' + esc(c.name + ' · ' + c.code) + '"><strong>' + esc(c.name) + '</strong> <span class="m">' + esc(c.code + (c.phone ? ' · ' + c.phone : '')) + '</span></button>'; }).join('') || '<div class="m px-2 py-2">No customer found: record as walk-in below.</div>';
            cs.style.display = 'block'; }); }, 220); });
    cs.addEventListener('click', function (e) { var b = e.target.closest('button[data-c]'); if (!b) return; cid.value = b.getAttribute('data-c'); cs.style.display = 'none'; cf.style.display = 'none'; wk.style.display = 'none';
        cc.style.display = 'block'; cc.innerHTML = '<div class="d-flex justify-content-between"><strong>' + esc(b.getAttribute('data-l')) + '</strong><a href="#" id="miCustUndo" class="small">change</a></div>';
        document.getElementById('miCustUndo').onclick = function (ev) { ev.preventDefault(); cid.value = ''; cc.style.display = 'none'; cf.style.display = ''; wk.style.display = ''; cf.value = ''; cf.focus(); }; });
    var uc = document.getElementById('urg'); document.getElementById('miForm').addEventListener('submit', function () { if (!uc.checked) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'urgency'; h.value = 'Normal'; this.appendChild(h); } });
    if (tgt.value) { choose(tgt.value, name.value, 'chosen before'); }
    <?php if (!$saved && !$warn) { ?>name.focus();<?php } ?>
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>