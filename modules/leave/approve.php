<?php
require '../../config/db.php';
require_once '../../includes/notification_helper.php'; // NEW: tell the employee the decision
require_role(['Manager', 'Admin']);

// CHANGED: problems are shown as errors (they were shown as success messages), and messages are encoded.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid leave request selected.')); exit; }

$statement = mysqli_prepare($conn, 'SELECT leave_requests.*, users.names AS employee_name FROM leave_requests INNER JOIN users ON leave_requests.user_id = users.id WHERE leave_requests.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$leave = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$leave) { header('Location: index.php?error=' . urlencode('Leave request not found.')); exit; }

$userId = (int) current_user_id();
$isOwnRequest = (int) $leave['user_id'] === $userId;

// Work out which stage this request is at, and whether the current user may act on it.
$stage = null;
if ($leave['status'] === 'Pending') {
    $stage = 'manager'; // Manager or Admin may act here
} elseif ($leave['status'] === 'Manager Approved') {
    $stage = 'hr'; // Admin (HR) only
}

// CHANGED: nobody decides their OWN leave request.
$canAct = !$isOwnRequest && (($stage === 'manager' && in_array(current_user_role(), ['Manager', 'Admin'], true))
    || ($stage === 'hr' && current_user_role() === 'Admin'));

if ($canAct && isset($_POST['decision'])) {
    $decision = $_POST['decision'];
    $comment = mb_substr(trim($_POST['comment'] ?? ''), 0, 1000);

    if (!in_array($decision, ['approve', 'reject'], true)) {
        $error = 'Invalid decision.';
    } else {
        // CHANGED: the update only succeeds while the request is STILL at the stage this person saw,
        // so two people deciding at the same moment can never overwrite each other.
        $expected = $stage === 'manager' ? 'Pending' : 'Manager Approved';
        if ($stage === 'manager') {
            $newStatus = $decision === 'approve' ? 'Manager Approved' : 'Rejected';
            $update = mysqli_prepare($conn, 'UPDATE leave_requests SET status = ?, manager_id = ?, manager_comment = ?, manager_acted_at = NOW() WHERE id = ? AND status = ?');
        } else {
            $newStatus = $decision === 'approve' ? 'Approved' : 'Rejected';
            $update = mysqli_prepare($conn, 'UPDATE leave_requests SET status = ?, hr_id = ?, hr_comment = ?, hr_acted_at = NOW() WHERE id = ? AND status = ?');
        }
        mysqli_stmt_bind_param($update, 'sisis', $newStatus, $userId, $comment, $id, $expected);
        mysqli_stmt_execute($update);

        if (mysqli_stmt_affected_rows($update) !== 1) {
            header('Location: index.php?error=' . urlencode('This request was already decided by someone else. Nothing was changed.'));
            exit;
        }

        // NEW: the employee is told the decision (and the comment, if any).
        $what = [
            'Manager Approved' => 'was approved by your supervisor and now waits for HR',
            'Approved' => 'was APPROVED',
            'Rejected' => 'was rejected',
        ][$newStatus];
        $when = date('d M Y', strtotime($leave['start_date']));
        try {
            notifyUser($conn, (int) $leave['user_id'], 'Leave request: ' . $newStatus,
                'Your ' . $leave['leave_type'] . ' request for ' . $when . ' ' . $what . '.' . ($comment !== '' ? ' Comment: ' . $comment : ''));
        } catch (Throwable $e) {
            error_log('leave decision notification failed: ' . $e->getMessage());
        }

        header('Location: index.php?success=' . urlencode('Leave request ' . strtolower($newStatus === 'Manager Approved' ? 'approved (sent to HR)' : $newStatus) . '. The employee was notified.'));
        exit;
    }
}

// NEW: dates in the same format as the Leave list.
$startTs = strtotime($leave['start_date']);
$endTs = strtotime($leave['end_date']);
$st = $leave['start_time'] ?? null;
$et = $leave['end_time'] ?? null;
if ($leave['leave_type'] === 'Short Leave' && $st && $et) {
    $minutes = (int) round((strtotime('2000-01-01 ' . $et) - strtotime('2000-01-01 ' . $st)) / 60);
    $length = $minutes >= 60 ? intdiv($minutes, 60) . 'h' . ($minutes % 60 ? ' ' . ($minutes % 60) . 'min' : '') : $minutes . 'min';
    $datesText = date('d M Y', $startTs) . ', ' . substr($st, 0, 5) . ' – ' . substr($et, 0, 5) . ' (' . $length . ')';
} else {
    $days = (int) round(($endTs - $startTs) / 86400) + 1;
    $datesText = ($startTs === $endTs ? date('d M Y', $startTs) : date('d M Y', $startTs) . ' → ' . date('d M Y', $endTs))
        . ' (' . $days . ' day' . ($days === 1 ? '' : 's') . ')';
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
                <div class="col-md-5">
                    <label class="form-label small fw-semibold text-muted">Leave Type</label>
                    <input class="form-control rm-input" value="<?= htmlspecialchars($leave['leave_type'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
                <div class="col-md-7">
                    <!-- CHANGED: Short Leave shows its date and hours -->
                    <label class="form-label small fw-semibold text-muted"><?= $leave['leave_type'] === 'Short Leave' ? 'Date and Time' : 'Dates'; ?></label>
                    <input class="form-control rm-input" value="<?= htmlspecialchars($datesText, ENT_QUOTES, 'UTF-8'); ?>" disabled>
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
                <input type="hidden" name="id" value="<?= (int) $id; ?>">
                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Your Comment <span class="text-muted fw-normal">(optional, the employee sees it)</span></label>
                    <textarea name="comment" class="form-control rm-input" rows="3" maxlength="1000" style="height:auto;"></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="btn btn-success rm-btn-primary" type="submit" name="decision" value="approve">
                        <i class="bi bi-check-circle-fill me-2"></i><?= $stage === 'manager' ? 'Approve' : 'Final Approve'; ?>
                    </button>
                    <button class="btn btn-danger rm-btn-primary" type="submit" name="decision" value="reject" onclick="return confirm('Reject this leave request?');">
                        <i class="bi bi-x-circle-fill me-2"></i>Reject
                    </button>
                    <a href="index.php" class="btn btn-light rm-btn-light">Cancel</a>
                </div>
            </form>
            <?php } else { ?>
            <div class="alert alert-secondary" style="border-radius:10px;">
                <?php if ($isOwnRequest && $stage) { ?>
                    This is your own request: it must be decided by someone else.
                <?php } else { ?>
                    This request is <?= $leave['status'] === 'Pending' || $leave['status'] === 'Manager Approved' ? 'awaiting a decision from someone else' : 'already ' . strtolower($leave['status']); ?>.
                <?php } ?>
            </div>
            <a href="index.php" class="btn btn-light rm-btn-light">Back</a>
            <?php } ?>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>