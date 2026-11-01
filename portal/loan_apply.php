<?php
/**
 * NEW FILE: portal/loan_apply.php — APPLY FOR A LOAN.
 * Six sections; information already in the profile is filled in; "Save draft" at any time; a review screen before
 * the final submission; collateral appears only above the unsecured limit; one open application at a time.
 */
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/cl_helpers.php';
require_once __DIR__ . '/../includes/evidence_store.php';
cl_ensure($conn);
if (cl_setting($conn, 'enabled') !== '1') { header('Location: loans.php'); exit; }
$el = cl_eligibility($conn, $cid);
$id = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
$app = null;
if ($id) {
    $app = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM cl_applications WHERE id = $id AND customer_id = $cid"));
    if (!$app || $app['status'] !== 'Draft') { header('Location: ' . ($app ? 'loan.php?id=' . $id : 'loans.php')); exit; }
} else {
    // One application at a time: continue the draft, or wait for the open application to be decided.
    $open = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, status FROM cl_applications WHERE customer_id = $cid AND status IN ('Draft', 'Submitted', 'Under Review', 'Additional Information Required', 'Approved', 'Awaiting Customer Acceptance') ORDER BY id DESC LIMIT 1"));
    if ($open) { header('Location: ' . ($open['status'] === 'Draft' ? 'loan_apply.php?id=' : 'loan.php?id=') . (int) $open['id'] . ($open['status'] === 'Draft' ? '' : '&error=' . urlencode('You already have an application in progress.'))); exit; }
}
$periods = array_values(array_filter(array_map('intval', explode(',', cl_setting($conn, 'periods')))));
$freqs = array_values(array_filter(array_map('trim', explode(',', cl_setting($conn, 'frequencies')))));
$min = (float) cl_setting($conn, 'min_amount'); $max = (float) cl_setting($conn, 'max_amount');
$P = ['national_id' => 'National ID number', 'date_of_birth' => 'Date of birth', 'gender' => 'Gender', 'email' => 'Email address', 'address' => 'Residential address',
      'district' => 'District', 'sector' => 'Sector', 'cell' => 'Cell', 'marital_status' => 'Marital status'];
$F = ['occupation' => 'Current occupation', 'employer' => 'Employer or business name', 'work_location' => 'Employment / business location', 'monthly_income' => 'Monthly income (RWF)',
      'other_income' => 'Other sources of income', 'household_expenses' => 'Monthly household expenses (RWF)', 'existing_loans' => 'Existing loan obligations (RWF per month)',
      'other_debts' => 'Other outstanding debts (RWF)', 'repayment_capacity' => 'Estimated monthly repayment capacity (RWF)', 'repayment_source' => 'Main source of repayment'];
