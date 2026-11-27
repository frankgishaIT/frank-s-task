<?php
/** NEW FILE: modules/staff_cards/edit.php — create / edit one carousel card (all six categories), with preview before publishing. */
require __DIR__ . '/common.php';
sdc_require($conn, 'manage');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$c = $id ? sdc_card($conn, $id) : null;
if ($id && !$c) { sdc_go('index.php', 'error', 'Card not found.'); }
$cat = $c['category'] ?? (isset(SDC_CATS[$_GET['cat'] ?? $_POST['cat'] ?? '']) ? ($_GET['cat'] ?? $_POST['cat']) : 'reminder');
$needs = in_array($cat, SDC_NEEDS_APPROVAL, true);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sdc_csrf_ok()) { $error = 'The form expired. Please try again.'; }
    else {
        [$r, $error] = sdc_form_card($conn, $cat, $_POST, $c);
        if (!$error) {
            try { $img = sdc_save_image($conn, 'image', $cat); if ($img) { $r['image_ref'] = $img; } }
            catch (Throwable $e) { $error = 'Image: ' . $e->getMessage(); }
        }
        if (!$error) {
            $go = (string) ($_POST['go'] ?? 'draft');
            $status = $c['status'] ?? 'Draft';
            $msg = 'Saved.';
            if ($c && $status === 'Published' && $needs) { $status = 'Pending'; $msg = 'Saved. Because it changed, it waits for approval again.'; }
            if ($go === 'publish' && !$needs) { $status = 'Published'; $msg = 'Saved and published.'; }
            if ($go === 'submit' && $needs && $status !== 'Published') { $status = 'Pending'; $msg = 'Saved and sent for approval.'; }
            $json = json_encode($r['d'], JSON_UNESCAPED_UNICODE);
            $now = sdc_now(); $u = sdc_uid();
            $cols = ['title' => 's', 'body' => 's', 'product_id' => 'i', 'user_id' => 'i', 'label' => 's', 'priority' => 'i', 'season_months' => 's', 'audience' => 's', 'month' => 's', 'start_date' => 's', 'end_date' => 's', 'media_url' => 's', 'show_photo' => 'i', 'image_ref' => 's'];
            $vals = []; foreach ($cols as $k => $_) { $vals[] = $r[$k]; }
            $vals[] = $json; $vals[] = $status; $vals[] = $u; $vals[] = $now;
            $types = implode('', $cols) . 'ssis';
            if ($c) {
                $s = mysqli_prepare($conn, 'UPDATE sdc_cards SET ' . implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($cols))) . ', details = ?, status = ?, updated_by = ?, updated_at = ?' . ($status === 'Pending' ? ', approved_by = NULL, approved_at = NULL' : '') . ' WHERE id = ?');
                $vals[] = $id; $types .= 'i';
                mysqli_stmt_bind_param($s, $types, ...$vals);
                mysqli_stmt_execute($s);
                $chg = [];
                foreach (array_keys($cols) as $k) { if ((string) $c[$k] !== (string) $r[$k]) { $chg[] = $k; } }
                if ($json !== (string) $c['details']) { $chg[] = 'details'; }
                sdc_audit($conn, $id, 'edit', SDC_CATS[$cat][0] . ': ' . $r['title'] . ($chg ? ' — changed: ' . implode(', ', $chg) : '') . ($status !== $c['status'] ? ' (' . $c['status'] . ' → ' . $status . ')' : ''));
            } else {
                $s = mysqli_prepare($conn, 'INSERT INTO sdc_cards (category, ' . implode(', ', array_keys($cols)) . ', details, status, created_by, created_at, updated_by, updated_at) VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?, ?, ?, ?, ?, ?)');
                $vals[] = $u; $vals[] = $now; $types .= 'is';
                array_unshift($vals, $cat);
                mysqli_stmt_bind_param($s, 's' . $types, ...$vals);
                mysqli_stmt_execute($s);
                $id = (int) mysqli_insert_id($conn);
                sdc_audit($conn, $id, 'create', SDC_CATS[$cat][0] . ': ' . $r['title'] . ' (' . $status . ')');
            }
            if ($status === 'Published' && (!$c || $c['status'] !== 'Published')) { sdc_audit($conn, $id, 'publish', SDC_CATS[$cat][0] . ': ' . $r['title']); }
            if ($status === 'Pending' && (!$c || $c['status'] !== 'Pending')) {
                require_once __DIR__ . '/../../includes/notification_helper.php';
                foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM users WHERE is_active = 1 AND role IN ('Admin', 'Manager') AND id <> " . sdc_uid()), MYSQLI_NUM) as [$a]) {
                    if (sdc_user_can($conn, (int) $a, 'staff_cards.approve')) { notifyUser($conn, (int) $a, 'Dashboard card waiting for approval', SDC_CATS[$cat][0] . ': ' . $r['title']); }
                }
            }
            sdc_go('index.php', 'success', $msg);
        }
        $c = array_merge($c ?? [], $r, ['details' => json_encode($r['d'])]);
    }
}

