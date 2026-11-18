<?php
/**
 * NEW FILE: learn/track.php — SECURE APPLICATION TRACKING (no account).
 * The applicant gives the reference AND the telephone (or email) used in the application — never the reference alone.
 * Optional one-time SMS code (Learning Centre → Settings). Then: status and its meaning, public history, requested
 * information/documents (upload), accept or decline the admission offer, withdraw. Internal staff notes are never shown.
 */
require __DIR__ . '/lc_public.php';
require_once __DIR__ . '/../includes/evidence_store.php';
require_once __DIR__ . '/../includes/lc_finance.php'; // NEW (stage 3): admission letter and fees
lc_fin_ensure($conn);
lcp_track_source();
$msg = ''; $err = ''; $otpFor = null;
$ttl = 7200; // access stays open 2 hours on this device

$access = function (string $ref) use ($ttl): bool {
    foreach (['lcp_access', 'lcp_tracked'] as $k) { if (!empty($_SESSION[$k][$ref]) && time() - (int) $_SESSION[$k][$ref] < $ttl) { return true; } }
    return false;
};
$grant = function (string $ref) { $_SESSION['lcp_access'][$ref] = time(); session_regenerate_id(true); };

$ref = strtoupper(trim((string) ($_POST['ref'] ?? $_GET['ref'] ?? '')));
$do = (string) ($_POST['do'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!lcp_csrf_ok()) { throw new RuntimeException('Your session expired. Please try again.'); }
        if ($do === 'find') {
            if (!lc_rate_ok($conn, 'track', 10, 15)) { throw new RuntimeException('Too many attempts. Please wait 15 minutes and try again.'); }
            lc_rate_hit($conn, 'track');
            $app = lc_track_find($conn, $ref, (string) ($_POST['contact'] ?? ''));
            if (!$app) { throw new RuntimeException('We could not find an application with this reference and telephone number / email. Please check both.'); }
            if (lc_setting($conn, 'tracking_otp') === '1') {
                $code = (string) random_int(100000, 999999);
                $h = password_hash($code, PASSWORD_DEFAULT); $exp = date('Y-m-d H:i:s', time() + 600); $aid = (int) $app['id'];
                mysqli_query($conn, "UPDATE lc_otps SET used_at = NOW() WHERE application_id = $aid AND used_at IS NULL");
                $s = mysqli_prepare($conn, 'INSERT INTO lc_otps (application_id, code_hash, expires_at) VALUES (?, ?, ?)');
                mysqli_stmt_bind_param($s, 'iss', $aid, $h, $exp);
                mysqli_stmt_execute($s);
                $sent = false;
                try { require_once __DIR__ . '/../includes/sms_center.php'; $sent = sms_notify($conn, 'LC_TRACK_OTP', $app['phone'], ['code' => $code], ['to' => 'applicant', 'ref_type' => 'lc_application', 'ref_id' => $aid]); } catch (Throwable $e) { error_log('lc otp: ' . $e->getMessage()); } // CHANGED (Task 10): security notification in SMS Management
                if (!$sent && !empty($app['email']) && is_file(__DIR__ . '/../includes/mailer.php')) {
                    try { require_once __DIR__ . '/../includes/mailer.php'; send_email($app['email'], $app['full_name'], 'Your RM Learning Centre code', '<p>Your code is <strong>' . $code . '</strong> (valid 10 minutes).</p>'); $sent = true; } catch (Throwable $e) { }
                }
                $_SESSION['lcp_otp'] = ['ref' => $app['ref'], 'id' => $aid];
                $otpFor = $app['ref'];
                $msg = 'We sent a 6-digit code to the telephone number ending in ' . substr($app['phone_key'], -3) . '. Enter it below.';
            } else {
                $grant($app['ref']); lc_audit($conn, 'track_open', 'application', (int) $app['id'], null, $app['customer_id'] ? (int) $app['customer_id'] : null);
                header('Location: track.php?ref=' . rawurlencode($app['ref'])); exit;
            }
        } elseif ($do === 'otp') {
            $o = $_SESSION['lcp_otp'] ?? null; $otpFor = $o['ref'] ?? null;
            if (!$o) { throw new RuntimeException('Please start again.'); }
            $aid = (int) $o['id'];
            $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lc_otps WHERE application_id = $aid AND used_at IS NULL ORDER BY id DESC LIMIT 1"));
            if (!$row || $row['expires_at'] < date('Y-m-d H:i:s') || (int) $row['attempts'] >= 5) { unset($_SESSION['lcp_otp']); $otpFor = null; throw new RuntimeException('The code expired. Please start again.'); }
            mysqli_query($conn, 'UPDATE lc_otps SET attempts = attempts + 1 WHERE id = ' . (int) $row['id']);
            if (!password_verify(preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')), $row['code_hash'])) { throw new RuntimeException('Wrong code. Please check the SMS and try again.'); }
            mysqli_query($conn, "UPDATE lc_otps SET used_at = NOW() WHERE id = " . (int) $row['id']);
            unset($_SESSION['lcp_otp']); $grant($o['ref']);
            header('Location: track.php?ref=' . rawurlencode($o['ref'])); exit;
        } elseif ($do === 'signout') {
            unset($_SESSION['lcp_access'][$ref], $_SESSION['lcp_tracked'][$ref]);
            header('Location: track.php'); exit;
        } elseif (in_array($do, ['reply', 'accept', 'decline', 'withdraw'], true)) {
            if (!$access($ref)) { throw new RuntimeException('Please open your application again.'); }
            $s = mysqli_prepare($conn, 'SELECT id FROM lc_applications WHERE ref = ?'); mysqli_stmt_bind_param($s, 's', $ref); mysqli_stmt_execute($s);
            $aid = (int) (mysqli_fetch_row(mysqli_stmt_get_result($s))[0] ?? 0);
            $a = $aid ? lc_application($conn, $aid) : null;
            if (!$a) { throw new RuntimeException('Application not found.'); }
            $cust = $a['customer_id'] ? (int) $a['customer_id'] : null;
            if ($do === 'reply') {
                if (!in_array($a['status'], LC_APP_OPEN, true) || $a['registered_at']) { throw new RuntimeException('This application can no longer be changed here. Please contact us.'); }
                $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 2000);
                $files = $_FILES['files'] ?? null; $n = 0;
                if ($files && is_array($files['name'])) {
                    foreach (array_keys($files['name']) as $i) {
                        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
                        if ($n >= 5) { break; }
                        $f = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                        $fref = evidence_save($conn, $f, null);
                        if ($fref) { $label = mb_substr(trim((string) ($_POST['label'] ?? '')) ?: 'Additional document', 0, 150); $on = mb_substr((string) $f['name'], 0, 255);
                            $d = mysqli_prepare($conn, 'INSERT INTO lc_application_docs (application_id, label, file_ref, original_name) VALUES (?, ?, ?, ?)');
                            mysqli_stmt_bind_param($d, 'isss', $aid, $label, $fref, $on); mysqli_stmt_execute($d); $n++; }
                    }
                }
                if ($note === '' && !$n) { throw new RuntimeException('Please write a message or attach a document.'); }
                lc_log($conn, $aid, 'reply', null, null, 'Applicant replied' . ($n ? ' and sent ' . $n . ' document(s)' : '') . ($note !== '' ? ': ' . $note : '.'), true);
                lc_audit($conn, 'applicant_reply', 'application', $aid, ['files' => $n], $cust);
                lc_notify_staff($conn, 'Applicant replied: ' . $a['ref'], $a['full_name'] . ' answered on the tracking page' . ($n ? ' (' . $n . ' document(s))' : '') . '.');
                $msg = 'Thank you, your answer was sent to the admissions team.';
            } elseif ($do === 'accept' || $do === 'decline') {
                if ($a['status'] !== 'Accepted' || ($a['offer_status'] ?: 'Pending') !== 'Pending') { throw new RuntimeException('There is no admission offer waiting for your answer.'); }
                $resp = $do === 'accept' ? 'Accepted' : 'Declined';
                mysqli_query($conn, "UPDATE lc_applications SET offer_status = '$resp', updated_at = NOW() WHERE id = $aid");
                lc_log($conn, $aid, 'offer', null, 'Offer ' . strtolower($resp), 'The applicant ' . strtolower($resp) . ' the admission offer online.', true);
                lc_audit($conn, 'offer_' . strtolower($resp), 'application', $aid, ['online' => true], $cust);
                lc_notify_staff($conn, 'Offer ' . strtolower($resp) . ': ' . $a['ref'], $a['full_name'] . ' ' . strtolower($resp) . ' the offer for ' . $a['course_title'] . '.');
                $msg = $resp === 'Accepted' ? 'Thank you! You accepted your offer. We will contact you to complete your registration.' : 'You declined the offer. Thank you for letting us know.';
            } else {
                if (!in_array($a['status'], LC_APP_OPEN, true) || $a['registered_at'] || empty($_POST['confirm'])) { throw new RuntimeException('Please tick the box to confirm the withdrawal.'); }
                mysqli_query($conn, "UPDATE lc_applications SET status = 'Withdrawn', updated_at = NOW() WHERE id = $aid");
                lc_log($conn, $aid, 'status', $a['status'], 'Withdrawn', 'Withdrawn by the applicant online.', true);
                lc_audit($conn, 'application_withdraw', 'application', $aid, ['online' => true], $cust);
                lc_notify_staff($conn, 'Application withdrawn: ' . $a['ref'], $a['full_name'] . ' withdrew the application for ' . $a['course_title'] . '.');
                $msg = 'Your application was withdrawn.';
            }
        }
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
      catch (Throwable $e) { error_log('lc track: ' . $e->getMessage()); $err = 'Something went wrong. Please try again.'; }
}

$app = null;
// NEW (stage 3): admission letter PDF for an admitted applicant with access on this device
if ($ref !== '' && !empty($_GET['letter']) && $access($ref)) {
    $s = mysqli_prepare($conn, 'SELECT id FROM lc_applications WHERE ref = ?'); mysqli_stmt_bind_param($s, 's', $ref); mysqli_stmt_execute($s);
    $la = lc_application($conn, (int) (mysqli_fetch_row(mysqli_stmt_get_result($s))[0] ?? 0));
    if ($la && $la['status'] === 'Accepted') { lc_audit($conn, 'admission_letter_download', 'application', (int) $la['id'], ['tracking' => true], $la['customer_id'] ? (int) $la['customer_id'] : null); lc_pdf_output(lc_admission_letter_html($conn, $la), 'admission-' . $la['ref'] . '.pdf'); exit; }
}
if ($ref !== '' && $access($ref)) {
    $s = mysqli_prepare($conn, 'SELECT id FROM lc_applications WHERE ref = ?'); mysqli_stmt_bind_param($s, 's', $ref); mysqli_stmt_execute($s);
    $id = (int) (mysqli_fetch_row(mysqli_stmt_get_result($s))[0] ?? 0);
    $app = $id ? lc_application($conn, $id) : null;
}
lcp_header($conn, ['title' => 'Track my application', 'url' => lc_public_base($conn) . '/track.php']);
$csrf = '<input type="hidden" name="csrf" value="' . lc_e(lcp_csrf()) . '"><input type="hidden" name="ref" value="' . lc_e($app['ref'] ?? $ref) . '">';
?>
<div class="container py-4" style="max-width:820px;">
<?php if ($msg) { ?><div class="alert alert-success"><?= lc_e($msg); ?></div><?php } ?>
<?php if ($err) { ?><div class="alert alert-danger"><?= lc_e($err); ?></div><?php } ?>
<?php if (!$app) { ?>
    <div class="card"><div class="card-body p-4">
        <h1 class="h4 fw-bold">Track my application</h1>
        <?php if ($otpFor) { ?>
        <form method="POST" class="row g-2" autocomplete="off"><?= $csrf; ?><input type="hidden" name="do" value="otp">
            <div class="col-md-5"><label class="form-label small fw-semibold">6-digit code</label><input name="code" class="form-control form-control-lg font-monospace" inputmode="numeric" maxlength="6" required autofocus></div>
            <div class="col-12"><button class="btn btn-primary">Open my application</button> <a href="track.php" class="btn btn-link">Start again</a></div></form>
        <?php } else { ?>
        <p class="small text-muted">Enter your application reference and the telephone number (or email) you used when applying.</p>
        <form method="POST" class="row g-2"><input type="hidden" name="csrf" value="<?= lc_e(lcp_csrf()); ?>"><input type="hidden" name="do" value="find">
            <div class="col-md-5"><label class="form-label small fw-semibold">Application reference</label><input name="ref" class="form-control font-monospace text-uppercase" required maxlength="30" value="<?= lc_e($ref); ?>" placeholder="RMLC<?= date('Y'); ?>-0001"></div>
            <div class="col-md-5"><label class="form-label small fw-semibold">Telephone or email</label><input name="contact" class="form-control" required maxlength="150" autocomplete="tel"></div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Open</button></div></form>
        <div class="small text-muted mt-3">Lost your reference? Call <?= lc_e(lc_setting($conn, 'contact_phone')); ?>. Customers can also see their applications in the <a href="<?= lc_e(lcp_portal_url($conn)); ?>">Customer Portal</a>.</div>
        <?php } ?>
    </div></div>
<?php } else {
    $hist = lc_public_history($conn, (int) $app['id']);
    $req = null; foreach ($hist as $h) { if ($h['kind'] === 'request') { $req = $h; } }
    $docs = mysqli_fetch_all(mysqli_query($conn, 'SELECT label, original_name, created_at FROM lc_application_docs WHERE application_id = ' . (int) $app['id'] . ' ORDER BY id'), MYSQLI_ASSOC);
    $open = in_array($app['status'], LC_APP_OPEN, true) && !$app['registered_at'];
    $color = ['Accepted' => 'success', 'Rejected' => 'danger', 'Withdrawn' => 'secondary', 'Additional Information Required' => 'warning', 'Waitlisted' => 'info'][$app['status']] ?? 'primary';
    ?>
    <div class="card mb-3"><div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between gap-2"><div><div class="small text-muted">Application</div><div class="fs-4 fw-bold font-monospace" style="color:var(--d)"><?= lc_e($app['ref']); ?></div></div>
            <form method="POST"><?= $csrf; ?><input type="hidden" name="do" value="signout"><button class="btn btn-sm btn-light border"><?= lcp_i('logout'); ?> Close</button></form></div>
        <div class="mt-2"><span class="badge bg-<?= $app['registered_at'] ? 'success' : $color; ?> fs-6"><?= lc_e($app['registered_at'] ? 'Registered' : $app['status']); ?></span>
            <?php if ($app['status'] === 'Accepted') { ?><span class="badge bg-light text-dark border">Offer: <?= lc_e($app['offer_status'] ?: 'Pending'); ?></span><?php } ?></div>
        <p class="mt-2 mb-2"><?= $app['registered_at'] ? 'You are registered. Your Student ID is <strong>' . lc_e(lc_customer_id_label($conn, (int) $app['customer_id'])) . '</strong> (the same as your RISE MOTIVE Customer ID).' : lc_e(lc_status_help($app['status'], $app['offer_status'])); ?></p>
        <div class="row small g-2">
            <div class="col-md-6"><span class="text-muted">Course:</span> <strong><?= lc_e($app['course_title']); ?></strong> (<?= lc_e($app['course_code']); ?>)</div>
            <div class="col-md-6"><span class="text-muted">Intake:</span> <?= lc_e($app['intake_name'] ?? '—'); ?> · <?= lc_e(lc_branch_name($conn, $app['branch_id'] ? (int) $app['branch_id'] : null)); ?></div>
            <div class="col-md-6"><span class="text-muted">Applicant:</span> <?= lc_e($app['full_name']); ?></div>
            <div class="col-md-6"><span class="text-muted">Submitted:</span> <?= $app['submitted_at'] ? date('d M Y H:i', strtotime($app['submitted_at'])) : '—'; ?></div>
        </div>
        <?php if ($app['status'] === 'Accepted') { ?><div class="mt-2"><a class="btn btn-sm btn-outline-primary" href="track.php?ref=<?= rawurlencode($app['ref']); ?>&letter=1" target="_blank"><?= lcp_i('file'); ?> Admission letter</a></div><?php } ?>
        <?php $tInv = array_filter(lc_invoices_for($conn, (int) $app['id']), function ($x) { return !$x['pending'] && !$x['cancelled']; }); if ($tInv) { ?><div class="small mt-2"><strong>Fees:</strong> <?php foreach ($tInv as $iv) { echo lc_e($iv['number']) . ' — ' . ($iv['balance'] <= 0.009 ? 'paid' : 'balance ' . lc_money($iv['balance']) . ($iv['next'] ? ', next ' . lc_money($iv['next']['due']) . ' by ' . date('d M Y', strtotime($iv['next']['due_date'])) : '')) . '. '; } ?></div><?php } ?>
        <?php if ($app['status'] === 'Accepted' && ($app['offer_status'] ?: 'Pending') === 'Pending') { ?>
        <div class="border rounded p-3 mt-3 bg-light"><div class="fw-bold mb-1"><span class="text-success"><?= lcp_i('award'); ?></span> Admission offer</div>
            <?php if ($app['offer_note']) { ?><div class="small mb-2" style="white-space:pre-line;"><?= lc_e($app['offer_note']); ?></div><?php } ?>
            <form method="POST" class="d-inline"><?= $csrf; ?><input type="hidden" name="do" value="accept"><button class="btn btn-success">Accept the offer</button></form>
            <form method="POST" class="d-inline" onsubmit="return confirm('Decline the admission offer?');"><?= $csrf; ?><input type="hidden" name="do" value="decline"><button class="btn btn-outline-danger">Decline</button></form></div>
        <?php } ?>
    </div></div>
    <?php if ($open) { ?>
    <div class="card mb-3 <?= $app['status'] === 'Additional Information Required' ? 'border border-warning' : ''; ?>"><div class="card-body">
        <div class="fw-bold mb-1"><?= $app['status'] === 'Additional Information Required' ? '<span class="text-warning">' . lcp_i('alert') . '</span> Information requested' : 'Send a message or a document'; ?></div>
        <?php if ($req && $app['status'] === 'Additional Information Required') { ?><div class="alert alert-warning small py-2"><?= lc_e($req['note']); ?> <span class="text-muted">(<?= date('d M Y', strtotime($req['created_at'])); ?>)</span></div><?php } ?>
        <form method="POST" enctype="multipart/form-data"><?= $csrf; ?><input type="hidden" name="do" value="reply">
            <textarea name="note" class="form-control mb-2" rows="2" maxlength="2000" placeholder="Your message"></textarea>
            <div class="row g-2"><div class="col-md-5"><input name="label" class="form-control form-control-sm" maxlength="150" placeholder="What is the document? (e.g. ID copy)"></div>
                <div class="col-md-5"><input type="file" name="files[]" multiple class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/*"></div>
                <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Send</button></div></div>
            <div class="form-text">PDF or photo, max 5 MB each, up to 5 files.</div></form>
    </div></div>
    <?php } ?>
    <div class="row g-3">
        <div class="col-md-7"><div class="card h-100"><div class="card-body"><div class="fw-bold mb-2">History</div>
            <?php foreach (array_reverse($hist) as $h) { ?><div class="border-start border-3 ps-2 mb-2 small"><div class="text-muted"><?= date('d M Y H:i', strtotime($h['created_at'])); ?></div>
                <?= $h['to_status'] ? '<strong>' . lc_e($h['to_status']) . '</strong>' : ''; ?><?php if ($h['note']) { ?><div><?= lc_e($h['note']); ?></div><?php } ?></div><?php } ?></div></div></div>
        <div class="col-md-5"><div class="card h-100"><div class="card-body"><div class="fw-bold mb-2">Documents received</div>
            <?php foreach ($docs as $d) { ?><div class="small mb-1"><?= lcp_i('clip'); ?> <?= lc_e($d['label']); ?> <span class="text-muted">(<?= lc_e($d['original_name']); ?>)</span></div><?php } ?>
            <?php if (!$docs) { ?><div class="small text-muted">None.</div><?php } ?></div></div></div>
    </div>
    <?php if ($open) { ?>
    <details class="mt-3"><summary class="small text-muted">Withdraw my application</summary>
        <form method="POST" class="mt-2"><?= $csrf; ?><input type="hidden" name="do" value="withdraw"><div class="form-check"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="wd"><label for="wd" class="form-check-label small">I want to withdraw this application. This cannot be undone online.</label></div>
            <button class="btn btn-sm btn-outline-danger mt-1">Withdraw</button></form></details>
    <?php } ?>
<?php } ?>
</div>
<?php lcp_footer($conn); ?>