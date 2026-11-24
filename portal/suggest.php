<?php
/**
 * NEW FILE: portal/suggest.php — RM Customer Portal: "Suggest a product or service".
 * A signed-in customer tells RISE MOTIVE what they would like to buy. The suggestion goes into RM Market Insights as a
 * customer request (source "Customer Portal"), added to the same product if it was already requested — so it counts
 * in the demand analysis with the customer counted once. The customer sees what happened to their suggestions.
 */
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/market_ops.php';
mi_ops_ensure($conn);
$kinds = ['product' => 'A product', 'service' => 'A service'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kind = ($_POST['kind'] ?? '') === 'service' ? 'service' : 'product';
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 160);
    $today = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mi_requests WHERE customer_id = $cid AND source = 'Customer Portal' AND requested_at >= '" . date('Y-m-d') . " 00:00:00'"))[0];
    if (!portal_csrf_ok()) { $error = 'Your session expired. Please try again.'; }
    elseif ($today >= 10) { $error = 'Thank you! You have sent many suggestions today: please continue tomorrow.'; }
    elseif (mb_strlen($name) < 2) { $error = 'Write the name of the ' . $kind . '.'; }
    else {
        try {
            $l = mi_lookup($conn, $name, $kind);
            $cat = array_values(array_filter($l['catalogue'], function ($p) { return $p['score'] >= 85; }));
            $opp = array_values(array_filter($l['opportunities'], function ($o) { return $o['score'] >= 85; }));
            $category = trim((string) ($_POST['category'] ?? '')); if ($category !== '' && !in_array($category, mi_categories($conn, $kind), true)) { $category = ''; }
            if ($cat) { $oid = mi_opportunity_for_product($conn, (int) $cat[0]['id'], $kind); }
            elseif ($opp) { $oid = (int) $opp[0]['id']; }
            else { $oid = mi_create_opportunity($conn, ['kind' => $kind, 'name' => $name, 'category' => $category, 'origin' => 'portal', 'description' => mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 2000)]); }
            $qty = (float) ($_POST['quantity'] ?? 1); $qty = $qty > 0 && $qty <= 100000 ? $qty : 1;
            mi_add_request($conn, $oid, ['customer_id' => $cid, 'quantity' => $qty, 'expected_price' => $_POST['expected_price'] ?? '', 'source' => 'Customer Portal', 'outcome' => 'Request unresolved',
                'reason' => $cat ? ((float) (mysqli_fetch_row(mysqli_query($conn, 'SELECT quantity FROM products WHERE id = ' . (int) $cat[0]['id']))[0] ?? 0) <= 0 && $kind === 'product' ? 'Out of stock' : '') : ($kind === 'service' ? 'Service not yet included in company offerings' : 'Not in our catalogue'), 'notes' => 'Customer Portal: ' . $name . (trim((string) ($_POST['description'] ?? '')) !== '' ? ' — ' . trim((string) $_POST['description']) : '')]);
            header('Location: suggest.php?success=' . urlencode('Thank you! Your suggestion was sent to RISE MOTIVE.')); exit;
        } catch (Throwable $e) { error_log('portal suggest: ' . $e->getMessage()); $error = 'Your suggestion could not be sent. Please try again.'; }
    }
}
$mine = mysqli_fetch_all(mysqli_query($conn, "SELECT r.id, r.quantity, r.requested_at, o.name, o.kind, o.status, o.unit FROM mi_requests r JOIN mi_opportunities o ON o.id = r.opportunity_id WHERE r.customer_id = $cid AND r.source = 'Customer Portal' ORDER BY r.id DESC LIMIT 30"), MYSQLI_ASSOC);
$friendly = function ($st) { return ['New' => ['Received', 'secondary'], 'Under Review' => ['Being studied', 'info'], 'More Information Required' => ['Being studied', 'info'], 'Recommended' => ['Being considered', 'info'], 'Approved' => ['Coming soon', 'primary'],
    'In Implementation' => ['Coming soon', 'primary'], 'Launched/Active' => ['Now available', 'success'], 'Completed' => ['Now available', 'success'], 'Rejected' => ['Not for now', 'secondary'], 'Deferred' => ['Later', 'secondary']][$st] ?? ['Received', 'secondary']; };
