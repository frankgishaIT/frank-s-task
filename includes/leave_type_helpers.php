<?php
/**
 * NEW FILE: includes/leave_type_helpers.php
 * Leave types that are a few HOURS on one date (start and end time) rather than whole days. "Short Leave" was the
 * first; the Work Timetable added four more, each an approved exception the timetable applies automatically:
 *   Late Arrival (may arrive until the end time), Early Departure (may sign out from the start time),
 *   Extended Break (the break may last until the end time), External Assignment (working outside for those hours).
 */
const LEAVE_TIMED_TYPES = [
    'Short Leave'         => 'Away for a few hours on one date.',
    'Late Arrival'        => 'Start time = your reporting time; end time = when you will arrive.',
    'Early Departure'     => 'Start time = when you need to leave; end time = your normal departure.',
    'Extended Break'      => 'Start time = your break start; end time = when you will be back.',
    'External Assignment' => 'Working outside the workplace between these hours.',
];

function leave_is_timed(?string $type): bool
{
    return $type !== null && isset(LEAVE_TIMED_TYPES[$type]);
}

/** Makes sure the timed types exist as leave categories (Admins can still deactivate them under Categories). */
function leave_timed_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        $ins = mysqli_prepare($conn, "INSERT IGNORE INTO rm_categories (group_key, name) VALUES ('leave_type', ?)");
        foreach (array_keys(LEAVE_TIMED_TYPES) as $name) { mysqli_stmt_bind_param($ins, 's', $name); mysqli_stmt_execute($ins); }
    } catch (Throwable $e) { error_log('leave_timed_ensure: ' . $e->getMessage()); }
}
