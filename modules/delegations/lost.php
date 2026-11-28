<?php
/**
 * NEW FILE: modules/delegations/lost.php — Scenario C: customer requests that could not be fulfilled (no available
 * RM Tasker, not offered…). The potential revenue is an OPPORTUNITY only: it is never recorded as revenue.
 * Each request is also added to RM Market Insights as unmet demand (once).
 */
require __DIR__ . '/common.php';
dl_require($conn, 'view');
const DL_LOST_REASONS = ['No eligible RM Tasker', 'RM Tasker unavailable', 'Price outside the Taskers\' range', 'Deadline too short', 'Not offered by RISE MOTIVE', 'Customer declined the price / time', 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dl_require($conn, 'create');
    if (!dl_csrf_ok()) { dl_go('lost.php', 'error', 'The form expired. Please try again.'); }
    if (($_POST['act'] ?? '') === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $st = in_array($_POST['status'] ?? '', ['Open', 'Followed up', 'Converted', 'Closed'], true) ? $_POST['status'] : 'Open';
        $on = mb_substr(trim((string) ($_POST['outcome_note'] ?? '')), 0, 500);
        if ($st !== 'Open' && $on === '') { dl_go('lost.php', 'error', 'Say what happened (for example "customer bought it after a Tasker became available").'); }
        $now = dl_now();
        $s = mysqli_prepare($conn, 'UPDATE dl_lost SET status = ?, outcome_note = ?, updated_at = ? WHERE id = ?');
        mysqli_stmt_bind_param($s, 'sssi', $st, $on, $now, $id);
        mysqli_stmt_execute($s);
        dl_audit($conn, 'lost', $id, 'update', 'Unavailable request #' . $id . ' → ' . $st, $on ?: null);
        dl_go('lost.php', 'success', 'Updated.');
    }
    $pid = (int) ($_POST['product_id'] ?? 0) ?: null;
    $p = $pid ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, product_name, item_type FROM products WHERE id = ' . $pid)) : null;
    $svc = mb_substr(trim((string) ($_POST['service_text'] ?? '')), 0, 200) ?: ($p['product_name'] ?? '');
    $cid = (int) ($_POST['customer_id'] ?? 0) ?: null;
    $cname = mb_substr(trim((string) ($_POST['customer_name'] ?? '')), 0, 150) ?: null;
    $cphone = preg_replace('/\D/', '', (string) ($_POST['customer_phone'] ?? '')) ?: null;
    $price = trim((string) ($_POST['price_quoted'] ?? '')) === '' ? null : max(0, (float) str_replace(',', '', (string) $_POST['price_quoted']));
    $qty = max(1, (float) ($_POST['quantity'] ?? 1));
    $reason = in_array($_POST['reason'] ?? '', DL_LOST_REASONS, true) ? $_POST['reason'] : '';
    $note = mb_substr(trim((string) ($_POST['reason_note'] ?? '')), 0, 500) ?: null;
    $fu = !empty($_POST['follow_up']) ? 1 : 0;
    $fud = $fu && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['follow_up_date'] ?? '')) ? $_POST['follow_up_date'] : null;
    $err = '';
    if ($svc === '') { $err = 'Say which service or product was requested.'; }
    elseif ($reason === '') { $err = 'Choose why it cannot be fulfilled.'; }
    elseif ($reason === 'Other' && !$note) { $err = 'Explain the reason.'; }
    elseif (!$cid && !$cname) { $err = 'Choose the customer or write their name.'; }
    elseif ($fu && !$fud) { $err = 'Choose the follow-up date.'; }
    if ($err) { dl_go('lost.php?' . http_build_query(['product' => $pid, 'customer' => $cid, 'price' => $price]), 'error', $err); }
    $pot = $price !== null ? round($price * ($p && $p['item_type'] === 'Item' ? $qty : 1), 2) : null;
    $now = dl_now(); $u = dl_uid();
    $s = mysqli_prepare($conn, 'INSERT INTO dl_lost (product_id, service_text, customer_id, customer_name, customer_phone, price_quoted, quantity, reason, reason_note, potential_revenue, follow_up, follow_up_date, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'isissddssdisis', $pid, $svc, $cid, $cname, $cphone, $price, $qty, $reason, $note, $pot, $fu, $fud, $u, $now);
    mysqli_stmt_execute($s);
    $lid = (int) mysqli_insert_id($conn);
    dl_audit($conn, 'lost', $lid, 'create', 'Unavailable request: ' . $svc . ($pot !== null ? ' · potential ' . dl_money($pot) . ' (not revenue)' : '') . ' · ' . $reason, $note);
    // Unmet demand for RM Market Insights (once).
    $mi = '';
    try {
        require_once __DIR__ . '/../../includes/market_helpers.php';
        if (function_exists('mi_add_request')) {
            mi_ensure($conn);
            $kind = $p ? ($p['item_type'] === 'Service' ? 'service' : 'product') : 'service';
            if ($p && function_exists('mi_opportunity_for_product')) { $opp = mi_opportunity_for_product($conn, (int) $p['id'], $kind); }
            else { $l = mi_lookup($conn, $svc, $kind); $hit = array_values(array_filter($l['opportunities'], function ($o) { return $o['score'] >= 85; })); $opp = $hit ? (int) $hit[0]['id'] : mi_create_opportunity($conn, ['kind' => $kind, 'name' => $svc, 'origin' => 'request']); }
            $rid = mi_add_request($conn, $opp, ['customer_id' => $cid, 'walkin_name' => $cname, 'walkin_phone' => $cphone, 'quantity' => $qty, 'expected_price' => $price !== null ? round($price / ($p && $p['item_type'] === 'Item' ? $qty : 1), 2) : '',
                'source' => 'Walk-in', 'reason' => $kind === 'service' ? ($reason === 'Not offered by RISE MOTIVE' ? 'Service not yet included in company offerings' : 'Insufficient human resources') : 'Not in our catalogue',
                'outcome' => 'Request unresolved', 'notes' => 'From Delegations (unavailable request #' . $lid . '): ' . $reason . ($note ? ' — ' . $note : '')]);
            mysqli_query($conn, "UPDATE dl_lost SET mi_request_id = $rid WHERE id = $lid");
            $mi = ' Added to RM Market Insights as unmet demand.';
        }
    } catch (Throwable $e) { error_log('dl lost → mi: ' . $e->getMessage()); }
    dl_go('lost.php', 'success', 'Recorded. The potential revenue is a lost opportunity, not revenue.' . $mi);
}

