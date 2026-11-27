<?php
/** NEW FILE: modules/staff_cards/catalogue.php — products (stock records) and services (active RM Offerings) for the carousel, with live stock and price. */
require __DIR__ . '/common.php';
sdc_require($conn, 'manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sdc_csrf_ok()) { sdc_go('catalogue.php', 'error', 'The form expired. Please try again.'); }
    $pid = (int) ($_POST['product_id'] ?? 0);
    $p = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, product_name, item_type FROM products WHERE id = ' . $pid));
    if (!$p) { sdc_go('catalogue.php', 'error', 'Item not found.'); }
    $cat = $p['item_type'] === 'Service' ? 'service' : 'product';
    $back = 'catalogue.php?type=' . ($cat === 'service' ? 'service' : 'product');
    $cards = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM sdc_cards WHERE category = '$cat' AND product_id = $pid AND status <> 'Inactive'"), MYSQLI_ASSOC);
    $now = sdc_now(); $u = sdc_uid();
    if (($_POST['act'] ?? '') === 'exclude') {
        if ($cards) { mysqli_query($conn, "UPDATE sdc_cards SET label = 'Excluded', updated_by = $u, updated_at = '$now' WHERE category = '$cat' AND product_id = $pid AND status <> 'Inactive'"); foreach ($cards as $c) { sdc_audit($conn, (int) $c['id'], 'label', $p['product_name'] . ': label ' . $c['label'] . ' → Excluded'); } }
        else {
            $s = mysqli_prepare($conn, "INSERT INTO sdc_cards (category, product_id, title, label, status, details, created_by, created_at, updated_by, updated_at) VALUES (?, ?, ?, 'Excluded', 'Draft', '{}', ?, ?, ?, ?)");
            mysqli_stmt_bind_param($s, 'sisisis', $cat, $pid, $p['product_name'], $u, $now, $u, $now);
            mysqli_stmt_execute($s);
            sdc_audit($conn, (int) mysqli_insert_id($conn), 'label', $p['product_name'] . ': Excluded from the carousel');
        }
        sdc_go($back, 'success', $p['product_name'] . ' will never appear in the carousel.');
    }
    if (($_POST['act'] ?? '') === 'include') {
        foreach ($cards as $c) { if ($c['label'] === 'Excluded') { mysqli_query($conn, "UPDATE sdc_cards SET label = 'Normal', updated_by = $u, updated_at = '$now' WHERE id = " . (int) $c['id']); sdc_audit($conn, (int) $c['id'], 'label', $p['product_name'] . ': label Excluded → Normal'); } }
        sdc_go($back, 'success', $p['product_name'] . ' can appear again.');
    }
    sdc_go($back, 'error', 'Unknown action.');
}

$type = ($_GET['type'] ?? 'product') === 'service' ? 'service' : 'product';
$items = sdc_products($conn, $type === 'service' ? 'Service' : 'Item');
$cards = [];
foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM sdc_cards WHERE category = '$type' AND product_id IS NOT NULL AND status <> 'Inactive' ORDER BY id"), MYSQLI_ASSOC) as $c) { $cards[(int) $c['product_id']][] = $c; }
$live = [];
foreach (sdc_pool($conn) as $it) { if ($it['p'] && $it['cat'] === $type) { $live[(int) $it['p']['id']] = $it; } }
$policy = sdc_get($conn, 'stock_policy');
$auto = sdc_get($conn, $type === 'service' ? 'auto_services' : 'auto_products') === '1';
$q = mb_strtolower(trim((string) ($_GET['q'] ?? '')));

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
sdc_tabs($conn, 'catalogue.php', 'Products & services');
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-2">
    <div class="btn-group btn-group-sm"><a href="?type=product" class="btn btn-<?= $type === 'product' ? 'primary' : 'outline-primary'; ?>">Products (stock records)</a><a href="?type=service" class="btn btn-<?= $type === 'service' ? 'primary' : 'outline-primary'; ?>">Services (RM Offerings)</a></div>
    <form class="d-flex gap-1"><input type="hidden" name="type" value="<?= $type; ?>"><input name="q" value="<?= sdc_e($_GET['q'] ?? ''); ?>" class="form-control form-control-sm" placeholder="Search"></form>
    <span class="small text-muted"><?= $type === 'product' ? 'Out of stock: ' . ($policy === 'exclude' ? 'hidden' : 'shown labelled "Out of stock" (cards only)') . ' · ' : ''; ?>Automatic <?= $type === 'product' ? 'products in stock' : 'active services'; ?>: <?= $auto ? 'on' : 'off'; ?> (<a href="settings.php">Settings</a>)</span>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm sdc-tbl mb-0">
