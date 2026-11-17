<?php
/**
 * NEW FILE: modules/learning/accommodation.php — ACCOMMODATION: Bookings · Occupancy · Facilities & rooms.
 * Only branches with accommodation switched on (Training Centres). One bed per booking, no overlap (no overbooking).
 * Charges = a separate "Accommodation fee" Sales invoice (payments and receipts in Sales, like every fee).
 */
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/lc_accommodation.php';
lcs_require($conn, 'accommodation');
lc_acc_ensure($conn);
$tab = in_array($_GET['tab'] ?? '', ['bookings', 'occupancy', 'rooms'], true) ? $_GET['tab'] : 'bookings';
$branches = lc_acc_branches($conn);
$myBranches = array_filter($branches, function ($b) use ($conn) { return lcs_branch_ok($conn, (int) $b['id'] ?: null); });
$back = 'accommodation.php?tab=';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? ''); $t = (string) ($_POST['tab'] ?? 'bookings');
    if (!lcs_csrf_ok()) { lcs_go($back . $t, 'error', 'The page had expired.'); }
    try {
        if ($do === 'facility') {
            $id = (int) ($_POST['id'] ?? 0); $bid = (int) ($_POST['branch_id'] ?? 0);
            if (!isset($myBranches[$bid])) { throw new RuntimeException('Choose a branch where accommodation is offered (Training Centres → accommodation).'); }
            $n = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120); if (mb_strlen($n) < 2) { throw new RuntimeException('Name the facility.'); }
            $g = in_array($_POST['gender'] ?? '', ['Mixed', 'Female', 'Male'], true) ? $_POST['gender'] : 'Mixed';
            $ad = mb_substr(trim((string) ($_POST['address'] ?? '')), 0, 200) ?: null; $ru = trim((string) ($_POST['rules'] ?? '')) ?: null; $st = ($_POST['status'] ?? '') === 'Inactive' ? 'Inactive' : 'Active'; $u = (int) current_user_id();
            $bidv = $bid ?: null;
            if ($id) { $s = mysqli_prepare($conn, 'UPDATE lc_acc_facilities SET branch_id = ?, name = ?, gender = ?, address = ?, rules = ?, status = ? WHERE id = ?'); mysqli_stmt_bind_param($s, 'isssssi', $bidv, $n, $g, $ad, $ru, $st, $id); }
            else { $s = mysqli_prepare($conn, 'INSERT INTO lc_acc_facilities (branch_id, name, gender, address, rules, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)'); mysqli_stmt_bind_param($s, 'isssssi', $bidv, $n, $g, $ad, $ru, $st, $u); }
            mysqli_stmt_execute($s);
            lc_audit($conn, $id ? 'facility_update' : 'facility_add', 'facility', $id ?: (int) mysqli_insert_id($conn), ['name' => $n, 'status' => $st]);
            lcs_go($back . 'rooms', 'success', 'Facility saved.');
        }
        if ($do === 'room') {
            $id = (int) ($_POST['id'] ?? 0); $fid = (int) ($_POST['facility_id'] ?? 0);
            $f = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lc_acc_facilities WHERE id = $fid"));
            if (!$f || !isset($myBranches[(int) $f['branch_id']])) { throw new RuntimeException('Choose the facility.'); }
            $n = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 60); $ty = mb_substr(trim((string) ($_POST['room_type'] ?? '')), 0, 60);
            $beds = max(1, min(50, (int) ($_POST['beds'] ?? 1))); $pr = max(0, (float) ($_POST['price'] ?? 0)); $un = isset(LC_ACC_UNITS[$_POST['price_unit'] ?? '']) ? $_POST['price_unit'] : 'month';
            $st = in_array($_POST['status'] ?? '', ['Available', 'Maintenance', 'Closed'], true) ? $_POST['status'] : 'Available'; $nt = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 200) ?: null;
            if ($n === '' || $ty === '') { throw new RuntimeException('Give the room a name and a type.'); }
            if ($id) {
                $maxBed = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(MAX(bed_no), 0) FROM lc_acc_bookings WHERE room_id = $id AND status IN ('Reserved', 'Checked in') AND end_date > '" . date('Y-m-d') . "'"))[0];
                if ($beds < $maxBed) { throw new RuntimeException('Bed ' . $maxBed . ' is booked: the room cannot have fewer beds now.'); }
                $s = mysqli_prepare($conn, 'UPDATE lc_acc_rooms SET facility_id = ?, name = ?, room_type = ?, beds = ?, price = ?, price_unit = ?, status = ?, notes = ? WHERE id = ?');
                mysqli_stmt_bind_param($s, 'issidsssi', $fid, $n, $ty, $beds, $pr, $un, $st, $nt, $id);
            } else {
                $s = mysqli_prepare($conn, 'INSERT INTO lc_acc_rooms (facility_id, name, room_type, beds, price, price_unit, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($s, 'issidsss', $fid, $n, $ty, $beds, $pr, $un, $st, $nt);
            }
            mysqli_stmt_execute($s);
            lc_audit($conn, $id ? 'room_update' : 'room_add', 'room', $id ?: (int) mysqli_insert_id($conn), ['name' => $n, 'beds' => $beds, 'price' => $pr . ' ' . $un, 'status' => $st]);
            lcs_go($back . 'rooms', 'success', 'Room saved.');
        }
        if ($do === 'book') {
            $a = lc_application($conn, (int) ($_POST['application_id'] ?? 0));
            if (!$a || !lcs_branch_ok($conn, (int) $a['branch_id'] ?: null)) { throw new RuntimeException('Choose the student.'); }
            [$rid, $bed] = array_map('intval', explode(':', (string) ($_POST['bed'] ?? '0:0')) + [0, 0]);
            $id = lc_acc_book($conn, $a, $rid, $bed, (string) ($_POST['start_date'] ?? ''), (string) ($_POST['end_date'] ?? ''), (string) ($_POST['notes'] ?? ''));
            lcs_go($back . 'bookings&booking=' . $id, 'success', 'Bed reserved (booking #' . $id . '). The student was informed.');
        }
        if (in_array($do, ['checkin', 'checkout', 'cancel', 'bill'], true)) {
            $b = lc_acc_booking($conn, (int) ($_POST['booking_id'] ?? 0));
            if (!$b || !lcs_branch_ok($conn, (int) $b['branch_id'] ?: null)) { throw new RuntimeException('Booking not found.'); }
            if ($do === 'bill') {
                if (!lcs_can($conn, 'finance')) { lcs_deny(); }
                $amt = (float) ($_POST['amount'] ?? 0); $due = (string) ($_POST['due_date'] ?? '');
                $sid = lc_acc_bill($conn, $b, $amt, [[$due, $amt]]);
                lcs_go($back . 'bookings&booking=' . (int) $b['id'], 'success', 'Accommodation invoice ' . lc_sale_number($sid) . ' created (separate from tuition).');
            }
            lc_acc_set_status($conn, $b, ['checkin' => 'Checked in', 'checkout' => 'Checked out', 'cancel' => 'Cancelled'][$do], (string) ($_POST['reason'] ?? ''));
            lcs_go($back . 'bookings&booking=' . (int) $b['id'], 'success', 'Booking updated.');
        }
    } catch (RuntimeException $e) { lcs_go($back . $t . (!empty($_POST['booking_id']) ? '&booking=' . (int) $_POST['booking_id'] : ''), 'error', $e->getMessage()); }
}

