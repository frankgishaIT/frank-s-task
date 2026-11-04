<?php
/**
 * NEW FILE: modules/portal/ebm.php — EBM receipt requests from customers: take in hand, attach the issued EBM receipt
 * (the customer downloads it from their account), or reject with the reason. Notifies the customer at each step.
 */
require __DIR__ . '/portal_common.php';
require_once __DIR__ . '/../../includes/ebm_helpers.php';
require_once __DIR__ . '/../../includes/evidence_store.php';
require_once __DIR__ . '/../../includes/cl_helpers.php'; // portal_notify_customer()
ebm_ensure($conn); cl_ensure($conn);
$canEdit = access_can('portal.edit', $conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0); $act = (string) ($_POST['action'] ?? ''); $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM ebm_requests WHERE id = $id"));
    try {
        if (!$canEdit) { throw new RuntimeException('You may view EBM requests but not process them.'); }
        if (!$r || in_array($r['status'], ['Issued', 'Rejected'], true)) { throw new RuntimeException('This request was already completed.'); }
        $ref = ebm_ref($id); $inv = portal_sale_number((int) $r['sale_id']);
        if ($act === 'take') {
            $s = mysqli_prepare($conn, "UPDATE ebm_requests SET status = 'In progress', processed_by = ?, updated_at = NOW() WHERE id = ? AND status = 'Submitted'");
            mysqli_stmt_bind_param($s, 'ii', $me, $id); mysqli_stmt_execute($s);
            portal_notify_customer($conn, (int) $r['customer_id'], 'Your EBM receipt request is being processed (' . $ref . ')', 'Invoice ' . $inv, 'ebm.php', false);
            $msg = $ref . ' is in progress.';
        } elseif ($act === 'issue') {
            $file = evidence_save($conn, $_FILES['receipt'] ?? null, $me, ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'], 5 * 1024 * 1024);
            if (!$file) { throw new RuntimeException('Attach the issued EBM receipt (PDF or photo).'); }
            $name = mb_substr(basename((string) $_FILES['receipt']['name']), 0, 255);
            $s = mysqli_prepare($conn, "UPDATE ebm_requests SET status = 'Issued', receipt_ref = ?, receipt_name = ?, staff_note = ?, processed_by = ?, processed_at = NOW(), updated_at = NOW() WHERE id = ? AND status IN ('Submitted', 'In progress')");
            mysqli_stmt_bind_param($s, 'sssii', $file, $name, $note, $me, $id); mysqli_stmt_execute($s);
            if (mysqli_stmt_affected_rows($s) !== 1) { throw new RuntimeException('This request was already completed.'); }
            portal_notify_customer($conn, (int) $r['customer_id'], 'Your EBM receipt is ready (' . $inv . ')', 'Download it from Request EBM Receipt in your portal.', 'ebm.php');
            portal_log($conn, (int) $r['customer_id'], 'ebm_issued', $ref . ' by ' . ($_SESSION['user_name'] ?? 'staff'));
            $msg = 'EBM receipt attached. The customer was notified.';
        } elseif ($act === 'reject') {
            if (mb_strlen($note) < 5) { throw new RuntimeException('Write the reason (the customer sees it).'); }
            $s = mysqli_prepare($conn, "UPDATE ebm_requests SET status = 'Rejected', staff_note = ?, processed_by = ?, processed_at = NOW(), updated_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($s, 'sii', $note, $me, $id); mysqli_stmt_execute($s);
            portal_notify_customer($conn, (int) $r['customer_id'], 'EBM receipt request not processed (' . $ref . ')', $note, 'ebm.php');
            portal_log($conn, (int) $r['customer_id'], 'ebm_rejected', $ref . ': ' . $note);
            $msg = 'Request rejected. The customer can correct it and request again.';
        } else { throw new RuntimeException('Unknown action.'); }
        header('Location: ebm.php?success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { header('Location: ebm.php?error=' . urlencode($ex->getMessage())); exit; }
}
$st = in_array($_GET['status'] ?? '', EBM_STATUSES, true) ? $_GET['status'] : '';
$where = $st !== '' ? "e.status = '" . mysqli_real_escape_string($conn, $st) . "'" : "e.status IN ('Submitted', 'In progress')";
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT e.*, c.name AS customer, s.total_amount, s.sale_date, u.names AS staff FROM ebm_requests e JOIN customers c ON c.id = e.customer_id
    LEFT JOIN sales s ON s.id = e.sale_id LEFT JOIN users u ON u.id = e.processed_by WHERE $where ORDER BY e.id DESC LIMIT 300"), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
portal_staff_tabs('ebm.php'); portal_staff_alerts();
?>
<div class="btn-group btn-group-sm mb-3"><a href="ebm.php" class="btn <?= $st === '' ? 'btn-primary' : 'btn-outline-primary'; ?>">To process</a>
    <?php foreach (EBM_STATUSES as $x) { ?><a href="ebm.php?status=<?= urlencode($x); ?>" class="btn <?= $st === $x ? 'btn-primary' : 'btn-outline-primary'; ?>"><?= $x; ?></a><?php } ?></div>
<div class="small text-muted mb-2">RISE MOTIVE's TIN for customers' purchase codes: <strong><?= EBM_RM_TIN; ?></strong>.</div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Request</th><th>Customer</th><th>Invoice</th><th>EBM details</th><th>Status</th><th style="width:30%;"></th></tr>
    <?php if (!$rows) { ?><tr><td colspan="6" class="text-center text-muted py-4">Nothing here.</td></tr><?php } ?>
    <?php foreach ($rows as $r) { $id = (int) $r['id']; ?>
    <tr><td class="ps-3"><?= ebm_ref($id); ?><div class="small text-muted"><?= date('d M Y, H:i', strtotime($r['created_at'])); ?></div></td>
        <td><?= $e($r['customer']); ?></td>
        <td><a href="../sales/invoice.php?id=<?= (int) $r['sale_id']; ?>" target="_blank"><?= portal_sale_number((int) $r['sale_id']); ?></a><div class="small text-muted"><?= $r['total_amount'] !== null ? 'RWF ' . number_format((float) $r['total_amount']) . ' · ' . date('d M Y', strtotime($r['sale_date'])) : ''; ?></div></td>
        <td class="small"><strong><?= $e($r['full_name']); ?></strong><br><?= $r['has_tin'] ? 'TIN <strong>' . $e($r['tin']) . '</strong> · purchase code <strong>' . $e($r['purchase_code']) . '</strong>' : 'No TIN · phone ' . $e($r['phone']); ?></td>
        <td><span class="badge <?= ['Submitted' => 'bg-primary', 'In progress' => 'bg-primary', 'Issued' => 'bg-success', 'Rejected' => 'bg-danger'][$r['status']]; ?>"><?= $e($r['status']); ?></span><?= $r['staff'] ? '<div class="small text-muted">' . $e($r['staff']) . '</div>' : ''; ?></td>
        <td><?php if ($canEdit && in_array($r['status'], ['Submitted', 'In progress'], true)) { ?>
            <?php if ($r['status'] === 'Submitted') { ?><form method="POST" class="d-inline"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="take"><button class="btn btn-sm btn-outline-primary">Take in hand</button></form><?php } ?>
            <form method="POST" enctype="multipart/form-data" class="d-flex gap-1 mt-1"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="issue"><input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png" class="form-control form-control-sm" required><button class="btn btn-sm btn-success text-nowrap">Attach &amp; issue</button></form>
            <form method="POST" class="d-flex gap-1 mt-1"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="reject"><input name="note" class="form-control form-control-sm" placeholder="Reason to reject"><button class="btn btn-sm btn-outline-danger">Reject</button></form>
        <?php } elseif ($r['status'] === 'Issued') { ?><span class="small text-success">Issued <?= $r['processed_at'] ? date('d M Y', strtotime($r['processed_at'])) : ''; ?> · <?= $e($r['receipt_name']); ?></span>
        <?php } elseif ($r['status'] === 'Rejected') { ?><span class="small text-danger"><?= $e($r['staff_note']); ?></span><?php } ?></td></tr>
    <?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>