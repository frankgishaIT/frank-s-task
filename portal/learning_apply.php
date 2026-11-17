<?php
/**
 * NEW FILE: portal/learning_apply.php — apply for a Learning Centre course from the Customer Portal.
 * Name and telephone come from the customer record (prefilled, not editable); the application is linked to the
 * customer immediately. Same application system, rules and duplicate check as the public website.
 */
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/lc_form.php';
lc_ensure($conn);
$c = lc_course_by_code($conn, (string) ($_GET['course'] ?? ''));
if (!$c || !in_array($c['status'], LC_PUBLIC_VISIBLE, true)) { header('Location: learning.php?tab=explore'); exit; }
[$canApply, $why] = lc_course_accepting($conn, $c);
$error = ''; $old = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = lc_form_old($_POST);
    try {
        if (!portal_csrf_ok()) { throw new RuntimeException('Your session expired. Please try again.'); }
        if (!$canApply) { throw new RuntimeException($why); }
        if (empty($_POST['consent'])) { throw new RuntimeException('Please confirm your information and agree to its use for your application.'); }
        if (!lc_rate_ok($conn, 'apply', 10, 60)) { throw new RuntimeException('Too many applications in a short time. Please try again later.'); }
        lc_rate_hit($conn, 'apply');
        $res = lc_submit_application($conn, $c, $_POST, $_FILES['docs'] ?? null, $me, 'portal', 'portal', null, null);
        $a = lc_application($conn, $res['id']);
        if ($res['duplicate'] && $a && !$a['customer_id']) { lc_link_customer($conn, (int) $a['id'], $cid, 'same telephone, applied again in the portal'); }
        header('Location: learning.php?app=' . rawurlencode($res['ref']) . '&success=' . urlencode($res['duplicate'] ? 'You already applied for this intake: here is your application ' . $res['ref'] . '.' : 'Application ' . $res['ref'] . ' was sent. We will keep you informed here and by SMS.'));
        exit;
    } catch (RuntimeException $e) { $error = $e->getMessage(); }
      catch (Throwable $e) { error_log('lc portal apply: ' . $e->getMessage()); $error = 'Something went wrong. Please try again.'; }
}
portal_header('Apply — ' . $c['title'], $me);
?>
<div class="small mb-2"><a href="learning.php?tab=explore"><i class="bi bi-arrow-left"></i> RM Learning Centre</a></div>
<div class="card"><div class="card-body">
    <h1 class="h5 fw-bold mb-0">Apply: <?= portal_e($c['title']); ?></h1><div class="small text-muted mb-3"><?= portal_e($c['code']); ?> · <a href="<?= portal_e(lc_course_url($conn, $c, 'portal')); ?>" target="_blank" rel="noopener">Course details</a></div>
    <?php if ($error) { ?><div class="alert alert-danger"><?= portal_e($error); ?></div><?php } ?>
    <?php if (!$canApply) { ?><div class="alert alert-secondary"><?= portal_e($why); ?></div>
    <?php } else { lc_form_render($conn, $c, $old, $me, portal_csrf_field(), 'learning_apply.php?course=' . rawurlencode(strtolower($c['code']))); } ?>
</div></div>
<?php portal_footer();