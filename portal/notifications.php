<?php
// NEW FILE: portal/notifications.php — the customer's notifications (loans, investments).
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/cl_helpers.php';
cl_ensure($conn);
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM portal_notifications WHERE customer_id = $cid ORDER BY id DESC LIMIT 100"), MYSQLI_ASSOC);
mysqli_query($conn, "UPDATE portal_notifications SET read_at = NOW() WHERE customer_id = $cid AND read_at IS NULL");
$GLOBALS['PORTAL_UNREAD'] = 0;
portal_header('Notifications', $me);
?>
<h1 class="h5 mb-3">Notifications</h1>
<div class="card"><div class="card-body">
    <?php if (!$rows) { ?><div class="text-muted small">No notifications yet.</div><?php } ?>
    <?php foreach ($rows as $n) { ?><div class="border-bottom py-2 <?= $n['read_at'] ? '' : 'fw-semibold'; ?>">
        <div class="small text-muted"><?= date('d M Y, H:i', strtotime($n['created_at'])); ?></div>
        <div><?= $n['link'] ? '<a href="' . portal_e($n['link']) . '">' . portal_e($n['title']) . '</a>' : portal_e($n['title']); ?></div>
        <?php if ($n['body']) { ?><div class="small fw-normal"><?= portal_e($n['body']); ?></div><?php } ?></div><?php } ?>
</div></div>
<?php portal_footer();