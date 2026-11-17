<?php
/**
 * NEW FILE: includes/lc_accommodation.php — RM Learning Centre ACCOMMODATION (stage 4).
 * Only for branches where accommodation is switched on (Training Centres). Facilities → rooms (type, beds, price per
 * day / week / month) → bookings of one BED for a period. A bed can never be booked twice for overlapping dates
 * (checked under a row lock, so two staff saving at the same moment cannot overbook). Reservation → check-in →
 * check-out (or cancellation). Charges are a separate "Accommodation fee" Sales invoice (never mixed with tuition).
 */
require_once __DIR__ . '/lc_finance.php';

const LC_ACC_SCHEMA = '1';
const LC_ACC_STATUSES = ['Reserved', 'Checked in', 'Checked out', 'Cancelled'];
const LC_ACC_UNITS = ['day' => 'per day', 'week' => 'per week', 'month' => 'per month'];

function lc_acc_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    lc_fin_ensure($conn);
    if (lc_setting($conn, 'acc_schema') === LC_ACC_SCHEMA) { return; }
    $q = function (string $sql) use ($conn) { try { mysqli_query($conn, $sql); } catch (Throwable $e) { error_log('lc_acc_ensure: ' . $e->getMessage()); } };
    $q('CREATE TABLE IF NOT EXISTS lc_acc_facilities (id INT(11) NOT NULL AUTO_INCREMENT, branch_id INT(11) NULL, name VARCHAR(120) NOT NULL, gender VARCHAR(10) NOT NULL DEFAULT \'Mixed\',
        address VARCHAR(200) NULL, rules TEXT NULL, status VARCHAR(10) NOT NULL DEFAULT \'Active\', created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY branch (branch_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_acc_rooms (id INT(11) NOT NULL AUTO_INCREMENT, facility_id INT(11) NOT NULL, name VARCHAR(60) NOT NULL, room_type VARCHAR(60) NOT NULL,
        beds INT(11) NOT NULL DEFAULT 1, price DECIMAL(14,2) NOT NULL DEFAULT 0, price_unit VARCHAR(6) NOT NULL DEFAULT \'month\', status VARCHAR(12) NOT NULL DEFAULT \'Available\',
        notes VARCHAR(200) NULL, PRIMARY KEY (id), KEY facility (facility_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_acc_bookings (id INT(11) NOT NULL AUTO_INCREMENT, room_id INT(11) NOT NULL, bed_no INT(11) NOT NULL, customer_id INT(11) NOT NULL,
        application_id INT(11) NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, status VARCHAR(12) NOT NULL DEFAULT \'Reserved\', amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        sale_id INT(11) NULL, notes VARCHAR(300) NULL, checked_in_at DATETIME NULL, checked_out_at DATETIME NULL, cancelled_reason VARCHAR(300) NULL,
        created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY room (room_id, bed_no), KEY customer (customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    lc_setting_set($conn, 'acc_schema', LC_ACC_SCHEMA);
}

/** Branches where accommodation is offered (Training Centres setting). */
function lc_acc_branches(mysqli $conn): array
{
    return array_filter(lc_training_branches($conn), function ($b) { return $b['enabled'] && !empty($b['settings']['accommodation']); });
}

function lc_acc_room(mysqli $conn, int $roomId): ?array
{
    return mysqli_fetch_assoc(mysqli_query($conn, 'SELECT r.*, f.name facility, f.branch_id, f.gender, f.status fstatus FROM lc_acc_rooms r JOIN lc_acc_facilities f ON f.id = r.facility_id WHERE r.id = ' . $roomId)) ?: null;
}

/** Beds of a room taken between two dates (bookings not cancelled / not checked out early that overlap). */
function lc_acc_taken_beds(mysqli $conn, int $roomId, string $from, string $to, int $exceptBooking = 0): array
{
    $f = mysqli_real_escape_string($conn, $from); $t = mysqli_real_escape_string($conn, $to);
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT bed_no FROM lc_acc_bookings WHERE room_id = $roomId AND id <> $exceptBooking AND status IN ('Reserved', 'Checked in')
        AND start_date < '$t' AND end_date > '$f'"));
    return array_map('intval', array_column($rows, 0));
}

/** Free beds of every available room of the facilities of a branch for a period: [room_id => [room..., free => [bed numbers]]]. */
function lc_acc_availability(mysqli $conn, string $from, string $to, ?int $branchId = null): array
{
    $w = "r.status = 'Available' AND f.status = 'Active'" . ($branchId !== null ? ' AND COALESCE(f.branch_id, 0) = ' . (int) $branchId : '');
    $o = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT r.*, f.name facility, f.branch_id, f.gender FROM lc_acc_rooms r JOIN lc_acc_facilities f ON f.id = r.facility_id WHERE $w ORDER BY f.name, r.name"), MYSQLI_ASSOC) as $r) {
        $taken = lc_acc_taken_beds($conn, (int) $r['id'], $from, $to);
        $r['free'] = array_values(array_diff(range(1, max(1, (int) $r['beds'])), $taken));
        $o[(int) $r['id']] = $r;
    }
    return $o;
}

/** Price of a stay: per day = nights; per week = started weeks; per month = started months (30 days). */
function lc_acc_price(array $room, string $from, string $to): float
{
    $days = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400));
    $n = ['day' => $days, 'week' => (int) ceil($days / 7), 'month' => (int) ceil($days / 30)][$room['price_unit']] ?? $days;
    return round($n * (float) $room['price'], 2);
}

