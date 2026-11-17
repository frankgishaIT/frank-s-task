<?php
/**
 * NEW FILE: modules/learning/common.php — RM LEARNING CENTRE, staff workspace (shared start of every page).
 * Permissions (Access Control): learning.view, courses, admissions, academic, finance, internship, settings. Branch-aware:
 * staff limited to some branches see only those branches' intakes, applications and students.
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/access_control.php';
require_once __DIR__ . '/../../includes/lc_helpers.php';
require_once __DIR__ . '/../../includes/branch_helpers.php';
require_once __DIR__ . '/../../includes/lc_academic.php'; // NEW (stage 2)
if (current_user_role() === 'Admin') { require_once __DIR__ . '/../../includes/admin_error_guard.php'; }
lc_ensure($conn);
lc_acad_ensure($conn);

function lcs_can(mysqli $conn, string $a): bool { return access_can('learning.' . $a, $conn); }
function lcs_require(mysqli $conn, string $a): void { if (!lcs_can($conn, $a)) { lcs_deny(); } }
function lcs_deny(): void { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
function lcs_csrf(): string { if (empty($_SESSION['lc_csrf'])) { $_SESSION['lc_csrf'] = bin2hex(random_bytes(16)); } return $_SESSION['lc_csrf']; }
function lcs_csrf_field(): string { return '<input type="hidden" name="csrf" value="' . lcs_csrf() . '">'; }
function lcs_csrf_ok(): bool { return isset($_POST['csrf'], $_SESSION['lc_csrf']) && hash_equals($_SESSION['lc_csrf'], (string) $_POST['csrf']); }
function lcs_go(string $url, string $type, string $msg): void { header('Location: ' . $url . (strpos($url, '?') === false ? '?' : '&') . $type . '=' . urlencode($msg)); exit; }

/** Branches this user may work with (null = all). Records without a branch belong to the Main Branch. */
function lcs_branch_sql(mysqli $conn, string $col): string
{
    if (!br_enabled($conn)) { return '1=1'; }
    $a = br_allowed_ids($conn);
    return $a === null ? '1=1' : 'COALESCE(' . $col . ', ' . br_main_id($conn) . ') IN (' . implode(',', array_map('intval', $a ?: [0])) . ')';
}

function lcs_branch_ok(mysqli $conn, ?int $branchId): bool
{
    if (!br_enabled($conn)) { return true; }
    $a = br_allowed_ids($conn);
    return $a === null || in_array((int) ($branchId ?: br_main_id($conn)), $a, true);
}

function lcs_badge(string $s): string
{
    $c = ['Draft' => 'secondary', 'Published' => 'primary', 'Applications Open' => 'success', 'Applications Closed' => 'dark', 'Fully Booked' => 'danger',
          'Temporarily Unavailable' => 'warning text-dark', 'Archived' => 'light text-dark border', 'Submitted' => 'primary', 'Under Review' => 'info text-dark',
          'Additional Information Required' => 'warning text-dark', 'Application Fee Pending' => 'warning text-dark', 'Application Fee Paid' => 'info text-dark',
          'Accepted' => 'success', 'Rejected' => 'danger', 'Waitlisted' => 'secondary', 'Withdrawn' => 'light text-dark border', 'Registered' => 'success',
          'Open' => 'success', 'Closed' => 'dark', 'Cancelled' => 'light text-dark border', 'Planned' => 'secondary', 'Running' => 'success', 'Completed' => 'dark', 'Suspended' => 'warning text-dark',
          'Scheduled' => 'primary', 'Held' => 'success', 'Pass' => 'success', 'Fail' => 'danger', 'Incomplete' => 'warning text-dark', 'Failed' => 'danger',
          'Valid' => 'success', 'Revoked' => 'danger', 'Replaced' => 'secondary', 'Active' => 'success', 'Inactive' => 'secondary'][$s] ?? 'secondary';
    return '<span class="badge bg-' . $c . '">' . lc_e($s) . '</span>';
}