$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT l.*, c.name cname FROM dl_lost l LEFT JOIN customers c ON c.id = l.customer_id ORDER BY l.id DESC LIMIT 300'), MYSQLI_ASSOC);
$tot = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) n, SUM(potential_revenue) pot, SUM(CASE WHEN status = 'Converted' THEN 1 ELSE 0 END) conv FROM dl_lost"));
$offers = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, product_name, selling_price FROM products WHERE is_active = 1 ORDER BY item_type DESC, product_name'), MYSQLI_ASSOC);
$customers = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name, phone FROM customers WHERE is_active = 1 ORDER BY name LIMIT 3000'), MYSQLI_ASSOC);
$names = dl_users($conn);
$pre = ['product' => (int) ($_GET['product'] ?? 0), 'customer' => (int) ($_GET['customer'] ?? 0), 'price' => $_GET['price'] ?? ''];

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'lost.php', 'Unavailable customer requests');
?>
<div class="row g-2 mb-3">
    <div class="col-4 col-md-3"><div class="dl-kpi"><div class="v"><?= (int) $tot['n']; ?></div><div class="l">Requests not fulfilled</div></div></div>
    <div class="col-4 col-md-3"><div class="dl-kpi"><div class="v"><?= dl_money($tot['pot'] ?? 0); ?></div><div class="l">Potential revenue (opportunity, not revenue)</div></div></div>
    <div class="col-4 col-md-3"><div class="dl-kpi"><div class="v"><?= (int) $tot['conv']; ?></div><div class="l">Converted later</div></div></div>
