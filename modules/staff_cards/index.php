<?php
/** NEW FILE: modules/staff_cards/index.php — Staff Dashboard Cards Management: all cards, status, label, priority. */
require __DIR__ . '/common.php';
sdc_require($conn, 'any');
if (!sdcc($conn, 'manage')) { header('Location: ' . (sdcc($conn, 'approve') ? 'approvals.php' : (sdcc($conn, 'settings') ? 'settings.php' : 'preview.php'))); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sdc_csrf_ok()) { sdc_go('index.php', 'error', 'The form expired. Please try again.'); }
    $c = sdc_card($conn, (int) ($_POST['id'] ?? 0));
    if (!$c) { sdc_go('index.php', 'error', 'Card not found.'); }
    $act = (string) ($_POST['act'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $back = 'index.php' . (!empty($_POST['back']) ? '?' . preg_replace('/[^A-Za-z0-9_=&%-]/', '', (string) $_POST['back']) : '');
    $needsApproval = in_array($c['category'], SDC_NEEDS_APPROVAL, true);
    if ($act === 'publish' && !$needsApproval && in_array($c['status'], ['Draft', 'Inactive'], true)) {
        sdc_set_status($conn, $c, 'Published');
        sdc_go($back, 'success', 'Published: it can now appear in the Dashboard carousel.');
    }
    if ($act === 'submit' && $needsApproval && in_array($c['status'], ['Draft', 'Rejected', 'Inactive'], true)) {
        sdc_set_status($conn, $c, 'Pending');
        $who = mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM users WHERE is_active = 1 AND role IN ('Admin', 'Manager') AND id <> " . sdc_uid()), MYSQLI_NUM);
        require_once __DIR__ . '/../../includes/notification_helper.php';
        foreach ($who as [$uid]) { if (sdc_user_can($conn, (int) $uid, 'staff_cards.approve')) { notifyUser($conn, (int) $uid, 'Dashboard card waiting for approval', SDC_CATS[$c['category']][0] . ': ' . $c['title']); } }
        sdc_go($back, 'success', 'Sent for approval. It appears only after it is approved.');
    }
    if ($act === 'deactivate' && in_array($c['status'], ['Published', 'Pending'], true)) {
        if ($reason === '') { sdc_go($back, 'error', 'Give the reason for deactivating it.'); }
        sdc_set_status($conn, $c, 'Inactive', $reason);
        sdc_go($back, 'success', ($c['category'] === 'announcement' ? 'Withdrawn' : 'Deactivated') . ': it no longer appears.');
    }
    if ($act === 'reopen' && in_array($c['status'], ['Inactive', 'Rejected'], true)) {
        sdc_set_status($conn, $c, 'Draft');
        sdc_go('edit.php?id=' . (int) $c['id'], 'success', 'Back to Draft: check it, then publish' . ($needsApproval ? ' (it needs approval again).' : '.'));
    }
    if ($act === 'label') {
        $l = in_array($_POST['label'] ?? '', SDC_LABELS, true) ? $_POST['label'] : 'Normal';
        $p = max(1, min(5, (int) ($_POST['priority'] ?? 3)));
        if ($l !== $c['label'] || $p !== (int) $c['priority']) {
            $now = sdc_now(); $u = sdc_uid();
            $s = mysqli_prepare($conn, 'UPDATE sdc_cards SET label = ?, priority = ?, updated_by = ?, updated_at = ? WHERE id = ?');
            $id = (int) $c['id'];
            mysqli_stmt_bind_param($s, 'siisi', $l, $p, $u, $now, $id);
            mysqli_stmt_execute($s);
            sdc_audit($conn, $id, 'label', ($c['title'] ?: '#' . $id) . ': label ' . $c['label'] . ' → ' . $l . ', priority ' . $c['priority'] . ' → ' . $p);
        }
        sdc_go($back, 'success', 'Label and priority saved.');
    }
    if ($act === 'delete' && in_array($c['status'], ['Draft', 'Rejected'], true) && !$c['approved_at']) {
        mysqli_query($conn, 'DELETE FROM sdc_cards WHERE id = ' . (int) $c['id']);
        sdc_audit($conn, (int) $c['id'], 'delete', SDC_CATS[$c['category']][0] . ': ' . ($c['title'] ?: '#' . $c['id']) . ' (never shown)');
        sdc_go($back, 'success', 'Deleted.');
    }
    sdc_go($back, 'error', 'That action is not possible for this card now.');
}

$cat = isset(SDC_CATS[$_GET['cat'] ?? '']) ? $_GET['cat'] : '';
$st = in_array($_GET['status'] ?? '', ['Draft', 'Pending', 'Published', 'Rejected', 'Inactive'], true) ? $_GET['status'] : '';
$w = ['1=1'];
if ($cat) { $w[] = "c.category = '$cat'"; }
if ($st) { $w[] = "c.status = '$st'"; }
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT c.*, p.product_name, p.quantity, p.item_type, p.is_active p_active, u.names emp FROM sdc_cards c LEFT JOIN products p ON p.id = c.product_id LEFT JOIN users u ON u.id = c.user_id WHERE ' . implode(' AND ', $w) . " ORDER BY CASE c.status WHEN 'Pending' THEN 0 WHEN 'Draft' THEN 1 WHEN 'Published' THEN 2 ELSE 3 END, c.updated_at DESC, c.id DESC"), MYSQLI_ASSOC);
$counts = [];
foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT category, COUNT(*) n FROM sdc_cards WHERE status = 'Published' GROUP BY category"), MYSQLI_ASSOC) as $r) { $counts[$r['category']] = (int) $r['n']; }
$back = http_build_query(array_filter(['cat' => $cat, 'status' => $st]));
$today = date('Y-m-d');

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
sdc_tabs($conn, 'index.php', 'Staff Dashboard Cards', '<div class="dropdown"><button class="btn btn-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-plus-lg"></i> New card</button><ul class="dropdown-menu dropdown-menu-end">'
    . implode('', array_map(function ($k, $v) { return '<li><a class="dropdown-item small" href="edit.php?cat=' . $k . '"><i class="bi ' . $v[1] . '"></i> ' . sdc_e($v[0]) . '</a></li>'; }, array_keys(SDC_CATS), SDC_CATS)) . '</ul></div>');
?>
<div class="d-flex flex-wrap gap-2 mb-3">
<?php foreach (SDC_CATS as $k => [$n, $i, $col]) { $off = sdc_get($conn, 'cat_' . $k) !== '1'; ?>
    <a href="?cat=<?= $k; ?>" class="text-decoration-none border rounded-3 px-2 py-1 small <?= $cat === $k ? 'border-primary' : ''; ?>" style="color:<?= $col; ?>;<?= $off ? 'opacity:.55' : ''; ?>">
        <i class="bi <?= $i; ?>"></i> <?= sdc_e($n); ?> <span class="text-muted">· <?= (int) ($counts[$k] ?? 0); ?> live<?= $off ? ' · switched off' : ''; ?></span></a>
<?php } ?>
<?php if ($cat || $st) { ?><a href="index.php" class="small align-self-center">Show all</a><?php } ?>
</div>
<form class="d-flex gap-2 mb-2"><?php if ($cat) { ?><input type="hidden" name="cat" value="<?= $cat; ?>"><?php } ?>
    <select name="status" class="form-select form-select-sm" style="max-width:190px" onchange="this.form.submit()"><option value="">Every status</option>
    <?php foreach (['Draft', 'Pending' => 'Waiting for approval', 'Published' => 'Published / approved', 'Rejected', 'Inactive' => 'Inactive / withdrawn'] as $k => $v) { $k = is_int($k) ? $v : $k; ?><option value="<?= $k; ?>" <?= $st === $k ? 'selected' : ''; ?>><?= $v; ?></option><?php } ?></select>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm sdc-tbl mb-0">
<thead><tr><th>Card</th><th>Category</th><th>Status</th><th>Label / priority</th><th>Dates</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php foreach ($rows as $r) {
    $r['d'] = json_decode((string) $r['details'], true) ?: [];
    $name = $r['category'] === 'employee' ? ($r['emp'] ?: '?') . ' · ' . ($r['month'] ? date('M Y', strtotime($r['month'] . '-01')) : '') : ($r['product_name'] && in_array($r['category'], ['product', 'service'], true) ? $r['product_name'] : ($r['title'] ?: mb_substr((string) $r['body'], 0, 70)));
    $warn = [];
    if (in_array($r['category'], ['product', 'service'], true) && $r['product_id'] && !(int) $r['p_active']) { $warn[] = 'the item is not active in RM Offerings'; }
    if ($r['category'] === 'product' && $r['product_id'] && (float) $r['quantity'] <= 0) { $warn[] = sdc_get($conn, 'stock_policy') === 'exclude' ? 'out of stock: hidden (stock policy)' : 'out of stock: shown labelled'; }
    if ($r['end_date'] && $r['end_date'] < $today) { $warn[] = 'expired'; }
    if ($r['start_date'] && $r['start_date'] > $today) { $warn[] = 'starts ' . date('d M', strtotime($r['start_date'])); }
    $needs = in_array($r['category'], SDC_NEEDS_APPROVAL, true);
?>
<tr><td><a href="edit.php?id=<?= (int) $r['id']; ?>" class="text-decoration-none fw-semibold"><?= sdc_e(mb_strimwidth((string) $name, 0, 80, '…')); ?></a>
        <?php if ($warn) { ?><div class="small text-danger"><?= sdc_e(implode(' · ', $warn)); ?></div><?php } ?>
        <?php if ($r['decision_reason'] && in_array($r['status'], ['Rejected', 'Inactive'], true)) { ?><div class="small text-muted">Reason: <?= sdc_e($r['decision_reason']); ?></div><?php } ?></td>
    <td class="small"><i class="bi <?= SDC_CATS[$r['category']][1]; ?>" style="color:<?= SDC_CATS[$r['category']][2]; ?>"></i> <?= sdc_e(SDC_CATS[$r['category']][0]); ?></td>
    <td><?= sdc_badge($r); ?></td>
    <td><form method="post" class="d-flex gap-1"><?= sdc_csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $r['id']; ?>"><input type="hidden" name="act" value="label"><input type="hidden" name="back" value="<?= sdc_e($back); ?>">
        <select name="label" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()"><?php foreach (SDC_LABELS as $l) { ?><option <?= $r['label'] === $l ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select>
        <select name="priority" class="form-select form-select-sm" style="width:auto" title="Priority 1 (low) – 5 (high)" onchange="this.form.submit()"><?php for ($i = 1; $i <= 5; $i++) { ?><option value="<?= $i; ?>" <?= (int) $r['priority'] === $i ? 'selected' : ''; ?>>P<?= $i; ?></option><?php } ?></select></form></td>
    <td class="small text-nowrap"><?= $r['start_date'] ? date('d M', strtotime($r['start_date'])) . ' – ' . ($r['end_date'] ? date('d M Y', strtotime($r['end_date'])) : '…') : '<span class="text-muted">always</span>'; ?></td>
    <td class="text-end text-nowrap">
        <a href="preview.php?id=<?= (int) $r['id']; ?>" class="btn btn-outline-secondary btn-sm py-0" title="Preview"><i class="bi bi-eye"></i></a>
        <form method="post" class="d-inline"><?= sdc_csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $r['id']; ?>"><input type="hidden" name="back" value="<?= sdc_e($back); ?>">
        <?php if (!$needs && in_array($r['status'], ['Draft', 'Inactive'], true)) { ?><button name="act" value="publish" class="btn btn-success btn-sm py-0">Publish</button><?php } ?>
        <?php if ($needs && in_array($r['status'], ['Draft', 'Rejected', 'Inactive'], true)) { ?><button name="act" value="submit" class="btn btn-primary btn-sm py-0">Send for approval</button><?php } ?>
        <?php if (in_array($r['status'], ['Inactive', 'Rejected'], true)) { ?><button name="act" value="reopen" class="btn btn-outline-secondary btn-sm py-0">Edit again</button><?php } ?>
        <?php if (in_array($r['status'], ['Draft', 'Rejected'], true) && !$r['approved_at']) { ?><button name="act" value="delete" class="btn btn-outline-danger btn-sm py-0" onclick="return confirm('Delete this card?')"><i class="bi bi-trash"></i></button><?php } ?>
        </form>
        <?php if (in_array($r['status'], ['Published', 'Pending'], true)) { ?>
        <form method="post" class="d-inline" onsubmit="var r=prompt('Why should it no longer appear?');if(!r){return false;}this.reason.value=r;"><?= sdc_csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $r['id']; ?>"><input type="hidden" name="act" value="deactivate"><input type="hidden" name="reason"><input type="hidden" name="back" value="<?= sdc_e($back); ?>">
            <button class="btn btn-outline-danger btn-sm py-0"><?= $r['category'] === 'announcement' ? 'Withdraw' : 'Deactivate'; ?></button></form>
        <?php } ?>
    </td></tr>
<?php } ?>
<?php if (!$rows) { ?><tr><td colspan="6" class="text-center text-muted py-5">No card here yet.</td></tr><?php } ?>
</tbody></table></div></div>
<p class="small text-muted mt-2 mb-0">Products in stock and active services also appear automatically with their live details (see <a href="catalogue.php">Products &amp; services</a>); a card only adds uses, tips and an image. <b>Excluded</b> content never appears.</p>
<?php include __DIR__ . '/../../includes/footer.php'; ?>