<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
require_role(['Admin', 'Manager']);

if (isset($_POST['save'])) {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $attendanceDate = $_POST['attendance_date'] ?? '';
    $checkIn = trim($_POST['check_in'] ?? '');
    $checkOut = trim($_POST['check_out'] ?? '');
    $status = $_POST['status'] ?? '';
    $validDate = DateTime::createFromFormat('Y-m-d', $attendanceDate);
    $validTime = function ($t) { return $t === '' || preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t) === 1; };

    // NEW: Absent and Leave have no check-in / check-out times.
    if (in_array($status, ['Absent', 'Leave'], true)) {
        $checkIn = '';
        $checkOut = '';
    }

    // NEW: the employee must be an active user.
    $userOk = false;
    if ($userId) {
        $u = mysqli_prepare($conn, 'SELECT id FROM users WHERE id = ? AND is_active = 1');
        mysqli_stmt_bind_param($u, 'i', $userId);
        mysqli_stmt_execute($u);
        $userOk = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($u));
    }

    if (!$userId || !$userOk || !$validDate || $validDate->format('Y-m-d') !== $attendanceDate || !in_array($status, ['Present', 'Absent', 'Late', 'Leave'], true)) {
        $error = 'Please provide valid attendance details.';
    } elseif ($attendanceDate > date('Y-m-d')) {
        // NEW: attendance cannot be recorded for a day that has not happened yet.
        $error = 'Attendance cannot be recorded for a future date.';
    } elseif (!$validTime($checkIn) || !$validTime($checkOut)) {
        $error = 'Please enter valid check-in and check-out times.';
    } elseif (in_array($status, ['Present', 'Late'], true) && $checkIn === '') {
        // NEW: someone who came to work has a check-in time.
        $error = 'Please enter the check-in time for a Present or Late employee.';
    } elseif ($checkIn !== '' && $checkOut !== '' && strcmp($checkOut, $checkIn) <= 0) {
        // NEW: check-out must be after check-in (hours worked would be negative).
        $error = 'The check-out time must be after the check-in time.';
    } else {
        $duplicateStatement = mysqli_prepare($conn, 'SELECT id FROM attendance WHERE user_id = ? AND attendance_date = ?');
        mysqli_stmt_bind_param($duplicateStatement, 'is', $userId, $attendanceDate);
        mysqli_stmt_execute($duplicateStatement);

        if (mysqli_num_rows(mysqli_stmt_get_result($duplicateStatement)) > 0) {
            $error = 'Attendance has already been recorded for this employee on this date.';
        } else {
            // CHANGED: a record entered by a Manager/Admin is already approved, so it is saved as
            // 'Confirmed'. Before, no workflow status was saved, so payroll (which only counts
            // Confirmed days) never counted manual records and the employee was deducted as absent.
            $statement = mysqli_prepare($conn, "INSERT INTO attendance (user_id, attendance_date, check_in, check_out, status, workflow_status) VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, 'Confirmed')");
            mysqli_stmt_bind_param($statement, 'issss', $userId, $attendanceDate, $checkIn, $checkOut, $status);

            try {
                if (mysqli_stmt_execute($statement)) {
                    // CHANGED: message is urlencoded.
                    header('Location: index.php?success=' . urlencode('Attendance recorded successfully.'));
                    exit;
                }
                $error = 'Unable to save attendance. Please try again.';
            } catch (mysqli_sql_exception $e) {
                // NEW: two people saving the same employee and day at the same moment.
                error_log('attendance create failed: ' . $e->getMessage());
                $error = (int) $e->getCode() === 1062
                    ? 'Attendance has already been recorded for this employee on this date.'
                    : 'Unable to save attendance. Please try again.';
            }
        }
    }
}

$employees = mysqli_query($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names');
include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-calendar-check-fill';
$modal_title = 'Record Attendance';
$modal_subtitle = 'Log an employee\'s attendance for a specific date. Manual records are saved as Confirmed.';
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
                    <input type="date" name="attendance_date" class="form-control rm-input" max="<?= date('Y-m-d'); ?>" value="<?= htmlspecialchars($attendanceDate ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="row g-3 mb-3" id="timesRow">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Check In</label>
                        <input type="time" name="check_in" id="checkIn" class="form-control rm-input" value="<?= htmlspecialchars($checkIn ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Check Out</label>
                        <input type="time" name="check_out" class="form-control rm-input" value="<?= htmlspecialchars($checkOut ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Status</label>
                    <select name="status" id="statusSelect" class="form-select rm-input" required>
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

<script>
// NEW: hide the times for Absent / Leave, and require a check-in time for Present / Late.
(function () {
    var status = document.getElementById('statusSelect');
    var times = document.getElementById('timesRow');
    var checkIn = document.getElementById('checkIn');
    function sync() {
        var away = status.value === 'Absent' || status.value === 'Leave';
        times.style.display = away ? 'none' : '';
        checkIn.required = !away;
    }
    status.addEventListener('change', sync);
    sync();
})();
</script>

<?php include '../../includes/footer.php'; ?>