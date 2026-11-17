<?php
/**
 * NEW FILE: portal/learning.php — "RM Learning Centre" in the Customer Portal.
 * Tabs: Explore courses · My applications (with the details of one application) · My learning (Student ID = Customer ID).
 * A guest application made on the public website can be added here with its reference, only when its telephone number
 * is the customer's own number (no one can attach somebody else's application).
 */
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/lc_helpers.php';
require_once __DIR__ . '/../includes/evidence_store.php';
lc_ensure($conn);
$tab = in_array($_GET['tab'] ?? '', ['explore', 'apps', 'learning'], true) ? $_GET['tab'] : 'apps';
$myKey = lc_phone_key((string) $me['phone']);
$go = function (string $q, string $k, string $m) { header('Location: learning.php?' . $q . '&' . $k . '=' . urlencode($m)); exit; };
$appRef = strtoupper(trim((string) ($_GET['app'] ?? $_POST['app'] ?? '')));
$mine = function (string $ref) use ($conn, $cid): ?array {
    $s = mysqli_prepare($conn, 'SELECT id FROM lc_applications WHERE ref = ? AND customer_id = ?'); mysqli_stmt_bind_param($s, 'si', $ref, $cid); mysqli_stmt_execute($s);
    $id = (int) (mysqli_fetch_row(mysqli_stmt_get_result($s))[0] ?? 0);
    return $id ? lc_application($conn, $id) : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    try {
        if (!portal_csrf_ok()) { throw new RuntimeException('Your session expired. Please try again.'); }
        if ($do === 'claim') {
            if (!lc_rate_ok($conn, 'claim', 10, 15)) { throw new RuntimeException('Too many attempts. Please wait 15 minutes.'); }
            lc_rate_hit($conn, 'claim');
            $s = mysqli_prepare($conn, 'SELECT id, phone_key, customer_id FROM lc_applications WHERE ref = ?'); mysqli_stmt_bind_param($s, 's', $appRef); mysqli_stmt_execute($s);
            $a = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
            // Same message whether the reference exists or not (nothing is revealed about other people's applications).
            if (!$a || strlen($myKey) !== 9 || !hash_equals($a['phone_key'], $myKey) || ($a['customer_id'] && (int) $a['customer_id'] !== $cid)) {
                throw new RuntimeException('This reference could not be added. It must be an application made with your own telephone number (' . $me['phone'] . '). Otherwise please contact us.');
            }
            if (!$a['customer_id']) { lc_link_customer($conn, (int) $a['id'], $cid, 'added by the customer in the portal, telephone matched'); }
            $go('app=' . rawurlencode($appRef), 'success', 'The application was added to your account.');
        }
        $a = $mine($appRef);
        if (!$a) { throw new RuntimeException('Application not found.'); }
        $aid = (int) $a['id'];
        if ($do === 'accept' || $do === 'decline') {
            if ($a['status'] !== 'Accepted' || ($a['offer_status'] ?: 'Pending') !== 'Pending') { throw new RuntimeException('There is no admission offer waiting for your answer.'); }
            $resp = $do === 'accept' ? 'Accepted' : 'Declined';
            mysqli_query($conn, "UPDATE lc_applications SET offer_status = '$resp', updated_at = NOW() WHERE id = $aid");
            lc_log($conn, $aid, 'offer', null, 'Offer ' . strtolower($resp), 'The applicant ' . strtolower($resp) . ' the admission offer in the Customer Portal.', true);
            lc_audit($conn, 'offer_' . strtolower($resp), 'application', $aid, ['portal' => true], $cid);
            lc_notify_staff($conn, 'Offer ' . strtolower($resp) . ': ' . $a['ref'], $a['full_name'] . ' ' . strtolower($resp) . ' the offer for ' . $a['course_title'] . '.');
            $go('app=' . rawurlencode($a['ref']), 'success', $resp === 'Accepted' ? 'You accepted your offer. We will contact you to complete your registration.' : 'You declined the offer.');
        }
        if ($do === 'reply') {
            if (!in_array($a['status'], LC_APP_OPEN, true) || $a['registered_at']) { throw new RuntimeException('This application can no longer be changed here.'); }
            $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 2000); $n = 0; $files = $_FILES['files'] ?? null;
            if ($files && is_array($files['name'])) {
                foreach (array_keys($files['name']) as $i) {
                    if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || $n >= 5) { continue; }
                    $f = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                    if ($fref = evidence_save($conn, $f, null)) { $label = mb_substr(trim((string) ($_POST['label'] ?? '')) ?: 'Additional document', 0, 150); $on = mb_substr((string) $f['name'], 0, 255);
                        $d = mysqli_prepare($conn, 'INSERT INTO lc_application_docs (application_id, label, file_ref, original_name) VALUES (?, ?, ?, ?)');
                        mysqli_stmt_bind_param($d, 'isss', $aid, $label, $fref, $on); mysqli_stmt_execute($d); $n++; }
                }
            }
            if ($note === '' && !$n) { throw new RuntimeException('Please write a message or attach a document.'); }
            lc_log($conn, $aid, 'reply', null, null, 'Applicant replied' . ($n ? ' and sent ' . $n . ' document(s)' : '') . ($note !== '' ? ': ' . $note : '.'), true);
            lc_audit($conn, 'applicant_reply', 'application', $aid, ['files' => $n, 'portal' => true], $cid);
            lc_notify_staff($conn, 'Applicant replied: ' . $a['ref'], $a['full_name'] . ' answered in the Customer Portal' . ($n ? ' (' . $n . ' document(s))' : '') . '.');
            $go('app=' . rawurlencode($a['ref']), 'success', 'Your answer was sent to the admissions team.');
        }
        if ($do === 'withdraw') {
            if (!in_array($a['status'], LC_APP_OPEN, true) || $a['registered_at'] || empty($_POST['confirm'])) { throw new RuntimeException('Please tick the box to confirm the withdrawal.'); }
            mysqli_query($conn, "UPDATE lc_applications SET status = 'Withdrawn', updated_at = NOW() WHERE id = $aid");
            lc_log($conn, $aid, 'status', $a['status'], 'Withdrawn', 'Withdrawn by the applicant in the Customer Portal.', true);
            lc_audit($conn, 'application_withdraw', 'application', $aid, ['portal' => true], $cid);
            $go('app=' . rawurlencode($a['ref']), 'success', 'Your application was withdrawn.');
        }
    } catch (RuntimeException $e) { $go($appRef !== '' && $do !== 'claim' ? 'app=' . rawurlencode($appRef) : 'tab=apps', 'error', $e->getMessage()); }
}

