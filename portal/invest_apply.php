<?php
/**
 * NEW FILE: portal/invest_apply.php — INVEST NOW: the investment application (profile, amount, risk acknowledgement,
 * agreement acceptance). Limits are checked on the server; "Save draft"; a review step; no double submission.
 */
require __DIR__ . '/invest_start.php';
$id = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
$a = $id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_applications WHERE id = $id AND customer_id = $cid AND status = 'Draft'")) : null;
if ($id && !$a) { header('Location: my_investments.php'); exit; }
$pid = $a ? (int) $a['project_id'] : (int) ($_POST['project'] ?? ($_GET['project'] ?? 0));
$p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_projects WHERE id = $pid"));
if (!$p || $p['status'] !== 'Published') { header('Location: invest.php?error=' . urlencode('This opportunity is not open for applications.')); exit; }
if (!$a) { $d = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM inv_applications WHERE project_id = $pid AND customer_id = $cid AND status = 'Draft' LIMIT 1")); if ($d) { header('Location: invest_apply.php?id=' . (int) $d['id']); exit; } }
$f = inv_funding($conn, $pid);
$P = ['national_id' => 'National ID / accepted ID', 'date_of_birth' => 'Date of birth', 'email' => 'Email', 'address' => 'Residential address', 'district' => 'District', 'sector' => 'Sector', 'cell' => 'Cell', 'nationality' => 'Nationality'];
$ACK = ['Projected returns are not guaranteed.', 'Actual profits may be lower than projected.', 'The project may incur losses.', 'Capital recovery is subject to the investment agreement and the project outcome.',
        'My funds may be committed for the project duration.', 'I have reviewed the project\'s financial projections and risks.', 'I understand the management fee (' . (float) $p['mgmt_fee_pct'] . '% of realized profit) and the profit-sharing method.'];
$step = 'form';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    try {
        if (!portal_csrf_ok()) { throw new RuntimeException('Your session expired. Please try again.'); }
        $prof = []; foreach (array_keys($P) as $k) { $prof[$k] = mb_substr(trim((string) ($_POST['p'][$k] ?? '')), 0, 120); }
        if ($prof['email'] !== '' && !filter_var($prof['email'], FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('Enter a valid email address.'); }
        if ($prof['date_of_birth'] !== '' && !DateTime::createFromFormat('Y-m-d', $prof['date_of_birth'])) { $prof['date_of_birth'] = ''; }
        $sets = []; $vals = []; foreach ($prof as $k => $v) { $sets[] = "$k = ?"; $vals[] = $v === '' ? null : $v; } $vals[] = $cid;
        $s = mysqli_prepare($conn, 'UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = ?'); mysqli_stmt_bind_param($s, str_repeat('s', count($prof)) . 'i', ...$vals); mysqli_stmt_execute($s);
        $amount = (float) ($_POST['amount'] ?? 0);
        $d = ['amount' => $amount > 0 ? $amount : null, 'proposed_date' => DateTime::createFromFormat('Y-m-d', (string) ($_POST['proposed_date'] ?? '')) ? $_POST['proposed_date'] : null,
              'source_of_funds' => in_array($_POST['source_of_funds'] ?? '', INV_SOURCES, true) ? $_POST['source_of_funds'] : null,
              'payment_method' => in_array($_POST['payment_method'] ?? '', INV_PAY_METHODS, true) ? $_POST['payment_method'] : null,
              'comments' => mb_substr(trim((string) ($_POST['comments'] ?? '')), 0, 1000) ?: null,
              'personal' => json_encode(['name' => $me['name'], 'customer_id' => portal_customer_code($cid), 'phone' => $me['phone']] + $prof, JSON_UNESCAPED_UNICODE)];
        if (!$id) { mysqli_query($conn, "INSERT INTO inv_applications (project_id, customer_id, status) VALUES ($pid, $cid, 'Draft')"); $id = (int) mysqli_insert_id($conn); }
        $sets = []; $vals = []; $types = ''; foreach ($d as $k => $v) { $sets[] = "$k = ?"; $vals[] = $v; $types .= is_float($v) ? 'd' : 's'; } $vals[] = $id; $types .= 'i';
        $s = mysqli_prepare($conn, 'UPDATE inv_applications SET ' . implode(', ', $sets) . ", updated_at = NOW() WHERE id = ? AND status = 'Draft'"); mysqli_stmt_bind_param($s, $types, ...$vals); mysqli_stmt_execute($s);
        $a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_applications WHERE id = $id"));
        if ($action === 'save') { header('Location: invest_apply.php?id=' . $id . '&success=' . urlencode('Draft saved.')); exit; }
        $err = [];
        foreach (['national_id', 'date_of_birth', 'address', 'district', 'sector', 'cell', 'nationality'] as $k) { if ($prof[$k] === '') { $err[] = $P[$k] . ' is required.'; } }
        if (!$d['amount']) { $err[] = 'Enter the amount you want to invest.'; } elseif ($why = inv_check_application($conn, $p, $cid, $d['amount'], $id)) { $err[] = $why; }
        if (!$d['proposed_date'] || !$d['source_of_funds'] || !$d['payment_method']) { $err[] = 'Complete the contribution date, the source of funds and the payment method.'; }
        if ($err) { throw new RuntimeException(implode(' ', $err)); }
        $step = 'review';
        if ($action === 'submit') {
            if (count(array_filter((array) ($_POST['ack'] ?? []))) < count($ACK)) { throw new RuntimeException('Tick every risk acknowledgement to continue.'); }
            $name = trim((string) ($_POST['full_name'] ?? ''));
            if (empty($_POST['agree']) || mb_strtolower($name) !== mb_strtolower(trim($me['name']))) { throw new RuntimeException('Accept the investment agreement and type your full name exactly as shown (' . $me['name'] . ').'); }
            $s = mysqli_prepare($conn, "UPDATE inv_applications SET status = 'Submitted', submitted_at = NOW(), risk_ack_at = NOW(), agreement_accepted_at = NOW(), accepted_name = ?, updated_at = NOW() WHERE id = ? AND status = 'Draft'");
            mysqli_stmt_bind_param($s, 'si', $name, $id); mysqli_stmt_execute($s);
            if (mysqli_stmt_affected_rows($s) !== 1) { header('Location: invest_app.php?id=' . $id); exit; }
            inv_audit($conn, $pid, $id, 'Application submitted for ' . cl_money($d['amount']) . '; risks acknowledged; agreement accepted by ' . $name, null, null, $cid);
            portal_notify_customer($conn, $cid, 'Investment application submitted (' . inv_app_ref($a) . ')', $p['name'] . ': ' . cl_money($d['amount']), 'invest_app.php?id=' . $id);
            portal_notify_staff($conn, 'New investment application ' . inv_app_ref($a), $me['name'] . ' applied to invest ' . cl_money($d['amount']) . ' in ' . $p['name'] . '.');
            header('Location: invest_app.php?id=' . $id . '&success=' . urlencode('Application ' . inv_app_ref($a) . ' submitted on ' . date('d M Y \a\t H:i') . '.')); exit;
        }
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
    $me = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM customers WHERE id = $cid"));
}
$v = function ($k) use ($a) { return $a[$k] ?? ''; };
$maxNow = min($f['available'], (float) $p['max_invest'] > 0 ? (float) $p['max_invest'] : PHP_INT_MAX);
portal_header('Invest in ' . $p['name'], $me);
portal_alerts();
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= portal_e($error); ?></div><?php } ?>
<a href="invest_project.php?id=<?= $pid; ?>" class="small">&larr; <?= portal_e($p['name']); ?></a>
<h1 class="h5 mt-1">Invest in <?= portal_e($p['name']); ?> <span class="small text-muted"><?= inv_project_ref($p); ?></span></h1>
<?php if ($step === 'review') { ?>
<form method="POST" class="card"><div class="card-body"><?= portal_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="submit">
    <?php foreach ($_POST as $k => $x) { if (in_array($k, ['csrf', 'action', 'id', 'ack', 'agree', 'full_name'], true)) { continue; } if (is_array($x)) { foreach ($x as $kk => $vv) { echo '<input type="hidden" name="' . portal_e($k) . '[' . portal_e($kk) . ']" value="' . portal_e($vv) . '">'; } } else { echo '<input type="hidden" name="' . portal_e($k) . '" value="' . portal_e($x) . '">'; } } ?>
    <h2 class="h6">Confirm your investment</h2>
    <table class="table table-sm small"><tr><td>Amount</td><td class="fw-bold"><?= cl_money($a['amount']); ?></td></tr><tr><td>Your share if fully funded</td><td><?= round(100 * (float) $a['amount'] / max(1, (float) $p['capital_required']), 2); ?>% of the project</td></tr>
        <tr><td>Contribution date</td><td><?= date('d M Y', strtotime($a['proposed_date'])); ?> by <?= portal_e($a['payment_method']); ?></td></tr><tr><td>Source of funds</td><td><?= portal_e($a['source_of_funds']); ?></td></tr></table>
    <h3 class="h6">Risk acknowledgement</h3>
    <?php foreach ($ACK as $i => $t) { ?><label class="d-block small"><input type="checkbox" name="ack[<?= $i; ?>]" value="1" class="form-check-input me-1" required><?= portal_e($t); ?></label><?php } ?>
    <h3 class="h6 mt-3">Investment agreement</h3>
    <p class="small"><a href="invest_agreement.php?app=<?= $id; ?>" target="_blank">Read the investment agreement for <?= cl_money($a['amount']); ?></a> (amount, purpose, duration, management fee, profit formula, capital recovery, losses, exit, disputes, legal conditions).</p>
    <label class="d-block small mb-2"><input type="checkbox" name="agree" value="1" class="form-check-input me-1" required>I have read and accept the investment agreement.</label>
    <input name="full_name" class="form-control form-control-sm mb-3" required placeholder="Type your full name to sign: <?= portal_e($me['name']); ?>">
    <div class="d-flex gap-2"><button class="btn btn-primary" onclick="this.disabled=true; this.form.submit();">Submit application</button><a href="invest_apply.php?id=<?= $id; ?>" class="btn btn-outline-secondary">Back to edit</a></div>
</div></form>
<?php } else { ?>
<form method="POST"><?= portal_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="project" value="<?= $pid; ?>">
<div class="card mb-3"><div class="card-body"><h2 class="h6">1. Investor information</h2>
    <div class="small text-muted mb-2"><?= portal_e($me['name']); ?> · <?= portal_customer_code($cid); ?> · <?= portal_e($me['phone']); ?></div>
    <div class="row g-2"><?php foreach ($P as $k => $l) { ?><div class="col-md-3"><label class="form-label small text-muted mb-1"><?= $l; ?><?= $k !== 'email' ? ' *' : ''; ?></label><input type="<?= $k === 'date_of_birth' ? 'date' : ($k === 'email' ? 'email' : 'text'); ?>" name="p[<?= $k; ?>]" class="form-control form-control-sm" value="<?= portal_e($me[$k] ?? ''); ?>"></div><?php } ?></div>
</div></div>
<div class="card mb-3"><div class="card-body"><h2 class="h6">2. Investment details</h2>
    <div class="small mb-2">Minimum <?= cl_money($p['min_invest']); ?> · maximum <?= (float) $p['max_invest'] > 0 ? cl_money($p['max_invest']) : '—'; ?> · available now <strong><?= cl_money($f['available']); ?></strong> · deadline <?= $p['funding_deadline'] ? date('d M Y', strtotime($p['funding_deadline'])) : '—'; ?></div>
    <div class="row g-2">
        <div class="col-md-4"><label class="form-label small text-muted mb-1">Amount (RWF) *</label><input type="number" name="amount" min="<?= (int) $p['min_invest']; ?>" max="<?= (int) $maxNow; ?>" step="1000" class="form-control form-control-sm" value="<?= portal_e($v('amount') !== '' ? (int) $v('amount') : ''); ?>"></div>
        <div class="col-md-4"><label class="form-label small text-muted mb-1">Proposed contribution date *</label><input type="date" name="proposed_date" class="form-control form-control-sm" value="<?= portal_e($v('proposed_date')); ?>"></div>
        <div class="col-md-4"><label class="form-label small text-muted mb-1">Source of funds *</label><select name="source_of_funds" class="form-select form-select-sm"><option value=""></option><?php foreach (INV_SOURCES as $o) { ?><option <?= $v('source_of_funds') === $o ? 'selected' : ''; ?>><?= $o; ?></option><?php } ?></select></div>
        <div class="col-md-4"><label class="form-label small text-muted mb-1">Payment method *</label><select name="payment_method" class="form-select form-select-sm"><option value=""></option><?php foreach (INV_PAY_METHODS as $o) { ?><option <?= $v('payment_method') === $o ? 'selected' : ''; ?>><?= $o; ?></option><?php } ?></select></div>
        <div class="col-md-8"><label class="form-label small text-muted mb-1">Comments</label><input name="comments" class="form-control form-control-sm" maxlength="1000" value="<?= portal_e($v('comments')); ?>"></div>
    </div>
</div></div>
<div class="d-flex gap-2 mb-4"><button name="action" value="review" class="btn btn-primary">Continue to risks &amp; agreement</button><button name="action" value="save" class="btn btn-outline-primary" formnovalidate>Save draft</button><a href="invest_project.php?id=<?= $pid; ?>" class="btn btn-link">Cancel</a></div>
</form>
<?php } ?>
<?php portal_footer();