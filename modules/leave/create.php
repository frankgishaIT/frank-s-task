<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
// Any logged-in employee can request their own leave.

$userId = current_user_id();

if (isset($_POST['save'])) {
    $leaveType = $_POST['leave_type'] ?? '';
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    $validStart = DateTime::createFromFormat('Y-m-d', $startDate);
    $validEnd = DateTime::createFromFormat('Y-m-d', $endDate);

    if (!in_array($leaveType, ['Annual', 'Sick', 'Emergency', 'Unpaid', 'Other'], true)
        || !$validStart || $validStart->format('Y-m-d') !== $startDate
        || !$validEnd || $validEnd->format('Y-m-d') !== $endDate
        || $startDate > $endDate) {
        $error = 'Please provide a valid leave type and date range.';
    } else {
        $statement = mysqli_prepare($conn, "INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, reason) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($statement, 'issss', $userId, $leaveType, $startDate, $endDate, $reason);

        if (mysqli_stmt_execute($statement)) {
            header('Location: index.php?success=Leave request submitted successfully.');
            exit;
        }
        $error = 'Unable to submit the leave request. Please try again.';
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-calendar-plus-fill';
$modal_title = 'Request Leave';
$modal_subtitle = 'Submit a leave request for your supervisor and HR to review.';
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

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Leave Type</label>
                    <select name="leave_type" class="form-select rm-input" required>
                        <option value="">Select type</option>
                        <?php foreach (['Annual', 'Sick', 'Emergency', 'Unpaid', 'Other'] as $option) { ?>
                            <option value="<?= $option; ?>" <?= ($leaveType ?? '') === $option ? 'selected' : ''; ?>><?= $option; ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Start Date</label>
                        <input type="date" name="start_date" class="form-control rm-input" value="<?= htmlspecialchars($startDate ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">End Date</label>
                        <input type="date" name="end_date" class="form-control rm-input" value="<?= htmlspecialchars($endDate ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Reason</label>
                    <textarea name="reason" class="form-control rm-input" rows="4" style="height:auto;"><?= htmlspecialchars($reason ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button type="submit" name="save" class="rm-btn rm-btn-primary">
                        <i class="bi bi-check-circle-fill me-2"></i>Submit Request
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-secondary">
                        <i class="bi bi-x-circle-fill me-2"></i>Cancel
                    </a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>
