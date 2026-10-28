<?php
/**
 * NEW FILE: includes/executive_delete_page.php
 * Task I: the confirmation page for deleting a loan or an asset (used by modules/loans/executive_delete.php
 * and modules/asset_management/executive_delete.php). Shows everything that will be reversed, requires a
 * reason and an explicit confirmation, then deletes in one all-or-nothing operation.
 */
require_once __DIR__ . '/executive_delete_helpers.php';

function exec_delete_page(mysqli $conn, string $type): void
{
    $isLoan = $type === 'loan';
    $perm = $isLoan ? 'loans.executive_delete' : 'asset_management.executive_delete';
    $back = $isLoan ? 'index.php' : 'index.php';
    access_ensure($conn);
    if (!access_can($perm, $conn)) {   // server-side, whatever the buttons show
        http_response_code(403);
        header('Location: ' . $back . '?error=' . urlencode('Only the Managing Director (or someone explicitly authorized) can delete ' . ($isLoan ? 'loans' : 'assets') . '.'));
        exit;
    }
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) { header('Location: ' . $back); exit; }
    $userId = (int) current_user_id();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $choice = (string) ($_POST['reason_choice'] ?? '');
        $details = trim((string) ($_POST['reason_details'] ?? ''));
        $reason = trim(($choice !== '' && $choice !== 'Other (explained below)' ? $choice . '. ' : '') . $details);
        if (!in_array($choice, EXEC_DELETE_REASONS, true)) {
            $error = 'Please choose the reason.';
        } elseif (mb_strlen($details) < 10) {
            $error = 'Please explain the reason in a few words (at least 10 characters): what was wrong with this record.';
        } elseif (($_POST['confirm_text'] ?? '') !== 'DELETE') {
            $error = 'Type DELETE in capital letters to confirm.';
        } else {
            try {
                $msg = exec_delete($conn, $type, (int) $id, $reason, $userId);
                header('Location: ' . $back . '?success=' . urlencode($msg));
                exit;
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
    }

    $plan = $isLoan ? exec_loan_plan($conn, (int) $id) : exec_asset_plan($conn, (int) $id);
    $record = $isLoan ? $plan['loan'] : $plan['asset'];
    if (!$record) { header('Location: ' . $back . '?error=' . urlencode(($isLoan ? 'Loan' : 'Asset') . ' not found.')); exit; }
    $ref = $isLoan ? 'LN-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT) : $record['asset_code'];
    $e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
    $m = function ($v) { return number_format((float) $v, 2); };

    // The effect on each fund, in plain words.
    $byFund = [];
    foreach ($plan['movements'] as $mv) {
        $byFund[$mv['fund']] = ($byFund[$mv['fund']] ?? 0) + ($mv['direction'] === 'IN' ? -1 : 1) * (float) $mv['amount'];
    }
    foreach ($plan['transactions'] as $tx) {
        $fid = (int) ($tx['fund_id'] ?? 0);
        if ($fid && $tx['status'] === 'approved' && $tx['transaction_type'] === 'Expense') {
            $f = fund_get($conn, $fid);
            $byFund[$f['name'] ?? 'Fund'] = ($byFund[$f['name'] ?? 'Fund'] ?? 0) + (float) $tx['amount'];
        }
    }

    $modal_icon = 'bi-exclamation-octagon-fill';
    $modal_title = 'Delete ' . ($isLoan ? 'loan' : 'asset') . ' ' . $ref;
    $modal_subtitle = 'Executive deletion: restricted, justified, confirmed and recorded.';
    include __DIR__ . '/header.php'; include __DIR__ . '/sidebar.php';
    ?>
    <div class="rm-modal-backdrop"><div class="rm-modal" style="max-width:860px;">
        <?php include __DIR__ . '/model_header.php'; ?>
        <div class="rm-modal-body">
            <?php if (isset($error)) { ?><div class="alert alert-danger small"><?= $e($error); ?></div><?php } ?>

            <table class="table table-sm mb-3" style="font-size:13px;">
                <?php if ($isLoan) { ?>
                <tr><th style="width:35%;">Loan</th><td><?= $e($ref . ' · ' . $record['lender'] . ' (' . $record['loan_type'] . ')'); ?></td></tr>
                <tr><th>Amount borrowed</th><td>RWF <?= $m($record['loan_amount']); ?> on <?= date('d M Y', strtotime($record['loan_start_date'])); ?></td></tr>
                <tr><th>Paid so far</th><td>RWF <?= $m($record['total_paid']); ?> (principal <?= $m($record['principal_repaid']); ?>, interest <?= $m($record['interest_paid']); ?>) in <?= count($plan['payments'] ?? []); ?> repayment(s)</td></tr>
                <tr><th>Status</th><td><?= $e($record['status']); ?></td></tr>
                <?php } else { ?>
                <tr><th style="width:35%;">Asset</th><td><?= $e($ref . ' · ' . $record['asset_name'] . ' (' . $record['asset_type'] . ')'); ?></td></tr>
                <tr><th>Acquired</th><td>RWF <?= $m($record['acquisition_value']); ?> on <?= date('d M Y', strtotime($record['acquisition_date'])); ?> · <?= $e($record['funding_type'] ?? ''); ?></td></tr>
                <tr><th>Value today</th><td>RWF <?= $m($record['current_value']); ?> · status <?= $e($record['status']); ?><?= !empty($plan['disposals']) ? ' · ' . count($plan['disposals']) . ' disposal record(s)' : ''; ?></td></tr>
                <?php } ?>
            </table>

            <?php if ($plan['problems']) { ?>
                <div class="alert alert-danger small"><strong>This record cannot be deleted yet:</strong><ul class="mb-0 mt-1"><?php foreach ($plan['problems'] as $p) { ?><li><?= $e($p); ?></li><?php } ?></ul></div>
                <a href="<?= $e($back); ?>" class="rm-btn rm-btn-secondary">Back</a>
            <?php } else { ?>
                <div class="alert alert-danger small mb-2">
                    <strong>What deleting it does</strong> (all together, or nothing if any step fails):
                    <ul class="mb-0 mt-1">
                        <li>The <?= $isLoan ? 'loan' : 'asset'; ?> is marked <strong>Deleted</strong>: it leaves every list, balance sheet, report and total. Its full details stay in the audit trail.</li>
                        <?php foreach ($byFund as $fund => $delta) { if (abs($delta) < 0.005) { continue; } ?>
                        <li><?= $e($fund); ?>: <strong><?= $delta > 0 ? '+' : '−'; ?>RWF <?= $m(abs($delta)); ?></strong> <?= $delta > 0 ? '(money comes back)' : '(money goes out)'; ?></li>
                        <?php } ?>
                        <li><?= count($plan['transactions']); ?> linked transaction(s) and <?= count($plan['movements']); ?> fund entr<?= count($plan['movements']) === 1 ? 'y' : 'ies'; ?> are reversed (listed below).</li>
                    </ul>
                </div>
                <details class="mb-3 small" <?= count($plan['transactions']) + count($plan['movements']) <= 8 ? 'open' : ''; ?>><summary>Everything that will be reversed</summary>
                    <table class="table table-sm mt-2 mb-0" style="font-size:12px;">
                        <tr><th>Reference</th><th>What</th><th class="text-end">RWF</th></tr>
                        <?php foreach ($plan['transactions'] as $tx) { ?>
                        <tr><td><?= $e(tx_reference((int) $tx['id'])); ?></td><td><?= $e($tx['why'] . ' — ' . $tx['description']); ?></td><td class="text-end"><?= $m($tx['amount']); ?></td></tr>
                        <?php } ?>
                        <?php foreach ($plan['movements'] as $mv) { ?>
                        <tr><td>Fund entry #<?= (int) $mv['id']; ?></td><td><?= $e($mv['fund'] . ' · ' . $mv['movement_type'] . ' ' . $mv['direction'] . ' — ' . $mv['description']); ?></td><td class="text-end"><?= $m($mv['amount']); ?></td></tr>
                        <?php } ?>
                        <?php if (!$plan['transactions'] && !$plan['movements']) { ?><tr><td colspan="3" class="text-muted">Nothing linked.</td></tr><?php } ?>
                    </table>
                </details>
                <form method="POST">
                    <input type="hidden" name="id" value="<?= (int) $id; ?>">
                    <label class="form-label small fw-semibold text-muted">Reason <span class="text-danger">*</span></label>
                    <select name="reason_choice" class="form-select rm-input mb-2" required data-no-search>
                        <option value="">Choose the reason</option>
                        <?php foreach (EXEC_DELETE_REASONS as $r) { ?><option <?= ($_POST['reason_choice'] ?? '') === $r ? 'selected' : ''; ?>><?= $e($r); ?></option><?php } ?>
                    </select>
                    <textarea name="reason_details" class="form-control rm-input mb-2" rows="3" minlength="10" maxlength="1000" required placeholder="Explain what was wrong with this record (e.g. recorded twice on 12 Oct; the correct one is LN-000007)"><?= $e($_POST['reason_details'] ?? ''); ?></textarea>
                    <label class="form-label small fw-semibold text-muted">Type <strong>DELETE</strong> to confirm</label>
                    <input type="text" name="confirm_text" class="form-control rm-input mb-3" autocomplete="off" required>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="submit" class="rm-btn rm-btn-danger"><i class="bi bi-trash me-1"></i>Delete <?= $e($ref); ?></button>
                        <a href="<?= $e($back); ?>" class="rm-btn rm-btn-secondary">Cancel</a>
                    </div>
                </form>
            <?php } ?>
        </div>
    </div></div>
    <?php
    include __DIR__ . '/footer.php';
}