$C = ['type' => 'Type of collateral', 'description' => 'Description of the asset', 'value' => 'Estimated market value (RWF)', 'ownership' => 'Ownership status', 'location' => 'Location of the asset'];
$fin = $app ? (json_decode((string) $app['financial'], true) ?: []) : [];
$col = $app ? (json_decode((string) $app['collateral'], true) ?: []) : [];
$val = function ($k) use ($app) { return $app[$k] ?? ''; };
$step = 'form';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    try {
        if (!portal_csrf_ok()) { throw new RuntimeException('Your session expired. Please try again.'); }
        // 1. Personal information: kept on the customer's profile (phone and name stay as verified).
        $prof = [];
        foreach (array_keys($P) as $k) { $prof[$k] = mb_substr(trim((string) ($_POST['p'][$k] ?? '')), 0, 120); }
        if ($prof['email'] !== '' && !filter_var($prof['email'], FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('Enter a valid email address.'); }
        if ($prof['date_of_birth'] !== '' && !DateTime::createFromFormat('Y-m-d', $prof['date_of_birth'])) { $prof['date_of_birth'] = ''; }
        $sets = []; $types = ''; $vals = [];
        foreach ($prof as $k => $v) { $sets[] = "$k = ?"; $types .= 's'; $vals[] = $v === '' ? null : $v; }
        $types .= 'i'; $vals[] = $cid;
        $s = mysqli_prepare($conn, 'UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = ?');
        mysqli_stmt_bind_param($s, $types, ...$vals); mysqli_stmt_execute($s);
        // 2-4. Loan, financial and collateral details.
        $d = [
            'requested_amount' => ($x = (float) ($_POST['requested_amount'] ?? 0)) > 0 ? $x : null,
            'purpose' => in_array($_POST['purpose'] ?? '', CL_PURPOSES, true) ? $_POST['purpose'] : null,
            'purpose_other' => mb_substr(trim((string) ($_POST['purpose_other'] ?? '')), 0, 120) ?: null,
            'intended_use' => trim((string) ($_POST['intended_use'] ?? '')) ?: null,
            'period_months' => in_array((int) ($_POST['period_months'] ?? 0), $periods, true) ? (int) $_POST['period_months'] : null,
            'frequency' => in_array($_POST['frequency'] ?? '', $freqs, true) ? $_POST['frequency'] : null,
            'proposed_date' => DateTime::createFromFormat('Y-m-d', (string) ($_POST['proposed_date'] ?? '')) ? $_POST['proposed_date'] : null,
            'disbursement_method' => in_array($_POST['disbursement_method'] ?? '', CL_DISBURSE, true) ? $_POST['disbursement_method'] : null,
            'has_collateral' => in_array($_POST['has_collateral'] ?? '', ['yes', 'no'], true) ? $_POST['has_collateral'] : null,
        ];
        $fin = []; foreach (array_keys($F) as $k) { $fin[$k] = mb_substr(trim((string) ($_POST['f'][$k] ?? '')), 0, 300); }
        $col = []; foreach (array_keys($C) as $k) { $col[$k] = mb_substr(trim((string) ($_POST['c'][$k] ?? '')), 0, 500); }
        $above = $d['requested_amount'] !== null && $d['requested_amount'] > $el['limit'];
        if (!$above) { $d['has_collateral'] = null; }
        $d['financial'] = json_encode($fin, JSON_UNESCAPED_UNICODE);
        $d['collateral'] = json_encode($above && $d['has_collateral'] === 'yes' ? $col : [], JSON_UNESCAPED_UNICODE);
        $d['personal'] = json_encode(['name' => $me['name'], 'customer_id' => portal_customer_code($cid), 'phone' => $me['phone']] + $prof, JSON_UNESCAPED_UNICODE);
        mysqli_begin_transaction($conn);
        if (!$id) {
            mysqli_query($conn, "INSERT INTO cl_applications (customer_id, status) VALUES ($cid, 'Draft')");
            $id = (int) mysqli_insert_id($conn);
            cl_audit($conn, $id, 'Draft created', null, 'Draft', null, null, $cid);
        }
        $sets = []; $types = ''; $vals = [];
        foreach ($d as $k => $v) { $sets[] = "$k = ?"; $types .= is_float($v) ? 'd' : (is_int($v) ? 'i' : 's'); $vals[] = $v; }
        $types .= 'i'; $vals[] = $id;
        $s = mysqli_prepare($conn, 'UPDATE cl_applications SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ? AND status = \'Draft\'');
        mysqli_stmt_bind_param($s, $types, ...$vals); mysqli_stmt_execute($s);
        // Supporting documents (income, collateral documents, collateral photos).
        foreach (['income_docs' => 'income', 'collateral_docs' => 'collateral_document', 'collateral_photos' => 'collateral_photo'] as $field => $kind) {
            $files = $_FILES[$field] ?? null;
            if (!$files || !is_array($files['name'])) { continue; }
            foreach ($files['name'] as $i => $n) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
                $one = ['name' => $n, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                $ref = evidence_save($conn, $one, null, CL_DOC_TYPES, 10 * 1024 * 1024);
                $nm = mb_substr(basename((string) $n), 0, 255);
                $s = mysqli_prepare($conn, 'INSERT INTO cl_documents (application_id, kind, file_ref, original_name) VALUES (?, ?, ?, ?)');
                mysqli_stmt_bind_param($s, 'isss', $id, $kind, $ref, $nm); mysqli_stmt_execute($s);
            }
        }
        foreach ((array) ($_POST['remove_doc'] ?? []) as $did) { mysqli_query($conn, 'DELETE FROM cl_documents WHERE id = ' . (int) $did . " AND application_id = $id"); }
        mysqli_commit($conn);
        $app = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM cl_applications WHERE id = $id"));

        if ($action === 'save') { header('Location: loan_apply.php?id=' . $id . '&success=' . urlencode('Draft saved. You can continue later from RM Loans.')); exit; }
        // Full check (review and submit).
        $err = [];
        foreach (['national_id', 'date_of_birth', 'address', 'district', 'sector', 'cell'] as $k) { if ($prof[$k] === '') { $err[] = $P[$k] . ' is required.'; } }
        if ($d['requested_amount'] === null) { $err[] = 'Enter the amount you want to borrow.'; }
        elseif ($d['requested_amount'] < $min || $d['requested_amount'] > $max) { $err[] = 'The amount must be between ' . cl_money($min) . ' and ' . cl_money($max) . '.'; }
        if (!$d['purpose']) { $err[] = 'Choose the loan purpose.'; } elseif ($d['purpose'] === 'Other' && !$d['purpose_other']) { $err[] = 'Specify the loan purpose.'; }
        if (!$d['intended_use']) { $err[] = 'Explain how you will use the money.'; }
        if (!$d['period_months'] || !$d['frequency'] || !$d['proposed_date'] || !$d['disbursement_method']) { $err[] = 'Complete the repayment period, frequency, proposed repayment date and disbursement method.'; }
        if ($d['proposed_date'] && $d['proposed_date'] <= date('Y-m-d')) { $err[] = 'The proposed repayment date must be in the future.'; }
        foreach (['occupation', 'monthly_income', 'household_expenses', 'repayment_capacity', 'repayment_source'] as $k) { if ($fin[$k] === '') { $err[] = $F[$k] . ' is required.'; } }
        if ($above && !$d['has_collateral']) { $err[] = 'Your amount is above your unsecured limit: say whether you have collateral.'; }
        if ($above && $d['has_collateral'] === 'yes') { foreach (array_keys($C) as $k) { if ($col[$k] === '') { $err[] = $C[$k] . ' is required.'; } } }
        if ($err) { throw new RuntimeException(implode(' ', $err)); }
        if ($action === 'review') { $step = 'review'; }
        if ($action === 'submit') {
            if (empty($_POST['declare'])) { $step = 'review'; throw new RuntimeException('Tick the declaration to submit.'); }
            $pts = $el['points']; $lim = $el['limit'];
            $s = mysqli_prepare($conn, "UPDATE cl_applications SET status = 'Submitted', submitted_at = NOW(), declared_at = NOW(), points_at_submit = ?, limit_at_submit = ?, updated_at = NOW() WHERE id = ? AND status = 'Draft'");
            mysqli_stmt_bind_param($s, 'idi', $pts, $lim, $id); mysqli_stmt_execute($s);
            if (mysqli_stmt_affected_rows($s) !== 1) { header('Location: loan.php?id=' . $id); exit; } // already submitted (double click)
            cl_audit($conn, $id, 'Submitted by the customer (declaration accepted)', 'Draft', 'Submitted', null, null, $cid);
            portal_notify_customer($conn, $cid, CL_NOTICES['Submitted'] . ' (' . cl_ref($id, $app['created_at']) . ')', 'We will review it and let you know.', 'loan.php?id=' . $id);
            portal_notify_staff($conn, 'New loan application ' . cl_ref($id, $app['created_at']), $me['name'] . ' applied for ' . cl_money($d['requested_amount']) . '.');
            header('Location: loan.php?id=' . $id . '&success=' . urlencode('Application ' . cl_ref($id, $app['created_at']) . ' submitted on ' . date('d M Y \a\t H:i') . '. We will notify you of every step.')); exit;
        }
    } catch (RuntimeException $ex) { @mysqli_rollback($conn); $error = $ex->getMessage(); if ($id) { $app = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM cl_applications WHERE id = $id")); } }
    $me = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM customers WHERE id = $cid"));
}
$docs = $id ? mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM cl_documents WHERE application_id = $id ORDER BY id"), MYSQLI_ASSOC) : [];
$amount = (float) ($app['requested_amount'] ?? 0);
portal_header('Apply for a loan', $me);
portal_alerts();
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= portal_e($error); ?></div><?php } ?>
<?php if ($step === 'review') { $calc = cl_schedule_calc($amount, (float) cl_setting($conn, 'monthly_rate'), (int) $app['period_months'], (string) $app['frequency'], cl_setting($conn, 'interest_method'), (float) cl_setting($conn, 'fee_pct'), date('Y-m-d')); ?>
<div class="card mb-3"><div class="card-body">
    <h1 class="h5">Review your application</h1>
    <p class="small text-muted">Check everything before you submit. After submitting, you can no longer change it (unless RISE MOTIVE asks for more information).</p>
    <table class="table table-sm small">
        <tr><th style="width:40%;">Amount requested</th><td><strong><?= cl_money($amount); ?></strong> <?= $amount > $el['limit'] ? '<span class="badge bg-warning">above your unsecured limit of ' . cl_money($el['limit']) . '</span>' : '<span class="badge bg-success">within your unsecured limit</span>'; ?></td></tr>
        <tr><th>Purpose</th><td><?= portal_e($app['purpose'] === 'Other' ? $app['purpose_other'] : $app['purpose']); ?> — <?= portal_e($app['intended_use']); ?></td></tr>
        <tr><th>Repayment</th><td><?= (int) $app['period_months']; ?> month(s), <?= portal_e(strtolower((string) $app['frequency'])); ?> · proposed date <?= date('d M Y', strtotime($app['proposed_date'])); ?> · paid by <?= portal_e($app['disbursement_method']); ?></td></tr>
        <tr><th>Estimated cost</th><td>interest <?= cl_money($calc['totals']['interest']); ?><?= $calc['totals']['fee'] > 0 ? ' + fee ' . cl_money($calc['totals']['fee']) : ''; ?> → total to repay <strong><?= cl_money($calc['totals']['total']); ?></strong> in <?= (int) $calc['totals']['instalments']; ?> instalment(s) (estimate at <?= (float) cl_setting($conn, 'monthly_rate'); ?>% per month)</td></tr>
        <tr><th>Collateral</th><td><?= $amount <= $el['limit'] ? 'Not required' : ($app['has_collateral'] === 'yes' ? 'Yes: ' . portal_e((json_decode((string) $app['collateral'], true)['type'] ?? '') . ', ' . (json_decode((string) $app['collateral'], true)['description'] ?? '')) : 'No collateral'); ?></td></tr>
        <tr><th>Documents</th><td><?= count($docs); ?> file(s)</td></tr>
    </table>
    <details class="small mb-3"><summary>Loan terms and privacy notice</summary><div class="border rounded p-2 mt-2" style="white-space:pre-wrap;"><?= portal_e(cl_setting($conn, 'terms')); ?>

<?= portal_e(cl_setting($conn, 'privacy')); ?></div></details>
    <form method="POST"><?= portal_csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $id; ?>"><input type="hidden" name="action" value="submit">
        <?php foreach ($_POST as $k => $v) { if (in_array($k, ['csrf', 'action', 'id', 'declare'], true)) { continue; } if (is_array($v)) { foreach ($v as $kk => $vv) { if (!is_array($vv)) { echo '<input type="hidden" name="' . portal_e($k) . '[' . portal_e($kk) . ']" value="' . portal_e($vv) . '">'; } } } else { echo '<input type="hidden" name="' . portal_e($k) . '" value="' . portal_e($v) . '">'; } } ?>
        <ul class="small"><li>All the information I provided is accurate.</li><li>I authorise RISE MOTIVE or its authorised financial partner to verify relevant financial information, as permitted by law.</li>
            <li>I consent to the processing of my personal information for this loan assessment.</li><li>I have read the loan terms, understand the interest rate and repayment obligations, and the consequences of late payment or default.</li></ul>
        <label class="d-block mb-3"><input type="checkbox" name="declare" value="1" class="form-check-input me-2" required>I confirm that the information provided is accurate and that I have read and accepted the loan application terms and privacy notice.</label>
        <div class="d-flex gap-2"><button class="btn btn-primary" onclick="this.disabled=true; this.form.submit();">Submit application</button><a href="loan_apply.php?id=<?= (int) $id; ?>" class="btn btn-outline-secondary">Back to edit</a></div>
    </form>
</div></div>
<?php } else { ?>
<form method="POST" enctype="multipart/form-data" id="appForm"><?= portal_csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $id; ?>">
<div class="d-flex justify-content-between align-items-center mb-2"><h1 class="h5 mb-0">Apply for a loan<?= $app ? ' · ' . cl_ref($id, $app['created_at']) . ' (draft)' : ''; ?></h1>
    <span class="small text-muted">Unsecured limit available: <strong><?= cl_money($el['limit']); ?></strong></span></div>

<div class="card mb-3"><div class="card-body"><h2 class="h6">1. Personal information</h2>
    <div class="small text-muted mb-2"><?= portal_e($me['name']); ?> · <?= portal_customer_code($cid); ?> · phone <?= portal_e($me['phone']); ?> (to change these, send a request to RISE MOTIVE)</div>
    <div class="row g-2">
        <?php foreach ($P as $k => $l) { $v = $me[$k] ?? ''; ?><div class="col-md-4"><label class="form-label small text-muted mb-1"><?= $l; ?><?= in_array($k, ['national_id', 'date_of_birth', 'address', 'district', 'sector', 'cell'], true) ? ' *' : ''; ?></label>
            <?php if ($k === 'gender') { ?><select name="p[gender]" class="form-select form-select-sm"><option value=""></option><?php foreach (['Female', 'Male'] as $o) { ?><option <?= $v === $o ? 'selected' : ''; ?>><?= $o; ?></option><?php } ?></select>
            <?php } elseif ($k === 'marital_status') { ?><select name="p[marital_status]" class="form-select form-select-sm"><option value=""></option><?php foreach (['Single', 'Married', 'Divorced', 'Widowed'] as $o) { ?><option <?= $v === $o ? 'selected' : ''; ?>><?= $o; ?></option><?php } ?></select>
            <?php } else { ?><input type="<?= $k === 'date_of_birth' ? 'date' : ($k === 'email' ? 'email' : 'text'); ?>" name="p[<?= $k; ?>]" class="form-control form-control-sm" value="<?= portal_e($v); ?>"><?php } ?></div><?php } ?>
    </div></div></div>

<div class="card mb-3"><div class="card-body"><h2 class="h6">2. Loan details</h2><div class="row g-2">
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Amount (RWF) *</label><input type="number" name="requested_amount" id="amt" min="<?= (int) $min; ?>" max="<?= (int) $max; ?>" step="1000" class="form-control form-control-sm" value="<?= portal_e($val('requested_amount') !== '' ? (int) $val('requested_amount') : ''); ?>">
        <div class="form-text">Between <?= cl_money($min); ?> and <?= cl_money($max); ?>.</div></div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Purpose *</label><select name="purpose" id="purpose" class="form-select form-select-sm"><option value=""></option><?php foreach (CL_PURPOSES as $o) { ?><option <?= $val('purpose') === $o ? 'selected' : ''; ?>><?= $o; ?></option><?php } ?></select></div>
    <div class="col-md-4" id="purposeOther"><label class="form-label small text-muted mb-1">Other purpose</label><input name="purpose_other" class="form-control form-control-sm" maxlength="120" value="<?= portal_e($val('purpose_other')); ?>"></div>
    <div class="col-12"><label class="form-label small text-muted mb-1">How will you use the money? *</label><textarea name="intended_use" class="form-control form-control-sm" rows="2"><?= portal_e($val('intended_use')); ?></textarea></div>
    <div class="col-6 col-md-3"><label class="form-label small text-muted mb-1">Repayment period *</label><select name="period_months" class="form-select form-select-sm"><option value=""></option><?php foreach ($periods as $o) { ?><option value="<?= $o; ?>" <?= (int) $val('period_months') === $o ? 'selected' : ''; ?>><?= $o; ?> month<?= $o > 1 ? 's' : ''; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label small text-muted mb-1">Repayment frequency *</label><select name="frequency" class="form-select form-select-sm"><option value=""></option><?php foreach ($freqs as $o) { ?><option <?= $val('frequency') === $o ? 'selected' : ''; ?>><?= portal_e($o); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label small text-muted mb-1">Proposed repayment date *</label><input type="date" name="proposed_date" class="form-control form-control-sm" value="<?= portal_e($val('proposed_date')); ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label small text-muted mb-1">Disbursement method *</label><select name="disbursement_method" class="form-select form-select-sm"><option value=""></option><?php foreach (CL_DISBURSE as $o) { ?><option <?= $val('disbursement_method') === $o ? 'selected' : ''; ?>><?= $o; ?></option><?php } ?></select></div>
</div></div></div>

<div class="card mb-3"><div class="card-body"><h2 class="h6">3. Financial information</h2><div class="row g-2">
    <?php foreach ($F as $k => $l) { ?><div class="col-md-6"><label class="form-label small text-muted mb-1"><?= $l; ?><?= in_array($k, ['occupation', 'monthly_income', 'household_expenses', 'repayment_capacity', 'repayment_source'], true) ? ' *' : ''; ?></label>
        <input <?= strpos($l, '(RWF') !== false ? 'type="number" min="0" step="1000"' : 'type="text"'; ?> name="f[<?= $k; ?>]" class="form-control form-control-sm" value="<?= portal_e($fin[$k] ?? ''); ?>"></div><?php } ?>
    <div class="col-12"><label class="form-label small text-muted mb-1">Supporting documents (salary slips, bank statements, business records…)</label><input type="file" name="income_docs[]" multiple class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx"></div>
</div></div></div>

<div class="card mb-3"><div class="card-body"><h2 class="h6">4. Collateral</h2>
    <div id="colWithin" class="alert alert-success small mb-0">Your requested amount falls within your unsecured loan eligibility. Collateral information is not required at this stage.</div>
    <div id="colAbove">
        <div class="alert alert-warning small">Your requested loan amount exceeds your unsecured eligibility limit (<?= cl_money($el['limit']); ?>). Do you have collateral that can support your application?</div>
        <label class="me-3"><input type="radio" name="has_collateral" value="yes" class="form-check-input me-1" <?= $val('has_collateral') === 'yes' ? 'checked' : ''; ?>>YES, I HAVE COLLATERAL</label>
        <label><input type="radio" name="has_collateral" value="no" class="form-check-input me-1" <?= $val('has_collateral') === 'no' ? 'checked' : ''; ?>>NO, I DO NOT HAVE COLLATERAL</label>
        <div id="colNo" class="small text-muted mt-2">Your application may be assessed within your unsecured limit, or considered under other approval arrangements.</div>
        <div id="colYes" class="row g-2 mt-1">
            <div class="col-md-4"><label class="form-label small text-muted mb-1">Type of collateral *</label><select name="c[type]" class="form-select form-select-sm"><option value=""></option><?php foreach (CL_COLLATERAL as $o) { ?><option <?= ($col['type'] ?? '') === $o ? 'selected' : ''; ?>><?= $o; ?></option><?php } ?></select></div>
            <?php foreach (['description', 'value', 'ownership', 'location'] as $k) { ?><div class="col-md-4"><label class="form-label small text-muted mb-1"><?= $C[$k]; ?> *</label><input <?= $k === 'value' ? 'type="number" min="0"' : 'type="text"'; ?> name="c[<?= $k; ?>]" class="form-control form-control-sm" value="<?= portal_e($col[$k] ?? ''); ?>"></div><?php } ?>
            <div class="col-md-6"><label class="form-label small text-muted mb-1">Ownership documents</label><input type="file" name="collateral_docs[]" multiple class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
            <div class="col-md-6"><label class="form-label small text-muted mb-1">Photographs of the asset</label><input type="file" name="collateral_photos[]" multiple class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp"></div>
            <div class="col-12 small text-muted">Submitting collateral does not mean RISE MOTIVE accepts it as security: it is assessed with your application.</div>
        </div>
    </div>
</div></div>

<?php if ($docs) { ?><div class="card mb-3"><div class="card-body small"><h2 class="h6">Files attached</h2>
    <?php foreach ($docs as $d) { ?><label class="d-block"><input type="checkbox" name="remove_doc[]" value="<?= (int) $d['id']; ?>" class="form-check-input me-1"><?= portal_e(str_replace('_', ' ', $d['kind']) . ': ' . $d['original_name']); ?> <span class="text-muted">(tick to remove)</span></label><?php } ?>
</div></div><?php } ?>

<div class="d-flex flex-wrap gap-2 mb-4">
    <button name="action" value="review" class="btn btn-primary">Continue to review &amp; declaration</button>
    <button name="action" value="save" class="btn btn-outline-primary" formnovalidate>Save draft</button>
    <a href="loans.php" class="btn btn-link">Cancel</a>
</div>
</form>
<script>
(function () {
    var limit = <?= json_encode((float) $el['limit']); ?>, amt = document.getElementById('amt'), purpose = document.getElementById('purpose');
    function sync() {
        var above = (+amt.value || 0) > limit;
        document.getElementById('colWithin').style.display = above ? 'none' : '';
        document.getElementById('colAbove').style.display = above ? '' : 'none';
        var yes = document.querySelector('input[name=has_collateral][value=yes]').checked, no = document.querySelector('input[name=has_collateral][value=no]').checked;
        document.getElementById('colYes').style.display = above && yes ? '' : 'none';
        document.getElementById('colNo').style.display = above && no ? '' : 'none';
        document.getElementById('purposeOther').style.display = purpose.value === 'Other' ? '' : 'none';
    }
    document.getElementById('appForm').addEventListener('input', sync); document.getElementById('appForm').addEventListener('change', sync); sync();
})();
</script>
<?php } ?>
<?php portal_footer();