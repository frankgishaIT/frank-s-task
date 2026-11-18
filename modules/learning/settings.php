<?php
/** NEW FILE: modules/learning/settings.php — public website texts and address, reference format, tracking security, categories, message templates. */
require __DIR__ . '/common.php';
lcs_require($conn, 'settings');
$fields = ['public_enabled' => 'bool', 'pretty_urls' => 'bool', 'public_url' => 'text', 'portal_url' => 'text', 'title' => 'text', 'tagline' => 'text', 'intro' => 'area', 'benefits' => 'area', 'contact_phone' => 'text', 'contact_whatsapp' => 'text',
           'contact_email' => 'text', 'contact_address' => 'text', 'accreditation_note' => 'area', 'ref_format' => 'text', 'tracking_otp' => 'bool', 'categories' => 'area',
           'tpl_submitted' => 'area', 'tpl_status' => 'area', 'tpl_info' => 'area', 'tpl_admitted' => 'area', 'tpl_registered' => 'area',
           // NEW (stage 2): academic
           'att_statuses' => 'area', 'att_alert_min_sessions' => 'text', 'grade_scale' => 'area', 'assessment_types' => 'area', 'cert_format' => 'text', 'cert_signatory' => 'text', 'accreditation_verified' => 'bool',
           'tpl_timetable' => 'area', 'tpl_exam' => 'area', 'tpl_attendance' => 'area', 'tpl_results' => 'area', 'tpl_certificate' => 'area',
           // NEW (stage 3): fees
           'clearance_policy' => 'clearance', 'fee_overdue_charges' => 'bool', 'fee_reminder_days' => 'text', 'tpl_fee_invoice' => 'area', 'tpl_fee_reminder' => 'area', 'admission_letter' => 'area',
           // NEW (stage 4): channels, accommodation, internships
           'notify_sms' => 'bool', 'notify_email' => 'bool', 'notify_portal' => 'bool', 'tpl_accommodation' => 'area', 'tpl_internship' => 'area', 'internship_ref_format' => 'text', 'internship_intro' => 'area', 'internship_letter' => 'area'];
