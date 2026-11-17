<?php
/**
 * NEW FILE: modules/learning/application.php — review ONE application: details and documents, internal notes,
 * requests for information, status changes (recorded with user, date and time), admission decision and offer,
 * customer linking (no duplicates), and student registration (Student ID = Customer ID).
 * Paying an application fee never means admission; submitting never means registration.
 */
require __DIR__ . '/common.php';
lcs_require($conn, 'admissions');
$id = (int) ($_GET['id'] ?? 0);
$a = lc_application($conn, $id);
if (!$a) { lcs_go('applications.php', 'error', 'Application not found.'); }
if (!lcs_branch_ok($conn, (int) $a['branch_id'] ?: null)) { lc_audit($conn, 'access_denied', 'application', $id); lcs_deny(); }
$back = 'application.php?id=' . $id;
$course = lc_course($conn, (int) $a['course_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { lcs_go($back, 'error', 'The page had expired.'); }
    $do = $_POST['do'] ?? ''; $note = trim((string) ($_POST['note'] ?? ''));
    if ($a['registered_at'] && $do !== 'note') { lcs_go($back, 'error', 'This applicant is registered: the application can no longer change.'); }
    if ($do === 'note') {
        if ($note === '') { lcs_go($back, 'error', 'Write the note.'); }
        lc_log($conn, $id, 'note', null, null, $note, false);
        lcs_go($back, 'success', 'Internal note added (the applicant never sees it).');
    }
    if ($do === 'status') {
        $new = (string) ($_POST['status'] ?? '');
        if (!in_array($new, LC_APP_STATUSES, true) || $new === 'Draft' || $new === $a['status']) { lcs_go($back, 'error', 'Choose a different status.'); }
        if (in_array($new, ['Rejected', 'Withdrawn', 'Waitlisted'], true) && mb_strlen($note) < 3) { lcs_go($back, 'error', 'Write the reason (it is shown to the applicant).'); }
        if ($new === 'Accepted') {
            if ($a['intake_id']) {
                $in = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT capacity FROM lc_intakes WHERE id = ' . (int) $a['intake_id']));
                if ($in && $in['capacity'] !== null && lc_intake_taken($conn, (int) $a['intake_id']) >= (int) $in['capacity']) { lcs_go($back, 'error', 'The intake is full. Put the applicant on the waiting list or move them to another intake.'); }
            }
            $missing = [];
            foreach (lc_lines($course['required_documents']) as $d) { if (substr($d, -1) !== '?' && !(int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_application_docs WHERE application_id = $id AND label = '" . mysqli_real_escape_string($conn, $d) . "'"))[0]) { $missing[] = $d; } }
            if ($missing && empty($_POST['override_docs'])) { lcs_go($back, 'error', 'Missing required document(s): ' . implode(', ', $missing) . '. Tick "admit anyway" to confirm.'); }
        }
        $uid = (int) current_user_id();
        $offer = $new === 'Accepted' ? 'Pending' : ($a['status'] === 'Accepted' ? null : $a['offer_status']);
        $s = mysqli_prepare($conn, 'UPDATE lc_applications SET status = ?, offer_status = ?, offer_note = ?, decided_by = ?, decided_at = NOW(), updated_at = NOW() WHERE id = ?');
        $on = $new === 'Accepted' ? ($note ?: null) : $a['offer_note'];
        mysqli_stmt_bind_param($s, 'sssii', $new, $offer, $on, $uid, $id);
        mysqli_stmt_execute($s);
        $public = !empty($_POST['public']) || in_array($new, ['Rejected', 'Waitlisted', 'Accepted', 'Withdrawn'], true);
        lc_log($conn, $id, 'status', $a['status'], $new, $note ?: null, $public);
        lc_audit($conn, 'application_status', 'application', $id, ['from' => $a['status'], 'to' => $new]);
        $a2 = lc_application($conn, $id);
        lc_notify_applicant($conn, $a2, $new === 'Accepted' ? 'tpl_admitted' : 'tpl_status');
        lcs_go($back, 'success', 'Status changed to ' . $new . '. The applicant was notified.');
    }
    if ($do === 'request') {
        if (mb_strlen($note) < 5) { lcs_go($back, 'error', 'Write what is missing.'); }
        mysqli_query($conn, "UPDATE lc_applications SET status = 'Additional Information Required', updated_at = NOW() WHERE id = $id");
        lc_log($conn, $id, 'request', $a['status'], 'Additional Information Required', $note, true);
        lc_audit($conn, 'application_request', 'application', $id, ['message' => $note]);
        lc_notify_applicant($conn, lc_application($conn, $id), 'tpl_info', ['{message}' => mb_substr($note, 0, 100)]);
        lcs_go($back, 'success', 'Request sent. The applicant can upload documents and reply on the tracking page or in the Customer Portal.');
    }
    if ($do === 'link') {
        $cid = (int) ($_POST['customer_id'] ?? 0);
        $ok = array_filter(lc_customer_matches($conn, $a), function ($m) use ($cid) { return (int) $m['id'] === $cid; });
        if (!$ok && empty($_POST['confirm_other'])) { lcs_go($back, 'error', 'This customer does not share the telephone or email of the application. Tick the confirmation after checking the identity.'); }
        if (!(int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM customers WHERE id = $cid"))[0]) { lcs_go($back, 'error', 'Customer not found.'); }
        lc_link_customer($conn, $id, $cid, 'staff verified' . ($note ? ': ' . $note : ''));
        lcs_go($back, 'success', 'Linked to customer ' . lc_customer_id_label($conn, $cid) . '. The Customer ID does not change.');
    }
    if ($do === 'create_customer') {
        if ($a['customer_id']) { lcs_go($back, 'error', 'Already linked to a customer.'); }
        if ($a['status'] !== 'Accepted') { lcs_go($back, 'error', 'Customer records are created at admission (status Accepted), not for every applicant.'); }
        try { $cid = lc_create_customer($conn, $a); } catch (RuntimeException $e) { lcs_go($back, 'error', $e->getMessage()); }
        lc_link_customer($conn, $id, $cid, 'new customer created at admission');
        lcs_go($back, 'success', 'Customer created: ' . lc_customer_id_label($conn, $cid) . '. This Customer ID will be the Student ID.');
    }
    if ($do === 'offer') {
        $resp = ($_POST['response'] ?? '') === 'Accepted' ? 'Accepted' : 'Declined';
        if ($a['status'] !== 'Accepted') { lcs_go($back, 'error', 'There is no admission offer.'); }
        if (mb_strlen($note) < 3) { lcs_go($back, 'error', 'Note how the applicant answered (call, visit, message).'); }
        mysqli_query($conn, "UPDATE lc_applications SET offer_status = '$resp', updated_at = NOW() WHERE id = $id");
        lc_log($conn, $id, 'offer', null, 'Offer ' . strtolower($resp), 'Recorded by staff: ' . $note, true);
        lc_audit($conn, 'offer_' . strtolower($resp), 'application', $id, ['recorded_by_staff' => $note]);
        lcs_go($back, 'success', 'Offer ' . strtolower($resp) . '.');
    }
    if ($do === 'register') {
        try { lc_register_student($conn, $a, trim((string) ($_POST['cohort'] ?? ''))); } catch (Throwable $e) { lcs_go($back, 'error', $e->getMessage()); }
        lcs_go($back, 'success', 'Registered. Student ID ' . lc_customer_id_label($conn, (int) $a['customer_id']) . ' (= Customer ID).');
    }
    if ($do === 'move') {
        $new = (int) ($_POST['intake_id'] ?? 0);
        $open = lc_intakes($conn, (int) $a['course_id']);
        if (!isset($open[$new]) || $open[$new]['status'] !== 'Open' || !lcs_branch_ok($conn, (int) $open[$new]['branch_id'] ?: null)) { lcs_go($back, 'error', 'Choose an open intake you manage.'); }
        if (mb_strlen($note) < 3) { lcs_go($back, 'error', 'Write the reason for the move.'); }
        $old = $a['intake_name'] . ' (' . lc_branch_name($conn, (int) $a['branch_id'] ?: null) . ')';
        $b = (int) $open[$new]['branch_id'] ?: null;
        $s = mysqli_prepare($conn, 'UPDATE lc_applications SET intake_id = ?, branch_id = ?, updated_at = NOW() WHERE id = ?');
        mysqli_stmt_bind_param($s, 'iii', $new, $b, $id);
        mysqli_stmt_execute($s);
        lc_log($conn, $id, 'move', null, null, 'Moved from ' . $old . ' to ' . $open[$new]['name'] . ' (' . $open[$new]['branch_name'] . '): ' . $note, true);
        lc_audit($conn, 'application_move', 'application', $id, ['from' => $a['intake_id'], 'to' => $new, 'reason' => $note]);
        lcs_go($back, 'success', 'Moved to ' . $open[$new]['name'] . '.');
    }
    lcs_go($back, 'error', 'Unknown action.');
}

$docs = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM lc_application_docs WHERE application_id = $id ORDER BY id"), MYSQLI_ASSOC);
$log = mysqli_fetch_all(mysqli_query($conn, "SELECT l.*, u.names FROM lc_application_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.application_id = $id ORDER BY l.id DESC"), MYSQLI_ASSOC);
$matches = $a['customer_id'] ? [] : lc_customer_matches($conn, $a);
$cust = $a['customer_id'] ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, name, phone, email FROM customers WHERE id = ' . (int) $a['customer_id'])) : null;
$modNames = $a['modules'] ? array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT name FROM lc_modules WHERE id IN (' . implode(',', array_map('intval', explode(',', $a['modules']))) . ')'), MYSQLI_ASSOC), 'name') : [];
$answers = json_decode((string) $a['answers'], true) ?: [];
$other = mysqli_fetch_all(mysqli_query($conn, "SELECT a.id, a.ref, a.status, c.code FROM lc_applications a JOIN lc_courses c ON c.id = a.course_id WHERE a.phone_key = '" . mysqli_real_escape_string($conn, $a['phone_key']) . "' AND a.id <> $id ORDER BY a.id DESC"), MYSQLI_ASSOC);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'applications.php', $a['ref'] . ' · ' . $a['full_name']);
$row = function ($l, $v) { if ($v === null || $v === '') { return; } echo '<tr><td class="text-muted" style="width:190px;">' . lc_e($l) . '</td><td>' . nl2br(lc_e($v)) . '</td></tr>'; };
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <?= $a['registered_at'] ? lcs_badge('Registered') : lcs_badge($a['status']); ?><?= $a['status'] === 'Accepted' ? ' <span class="badge bg-light text-dark border">Offer: ' . lc_e($a['offer_status'] ?: 'Pending') . '</span>' : ''; ?>
    <span class="small text-muted"><?= lc_e($a['code'] ?? $a['course_code']); ?> <?= lc_e($a['course_title']); ?> · <?= lc_e($a['intake_name']); ?> · <?= lc_e(lc_branch_name($conn, (int) $a['branch_id'] ?: null)); ?> · source <?= lc_e(LC_SOURCES[$a['source']] ?? $a['source']); ?><?= $a['campaign'] ? ' (' . lc_e($a['campaign']) . ')' : ''; ?> · <?= $a['channel'] === 'portal' ? 'Customer Portal' : 'public website'; ?></span>
</div>
<div class="row g-3">
<div class="col-xl-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6>Applicant</h6><table class="table table-sm small mb-0">
        <?php $row('Full name', $a['full_name']); $row('Telephone', $a['phone']); $row('Email', $a['email']); $row('Date of birth', $a['dob']); $row('Gender', $a['gender']); $row('National ID / passport', $a['national_id']);
        $row('Address', trim(implode(', ', array_filter([$a['address'], $a['sector'], $a['district'], $a['province']])))); $row('Education', $a['education']); $row('Occupation', $a['employment']);
        $row('Motivation', $a['motivation']); $row('Training mode', $a['mode']); $row('Modules chosen', implode(', ', $modNames)); $row('Accommodation', $a['accommodation'] ? 'Interested' : null);
        foreach ($answers as $x) { $row($x['q'], $x['a']); } $row('Submitted', $a['submitted_at']); ?>
    </table></div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6>Documents</h6>
        <?php foreach ($docs as $d) { ?><div class="small border-top py-1"><i class="bi bi-paperclip"></i> <a href="file.php?id=<?= (int) $d['id']; ?>" target="_blank"><?= lc_e($d['label']); ?></a> <span class="text-muted"><?= lc_e($d['original_name']); ?> · <?= lc_e($d['created_at']); ?></span></div><?php } ?>
        <?php if (!$docs) { ?><div class="small text-muted">No document.</div><?php } ?>
        <?php $req = lc_lines($course['required_documents']); $have = array_column($docs, 'label'); foreach ($req as $r) { if (substr($r, -1) !== '?' && !in_array($r, $have, true)) { ?><div class="small text-danger">Missing: <?= lc_e($r); ?></div><?php } } ?>
    </div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6>History</h6>
        <?php foreach ($log as $l) { ?><div class="small border-top py-1"><strong><?= lc_e($l['to_status'] ?: ucfirst($l['kind'])); ?></strong><?= $l['from_status'] ? ' <span class="text-muted">(from ' . lc_e($l['from_status']) . ')</span>' : ''; ?> · <?= lc_e($l['names'] ?: ($l['user_id'] ? '#' . $l['user_id'] : 'Applicant / system')); ?> · <span class="text-muted"><?= lc_e($l['created_at']); ?></span>
            <?= $l['public'] ? '<span class="badge bg-light text-dark border ms-1">visible to applicant</span>' : '<span class="badge bg-dark ms-1">internal</span>'; ?><?= $l['note'] ? '<div>' . nl2br(lc_e($l['note'])) . '</div>' : ''; ?></div><?php } ?>
    </div></div>
</div>
<div class="col-xl-5">
    <?php if (!$a['registered_at']) { ?>
    <div class="card border-0 shadow-sm mb-3 border-start border-4 border-primary"><div class="card-body">
        <h6>Decision</h6>
        <form method="POST" class="mb-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="status">
            <select name="status" class="form-select form-select-sm mb-1"><?php foreach (LC_APP_STATUSES as $s) { if ($s === 'Draft') { continue; } ?><option <?= $a['status'] === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select>
            <textarea name="note" class="form-control form-control-sm mb-1" rows="2" placeholder="Message / reason (required to reject, waitlist or withdraw)"></textarea>
            <div class="form-check small"><input class="form-check-input" type="checkbox" name="public" value="1" id="pub" checked><label class="form-check-label" for="pub">Show the message to the applicant</label></div>
            <div class="form-check small"><input class="form-check-input" type="checkbox" name="override_docs" value="1" id="ov"><label class="form-check-label" for="ov">Admit anyway if a required document is missing</label></div>
            <button class="btn btn-sm btn-primary mt-1">Change status</button>
        </form>
        <div class="small text-muted">"Application Fee Paid" does not admit anyone: admission is only "Accepted". Every change is recorded with your name, date and time.</div>
        <form method="POST" class="mt-3 border-top pt-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="request">
            <label class="form-label small fw-semibold">Request information / documents</label><textarea name="note" class="form-control form-control-sm mb-1" rows="2" placeholder="e.g. Please upload a clearer copy of your ID"></textarea><button class="btn btn-sm btn-outline-warning">Send request</button></form>
        <?php if ($a['status'] === 'Accepted') { ?>
        <form method="POST" class="mt-3 border-top pt-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="offer">
            <label class="form-label small fw-semibold">Record the applicant's answer to the offer (if given by phone / in person)</label><input name="note" class="form-control form-control-sm mb-1" placeholder="How the applicant answered">
            <button name="response" value="Accepted" class="btn btn-sm btn-outline-success">Offer accepted</button> <button name="response" value="Declined" class="btn btn-sm btn-outline-danger">Offer declined</button></form>
        <?php } ?>
        <?php $ins = array_filter(lc_intakes($conn, (int) $a['course_id']), function ($i) use ($a) { return $i['status'] === 'Open' && (int) $i['id'] !== (int) $a['intake_id']; }); if ($ins) { ?>
        <form method="POST" class="mt-3 border-top pt-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="move">
            <label class="form-label small fw-semibold">Move to another intake / branch</label><div class="d-flex gap-1"><select name="intake_id" class="form-select form-select-sm"><?php foreach ($ins as $i) { ?><option value="<?= (int) $i['id']; ?>"><?= lc_e($i['name'] . ' · ' . $i['branch_name']); ?></option><?php } ?></select></div>
            <input name="note" class="form-control form-control-sm my-1" placeholder="Reason"><button class="btn btn-sm btn-outline-secondary">Move</button></form>
        <?php } ?>
    </div></div>
    <?php } ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h6>Customer &amp; student</h6>
        <?php if ($cust) { ?>
            <div class="small">Linked to <strong><?= lc_e($cust['name']); ?></strong> · Customer ID <strong><?= lc_e(lc_customer_id_label($conn, (int) $cust['id'])); ?></strong><br><span class="text-muted"><?= lc_e($cust['phone'] . ($cust['email'] ? ' · ' . $cust['email'] : '')); ?></span></div>
            <?php if ($a['registered_at']) { ?><div class="alert alert-success small mt-2 mb-0">Registered student since <?= lc_e($a['registered_at']); ?>. <strong>Student ID: <?= lc_e(lc_customer_id_label($conn, (int) $cust['id'])); ?></strong> (same as the Customer ID)<?= $a['cohort'] ? ' · cohort ' . lc_e($a['cohort']) : ''; ?>.</div>
            <?php } elseif ($a['status'] === 'Accepted' && $a['offer_status'] === 'Accepted') { ?>
            <form method="POST" class="mt-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="register"><input name="cohort" class="form-control form-control-sm mb-1" placeholder="Cohort / class (e.g. WDF-JAN27-A)">
                <button class="btn btn-sm btn-success">Register as student (Student ID = <?= lc_e(lc_customer_id_label($conn, (int) $cust['id'])); ?>)</button></form>
            <?php } else { ?><div class="small text-muted mt-2">Registration opens when the applicant is admitted (Accepted) and has accepted the offer.</div><?php } ?>
        <?php } else { ?>
            <div class="small text-muted mb-2">Guest application (no customer linked). Link it only when the identity is verified. Never create a second customer for the same person.</div>
            <?php foreach ($matches as $m) { ?><form method="POST" class="d-flex justify-content-between align-items-center border-top py-1 small"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="link"><input type="hidden" name="customer_id" value="<?= (int) $m['id']; ?>">
                <span><strong><?= lc_e($m['name']); ?></strong> · <?= lc_e(lc_customer_id_label($conn, (int) $m['id'])); ?><br><span class="text-muted"><?= lc_e($m['phone'] . ($m['email'] ? ' · ' . $m['email'] : '')); ?></span></span><button class="btn btn-sm btn-outline-primary">Link</button></form><?php } ?>
            <?php if (!$matches) { ?><div class="small">No existing customer has this telephone or email.</div><?php } ?>
            <form method="POST" class="mt-2 border-top pt-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="link">
                <div class="input-group input-group-sm"><span class="input-group-text">Customer #</span><input name="customer_id" type="number" min="1" class="form-control" placeholder="id"><button class="btn btn-outline-secondary">Link other</button></div>
                <div class="form-check small"><input class="form-check-input" type="checkbox" name="confirm_other" value="1" id="co"><label class="form-check-label" for="co">I checked this is the same person (different phone/email)</label></div></form>
            <?php if ($a['status'] === 'Accepted' && !$matches) { ?><form method="POST" class="mt-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="create_customer"><button class="btn btn-sm btn-primary" onclick="return confirm('Create ONE new customer record for this applicant?');">Create the customer record (new Customer ID)</button></form><?php } ?>
        <?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h6>Internal note</h6>
        <form method="POST"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="note"><textarea name="note" class="form-control form-control-sm mb-1" rows="2"></textarea><button class="btn btn-sm btn-outline-dark">Add note</button></form>
    </div></div>
    <?php if ($other) { ?><div class="card border-0 shadow-sm"><div class="card-body small"><h6>Other applications with this telephone</h6>
        <?php foreach ($other as $o) { ?><div><a href="?id=<?= (int) $o['id']; ?>"><?= lc_e($o['ref']); ?></a> · <?= lc_e($o['code']); ?> · <?= lc_e($o['status']); ?></div><?php } ?></div></div><?php } ?>
</div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>