</div>
<?php if (dlc($conn, 'create')) { ?>
<form method="post" class="card mb-3"><div class="card-body py-2"><?= dl_csrf_field(); ?>
<div class="fw-semibold small mb-1">Record an unavailable request</div>
<div class="row g-2">
    <div class="col-md-4"><label class="form-label small mb-0">Service / product</label><select name="product_id" class="form-select form-select-sm"><option value="">Not in RM Offerings (write it)</option><?php foreach ($offers as $o) { ?><option value="<?= (int) $o['id']; ?>" <?= $pre['product'] === (int) $o['id'] ? 'selected' : ''; ?>><?= dl_e($o['product_name']); ?></option><?php } ?></select></div>
    <div class="col-md-4"><label class="form-label small mb-0">…or what the customer asked for</label><input name="service_text" class="form-control form-control-sm"></div>
    <div class="col-md-2"><label class="form-label small mb-0">Price quoted (RWF)</label><input name="price_quoted" inputmode="numeric" class="form-control form-control-sm" value="<?= dl_e($pre['price']); ?>"></div>
    <div class="col-md-2"><label class="form-label small mb-0">Quantity</label><input type="number" step="1" min="1" name="quantity" value="1" class="form-control form-control-sm"></div>
    <div class="col-md-4"><label class="form-label small mb-0">Customer</label><select name="customer_id" class="form-select form-select-sm"><option value="">Walk-in / not registered</option><?php foreach ($customers as $c) { ?><option value="<?= (int) $c['id']; ?>" <?= $pre['customer'] === (int) $c['id'] ? 'selected' : ''; ?>><?= dl_e($c['name']); ?> · <?= dl_e($c['phone']); ?></option><?php } ?></select></div>
    <div class="col-md-4"><label class="form-label small mb-0">…or name</label><input name="customer_name" class="form-control form-control-sm"></div>
    <div class="col-md-4"><label class="form-label small mb-0">Phone</label><input name="customer_phone" inputmode="numeric" class="form-control form-control-sm"></div>
    <div class="col-md-4"><label class="form-label small mb-0">Why it cannot be fulfilled</label><select name="reason" class="form-select form-select-sm" required><option value="">Choose…</option><?php foreach (DL_LOST_REASONS as $r) { ?><option><?= dl_e($r); ?></option><?php } ?></select></div>
    <div class="col-md-8"><label class="form-label small mb-0">Details</label><input name="reason_note" class="form-control form-control-sm"></div>
    <div class="col-md-4"><label class="small"><input type="checkbox" name="follow_up" value="1"> Follow-up required</label></div>
    <div class="col-md-3"><input type="date" name="follow_up_date" class="form-control form-control-sm" min="<?= date('Y-m-d'); ?>"></div>
    <div class="col-md-5 text-end"><button class="btn btn-primary btn-sm">Record</button></div>
</div></div></form>
<?php } ?>
<div class="card"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0">
<thead><tr><th>Date</th><th>Service</th><th>Customer</th><th class="text-end">Potential</th><th>Reason</th><th>Follow-up</th><th>By</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $r) { ?>
<tr><td class="small text-nowrap"><?= dl_e(date('d M Y', strtotime($r['created_at']))); ?></td><td class="small"><?= dl_e($r['service_text']); ?></td><td class="small"><?= dl_e($r['cname'] ?: $r['customer_name'] ?: '—'); ?><?= $r['customer_phone'] ? ' · ' . dl_e($r['customer_phone']) : ''; ?></td>
    <td class="text-end small"><?= $r['potential_revenue'] !== null ? dl_money($r['potential_revenue']) : '—'; ?></td><td class="small"><?= dl_e($r['reason']); ?><?= $r['reason_note'] ? '<div class="text-muted">' . dl_e($r['reason_note']) . '</div>' : ''; ?></td>
    <td class="small <?= (int) $r['follow_up'] && $r['status'] === 'Open' && $r['follow_up_date'] && $r['follow_up_date'] <= date('Y-m-d') ? 'text-danger fw-semibold' : ''; ?>"><?= (int) $r['follow_up'] ? dl_e(date('d M', strtotime($r['follow_up_date']))) : '—'; ?></td>
    <td class="small"><?= dl_e($names[(int) $r['user_id']] ?? ''); ?></td>
    <td><?php if (dlc($conn, 'create')) { ?><form method="post" class="d-flex gap-1"><?= dl_csrf_field(); ?><input type="hidden" name="act" value="update"><input type="hidden" name="id" value="<?= (int) $r['id']; ?>"><select name="status" class="form-select form-select-sm" style="width:auto"><?php foreach (['Open', 'Followed up', 'Converted', 'Closed'] as $s) { ?><option <?= $r['status'] === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select><input name="outcome_note" class="form-control form-control-sm" style="max-width:160px" placeholder="what happened" value="<?= dl_e($r['outcome_note']); ?>"><button class="btn btn-outline-primary btn-sm py-0">Save</button></form><?php } else { echo dl_e($r['status']); } ?></td></tr>
<?php } ?>
<?php if (!$rows) { ?><tr><td colspan="8" class="text-center text-muted py-4">None recorded.</td></tr><?php } ?>
</tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>