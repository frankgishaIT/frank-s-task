<?php
require '../../config/db.php';
require_role(['Admin', 'Manager']);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid attendance record selected.'); exit; }

$recordStatement = mysqli_prepare($conn, 'SELECT * FROM attendance WHERE id = ?');
mysqli_stmt_bind_param($recordStatement, 'i', $id);
mysqli_stmt_execute($recordStatement);
$record = mysqli_fetch_assoc(mysqli_stmt_get_result($recordStatement));
if (!$record) { header('Location: index.php?success=Attendance record not found.'); exit; }

if (isset($_POST['update'])) {
    $attendanceDate = $_POST['attendance_date'] ?? '';
    $checkIn = $_POST['check_in'] ?? '';
    $checkOut = $_POST['check_out'] ?? '';
    $status = $_POST['status'] ?? '';
    $validDate = DateTime::createFromFormat('Y-m-d', $attendanceDate);
    if (!$validDate || $validDate->format('Y-m-d') !== $attendanceDate || !in_array($status, ['Present', 'Absent', 'Late', 'Leave'], true)) {
        $error = 'Please provide valid attendance details.';
    } else {
        $duplicateStatement = mysqli_prepare($conn, 'SELECT id FROM attendance WHERE user_id = ? AND attendance_date = ? AND id != ?');
        mysqli_stmt_bind_param($duplicateStatement, 'isi', $record['user_id'], $attendanceDate, $id);
        mysqli_stmt_execute($duplicateStatement);
        if (mysqli_num_rows(mysqli_stmt_get_result($duplicateStatement)) > 0) {
            $error = 'Attendance already exists for this employee on this date.';
        } else {
            $statement = mysqli_prepare($conn, "UPDATE attendance SET attendance_date = ?, check_in = NULLIF(?, ''), check_out = NULLIF(?, ''), status = ? WHERE id = ?");
            mysqli_stmt_bind_param($statement, 'ssssi', $attendanceDate, $checkIn, $checkOut, $status, $id);
            mysqli_stmt_execute($statement);
            header('Location: index.php?success=Attendance updated successfully.'); exit;
        }
    }
    $record['attendance_date'] = $attendanceDate; $record['check_in'] = $checkIn; $record['check_out'] = $checkOut; $record['status'] = $status;
}

$employeeStatement = mysqli_prepare($conn, 'SELECT names FROM users WHERE id = ?');
mysqli_stmt_bind_param($employeeStatement, 'i', $record['user_id']); mysqli_stmt_execute($employeeStatement);
$employee = mysqli_fetch_assoc(mysqli_stmt_get_result($employeeStatement));
include '../../includes/header.php'; include '../../includes/sidebar.php';

$modal_icon = 'bi-calendar-week';
$modal_title = 'Edit Attendance';
$modal_subtitle = 'Update this attendance record.';
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
                    <input class="form-control rm-input" value="<?= htmlspecialchars($employee['names'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Date</label>
                    <input type="date" name="attendance_date" class="form-control rm-input" value="<?= htmlspecialchars($record['attendance_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Check In</label>
                        <input type="time" name="check_in" class="form-control rm-input" value="<?= htmlspecialchars($record['check_in'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Check Out</label>
                        <input type="time" name="check_out" class="form-control rm-input" value="<?= htmlspecialchars($record['check_out'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Status</label>
                    <select name="status" class="form-select rm-input" required>
                        <?php foreach (['Present', 'Absent', 'Late', 'Leave'] as $option) { ?>
                            <option value="<?= $option; ?>" <?= $record['status'] === $option ? 'selected' : ''; ?>><?= $option; ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="btn btn-primary rm-btn-primary" type="submit" name="update">
                        <i class="bi bi-check-circle-fill me-2"></i>Update Attendance
                    </button>
                    <a href="index.php" class="btn btn-light rm-btn-light">Cancel</a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>