$labels = ['public_enabled' => 'Public website open', 'pretty_urls' => 'Short course links (/courses/wdf-001) — only if the server runs Apache mod_rewrite with learn/.htaccess; otherwise links are course.php?code=wdf-001', 'public_url' => 'Public address (e.g. https://learn.risemotive.rw — empty = this server /learn)', 'portal_url' => 'Customer Portal sign-in address (only when the website runs on a sub-domain, e.g. https://os.risemotive.rw/portal/login.php)', 'title' => 'Name', 'tagline' => 'Tagline',
           'intro' => 'Introduction (home page)', 'benefits' => 'Key benefits (one per line)', 'contact_phone' => 'Telephone', 'contact_whatsapp' => 'WhatsApp number (international, e.g. 250795344768)',
           'contact_email' => 'Email', 'contact_address' => 'Address', 'accreditation_note' => 'Accreditation statement (only if verified; leave empty otherwise)',
           'ref_format' => 'Application reference format ({YYYY} or {YY} = year, {NNNN} = number)', 'tracking_otp' => 'Ask an SMS code before showing an application (in addition to reference + telephone/email)',
           'categories' => 'Course categories (one per line)', 'tpl_submitted' => 'Message: application received', 'tpl_status' => 'Message: status changed', 'tpl_info' => 'Message: information requested',
           'tpl_admitted' => 'Message: admitted', 'tpl_registered' => 'Message: registered as student',
           'att_statuses' => 'Attendance statuses, one per line: Name=present / absent / excused (excused sessions are not counted)', 'att_alert_min_sessions' => 'Attendance alerts start after this number of sessions held',
           'grade_scale' => 'Grading scale, one per line: minimum %=grade (e.g. 80=A)', 'assessment_types' => 'Assessment types (one per line)', 'cert_format' => 'Certificate number format ({YYYY}, {NNNNN})',
           'cert_signatory' => 'Signatory printed on certificates', 'accreditation_verified' => 'Accreditation verified by management: print the accreditation statement on certificates and transcripts',
           'tpl_timetable' => 'Message: timetable change ({class} {message})', 'tpl_exam' => 'Message: examination date ({assessment} {class} {date})', 'tpl_attendance' => 'Message: attendance alert ({pct} {min} {class})',
           'tpl_results' => 'Message: results published ({class} {decision})', 'tpl_certificate' => 'Message: certificate ready ({number} {course})',
           'clearance_policy' => 'Financial clearance (overdue fees only)', 'fee_overdue_charges' => 'Apply the Sales overdue charges (1% a day) to fee invoices after their last due date (off = no charges on fees)',
           'fee_reminder_days' => 'Payment reminders: days before a due date', 'tpl_fee_invoice' => 'Message: new fee invoice ({invoice} {amount} {course} {plan})', 'tpl_fee_reminder' => 'Message: payment reminder ({amount} {course} {date} {invoice})',
           'admission_letter' => 'Admission letter: main text',
           'notify_sms' => 'Send notifications by SMS', 'notify_email' => 'Send notifications by email (when an email is known)', 'notify_portal' => 'Show notifications in the Customer Portal',
           'tpl_accommodation' => 'Message: accommodation ({status} {facility} {room} {bed} {from} {to})', 'tpl_internship' => 'Message: internship application ({ref} {post} {status} {message})',
           'internship_ref_format' => 'Internship reference format ({YYYY}, {NNNN})', 'internship_intro' => 'Internships page: introduction', 'internship_letter' => 'Internship completion letter: main text'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { lcs_go('settings.php', 'error', 'The page had expired.'); }
    $chg = []; $vals = [];
    foreach ($fields as $k => $t) {
        $v = $t === 'bool' ? (!empty($_POST[$k]) ? '1' : '0') : trim((string) ($_POST[$k] ?? ''));
        if ($k === 'ref_format' && !preg_match('/^[A-Za-z0-9\-\/{}]+$/', $v)) { lcs_go('settings.php', 'error', 'The reference format may contain letters, numbers, - / and the {YYYY} {YY} {NNNN} parts.'); }
        if ($k === 'internship_ref_format' && !preg_match('/^[A-Za-z0-9\-\/{}]+$/', $v)) { lcs_go('settings.php', 'error', 'The internship reference format may contain letters, numbers, - / and {YYYY} {YY} {NNNN}.'); }
        if ($k === 'clearance_policy' && !in_array($v, ['none', 'certificate', 'documents'], true)) { $v = 'none'; }
        if ($k === 'cert_format' && !preg_match('/^[A-Za-z0-9\-\/{}]+$/', $v)) { lcs_go('settings.php', 'error', 'The certificate number format may contain letters, numbers, - / and {YYYY} {YY} {NNNNN}.'); }
        if ($k === 'grade_scale' && $v !== '' && !preg_match('/^(\s*\d+(\.\d+)?\s*=\s*\S+\s*(\r?\n|$))+$/', $v)) { lcs_go('settings.php', 'error', 'Grading scale: one line per grade, like 80=A.'); }
        if (in_array($k, ['public_url', 'portal_url'], true) && $v !== '' && !preg_match('#^https?://#', $v)) { lcs_go('settings.php', 'error', 'The public address must start with https://'); }
        $vals[$k] = $v;
    }
    // FIXED: everything is checked first, then saved (an error never leaves a half-saved form).
    foreach ($vals as $k => $v) { if ($v !== lc_setting($conn, $k)) { $chg[$k] = ['from' => mb_substr(lc_setting($conn, $k), 0, 80), 'to' => mb_substr($v, 0, 80)]; lc_setting_set($conn, $k, $v); } }
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
        <?php } elseif ($t === 'clearance') { ?><label class="form-label small"><?= lc_e($labels[$k]); ?></label><select name="<?= $k; ?>" class="form-select form-select-sm"><?php foreach (['none' => 'Fees never block studies or documents', 'certificate' => 'No certificate while fees are overdue', 'documents' => 'No certificate and no final transcript while fees are overdue'] as $cv => $cl) { ?><option value="<?= $cv; ?>" <?= $v === $cv ? 'selected' : ''; ?>><?= $cl; ?></option><?php } ?></select>
        <?php } elseif ($t === 'area') { ?><label class="form-label small"><?= lc_e($labels[$k]); ?></label><textarea name="<?= $k; ?>" rows="<?= strpos($k, 'tpl_') === 0 ? 2 : 3; ?>" class="form-control form-control-sm"><?= lc_e($v); ?></textarea>
        <?php } else { ?><label class="form-label small"><?= lc_e($labels[$k]); ?></label><input name="<?= $k; ?>" class="form-control form-control-sm" value="<?= lc_e($v); ?>"><?php } ?>
    </div><?php } ?>
    <div class="col-12 small text-muted">Messages can use {name} {ref} {course} {intake} {status} {message} {track_url} {student_id}. They are sent by SMS (when SMS is set up), by email when the applicant gave one, and in the Customer Portal when the application is linked to a customer.</div>
    <div class="col-12 small text-muted">Public address: point the sub-domain (learn.risemotive.rw) to the <code>learn</code> folder of MY MOTIVE, then enter it here. Course links then look like learn.risemotive.rw/courses/wdf-001.</div>
    <div class="col-12"><button class="btn btn-primary btn-sm">Save settings</button></div>
</div></form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>