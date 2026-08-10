<?php
require '../../config/db.php';
require_role(['Manager', 'Admin']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid leave request selected.'); exit; }

$statement = mysqli_prepare($conn, 'SELECT leave_requests.*, users.names AS employee_name FROM leave_requests INNER JOIN users ON leave_requests.user_id = users.id WHERE leave_requests.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$leave = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$leave) { header('Location: index.php?success=Leave request not found.'); exit; }

// Work out which stage this request is at, and whether the current user may act on it.
$stage = null;
if ($leave['status'] === 'Pending') {
    $stage = 'manager'; // Manager or Admin may act here
} elseif ($leave['status'] === 'Manager Approved') {
    $stage = 'hr'; // Admin (HR) only
}

$canAct = ($stage === 'manager' && in_array(current_user_role(), ['Manager', 'Admin'], true))
    || ($stage === 'hr' && current_user_role() === 'Admin');

if ($canAct && isset($_POST['decision'])) {
    $decision = $_POST['decision'];
    $comment = trim($_POST['comment'] ?? '');

    if (!in_array($decision, ['approve', 'reject'], true)) {
        $error = 'Invalid decision.';
    } else {
        $userId = current_user_id();

        if ($stage === 'manager') {
            $newStatus = $decision === 'approve' ? 'Manager Approved' : 'Rejected';
            $update = mysqli_prepare($conn, 'UPDATE leave_requests SET status = ?, manager_id = ?, manager_comment = ?, manager_acted_at = NOW() WHERE id = ?');
            mysqli_stmt_bind_param($update, 'sisi', $newStatus, $userId, $comment, $id);
        } else {
            $newStatus = $decision === 'approve' ? 'Approved' : 'Rejected';
            $update = mysqli_prepare($conn, 'UPDATE leave_requests SET status = ?, hr_id = ?, hr_comment = ?, hr_acted_at = NOW() WHERE id = ?');
            mysqli_stmt_bind_param($update, 'sisi', $newStatus, $userId, $comment, $id);
        }

        mysqli_stmt_execute($update);
        header('Location: index.php?success=Leave request updated successfully.');
        exit;
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-calendar-check';
$modal_title = 'Review Leave Request';
$modal_subtitle = htmlspecialchars($leave['employee_name'], ENT_QUOTES, 'UTF-8') . "'s request";
?>

<div class="rm-modal-backdrop">
    <div class="rm-modal">
        <?php include '../../includes/model_header.php'; ?>

        <div class="rm-modal-body">
            <?php if (isset($error)) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php } ?>

            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">Employee</label>
                <input class="form-control rm-input" value="<?= htmlspecialchars($leave['employee_name'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Leave Type</label>
                    <input class="form-control rm-input" value="<?= htmlspecialchars($leave['leave_type'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Dates</label>
                    <input class="form-control rm-input" value="<?= htmlspecialchars($leave['start_date'], ENT_QUOTES, 'UTF-8'); ?> &rarr; <?= htmlspecialchars($leave['end_date'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">Reason</label>
                <textarea class="form-control rm-input" rows="3" style="height:auto;" disabled><?= htmlspecialchars($leave['reason'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <?php if ($leave['manager_id']) { ?>
            <div class="mb-3 p-3" style="background:#F8FAFC; border-radius:12px;">
                <div class="small fw-semibold text-muted mb-1">Supervisor decision: <?= htmlspecialchars($leave['status'] === 'Rejected' && !$leave['hr_id'] ? 'Rejected' : ($leave['status'] === 'Pending' ? 'Pending' : 'Approved'), ENT_QUOTES, 'UTF-8'); ?></div>
                <?php if ($leave['manager_comment']) { ?><div class="small text-muted"><?= htmlspecialchars($leave['manager_comment'], ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
            </div>
            <?php } ?>

            <?php if ($leave['hr_id']) { ?>
            <div class="mb-3 p-3" style="background:#F8FAFC; border-radius:12px;">
                <div class="small fw-semibold text-muted mb-1">HR decision: <?= htmlspecialchars($leave['status'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php if ($leave['hr_comment']) { ?><div class="small text-muted"><?= htmlspecialchars($leave['hr_comment'], ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
            </div>
            <?php } ?>

            <?php if ($canAct) { ?>
            <form method="POST">
                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Your Comment <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea name="comment" class="form-control rm-input" rows="3" style="height:auto;"></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="btn btn-success rm-btn-primary" type="submit" name="decision" value="approve">
                        <i class="bi bi-check-circle-fill me-2"></i><?= $stage === 'manager' ? 'Approve' : 'Final Approve'; ?>
                    </button>
                    <button class="btn btn-danger rm-btn-primary" type="submit" name="decision" value="reject">
                        <i class="bi bi-x-circle-fill me-2"></i>Reject
                    </button>
                    <a href="index.php" class="btn btn-light rm-btn-light">Cancel</a>
                </div>
            </form>
            <?php } else { ?>
            <div class="alert alert-secondary" style="border-radius:10px;">
                This request is <?= $leave['status'] === 'Pending' || $leave['status'] === 'Manager Approved' ? 'awaiting a decision from someone else' : 'already ' . strtolower($leave['status']); ?>.
            </div>
            <a href="index.php" class="btn btn-light rm-btn-light">Back</a>
            <?php } ?>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>
