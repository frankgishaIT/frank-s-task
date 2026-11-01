<?php
// NEW FILE: modules/portal/index.php — customer requests (staff side).
require __DIR__ . '/portal_common.php';
$status = in_array($_GET['status'] ?? '', ['Open', 'In progress', 'Resolved', 'Closed'], true) ? $_GET['status'] : 'active';
$where = $status === 'active' ? "r.status IN ('Open', 'In progress')" : "r.status = '" . mysqli_real_escape_string($conn, $status) . "'";
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT r.*, c.name, (SELECT COUNT(*) FROM portal_request_messages m WHERE m.request_id = r.id) AS msgs,
    (SELECT from_customer FROM portal_request_messages m WHERE m.request_id = r.id ORDER BY m.id DESC LIMIT 1) AS last_from_customer
    FROM portal_requests r JOIN customers c ON c.id = r.customer_id WHERE $where ORDER BY r.updated_at DESC, r.id DESC LIMIT 300"), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
portal_staff_tabs('index.php'); portal_staff_alerts();
?>
<div class="btn-group btn-group-sm mb-3">
    <?php foreach (['active' => 'Open & in progress', 'Resolved' => 'Resolved', 'Closed' => 'Closed'] as $k => $l) { ?><a href="index.php?status=<?= urlencode($k); ?>" class="btn <?= $status === $k ? 'btn-primary' : 'btn-outline-primary'; ?>"><?= $l; ?></a><?php } ?>
</div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover bg-white mb-0 align-middle" style="font-size:13px;">
    <tr><th>Number</th><th>Customer</th><th>Type / subject</th><th>Status</th><th>Updated</th><th></th></tr>
    <?php if (!$rows) { ?><tr><td colspan="6" class="text-center text-muted py-4">No requests.</td></tr><?php } ?>
    <?php foreach ($rows as $r) { ?>
    <tr<?= $r['last_from_customer'] ? ' style="background:#EEF3FF;"' : ''; ?>><td><?= portal_request_number((int) $r['id']); ?></td>
        <td><?= $e($r['name']); ?><div class="small text-muted"><?= portal_customer_code((int) $r['customer_id']); ?></div></td>
        <td><?= $e($r['subject']); ?><div class="small text-muted"><?= $e($r['type']); ?> · <?= (int) $r['msgs']; ?> message(s)<?= $r['last_from_customer'] ? ' · <strong>waiting for an answer</strong>' : ''; ?></div></td>
        <td><span class="badge <?= in_array($r['status'], ['Resolved', 'Closed'], true) ? 'bg-success' : 'bg-primary'; ?>"><?= $e($r['status']); ?></span></td>
        <td class="text-nowrap small"><?= date('d M Y, H:i', strtotime($r['updated_at'] ?? $r['created_at'])); ?></td>
        <td><a href="request.php?id=<?= (int) $r['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">Open</a></td></tr>
    <?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>