function lcs_tabs(mysqli $conn, string $active, string $title, string $right = ''): void
{
    $tabs = [['index.php', 'Dashboard', 'bi-speedometer2', 'view'], ['courses.php', 'Courses', 'bi-journal-bookmark', 'view'], ['intakes.php', 'Intakes', 'bi-calendar-event', 'view'],
             ['applications.php', 'Applications', 'bi-inboxes', 'admissions'], ['students.php', 'Students', 'bi-mortarboard', 'view'],
             ['classes.php', 'Classes', 'bi-easel', 'classes'], ['instructors.php', 'Instructors', 'bi-person-video3', 'academic'], ['certificates.php', 'Certificates', 'bi-patch-check', 'academic'],
             ['centres.php', 'Training Centres', 'bi-building', 'courses'], ['settings.php', 'Settings', 'bi-sliders', 'settings'], ['audit.php', 'Audit Trail', 'bi-journal-text', 'settings']];
    echo '<style>.lc-tabs{display:flex;flex-wrap:wrap;gap:2px;border-bottom:1px solid #E4E7EF;margin-bottom:14px}.lc-tabs a{padding:7px 12px;font-size:13.5px;color:#5B6275;text-decoration:none;border-bottom:2px solid transparent}
        .lc-tabs a.on{color:#1E2FE0;border-bottom-color:#1E2FE0;font-weight:600}.lc-tabs a:hover{color:#1E2FE0}.lc-k .lbl{font-size:12px;color:#6B7280}.lc-k .val{font-weight:700;font-size:1.25rem}</style>';
    echo '<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2"><div><h3 class="mb-0">' . lc_e($title) . '</h3><div class="small text-muted">RM Learning Centre · '
        . '<a href="' . lc_e(lc_public_base($conn)) . '/" target="_blank" rel="noopener">public website <i class="bi bi-box-arrow-up-right"></i></a></div></div>' . $right . '</div>';
    echo '<div class="lc-tabs">';
    foreach ($tabs as [$f, $l, $i, $p]) { if ($p === 'classes' ? lcs_classes_visible($conn) : lcs_can($conn, $p)) { echo '<a href="' . $f . '" class="' . ($f === $active ? 'on' : '') . '"><i class="bi ' . $i . '"></i> ' . $l . '</a>'; } }
    echo '</div>';
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) { if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $c . ' py-2 alert-dismissible fade show">' . lc_e($_GET[$k]) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'; } }
}

/** NEW (stage 2): who may open Classes — staff with view / academic permission, and instructors (their own classes only). */
function lcs_classes_visible(mysqli $conn): bool { return lcs_can($conn, 'view') || lcs_can($conn, 'academic') || lc_my_instructor_id($conn) !== null; }

/**
 * NEW (stage 2): what the signed-in user may do in a class: 'manage' (academic staff of the branch: everything, publish results,
 * certificates), 'teach' (assigned instructor: register attendance, enter marks, see students), 'view' (view only), or null.
 */
function lcs_class_role(mysqli $conn, array $class): ?string
{
    if (!lcs_branch_ok($conn, $class['branch_id'] ? (int) $class['branch_id'] : null)) { return null; }
    if (lcs_can($conn, 'academic')) { return 'manage'; }
    $me = lc_my_instructor_id($conn);
    if ($me !== null && in_array((int) $class['id'], lc_instructor_class_ids($conn, $me), true)) { return 'teach'; }
    return lcs_can($conn, 'view') ? 'view' : null;
}

function lcs_users(mysqli $conn): array
{
    $o = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names'), MYSQLI_ASSOC) as $u) { $o[(int) $u['id']] = $u['names']; }
    return $o;
}

/** Share box: link, Copy, WhatsApp, Facebook, LinkedIn, X, email, QR code (rendered in the browser, downloadable PNG). */
function lcs_share_box(mysqli $conn, array $course): void
{
    $url = lc_course_url($conn, $course);
    $txt = $course['title'] . ' — RM Learning Centre. ' . ($course['summary'] ?: 'Apply online:');
    $links = ['whatsapp' => 'https://wa.me/?text=' . rawurlencode($txt . ' ' . lc_course_url($conn, $course, 'wa')),
              'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode(lc_course_url($conn, $course, 'fb')),
              'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode(lc_course_url($conn, $course, 'li')),
              'twitter-x' => 'https://twitter.com/intent/tweet?text=' . rawurlencode($txt) . '&url=' . rawurlencode(lc_course_url($conn, $course, 'x')),
              'envelope' => 'mailto:?subject=' . rawurlencode($course['title']) . '&body=' . rawurlencode($txt . ' ' . lc_course_url($conn, $course, 'email'))];
    $qr = lc_course_url($conn, $course, 'qr');
    ?>
    <div class="input-group input-group-sm mb-2"><input class="form-control" value="<?= lc_e($url); ?>" readonly id="lcUrl"><button class="btn btn-outline-primary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('lcUrl').value);this.innerText='Copied';">Copy link</button></div>
    <div class="d-flex flex-wrap gap-1 mb-2"><?php foreach ($links as $i => $l) { ?><a class="btn btn-sm btn-light border" target="_blank" rel="noopener" href="<?= lc_e($l); ?>"><i class="bi bi-<?= $i; ?>"></i> <?= ucfirst(str_replace('twitter-x', 'X', $i === 'envelope' ? 'Email' : $i)); ?></a><?php } ?></div>
    <div class="d-flex align-items-center gap-3"><div id="lcQr" data-url="<?= lc_e($qr); ?>" style="width:132px;height:132px;" class="border rounded bg-white d-flex align-items-center justify-content-center small text-muted">QR</div>
        <div class="small text-muted">The QR code opens the course page directly and counts applications as "QR code".<br><a href="#" id="lcQrDl">Download the QR code (image)</a></div></div>
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js"></script>
    <script>(function(){try{var el=document.getElementById('lcQr');var q=qrcode(0,'M');q.addData(el.dataset.url);q.make();el.innerHTML=q.createImgTag(4,8);var img=el.querySelector('img');
        document.getElementById('lcQrDl').addEventListener('click',function(e){e.preventDefault();var big=qrcode(0,'M');big.addData(el.dataset.url);big.make();var a=document.createElement('a');a.href=big.createDataURL(10,20);a.download='<?= lc_e(strtolower($course['code'])); ?>-qr.gif';a.click();});}catch(e){}})();</script>
    <?php
}