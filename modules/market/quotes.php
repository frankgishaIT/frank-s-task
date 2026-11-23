<?php
/**
 * NEW FILE: modules/market/quotes.php — SUPPLIER QUOTATIONS for a product opportunity and their COMPARISON:
 * cheapest unit price, best overall cost (with delivery and other costs), shortest delivery, best payment terms, and the
 * suggested supplier with the reason. Expired or poor-quality quotations are shown but never suggested.
 * Suppliers can be chosen from RM Business Parties (type Supplier) or typed in (a potential supplier).
 */
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/business_party_helpers.php';
if (!mi_can_evaluate($conn)) { mi_require($conn, 'research'); }
$o = mi_load_opp($conn, 'quotes.php');
$id = (int) $o['id']; $self = 'quotes.php?id=' . $id;
if ($o['kind'] !== 'product') { header('Location: capability.php?id=' . $id); exit; }
$canEdit = mic($conn, 'research');
const MI_Q_DIR = __DIR__ . '/../../storage/market/quotes';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mi_csrf_ok()) { mi_go($self, 'error', 'The page had expired.'); }
    if (!$canEdit) { mi_go($self, 'error', 'You may not change quotations.'); }
    $do = (string) ($_POST['do'] ?? '');
    try {
        if ($do === 'remove') {
            $qid = (int) $_POST['quote_id'];
            mysqli_query($conn, "UPDATE mi_quotes SET is_active = 0 WHERE id = $qid AND opportunity_id = $id");
            mi_audit($conn, $id, null, 'quote_removed', 'Quotation #' . $qid . ' removed from the comparison');
            mi_go($self, 'success', 'Quotation removed from the comparison (kept in the history).');
        }
        $party = (int) ($_POST['supplier_party_id'] ?? 0) ?: null; $name = trim((string) ($_POST['supplier_name'] ?? ''));
        if ($party) { $bp = business_party_by_id($conn, $party); if (!$bp || $bp['type'] !== 'Supplier') { throw new RuntimeException('Choose the supplier again.'); } $name = $bp['business_name'] ?: $bp['name']; }
        if ($name === '') { throw new RuntimeException('Give the supplier name.'); }
        $num = function ($k, $req = false) { $v = trim((string) ($_POST[$k] ?? '')); if ($v === '') { if ($req) { throw new RuntimeException('Enter the unit purchase price.'); } return null; } if (!is_numeric($v) || (float) $v < 0) { throw new RuntimeException('Numbers only.'); } return (float) $v; };
        $price = $num('unit_price', true); if ($price <= 0) { throw new RuntimeException('The unit purchase price must be above 0.'); }
        $moq = $num('moq'); $avail = $num('available_qty'); $del = $num('delivery_cost') ?? 0.0; $oth = $num('other_costs') ?? 0.0;
        $days = $num('delivery_days'); $days = $days !== null ? (int) $days : null;
        $terms = in_array($_POST['payment_terms'] ?? '', MI_PAYMENT_TERMS, true) ? $_POST['payment_terms'] : null; $cd = $terms === 'Credit' ? (int) ($num('credit_days') ?? 0) : null;
        $quality = in_array($_POST['quality'] ?? '', ['Good', 'Standard', 'Poor'], true) ? $_POST['quality'] : null;
        $d = function ($k) { $v = (string) ($_POST[$k] ?? ''); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; };
        $qd = $d('quote_date') ?: date('Y-m-d'); $vu = $d('valid_until');
        if ($vu && $vu < $qd) { throw new RuntimeException('The validity date is before the quotation date.'); }
        $t = function ($k, $n) { $v = trim((string) ($_POST[$k] ?? '')); return $v === '' ? null : mb_substr($v, 0, $n); };
        $contact = $t('contact', 120); $loc = $t('location', 120); $notes = $t('notes', 2000);
        $att = null;
        if (!empty($_FILES['attachment']['name']) && ($_FILES['attachment']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
            if (($_FILES['attachment']['error'] ?? 1) !== UPLOAD_ERR_OK || $_FILES['attachment']['size'] > 8 * 1024 * 1024) { throw new RuntimeException('The attachment could not be uploaded (max 8 MB).'); }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['attachment']['tmp_name']);
            $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
            if (!$ext) { throw new RuntimeException('Attach a PDF or a picture.'); }
            if (!is_dir(MI_Q_DIR)) { @mkdir(MI_Q_DIR, 0775, true); @file_put_contents(dirname(MI_Q_DIR) . '/.htaccess', "Require all denied\n"); }
            $att = 'q_' . $id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['attachment']['tmp_name'], MI_Q_DIR . '/' . $att)) { throw new RuntimeException('The attachment could not be saved.'); }
        }
        $u = (int) current_user_id();
        $s = mysqli_prepare($conn, 'INSERT INTO mi_quotes (opportunity_id, supplier_party_id, supplier_name, contact, location, unit_price, moq, available_qty, delivery_cost, other_costs, delivery_days,
            payment_terms, credit_days, quality, quote_date, valid_until, notes, attachment, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'iisssdddddisisssssi', $id, $party, $name, $contact, $loc, $price, $moq, $avail, $del, $oth, $days, $terms, $cd, $quality, $qd, $vu, $notes, $att, $u);
        mysqli_stmt_execute($s);
        $qid = (int) mysqli_insert_id($conn);
        mi_audit($conn, $id, null, 'quote_added', 'Quotation from ' . $name . ': ' . mi_money($price) . ' per unit' . ($moq ? ', minimum ' . mi_num($moq) : '') . ($del ? ', delivery ' . mi_money($del) : '') . ($vu ? ', valid until ' . $vu : ''));
        if (in_array($o['status'], ['New', 'Under Review'], true)) { mysqli_query($conn, "UPDATE mi_opportunities SET status = 'Under Review' WHERE id = $id"); }
        mi_go($self, 'success', 'Quotation added.');
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
}
$e = mi_eval_get($conn, $id);
$qty = (float) ($_GET['qty'] ?? ($e['proposed_qty'] ?? 0));
if ($qty <= 0) { $st = mi_stats($conn, [$id], date('Y-m-d', strtotime('-90 days')))[$id] ?? null; $qty = max(1, ceil((float) ($st['quantity'] ?? 0) * mi_eset($conn, 'conversion_pct') / 100)); }
$cmp = mi_compare_quotes(mi_quotes($conn, $id), $qty);
$removed = mysqli_fetch_all(mysqli_query($conn, "SELECT supplier_name, unit_price, created_at FROM mi_quotes WHERE opportunity_id = $id AND is_active = 0 ORDER BY id DESC"), MYSQLI_ASSOC);
$suppliers = business_parties_of_type($conn, 'Supplier');
$v = function ($k, $d = '') { return mi_e($_POST[$k] ?? $d); };
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, 'opportunities.php', $o['name']);
mi_opp_nav($conn, $o, 'quotes.php');
$flag = function ($qid) use ($cmp) { $f = []; foreach (['suggested' => ['Suggested', 'success'], 'cheapest' => ['Lowest unit price', 'light text-dark border'], 'fastest' => ['Fastest delivery', 'light text-dark border'], 'terms' => ['Best payment terms', 'light text-dark border']] as $k => [$l, $c]) { if ($cmp[$k] === $qid) { $f[] = '<span class="badge bg-' . $c . '">' . $l . '</span>'; } } return implode(' ', $f); };
?>
<?php if (!empty($err)) { ?><div class="alert alert-danger py-2 small"><?= mi_e($err); ?></div><?php } ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0">
    <form class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-2 px-3 small"><input type="hidden" name="id" value="<?= $id; ?>"><span class="fw-semibold">Comparison for <input type="number" name="qty" value="<?= mi_e(mi_num($qty)); ?>" min="1" step="any" class="form-control form-control-sm d-inline-block" style="width:90px"> <?= mi_e($o['unit'] ?: 'units'); ?> <button class="btn btn-sm btn-light border py-0">Recalculate</button></span><span class="text-muted">Total = unit price × quantity (at least the minimum order) + delivery + other costs</span></form>
    <div class="table-responsive"><table class="table table-sm mb-0 mi-tbl">
        <tr class="table-light"><th class="ps-3">Supplier</th><th class="text-end">Unit price</th><th class="text-end">Min. order</th><th class="text-end">Delivery + other</th><th class="text-end">Total</th><th class="text-end">Cost / unit</th><th>Delivery</th><th>Payment</th><th>Valid until</th><th></th></tr>
        <?php foreach ($cmp['rows'] as $q) { ?><tr class="<?= $q['expired'] || $q['quality'] === 'Poor' ? 'text-muted' : ''; ?><?= $cmp['suggested'] === (int) $q['id'] ? ' table-success' : ''; ?>">
            <td class="ps-3"><strong><?= mi_e($q['supplier_name']); ?></strong> <?= $flag((int) $q['id']); ?><div class="small text-muted"><?= mi_e(trim($q['contact'] . ' · ' . $q['location'], ' ·')); ?><?= $q['quality'] ? ' · quality: ' . mi_e($q['quality']) : ''; ?><?= $q['short'] ? ' · <span class="text-danger">only ' . mi_num($q['available_qty']) . ' available</span>' : ''; ?></div>
                <?= $q['notes'] ? '<div class="small text-muted">' . mi_e($q['notes']) . '</div>' : ''; ?></td>
            <td class="text-end"><?= mi_money($q['unit_price']); ?></td><td class="text-end"><?= $q['moq'] ? mi_num($q['moq']) : '—'; ?></td><td class="text-end"><?= mi_money((float) $q['delivery_cost'] + (float) $q['other_costs']); ?></td>
            <td class="text-end"><?= mi_money($q['total']); ?><div class="small text-muted">for <?= mi_num($q['qty']); ?></div></td><td class="text-end fw-semibold"><?= mi_money($q['landed']); ?></td>
            <td class="small"><?= $q['delivery_days'] !== null ? (int) $q['delivery_days'] . ' day(s)' : '—'; ?></td><td class="small"><?= mi_e($q['payment_terms'] ?: '—'); ?><?= $q['credit_days'] ? ' ' . (int) $q['credit_days'] . ' d' : ''; ?></td>
            <td class="small text-nowrap"><?= $q['valid_until'] ? ($q['expired'] ? '<span class="text-danger">expired ' : '') . date('d M Y', strtotime($q['valid_until'])) . ($q['expired'] ? '</span>' : '') : '—'; ?></td>
            <td class="text-end text-nowrap"><?php if ($q['attachment']) { ?><a href="file.php?quote=<?= (int) $q['id']; ?>" target="_blank" title="Attachment"><i class="bi bi-paperclip"></i></a><?php } ?>
                <?php if ($canEdit) { ?><form method="POST" class="d-inline" onsubmit="return confirm('Remove this quotation from the comparison?')"><?= mi_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="remove"><input type="hidden" name="quote_id" value="<?= (int) $q['id']; ?>"><button class="btn btn-link btn-sm p-0 text-danger" title="Remove"><i class="bi bi-x-lg"></i></button></form><?php } ?></td></tr><?php } ?>
        <?php if (!$cmp['rows']) { ?><tr><td colspan="10" class="text-center text-muted py-4">No supplier quotation yet. Without one, purchase costs are only estimates.</td></tr><?php } ?>
    </table></div>
    <?php if ($cmp['why']) { ?><div class="p-2 px-3 small border-top"><i class="bi bi-lightbulb text-warning me-1"></i><?= mi_e($cmp['why']); ?> <span class="text-muted">The cheapest price is not always the best choice: check quality and reliability too.</span></div><?php } ?>
