<?php
require '../../config/db.php';
require_role(['Admin', 'Manager']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=Invalid purchase order.'); exit; }

$statement = mysqli_prepare($conn, 'SELECT * FROM purchase_orders WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$po = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$po) { header('Location: index.php?error=Purchase order not found.'); exit; }

if (isset($_POST['confirm'])) {
    if (!in_array($po['status'], ['Draft', 'Ordered'], true)) {
        header('Location: view.php?id=' . $id . '&error=' . urlencode('Only Draft or Ordered purchase orders can be cancelled.'));
        exit;
    }
    $reason = trim($_POST['reason'] ?? '');
    $userId = current_user_id();
    $update = mysqli_prepare($conn, "UPDATE purchase_orders SET status = 'Cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?");
    mysqli_stmt_bind_param($update, 'isi', $userId, $reason, $id);
    mysqli_stmt_execute($update);

    header('Location: view.php?id=' . $id . '&success=' . urlencode('Purchase Order cancelled.'));
    exit;
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
$modal_icon = 'bi-x-circle'; $modal_title = 'Cancel Purchase Order'; $modal_subtitle = 'This purchase order will not be received.';
?>
<div class="rm-modal-backdrop"><div class="rm-modal">
    <?php include '../../includes/model_header.php'; ?>
    <div class="rm-modal-body">
        <?php if (!in_array($po['status'], ['Draft', 'Ordered'], true)) { ?>
            <div class="alert alert-secondary" style="border-radius:10px;">Only Draft or Ordered purchase orders can be cancelled. This one is "<?= htmlspecialchars($po['status'], ENT_QUOTES, 'UTF-8'); ?>".</div>
            <a href="view.php?id=<?= (int) $id; ?>" class="btn btn-light rm-btn-light">Back</a>
        <?php } else { ?>
        <form method="POST">
            <label class="form-label small fw-semibold text-muted">Reason (optional)</label>
            <textarea name="reason" class="form-control rm-input mb-4" rows="3"></textarea>
            <div class="d-grid gap-2 d-md-flex justify-content-end">
                <button class="btn btn-danger rm-btn-primary" type="submit" name="confirm" value="1"><i class="bi bi-x-circle-fill me-2"></i>Confirm Cancel</button>
                <a href="view.php?id=<?= (int) $id; ?>" class="btn btn-light rm-btn-light">Back</a>
            </div>
        </form>
        <?php } ?>
    </div>
</div></div>
<?php include '../../includes/footer.php'; ?>