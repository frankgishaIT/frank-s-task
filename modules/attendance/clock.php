<?php
require '../../config/db.php';

$userId = current_user_id();
$today = date('Y-m-d');
define('WORK_START', '08:00:00');
define('WORK_END', '17:00:00');

// Find today's attendance record for this employee, if any.
$statement = mysqli_prepare($conn, 'SELECT * FROM attendance WHERE user_id = ? AND attendance_date = ?');
mysqli_stmt_bind_param($statement, 'is', $userId, $today);
mysqli_stmt_execute($statement);
$today_record = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (isset($_POST['clock_in']) && !$today_record) {
    $now = date('H:i:s');
    $statement = mysqli_prepare($conn, "INSERT INTO attendance (user_id, attendance_date, check_in, status, workflow_status) VALUES (?, ?, ?, 'Present', 'Pending Clock-In Approval')");
    mysqli_stmt_bind_param($statement, 'iss', $userId, $today, $now);
    mysqli_stmt_execute($statement);
    header('Location: clock.php?success=Clocked in. Waiting for your manager to approve.');
    exit;
}

if (isset($_POST['clock_out']) && $today_record && $today_record['workflow_status'] === 'Working') {
    $now = date('H:i:s');
    $workedMinutes = max(0, (strtotime($now) - strtotime($today_record['check_in'])) / 60);
    $overtimeMinutes = max(0, (strtotime($now) - strtotime(WORK_END)) / 60);

    $statement = mysqli_prepare($conn, "UPDATE attendance SET check_out = ?, worked_minutes = ?, overtime_minutes = ?, workflow_status = 'Pending Clock-Out Confirmation' WHERE id = ?");
    mysqli_stmt_bind_param($statement, 'siii', $now, $workedMinutes, $overtimeMinutes, $today_record['id']);
    mysqli_stmt_execute($statement);
    header('Location: clock.php?success=Clocked out. Waiting for your manager to confirm.');
    exit;
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<?php if (isset($_GET['success'])) { ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>My Attendance</h2>
</div>

<div class="card border-0 shadow-sm" style="max-width:480px;">
    <div class="card-body p-4 text-center">
        <p class="text-muted mb-1">Today — <?= date('l, d F Y'); ?></p>

        <?php if (!$today_record) { ?>
            <p class="mb-4">You haven't clocked in yet today.</p>
            <form method="POST">
                <button type="submit" name="clock_in" class="btn btn-primary btn-lg px-5">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Clock In
                </button>
            </form>

        <?php } elseif ($today_record['workflow_status'] === 'Pending Clock-In Approval') { ?>
            <p class="mb-1">Clocked in at <strong><?= date('H:i', strtotime($today_record['check_in'])); ?></strong></p>
            <span class="badge bg-secondary mb-3">Waiting for manager approval</span>

        <?php } elseif ($today_record['workflow_status'] === 'Working') { ?>
            <p class="mb-1">Clocked in at <strong><?= date('H:i', strtotime($today_record['check_in'])); ?></strong></p>
            <span class="badge bg-success mb-3">Approved — you're clocked in</span>
            <form method="POST">
                <button type="submit" name="clock_out" class="btn btn-danger btn-lg px-5">
                    <i class="bi bi-box-arrow-left me-2"></i>Clock Out
                </button>
            </form>

        <?php } elseif ($today_record['workflow_status'] === 'Pending Clock-Out Confirmation') { ?>
            <p class="mb-1">Clocked out at <strong><?= date('H:i', strtotime($today_record['check_out'])); ?></strong></p>
            <span class="badge bg-secondary mb-3">Waiting for manager confirmation</span>

        <?php } elseif ($today_record['workflow_status'] === 'Confirmed') { ?>
            <p class="mb-1">
                <strong><?= date('H:i', strtotime($today_record['check_in'])); ?></strong>
                &rarr;
                <strong><?= date('H:i', strtotime($today_record['check_out'])); ?></strong>
            </p>
            <span class="badge bg-success mb-3">Day confirmed — <?= round($today_record['worked_minutes'] / 60, 1); ?> hrs worked</span>

        <?php } elseif ($today_record['workflow_status'] === 'Rejected') { ?>
            <span class="badge bg-danger mb-3">Today's attendance was rejected</span>
            <?php if ($today_record['manager_note']) { ?>
                <p class="text-muted small"><?= htmlspecialchars($today_record['manager_note'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php } ?>
        <?php } ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
