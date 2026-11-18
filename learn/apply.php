<?php
/**
 * NEW FILE: learn/apply.php — GUEST APPLICATION (no account). One-time form token (no double submission),
 * hidden trap field and limits per connection against spam, the visitor's source (WhatsApp, Facebook, QR…) is saved.
 * The same person cannot apply twice for the same course and intake: the existing reference is shown instead.
 */
require __DIR__ . '/lc_public.php';
require_once __DIR__ . '/../includes/lc_form.php';
lcp_track_source();
$c = lc_course_by_code($conn, (string) ($_GET['course'] ?? $_POST['course'] ?? ''));
if (!$c || !in_array($c['status'], LC_PUBLIC_VISIBLE, true)) { header('Location: courses.php'); exit; }
[$canApply, $why] = lc_course_accepting($conn, $c);
$error = ''; $old = [];
if (!empty($_GET['intake'])) { $old['intake_id'] = (int) $_GET['intake']; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = lc_form_old($_POST);
    $tok = (string) ($_POST['token'] ?? '');
    try {
        if (!lcp_csrf_ok()) { throw new RuntimeException('Your session expired. Please submit the form again.'); }
        if (!empty($_POST['website'])) { throw new RuntimeException('Your application could not be sent.'); }
        if (!$canApply) { throw new RuntimeException($why); }
        if (empty($_POST['consent'])) { throw new RuntimeException('Please confirm your information and agree to its use for your application.'); }
        // Same form sent twice (double click, refresh): show the first result.
        if ($tok !== '' && isset($_SESSION['lcp_done'][$tok])) { header('Location: apply.php?course=' . rawurlencode(strtolower($c['code'])) . '&done=' . rawurlencode($_SESSION['lcp_done'][$tok][0]) . ($_SESSION['lcp_done'][$tok][1] ? '&dup=1' : '')); exit; }
        if (!lc_rate_ok($conn, 'apply', 5, 60)) { throw new RuntimeException('Too many applications were sent from this connection. Please try again in an hour or call us.'); }
        lc_rate_hit($conn, 'apply');
        $res = lc_submit_application($conn, $c, $_POST, $_FILES['docs'] ?? null, null, 'public', $_SESSION['lcp_source'] ?? 'website', $_SESSION['lcp_campaign'] ?? null, $_SESSION['lcp_referrer'] ?? null);
        $_SESSION['lcp_done'][$tok] = [$res['ref'], $res['duplicate']];
        $_SESSION['lcp_tracked'][$res['ref']] = time(); // may open the tracking page directly on this device for 2 hours
        header('Location: apply.php?course=' . rawurlencode(strtolower($c['code'])) . '&done=' . rawurlencode($res['ref']) . ($res['duplicate'] ? '&dup=1' : ''));
        exit;
    } catch (RuntimeException $e) { $error = $e->getMessage(); }
      catch (Throwable $e) { error_log('lc apply: ' . $e->getMessage()); $error = 'Something went wrong. Please try again, or contact us.'; }
}
$done = (string) ($_GET['done'] ?? '');
if ($done !== '' && empty($_SESSION['lcp_tracked'][$done])) { $done = ''; } // only on the device that applied
lcp_header($conn, ['title' => 'Apply — ' . $c['title'], 'description' => 'Apply online for ' . $c['title'] . ' at ' . lc_setting($conn, 'title') . '.', 'url' => lc_course_url($conn, $c)]);
?>
<div class="container py-4" style="max-width:900px;">
    <div class="small mb-1"><a href="<?= lc_e(lcp_course_href($conn, $c['code'])); ?>"><?= lcp_i('arrow-left'); ?> <?= lc_e($c['title']); ?></a></div>
<?php if ($done !== '') { ?>
    <div class="card"><div class="card-body p-4 text-center">
        <div class="text-success mb-2"><?= lcp_i('check-circle', 'lci-lg'); ?></div>
        <?php if (!empty($_GET['dup'])) { ?><h1 class="h4 fw-bold">You already applied for this intake</h1><p>We did not create a second application. Your reference is:</p>
        <?php } else { ?><h1 class="h4 fw-bold">Application received</h1><p>Thank you. Your application for <strong><?= lc_e($c['title']); ?></strong> was sent. Your reference is:</p><?php } ?>
        <div class="fs-2 fw-bold font-monospace my-2" style="color:var(--d)"><?= lc_e($done); ?></div>
        <p class="small text-muted mb-3">Keep this reference. To track your application you will need it together with the telephone number (or email) you used.<br>We also sent it by SMS<?= lc_setting($conn, 'contact_email') !== '' ? ' and email' : ''; ?> when available.</p>
        <a href="track.php?ref=<?= rawurlencode($done); ?>" class="btn btn-primary">Track my application</a> <a href="courses.php" class="btn btn-light border">More courses</a>
        <div class="small text-muted mt-3">Already a RISE MOTIVE customer? Sign in to the Customer Portal and add this reference under <em>RM Learning Centre → My applications</em>.</div>
    </div></div>
    <script>try { localStorage.removeItem('lcdraft-<?= lc_e($c['code']); ?>'); } catch (e) {}</script>
<?php } elseif (!$canApply) { ?>
    <div class="card"><div class="card-body"><h1 class="h4"><?= lc_e($c['title']); ?></h1><div class="alert alert-secondary mb-0"><?= lc_e($why); ?></div></div></div>
<?php } else { ?>
    <div class="card"><div class="card-body p-4">
        <h1 class="h4 fw-bold mb-0">Apply: <?= lc_e($c['title']); ?></h1><div class="small text-muted mb-3"><?= lc_e($c['code']); ?> · No account needed · About 5 minutes</div>
        <?php if ($error) { ?><div class="alert alert-danger"><?= lc_e($error); ?></div><?php } ?>
        <?php lc_form_render($conn, $c, $old, null, '<input type="hidden" name="csrf" value="' . lc_e(lcp_csrf()) . '"><input type="hidden" name="token" value="' . bin2hex(random_bytes(12)) . '"><input type="hidden" name="course" value="' . lc_e(strtolower($c['code'])) . '">', 'apply.php?course=' . rawurlencode(strtolower($c['code']))); ?>
    </div></div>
<?php } ?>
</div>
<?php lcp_footer($conn); ?>