$kindSel = ($_POST['kind'] ?? $_GET['kind'] ?? 'product') === 'service' ? 'service' : 'product';
portal_header('Suggest a product', $me);
portal_alerts();
?>
<h1 class="p-title">Suggest a product or service</h1>
<div class="row g-3">
<div class="col-lg-7"><form method="POST" class="card"><div class="card-body"><?= portal_csrf_field(); ?>
    <p class="small text-muted">Is there something you would like to buy from RISE MOTIVE that we do not offer yet? Tell us: when several customers ask for the same thing, we study it.</p>
    <?php if (isset($error)) { ?><div class="alert alert-danger small"><?= portal_e($error); ?></div><?php } ?>
    <div class="mb-2"><?php foreach ($kinds as $k => $l) { ?><label class="me-3"><input type="radio" name="kind" value="<?= $k; ?>" class="form-check-input me-1" <?= $kindSel === $k ? 'checked' : ''; ?> onchange="location.href='suggest.php?kind=<?= $k; ?>'"><?= $l; ?></label><?php } ?></div>
    <label class="form-label small fw-semibold">Name</label><input name="name" class="form-control mb-2" maxlength="160" required value="<?= portal_e($_POST['name'] ?? ''); ?>" placeholder="<?= $kindSel === 'service' ? 'e.g. Website design' : 'e.g. A3 paper'; ?>">
    <div class="row g-2"><div class="col-sm-6"><label class="form-label small fw-semibold">Category</label><select name="category" class="form-select mb-2"><option value="">Not sure</option><?php foreach (mi_categories($conn, $kindSel) as $c) { ?><option <?= ($_POST['category'] ?? '') === $c ? 'selected' : ''; ?>><?= portal_e($c); ?></option><?php } ?></select></div>
        <div class="col-6 col-sm-3"><label class="form-label small fw-semibold">Quantity</label><input type="number" name="quantity" min="1" step="any" class="form-control mb-2" value="<?= portal_e($_POST['quantity'] ?? '1'); ?>"></div>
        <div class="col-6 col-sm-3"><label class="form-label small fw-semibold">Price you'd pay</label><input type="number" name="expected_price" min="0" step="any" class="form-control mb-2" value="<?= portal_e($_POST['expected_price'] ?? ''); ?>" placeholder="optional"></div></div>
    <label class="form-label small fw-semibold">More details <span class="text-muted fw-normal">(optional)</span></label><textarea name="description" class="form-control mb-3" rows="2" maxlength="2000" placeholder="Size, brand, how you would use it…"><?= portal_e($_POST['description'] ?? ''); ?></textarea>
    <button class="btn btn-primary">Send my suggestion</button>
</div></form></div>
<div class="col-lg-5"><div class="card"><div class="card-body"><div class="fw-bold mb-2">My suggestions</div>
    <?php foreach ($mine as $m) { [$lab, $col] = $friendly($m['status']); ?><div class="d-flex justify-content-between border-bottom py-2 small"><span><strong><?= portal_e($m['name']); ?></strong><div class="text-muted"><?= date('d M Y', strtotime($m['requested_at'])); ?> · <?= rtrim(rtrim(number_format((float) $m['quantity'], 2, '.', ''), '0'), '.'); ?> <?= portal_e($m['unit']); ?></div></span><span><span class="badge bg-<?= $col; ?>"><?= $lab; ?></span></span></div><?php } ?>
    <?php if (!$mine) { ?><div class="small text-muted">No suggestion yet.</div><?php } ?>
</div></div></div>
</div>
<?php portal_footer();