<thead><tr><th>Name</th><th>Category</th><?php if ($type === 'product') { ?><th class="text-end">In stock</th><?php } ?><th class="text-end">Price</th><th>Carousel</th><th class="text-end"></th></tr></thead><tbody>
<?php foreach ($items as $id => $p) {
    if ($q !== '' && mb_strpos(mb_strtolower($p['product_name'] . ' ' . $p['category']), $q) === false) { continue; }
    $cs = $cards[$id] ?? [];
    $excluded = (bool) array_filter($cs, function ($c) { return $c['label'] === 'Excluded'; });
    $pub = array_values(array_filter($cs, function ($c) { return $c['status'] === 'Published' && $c['label'] !== 'Excluded'; }));
    $draft = array_values(array_filter($cs, function ($c) { return $c['status'] !== 'Published' && $c['label'] !== 'Excluded'; }));
    if ($excluded) { $state = '<span class="sdc-st lb-Excluded">Excluded</span>'; }
    elseif (!(int) $p['is_active']) { $state = '<span class="sdc-st st-Inactive">Not active in RM Offerings</span>'; }
    elseif (isset($live[$id])) { $state = '<span class="sdc-st st-Published">Shown' . ($pub ? ' · card' : ' · automatic') . (in_array('out', $live[$id]['flags'], true) ? ' · out of stock' : '') . '</span>'; }
    elseif ($type === 'product' && (float) $p['quantity'] <= 0) { $state = '<span class="sdc-st st-Inactive">Out of stock: hidden</span>'; }
    else { $state = '<span class="sdc-st st-Draft">Not shown' . ($draft ? ' · draft card' : '') . '</span>'; }
    $edit = $pub[0] ?? $draft[0] ?? null;
?>
<tr><td><?= sdc_e($p['product_name']); ?></td><td class="small"><?= sdc_e($p['category'] ?: '—'); ?></td>
    <?php if ($type === 'product') { $qty = (float) $p['quantity']; ?><td class="text-end <?= $qty <= 0 ? 'text-danger' : ($qty <= sdc_low_level($p) ? 'text-warning' : ''); ?>"><?= sdc_e(rtrim(rtrim(number_format($qty, 2), '0'), '.') . ' ' . ($p['unit'] ?: '')); ?></td><?php } ?>
    <td class="text-end text-nowrap"><?= (float) $p['selling_price'] > 0 ? 'RWF ' . number_format((float) $p['selling_price']) : ($type === 'service' ? 'Quotation' : '—'); ?></td>
    <td><?= $state; ?></td>
    <td class="text-end text-nowrap">
        <?php if ($edit) { ?><a href="edit.php?id=<?= (int) $edit['id']; ?>" class="btn btn-outline-primary btn-sm py-0">Edit card</a><?php } elseif ((int) $p['is_active'] && !$excluded) { ?><a href="edit.php?cat=<?= $type; ?>&product=<?= (int) $id; ?>" class="btn btn-outline-primary btn-sm py-0">Add details</a><?php } ?>
        <form method="post" class="d-inline"><?= sdc_csrf_field(); ?><input type="hidden" name="product_id" value="<?= (int) $id; ?>">
        <?php if ($excluded) { ?><button name="act" value="include" class="btn btn-outline-secondary btn-sm py-0">Allow again</button><?php } else { ?><button name="act" value="exclude" class="btn btn-outline-danger btn-sm py-0">Exclude</button><?php } ?></form>
    </td></tr>
<?php } ?>
<?php if (!$items) { ?><tr><td colspan="6" class="text-center text-muted py-5">Nothing in <?= $type === 'service' ? 'RM Offerings services' : 'the stock records'; ?>.</td></tr><?php } ?>
</tbody></table></div></div>
<p class="small text-muted mt-2 mb-0">Stock, price, name and category are read live: when they change in MY MOTIVE, the carousel shows the new values the next time the card appears.</p>
<?php include __DIR__ . '/../../includes/footer.php'; ?>