$facilities = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM lc_acc_facilities WHERE ' . lcs_branch_sql($conn, 'branch_id') . ' ORDER BY name'), MYSQLI_ASSOC);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'accommodation.php', 'Accommodation');
?>
<ul class="nav nav-pills small mb-3"><?php foreach (['bookings' => 'Bookings', 'occupancy' => 'Occupancy', 'rooms' => 'Facilities & rooms'] as $k => $l) { ?><li class="nav-item"><a class="nav-link py-1 <?= $tab === $k ? 'active' : ''; ?>" href="?tab=<?= $k; ?>"><?= $l; ?></a></li><?php } ?></ul>
<?php if (!$branches) { ?><div class="alert alert-warning small">No branch offers accommodation yet. Switch it on for a branch in <a href="centres.php">Training Centres</a>.</div><?php } ?>
<?php
/* ============================================================ ROOMS */
if ($tab === 'rooms') {
    $editF = !empty($_GET['facility']) ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM lc_acc_facilities WHERE id = ' . (int) $_GET['facility'])) : null;
    $editR = !empty($_GET['room']) ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM lc_acc_rooms WHERE id = ' . (int) $_GET['room'])) : null;
    $vf = $editF ?: ['id' => 0, 'branch_id' => array_key_first($myBranches), 'name' => '', 'gender' => 'Mixed', 'address' => '', 'rules' => '', 'status' => 'Active'];
    $vr = $editR ?: ['id' => 0, 'facility_id' => (int) ($_GET['f'] ?? 0), 'name' => '', 'room_type' => '', 'beds' => 2, 'price' => '', 'price_unit' => 'month', 'status' => 'Available', 'notes' => ''];
    ?>
<div class="row g-3"><div class="col-lg-8">
    <?php foreach ($facilities as $f) { $rooms = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM lc_acc_rooms WHERE facility_id = ' . (int) $f['id'] . ' ORDER BY name'), MYSQLI_ASSOC); ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-0">
        <div class="p-2 d-flex justify-content-between"><div><strong><?= lc_e($f['name']); ?></strong> <span class="small text-muted"><?= lc_e(lc_branch_name($conn, (int) $f['branch_id'] ?: null)); ?> · <?= lc_e($f['gender']); ?> · <?= lc_e($f['address']); ?></span> <?= lcs_badge($f['status']); ?></div>
            <div class="small"><a href="?tab=rooms&facility=<?= (int) $f['id']; ?>">Edit</a> · <a href="?tab=rooms&f=<?= (int) $f['id']; ?>#room">Add room</a></div></div>
        <table class="table table-sm mb-0" style="font-size:13px;"><tr class="table-light"><th class="ps-3">Room</th><th>Type</th><th class="text-end">Beds</th><th class="text-end">Price</th><th>Status</th><th></th></tr>
            <?php foreach ($rooms as $r) { ?><tr><td class="ps-3"><?= lc_e($r['name']); ?></td><td><?= lc_e($r['room_type']); ?></td><td class="text-end"><?= (int) $r['beds']; ?></td><td class="text-end"><?= lc_money($r['price']); ?> <span class="small text-muted"><?= LC_ACC_UNITS[$r['price_unit']] ?? ''; ?></span></td><td><?= lc_e($r['status']); ?></td><td><a class="small" href="?tab=rooms&room=<?= (int) $r['id']; ?>#room">Edit</a></td></tr><?php } ?>
            <?php if (!$rooms) { ?><tr><td colspan="6" class="ps-3 text-muted small">No room yet.</td></tr><?php } ?></table>
    </div></div><?php } ?>
    <?php if (!$facilities) { ?><div class="text-muted small">No facility yet.</div><?php } ?>
</div>
<div class="col-lg-4">
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body row g-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="facility"><input type="hidden" name="tab" value="rooms"><input type="hidden" name="id" value="<?= (int) $vf['id']; ?>">
        <div class="col-12 fw-semibold"><?= $editF ? 'Edit facility' : 'New facility (hostel, house…)'; ?></div>
        <div class="col-12"><select name="branch_id" class="form-select form-select-sm"><?php foreach ($myBranches as $b) { ?><option value="<?= (int) $b['id']; ?>" <?= (int) $vf['branch_id'] === (int) $b['id'] ? 'selected' : ''; ?>><?= lc_e($b['name']); ?></option><?php } ?></select></div>
        <div class="col-12"><input name="name" class="form-control form-control-sm" placeholder="Name" value="<?= lc_e($vf['name']); ?>" required></div>
        <div class="col-6"><select name="gender" class="form-select form-select-sm"><?php foreach (['Mixed', 'Female', 'Male'] as $g) { ?><option <?= $vf['gender'] === $g ? 'selected' : ''; ?>><?= $g; ?></option><?php } ?></select></div>
        <div class="col-6"><select name="status" class="form-select form-select-sm"><option <?= $vf['status'] === 'Active' ? 'selected' : ''; ?>>Active</option><option <?= $vf['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option></select></div>
        <div class="col-12"><input name="address" class="form-control form-control-sm" placeholder="Address" value="<?= lc_e($vf['address']); ?>"></div>
        <div class="col-12"><textarea name="rules" class="form-control form-control-sm" rows="2" placeholder="House rules (shown to the student)"><?= lc_e($vf['rules']); ?></textarea></div>
        <div class="col-12"><button class="btn btn-sm btn-primary" <?= $myBranches ? '' : 'disabled'; ?>>Save facility</button></div></div></form>
    <form method="POST" class="card border-0 shadow-sm" id="room"><div class="card-body row g-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="room"><input type="hidden" name="tab" value="rooms"><input type="hidden" name="id" value="<?= (int) $vr['id']; ?>">
        <div class="col-12 fw-semibold"><?= $editR ? 'Edit room' : 'New room'; ?></div>
        <div class="col-12"><select name="facility_id" class="form-select form-select-sm" required><option value="">Facility…</option><?php foreach ($facilities as $f) { ?><option value="<?= (int) $f['id']; ?>" <?= (int) $vr['facility_id'] === (int) $f['id'] ? 'selected' : ''; ?>><?= lc_e($f['name']); ?></option><?php } ?></select></div>
        <div class="col-6"><input name="name" class="form-control form-control-sm" placeholder="Room (e.g. A1)" value="<?= lc_e($vr['name']); ?>" required></div>
        <div class="col-6"><input name="room_type" class="form-control form-control-sm" placeholder="Type (e.g. Double)" value="<?= lc_e($vr['room_type']); ?>" required list="rtypes"><datalist id="rtypes"><option>Single</option><option>Double</option><option>Dormitory</option></datalist></div>
        <div class="col-4"><label class="form-label small mb-0">Beds</label><input type="number" name="beds" min="1" max="50" class="form-control form-control-sm" value="<?= (int) $vr['beds']; ?>"></div>
        <div class="col-4"><label class="form-label small mb-0">Price (RWF)</label><input name="price" class="form-control form-control-sm" inputmode="decimal" value="<?= lc_e($vr['price']); ?>"></div>
        <div class="col-4"><label class="form-label small mb-0">Per</label><select name="price_unit" class="form-select form-select-sm"><?php foreach (LC_ACC_UNITS as $k => $l) { ?><option value="<?= $k; ?>" <?= $vr['price_unit'] === $k ? 'selected' : ''; ?>><?= str_replace('per ', '', $l); ?></option><?php } ?></select></div>
        <div class="col-6"><select name="status" class="form-select form-select-sm"><?php foreach (['Available', 'Maintenance', 'Closed'] as $s) { ?><option <?= $vr['status'] === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select></div>
        <div class="col-6"><input name="notes" class="form-control form-control-sm" placeholder="Note" value="<?= lc_e($vr['notes']); ?>"></div>
        <div class="col-12"><button class="btn btn-sm btn-primary" <?= $facilities ? '' : 'disabled'; ?>>Save room</button> <?php if ($editR || $editF) { ?><a href="?tab=rooms" class="btn btn-sm btn-light border">Close</a><?php } ?></div></div></form>
</div></div>
<?php
/* ============================================================ OCCUPANCY */
} elseif ($tab === 'occupancy') {
    $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day'] ?? '') ? $_GET['day'] : date('Y-m-d');
    $next = date('Y-m-d', strtotime($day . ' +1 day'));
    $who = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT b.room_id, b.bed_no, b.status, b.id, cu.name FROM lc_acc_bookings b JOIN customers cu ON cu.id = b.customer_id WHERE b.status IN ('Reserved', 'Checked in') AND b.start_date < '$next' AND b.end_date > '$day'"), MYSQLI_ASSOC) as $r) { $who[(int) $r['room_id']][(int) $r['bed_no']] = $r; }
    $tot = 0; $occ = 0;
    ?>
<form class="d-flex gap-2 mb-3"><input type="hidden" name="tab" value="occupancy"><input type="date" name="day" value="<?= $day; ?>" class="form-control form-control-sm" style="width:170px"><button class="btn btn-sm btn-primary">Show</button></form>
<div class="row g-3"><?php foreach ($facilities as $f) { $rooms = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM lc_acc_rooms WHERE facility_id = ' . (int) $f['id'] . ' ORDER BY name'), MYSQLI_ASSOC); ?>
    <div class="col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body"><div class="fw-semibold mb-2"><?= lc_e($f['name']); ?> <span class="small text-muted"><?= lc_e($f['gender']); ?></span></div>
        <?php foreach ($rooms as $r) { ?><div class="d-flex align-items-center gap-2 border-top py-1 small"><span style="width:90px"><strong><?= lc_e($r['name']); ?></strong> <span class="text-muted"><?= lc_e($r['room_type']); ?></span></span>
            <div class="d-flex flex-wrap gap-1"><?php for ($bd = 1; $bd <= (int) $r['beds']; $bd++) { $x = $who[(int) $r['id']][$bd] ?? null; if ($r['status'] === 'Available') { $tot++; if ($x) { $occ++; } } ?>
                <span class="badge <?= $r['status'] !== 'Available' ? 'bg-light text-muted border' : ($x ? ($x['status'] === 'Checked in' ? 'bg-primary' : 'bg-info text-dark') : 'bg-success'); ?>" title="<?= lc_e($x['name'] ?? ($r['status'] !== 'Available' ? $r['status'] : 'Free')); ?>">Bed <?= $bd; ?><?= $x ? ': ' . lc_e(strtok($x['name'], ' ')) : ''; ?></span><?php } ?></div></div><?php } ?>
    </div></div></div><?php } ?></div>
<div class="small text-muted mt-2"><?= $tot ? 'Occupancy on ' . date('d M Y', strtotime($day)) . ': ' . $occ . ' of ' . $tot . ' beds (' . round($occ / $tot * 100) . '%).' : ''; ?> <span class="badge bg-success">free</span> <span class="badge bg-info text-dark">reserved</span> <span class="badge bg-primary">checked in</span></div>
<?php
/* ============================================================ BOOKINGS */
} else {
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d'); $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') && $_GET['to'] > $from ? $_GET['to'] : date('Y-m-d', strtotime($from . ' +30 days'));
    $sel = !empty($_GET['booking']) ? lc_acc_booking($conn, (int) $_GET['booking']) : null;
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT b.*, r.name room, f.name facility, cu.name cname, a.ref FROM lc_acc_bookings b JOIN lc_acc_rooms r ON r.id = b.room_id JOIN lc_acc_facilities f ON f.id = r.facility_id
        JOIN customers cu ON cu.id = b.customer_id LEFT JOIN lc_applications a ON a.id = b.application_id WHERE " . lcs_branch_sql($conn, 'f.branch_id') . " ORDER BY CASE b.status WHEN 'Checked in' THEN 0 WHEN 'Reserved' THEN 1 ELSE 2 END, b.start_date LIMIT 500"), MYSQLI_ASSOC);
    // Admitted applicants / students who may get a bed (accepted, linked to a customer, branch offering accommodation); those who asked first.
    $cands = mysqli_fetch_all(mysqli_query($conn, "SELECT a.id, a.ref, a.full_name, a.accommodation, a.branch_id, c.code FROM lc_applications a JOIN lc_courses c ON c.id = a.course_id
        WHERE a.status = 'Accepted' AND a.customer_id IS NOT NULL AND " . lcs_branch_sql($conn, 'a.branch_id') . " ORDER BY a.accommodation DESC, a.full_name LIMIT 500"), MYSQLI_ASSOC);
    $avail = $branches ? lc_acc_availability($conn, $from, $to) : [];
    ?>
<div class="row g-3"><div class="col-lg-8">
    <?php if ($sel) { $inv = $sel['sale_id'] ? lc_invoice_by_sale($conn, (int) $sel['sale_id']) : null; ?>
    <div class="card border-0 shadow-sm mb-3 border-start border-4 border-primary"><div class="card-body">
        <div class="d-flex justify-content-between"><div><strong>Booking #<?= (int) $sel['id']; ?></strong> · <?= lc_e($sel['cname']); ?> <span class="text-muted small"><?= lc_e(lc_customer_id_label($conn, (int) $sel['customer_id'])); ?> · <?= lc_e($sel['ref']); ?></span></div><?= lcs_badge($sel['status']); ?></div>
        <div class="small mt-1"><?= lc_e($sel['facility']); ?> · room <?= lc_e($sel['room']); ?> (<?= lc_e($sel['room_type']); ?>) · bed <?= (int) $sel['bed_no']; ?> · <?= date('d M Y', strtotime($sel['start_date'])); ?> → <?= date('d M Y', strtotime($sel['end_date'])); ?> · <?= lc_money($sel['amount']); ?> (<?= lc_money($sel['price']); ?> <?= LC_ACC_UNITS[$sel['price_unit']] ?? ''; ?>)</div>
        <?php if ($sel['cancelled_reason']) { ?><div class="small text-danger">Cancelled: <?= lc_e($sel['cancelled_reason']); ?></div><?php } ?>
        <?php if ($inv) { ?><div class="small mt-1">Invoice <a href="../sales/invoice.php?id=<?= (int) $inv['sale_id']; ?>"><?= lc_e($inv['number']); ?></a> · <?= lc_e($inv['status']); ?> · balance <?= lc_money($inv['balance']); ?></div><?php } ?>
        <div class="d-flex flex-wrap gap-2 mt-2">
            <?php $hid = lcs_csrf_field() . '<input type="hidden" name="tab" value="bookings"><input type="hidden" name="booking_id" value="' . (int) $sel['id'] . '">'; ?>
            <?php if ($sel['status'] === 'Reserved') { ?><form method="POST"><?= $hid; ?><input type="hidden" name="do" value="checkin"><button class="btn btn-sm btn-primary">Check in</button></form>
                <form method="POST" onsubmit="var r=prompt('Reason of the cancellation:');if(!r)return false;this.reason.value=r;return true;"><?= $hid; ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="reason"><button class="btn btn-sm btn-outline-danger">Cancel</button></form><?php } ?>
            <?php if ($sel['status'] === 'Checked in') { ?><form method="POST" onsubmit="return confirm('Check out now? An early departure frees the bed from tomorrow.');"><?= $hid; ?><input type="hidden" name="do" value="checkout"><button class="btn btn-sm btn-outline-primary">Check out</button></form><?php } ?>
            <?php if (lcs_can($conn, 'finance') && $sel['status'] !== 'Cancelled' && (!$inv || $inv['cancelled'])) { ?>
            <form method="POST" class="d-flex gap-1 align-items-center"><?= $hid; ?><input type="hidden" name="do" value="bill"><span class="small">Bill</span><input name="amount" class="form-control form-control-sm" style="width:120px" value="<?= (float) $sel['amount']; ?>"><span class="small">due</span><input type="date" name="due_date" class="form-control form-control-sm" value="<?= lc_e($sel['start_date']); ?>"><button class="btn btn-sm btn-outline-success">Create invoice</button></form><?php } ?>
        </div>
    </div></div>
    <?php } ?>
    <div class="card border-0 shadow-sm"><div class="card-body p-0"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
        <tr class="table-light"><th class="ps-3">#</th><th>Student</th><th>Facility · room · bed</th><th>Dates</th><th class="text-end">Amount</th><th>Invoice</th><th>Status</th></tr>
        <?php foreach ($rows as $r) { ?><tr><td class="ps-3"><a href="?tab=bookings&booking=<?= (int) $r['id']; ?>"><?= (int) $r['id']; ?></a></td><td><?= lc_e($r['cname']); ?><div class="small text-muted"><?= lc_e($r['ref']); ?></div></td>
            <td class="small"><?= lc_e($r['facility']); ?> · <?= lc_e($r['room']); ?> · bed <?= (int) $r['bed_no']; ?></td><td class="small"><?= date('d M', strtotime($r['start_date'])); ?> → <?= date('d M Y', strtotime($r['end_date'])); ?></td>
            <td class="text-end"><?= lc_money($r['amount']); ?></td><td class="small"><?= $r['sale_id'] ? '<a href="../sales/invoice.php?id=' . (int) $r['sale_id'] . '">' . lc_sale_number((int) $r['sale_id']) . '</a>' : '—'; ?></td><td><?= lcs_badge($r['status']); ?></td></tr><?php } ?>
        <?php if (!$rows) { ?><tr><td colspan="7" class="text-center text-muted py-4">No booking yet.</td></tr><?php } ?>
    </table></div></div>
</div>
<div class="col-lg-4">
    <form class="card border-0 shadow-sm mb-2"><div class="card-body py-2"><input type="hidden" name="tab" value="bookings"><div class="small fw-semibold mb-1">Free beds for</div>
        <div class="d-flex gap-1 mb-1"><input type="date" name="from" value="<?= $from; ?>" class="form-control form-control-sm"><input type="date" name="to" value="<?= $to; ?>" class="form-control form-control-sm"></div><button class="btn btn-sm btn-light border w-100">Check availability</button></div></form>
    <form method="POST" class="card border-0 shadow-sm"><div class="card-body row g-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="book"><input type="hidden" name="tab" value="bookings"><input type="hidden" name="start_date" value="<?= $from; ?>"><input type="hidden" name="end_date" value="<?= $to; ?>">
        <div class="col-12 fw-semibold">Reserve a bed · <?= date('d M', strtotime($from)); ?> → <?= date('d M Y', strtotime($to)); ?></div>
        <div class="col-12"><select name="application_id" class="form-select form-select-sm" required><option value="">Admitted applicant / student…</option><?php foreach ($cands as $c) { ?><option value="<?= (int) $c['id']; ?>"><?= lc_e($c['full_name'] . ' · ' . $c['code'] . ' · ' . $c['ref'] . ($c['accommodation'] ? ' ★ asked for accommodation' : '')); ?></option><?php } ?></select></div>
        <div class="col-12"><select name="bed" class="form-select form-select-sm" required><option value="">Free bed…</option><?php foreach ($avail as $rid => $r) { foreach ($r['free'] as $bd) { ?><option value="<?= $rid . ':' . $bd; ?>"><?= lc_e($r['facility'] . ' (' . $r['gender'] . ') · ' . $r['name'] . ' ' . $r['room_type'] . ' · bed ' . $bd . ' · ' . lc_money(lc_acc_price($r, $from, $to))); ?></option><?php } } ?></select></div>
        <div class="col-12"><input name="notes" class="form-control form-control-sm" placeholder="Note (optional)"></div>
        <div class="col-12"><button class="btn btn-sm btn-primary" <?= $avail ? '' : 'disabled'; ?>>Reserve</button></div>
        <div class="col-12 small text-muted">★ = asked for accommodation on the application. A bed can never be booked twice for the same nights. Price: nights, started weeks or started months (30 days).</div>
    </div></form>
</div></div>
<?php }
include __DIR__ . '/../../includes/footer.php';