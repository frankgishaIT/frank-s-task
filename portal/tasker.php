<?php
// NEW FILE: portal/tasker.php — RM Tasker: my tasks (only this Tasker's own assignments).
require_once __DIR__ . '/../includes/portal_helpers.php';
require_once __DIR__ . '/../includes/portal_layout.php';
$conn = portal_boot();
require_once __DIR__ . '/../includes/dl_portal.php';
$t = dlp_require($conn);
$pid = (int) $t['id'];
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT d.id, d.status, d.response, d.deadline, d.pay_exp, d.pay_due, d.completed_at, d.verified_at, d.urgent, p.product_name
    FROM dl_delegations d LEFT JOIN products p ON p.id = d.product_id WHERE d.party_id = $pid AND d.status NOT IN ('Draft', 'Pending Approval') ORDER BY
    CASE WHEN d.status = 'Awaiting Tasker Acceptance' THEN 0 WHEN d.status IN ('Accepted', 'In Progress') THEN 1 WHEN d.status = 'Completed – Awaiting Verification' THEN 2 ELSE 3 END, d.deadline DESC LIMIT 300"), MYSQLI_ASSOC);
$open = array_values(array_filter($rows, function ($r) { return in_array($r['status'], ['Awaiting Tasker Acceptance', 'Accepted', 'In Progress', 'On Hold', 'Completed – Awaiting Verification'], true); }));
$past = array_values(array_filter($rows, function ($r) use ($open) { return !in_array($r, $open, true); }));
dlp_header('My Tasks', $t);
portal_alerts();
$e = 'portal_e';
$list = function (array $rs) use ($e) {
    foreach ($rs as $r) {
        $late = in_array($r['status'], ['Awaiting Tasker Acceptance', 'Accepted', 'In Progress'], true) && strtotime($r['deadline']) < time();
        echo '<a href="tasker_task.php?ref=' . dl_ref((int) $r['id']) . '" class="list-group-item list-group-item-action"><div class="d-flex justify-content-between gap-2 flex-wrap"><div><div class="fw-semibold">' . $e($r['product_name']) . ($r['urgent'] ? ' <span class="badge bg-danger">Urgent</span>' : '') . '</div>'
            . '<div class="small text-muted">' . dl_ref((int) $r['id']) . ' · ' . $e(dlp_status($r)) . '</div></div><div class="text-end small"><div class="' . ($late ? 'text-danger fw-bold' : '') . '">Deadline ' . $e(dl_deadline_text($r['deadline'])) . '</div>'
            . '<div>' . ($r['verified_at'] ? 'Payment due' : 'Expected payment') . ': <b>RWF ' . number_format((float) ($r['pay_due'] ?? $r['pay_exp'])) . '</b></div></div></div></a>';
    }
};
?>
<h1 class="mb-1">My Tasks</h1>
<p class="text-muted mb-3">Work RISE MOTIVE has given you. Answer new requests quickly: if we cannot reach you, a RISE MOTIVE staff member may call you or write on WhatsApp so the customer is not kept waiting.</p>
<h2>Open</h2>
<div class="list-group mb-4"><?php $list($open); if (!$open) { echo '<div class="list-group-item text-muted">No open task.</div>'; } ?></div>
<?php if ($past) { ?><h2>Earlier</h2><div class="list-group mb-4"><?php $list($past); ?></div><?php } ?>
<?php portal_footer();