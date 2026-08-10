<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
require_role(['Admin', 'Manager']);

if (isset($_POST['save'])) {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $attendanceDate = $_POST['attendance_date'] ?? '';
    $checkIn = $_POST['check_in'] ?? '';
    $checkOut = $_POST['check_out'] ?? '';
    $status = $_POST['status'] ?? '';
    $validDate = DateTime::createFromFormat('Y-m-d', $attendanceDate);

    if (!$userId || !$validDate || $validDate->format('Y-m-d') !== $attendanceDate || !in_array($status, ['Present', 'Absent', 'Late', 'Leave'], true)) {
        $error = 'Please provide valid attendance details.';
    } else {
        $duplicateStatement = mysqli_prepare($conn, 'SELECT id FROM attendance WHERE user_id = ? AND attendance_date = ?');
        mysqli_stmt_bind_param($duplicateStatement, 'is', $userId, $attendanceDate);
        mysqli_stmt_execute($duplicateStatement);

        if (mysqli_num_rows(mysqli_stmt_get_result($duplicateStatement)) > 0) {
            $error = 'Attendance has already been recorded for this employee on this date.';
        } else {
            $statement = mysqli_prepare($conn, "INSERT INTO attendance (user_id, attendance_date, check_in, check_out, status) VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), ?)");
            mysqli_stmt_bind_param($statement, 'issss', $userId, $attendanceDate, $checkIn, $checkOut, $status);

            if (mysqli_stmt_execute($statement)) {
                header('Location: index.php?success=Attendance recorded successfully.');
                exit;
            }
            $error = 'Unable to save attendance. Please try again.';
        }
    }
}

$employees = mysqli_query($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names');
include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-calendar-check-fill';
$modal_title = 'Record Attendance';
$modal_subtitle = 'Log an employee\'s attendance for a specific date.';
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
                    <label class="form-label small fw-semibold text-muted">Employee</label>
                    <select name="user_id" class="form-select rm-input" required>
                        <option value="">Select employee</option>
                        <?php while ($employee = mysqli_fetch_assoc($employees)) { ?>
                            <option value="<?= (int) $employee['id']; ?>" <?= isset($userId) && $userId === (int) $employee['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($employee['names'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Date</label>
                    <input type="date" name="attendance_date" class="form-control rm-input" value="<?= htmlspecialchars($attendanceDate ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Check In</label>
                        <input type="time" name="check_in" class="form-control rm-input" value="<?= htmlspecialchars($checkIn ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Check Out</label>
                        <input type="time" name="check_out" class="form-control rm-input" value="<?= htmlspecialchars($checkOut ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Status</label>
                    <select name="status" class="form-select rm-input" required>
                        <?php foreach (['Present', 'Absent', 'Late', 'Leave'] as $option) { ?>
                            <option value="<?= $option; ?>" <?= ($status ?? 'Present') === $option ? 'selected' : ''; ?>><?= $option; ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button type="submit" name="save" class="rm-btn rm-btn-primary">
                        <i class="bi bi-check-circle-fill me-2"></i>Save Attendance
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
