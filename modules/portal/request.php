<?php
// NEW FILE: modules/portal/request.php — one customer request: the conversation, answer, status.
require __DIR__ . '/portal_common.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$req = $id ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT r.*, c.name, c.phone FROM portal_requests r JOIN customers c ON c.id = r.customer_id WHERE r.id = ' . (int) $id)) : null;
if (!$req) { header('Location: index.php?error=' . urlencode('Request not found.')); exit; }
$rid = (int) $req['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!access_can('portal.edit', $conn)) { header('Location: request.php?id=' . $rid . '&error=' . urlencode('You may read requests but not answer them.')); exit; }
    $body = trim((string) ($_POST['body'] ?? ''));
    $status = in_array($_POST['status'] ?? '', ['Open', 'In progress', 'Resolved', 'Closed'], true) ? $_POST['status'] : $req['status'];
    if ($body !== '') {
        $uid = (int) current_user_id();
        $m = mysqli_prepare($conn, 'INSERT INTO portal_request_messages (request_id, from_customer, user_id, body) VALUES (?, 0, ?, ?)');
        mysqli_stmt_bind_param($m, 'iis', $rid, $uid, $body);
        mysqli_stmt_execute($m);
        if (portal_setting($conn, 'sms_on_reply', '1') === '1' && is_file(__DIR__ . '/../../includes/sms_helpers.php')) {
            require_once __DIR__ . '/../../includes/sms_helpers.php';
            try { sms_send($conn, (string) $req['phone'], 'RISE MOTIVE answered your request ' . portal_request_number($rid) . '. Sign in to the customer portal to read it.', 'portal_reply', 'portal_request', $rid, $uid); } catch (Throwable $x) {}
        }
    }
    $s = mysqli_prepare($conn, 'UPDATE portal_requests SET status = ?, updated_at = NOW() WHERE id = ?');
    mysqli_stmt_bind_param($s, 'si', $status, $rid);
    mysqli_stmt_execute($s);
    portal_log($conn, (int) $req['customer_id'], 'staff_answer', portal_request_number($rid) . ' → ' . $status . ' by ' . ($_SESSION['user_name'] ?? 'staff'));
    header('Location: request.php?id=' . $rid . '&success=' . urlencode($body !== '' ? 'Answer sent.' : 'Status saved.')); exit;
}
$msgs = mysqli_fetch_all(mysqli_query($conn, "SELECT m.*, u.names FROM portal_request_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.request_id = $rid ORDER BY m.id"), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
portal_staff_tabs('index.php'); portal_staff_alerts();
?>
<h5 class="mb-1"><?= portal_request_number($rid); ?> · <?= $e($req['subject']); ?></h5>
<div class="small text-muted mb-3"><?= $e($req['type']); ?> · <?= $e($req['name']); ?> (<?= portal_customer_code((int) $req['customer_id']); ?>, <?= $e($req['phone']); ?>) · status <strong><?= $e($req['status']); ?></strong>
    · <a href="../customers/reports.php?report=statement&period=this_year&customer_id=<?= (int) $req['customer_id']; ?>">Customer statement</a></div>
<?php foreach ($msgs as $m) { ?>
<div class="card border-0 shadow-sm mb-2" style="<?= $m['from_customer'] ? 'margin-right:10%;' : 'margin-left:10%; border-left:3px solid #1E2FE0 !important;'; ?>"><div class="card-body py-2 small">
    <div class="text-muted mb-1"><?= $m['from_customer'] ? $e($req['name']) : 'RISE MOTIVE · ' . $e($m['names']); ?> · <?= date('d M Y, H:i', strtotime($m['created_at'])); ?></div>
    <div style="white-space:pre-wrap;"><?= $e($m['body']); ?></div></div></div>
<?php } ?>
<?php if (access_can('portal.edit', $conn)) { ?>
<form method="POST" class="card border-0 shadow-sm mt-3"><div class="card-body"><input type="hidden" name="id" value="<?= $rid; ?>">
    <textarea name="body" class="form-control mb-2" rows="3" maxlength="3000" placeholder="Your answer to the customer (they get an SMS that an answer is waiting)"></textarea>
    <div class="d-flex gap-2 align-items-center"><select name="status" class="form-select form-select-sm" style="max-width:200px;" data-no-search>
        <?php foreach (['Open', 'In progress', 'Resolved', 'Closed'] as $st) { ?><option <?= $req['status'] === $st ? 'selected' : ''; ?>><?= $st; ?></option><?php } ?></select>
        <button class="btn btn-primary btn-sm">Send / save</button></div>
</div></form>
<?php } ?>
<?php include '../../includes/footer.php'; ?>