/**
 * Books one bed. $app must be an admitted / registered application linked to a customer, for a branch that offers accommodation.
 * The room row is locked while checking, so the same bed can never be booked twice for overlapping dates.
 */
function lc_acc_book(mysqli $conn, array $app, int $roomId, int $bedNo, string $from, string $to, string $notes = ''): int
{
    lc_acc_ensure($conn);
    if (empty($app['customer_id'])) { throw new RuntimeException('Link the application to the customer first.'); }
    if ($app['status'] !== 'Accepted') { throw new RuntimeException('Accommodation is for admitted (Accepted) applicants and students.'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to <= $from) { throw new RuntimeException('Choose the arrival and departure dates (departure after arrival).'); }
    $own = false; try { $own = mysqli_begin_transaction($conn); } catch (Throwable $e) { $own = false; }
    try {
        $room = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lc_acc_rooms WHERE id = $roomId FOR UPDATE"));
        $full = $room ? lc_acc_room($conn, $roomId) : null;
        if (!$full || $full['status'] !== 'Available' || $full['fstatus'] !== 'Active') { throw new RuntimeException('This room is not available.'); }
        if (!isset(lc_acc_branches($conn)[(int) $full['branch_id']])) { throw new RuntimeException('Accommodation is not offered at this branch (Training Centres).'); }
        if ($bedNo < 1 || $bedNo > (int) $full['beds']) { throw new RuntimeException('Choose a bed of this room.'); }
        if (in_array($bedNo, lc_acc_taken_beds($conn, $roomId, $from, $to), true)) { throw new RuntimeException('Bed ' . $bedNo . ' of room ' . $full['name'] . ' is already booked for these dates.'); }
        $cid = (int) $app['customer_id'];
        $dup = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM lc_acc_bookings WHERE customer_id = $cid AND status IN ('Reserved', 'Checked in') AND start_date < '" . mysqli_real_escape_string($conn, $to) . "' AND end_date > '" . mysqli_real_escape_string($conn, $from) . "'"));
        if ($dup) { throw new RuntimeException('This student already has a bed for these dates (booking #' . $dup[0] . ').'); }
        $amt = lc_acc_price($full, $from, $to); $aid = (int) $app['id']; $uid = (int) current_user_id(); $nt = mb_substr(trim($notes), 0, 300) ?: null;
        $s = mysqli_prepare($conn, 'INSERT INTO lc_acc_bookings (room_id, bed_no, customer_id, application_id, start_date, end_date, amount, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'iiiissdsi', $roomId, $bedNo, $cid, $aid, $from, $to, $amt, $nt, $uid);
        mysqli_stmt_execute($s);
        $id = (int) mysqli_insert_id($conn);
        if ($own) { mysqli_commit($conn); }
    } catch (Throwable $e) { if ($own) { mysqli_rollback($conn); } throw $e; }
    lc_audit($conn, 'accommodation_book', 'booking', $id, ['room' => $roomId, 'bed' => $bedNo, 'from' => $from, 'to' => $to, 'amount' => $amt], $cid);
    lc_acc_notify($conn, $id, 'reserved');
    return $id;
}

function lc_acc_booking(mysqli $conn, int $id): ?array
{
    return mysqli_fetch_assoc(mysqli_query($conn, "SELECT b.*, r.name room, r.room_type, r.price, r.price_unit, f.name facility, f.branch_id, cu.name cname, cu.phone cphone, a.ref
        FROM lc_acc_bookings b JOIN lc_acc_rooms r ON r.id = b.room_id JOIN lc_acc_facilities f ON f.id = r.facility_id JOIN customers cu ON cu.id = b.customer_id
        LEFT JOIN lc_applications a ON a.id = b.application_id WHERE b.id = $id")) ?: null;
}

/** Status change: check in / check out / cancel (with reason). */
function lc_acc_set_status(mysqli $conn, array $b, string $to, string $reason = ''): void
{
    $from = $b['status']; $id = (int) $b['id']; $now = date('Y-m-d H:i:s');
    $ok = ['Checked in' => ['Reserved'], 'Checked out' => ['Checked in'], 'Cancelled' => ['Reserved']][$to] ?? [];
    if (!in_array($from, $ok, true)) { throw new RuntimeException('A ' . strtolower($from) . ' booking cannot become ' . strtolower($to) . '.'); }
    if ($to === 'Cancelled' && mb_strlen(trim($reason)) < 3) { throw new RuntimeException('Give the reason of the cancellation.'); }
    if ($to === 'Checked in' && $b['start_date'] > date('Y-m-d', strtotime('+1 day'))) { throw new RuntimeException('Check-in is possible from the day before arrival.'); }
    if ($to === 'Checked in') { mysqli_query($conn, "UPDATE lc_acc_bookings SET status = 'Checked in', checked_in_at = '$now' WHERE id = $id"); }
    if ($to === 'Checked out') {
        // An early departure frees the bed from today (the booking keeps its planned dates in the history).
        $end = min($b['end_date'], date('Y-m-d', strtotime('+1 day')));
        mysqli_query($conn, "UPDATE lc_acc_bookings SET status = 'Checked out', checked_out_at = '$now', end_date = '$end' WHERE id = $id");
    }
    if ($to === 'Cancelled') { $r = mb_substr(trim($reason), 0, 300); $s = mysqli_prepare($conn, "UPDATE lc_acc_bookings SET status = 'Cancelled', cancelled_reason = ? WHERE id = ?"); mysqli_stmt_bind_param($s, 'si', $r, $id); mysqli_stmt_execute($s); }
    lc_audit($conn, 'accommodation_' . strtolower(str_replace(' ', '_', $to)), 'booking', $id, ['from' => $from, 'reason' => $reason ?: null, 'planned_end' => $b['end_date']], (int) $b['customer_id']);
    lc_acc_notify($conn, $id, strtolower($to));
}

/** The accommodation invoice: a separate Sales invoice ("Accommodation fee"), never mixed with tuition. */
function lc_acc_bill(mysqli $conn, array $b, float $amount, array $plan): int
{
    if ($b['sale_id']) { $st = mysqli_fetch_row(mysqli_query($conn, 'SELECT status FROM sales WHERE id = ' . (int) $b['sale_id'])); if ($st && $st[0] !== 'Cancelled') { throw new RuntimeException('This booking already has invoice ' . lc_sale_number((int) $b['sale_id']) . '.'); } }
    if ($b['status'] === 'Cancelled') { throw new RuntimeException('The booking is cancelled.'); }
    $app = $b['application_id'] ? lc_application($conn, (int) $b['application_id']) : null;
    if (!$app) { throw new RuntimeException('The booking has no application.'); }
    $desc = 'Accommodation — ' . $b['facility'] . ', room ' . $b['room'] . ', bed ' . $b['bed_no'] . ' (' . date('d M', strtotime($b['start_date'])) . ' – ' . date('d M Y', strtotime($b['end_date'])) . ')';
    $sid = lc_create_invoice($conn, $app, 'accommodation', [['accommodation', $desc, $amount]], [], $plan ?: [[$b['start_date'], $amount]], 'Booking #' . $b['id'], $b['branch_id'] !== null ? (int) $b['branch_id'] : null);
    mysqli_query($conn, 'UPDATE lc_acc_bookings SET sale_id = ' . $sid . ', amount = ' . round($amount, 2) . ' WHERE id = ' . (int) $b['id']);
    lc_audit($conn, 'accommodation_bill', 'booking', (int) $b['id'], ['sale' => $sid, 'amount' => $amount], (int) $b['customer_id']);
    return $sid;
}

function lc_acc_notify(mysqli $conn, int $bookingId, string $status): void
{
    $b = lc_acc_booking($conn, $bookingId);
    if (!$b) { return; }
    lc_notify_student($conn, (int) $b['customer_id'], 'tpl_accommodation', ['{status}' => $status, '{facility}' => $b['facility'], '{room}' => $b['room'], '{bed}' => (string) $b['bed_no'],
        '{from}' => date('d M', strtotime($b['start_date'])), '{to}' => date('d M Y', strtotime($b['end_date']))], 'learning.php?tab=learning');
}

/** Bookings of a customer (portal). */
function lc_acc_customer_bookings(mysqli $conn, int $customerId): array
{
    return mysqli_fetch_all(mysqli_query($conn, "SELECT b.*, r.name room, r.room_type, f.name facility, f.address, f.rules FROM lc_acc_bookings b JOIN lc_acc_rooms r ON r.id = b.room_id JOIN lc_acc_facilities f ON f.id = r.facility_id
        WHERE b.customer_id = $customerId AND b.status <> 'Cancelled' ORDER BY b.start_date DESC"), MYSQLI_ASSOC);
}