$v = $c ?? ['title' => '', 'body' => '', 'product_id' => (int) ($_GET['product'] ?? 0) ?: null, 'user_id' => null, 'label' => 'Normal', 'priority' => 3, 'season_months' => '', 'audience' => 'all', 'month' => date('Y-m', strtotime(date('Y-m-01') . ' -1 month')),
    'start_date' => $cat === 'announcement' ? date('Y-m-d') : null, 'end_date' => $cat === 'announcement' ? date('Y-m-d', strtotime('+14 days')) : null, 'media_url' => '', 'show_photo' => 1, 'image_ref' => null, 'status' => 'Draft', 'details' => '{}'];
$d = json_decode((string) ($v['details'] ?? '{}'), true) ?: [];
$items = sdc_products($conn);
$users = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names, role FROM users WHERE is_active = 1 ORDER BY names'), MYSQLI_ASSOC);
$depts = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name'), MYSQLI_ASSOC);
$images = mysqli_fetch_all(mysqli_query($conn, "SELECT image_ref, MAX(title) t FROM sdc_cards WHERE image_ref LIKE 'db:%' GROUP BY image_ref ORDER BY MAX(id) DESC LIMIT 40"), MYSQLI_ASSOC);
$kpi = null;
if ($cat === 'employee') { try { require_once __DIR__ . '/../../includes/best_employee.php'; $kpi = be_current($conn); } catch (Throwable $e) { $kpi = null; } }
$f = function ($k) use ($v) { return sdc_e($v[$k] ?? ''); };
$dv = function ($k) use ($d) { return sdc_e($d[$k] ?? ''); };
$ta = function ($name, $label, $val, $rows = 2, $ph = '') { return '<div class="col-12"><label class="form-label small mb-0">' . sdc_e($label) . '</label><textarea name="' . $name . '" rows="' . $rows . '" class="form-control form-control-sm" placeholder="' . sdc_e($ph) . '">' . $val . '</textarea></div>'; };
$money = function ($x) { return 'RWF ' . number_format((float) $x); };

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
sdc_tabs($conn, 'index.php', ($c && !empty($c['id']) ? 'Edit' : 'New') . ': ' . SDC_CATS[$cat][0], $c && !empty($c['id']) ? sdc_badge($c) : '');
if ($error) { echo '<div class="alert alert-danger py-2 small">' . sdc_e($error) . '</div>'; }
?>
<form method="post" enctype="multipart/form-data" class="card"><div class="card-body">
<?= sdc_csrf_field(); ?><input type="hidden" name="id" value="<?= (int) ($c['id'] ?? 0); ?>"><input type="hidden" name="cat" value="<?= sdc_e($cat); ?>">
<div class="row g-2" style="max-width:920px">
<?php if ($cat === 'reminder') { ?>
    <div class="col-md-4"><label class="form-label small mb-0">Heading</label><input name="title" class="form-control form-control-sm" value="<?= $f('title') ?: 'Customer First'; ?>"></div>
    <?= $ta('body', 'Reminder (short and practical, at most 300 characters)', $f('body'), 2, "Understand the customer's needs before recommending a product."); ?>
<?php } elseif ($cat === 'product') { ?>
    <div class="col-md-6"><label class="form-label small mb-0">Product (from the stock records)</label><select name="product_id" class="form-select form-select-sm" required><option value="">Choose…</option>
        <?php foreach ($items as $p) { if ($p['item_type'] !== 'Item') { continue; } ?><option value="<?= (int) $p['id']; ?>" <?= (int) $v['product_id'] === (int) $p['id'] ? 'selected' : ''; ?>><?= sdc_e($p['product_name']); ?> — <?= (float) $p['quantity'] > 0 ? sdc_e(rtrim(rtrim(number_format((float) $p['quantity'], 2), '0'), '.') . ' ' . ($p['unit'] ?: '')) . ' in stock' : 'OUT OF STOCK'; ?> · <?= $money($p['selling_price']); ?><?= (int) $p['is_active'] ? '' : ' (inactive)'; ?></option><?php } ?></select>
        <div class="form-text">Name, category, quantity and price are read live from MY MOTIVE: never typed here.</div></div>
    <?= $ta('uses', 'Uses and benefits', $dv('uses'), 2, 'Printing, photocopying and office documentation.'); ?>
    <?= $ta('suitable', 'Suitable customers / customer need', $dv('suitable'), 2, 'Schools, offices, students and printing businesses.'); ?>
    <div class="col-12"><label class="form-label small mb-0">Complementary products (optional)</label><select name="complements[]" multiple size="4" class="form-select form-select-sm">
        <?php foreach ($items as $p) { if (!(int) $p['is_active']) { continue; } ?><option value="<?= (int) $p['id']; ?>" <?= in_array((int) $p['id'], array_map('intval', (array) ($d['complements'] ?? [])), true) ? 'selected' : ''; ?>><?= sdc_e($p['product_name']); ?><?= $p['item_type'] === 'Service' ? ' (service)' : ''; ?></option><?php } ?></select>
        <div class="form-text">Ctrl / Cmd-click to choose several. Without any, the card shows what customers often buy with it (from sales).</div></div>
    <?= $ta('tip', 'Practical sales tip', $dv('tip'), 2, "Ask about the customer's expected usage before recommending quantity."); ?>
<?php } elseif ($cat === 'education') { ?>
    <div class="col-md-6"><label class="form-label small mb-0">Title</label><input name="title" class="form-control form-control-sm" value="<?= $f('title'); ?>" placeholder="Choosing the right paper" required></div>
    <div class="col-md-6"><label class="form-label small mb-0">About a product or service (optional)</label><select name="product_id" class="form-select form-select-sm"><option value="">General advice</option>
        <?php foreach ($items as $p) { if (!(int) $p['is_active']) { continue; } ?><option value="<?= (int) $p['id']; ?>" <?= (int) $v['product_id'] === (int) $p['id'] ? 'selected' : ''; ?>><?= sdc_e($p['product_name']); ?><?= $p['item_type'] === 'Service' ? ' (service)' : ''; ?></option><?php } ?></select></div>
    <?php foreach (SDC_EDU_FIELDS as $k => $l) { echo $ta($k, $l, $dv($k), 2); } ?>
    <div class="col-12 small text-muted">The aim is product knowledge and customer satisfaction, not unnecessary selling.</div>
<?php } elseif ($cat === 'service') { ?>
    <div class="col-md-6"><label class="form-label small mb-0">Service (active RM Offerings)</label><select name="product_id" class="form-select form-select-sm" required><option value="">Choose…</option>
        <?php foreach ($items as $p) { if ($p['item_type'] !== 'Service') { continue; } ?><option value="<?= (int) $p['id']; ?>" <?= (int) $v['product_id'] === (int) $p['id'] ? 'selected' : ''; ?> <?= (int) $p['is_active'] ? '' : 'disabled'; ?>><?= sdc_e($p['product_name']); ?><?= (float) $p['selling_price'] > 0 ? ' · ' . $money($p['selling_price']) : ' · quotation'; ?><?= (int) $p['is_active'] ? '' : ' (not active)'; ?></option><?php } ?></select>
        <div class="form-text">The price guide is read live from RM Offerings.</div></div>
    <?= $ta('description', 'Short description (empty = the RM Offerings description)', $dv('description'), 2); ?>
    <?= $ta('requirements', 'Customer requirements', $dv('requirements'), 2); ?>
    <?= $ta('documents', 'Required documents (where applicable)', $dv('documents'), 2); ?>
    <?= $ta('price_note', 'Price guidance or quotation requirements', $dv('price_note'), 2, 'Price depends on size; give a quotation after seeing the item.'); ?>
    <?= $ta('assist', 'How staff can assist the customer', $dv('assist'), 2); ?>
<?php } elseif ($cat === 'employee') { ?>
    <?php if ($kpi) { $w = $kpi['winners'][0]; $txt = 'Highest weighted KPI achievement in ' . date('F Y', strtotime($kpi['month'] . '-01')) . ': ' . rtrim(rtrim(number_format((float) $w['score'], 1), '0'), '.') . '% · ' . (int) $w['met'] . ' of ' . (int) $w['measured'] . ' KPIs on target (approved KPI results).'; ?>
    <div class="col-12"><div class="alert alert-info py-2 small mb-1">Approved KPI results, <?= sdc_e(date('F Y', strtotime($kpi['month'] . '-01'))); ?><?= $kpi['final'] ? '' : ' (so far — not final)'; ?>: <b><?= sdc_e(implode(', ', array_column($kpi['winners'], 'name'))); ?></b>.
        <button type="button" class="btn btn-sm btn-outline-primary py-0 ms-1" onclick="var f=this.form;f.user_id.value='<?= (int) $w['user_id']; ?>';f.month.value='<?= sdc_e($kpi['month']); ?>';f.body.value=<?= sdc_e(json_encode($txt)); ?>;f.source.value='kpi';">Use this result</button>
        <?= $kpi['final'] ? '<div class="mt-1">When nobody publishes a recognition for that month, this final result is shown automatically (Settings).</div>' : ''; ?></div></div>
    <?php } ?>
    <div class="col-md-5"><label class="form-label small mb-0">Employee</label><select name="user_id" class="form-select form-select-sm" required><option value="">Choose…</option><?php foreach ($users as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= (int) $v['user_id'] === (int) $u['id'] ? 'selected' : ''; ?>><?= sdc_e($u['names']); ?></option><?php } ?></select></div>
    <div class="col-md-3"><label class="form-label small mb-0">Month</label><input type="month" name="month" class="form-control form-control-sm" value="<?= $f('month'); ?>" max="<?= date('Y-m'); ?>" required></div>
    <div class="col-md-4"><label class="form-label small mb-0">Recognition title</label><input name="title" class="form-control form-control-sm" value="<?= $f('title') ?: 'Best Employee of the Month'; ?>"></div>
    <?= $ta('body', 'Reason (factual: approved decision or verified performance)', $f('body'), 2); ?>
    <div class="col-md-4"><label class="form-label small mb-0">Based on</label><select name="source" class="form-select form-select-sm"><option value="decision" <?= ($d['source'] ?? '') !== 'kpi' ? 'selected' : ''; ?>>Management decision</option><option value="kpi" <?= ($d['source'] ?? '') === 'kpi' ? 'selected' : ''; ?>>Approved KPI results</option></select></div>
    <div class="col-md-8"><label class="form-label small mb-0">Decision reference (meeting, date, minutes)</label><input name="reference" class="form-control form-control-sm" value="<?= $dv('reference'); ?>"></div>
    <div class="col-12"><label class="small"><input type="checkbox" name="show_photo" value="1" <?= (int) $v['show_photo'] ? 'checked' : ''; ?>> Show the employee's profile photo (only with their agreement)</label></div>
    <div class="col-12 small text-muted">A recognition appears only after approval, for the month chosen and the month after.</div>
<?php } else { ?>
    <div class="col-md-8"><label class="form-label small mb-0">Title</label><input name="title" class="form-control form-control-sm" value="<?= $f('title'); ?>" required></div>
    <div class="col-md-4"><label class="form-label small mb-0">Intended audience</label><select name="audience" class="form-select form-select-sm">
        <option value="all">All staff</option><?php foreach (['Admin', 'Manager', 'Employee'] as $ro) { ?><option value="role:<?= $ro; ?>" <?= $v['audience'] === 'role:' . $ro ? 'selected' : ''; ?>><?= $ro; ?>s only</option><?php } ?>
        <?php foreach ($depts as $dp) { ?><option value="dept:<?= (int) $dp['id']; ?>" <?= $v['audience'] === 'dept:' . (int) $dp['id'] ? 'selected' : ''; ?>>Department: <?= sdc_e($dp['name']); ?></option><?php } ?></select></div>
    <?= $ta('body', 'Message', $f('body'), 3); ?>
    <div class="col-md-12"><label class="form-label small mb-0">Media link (optional: video or document, https://…)</label><input name="media_url" class="form-control form-control-sm" value="<?= $f('media_url'); ?>"></div>
<?php } ?>

    <div class="col-12"><hr class="my-2"></div>
    <div class="col-md-5"><label class="form-label small mb-0">Image <?= $cat === 'employee' ? '(empty = profile photo)' : ''; ?></label><input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm">
        <?php if ($images) { ?><select name="image_pick" class="form-select form-select-sm mt-1"><option value="">…or reuse an uploaded image</option><?php foreach ($images as $im) { ?><option value="<?= sdc_e($im['image_ref']); ?>" <?= ($v['image_ref'] ?? '') === $im['image_ref'] ? 'selected' : ''; ?>><?= sdc_e($im['t'] ?: $im['image_ref']); ?></option><?php } ?></select><?php } ?>
        <?php if (!empty($v['image_ref'])) { ?><label class="small mt-1"><input type="checkbox" name="image_remove" value="1"> Remove the image</label><?php } ?>
        <div class="form-text">Missing images show a standard placeholder.</div></div>
    <div class="col-md-2"><label class="form-label small mb-0">Label</label><select name="label" class="form-select form-select-sm"><?php foreach (SDC_LABELS as $l) { ?><option <?= $v['label'] === $l ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
    <div class="col-md-2"><label class="form-label small mb-0">Priority</label><select name="priority" class="form-select form-select-sm"><?php foreach ([1 => '1 low', 2 => '2', 3 => '3 normal', 4 => '4 important', 5 => '5 urgent'] as $k => $l) { ?><option value="<?= $k; ?>" <?= (int) $v['priority'] === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
    <div class="col-md-3"><label class="form-label small mb-0">Show from – until <?= $cat === 'announcement' ? '' : '(optional)'; ?></label><div class="d-flex gap-1"><input type="date" name="start_date" class="form-control form-control-sm" value="<?= $f('start_date'); ?>" <?= $cat === 'announcement' ? 'required' : ''; ?>><input type="date" name="end_date" class="form-control form-control-sm" value="<?= $f('end_date'); ?>" <?= $cat === 'announcement' ? 'required' : ''; ?>></div></div>
    <?php if (in_array($cat, ['product', 'education', 'service', 'reminder'], true)) { $sm = array_map('intval', explode(',', (string) $v['season_months'])); ?>
    <div class="col-12"><span class="small text-muted me-1">Seasonal relevance (shown more often in):</span><?php for ($m = 1; $m <= 12; $m++) { ?><label class="small me-2"><input type="checkbox" name="season[]" value="<?= $m; ?>" <?= in_array($m, $sm, true) ? 'checked' : ''; ?>> <?= date('M', mktime(0, 0, 0, $m, 1)); ?></label><?php } ?></div>
    <?php } ?>
</div>
<div class="d-flex flex-wrap gap-2 mt-3">
    <button class="btn btn-outline-secondary btn-sm" formaction="preview.php" formtarget="_blank" name="preview" value="1"><i class="bi bi-eye"></i> Preview</button>
    <button class="btn btn-outline-primary btn-sm" name="go" value="draft"><?= ($c['status'] ?? 'Draft') === 'Published' ? 'Save changes' : 'Save as draft'; ?></button>
    <?php if (!$needs && ($c['status'] ?? 'Draft') !== 'Published') { ?><button class="btn btn-success btn-sm" name="go" value="publish">Save and publish</button><?php } ?>
    <?php if ($needs && !in_array($c['status'] ?? 'Draft', ['Published', 'Pending'], true)) { ?><button class="btn btn-primary btn-sm" name="go" value="submit">Save and send for approval</button><?php } ?>
    <a href="index.php" class="btn btn-link btn-sm">Cancel</a>
</div>
<?php if ($needs && ($c['status'] ?? '') === 'Published') { ?><div class="small text-muted mt-1">Saving changes sends it back for approval: it stops showing until it is approved again.</div><?php } ?>
</div></form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>