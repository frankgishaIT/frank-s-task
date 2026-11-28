<?php
/**
 * NEW FILE: modules/delegations/pay.php — Tasker payment of ONE delegation: approve the amount, record payments
 * (partial payments allowed, each with its own reference), reasons for non-payment, the Tasker's confirmation,
 * disputes and the final reconciliation.
 */
require __DIR__ . '/common.php';
if (!dlc($conn, 'finance') && !dlc($conn, 'approve')) { dl_require($conn, 'finance'); }
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$d = dl_get($conn, $id);
if (!$d) { dl_go('payments.php', 'error', 'Delegation not found.'); }
$back = 'pay.php?id=' . $id;
$fin = dlc($conn, 'finance');
$appr = dlc($conn, 'approve');
$isAdmin = ($_SESSION['user_role'] ?? '') === 'Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dl_csrf_ok()) { dl_go($back, 'error', 'The form expired. Please try again.'); }
    $act = (string) ($_POST['act'] ?? '');
    $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
    $now = dl_now(); $u = dl_uid();
    $pos = dl_pay_position($conn, $d);
    $need = function (bool $ok, string $msg) use ($back) { if (!$ok) { dl_go($back, 'error', $msg); } };
    switch ($act) {
        case 'approve_amount':
            $need($appr, 'Only an approver can approve Tasker payments.');
            $need((bool) $d['verified_at'], 'The service must be verified first.');
            $need($d['status'] !== 'Closed – Financially Reconciled', 'This delegation is closed.');
            $amt = round(max(0, (float) str_replace(',', '', (string) ($_POST['amount'] ?? ''))), 2);
            $need($amt > 0 || $note !== '', 'Give the amount.');
            $need(abs($amt - (float) $d['pay_due']) < 0.5 || $note !== '', 'The amount differs from the entitlement (' . dl_money($d['pay_due']) . '): give the reason. Nothing is deducted from a Tasker without an investigated, documented decision.');
            $need($amt >= dl_paid_total($pos['pays']) - 0.5, 'The approved amount cannot be below what is already paid.');
            dl_set_status($conn, $id, $d['status'] === 'Verified' ? 'Awaiting Payment' : $d['status'], ['pay_approved' => $amt, 'pay_approved_by' => $u, 'pay_approved_at' => $now, 'pay_approval_note' => $note ?: null]);
            dl_event($conn, $id, 'payment_approved', 'Tasker payment approved: ' . dl_money($amt) . ($d['pay_approved'] !== null ? ' (was ' . dl_money($d['pay_approved']) . ')' : '') . ' · entitlement ' . dl_money($d['pay_due']) . ' · due ' . ($d['pay_due_date'] ? date('d M Y', strtotime($d['pay_due_date'])) : '—'), 'staff', 0, $note ?: null);
            dl_notify_staff($conn, dl_users_with($conn, 'delegations.finance'), $d['ref'] . ': Tasker payment approved', dl_money($amt) . ' for ' . ($d['tasker']['display'] ?? '') . ', due ' . ($d['pay_due_date'] ? date('d M Y', strtotime($d['pay_due_date'])) : 'now') . '.');
            dl_go($back, 'success', 'Approved: ' . dl_money($amt) . '.');
        case 'reason':
            $need($fin || $appr, 'Not allowed.');
            $r = in_array($_POST['nonpay_reason'] ?? '', DL_NONPAY_REASONS, true) ? $_POST['nonpay_reason'] : '';
            $need($r !== '', 'Choose the reason.');
            $need($r !== 'Other' || $note !== '', 'Explain the reason.');
            dl_set_status($conn, $id, $d['status'], ['nonpay_reason' => $r, 'nonpay_note' => $note ?: null]);
            dl_event($conn, $id, 'nonpay_reason', 'Not paid yet: ' . $r . (in_array($r, DL_HOLD_REASONS, true) ? ' (legitimate hold)' : ''), 'staff', 0, $note ?: null);
            dl_go($back, 'success', 'Reason recorded.');
        case 'record':
            $need($fin, 'Only Finance can record Tasker payments.');
            $need($d['pay_approved'] !== null, 'The payment amount must be approved first.');
            $need($d['status'] !== 'Issue Review', 'A service issue on this delegation is under review: the Tasker payment is held until it is resolved (Quality & issues).');
            $amt = round((float) str_replace(',', '', (string) ($_POST['amount'] ?? '')), 2);
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['paid_on'] ?? '')) ? $_POST['paid_on'] : '';
            $method = in_array($_POST['method'] ?? '', DL_PAY_METHODS, true) ? $_POST['method'] : '';
            $ref = mb_substr(trim((string) ($_POST['reference'] ?? '')), 0, 80);
            $fund = (int) ($_POST['fund_id'] ?? 0);
            $acc = (int) ($_POST['account_id'] ?? 0) ?: null;
            $need($amt > 0, 'Give the amount paid.');
            $need($date !== '' && $date <= date('Y-m-d'), 'Give the payment date (not in the future).');
            $need($method !== '', 'Choose how it was paid.');
            $need($ref !== '', 'Give the unique payment reference (MoMo / bank transaction ID or receipt number).');
            $need($fund > 0, 'Choose the fund the payment comes from.');
            $s = mysqli_prepare($conn, "SELECT p.id, p.delegation_id FROM dl_payments p WHERE p.status <> 'Void' AND LOWER(p.reference) = LOWER(?) LIMIT 1");
            mysqli_stmt_bind_param($s, 's', $ref); mysqli_stmt_execute($s);
            $dupRef = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
            $need(!$dupRef, 'Reference ' . $ref . ' is already used by ' . ($dupRef ? dl_pay_ref((int) $dupRef['id']) . ' (' . dl_ref((int) $dupRef['delegation_id']) . ')' : '') . ': a payment reference can be recorded only once.');
            $flags = [];
            foreach ($pos['pays'] as $p) { if ($p['status'] !== 'Void' && abs((float) $p['amount'] - $amt) < 0.5 && $p['paid_on'] === $date) { $flags[] = 'same amount on the same day as ' . dl_pay_ref((int) $p['id']) . ' (possible duplicate)'; } }
            if ($amt > $pos['outstanding'] + 0.5) { $flags[] = $pos['outstanding'] <= 0.5 ? 'the approved amount is already fully paid' : 'more than the outstanding ' . dl_money($pos['outstanding']); }
            $expl = mb_substr(trim((string) ($_POST['explanation'] ?? '')), 0, 500);
            $need(!$flags || $expl !== '', 'This payment needs an explanation and an approver: ' . implode('; ', $flags) . '.');
            $proof = null;
            if (!empty($_FILES['proof']['name'])) {
                require_once __DIR__ . '/../../includes/evidence_store.php';
                try { $proof = evidence_save($conn, $_FILES['proof'], $u, ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 5 * 1048576); }
                catch (Throwable $e) { dl_go($back, 'error', 'Payment proof: ' . $e->getMessage()); }
            }
            $status = $flags ? 'Pending approval' : 'Recorded';
            $pid = (int) $d['party_id']; $ex = $expl ?: null;
            mysqli_begin_transaction($conn);
            try {
                $s = mysqli_prepare($conn, 'INSERT INTO dl_payments (delegation_id, party_id, amount, paid_on, method, reference, proof_ref, fund_id, account_id, status, explanation, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($s, 'iidssssiissis', $id, $pid, $amt, $date, $method, $ref, $proof, $fund, $acc, $status, $ex, $u, $now);
                mysqli_stmt_execute($s);
                $payId = (int) mysqli_insert_id($conn);
                if ($status === 'Recorded') { dl_post_payment($conn, $d, ['id' => $payId, 'amount' => $amt, 'paid_on' => $date, 'reference' => $ref, 'fund_id' => $fund, 'account_id' => $acc]); }
                mysqli_commit($conn);
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                dl_go($back, 'error', 'Not recorded: ' . $e->getMessage());
            }
            if ($status === 'Recorded') {
                dl_after_post($conn, $id, $payId);
                $pos2 = dl_pay_position($conn, dl_get($conn, $id));
                dl_go($back, 'success', dl_pay_ref($payId) . ' recorded (' . dl_money($amt) . ') and posted in Transactions. ' . $pos2['label'] . '. The Tasker was asked to confirm receipt.');
            }
            dl_event($conn, $id, 'payment_pending', dl_pay_ref($payId) . ': ' . dl_money($amt) . ' (' . $ref . ') waits for approval: ' . implode('; ', $flags), 'staff', 0, $expl);
            dl_notify_staff($conn, array_values(array_diff(dl_approvers($conn), [$u])), 'Tasker payment to approve: ' . dl_pay_ref($payId), $d['ref'] . ' · ' . dl_money($amt) . ' · ' . implode('; ', $flags) . ' · Explanation: ' . $expl);
            dl_go($back, 'success', dl_pay_ref($payId) . ' saved but NOT paid out yet: an approver must accept it (' . implode('; ', $flags) . ').');
        case 'approve_payment':
        case 'refuse_payment':
            $need($appr, 'Only an approver can decide.');
            $p = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_payments WHERE id = ' . (int) ($_POST['pay_id'] ?? 0) . " AND delegation_id = $id AND status = 'Pending approval'"));
            $need((bool) $p, 'Payment not found.');
            $need((int) $p['recorded_by'] !== $u || $isAdmin, 'You recorded this payment: another approver must decide.');
            if ($act === 'refuse_payment') {
                $need($note !== '', 'Give the reason.');
                mysqli_query($conn, "UPDATE dl_payments SET status = 'Void', void_reason = '" . mysqli_real_escape_string($conn, 'Not approved: ' . $note) . "', approved_by = $u, approved_at = '$now' WHERE id = " . (int) $p['id']);
                dl_event($conn, $id, 'payment_refused', dl_pay_ref((int) $p['id']) . ' (' . dl_money($p['amount']) . ') not approved', 'staff', 0, $note);
                dl_go($back, 'success', 'Not approved: nothing was paid out.');
            }
            mysqli_begin_transaction($conn);
            try {
                mysqli_query($conn, "UPDATE dl_payments SET status = 'Recorded', approved_by = $u, approved_at = '$now' WHERE id = " . (int) $p['id']);
                dl_post_payment($conn, $d, $p);
                mysqli_commit($conn);
            } catch (Throwable $e) { mysqli_rollback($conn); dl_go($back, 'error', 'Not posted: ' . $e->getMessage()); }
            dl_event($conn, $id, 'payment_accepted', dl_pay_ref((int) $p['id']) . ' approved despite: ' . $p['explanation'], 'staff', 0, $note ?: null);
            dl_after_post($conn, $id, (int) $p['id']);
            dl_go($back, 'success', 'Approved and posted.');
        case 'confirm_for':
            $need($fin || $appr, 'Not allowed.');
            $p = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_payments WHERE id = ' . (int) ($_POST['pay_id'] ?? 0) . " AND delegation_id = $id AND status = 'Recorded'"));
            $need((bool) $p && $p['confirm_status'] !== 'Confirmed', 'Not possible now.');
            $need($note !== '', 'Say how the Tasker confirmed (call, WhatsApp message, signed receipt…).');
            $s = mysqli_prepare($conn, "UPDATE dl_payments SET confirm_status = 'Confirmed', confirmed_at = ?, confirmed_by_staff = ?, confirm_note = ? WHERE id = ?");
            $pidp = (int) $p['id']; mysqli_stmt_bind_param($s, 'sisi', $now, $u, $note, $pidp); mysqli_stmt_execute($s);
            dl_event($conn, $id, 'payment_confirmed', 'Receipt of ' . $p['reference'] . ' (' . dl_money($p['amount']) . ') recorded BY STAFF for the Tasker', 'staff', 1, $note, 1);
            dl_go($back, 'success', 'Confirmation recorded (as staff-recorded, not the Tasker\'s own click).');
        case 'dispute_for':
            $need($fin || $appr, 'Not allowed.');
            $p = (int) ($_POST['pay_id'] ?? 0) ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_payments WHERE id = ' . (int) $_POST['pay_id'] . " AND delegation_id = $id")) : null;
            $kind = in_array($_POST['kind'] ?? '', DL_DISPUTE_KINDS, true) ? $_POST['kind'] : '';
            $need($kind !== '' && $note !== '', 'Choose the kind of issue and describe it.');
            $rec = trim((string) ($_POST['received'] ?? '')) === '' ? null : max(0, (float) str_replace(',', '', (string) $_POST['received']));
            $did = dl_create_dispute($conn, $d, $p ?: null, $kind, $rec, $note, 'staff');
            dl_go('dispute.php?id=' . $did, 'success', 'Dispute ' . dl_dispute_ref($did) . ' opened for the Tasker.');
        case 'void_pending':
            $need($fin || $appr, 'Not allowed.');
            $p = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_payments WHERE id = ' . (int) ($_POST['pay_id'] ?? 0) . " AND delegation_id = $id AND status = 'Pending approval'"));
            $need((bool) $p && $note !== '', 'Give the reason.');
            mysqli_query($conn, "UPDATE dl_payments SET status = 'Void', void_reason = '" . mysqli_real_escape_string($conn, $note) . "' WHERE id = " . (int) $p['id']);
            dl_event($conn, $id, 'payment_void', dl_pay_ref((int) $p['id']) . ' withdrawn before payment', 'staff', 0, $note);
            dl_go($back, 'success', 'Withdrawn.');
        case 'reverse':
            // NEW (batch 4): a posted Tasker payment cannot be deleted in Transactions; it is reversed here, by Finance with
            // approval rights (Admins by default), with the reason. The money goes back to its fund, the transaction is kept
            // as 'deleted' in the transactions audit, and the payment becomes Void. Nothing is lost from the history.
            $need($fin && ($appr || $isAdmin), 'Only Finance with approval rights can reverse a recorded payment.');
            $need($d['status'] !== 'Closed – Financially Reconciled', 'This delegation is closed.');
            $p = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_payments WHERE id = ' . (int) ($_POST['pay_id'] ?? 0) . " AND delegation_id = $id AND status = 'Recorded'"));
            $need((bool) $p, 'Payment not found.');
            $need(mb_strlen($note) >= 10, 'Explain why the payment is reversed (wrong amount, not actually sent, money returned by the Tasker…).');
            require_once __DIR__ . '/../../includes/fund_helpers.php';
            require_once __DIR__ . '/../../includes/transaction_audit_helpers.php';
            tx_audit_ensure($conn);
            mysqli_begin_transaction($conn);
            try {
                $txId = (int) $p['transaction_id'];
                $tx = $txId ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM transactions WHERE id = ' . $txId)) : null;
                if ($tx && $tx['status'] !== 'deleted') {
                    if (!empty($tx['fund_id'])) { fund_lock($conn, (int) $tx['fund_id']); fund_reverse_movements($conn, 'm.transaction_id = ?', 'i', [$txId], $u ?: null, tx_reference($txId) . ' reversed (' . dl_pay_ref((int) $p['id']) . '): ' . $note); }
                    $s = mysqli_prepare($conn, "UPDATE transactions SET status = 'deleted', deleted_by = ?, deleted_at = NOW() WHERE id = ?");
                    mysqli_stmt_bind_param($s, 'ii', $u, $txId); mysqli_stmt_execute($s);
                    tx_audit_log($conn, $tx, 'Deleted', $note, 'RM Tasker payment ' . dl_pay_ref((int) $p['id']) . ' on ' . $d['ref'] . ' reversed from Delegations; the money was returned to its fund.', $u ?: null);
                }
                $why = mb_substr('Reversed: ' . $note, 0, 255);
                $s = mysqli_prepare($conn, "UPDATE dl_payments SET status = 'Void', void_reason = ? WHERE id = ?");
                $pidv = (int) $p['id']; mysqli_stmt_bind_param($s, 'si', $why, $pidv); mysqli_stmt_execute($s);
                mysqli_commit($conn);
            } catch (Throwable $e) { mysqli_rollback($conn); dl_go($back, 'error', 'Not reversed: ' . $e->getMessage()); }
            dl_event($conn, $id, 'payment_reversed', dl_pay_ref((int) $p['id']) . ' (' . dl_money($p['amount']) . ', ref ' . $p['reference'] . ') reversed' . ($p['transaction_id'] ? ' · transaction #' . (int) $p['transaction_id'] . ' reversed in its fund' : '') . ($p['confirm_status'] === 'Confirmed' ? ' · it had been confirmed by the Tasker' : ''), 'staff', 0, $note, 1);
            dl_audit($conn, 'payment', (int) $p['id'], 'reversed', dl_pay_ref((int) $p['id']) . ' · ' . $d['ref'] . ' · ' . dl_money($p['amount']), $note, 'staff', (int) $p['party_id']);
            dl_sync_after_payment($conn, $id);
            dl_go($back, 'success', 'Payment reversed; the money is back in its fund.');
        case 'reconcile':
            $need($fin || $appr, 'Not allowed.');
            $need($d['status'] === 'Paid', 'Only a fully paid delegation can be reconciled.');
            $need($pos['approved'] !== null && abs($pos['paid'] - $pos['approved']) < 0.5, 'Paid (' . dl_money($pos['paid']) . ') and approved (' . dl_money($pos['approved']) . ') do not match.');
            $need($pos['confirmed_all'], 'Every payment must be confirmed as received (by the Tasker, or recorded by staff with how it was confirmed).');
            $need(!$pos['disputes'], 'A payment dispute is still open.');
            $need(!mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM dl_payments WHERE delegation_id = $id AND status = 'Pending approval'")), 'A payment is still waiting for approval.');
            dl_set_status($conn, $id, 'Closed – Financially Reconciled', ['closed_at' => $now, 'reconciled_by' => $u]);
            dl_event($conn, $id, 'reconciled', 'Financially reconciled: approved ' . dl_money($pos['approved']) . ' = paid ' . dl_money($pos['paid']) . ' = confirmed by the Tasker; no open dispute', 'staff', 0, $note ?: null);
            dl_go($back, 'success', 'Closed – financially reconciled.');
    }
    dl_go($back, 'error', 'Unknown action.');
}

