<?php
// NEW FILE: portal/requests.php — the customer's requests, and a new request.
require __DIR__ . '/portal_start.php';
const PORTAL_REQUEST_TYPES = ['Missing invoice or receipt', 'Account statement', 'Copy of an agreement', 'Complaint', 'Service request', 'Question about a transaction', 'Other'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = (string) ($_POST['type'] ?? ''); $subject = mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 200); $body = trim((string) ($_POST['body'] ?? ''));
    $today = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM portal_requests WHERE customer_id = $cid AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)"))[0];
    if (!portal_csrf_ok()) { $error = 'Your session expired. Please try again.'; }
    elseif (!in_array($type, PORTAL_REQUEST_TYPES, true) || $subject === '' || mb_strlen($body) < 5) { $error = 'Choose the type, and write a subject and your message.'; }
    elseif (mb_strlen($body) > 3000) { $error = 'The message is too long (3000 characters at most).'; }
    elseif ($today >= 10) { $error = 'You have sent many requests today. Please wait for an answer, or call RISE MOTIVE.'; }
    else {
        mysqli_begin_transaction($conn);
        $s = mysqli_prepare($conn, "INSERT INTO portal_requests (customer_id, type, subject, updated_at) VALUES (?, ?, ?, NOW())");
        mysqli_stmt_bind_param($s, 'iss', $cid, $type, $subject);
        mysqli_stmt_execute($s);
        $rid = (int) mysqli_insert_id($conn);
        $m = mysqli_prepare($conn, 'INSERT INTO portal_request_messages (request_id, from_customer, body) VALUES (?, 1, ?)');
        mysqli_stmt_bind_param($m, 'is', $rid, $body);
        mysqli_stmt_execute($m);
        mysqli_commit($conn);
        portal_log($conn, $cid, 'request_sent', portal_request_number($rid) . ' ' . $type);
        portal_notify_staff($conn, 'Customer request ' . portal_request_number($rid), $me['name'] . ' (' . portal_customer_code($cid) . '): ' . $type . ' — ' . $subject);
        header('Location: request.php?id=' . $rid . '&success=' . urlencode('Request ' . portal_request_number($rid) . ' sent. You will see the answer here.')); exit;
    }
}
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM portal_requests WHERE customer_id = $cid ORDER BY id DESC"), MYSQLI_ASSOC);
portal_header('Requests', $me);
?>
<div class="row g-3">
<div class="col-lg-5"><div class="card"><div class="card-body">
    <h1 class="h6">New request</h1>
    <?php if (isset($error)) { ?><div class="alert alert-danger small"><?= portal_e($error); ?></div><?php } ?>
    <form method="POST"><?= portal_csrf_field(); ?>
        <select name="type" class="form-select mb-2" required><option value="">What do you need?</option>
            <?php foreach (PORTAL_REQUEST_TYPES as $t) { ?><option <?= ($_POST['type'] ?? '') === $t ? 'selected' : ''; ?>><?= portal_e($t); ?></option><?php } ?></select>
        <input name="subject" class="form-control mb-2" maxlength="200" required placeholder="Subject (e.g. invoice number)" value="<?= portal_e($_POST['subject'] ?? ''); ?>">
        <textarea name="body" class="form-control mb-2" rows="4" maxlength="3000" required placeholder="Your message"><?= portal_e($_POST['body'] ?? ''); ?></textarea>
        <button class="btn btn-primary w-100">Send request</button></form>
</div></div></div>
<div class="col-lg-7"><div class="card"><div class="card-body"><h2 class="h6">My requests</h2><div class="table-responsive"><table class="table mb-0 align-middle">
    <tr><th>Number</th><th>Subject</th><th>Status</th><th>Updated</th></tr>
    <?php if (!$rows) { ?><tr><td colspan="4" class="text-muted">No requests yet.</td></tr><?php } ?>
    <?php foreach ($rows as $r) { ?><tr><td><a href="request.php?id=<?= (int) $r['id']; ?>"><?= portal_request_number((int) $r['id']); ?></a></td><td><?= portal_e($r['subject']); ?><div class="small text-muted"><?= portal_e($r['type']); ?></div></td>
        <td><span class="badge <?= in_array($r['status'], ['Resolved', 'Closed'], true) ? 'bg-success' : 'bg-primary'; ?>"><?= portal_e($r['status']); ?></span></td><td class="small"><?= date('d M Y', strtotime($r['updated_at'] ?? $r['created_at'])); ?></td></tr><?php } ?>
</table></div></div></div></div>
</div>
<?php portal_footer();