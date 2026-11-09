<?php
/**
 * NEW FILE: modules/marketing/lead.php — one lead: follow-ups (communication history), status (Lost needs a reason;
 * Won only through conversion), reassignment, conversion to customer (no duplicate customers), acquisition and first
 * verified payment, and team contributions (percentages / points with reasons, approved by an authorised person).
 */
require __DIR__ . '/mkt_common.php';
$L = mkt_lists($conn);
$id = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
$l = mysqli_fetch_assoc(mysqli_query($conn, "SELECT l.*, u.names AS owner, c.name AS campaign, c.created_at AS campaign_created, p.product_name, cr.names AS creator FROM mkt_leads l
    LEFT JOIN users u ON u.id = l.owner_id LEFT JOIN mkt_campaigns c ON c.id = l.campaign_id LEFT JOIN products p ON p.id = l.product_id LEFT JOIN users cr ON cr.id = l.created_by
    WHERE l.id = $id AND " . mkt_lead_scope_sql($conn, $me)));
if (!$l) { header('Location: leads.php?error=' . urlencode('Lead not found, or not yours.')); exit; }
$ref = mkt_code('lead', $l);
$canLeads = access_can('marketing.leads', $conn); $canManage = mkt_is_manager(); $canApprove = access_can('marketing.approve', $conn); // reassign / contributions: Managers and Admins only
$isOwner = (int) $l['owner_id'] === $me;
$mayWork = $canLeads && ($isOwner || $canManage || (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mkt_lead_contributors WHERE lead_id = $id AND user_id = $me"))[0] > 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? ''); $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        switch ($act) {
            case 'followup':
                if (!$mayWork || !mkt_lead_open($l)) { throw new RuntimeException('You cannot record follow-ups on this lead.'); }
                $method = in_array($_POST['method'] ?? '', MKT_FOLLOWUP_METHODS, true) ? $_POST['method'] : null; $note = trim((string) ($_POST['note'] ?? ''));
                $to = in_array($_POST['status_after'] ?? '', MKT_LEAD_FLOW, true) ? $_POST['status_after'] : $l['status'];
                $next = DateTime::createFromFormat('Y-m-d', (string) ($_POST['next_date'] ?? '')) ? $_POST['next_date'] : null;
                if (!$method || mb_strlen($note) < 3) { throw new RuntimeException('Choose how you contacted the lead and write what happened.'); }
                if ($to === 'Won') { throw new RuntimeException('A lead becomes Won only by converting it to a customer (button "Convert to customer").'); }
                if ($to === 'Lost' && mb_strlen($reason) < 5) { throw new RuntimeException('A lost lead needs the reason it was lost.'); }
                if ($to !== 'Lost' && !$next) { throw new RuntimeException('Set the next follow-up date (or mark the lead Lost with the reason).'); }
                if ($next && $next < date('Y-m-d')) { throw new RuntimeException('The next follow-up date cannot be in the past.'); }
                mysqli_begin_transaction($conn);
                $s = mysqli_prepare($conn, 'INSERT INTO mkt_followups (lead_id, user_id, method, note, status_after, next_date) VALUES (?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($s, 'iissss', $id, $me, $method, $note, $to, $next); mysqli_stmt_execute($s);
                $lost = $to === 'Lost' ? $reason : null; $nf = $to === 'Lost' ? null : $next;
                $s = mysqli_prepare($conn, 'UPDATE mkt_leads SET status = ?, next_followup = ?, lost_reason = ?, updated_at = NOW() WHERE id = ?'); mysqli_stmt_bind_param($s, 'sssi', $to, $nf, $lost, $id); mysqli_stmt_execute($s);
                if ($to !== $l['status']) { mkt_audit($conn, 'Lead status changed (follow-up)', $ref, $l['status'], $to, $lost, $me); }
                mysqli_commit($conn); $msg = 'Follow-up recorded.'; break;
            case 'reassign':
                if (!$canManage) { throw new RuntimeException('Only managers reassign leads.'); }
                $new = (int) ($_POST['owner_id'] ?? 0);
                if (!in_array($new, array_map('intval', array_column($L['users'], 'id')), true) || $new === (int) $l['owner_id']) { throw new RuntimeException('Choose a different team member.'); }
                if (mb_strlen($reason) < 5) { throw new RuntimeException('Write the reason for the reassignment.'); }
                mysqli_query($conn, "UPDATE mkt_leads SET owner_id = $new, updated_at = NOW() WHERE id = $id");
                mysqli_query($conn, "UPDATE mkt_acquisitions SET owner_id = $new WHERE lead_id = $id AND first_paid_at IS NULL"); // not after a verified payment
                mkt_audit($conn, 'Lead reassigned', $ref, $l['owner'], mysqli_fetch_row(mysqli_query($conn, "SELECT names FROM users WHERE id = $new"))[0], $reason, $me);
                mkt_notify($conn, [$new], 'Lead reassigned to you: ' . $l['name'], $ref . ': ' . $reason); mkt_notify($conn, [(int) $l['owner_id']], 'Lead reassigned: ' . $l['name'], $ref . ' now belongs to another team member.');
                $msg = 'Lead reassigned.'; break;
            case 'reopen':
                if (!$canManage || $l['status'] !== 'Lost') { throw new RuntimeException('Only managers can reopen a lost lead.'); }
                if (mb_strlen($reason) < 5) { throw new RuntimeException('Write why the lead is reopened.'); }
                if ($dup = mkt_duplicate_lead($conn, $l['phone'], $l['email'], $id)) { throw new RuntimeException('This contact already has an open lead: ' . mkt_code('lead', $dup) . '.'); }
                mysqli_query($conn, "UPDATE mkt_leads SET status = 'Contacted', lost_reason = NULL, next_followup = CURDATE(), updated_at = NOW() WHERE id = $id");
                mkt_audit($conn, 'Lead reopened', $ref, 'Lost', 'Contacted', $reason, $me); $msg = 'Lead reopened.'; break;
            case 'convert':
                if (!$mayWork || !mkt_lead_open($l)) { throw new RuntimeException('You cannot convert this lead.'); }
                $r = mkt_convert_lead($conn, $id, $me);
                $msg = ($r['created'] ? 'New customer created: ' : 'Linked to the existing customer: ') . $r['name'] . '. ' . ($r['new'] ? 'It becomes a paying customer when its first sale is fully paid and approved in Transactions.' : 'Note: this customer had paid purchases before, so it is not counted as a new acquisition.'); break;
            case 'contrib_add':
                if (!$canManage) { throw new RuntimeException('Only managers record contributions (team members cannot set their own share).'); }
                $uid = (int) ($_POST['user_id'] ?? 0); $role = in_array($_POST['role'] ?? '', MKT_CONTRIB_ROLES, true) ? $_POST['role'] : '';
                $pct = ($_POST['share_pct'] ?? '') === '' ? null : round((float) $_POST['share_pct'], 2); $pts = ($_POST['points'] ?? '') === '' ? null : max(0, (int) $_POST['points']);
                if (!in_array($uid, array_map('intval', array_column($L['users'], 'id')), true) || $role === '') { throw new RuntimeException('Choose the team member and the contribution.'); }
                if ($pct === null && $pts === null) { throw new RuntimeException('Enter a percentage or points.'); }
                if ($pct !== null && ($pct <= 0 || $pct > 100)) { throw new RuntimeException('The percentage must be between 0 and 100.'); }
                if ($pct !== null && mkt_contrib_total($conn, $id) + $pct > 100.001) { throw new RuntimeException('Contributions would exceed 100% (already ' . mkt_contrib_total($conn, $id) . '%).'); }
                if (mb_strlen($reason) < 5) { throw new RuntimeException('Write the reason for this contribution.'); }
                $s = mysqli_prepare($conn, 'INSERT INTO mkt_lead_contributors (lead_id, user_id, role, share_pct, points, reason, added_by) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE share_pct = VALUES(share_pct), points = VALUES(points), reason = VALUES(reason), status = \'Proposed\', approved_by = NULL, approved_at = NULL');
                mysqli_stmt_bind_param($s, 'iisdisi', $id, $uid, $role, $pct, $pts, $reason, $me); mysqli_stmt_execute($s);
                mkt_audit($conn, 'Contribution proposed', $ref, null, ['user' => $uid, 'role' => $role, 'share' => $pct, 'points' => $pts], $reason, $me); $msg = 'Contribution proposed; it needs approval.'; break;
            case 'contrib_decide':
                $cid = (int) ($_POST['contrib_id'] ?? 0); $k = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_lead_contributors WHERE id = $cid AND lead_id = $id"));
                if (!$k || $k['status'] !== 'Proposed') { throw new RuntimeException('This contribution is not waiting for approval.'); }
                if (!$canApprove) { throw new RuntimeException('Only authorised approvers approve contributions.'); }
                $involved = (int) $l['owner_id'] === $me || (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mkt_lead_contributors WHERE lead_id = $id AND user_id = $me"))[0] > 0;
                if ($involved && !$isAdmin) { throw new RuntimeException('You are part of this lead: another approver must decide.'); }
                if ($involved && mb_strlen($reason) < 5) { throw new RuntimeException('You are part of this lead: write a short note (kept in the audit trail).'); }
                $to = ($_POST['decision'] ?? '') === 'approve' ? 'Approved' : 'Rejected';
                $s = mysqli_prepare($conn, 'UPDATE mkt_lead_contributors SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?'); mysqli_stmt_bind_param($s, 'sii', $to, $me, $cid); mysqli_stmt_execute($s);
                mkt_audit($conn, 'Contribution ' . strtolower($to) . ($involved ? ' (by an Administrator involved in the lead)' : ''), $ref, 'Proposed', $to, $reason ?: null, $me);
                mkt_notify($conn, [(int) $k['user_id']], 'Your contribution was ' . strtolower($to) . ': ' . $l['name'], $k['role'] . ($k['share_pct'] !== null ? ' · ' . (float) $k['share_pct'] . '%' : '')); $msg = 'Contribution ' . strtolower($to) . '.'; break;
            default: throw new RuntimeException('Unknown action.');
        }
        header('Location: lead.php?id=' . $id . '&success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { @mysqli_rollback($conn); $error = $ex->getMessage(); }
}
$fups = mysqli_fetch_all(mysqli_query($conn, "SELECT f.*, u.names FROM mkt_followups f LEFT JOIN users u ON u.id = f.user_id WHERE f.lead_id = $id ORDER BY f.id DESC"), MYSQLI_ASSOC);
$contribs = mysqli_fetch_all(mysqli_query($conn, "SELECT k.*, u.names, a.names AS approver FROM mkt_lead_contributors k JOIN users u ON u.id = k.user_id LEFT JOIN users a ON a.id = k.approved_by WHERE k.lead_id = $id ORDER BY k.id"), MYSQLI_ASSOC);
$acq = mysqli_fetch_assoc(mysqli_query($conn, "SELECT a.*, c.name AS customer FROM mkt_acquisitions a JOIN customers c ON c.id = a.customer_id WHERE a.lead_id = $id"));
$match = mkt_lead_open($l) ? mkt_matching_customer($conn, $l['phone'], $l['email']) : null;
$log = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, u.names FROM mkt_audit a LEFT JOIN users u ON u.id = a.user_id WHERE a.reference = '$ref' ORDER BY a.id DESC"), MYSQLI_ASSOC);
$od = $l['next_followup'] && $l['next_followup'] < date('Y-m-d') && mkt_lead_open($l);
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('leads.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<a href="leads.php" class="small text-muted text-decoration-none">&larr; Leads</a>
<h4 class="mt-1"><?= $e($l['name']); ?> <span class="small text-muted"><?= $ref; ?></span> <?= mkt_badge($l['status']); ?></h4>
<?php if ($od) { ?><div class="alert alert-danger small"><i class="bi bi-alarm"></i> Follow-up overdue since <?= date('d M Y', strtotime($l['next_followup'])); ?>.</div><?php } ?>
<?php if ($l['status'] === 'Lost') { ?><div class="alert alert-secondary small"><strong>Lost:</strong> <?= $e($l['lost_reason']); ?></div><?php } ?>
<div class="row g-3">
<div class="col-lg-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="row">
        <?php foreach (['Contact person' => $l['contact_person'], 'Phone' => $l['phone'], 'Email' => $l['email'], 'Location' => $l['location'], 'Organization type' => $l['org_type'], 'Product / service' => $l['product_name'],
                        'Source' => $l['source'], 'Campaign' => $l['campaign'] ? mkt_code('campaign', ['id' => $l['campaign_id'], 'created_at' => $l['campaign_created']]) . ' ' . $l['campaign'] : '', 'Owner (direct acquirer)' => $l['owner'],
                        'Estimated value' => $l['estimated_value'] !== null ? $rwf($l['estimated_value']) : '', 'Next follow-up' => $l['next_followup'] ? date('d M Y', strtotime($l['next_followup'])) : '', 'Notes' => $l['notes'],
                        'Registered' => date('d M Y', strtotime($l['created_at'])) . ' by ' . $l['creator']] as $k => $val) { if ((string) $val === '') { continue; } ?>
        <div class="col-md-6 mb-2"><div class="text-muted"><?= $k; ?></div><div><?= $e($val); ?></div></div><?php } ?></div></div></div>
    <?php if ($mayWork && mkt_lead_open($l)) { ?>
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 small"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="followup">
        <div class="col-12 fw-semibold">Record a follow-up</div>
        <div class="col-md-4"><select name="method" class="form-select form-select-sm" required><option value="">How?</option><?php foreach (MKT_FOLLOWUP_METHODS as $m) { ?><option><?= $m; ?></option><?php } ?></select></div>
        <div class="col-md-4"><select name="status_after" id="stAfter" class="form-select form-select-sm"><?php foreach (array_diff(MKT_LEAD_FLOW, ['Won']) as $s) { ?><option <?= $s === $l['status'] ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select></div>
        <div class="col-md-4"><input type="date" name="next_date" min="<?= date('Y-m-d'); ?>" class="form-control form-control-sm" title="Next follow-up"></div>
        <div class="col-12"><textarea name="note" rows="2" class="form-control form-control-sm" placeholder="What was discussed, the customer's reaction, next step" required></textarea></div>
        <div class="col-12" id="lostBox" style="display:none;"><input name="reason" class="form-control form-control-sm" placeholder="Why was the lead lost? (required)"></div>
        <div class="col-12"><button class="btn btn-primary btn-sm">Save follow-up</button></div>
    </div></form>
    <script>(function () { var s = document.getElementById('stAfter'), b = document.getElementById('lostBox'); function f() { b.style.display = s.value === 'Lost' ? '' : 'none'; } s.addEventListener('change', f); f(); })();</script>
    <?php } ?>
    <div class="card border-0 shadow-sm"><div class="card-body small"><h6>Communication history</h6>
        <?php if (!$fups) { ?><div class="text-muted">No follow-up recorded yet.</div><?php } ?>
        <?php foreach ($fups as $f) { ?><div class="border-bottom py-2"><div class="d-flex justify-content-between"><strong><?= $e($f['method']); ?> · <?= $e($f['names']); ?></strong><span class="text-muted"><?= date('d M Y, H:i', strtotime($f['created_at'])); ?></span></div>
            <div style="white-space:pre-wrap;"><?= $e($f['note']); ?></div><div class="text-muted">Status: <?= $e($f['status_after']); ?><?= $f['next_date'] ? ' · next follow-up ' . date('d M Y', strtotime($f['next_date'])) : ''; ?></div></div><?php } ?>
    </div></div>
</div>
<div class="col-lg-5">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><h6>Customer acquisition</h6>
        <?php if ($acq) { ?>
            <div>Customer: <strong><?= $e($acq['customer']); ?></strong> (#<?= (int) $acq['customer_id']; ?>)</div>
            <div>Status: <?= mkt_badge($acq['status']); ?></div>
            <div>Converted: <?= date('d M Y', strtotime($acq['acquired_at'])); ?></div>
            <div>First verified payment: <?= $acq['first_paid_at'] ? '<strong>' . date('d M Y', strtotime($acq['first_paid_at'])) . '</strong> (sale #' . (int) $acq['first_paid_sale_id'] . ')' : '<span class="text-muted">not yet: counts once a sale is fully paid and approved in Transactions</span>'; ?></div>
            <?php if ($acq['status'] === 'Existing customer') { ?><div class="text-muted mt-1">This customer had paid purchases before the lead was registered, so it is not a new acquisition.</div><?php } ?>
        <?php } elseif (mkt_lead_open($l) && $mayWork) { ?>
            <div class="mb-2"><?= $match ? 'An existing customer matches this contact: <strong>' . $e($match['name']) . '</strong> (#' . (int) $match['id'] . ', ' . $e($match['phone']) . '). Converting <strong>links</strong> it: no duplicate customer is created.' : 'No existing customer has this phone or email: converting <strong>creates</strong> the customer.'; ?></div>
            <form method="POST" onsubmit="return confirm('Convert this lead to a customer? The lead becomes Won.');"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="convert"><button class="btn btn-success btn-sm">Convert to customer</button></form>
        <?php } else { ?><div class="text-muted">Not converted.</div><?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><h6>Team contributions</h6>
        <div class="text-muted mb-2">The lead owner (<?= $e($l['owner']); ?>) is the direct acquirer. Supporting work is recorded here with a share and a reason, and approved by an authorised person.</div>
        <?php foreach ($contribs as $k) { ?><div class="border-top py-2"><div class="d-flex justify-content-between"><strong><?= $e($k['names']); ?></strong><?= mkt_badge($k['status']); ?></div>
            <div><?= $e($k['role']); ?><?= $k['share_pct'] !== null ? ' · ' . (float) $k['share_pct'] . '%' : ''; ?><?= $k['points'] !== null ? ' · ' . (int) $k['points'] . ' points' : ''; ?></div><div class="text-muted"><?= $e($k['reason']); ?><?= $k['approver'] ? ' · ' . strtolower($k['status']) . ' by ' . $e($k['approver']) : ''; ?></div>
            <?php if ($k['status'] === 'Proposed' && $canApprove) { ?><form method="POST" class="d-flex gap-1 mt-1"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="contrib_decide"><input type="hidden" name="contrib_id" value="<?= (int) $k['id']; ?>">
                <input name="reason" class="form-control form-control-sm" placeholder="Note"><button name="decision" value="approve" class="btn btn-sm btn-success">Approve</button><button name="decision" value="reject" class="btn btn-sm btn-outline-danger">Reject</button></form><?php } ?></div><?php } ?>
        <div class="text-muted">Total shares: <?= mkt_contrib_total($conn, $id); ?>% (maximum 100%).</div>
        <?php if ($canManage) { ?><form method="POST" class="row g-1 mt-2"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="contrib_add">
            <div class="col-6"><select name="user_id" class="form-select form-select-sm" required><option value="">Team member</option><?php foreach ($L['users'] as $u) { ?><option value="<?= (int) $u['id']; ?>"><?= $e($u['names']); ?></option><?php } ?></select></div>
            <div class="col-6"><select name="role" class="form-select form-select-sm" required><option value="">Contribution</option><?php foreach (MKT_CONTRIB_ROLES as $r) { ?><option><?= $r; ?></option><?php } ?></select></div>
            <div class="col-3"><input type="number" step="0.01" min="0" max="100" name="share_pct" class="form-control form-control-sm" placeholder="%"></div><div class="col-3"><input type="number" min="0" name="points" class="form-control form-control-sm" placeholder="points"></div>
            <div class="col-6"><input name="reason" class="form-control form-control-sm" placeholder="Reason" required></div>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary">Propose contribution</button></div></form><?php } ?>
    </div></div>
    <?php if ($canManage) { ?><div class="card border-0 shadow-sm mb-3"><div class="card-body small">
        <?php if (mkt_lead_open($l)) { ?><h6>Reassign</h6><form method="POST" class="d-flex flex-wrap gap-1"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="reassign">
            <select name="owner_id" class="form-select form-select-sm" style="max-width:180px;"><?php foreach ($L['users'] as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= (int) $l['owner_id'] === (int) $u['id'] ? 'selected' : ''; ?>><?= $e($u['names']); ?></option><?php } ?></select>
            <input name="reason" class="form-control form-control-sm" style="max-width:200px;" placeholder="Reason"><button class="btn btn-sm btn-outline-primary">Reassign</button></form><?php } ?>
        <?php if ($l['status'] === 'Lost') { ?><h6>Reopen</h6><form method="POST" class="d-flex gap-1"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="reopen"><input name="reason" class="form-control form-control-sm" placeholder="Why reopen?"><button class="btn btn-sm btn-outline-primary">Reopen</button></form><?php } ?>
    </div></div><?php } ?>
    <div class="card border-0 shadow-sm"><div class="card-body small"><h6>History</h6>
        <?php foreach ($log as $a) { ?><div class="border-bottom py-1"><span class="text-muted"><?= date('d M Y, H:i', strtotime($a['created_at'])); ?></span> · <?= $e($a['names'] ?? 'System'); ?> · <?= $e($a['action']); ?><?= $a['reason'] ? '<div class="text-muted">' . $e($a['reason']) . '</div>' : ''; ?></div><?php } ?>
    </div></div>
</div></div>
<?php include '../../includes/footer.php'; ?>