$pos = dl_pay_position($conn, $d);
$names = dl_users($conn);
$funds = mysqli_fetch_all(mysqli_query($conn, "SELECT id, code, name FROM funds ORDER BY id"), MYSQLI_ASSOC);
$accounts = [];
try { $r = mysqli_query($conn, "SELECT id, name FROM fa_accounts WHERE status = 'Active' ORDER BY name"); if ($r) { $accounts = mysqli_fetch_all($r, MYSQLI_ASSOC); } } catch (Throwable $e) { $accounts = []; }
$allDisputes = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM dl_disputes WHERE delegation_id = ' . $id . ' ORDER BY id DESC'), MYSQLI_ASSOC);
$confSum = 0; foreach ($pos['pays'] as $p) { if ($p['status'] === 'Recorded' && $p['confirm_status'] === 'Confirmed') { $confSum += (float) $p['amount']; } }
$t = $d['tasker'];
$form = function ($act, $inner, $btn, $cls = 'btn-outline-primary', $multipart = false) use ($id) { return '<form method="post"' . ($multipart ? ' enctype="multipart/form-data"' : '') . ' class="mb-2">' . dl_csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="act" value="' . $act . '">' . $inner . '<button class="btn btn-sm ' . $cls . '">' . $btn . '</button></form>'; };

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'payments.php', 'Tasker payment · ' . $d['ref'], dl_pay_badge($pos));
?>
<div class="small mb-2"><a href="view.php?id=<?= $id; ?>"><?= dl_e($d['ref']); ?></a> · <?= dl_e($d['product_name']); ?> · <?= $t ? '<a href="tasker.php?id=' . (int) $t['id'] . '">' . dl_e($t['display']) . '</a> (' . dl_e($t['code']) . ') · pays to ' . dl_e(($t['payment_account_type'] === 'Phone' ? 'Mobile Money ' : 'Bank ') . $t['payment_account_details']) : ''; ?> · delegation status <?= dl_status_badge($d['status']); ?></div>
<div class="alert <?= in_array($pos['status'], ['Unpaid', 'Overpaid', 'Payment Disputed', 'Reconciliation Required'], true) || $pos['overdue'] ? 'alert-danger' : 'alert-light border'; ?> py-2 small fw-semibold"><?= dl_e($pos['label']); ?><?= $pos['overdue'] ? ' · OVERDUE since ' . dl_e(date('d M Y', strtotime($pos['due_date']))) : ''; ?><?= $d['nonpay_reason'] ? ' · Held: ' . dl_e($d['nonpay_reason']) . ($d['nonpay_note'] ? ' (' . dl_e($d['nonpay_note']) . ')' : '') : ''; ?></div>
<div class="row g-3">
<div class="col-xl-8">
    <div class="row g-2 mb-3">
    <?php foreach ([['Total Tasker amount due', $pos['approved'] ?? $pos['entitled']], ['Amount paid', $pos['paid']], ['Outstanding balance', $pos['outstanding']]] as [$l, $v]) { ?>
        <div class="col-4"><div class="dl-kpi"><div class="v"><?= dl_money($v); ?></div><div class="l"><?= $l; ?></div></div></div>
    <?php } ?>
    </div>
    <div class="card mb-3"><div class="card-body py-2 small">
        <div class="fw-semibold mb-1">Reconciliation</div>
        <table class="table table-sm dl-tbl mb-1"><tbody>
            <tr><td>Tasker entitlement (calculated at verification)</td><td class="text-end"><?= dl_money($pos['entitled']); ?></td></tr>
            <tr><td>Approved payment amount<?= $d['pay_approved_by'] ? ' <span class="text-muted">by ' . dl_e($names[(int) $d['pay_approved_by']] ?? '') . ' ' . dl_e(date('d M Y', strtotime($d['pay_approved_at']))) . '</span>' : ''; ?><?= $d['pay_approval_note'] ? '<div class="text-muted">' . dl_e($d['pay_approval_note']) . '</div>' : ''; ?></td><td class="text-end"><?= $pos['approved'] !== null ? dl_money($pos['approved']) : '<span class="text-warning">not approved</span>'; ?></td></tr>
            <tr><td>Recorded payment transactions</td><td class="text-end"><?= dl_money($pos['paid']); ?></td></tr>
            <tr><td>Receipt confirmed by the Tasker</td><td class="text-end"><?= dl_money($confSum); ?></td></tr>
            <tr class="fw-semibold"><td>Outstanding<?= $pos['overpaid'] > 0 ? ' / <span class="text-danger">overpaid ' . dl_money($pos['overpaid']) . ': investigate</span>' : ''; ?></td><td class="text-end"><?= dl_money($pos['outstanding']); ?></td></tr>
            <tr><td>Payment due date</td><td class="text-end"><?= $pos['due_date'] ? dl_e(date('d M Y', strtotime($pos['due_date']))) : '—'; ?></td></tr>
            <tr><td>Open payment disputes</td><td class="text-end"><?= count($pos['disputes']); ?></td></tr>
        </tbody></table>
        <?php if ($d['status'] === 'Closed – Financially Reconciled') { ?><div class="text-success fw-semibold">Closed – financially reconciled<?= $d['reconciled_by'] ? ' by ' . dl_e($names[(int) $d['reconciled_by']] ?? '') : ''; ?> on <?= dl_e(date('d M Y', strtotime($d['closed_at']))); ?>.</div>
        <?php } elseif ($d['status'] === 'Paid' && ($fin || $appr)) { ?><?= $form('reconcile', '<input name="note" class="form-control form-control-sm mb-1" placeholder="Note (optional)">', 'Close as financially reconciled', 'btn-success'); ?><?php } ?>
    </div></div>
    <?php /* NEW (batch 4): issues and authorized recoveries — applied only by changing the approved amount, with the reference */
    $rec = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM dl_issues WHERE delegation_id = $id AND (status IN ('Open', 'Under Investigation') OR recovery_status IN ('Approved', 'Recovered'))"), MYSQLI_ASSOC);
    if ($rec) { ?><div class="card mb-3 border-warning"><div class="card-body py-2 small"><div class="fw-semibold mb-1">Service issues affecting this payment</div>
        <?php foreach ($rec as $x) { ?><div><a href="issue.php?id=<?= (int) $x['id']; ?>"><?= dlq_ref((int) $x['id']); ?></a> · <?= dl_e($x['type']); ?> · <?= dl_e($x['status']); ?><?= in_array($x['status'], DLQ_OPEN, true) && (int) $x['held_payment'] ? ' · <b>payment held until resolved</b>' : ''; ?>
            <?= $x['recovery_status'] === 'Approved' ? ' · <b>authorized recovery ' . dl_money($x['recovery_amount']) . '</b>: to deduct it, change the approved amount with reference ' . dlq_ref((int) $x['id']) : ($x['recovery_status'] === 'Recovered' ? ' · recovered ' . dl_money($x['recovery_amount']) : ''); ?></div><?php } ?>
        <div class="text-muted">Nothing is deducted automatically.</div></div></div><?php } ?>
    <h6 class="mb-1">Payments</h6>
    <div class="card mb-3"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0">
    <thead><tr><th>Payment</th><th>Date</th><th class="text-end">Amount</th><th>Method / reference</th><th>Status</th><th>Confirmed by Tasker</th><th></th></tr></thead><tbody>
    <?php foreach ($pos['pays'] as $p) { ?>
    <tr class="<?= $p['status'] === 'Void' ? 'text-muted' : ''; ?>"><td><?= dl_pay_ref((int) $p['id']); ?><div class="small text-muted"><?= dl_e($names[(int) $p['recorded_by']] ?? ''); ?></div></td><td class="small text-nowrap"><?= dl_e(date('d M Y', strtotime($p['paid_on']))); ?></td>
        <td class="text-end"><?= $p['status'] === 'Void' ? '<s>' . dl_money($p['amount']) . '</s>' : dl_money($p['amount']); ?></td>
        <td class="small"><?= dl_e($p['method']); ?> · <?= dl_e($p['reference']); ?><?= $p['transaction_id'] ? ' · <a href="../transactions/view.php?id=' . (int) $p['transaction_id'] . '">tx #' . (int) $p['transaction_id'] . '</a>' : ''; ?><?= $p['proof_ref'] ? ' · <a href="pay_proof.php?id=' . (int) $p['id'] . '" target="_blank">proof</a>' : ''; ?><?= $p['explanation'] ? '<div class="text-muted">' . dl_e($p['explanation']) . '</div>' : ''; ?></td>
        <td class="small"><?= dl_e($p['status']); ?><?= $p['void_reason'] ? '<div class="text-muted">' . dl_e($p['void_reason']) . '</div>' : ''; ?></td>
        <td class="small"><?php if ($p['status'] === 'Recorded') { ?><span class="dl-st <?= $p['confirm_status'] === 'Confirmed' ? 'dl-ok' : ($p['confirm_status'] === 'Awaiting confirmation' ? 'dl-warn' : 'dl-bad'); ?>"><?= dl_e($p['confirm_status']); ?></span>
            <?= $p['confirmed_at'] ? '<div class="text-muted">' . dl_e(date('d M H:i', strtotime($p['confirmed_at']))) . ($p['confirmed_by_staff'] ? ' · by staff: ' . dl_e($p['confirm_note']) : ' · by the Tasker') . '</div>' : ($p['confirm_due'] ? '<div class="text-muted">until ' . dl_e(dl_deadline_text($p['confirm_due'])) . '</div>' : ''); ?><?php } ?></td>
        <td class="text-nowrap">
            <?php if ($p['status'] === 'Pending approval' && $appr && ((int) $p['recorded_by'] !== dl_uid() || $isAdmin)) { ?>
                <?= $form('approve_payment', '<input type="hidden" name="pay_id" value="' . (int) $p['id'] . '">', 'Approve & pay', 'btn-success py-0'); ?>
                <?= $form('refuse_payment', '<input type="hidden" name="pay_id" value="' . (int) $p['id'] . '"><input name="note" class="form-control form-control-sm mb-1" placeholder="reason">', 'Refuse', 'btn-outline-danger py-0'); ?>
            <?php } elseif ($p['status'] === 'Pending approval') { ?><span class="small text-muted">waiting for another approver</span><?php } ?>
            <?php if ($p['status'] === 'Recorded' && $fin && ($appr || $isAdmin) && $d['status'] !== 'Closed – Financially Reconciled') { ?><?= $form('reverse', '<input type="hidden" name="pay_id" value="' . (int) $p['id'] . '"><input name="note" class="form-control form-control-sm mb-1" placeholder="Why reverse it" minlength="10">', 'Reverse', 'btn-outline-danger py-0'); ?><?php } ?>
            <?php if ($p['status'] === 'Recorded' && $p['confirm_status'] !== 'Confirmed' && ($fin || $appr)) { ?><?= $form('confirm_for', '<input type="hidden" name="pay_id" value="' . (int) $p['id'] . '"><input name="note" class="form-control form-control-sm mb-1" placeholder="How the Tasker confirmed">', 'Record confirmation', 'btn-outline-secondary py-0'); ?><?php } ?>
        </td></tr>
    <?php } ?>
    <?php if (!$pos['pays']) { ?><tr><td colspan="7" class="text-center text-muted py-3">No payment yet.</td></tr><?php } ?>
    </tbody></table></div></div>
    <?php if ($allDisputes) { ?>
    <h6 class="mb-1">Payment disputes</h6>
    <div class="card mb-3"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0"><tbody>
    <?php foreach ($allDisputes as $x) { ?><tr><td><a href="dispute.php?id=<?= (int) $x['id']; ?>"><?= dl_dispute_ref((int) $x['id']); ?></a></td><td class="small"><?= dl_e($x['kind']); ?></td><td class="small"><?= dl_e(mb_strimwidth($x['description'], 0, 90, '…')); ?></td><td><span class="dl-st <?= in_array($x['status'], DL_DISPUTE_OPEN, true) ? 'dl-bad' : 'dl-ok'; ?>"><?= dl_e($x['status']); ?></span></td></tr><?php } ?>
    </tbody></table></div></div>
    <?php } ?>
