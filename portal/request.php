<?php
// NEW FILE: portal/request.php — one request of the signed-in customer, with its conversation.
require __DIR__ . '/portal_start.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$s = mysqli_prepare($conn, 'SELECT * FROM portal_requests WHERE id = ? AND customer_id = ?');
mysqli_stmt_bind_param($s, 'ii', $id, $cid);
mysqli_stmt_execute($s);
$req = $id ? mysqli_fetch_assoc(mysqli_stmt_get_result($s)) : null;
if (!$req) { header('Location: requests.php'); exit; }
$rid = (int) $req['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = trim((string) ($_POST['body'] ?? ''));
    if (!portal_csrf_ok()) { $error = 'Your session expired. Please try again.'; }
    elseif ($req['status'] === 'Closed') { $error = 'This request is closed. Please send a new request.'; }
    elseif ($body === '' || mb_strlen($body) > 3000) { $error = 'Write your message (3000 characters at most).'; }
    else {
        $m = mysqli_prepare($conn, 'INSERT INTO portal_request_messages (request_id, from_customer, body) VALUES (?, 1, ?)');
        mysqli_stmt_bind_param($m, 'is', $rid, $body);
        mysqli_stmt_execute($m);
        mysqli_query($conn, "UPDATE portal_requests SET updated_at = NOW(), status = IF(status = 'Resolved', 'Open', status) WHERE id = $rid");
        portal_notify_staff($conn, 'Customer replied: ' . portal_request_number($rid), $me['name'] . ': ' . mb_substr($body, 0, 120));
        header('Location: request.php?id=' . $rid); exit;
    }
}
$msgs = mysqli_fetch_all(mysqli_query($conn, "SELECT m.*, u.names FROM portal_request_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.request_id = $rid ORDER BY m.id"), MYSQLI_ASSOC);
portal_header(portal_request_number($rid), $me);
portal_alerts();
?>
<a href="requests.php" class="small">&larr; My requests</a>
<h1 class="h5 mt-1"><?= portal_request_number($rid); ?> · <?= portal_e($req['subject']); ?></h1>
<div class="small text-muted mb-3"><?= portal_e($req['type']); ?> · sent <?= date('d M Y, H:i', strtotime($req['created_at'])); ?> · status <strong><?= portal_e($req['status']); ?></strong></div>
<?php foreach ($msgs as $m) { ?>
<div class="card mb-2" style="<?= $m['from_customer'] ? 'margin-left:10%;' : 'margin-right:10%; border-left:3px solid #1E2FE0 !important;'; ?>"><div class="card-body py-2">
    <div class="small text-muted mb-1"><?= $m['from_customer'] ? 'You' : 'RISE MOTIVE' . ($m['names'] ? ' (' . portal_e($m['names']) . ')' : ''); ?> · <?= date('d M Y, H:i', strtotime($m['created_at'])); ?></div>
    <div style="white-space:pre-wrap;"><?= portal_e($m['body']); ?></div></div></div>
<?php } ?>
<?php if ($req['status'] !== 'Closed') { ?>
<form method="POST" class="card mt-3"><div class="card-body"><?= portal_csrf_field(); ?><input type="hidden" name="id" value="<?= $rid; ?>">
    <?php if (isset($error)) { ?><div class="alert alert-danger small"><?= portal_e($error); ?></div><?php } ?>
    <textarea name="body" class="form-control mb-2" rows="3" maxlength="3000" required placeholder="Add a message"></textarea>
    <button class="btn btn-primary btn-sm">Send</button></div></form>
<?php } ?>
<?php portal_footer();