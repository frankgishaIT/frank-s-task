<?php
/** NEW FILE: modules/learning/settings.php — public website texts and address, reference format, tracking security, categories, message templates. */
require __DIR__ . '/common.php';
lcs_require($conn, 'settings');
$fields = ['public_enabled' => 'bool', 'public_url' => 'text', 'portal_url' => 'text', 'title' => 'text', 'tagline' => 'text', 'intro' => 'area', 'benefits' => 'area', 'contact_phone' => 'text', 'contact_whatsapp' => 'text',
           'contact_email' => 'text', 'contact_address' => 'text', 'accreditation_note' => 'area', 'ref_format' => 'text', 'tracking_otp' => 'bool', 'categories' => 'area',
           'tpl_submitted' => 'area', 'tpl_status' => 'area', 'tpl_info' => 'area', 'tpl_admitted' => 'area', 'tpl_registered' => 'area'];
$labels = ['public_enabled' => 'Public website open', 'public_url' => 'Public address (e.g. https://learn.risemotive.rw — empty = this server /learn)', 'portal_url' => 'Customer Portal sign-in address (only when the website runs on a sub-domain, e.g. https://os.risemotive.rw/portal/login.php)', 'title' => 'Name', 'tagline' => 'Tagline',
           'intro' => 'Introduction (home page)', 'benefits' => 'Key benefits (one per line)', 'contact_phone' => 'Telephone', 'contact_whatsapp' => 'WhatsApp number (international, e.g. 250795344768)',
           'contact_email' => 'Email', 'contact_address' => 'Address', 'accreditation_note' => 'Accreditation statement (only if verified; leave empty otherwise)',
           'ref_format' => 'Application reference format ({YYYY} or {YY} = year, {NNNN} = number)', 'tracking_otp' => 'Ask an SMS code before showing an application (in addition to reference + telephone/email)',
           'categories' => 'Course categories (one per line)', 'tpl_submitted' => 'Message: application received', 'tpl_status' => 'Message: status changed', 'tpl_info' => 'Message: information requested',
           'tpl_admitted' => 'Message: admitted', 'tpl_registered' => 'Message: registered as student'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { lcs_go('settings.php', 'error', 'The page had expired.'); }
    $chg = [];
    foreach ($fields as $k => $t) {
        $v = $t === 'bool' ? (!empty($_POST[$k]) ? '1' : '0') : trim((string) ($_POST[$k] ?? ''));
        if ($k === 'ref_format' && !preg_match('/^[A-Za-z0-9\-\/{}]+$/', $v)) { lcs_go('settings.php', 'error', 'The reference format may contain letters, numbers, - / and the {YYYY} {YY} {NNNN} parts.'); }
        if (in_array($k, ['public_url', 'portal_url'], true) && $v !== '' && !preg_match('#^https?://#', $v)) { lcs_go('settings.php', 'error', 'The public address must start with https://'); }
        if ($v !== lc_setting($conn, $k)) { $chg[$k] = ['from' => mb_substr(lc_setting($conn, $k), 0, 80), 'to' => mb_substr($v, 0, 80)]; lc_setting_set($conn, $k, $v); }
    }
    if ($chg) { lc_audit($conn, 'settings', 'settings', null, $chg); }
    lcs_go('settings.php', 'success', $chg ? 'Saved (' . count($chg) . ' change(s)).' : 'Nothing changed.');
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'settings.php', 'Learning Centre Settings');
?>
<form method="POST" class="card border-0 shadow-sm" style="max-width:900px;"><div class="card-body row g-2"><?= lcs_csrf_field(); ?>
    <?php foreach ($fields as $k => $t) { $v = trim(lc_setting($conn, $k)); ?>
    <div class="<?= $t === 'area' ? 'col-12' : 'col-md-6'; ?>">
        <?php if ($t === 'bool') { ?><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="<?= $k; ?>" value="1" id="s<?= $k; ?>" <?= $v === '1' ? 'checked' : ''; ?>><label class="form-check-label small" for="s<?= $k; ?>"><?= lc_e($labels[$k]); ?></label></div>
        <?php } elseif ($t === 'area') { ?><label class="form-label small"><?= lc_e($labels[$k]); ?></label><textarea name="<?= $k; ?>" rows="<?= strpos($k, 'tpl_') === 0 ? 2 : 3; ?>" class="form-control form-control-sm"><?= lc_e($v); ?></textarea>
        <?php } else { ?><label class="form-label small"><?= lc_e($labels[$k]); ?></label><input name="<?= $k; ?>" class="form-control form-control-sm" value="<?= lc_e($v); ?>"><?php } ?>
    </div><?php } ?>
    <div class="col-12 small text-muted">Messages can use {name} {ref} {course} {intake} {status} {message} {track_url} {student_id}. They are sent by SMS (when SMS is set up), by email when the applicant gave one, and in the Customer Portal when the application is linked to a customer.</div>
    <div class="col-12 small text-muted">Public address: point the sub-domain (learn.risemotive.rw) to the <code>learn</code> folder of MY MOTIVE, then enter it here. Course links then look like learn.risemotive.rw/courses/wdf-001.</div>
    <div class="col-12"><button class="btn btn-primary btn-sm">Save settings</button></div>
</div></form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>