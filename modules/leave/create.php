<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
// Any logged-in employee can request their own leave.

$userId = current_user_id();
const LEAVE_TYPES = ['Annual', 'Sick', 'Emergency', 'Unpaid', 'Short Leave', 'Other'];

/**
 * NEW: makes sure this database can store every leave type (including Short Leave with its start and
 * end time). Adds ONLY what is missing, and keeps every leave type the database already allows.
 * Done automatically, so the live database does not need to be changed by hand.
 */
function leave_ensure_schema(mysqli $conn): void
{
    $col = function (string $name) use ($conn): ?array {
        $s = mysqli_prepare($conn, "SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'leave_requests' AND column_name = ?");
        mysqli_stmt_bind_param($s, 's', $name);
        mysqli_stmt_execute($s);
        return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
    };
    if (!$col('start_time')) { mysqli_query($conn, 'ALTER TABLE leave_requests ADD COLUMN start_time TIME NULL'); }
    if (!$col('end_time')) { mysqli_query($conn, 'ALTER TABLE leave_requests ADD COLUMN end_time TIME NULL'); }

    // If leave_type is a fixed list (ENUM), add any missing type, keeping the existing ones.
    $type = $col('leave_type');
    if ($type && strtolower($type['DATA_TYPE']) === 'enum') {
        preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", $type['COLUMN_TYPE'], $m);
        $existing = array_map(function ($v) { return str_replace("''", "'", $v); }, $m[1]);
        $missing = array_diff(LEAVE_TYPES, $existing);
        if ($missing) {
            $all = array_merge($existing, array_values($missing));
            $list = implode(',', array_map(function ($v) use ($conn) { return "'" . mysqli_real_escape_string($conn, $v) . "'"; }, $all));
            mysqli_query($conn, "ALTER TABLE leave_requests MODIFY leave_type ENUM($list) NOT NULL");
        }
    }
}

if (isset($_POST['save'])) {
    $leaveType = $_POST['leave_type'] ?? '';
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    // CHANGED: some browsers send "08:30:00" (with seconds): only hours and minutes are used.
    $startTime = substr(trim($_POST['start_time'] ?? ''), 0, 5);
    $endTime = substr(trim($_POST['end_time'] ?? ''), 0, 5);
    $reason = trim($_POST['reason'] ?? '');
    $isShortLeave = $leaveType === 'Short Leave';

    // Short Leave is a single day with a start/end time instead of a date range.
    if ($isShortLeave) {
        $endDate = $startDate;
    }

    $validStart = DateTime::createFromFormat('Y-m-d', $startDate);
    $validEnd = DateTime::createFromFormat('Y-m-d', $endDate);
    $validStartTime = $isShortLeave ? DateTime::createFromFormat('H:i', $startTime) : true;
    $validEndTime = $isShortLeave ? DateTime::createFromFormat('H:i', $endTime) : true;

    if (!in_array($leaveType, LEAVE_TYPES, true)
        || !$validStart || $validStart->format('Y-m-d') !== $startDate
        || !$validEnd || $validEnd->format('Y-m-d') !== $endDate
        || $startDate > $endDate) {
        $error = 'Please provide a valid leave type and date range.';
    } elseif ($isShortLeave && (!$validStartTime || !$validEndTime || $startTime >= $endTime)) {
        $error = 'Please provide a valid start and end time (end must be after start).';
    } else {
        $startTimeToStore = $isShortLeave ? $startTime : null;
        $endTimeToStore = $isShortLeave ? $endTime : null;

        // CHANGED: protected. A database problem shows a message (with the reason for an Admin)
        // instead of a crashed page; the database is completed first if anything is missing.
        try {
            leave_ensure_schema($conn);
            $statement = mysqli_prepare($conn, "INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, start_time, end_time, reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($statement, 'issssss', $userId, $leaveType, $startDate, $endDate, $startTimeToStore, $endTimeToStore, $reason);
            mysqli_stmt_execute($statement);
            header('Location: index.php?success=' . urlencode('Leave request submitted successfully.'));
            exit;
        } catch (Throwable $e) {
            error_log('leave request failed: ' . $e->getMessage());
            $error = 'Unable to submit the leave request. Nothing was saved. Please try again.';
            if (current_user_role() === 'Admin') { $error .= ' [Technical detail for Admin: ' . $e->getMessage() . ']'; }
        }
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

            <form method="POST" id="leaveForm">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Leave Type</label>
                    <select name="leave_type" id="leaveTypeSelect" class="form-select rm-input" required>
                        <option value="">Select type</option>
                        <?php foreach (LEAVE_TYPES as $option) { ?>
                            <option value="<?= $option; ?>" <?= ($leaveType ?? '') === $option ? 'selected' : ''; ?>><?= $option; ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="row g-3 mb-3" id="dateRangeFields">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted" id="startDateLabel">Start Date</label>
                        <input type="date" name="start_date" id="startDateInput" class="form-control rm-input" value="<?= htmlspecialchars($startDate ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="col-6" id="endDateField">
                        <label class="form-label small fw-semibold text-muted">End Date</label>
                        <input type="date" name="end_date" id="endDateInput" class="form-control rm-input" value="<?= htmlspecialchars($endDate ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                </div>

                <div class="row g-3 mb-3 d-none" id="timeFields">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Start Time</label>
                        <input type="time" name="start_time" id="startTimeInput" class="form-control rm-input" value="<?= htmlspecialchars($startTime ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">End Time</label>
                        <input type="time" name="end_time" id="endTimeInput" class="form-control rm-input" value="<?= htmlspecialchars($endTime ?? '', ENT_QUOTES, 'UTF-8'); ?>">
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

<script>
(function () {
    var typeSelect = document.getElementById('leaveTypeSelect');
    var startDateLabel = document.getElementById('startDateLabel');
    var endDateField = document.getElementById('endDateField');
    var endDateInput = document.getElementById('endDateInput');
    var timeFields = document.getElementById('timeFields');
    var startTimeInput = document.getElementById('startTimeInput');
    var endTimeInput = document.getElementById('endTimeInput');

    function toggleShortLeave() {
        var isShort = typeSelect.value === 'Short Leave';

        startDateLabel.textContent = isShort ? 'Date' : 'Start Date';
        endDateField.classList.toggle('d-none', isShort);
        endDateInput.required = !isShort;

        timeFields.classList.toggle('d-none', !isShort);
        startTimeInput.required = isShort;
        endTimeInput.required = isShort;
    }

    typeSelect.addEventListener('change', toggleShortLeave);
    toggleShortLeave();
})();
</script>

<?php include '../../includes/footer.php'; ?>