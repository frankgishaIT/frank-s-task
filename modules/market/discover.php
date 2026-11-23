<?php
/**
 * NEW FILE: modules/market/discover.php — NEW PRODUCT DISCOVERY: an employee records a product or service seen in the
 * market (competitor shop, supplier catalogue, social media…) that RISE MOTIVE does not sell yet. It becomes a
 * "Market research" opportunity; customer requests recorded later for it are added to the same record.
 */
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/image_kit.php';
mi_require($conn, 'request');
const MI_IMG_DIR = __DIR__ . '/../../storage/market';
$err = null; $similar = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mi_csrf_ok()) { mi_go('discover.php', 'error', 'The page had expired. Please try again.'); }
    $kind = ($_POST['kind'] ?? '') === 'service' ? 'service' : 'product';
    $name = trim((string) ($_POST['name'] ?? ''));
    try {
        if (mb_strlen($name) < 2) { throw new RuntimeException('Give the product or service name.'); }
        $l = mi_lookup($conn, $name, $kind);
        $cat = array_values(array_filter($l['catalogue'], function ($p) { return $p['score'] >= 85; }));
        if ($cat) { throw new RuntimeException('"' . $cat[0]['product_name'] . '" is already in RM Offerings. If customers ask for it, use "Record a request" instead.'); }
        $ex = array_values(array_filter($l['opportunities'], function ($o) { return $o['score'] >= 85; }));
        if ($ex && empty($_POST['confirm_new'])) { $similar = $ex; throw new RuntimeException('similar'); }
        $category = trim((string) ($_POST['category'] ?? '')); if ($category !== '' && !in_array($category, mi_categories($conn, $kind), true)) { $category = ''; }
        $src = in_array($_POST['discovery_source'] ?? '', MI_DISCOVERY_SOURCES, true) ? $_POST['discovery_source'] : null;
        $num = function ($k) { $v = $_POST[$k] ?? ''; return $v === '' ? null : max(0, (float) $v); };
        $img = null;
        if (!empty($_FILES['image']['name']) && ($_FILES['image']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
            if (($_FILES['image']['error'] ?? 1) !== UPLOAD_ERR_OK) { throw new RuntimeException('The picture could not be uploaded.'); }
            if ($e = img_prepare_upload('image', 'product')) { throw new RuntimeException($e); }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['image']['tmp_name']);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { throw new RuntimeException('The picture must be JPG, PNG or WebP.'); }
            if (!is_dir(MI_IMG_DIR)) { @mkdir(MI_IMG_DIR, 0775, true); @file_put_contents(MI_IMG_DIR . '/.htaccess', "Require all denied\n"); }
            $img = 'opp_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
            if (!move_uploaded_file($_FILES['image']['tmp_name'], MI_IMG_DIR . '/' . $img)) { throw new RuntimeException('The picture could not be saved.'); }
        }
        $branch = br_enabled($conn) ? (br_current($conn) ?: br_primary_id($conn, (int) current_user_id())) : null;
        $t = function ($k, $n) { return mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $n); };
        $before = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COALESCE(MAX(id), 0) FROM mi_opportunities'))[0];
        $id = mi_create_opportunity($conn, ['kind' => $kind, 'name' => $name, 'category' => $category, 'description' => $t('description', 2000), 'unit' => $t('unit', 30), 'origin' => 'research',
            'est_demand' => $t('est_demand', 80), 'segment' => $t('segment', 120), 'est_sell_price' => $num('est_sell_price'), 'est_buy_price' => $num('est_buy_price'),
            'possible_supplier' => $t('possible_supplier', 160), 'discovery_source' => $src, 'image_ref' => $img, 'notes' => $t('notes', 2000), 'branch_id' => $branch]);
        if ($id <= $before) { // the same name existed: keep the research details on the existing record
            mi_audit($conn, $id, null, 'research', 'Market research added: ' . ($src ?: 'no source') . ($t('notes', 300) !== '' ? ' — ' . $t('notes', 300) : ''));
            if ($img) { mysqli_query($conn, "UPDATE mi_opportunities SET image_ref = COALESCE(image_ref, '" . mysqli_real_escape_string($conn, $img) . "') WHERE id = $id"); }
        }
        mi_go('opportunity.php?id=' . $id, 'success', 'Thank you: the discovery is recorded. Management will review it with the other market signals.');
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
}
$kind = (($_POST['kind'] ?? $_GET['kind'] ?? '') === 'service') ? 'service' : 'product';
$v = function ($k, $d = '') { return mi_e($_POST[$k] ?? $d); };
$canMoney = mic($conn, 'finance') || mic($conn, 'research') || mic($conn, 'request');
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, 'discover.php', 'New product discovery');
echo img_kit_js();
?>
<form method="POST" enctype="multipart/form-data" style="max-width:760px"><?= mi_csrf_field(); ?>
<p class="small text-muted">Saw a product or service in the market that we do not sell? Record it here. It does not create a stock item: management first checks demand, suppliers and profitability.</p>
<?php if ($err === 'similar') { ?><div class="alert alert-warning small py-2">Already recorded: <?php foreach ($similar as $s) { ?><a href="opportunity.php?id=<?= (int) $s['id']; ?>" class="fw-semibold"><?= mi_e($s['name']); ?></a> (<?= (int) $s['requests']; ?> request(s)) <?php } ?>
    <label class="d-block mt-1"><input type="checkbox" name="confirm_new" value="1" class="form-check-input me-1">It is a different <?= $kind; ?>: record it anyway</label></div>
<?php } elseif ($err) { ?><div class="alert alert-danger small py-2"><?= mi_e($err); ?></div><?php } ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 small">
    <div class="col-sm-4"><label class="form-label fw-semibold">Type</label><select name="kind" class="form-select form-select-sm"><option value="product">Product</option><option value="service" <?= $kind === 'service' ? 'selected' : ''; ?>>Service</option></select></div>
    <div class="col-sm-8"><label class="form-label fw-semibold">Name</label><input name="name" class="form-control form-control-sm" maxlength="160" value="<?= $v('name'); ?>" required></div>
    <div class="col-sm-6"><label class="form-label fw-semibold">Category</label><select name="category" class="form-select form-select-sm"><option value="">Not sure</option><?php foreach (mi_categories($conn, 'product') as $c) { ?><option <?= ($_POST['category'] ?? '') === $c ? 'selected' : ''; ?>><?= mi_e($c); ?></option><?php } ?></select></div>
    <div class="col-sm-6"><label class="form-label fw-semibold">Where did you see it?</label><select name="discovery_source" class="form-select form-select-sm"><?php foreach (MI_DISCOVERY_SOURCES as $s) { ?><option <?= ($_POST['discovery_source'] ?? '') === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select></div>
    <div class="col-12"><label class="form-label fw-semibold">Description</label><textarea name="description" class="form-control form-control-sm" rows="2" maxlength="2000" placeholder="What it is, sizes, brands…"><?= $v('description'); ?></textarea></div>
    <div class="col-sm-6"><label class="form-label fw-semibold">Estimated demand</label><input name="est_demand" class="form-control form-control-sm" maxlength="80" value="<?= $v('est_demand'); ?>" placeholder="e.g. 30 pieces / month"></div>
    <div class="col-sm-6"><label class="form-label fw-semibold">Customer segment</label><input name="segment" class="form-control form-control-sm" maxlength="120" value="<?= $v('segment'); ?>" placeholder="e.g. students, offices, schools"></div>
    <?php if ($canMoney) { ?>
    <div class="col-6 col-sm-4"><label class="form-label fw-semibold">Est. selling price</label><input type="number" step="any" min="0" name="est_sell_price" class="form-control form-control-sm" value="<?= $v('est_sell_price'); ?>" placeholder="RWF"></div>
    <div class="col-6 col-sm-4"><label class="form-label fw-semibold">Est. purchase price</label><input type="number" step="any" min="0" name="est_buy_price" class="form-control form-control-sm" value="<?= $v('est_buy_price'); ?>" placeholder="RWF"></div>
    <?php } ?>
    <div class="col-sm-4"><label class="form-label fw-semibold">Unit</label><input name="unit" class="form-control form-control-sm" maxlength="30" value="<?= $v('unit'); ?>" placeholder="pieces"></div>
    <div class="col-sm-6"><label class="form-label fw-semibold">Possible supplier</label><input name="possible_supplier" class="form-control form-control-sm" maxlength="160" value="<?= $v('possible_supplier'); ?>"></div>
    <div class="col-sm-6"><label class="form-label fw-semibold">Picture <span class="fw-normal text-muted">(optional)</span></label><input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm"<?= img_input('product'); ?>></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes / evidence</label><textarea name="notes" class="form-control form-control-sm" rows="2" maxlength="2000" placeholder="Why you think it would sell, competitor prices…"><?= $v('notes'); ?></textarea></div>
</div></div>
<button class="btn btn-primary px-4 mb-4">Record the discovery</button>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>