</div></div>
<?php if ($canEdit) { ?>
<details class="card border-0 shadow-sm mb-3" <?= !empty($err) || !$cmp['rows'] ? 'open' : ''; ?>><summary class="card-body py-2 fw-semibold text-primary">Add a supplier quotation</summary>
<form method="POST" enctype="multipart/form-data" class="card-body pt-0 row g-2 small"><?= mi_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="add">
    <div class="col-md-6"><label class="form-label fw-semibold">Supplier (RM Business Parties)</label><select name="supplier_party_id" class="form-select form-select-sm" onchange="document.getElementById('miSupName').style.display=this.value?'none':''"><option value="">— Potential supplier (type the name) —</option><?php foreach ($suppliers as $sp) { ?><option value="<?= (int) $sp['id']; ?>" <?= (int) ($_POST['supplier_party_id'] ?? 0) === (int) $sp['id'] ? 'selected' : ''; ?>><?= mi_e($sp['business_name'] ?: $sp['name']); ?></option><?php } ?></select></div>
    <div class="col-md-6" id="miSupName"><label class="form-label fw-semibold">Supplier name</label><input name="supplier_name" class="form-control form-control-sm" maxlength="160" value="<?= $v('supplier_name'); ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label fw-semibold">Contact</label><input name="contact" class="form-control form-control-sm" maxlength="120" value="<?= $v('contact'); ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label fw-semibold">Location</label><input name="location" class="form-control form-control-sm" maxlength="120" value="<?= $v('location'); ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label fw-semibold">Unit purchase price</label><input type="number" name="unit_price" min="0.01" step="any" class="form-control form-control-sm" value="<?= $v('unit_price'); ?>" required placeholder="RWF"></div>
    <div class="col-6 col-md-3"><label class="form-label fw-semibold">Minimum order qty</label><input type="number" name="moq" min="0" step="any" class="form-control form-control-sm" value="<?= $v('moq'); ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label fw-semibold">Available qty</label><input type="number" name="available_qty" min="0" step="any" class="form-control form-control-sm" value="<?= $v('available_qty'); ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label fw-semibold">Delivery / transport cost</label><input type="number" name="delivery_cost" min="0" step="any" class="form-control form-control-sm" value="<?= $v('delivery_cost'); ?>" placeholder="RWF total"></div>
    <div class="col-6 col-md-3"><label class="form-label fw-semibold">Other costs</label><input type="number" name="other_costs" min="0" step="any" class="form-control form-control-sm" value="<?= $v('other_costs'); ?>" placeholder="RWF total"></div>
    <div class="col-6 col-md-3"><label class="form-label fw-semibold">Delivery time (days)</label><input type="number" name="delivery_days" min="0" step="1" class="form-control form-control-sm" value="<?= $v('delivery_days'); ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label fw-semibold">Payment terms</label><select name="payment_terms" class="form-select form-select-sm"><option value="">—</option><?php foreach (MI_PAYMENT_TERMS as $x) { ?><option <?= ($_POST['payment_terms'] ?? '') === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label fw-semibold">Credit days</label><input type="number" name="credit_days" min="0" class="form-control form-control-sm" value="<?= $v('credit_days'); ?>" placeholder="if credit"></div>
    <div class="col-6 col-md-3"><label class="form-label fw-semibold">Quality</label><select name="quality" class="form-select form-select-sm"><option value="">—</option><?php foreach (['Good', 'Standard', 'Poor'] as $x) { ?><option <?= ($_POST['quality'] ?? '') === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label fw-semibold">Quotation date</label><input type="date" name="quote_date" class="form-control form-control-sm" value="<?= $v('quote_date', date('Y-m-d')); ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label fw-semibold">Valid until</label><input type="date" name="valid_until" class="form-control form-control-sm" value="<?= $v('valid_until'); ?>"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Quotation document <span class="fw-normal text-muted">(PDF or picture)</span></label><input type="file" name="attachment" accept="application/pdf,image/*" class="form-control form-control-sm"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Notes</label><input name="notes" class="form-control form-control-sm" maxlength="2000" value="<?= $v('notes'); ?>" placeholder="Brand, reliability, conditions…"></div>
    <div class="col-12"><button class="btn btn-primary btn-sm px-4">Add quotation</button></div>
</form></details>
<script>(function(){var s=document.querySelector('[name=supplier_party_id]');if(s&&s.value)document.getElementById('miSupName').style.display='none';})();</script>
<?php } ?>
<?php if ($removed) { ?><div class="small text-muted">Removed from the comparison: <?= mi_e(implode(', ', array_map(function ($r) { return $r['supplier_name'] . ' (' . mi_money($r['unit_price']) . ')'; }, $removed))); ?></div><?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>