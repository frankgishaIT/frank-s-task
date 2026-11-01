<?php
/**
 * NEW FILE: modules/rm_invest/project.php — one investment project: creation form (A basic, B capital, C projections,
 * D distribution, E risks, F publication), files, investor updates, results, profit distribution and payouts.
 */
require __DIR__ . '/invest_common.php';
require_once __DIR__ . '/../../includes/evidence_store.php';
$id = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
$p = $id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_projects WHERE id = $id")) : null;
if ($id && !$p) { header('Location: index.php?error=' . urlencode('Project not found.')); exit; }
$canCreate = access_can('rm_invest.create', $conn); $canEdit = access_can('rm_invest.edit', $conn); $canApprove = access_can('rm_invest.approve', $conn);
$text = ['description', 'objectives', 'why_capital', 'use_of_funds', 'risk_business', 'risk_market', 'risk_delays', 'risk_losses', 'risk_other', 'capital_recovery', 'cancellation_conditions', 'distribution_conditions', 'agreement_terms'];
$money = ['capital_required', 'min_invest', 'max_invest', 'expected_revenue', 'expected_direct', 'expected_other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? ''); $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        switch ($act) {
            case 'save':
                if (!$canCreate) { throw new RuntimeException('You cannot create or change projects.'); }
                if ($p && !in_array($p['status'], ['Draft', 'Paused', 'Published'], true)) { throw new RuntimeException('Funding is closed: the project terms can no longer be changed.'); }
                $v = ['name' => mb_substr(trim((string) $_POST['name']), 0, 160), 'category' => in_array($_POST['category'] ?? '', INV_CATEGORIES, true) ? $_POST['category'] : null,
                      'location' => mb_substr(trim((string) ($_POST['location'] ?? '')), 0, 160) ?: null, 'risk_level' => in_array($_POST['risk_level'] ?? '', INV_RISK_LEVELS, true) ? $_POST['risk_level'] : 'Medium'];
                foreach ($text as $k) { $v[$k] = trim((string) ($_POST[$k] ?? '')) ?: null; }
                foreach ($money as $k) { $v[$k] = max(0, (float) ($_POST[$k] ?? 0)); }
                foreach (['max_investors', 'min_investors'] as $k) { $v[$k] = (int) ($_POST[$k] ?? 0) ?: null; }
                foreach (['funding_deadline', 'start_date', 'end_date'] as $k) { $v[$k] = DateTime::createFromFormat('Y-m-d', (string) ($_POST[$k] ?? '')) ? $_POST[$k] : null; }
                $v['mgmt_fee_pct'] = min(100, max(0, (float) ($_POST['mgmt_fee_pct'] ?? inv_setting($conn, 'mgmt_fee_pct'))));
                if ($v['name'] === '' || $v['capital_required'] <= 0) { throw new RuntimeException('Give the project a name and the total capital required.'); }
                if ($v['min_invest'] <= 0 || ($v['max_invest'] > 0 && $v['max_invest'] < $v['min_invest']) || $v['min_invest'] > $v['capital_required']) { throw new RuntimeException('Check the minimum and maximum investment per investor.'); }
                if ($v['start_date'] && $v['end_date'] && $v['end_date'] < $v['start_date']) { throw new RuntimeException('The end date is before the start date.'); }
                if ($p && (float) $v['capital_required'] < inv_funding($conn, $id)['committed']) { throw new RuntimeException('The capital required cannot be below what investors have already committed.'); }
                $cols = array_keys($v);
                if ($p) {
                    $sql = 'UPDATE inv_projects SET ' . implode(', ', array_map(function ($c) { return "$c = ?"; }, $cols)) . ', updated_at = NOW() WHERE id = ?'; $vals = array_values($v); $vals[] = $id;
                } else {
                    $sql = 'INSERT INTO inv_projects (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)'; $vals = array_values($v); $vals[] = $me;
                }
                $types = ''; foreach ($vals as $x) { $types .= is_int($x) ? 'i' : (is_float($x) ? 'd' : 's'); }
                $s = mysqli_prepare($conn, $sql); mysqli_stmt_bind_param($s, $types, ...$vals); mysqli_stmt_execute($s);
                $id = $id ?: (int) mysqli_insert_id($conn);
                inv_audit($conn, $id, null, $p ? 'Project details changed' : 'Project created (draft)', $reason ?: null, $me);
                $msg = $p ? 'Project saved.' : 'Project created as a draft. Add photos and documents, then Preview and Publish.'; break;
            case 'upload':
                if (!$canCreate || !$p) { throw new RuntimeException('Not allowed.'); }
                $kind = ($_POST['kind'] ?? '') === 'photo' ? 'photo' : (($_POST['kind'] ?? '') === 'agreement' ? 'agreement' : 'document');
                $types = $kind === 'photo' ? ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'] : CL_DOC_TYPES;
                $files = $_FILES['files'] ?? null; $n = 0;
                if ($files && is_array($files['name'])) { foreach ($files['name'] as $i => $nm) { if (($files['error'][$i] ?? 4) === UPLOAD_ERR_NO_FILE) { continue; }
                    $ref = evidence_save($conn, ['name' => $nm, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]], $me, $types, 10 * 1024 * 1024);
                    $bn = mb_substr(basename($nm), 0, 255); $s = mysqli_prepare($conn, 'INSERT INTO inv_project_files (project_id, kind, file_ref, original_name) VALUES (?, ?, ?, ?)');
                    mysqli_stmt_bind_param($s, 'isss', $id, $kind, $ref, $bn); mysqli_stmt_execute($s); $n++; } }
                if (!$n) { throw new RuntimeException('Choose at least one file.'); }
                inv_audit($conn, $id, null, "$n $kind file(s) added", null, $me); $msg = "$n file(s) added."; break;
            case 'remove_file':
                if (!$canCreate) { throw new RuntimeException('Not allowed.'); }
                mysqli_query($conn, 'DELETE FROM inv_project_files WHERE id = ' . (int) $_POST['file_id'] . " AND project_id = $id"); inv_audit($conn, $id, null, 'File removed', null, $me); $msg = 'File removed.'; break;
            case 'publication':
                $to = (string) ($_POST['to'] ?? '');
                $allowed = ['publish' => [['Draft', 'Paused'], 'Published'], 'pause' => [['Published'], 'Paused'], 'close' => [['Published', 'Paused'], 'Funding Closed'],
                            'activate' => [['Funding Closed', 'Published'], 'Active'], 'complete' => [['Active'], 'Completed'], 'cancel' => [['Draft', 'Published', 'Paused', 'Funding Closed', 'Active'], 'Cancelled'], 'extend' => [['Published', 'Paused', 'Funding Closed'], null]];
                if (!isset($allowed[$to]) || !$canApprove) { throw new RuntimeException('Only managers with approval rights can publish or change a project\'s stage.'); }
                [$from, $new] = $allowed[$to];
                if (!in_array($p['status'], $from, true)) { throw new RuntimeException('Not possible from "' . $p['status'] . '".'); }
                if ($to === 'publish' && (!$p['description'] || !$p['funding_deadline'] || !$p['risk_losses'] || !$p['capital_recovery'] || !$p['agreement_terms'])) { throw new RuntimeException('Before publishing, fill in the description, funding deadline, potential losses, capital recovery conditions and the investment agreement terms.'); }
                if ($to === 'publish' && $p['funding_deadline'] < date('Y-m-d')) { throw new RuntimeException('The funding deadline has passed: extend it first.'); }
                if (in_array($to, ['cancel', 'pause', 'close'], true) && mb_strlen($reason) < 5) { throw new RuntimeException('Write the reason.'); }
                if ($to === 'extend') {
                    $nd = (string) ($_POST['new_deadline'] ?? '');
                    if (!DateTime::createFromFormat('Y-m-d', $nd) || $nd <= (string) $p['funding_deadline'] || mb_strlen($reason) < 5) { throw new RuntimeException('Choose a later deadline and write the reason.'); }
                    mysqli_query($conn, "UPDATE inv_projects SET funding_deadline = '" . mysqli_real_escape_string($conn, $nd) . "', status = IF(status = 'Funding Closed', 'Published', status) WHERE id = $id");
                    inv_audit($conn, $id, null, 'Funding deadline extended to ' . $nd, $reason, $me); $msg = 'Deadline extended.'; break;
                }
                if ($to === 'activate' && $p['min_investors'] && inv_funding($conn, $id)['investors'] < (int) $p['min_investors']) { throw new RuntimeException('Fewer confirmed investors than the minimum (' . (int) $p['min_investors'] . ').'); }
                mysqli_query($conn, "UPDATE inv_projects SET status = '$new', updated_at = NOW()" . ($to === 'publish' && !$p['published_at'] ? ', published_at = NOW()' : '') . " WHERE id = $id");
                inv_audit($conn, $id, null, 'Project ' . $p['status'] . ' → ' . $new, $reason ?: null, $me);
                $ref = inv_project_ref($p);
                if ($to === 'publish' && !$p['published_at']) { // new opportunity: tell customers with portal accounts
                    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT customer_id FROM portal_accounts WHERE status = 'active' AND password_hash IS NOT NULL"), MYSQLI_ASSOC) as $r) {
                        portal_notify_customer($conn, (int) $r['customer_id'], 'New investment opportunity: ' . $p['name'], 'See RM Invest in your customer portal.', 'invest_project.php?id=' . $id, false);
                    }
                }
                $notice = ['close' => 'Project funding completed', 'activate' => 'Project started', 'complete' => 'Project completed', 'cancel' => 'Project cancelled'][$to] ?? null;
                if ($notice) { foreach (inv_project_investors($conn, $id) as $cid) { portal_notify_customer($conn, $cid, $notice . ': ' . $p['name'], $to === 'cancel' ? $reason : '', 'my_investments.php'); } }
                if ($to === 'activate') { mysqli_query($conn, "UPDATE inv_applications SET status = 'Active Investor', updated_at = NOW() WHERE project_id = $id AND status = 'Confirmed'"); }
                if ($to === 'complete') { mysqli_query($conn, "UPDATE inv_applications SET status = 'Completed', updated_at = NOW() WHERE project_id = $id AND status = 'Active Investor'"); }
                $msg = 'Project is now ' . $new . '.'; break;
            case 'update':
                if (!$canEdit) { throw new RuntimeException('Not allowed.'); }
                $t = mb_substr(trim((string) $_POST['title']), 0, 160); $b = trim((string) $_POST['body']);
                if ($t === '' || $b === '') { throw new RuntimeException('Write a title and the update.'); }
                $s = mysqli_prepare($conn, 'INSERT INTO inv_updates (project_id, title, body, created_by) VALUES (?, ?, ?, ?)'); mysqli_stmt_bind_param($s, 'issi', $id, $t, $b, $me); mysqli_stmt_execute($s);
                foreach (inv_project_investors($conn, $id) as $cid) { portal_notify_customer($conn, $cid, 'Project update: ' . $p['name'], $t, 'my_investments.php?project=' . $id); }
                inv_audit($conn, $id, null, 'Progress update published: ' . $t, null, $me); $msg = 'Update published and investors notified.'; break;
            case 'results':
                if (!$canEdit) { throw new RuntimeException('Not allowed.'); }
                if (!in_array($p['status'], ['Active', 'Completed'], true)) { throw new RuntimeException('Results are entered once the project is active or completed.'); }
                if ($p['results_status'] === 'Approved') { throw new RuntimeException('The results are approved: they can no longer be changed.'); }
                $r = max(0, (float) $_POST['actual_revenue']); $dct = max(0, (float) $_POST['actual_direct']); $o = max(0, (float) $_POST['actual_other']); $note = trim((string) ($_POST['results_note'] ?? ''));
                $s = mysqli_prepare($conn, "UPDATE inv_projects SET actual_revenue = ?, actual_direct = ?, actual_other = ?, results_note = ?, results_status = 'Submitted', results_by = ? WHERE id = ?");
                mysqli_stmt_bind_param($s, 'dddsii', $r, $dct, $o, $note, $me, $id); mysqli_stmt_execute($s);
                inv_audit($conn, $id, null, 'Actual results entered: revenue ' . cl_money($r) . ', direct costs ' . cl_money($dct) . ', other ' . cl_money($o), $note ?: null, $me);
                $msg = 'Results saved. They must be approved before any payout.'; break;
            case 'approve_results':
                if (!$canApprove || $p['results_status'] !== 'Submitted') { throw new RuntimeException('Only managers with approval rights can approve submitted results.'); }
                if ((int) $p['results_by'] === $me) { throw new RuntimeException('Results must be approved by someone other than the person who entered them.'); }
                if (mb_strlen($reason) < 5) { throw new RuntimeException('Write a short approval note (e.g. figures checked against the project accounts).'); }
                $calc = inv_calculate($conn, $p);
                if (!$calc['ok']) { throw new RuntimeException($calc['reason']); }
                mysqli_begin_transaction($conn);
                mysqli_query($conn, "DELETE FROM inv_distributions WHERE project_id = $id AND status <> 'Paid'");
                $s = mysqli_prepare($conn, "INSERT IGNORE INTO inv_distributions (project_id, application_id, customer_id, contribution, share_pct, profit_share, capital_returned, total_payout, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Approved')");
                foreach ($calc['rows'] as $x) { mysqli_stmt_bind_param($s, 'iiiddddd', $id, $x['application_id'], $x['customer_id'], $x['contribution'], $x['share_pct'], $x['profit'], $x['capital'], $x['total']); mysqli_stmt_execute($s); }
                mysqli_query($conn, "UPDATE inv_projects SET results_status = 'Approved', results_approved_by = $me, results_approved_at = NOW() WHERE id = $id");
                inv_audit($conn, $id, null, 'Results approved: realized ' . cl_money($calc['profit']) . ', management fee ' . cl_money($calc['fee']) . ', distributable ' . cl_money($calc['distributable']), $reason, $me);
                mysqli_commit($conn);
                foreach ($calc['rows'] as $x) { portal_notify_customer($conn, $x['customer_id'], 'Profit / loss report published: ' . $p['name'], 'Your payout: ' . cl_money($x['total']) . ' (capital ' . cl_money($x['capital']) . ' + profit ' . cl_money($x['profit']) . ').', 'my_investments.php?project=' . $id); }
                $msg = 'Results approved and the distribution calculated.'; break;
            case 'payout':
                if (!$canApprove) { throw new RuntimeException('Not allowed.'); }
                if (inv_testing($conn)) { throw new RuntimeException('Testing mode is ON: payouts are blocked.'); }
                $did = (int) $_POST['distribution_id']; $pref = mb_substr(trim((string) $_POST['paid_ref']), 0, 80);
                if ($pref === '') { throw new RuntimeException('Enter the payment reference.'); }
                $s = mysqli_prepare($conn, "UPDATE inv_distributions SET status = 'Paid', paid_at = NOW(), paid_ref = ?, paid_by = ? WHERE id = ? AND project_id = ? AND status = 'Approved'");
                mysqli_stmt_bind_param($s, 'siii', $pref, $me, $did, $id); mysqli_stmt_execute($s);
                if (mysqli_stmt_affected_rows($s) !== 1) { throw new RuntimeException('That payout was already processed.'); }
                $d = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_distributions WHERE id = $did"));
                inv_audit($conn, $id, (int) $d['application_id'], 'Payout processed: ' . cl_money($d['total_payout']) . ' ref ' . $pref, null, $me);
                portal_notify_customer($conn, (int) $d['customer_id'], 'Payout processed: ' . cl_money($d['total_payout']), $p['name'], 'my_investments.php?project=' . $id);
                $msg = 'Payout recorded.'; break;
            default: throw new RuntimeException('Unknown action.');
        }
        header('Location: project.php?id=' . $id . '&success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { @mysqli_rollback($conn); $error = $ex->getMessage(); }
}
$p = $id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_projects WHERE id = $id")) : null;
$val = function ($k, $d = '') use ($p) { return $_POST[$k] ?? ($p[$k] ?? $d); };
$fund = $p ? inv_funding($conn, $id) : null; $proj = $p ? inv_projection($p) : null;
$files = $p ? mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM inv_project_files WHERE project_id = $id ORDER BY kind, id"), MYSQLI_ASSOC) : [];
$updates = $p ? mysqli_fetch_all(mysqli_query($conn, "SELECT u.*, us.names FROM inv_updates u LEFT JOIN users us ON us.id = u.created_by WHERE project_id = $id ORDER BY id DESC"), MYSQLI_ASSOC) : [];
$apps = $p ? mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, c.name, k.status AS pay_status FROM inv_applications a JOIN customers c ON c.id = a.customer_id LEFT JOIN inv_contributions k ON k.application_id = a.id WHERE a.project_id = $id AND a.status <> 'Draft' ORDER BY a.id DESC"), MYSQLI_ASSOC) : [];
$dist = $p ? mysqli_fetch_all(mysqli_query($conn, "SELECT d.*, c.name FROM inv_distributions d JOIN customers c ON c.id = d.customer_id WHERE d.project_id = $id ORDER BY d.contribution DESC"), MYSQLI_ASSOC) : [];
$calc = $p && $p['actual_revenue'] !== null ? inv_calculate($conn, $p) : null;
$editable = !$p || in_array($p['status'], ['Draft', 'Paused', 'Published'], true);
$ta = function ($k, $label, $rows = 2) use ($val, $e, $editable) { echo '<div class="col-12"><label class="form-label small text-muted mb-1">' . $label . '</label><textarea name="' . $k . '" rows="' . $rows . '" class="form-control form-control-sm"' . ($editable ? '' : ' readonly') . '>' . $e($val($k)) . '</textarea></div>'; };
$in = function ($k, $label, $type = 'text', $col = 'col-md-4') use ($val, $e, $editable) { echo '<div class="' . $col . '"><label class="form-label small text-muted mb-1">' . $label . '</label><input type="' . $type . '"' . ($type === 'number' ? ' min="0" step="1"' : '') . ' name="' . $k . '" class="form-control form-control-sm" value="' . $e($val($k)) . '"' . ($editable ? '' : ' readonly') . '></div>'; };
include '../../includes/header.php'; include '../../includes/sidebar.php';
inv_tabs('index.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<a href="index.php" class="small text-muted text-decoration-none">&larr; Projects</a>
<h4 class="mt-1"><?= $p ? $e($p['name']) . ' <span class="small text-muted">' . inv_project_ref($p) . '</span> ' . inv_badge($p['status']) : 'New investment opportunity'; ?></h4>
<?php if ($p) { ?>
<div class="row g-3 mb-3">
    <?php foreach ([['Capital required', cl_money($fund['required'])], ['Raised (verified)', cl_money($fund['raised']) . ' · ' . $fund['pct'] . '%'], ['Committed (approved)', cl_money($fund['committed'])], ['Investors', $fund['investors'] . ($p['max_investors'] ? ' / ' . (int) $p['max_investors'] : '')], ['Projected return (estimate)', $proj['return_pct'] !== null ? $proj['return_pct'] . '%' : '—'], ['Deadline', $p['funding_deadline'] ? date('d M Y', strtotime($p['funding_deadline'])) : '—']] as [$l, $v]) { ?>
    <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="small text-muted"><?= $l; ?></div><div class="fw-bold"><?= $v; ?></div></div></div></div><?php } ?>
</div>
<?php if ($canApprove) { ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body small d-flex flex-wrap gap-2 align-items-end"><strong class="me-2">Publication:</strong>
    <a href="preview.php?id=<?= $id; ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Preview</a>
    <?php foreach (['publish' => ['Publish', 'btn-success', ['Draft', 'Paused']], 'pause' => ['Pause applications', 'btn-outline-secondary', ['Published']], 'close' => ['Close funding', 'btn-outline-primary', ['Published', 'Paused']],
                    'activate' => ['Mark as Active', 'btn-primary', ['Funding Closed', 'Published']], 'complete' => ['Mark as Completed', 'btn-primary', ['Active']], 'cancel' => ['Cancel opportunity', 'btn-outline-danger', ['Draft', 'Published', 'Paused', 'Funding Closed', 'Active']]] as $to => [$label, $cls, $from]) {
        if (!in_array($p['status'], $from, true)) { continue; } ?>
        <form method="POST" class="d-inline-flex gap-1" onsubmit="<?= in_array($to, ['cancel', 'pause', 'close'], true) ? "this.reason.value = prompt('Reason:') || ''; return this.reason.value.length > 4;" : "return confirm('" . $e($label) . "?');"; ?>"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="publication"><input type="hidden" name="to" value="<?= $to; ?>"><input type="hidden" name="reason"><button class="btn btn-sm <?= $cls; ?>"><?= $label; ?></button></form>
    <?php } ?>
    <?php if (in_array($p['status'], ['Published', 'Paused', 'Funding Closed'], true)) { ?>
        <form method="POST" class="d-inline-flex gap-1"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="publication"><input type="hidden" name="to" value="extend"><input type="date" name="new_deadline" class="form-control form-control-sm" style="width:150px;"><input name="reason" class="form-control form-control-sm" style="width:170px;" placeholder="Reason"><button class="btn btn-sm btn-outline-primary">Extend deadline</button></form>
    <?php } ?>
</div></div>
<?php } ?>
<?php } ?>

<details class="card border-0 shadow-sm mb-3" <?= !$p ? 'open' : ''; ?>><summary class="card-body py-2 fw-semibold">Project details (A – F)<?= !$editable ? ' · read-only (funding closed)' : ''; ?></summary>
<form method="POST" class="card-body pt-0 row g-2"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="save">
    <div class="col-12 fw-semibold small mt-2">A. Basic information</div>
    <?php $in('name', 'Project name *', 'text', 'col-md-6'); ?>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Category</label><select name="category" class="form-select form-select-sm"><option value=""></option><?php foreach (INV_CATEGORIES as $o) { ?><option <?= $val('category') === $o ? 'selected' : ''; ?>><?= $o; ?></option><?php } ?></select></div>
    <?php $in('location', 'Business location', 'text', 'col-md-3'); $ta('description', 'Project description *', 3); $ta('objectives', 'Project objectives'); $ta('why_capital', 'Why the capital is required'); $ta('use_of_funds', 'How the funds will be used'); ?>
    <div class="col-12 fw-semibold small mt-2">B. Capital requirements</div>
    <?php $in('capital_required', 'Total capital required (RWF) *', 'number', 'col-md-3'); $in('min_invest', 'Minimum per investor *', 'number', 'col-md-3'); $in('max_invest', 'Maximum per investor', 'number', 'col-md-3'); $in('max_investors', 'Maximum number of investors', 'number', 'col-md-3');
          $in('min_investors', 'Minimum number of investors', 'number', 'col-md-3'); $in('funding_deadline', 'Funding deadline *', 'date', 'col-md-3'); $in('start_date', 'Investment start', 'date', 'col-md-3'); $in('end_date', 'Investment end', 'date', 'col-md-3'); ?>
    <div class="col-12 fw-semibold small mt-2">C. Financial projections (estimates)</div>
    <?php $in('expected_revenue', 'Expected sales / revenue', 'number', 'col-md-4'); $in('expected_direct', 'Expected direct operating costs (incl. stock bought with the capital)', 'number', 'col-md-4'); $in('expected_other', 'Other projected expenses', 'number', 'col-md-4'); ?>
    <?php if ($proj) { ?><div class="col-12 small text-muted">Gross profit <?= cl_money($proj['gross']); ?> · net project profit <?= cl_money($proj['net']); ?> · management cost <?= cl_money($proj['fee']); ?> · distributable <?= cl_money($proj['distributable']); ?> · projected investor return <?= $proj['return_pct'] !== null ? $proj['return_pct'] . '%' : '—'; ?> (estimate)</div><?php } ?>
    <div class="col-12 fw-semibold small mt-2">D. Distribution</div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Administrative &amp; management cost, % of realized net profit</label><input type="number" step="0.1" min="0" max="100" name="mgmt_fee_pct" class="form-control form-control-sm" value="<?= $e($val('mgmt_fee_pct', inv_setting($conn, 'mgmt_fee_pct'))); ?>" <?= $editable ? '' : 'readonly'; ?>></div>
    <div class="col-md-8 small text-muted align-self-end">Investors receive the rest (<?= 100 - (float) $val('mgmt_fee_pct', inv_setting($conn, 'mgmt_fee_pct')); ?>%), in proportion to their verified contributions. The fee applies to realized profit only, never to the capital.</div>
    <div class="col-12 fw-semibold small mt-2">E. Risks</div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Risk classification</label><select name="risk_level" class="form-select form-select-sm"><?php foreach (INV_RISK_LEVELS as $o) { ?><option <?= $val('risk_level', 'Medium') === $o ? 'selected' : ''; ?>><?= $o; ?></option><?php } ?></select></div>
    <?php $ta('risk_business', 'Business risks'); $ta('risk_market', 'Market risks'); $ta('risk_delays', 'Possible delays'); $ta('risk_losses', 'Potential losses *'); $ta('risk_other', 'Other risks');
          $ta('capital_recovery', 'Capital recovery conditions *'); $ta('cancellation_conditions', 'Project cancellation conditions'); $ta('distribution_conditions', 'Profit distribution conditions'); ?>
    <div class="col-12 fw-semibold small mt-2">Investment agreement (project-specific)</div>
    <?php $ta('agreement_terms', 'Agreement terms * (purpose, duration, capital recovery, loss sharing, exit and withdrawal, dispute resolution, legal conditions)', 5); ?>
    <?php if ($editable && $canCreate) { ?><div class="col-md-8"><input name="reason" class="form-control form-control-sm" placeholder="Note for the audit log (optional)"></div><div class="col-md-4"><button class="btn btn-primary btn-sm w-100"><?= $p ? 'Save changes' : 'Save as draft'; ?></button></div><?php } ?>
</form></details>

<?php if ($p) { ?>
<div class="row g-3">
<div class="col-lg-6">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><h6>Photos, documents and agreement</h6>
        <?php foreach ($files as $f) { ?><div class="d-flex justify-content-between border-bottom py-1"><span><span class="badge bg-light text-dark border"><?= $e($f['kind']); ?></span> <a href="file.php?id=<?= (int) $f['id']; ?>" target="_blank"><?= $e($f['original_name']); ?></a></span>
            <?php if ($canCreate && $editable) { ?><form method="POST" onsubmit="return confirm('Remove?');"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="remove_file"><input type="hidden" name="file_id" value="<?= (int) $f['id']; ?>"><button class="btn btn-sm btn-link text-danger p-0">Remove</button></form><?php } ?></div><?php } ?>
        <?php if ($canCreate) { ?><form method="POST" enctype="multipart/form-data" class="d-flex gap-1 mt-2"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="upload">
            <select name="kind" class="form-select form-select-sm" style="width:130px;"><option value="photo">Photo</option><option value="document">Document</option><option value="agreement">Agreement</option></select>
            <input type="file" name="files[]" multiple class="form-control form-control-sm"><button class="btn btn-sm btn-primary">Add</button></form><?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><h6>Progress updates to investors</h6>
        <?php if ($canEdit) { ?><form method="POST" class="mb-2"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="update"><input name="title" class="form-control form-control-sm mb-1" maxlength="160" placeholder="Title"><textarea name="body" class="form-control form-control-sm mb-1" rows="2" placeholder="What happened (sales so far, delays, milestones…)"></textarea><button class="btn btn-sm btn-primary">Publish update</button></form><?php } ?>
        <?php foreach ($updates as $u) { ?><div class="border-top py-1"><strong><?= $e($u['title']); ?></strong> <span class="text-muted"><?= date('d M Y', strtotime($u['created_at'])); ?> · <?= $e($u['names']); ?></span><div style="white-space:pre-wrap;"><?= $e($u['body']); ?></div></div><?php } ?>
    </div></div>
</div>
<div class="col-lg-6">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><h6>Applications (<?= count($apps); ?>)</h6>
        <?php if (!$apps) { ?><div class="text-muted">None yet.</div><?php } foreach ($apps as $a) { ?><div class="d-flex justify-content-between border-bottom py-1"><a href="application.php?id=<?= (int) $a['id']; ?>"><?= inv_app_ref($a); ?> · <?= $e($a['name']); ?></a><span><?= cl_money($a['amount']); ?> <?= inv_badge($a['status']); ?></span></div><?php } ?>
    </div></div>
    <?php if (in_array($p['status'], ['Active', 'Completed'], true)) { ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><h6>Actual results &amp; profit distribution</h6>
        <?php if ($p['results_status'] !== 'Approved' && $canEdit) { ?>
        <form method="POST" class="row g-1 mb-2"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="results">
            <div class="col-4"><label class="text-muted">Actual revenue</label><input type="number" min="0" name="actual_revenue" class="form-control form-control-sm" value="<?= $e($p['actual_revenue']); ?>"></div>
            <div class="col-4"><label class="text-muted">Direct costs (incl. capital used)</label><input type="number" min="0" name="actual_direct" class="form-control form-control-sm" value="<?= $e($p['actual_direct']); ?>"></div>
            <div class="col-4"><label class="text-muted">Other expenses</label><input type="number" min="0" name="actual_other" class="form-control form-control-sm" value="<?= $e($p['actual_other']); ?>"></div>
            <div class="col-9"><input name="results_note" class="form-control form-control-sm" placeholder="Source of the figures (project accounts, invoices…)" value="<?= $e($p['results_note']); ?>"></div><div class="col-3"><button class="btn btn-sm btn-primary w-100">Save results</button></div></form>
        <?php } ?>
        <?php if ($calc && $calc['ok']) { ?>
            <table class="table table-sm mb-2"><tr><td>Verified capital</td><td class="text-end"><?= cl_money($calc['capital']); ?></td></tr><tr><td>Actual revenue</td><td class="text-end"><?= cl_money($calc['revenue']); ?></td></tr>
                <tr><td>− Direct costs</td><td class="text-end"><?= cl_money($calc['direct']); ?></td></tr><tr><td>− Other expenses</td><td class="text-end"><?= cl_money($calc['other']); ?></td></tr>
                <tr class="fw-bold"><td>Realized project <?= $calc['profit'] >= 0 ? 'profit' : 'LOSS'; ?></td><td class="text-end <?= $calc['profit'] < 0 ? 'text-danger' : ''; ?>"><?= cl_money($calc['profit']); ?></td></tr>
                <tr><td>Management cost (<?= (float) $p['mgmt_fee_pct']; ?>% of the profit)</td><td class="text-end"><?= cl_money($calc['fee']); ?></td></tr><tr class="fw-bold"><td>Distributable to investors</td><td class="text-end"><?= cl_money($calc['distributable']); ?></td></tr>
                <tr><td>Capital returned</td><td class="text-end"><?= cl_money($calc['capital_returned']); ?></td></tr></table>
            <div class="mb-2">Status of the results: <strong><?= $e($p['results_status']); ?></strong><?= $p['results_approved_at'] ? ' on ' . date('d M Y', strtotime($p['results_approved_at'])) : ''; ?></div>
            <?php if ($p['results_status'] === 'Submitted' && $canApprove) { ?><form method="POST" class="d-flex gap-1 mb-2"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="approve_results"><input name="reason" class="form-control form-control-sm" placeholder="Approval note (figures checked …)"><button class="btn btn-sm btn-success" onclick="return confirm('Approve these results and calculate the payouts?');">Approve results</button></form><?php } ?>
        <?php } elseif ($calc) { ?><div class="text-muted"><?= $e($calc['reason']); ?></div><?php } ?>
        <?php if ($dist) { ?><table class="table table-sm mb-0"><tr><th>Investor</th><th class="text-end">Contribution</th><th class="text-end">Share</th><th class="text-end">Profit</th><th class="text-end">Capital</th><th class="text-end">Payout</th><th></th></tr>
            <?php foreach ($dist as $d) { ?><tr><td><?= $e($d['name']); ?></td><td class="text-end"><?= number_format((float) $d['contribution']); ?></td><td class="text-end"><?= round((float) $d['share_pct'], 2); ?>%</td><td class="text-end"><?= number_format((float) $d['profit_share']); ?></td><td class="text-end"><?= number_format((float) $d['capital_returned']); ?></td><td class="text-end fw-semibold"><?= number_format((float) $d['total_payout']); ?></td>
                <td><?php if ($d['status'] === 'Paid') { ?><span class="badge bg-success">Paid</span><?php } elseif ($canApprove && !inv_testing($conn)) { ?><form method="POST" class="d-flex gap-1"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="payout"><input type="hidden" name="distribution_id" value="<?= (int) $d['id']; ?>"><input name="paid_ref" class="form-control form-control-sm" style="width:90px;" placeholder="Ref"><button class="btn btn-sm btn-success">Paid</button></form><?php } else { ?><span class="badge bg-secondary"><?= inv_testing($conn) ? 'testing mode' : 'Approved'; ?></span><?php } ?></td></tr><?php } ?>
            </table><?php } ?>
    </div></div>
    <?php } ?>
</div></div>
<?php } ?>
<?php include '../../includes/footer.php'; ?>