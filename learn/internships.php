<?php
/**
 * NEW FILE: learn/internships.php — PUBLIC INTERNSHIPS page: open opportunities, apply (no account), check an
 * application with its reference + telephone. RM Learning Centre students are recognised by their telephone.
 */
require __DIR__ . '/lc_public.php';
require_once __DIR__ . '/../includes/lc_internship.php';
lc_int_ensure($conn);
lcp_track_source();
$post = !empty($_GET['post']) ? lc_int_post($conn, (int) $_GET['post']) : null;
if ($post && $post['status'] !== 'Open') { $post = null; }
$msg = ''; $err = ''; $found = null; $old = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    try {
        if (!lcp_csrf_ok()) { throw new RuntimeException('Your session expired. Please try again.'); }
        if ($do === 'apply') {
            $old = $_POST;
            $post = lc_int_post($conn, (int) ($_POST['post'] ?? 0));
            if (!$post || $post['status'] !== 'Open') { throw new RuntimeException('This opportunity is closed.'); }
            if (!empty($_POST['website'])) { throw new RuntimeException('Your application could not be sent.'); }
            if (empty($_POST['consent'])) { throw new RuntimeException('Please confirm your information.'); }
            if (!lc_rate_ok($conn, 'intapply', 5, 60)) { throw new RuntimeException('Too many applications from this connection. Please try again later.'); }
            lc_rate_hit($conn, 'intapply');
            $r = lc_int_submit($conn, $post, $_POST, $_FILES['cv'] ?? null, null, 'public');
            $msg = ($r['duplicate'] ? 'You already applied for this internship. ' : 'Application received. ') . 'Your reference is ' . $r['ref'] . '. Keep it with your telephone number to check your application here.';
            $post = null; $old = [];
        } elseif ($do === 'check') {
            if (!lc_rate_ok($conn, 'intcheck', 10, 15)) { throw new RuntimeException('Too many attempts. Please wait 15 minutes.'); }
            lc_rate_hit($conn, 'intcheck');
            $ref = strtoupper(trim((string) ($_POST['ref'] ?? ''))); $key = lc_phone_key((string) ($_POST['phone'] ?? ''));
            $s = mysqli_prepare($conn, 'SELECT id FROM lc_int_apps WHERE ref = ? AND phone_key = ?'); mysqli_stmt_bind_param($s, 'ss', $ref, $key); mysqli_stmt_execute($s);
            $id = (int) (mysqli_fetch_row(mysqli_stmt_get_result($s))[0] ?? 0);
            if (!$id || strlen($key) !== 9) { throw new RuntimeException('No application matches this reference and telephone number.'); }
            $found = lc_int_app($conn, $id);
        }
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
}
$posts = mysqli_fetch_all(mysqli_query($conn, "SELECT p.*, d.name department FROM lc_int_posts p LEFT JOIN departments d ON d.id = p.department_id WHERE p.status = 'Open' AND (p.apply_close IS NULL OR p.apply_close >= '" . date('Y-m-d') . "') ORDER BY p.apply_close IS NULL, p.apply_close, p.id DESC"), MYSQLI_ASSOC);
lcp_header($conn, ['title' => 'Internships', 'description' => lc_setting($conn, 'internship_intro'), 'url' => lc_public_base($conn) . '/internships.php']);
$v = function ($k) use ($old) { return lc_e($old[$k] ?? ''); };
?>
<div class="container py-4" style="max-width:960px;">
    <h1 class="h3 fw-bold" style="color:var(--d)">Internships</h1><p class="text-muted"><?= lc_e(lc_setting($conn, 'internship_intro')); ?></p>
    <?php if ($msg) { ?><div class="alert alert-success"><?= lc_e($msg); ?></div><?php } ?>
    <?php if ($err) { ?><div class="alert alert-danger"><?= lc_e($err); ?></div><?php } ?>
    <?php if ($found) { $log = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM lc_int_log WHERE app_id = ' . (int) $found['id'] . ' AND public = 1 ORDER BY id DESC'), MYSQLI_ASSOC); ?>
    <div class="card mb-3"><div class="card-body"><div class="small text-muted">Application <?= lc_e($found['ref']); ?></div><div class="h5 fw-bold"><?= lc_e($found['post_title']); ?></div>
        <span class="badge bg-primary fs-6"><?= lc_e($found['status']); ?></span>
        <?php if (in_array($found['status'], ['Approved', 'Active'], true) && $found['start_date']) { ?><div class="small mt-2">Period: <?= date('d M Y', strtotime($found['start_date'])); ?> → <?= $found['end_date'] ? date('d M Y', strtotime($found['end_date'])) : ''; ?> · <?= lc_e($found['department'] ?? ''); ?> · <?= lc_e(lc_branch_name($conn, (int) $found['branch_id'] ?: null)); ?></div><?php } ?>
        <?php foreach ($log as $l) { ?><div class="small border-top py-1 mt-1"><span class="text-muted"><?= date('d M Y', strtotime($l['created_at'])); ?></span> <strong><?= lc_e($l['to_status']); ?></strong> <?= lc_e($l['note']); ?></div><?php } ?>
    </div></div>
    <?php } ?>
    <?php if ($post) { [$ok, $why] = lc_int_post_open($conn, $post); ?>
    <div class="card mb-3"><div class="card-body p-4">
        <div class="small"><a href="internships.php"><?= lcp_i('arrow-left'); ?> All internships</a></div>
        <h2 class="h4 fw-bold mt-1"><?= lc_e($post['title']); ?></h2>
        <div class="small text-muted mb-2"><?= lc_e($post['department'] ?? ''); ?> · <?= lc_e(lc_branch_name($conn, (int) $post['branch_id'] ?: null)); ?><?= $post['start_date'] ? ' · ' . date('d M Y', strtotime($post['start_date'])) . ' → ' . ($post['end_date'] ? date('d M Y', strtotime($post['end_date'])) : '') : ''; ?></div>
        <div style="white-space:pre-line;"><?= lc_e($post['description']); ?></div>
        <?php if (lc_lines($post['requirements'])) { ?><div class="fw-semibold mt-2">Requirements</div><ul><?php foreach (lc_lines($post['requirements']) as $r) { ?><li><?= lc_e($r); ?></li><?php } ?></ul><?php } ?>
        <?php if (!$ok) { ?><div class="alert alert-secondary mb-0"><?= lc_e($why); ?></div><?php } else { ?>
        <form method="POST" enctype="multipart/form-data" class="row g-2 mt-2"><input type="hidden" name="csrf" value="<?= lc_e(lcp_csrf()); ?>"><input type="hidden" name="do" value="apply"><input type="hidden" name="post" value="<?= (int) $post['id']; ?>">
            <div style="position:absolute;left:-5000px;" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Full name *</label><input name="full_name" class="form-control" required maxlength="150" value="<?= $v('full_name'); ?>"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Telephone *</label><input name="phone" class="form-control" required maxlength="30" inputmode="tel" value="<?= $v('phone'); ?>"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">Email</label><input type="email" name="email" class="form-control" maxlength="150" value="<?= $v('email'); ?>"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold">CV (PDF or photo, max 5 MB)</label><input type="file" name="cv" class="form-control" accept=".pdf,.jpg,.jpeg,.png"></div>
            <div class="col-12"><label class="form-label small fw-semibold">Education and experience</label><textarea name="education" class="form-control" rows="2" maxlength="2000"><?= $v('education'); ?></textarea></div>
            <div class="col-12"><label class="form-label small fw-semibold">Why this internship?</label><textarea name="motivation" class="form-control" rows="3" maxlength="2000"><?= $v('motivation'); ?></textarea></div>
            <div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" name="consent" value="1" id="ic" required><label class="form-check-label small" for="ic">I confirm the information is correct and agree that RISE MOTIVE Ltd uses it for this application.</label></div>
            <div class="col-12"><button class="btn btn-primary">Send my application</button> <span class="small text-muted">RM Learning Centre students can also apply in the Customer Portal.</span></div></form>
        <?php } ?>
    </div></div>
    <?php } else { ?>
    <div class="row g-3 mb-3"><?php foreach ($posts as $p) { ?>
        <div class="col-md-6"><a href="internships.php?post=<?= (int) $p['id']; ?>" class="text-decoration-none text-reset"><div class="card h-100 lc-course-card"><div class="card-body">
            <div class="small text-muted"><?= lc_e($p['department'] ?? 'RISE MOTIVE'); ?> · <?= lc_e(lc_branch_name($conn, (int) $p['branch_id'] ?: null)); ?></div><div class="fw-bold"><?= lc_e($p['title']); ?></div>
            <div class="small text-muted"><?= lc_e(mb_strimwidth((string) $p['description'], 0, 140, '…')); ?></div>
            <div class="small mt-1"><?= $p['start_date'] ? '<span class="lc-chip">' . date('M Y', strtotime($p['start_date'])) . '</span>' : ''; ?><?= $p['apply_close'] ? '<span class="lc-chip">apply by ' . date('d M', strtotime($p['apply_close'])) . '</span>' : ''; ?><?= $p['open_to'] === 'students' ? '<span class="lc-chip">LC students</span>' : ''; ?></div>
        </div></div></a></div><?php } ?>
        <?php if (!$posts) { ?><div class="col-12 text-muted">No internship is open at the moment.</div><?php } ?></div>
    <?php } ?>
    <div class="card"><div class="card-body"><div class="fw-semibold mb-2">Check my internship application</div>
        <form method="POST" class="row g-2"><input type="hidden" name="csrf" value="<?= lc_e(lcp_csrf()); ?>"><input type="hidden" name="do" value="check">
            <div class="col-md-5"><input name="ref" class="form-control font-monospace text-uppercase" placeholder="RMIN<?= date('Y'); ?>-0001" required></div><div class="col-md-5"><input name="phone" class="form-control" placeholder="Telephone used" required></div><div class="col-md-2"><button class="btn btn-outline-primary w-100">Check</button></div></form></div></div>
</div>
<?php lcp_footer($conn); ?>