</div>
<div class="col-xl-4">
<?php if ($appr && $d['verified_at'] && !in_array($d['status'], array_merge(DL_ENDED, ['Closed – Financially Reconciled']), true)) { ?>
    <div class="card mb-3 <?= $d['pay_approved'] === null ? 'border-warning' : ''; ?>"><div class="card-body py-2"><div class="fw-semibold small mb-1"><?= $d['pay_approved'] === null ? 'Approve the Tasker payment' : 'Change the approved amount'; ?></div>
        <?= $form('approve_amount', '<input name="amount" class="form-control form-control-sm mb-1" value="' . dl_e((int) round((float) ($d['pay_approved'] ?? $d['pay_due']))) . '"><input name="note" class="form-control form-control-sm mb-1" placeholder="Reason if different from the entitlement">', 'Approve', 'btn-success'); ?>
        <div class="small text-muted">Entitlement <?= dl_money($d['pay_due']); ?> = <?= dl_pct($d['rate_pct']); ?> of the actual profit pool. Never reduce it automatically for a complaint: only after an investigated, documented decision.</div>
    </div></div>
<?php } ?>
<?php if ($fin && $d['pay_approved'] !== null && !in_array($d['status'], array_merge(DL_ENDED, ['Closed – Financially Reconciled']), true)) { ?>
    <div class="card mb-3"><div class="card-body py-2"><div class="fw-semibold small mb-1">Record a payment</div>
    <?= $form('record', '<div class="row g-1 mb-1"><div class="col-6"><input name="amount" class="form-control form-control-sm" placeholder="Amount" value="' . ($pos['outstanding'] > 0 ? (int) round($pos['outstanding']) : '') . '"></div><div class="col-6"><input type="date" name="paid_on" class="form-control form-control-sm" value="' . date('Y-m-d') . '" max="' . date('Y-m-d') . '"></div>'
        . '<div class="col-6"><select name="method" class="form-select form-select-sm">' . implode('', array_map(function ($m) use ($t) { return '<option' . (($t['payment_account_type'] ?? '') === 'Phone' && $m === 'Mobile Money' || ($t['payment_account_type'] ?? '') === 'Bank' && $m === 'Bank transfer' ? ' selected' : '') . '>' . $m . '</option>'; }, DL_PAY_METHODS)) . '</select></div>'
        . '<div class="col-6"><input name="reference" class="form-control form-control-sm" placeholder="Transaction reference"></div>'
        . '<div class="col-6"><select name="fund_id" class="form-select form-select-sm">' . implode('', array_map(function ($f) { return '<option value="' . (int) $f['id'] . '"' . ($f['code'] === 'OPERATING' ? ' selected' : '') . '>' . dl_e($f['name']) . '</option>'; }, $funds)) . '</select></div>'
        . '<div class="col-6"><select name="account_id" class="form-select form-select-sm"><option value="">Account (optional)</option>' . implode('', array_map(function ($a) { return '<option value="' . (int) $a['id'] . '">' . dl_e($a['name']) . '</option>'; }, $accounts)) . '</select></div>'
        . '<div class="col-12"><label class="small text-muted">Payment proof (PDF / image)</label><input type="file" name="proof" class="form-control form-control-sm"></div>'
        . '<div class="col-12"><input name="explanation" class="form-control form-control-sm" placeholder="Explanation (needed for a possible duplicate or an amount above the outstanding)"></div></div>', 'Record payment', 'btn-primary', true); ?>
    <div class="small text-muted">Posted once as an expense "RM Tasker payments" in Transactions. A partial payment is fine: each one has its own reference.</div>
    </div></div>
<?php } ?>
<?php if (($fin || $appr) && $d['verified_at'] && $pos['outstanding'] > 0.5 && !in_array($d['status'], DL_ENDED, true)) { ?>
    <div class="card mb-3"><div class="card-body py-2"><div class="fw-semibold small mb-1">Why is it not (fully) paid?</div>
        <?= $form('reason', '<select name="nonpay_reason" class="form-select form-select-sm mb-1"><option value="">Choose…</option>' . implode('', array_map(function ($r) use ($d) { return '<option' . ($d['nonpay_reason'] === $r ? ' selected' : '') . '>' . dl_e($r) . '</option>'; }, DL_NONPAY_REASONS)) . '</select><input name="note" class="form-control form-control-sm mb-1" placeholder="Explanation" value="' . dl_e($d['nonpay_note']) . '">', 'Save reason'); ?>
        <div class="small text-muted">Holds (customer not paid, complaint, dispute, approval, wrong details) are not counted as overdue. "Awaiting Finance processing" past the due date is overdue.</div>
    </div></div>
<?php } ?>
<?php if (($fin || $appr) && $pos['pays']) { ?>
    <div class="card mb-3"><div class="card-body py-2"><div class="fw-semibold small mb-1">Open a dispute for the Tasker</div>
        <?= $form('dispute_for', '<select name="pay_id" class="form-select form-select-sm mb-1"><option value="">(no specific payment)</option>' . implode('', array_map(function ($p) { return '<option value="' . (int) $p['id'] . '">' . dl_pay_ref((int) $p['id']) . ' · ' . dl_e($p['reference']) . ' · ' . dl_money($p['amount']) . '</option>'; }, $pos['pays'])) . '</select><select name="kind" class="form-select form-select-sm mb-1">' . implode('', array_map(function ($k) { return '<option>' . $k . '</option>'; }, DL_DISPUTE_KINDS)) . '</select><input name="received" class="form-control form-control-sm mb-1" placeholder="Amount the Tasker says they received"><input name="note" class="form-control form-control-sm mb-1" placeholder="What the Tasker reported">', 'Open dispute', 'btn-outline-danger'); ?>
    </div></div>
<?php } ?>
</div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>