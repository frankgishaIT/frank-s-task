<?php
require '../../config/db.php';
require_role(['Manager', 'Admin']);

define('WORK_START', '08:00:00');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid attendance record selected.');
 exit; }

$statement = mysqli_prepare($conn, 'SELECT attendance.*, users.names AS employee_name FROM attendance INNER JOIN users ON attendance.user_id = users.id WHERE attendance.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$record = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$record) { header('Location: index.php?success=Attendance record not found.'); exit; }

$stage = null;
if ($record['workflow_status'] === 'Pending Clock-In Approval') {
    $stage = 'clock_in';
} elseif ($record['workflow_status'] === 'Pending Clock-Out Confirmation') {
    $stage = 'clock_out';
}

if ($stage && isset($_POST['decision'])) {
    $decision = $_POST['decision'];
    $note = trim($_POST['note'] ?? '');
    $managerId = current_user_id();

    if (!in_array($decision, ['approve', 'reject'], true)) {
        $error = 'Invalid decision.';
    } elseif ($stage === 'clock_in') {
        if ($decision === 'reject') {
            $statement = mysqli_prepare($conn, "UPDATE attendance SET workflow_status = 'Rejected', manager_id = ?, manager_note = ? WHERE id = ?");
            mysqli_stmt_bind_param($statement, 'isi', $managerId, $note, $id);
        } else {
            $lateMinutes = max(0, (strtotime($record['check_in']) - strtotime(WORK_START)) / 60);
            $status = $lateMinutes > 0 ? 'Late' : 'Present';
            $statement = mysqli_prepare($conn, "UPDATE attendance SET workflow_status = 'Working', status = ?, late_minutes = ?, manager_id = ?, manager_note = ? WHERE id = ?");
            mysqli_stmt_bind_param($statement, 'siisi', $status, $lateMinutes, $managerId, $note, $id);
        }
        mysqli_stmt_execute($statement);
        header('Location: index.php?success=Attendance updated successfully.');
        exit;
    } elseif ($stage === 'clock_out') {
        $newStatus = $decision === 'approve' ? 'Confirmed' : 'Rejected';
        $confirmedAt = $decision === 'approve' ? date('Y-m-d H:i:s') : null;
        $statement = mysqli_prepare($conn, "UPDATE attendance SET workflow_status = ?, confirmed_by = ?, confirmed_at = ?, manager_note = ? WHERE id = ?");
        mysqli_stmt_bind_param($statement, 'sissi', $newStatus, $managerId, $confirmedAt, $note, $id);
        mysqli_stmt_execute($statement);
        header('Location: index.php?success=Attendance confirmed successfully. Payroll will reflect these hours.');
        exit;
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-calendar-check';
$modal_title = $stage === 'clock_out' ? 'Confirm Clock-Out' : 'Approve Clock-In';
$modal_subtitle = htmlspecialchars($record['employee_name'], ENT_QUOTES, 'UTF-8') . ' — ' . date('d M Y', strtotime($record['attendance_date']));
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

            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Check In</label>
                    <input class="form-control rm-input" value="<?= $record['check_in'] ? date('H:i', strtotime($record['check_in'])) : '—'; ?>" disabled>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Check Out</label>
                    <input class="form-control rm-input" value="<?= $record['check_out'] ? date('H:i', strtotime($record['check_out'])) : '—'; ?>" disabled>
                </div>
            </div>

            <?php if (!$stage) { ?>
                <div class="alert alert-secondary" style="border-radius:10px;">
                    This record is already <?= strtolower($record['workflow_status']); ?> and has no pending action.
                </div>
                <a href="index.php" class="btn btn-light rm-btn-light">Back</a>
            <?php } else { ?>
            <form method="POST">
                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Note <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea name="note" class="form-control rm-input" rows="3" style="height:auto;"></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="btn btn-success rm-btn-primary" type="submit" name="decision" value="approve">
                        <i class="bi bi-check-circle-fill me-2"></i><?= $stage === 'clock_out' ? 'Confirm' : 'Approve'; ?>
                    </button>
                    <button class="btn btn-danger rm-btn-primary" type="submit" name="decision" value="reject">
                        <i class="bi bi-x-circle-fill me-2"></i>Reject
                    </button>
                    <a href="index.php" class="btn btn-light rm-btn-light">Cancel</a>
                </div>
            </form>
            <?php } ?>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>