$sid = lc_customer_id_label($conn, $cid);
$isStudent = lc_is_student($conn, $cid);
portal_header('RM Learning Centre', $me);
portal_alerts();
$tabs = ['explore' => ['compass', 'Explore courses'], 'apps' => ['file-earmark-text', 'My applications'], 'learning' => ['mortarboard', 'My learning']];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h1 class="h4 fw-bold mb-0">RM Learning Centre</h1><div class="small text-muted">Your <?= $isStudent ? 'Student ID' : 'Customer ID'; ?>: <strong><?= portal_e($sid); ?></strong><?= $isStudent ? ' (same as your Customer ID)' : ''; ?></div></div>
    <a href="<?= portal_e(lc_public_base($conn)); ?>/" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-up-right"></i> Learning Centre website</a>
</div>
<ul class="nav nav-pills mb-3 small"><?php foreach ($tabs as $k => [$i, $l]) { ?><li class="nav-item"><a class="nav-link <?= $tab === $k && $appRef === '' ? 'active' : ''; ?>" href="learning.php?tab=<?= $k; ?>"><i class="bi bi-<?= $i; ?>"></i> <?= $l; ?></a></li><?php } ?></ul>
<?php
if ($appRef !== '' && ($a = $mine($appRef))) {
    $hist = lc_public_history($conn, (int) $a['id']);
    $req = null; foreach ($hist as $h) { if ($h['kind'] === 'request') { $req = $h; } }
    $docs = mysqli_fetch_all(mysqli_query($conn, 'SELECT label, original_name FROM lc_application_docs WHERE application_id = ' . (int) $a['id'] . ' ORDER BY id'), MYSQLI_ASSOC);
    $open = in_array($a['status'], LC_APP_OPEN, true) && !$a['registered_at'];
    ?>
    <div class="small mb-2"><a href="learning.php?tab=apps"><i class="bi bi-arrow-left"></i> My applications</a></div>
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-2"><div><div class="small text-muted">Application <?= portal_e($a['ref']); ?></div><div class="h5 fw-bold mb-1"><?= portal_e($a['course_title']); ?></div>
            <div class="small text-muted"><?= portal_e($a['intake_name'] ?? ''); ?> · <?= portal_e(lc_branch_name($conn, $a['branch_id'] ? (int) $a['branch_id'] : null)); ?><?= $a['mode'] ? ' · ' . portal_e($a['mode']) : ''; ?></div></div>
            <div><span class="badge bg-<?= $a['registered_at'] ? 'success' : 'primary'; ?>"><?= portal_e($a['registered_at'] ? 'Registered' : $a['status']); ?></span><?= $a['status'] === 'Accepted' ? ' <span class="badge bg-light text-dark border">Offer: ' . portal_e($a['offer_status'] ?: 'Pending') . '</span>' : ''; ?></div></div>
        <p class="small mt-2 mb-0"><?= $a['registered_at'] ? 'You are registered. Your Student ID is <strong>' . portal_e($sid) . '</strong>.' : portal_e(lc_status_help($a['status'], $a['offer_status'])); ?></p>
        <?php if ($a['status'] === 'Accepted' && ($a['offer_status'] ?: 'Pending') === 'Pending') { ?>
        <div class="border rounded p-2 mt-2 bg-light"><?php if ($a['offer_note']) { ?><div class="small mb-2" style="white-space:pre-line;"><?= portal_e($a['offer_note']); ?></div><?php } ?>
            <form method="POST" class="d-inline"><?= portal_csrf_field(); ?><input type="hidden" name="app" value="<?= portal_e($a['ref']); ?>"><input type="hidden" name="do" value="accept"><button class="btn btn-sm btn-success">Accept the offer</button></form>
            <form method="POST" class="d-inline" onsubmit="return confirm('Decline the admission offer?');"><?= portal_csrf_field(); ?><input type="hidden" name="app" value="<?= portal_e($a['ref']); ?>"><input type="hidden" name="do" value="decline"><button class="btn btn-sm btn-outline-danger">Decline</button></form></div>
        <?php } ?>
    </div></div>
    <?php if ($open) { ?>
    <div class="card mb-3"><div class="card-body"><div class="fw-bold small mb-1"><?= $a['status'] === 'Additional Information Required' ? 'Information requested' : 'Send a message or a document'; ?></div>
        <?php if ($req && $a['status'] === 'Additional Information Required') { ?><div class="alert alert-warning small py-2"><?= portal_e($req['note']); ?></div><?php } ?>
        <form method="POST" enctype="multipart/form-data"><?= portal_csrf_field(); ?><input type="hidden" name="app" value="<?= portal_e($a['ref']); ?>"><input type="hidden" name="do" value="reply">
            <textarea name="note" class="form-control form-control-sm mb-2" rows="2" maxlength="2000" placeholder="Your message"></textarea>
            <div class="row g-2"><div class="col-md-5"><input name="label" class="form-control form-control-sm" maxlength="150" placeholder="What is the document?"></div><div class="col-md-5"><input type="file" name="files[]" multiple class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png"></div><div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Send</button></div></div></form>
    </div></div>
    <?php } ?>
    <div class="row g-3"><div class="col-md-7"><div class="card h-100"><div class="card-body"><div class="fw-bold small mb-2">History</div>
        <?php foreach (array_reverse($hist) as $h) { ?><div class="border-start border-3 ps-2 mb-2 small"><div class="text-muted"><?= date('d M Y H:i', strtotime($h['created_at'])); ?></div><?= $h['to_status'] ? '<strong>' . portal_e($h['to_status']) . '</strong>' : ''; ?><?= $h['note'] ? '<div>' . portal_e($h['note']) . '</div>' : ''; ?></div><?php } ?></div></div></div>
        <div class="col-md-5"><div class="card h-100"><div class="card-body"><div class="fw-bold small mb-2">Documents</div><?php foreach ($docs as $d) { ?><div class="small"><i class="bi bi-paperclip"></i> <?= portal_e($d['label']); ?></div><?php } ?><?= $docs ? '' : '<div class="small text-muted">None.</div>'; ?></div></div></div></div>
    <?php if ($open) { ?><details class="mt-3"><summary class="small text-muted">Withdraw this application</summary><form method="POST" class="mt-2"><?= portal_csrf_field(); ?><input type="hidden" name="app" value="<?= portal_e($a['ref']); ?>"><input type="hidden" name="do" value="withdraw">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="wd"><label class="form-check-label small" for="wd">I want to withdraw this application.</label></div><button class="btn btn-sm btn-outline-danger mt-1">Withdraw</button></form></details><?php } ?>
<?php } elseif ($tab === 'explore') {
    $courses = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM lc_courses WHERE status IN ('" . implode("','", LC_PUBLIC_VISIBLE) . "') ORDER BY (status = 'Applications Open') DESC, title"), MYSQLI_ASSOC);
    ?>
    <div class="row g-3"><?php foreach ($courses as $c) { $av = lc_availability($conn, $c); $ok = lc_course_accepting($conn, $c)[0]; ?>
        <div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-body d-flex flex-column">
            <div class="d-flex justify-content-between gap-2"><span class="small text-muted"><?= portal_e($c['category']); ?> · <?= portal_e($c['code']); ?></span><span class="badge bg-<?= $av[1]; ?>"><?= portal_e($av[0]); ?></span></div>
            <div class="fw-bold mt-1"><?= portal_e($c['title']); ?></div><div class="small text-muted mb-2"><?= portal_e(mb_strimwidth((string) $c['summary'], 0, 130, '…')); ?></div>
            <div class="small mb-2"><?= $c['duration_value'] ? (int) $c['duration_value'] . ' ' . portal_e($c['duration_unit']) . ' · ' : ''; ?><?= portal_e(str_replace(',', ', ', (string) $c['modes'])); ?><?= $c['fees_public'] && (float) $c['course_fee'] > 0 ? ' · ' . lc_money($c['course_fee']) : ''; ?></div>
            <div class="mt-auto d-flex gap-2"><a class="btn btn-sm btn-light border" target="_blank" rel="noopener" href="<?= portal_e(lc_course_url($conn, $c, 'portal')); ?>">Details</a><?php if ($ok) { ?><a class="btn btn-sm btn-primary" href="learning_apply.php?course=<?= portal_e(strtolower($c['code'])); ?>">Apply</a><?php } ?></div>
        </div></div></div><?php } ?>
        <?php if (!$courses) { ?><div class="col-12 text-muted small">No course is published yet.</div><?php } ?></div>
<?php } elseif ($tab === 'learning') {
    $enr = mysqli_fetch_all(mysqli_query($conn, "SELECT e.*, c.title, c.code, i.name intake_name, i.start_date, i.end_date FROM lc_enrollments e JOIN lc_courses c ON c.id = e.course_id LEFT JOIN lc_intakes i ON i.id = e.intake_id WHERE e.customer_id = $cid ORDER BY e.id DESC"), MYSQLI_ASSOC);
    ?>
    <?php if (!$isStudent) { ?><div class="card"><div class="card-body small text-muted">You are not registered on a course yet. When you are admitted and registered, your courses appear here. Your Student ID will be your Customer ID <strong><?= portal_e($sid); ?></strong>.</div></div>
    <?php } else { ?>
    <div class="card mb-3"><div class="card-body d-flex align-items-center gap-3"><i class="bi bi-person-badge fs-2 text-primary"></i><div><div class="small text-muted">Student ID</div><div class="h5 fw-bold mb-0 font-monospace"><?= portal_e($sid); ?></div><div class="small text-muted">Same number as your RISE MOTIVE Customer ID.</div></div></div></div>
    <div class="card"><div class="card-body p-0"><table class="table table-sm align-middle mb-0 small"><thead><tr><th class="ps-3">Course</th><th>Intake</th><th>Branch</th><th>Cohort</th><th>Dates</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($enr as $e2) { ?><tr><td class="ps-3"><strong><?= portal_e($e2['title']); ?></strong><div class="text-muted"><?= portal_e($e2['code']); ?></div></td><td><?= portal_e($e2['intake_name'] ?? '—'); ?></td><td><?= portal_e(lc_branch_name($conn, $e2['branch_id'] ? (int) $e2['branch_id'] : null)); ?></td>
            <td><?= portal_e($e2['cohort'] ?? '—'); ?></td><td><?= $e2['start_date'] ? date('d M Y', strtotime($e2['start_date'])) . ($e2['end_date'] ? ' – ' . date('d M Y', strtotime($e2['end_date'])) : '') : '—'; ?></td><td><span class="badge bg-success"><?= portal_e($e2['status']); ?></span></td></tr><?php } ?>
    </tbody></table></div></div>
    <div class="small text-muted mt-2">Timetable, attendance, results and certificates will appear here.</div>
    <?php } ?>
<?php } else {
    $apps = mysqli_fetch_all(mysqli_query($conn, "SELECT a.ref, a.status, a.offer_status, a.registered_at, a.submitted_at, c.title, i.name intake_name FROM lc_applications a JOIN lc_courses c ON c.id = a.course_id LEFT JOIN lc_intakes i ON i.id = a.intake_id WHERE a.customer_id = $cid ORDER BY a.id DESC"), MYSQLI_ASSOC);
    ?>
    <div class="d-flex justify-content-end mb-2"><a href="learning.php?tab=explore" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New application</a></div>
    <div class="card mb-3"><div class="card-body p-0"><table class="table table-sm align-middle mb-0 small"><thead><tr><th class="ps-3">Reference</th><th>Course</th><th>Intake</th><th>Submitted</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($apps as $r) { ?><tr><td class="ps-3 font-monospace"><?= portal_e($r['ref']); ?></td><td><?= portal_e($r['title']); ?></td><td><?= portal_e($r['intake_name'] ?? '—'); ?></td><td><?= $r['submitted_at'] ? date('d M Y', strtotime($r['submitted_at'])) : '—'; ?></td>
            <td><span class="badge bg-<?= $r['registered_at'] ? 'success' : 'primary'; ?>"><?= portal_e($r['registered_at'] ? 'Registered' : $r['status']); ?></span><?= $r['status'] === 'Accepted' && ($r['offer_status'] ?: 'Pending') === 'Pending' ? ' <span class="badge bg-warning text-dark">Answer the offer</span>' : ''; ?></td>
            <td class="text-end pe-3"><a class="btn btn-sm btn-light border" href="learning.php?app=<?= rawurlencode($r['ref']); ?>">Open</a></td></tr><?php } ?>
        <?php if (!$apps) { ?><tr><td colspan="6" class="text-muted ps-3 py-3">No application yet.</td></tr><?php } ?>
    </tbody></table></div></div>
    <div class="card"><div class="card-body"><div class="fw-bold small mb-1">Applied on the website without signing in?</div><div class="small text-muted mb-2">Add it here with its reference. It must have been made with your telephone number <?= portal_e($me['phone']); ?>.</div>
        <form method="POST" class="d-flex gap-2" style="max-width:420px;"><?= portal_csrf_field(); ?><input type="hidden" name="do" value="claim"><input name="app" class="form-control form-control-sm font-monospace text-uppercase" placeholder="RMLC<?= date('Y'); ?>-0001" required maxlength="30"><button class="btn btn-sm btn-outline-primary text-nowrap">Add</button></form></div></div>
<?php }
portal_footer();