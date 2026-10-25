<?php
require_once __DIR__ . '/../includes/platform_web.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/features.php';
require_once __DIR__ . '/../includes/subscriptions.php';
require_once __DIR__ . '/../includes/business_delete.php';

$admin = platform_require_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    $companyId = (is_string($_POST['company_id'] ?? null) && ctype_digit($_POST['company_id'])) ? (int) $_POST['company_id'] : 0;
    $to = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
    $newPackage = is_string($_POST['package'] ?? null) ? $_POST['package'] : '';
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $text = static fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    try {
        if ($action === 'renew') {
            $end = company_record_renewal($companyId, $text('method'), $text('reference'), (int) preg_replace('/[^0-9]/', '', $text('amount')), $admin['email']);
            platform_flash('Renewal recorded. The business is active until ' . $end . '. The owner was emailed a receipt.');
        } elseif ($action === 'delete') {
            company_request_delete($companyId, $text('confirm_code'), $admin['email']);
            platform_flash('The business is being deleted. It disappears from this list within a minute.');
        } elseif ($newPackage !== '') {
            if ($companyId === 1 || !in_array($newPackage, ['dream', 'hasty'], true)) {
                throw new SignupException('That package change is not allowed.');
            }
            company_set_package($companyId, $newPackage, 'admin:' . $admin['email']);
            platform_flash('The business is now on the ' . $newPackage . ' package.');
        } else {
            company_set_status($companyId, $to, $admin['email']);
            platform_flash('The business is now ' . $to . '.');
        }
    } catch (SignupException $e) {
        platform_flash($e->getMessage(), 'danger');
    } catch (RuntimeException $e) {
        platform_flash($e->getMessage(), 'danger');
    }
    header('Location: companies.php');
    exit;
}
$rows = signup_companies();
platform_page_start('Businesses', $admin, 'companies');
?>
<h4>Businesses</h4>
<div class="card p-4"><div class="table-responsive"><table class="table align-middle">
<thead><tr><th>Code</th><th>Business</th><th>Owner</th><th>Package</th><th>Subscription</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r) {
    $own = (int) $r['id'] === 1;
    $days = $r['ends_at'] ? (int) floor((strtotime($r['ends_at']) - strtotime(date('Y-m-d'))) / 86400) : null;
    $sub = $own ? null : subscription_latest((int) $r['id']);
    $due = $sub ? subscription_renewal_amount($sub) : 0; ?>
<tr>
    <td><b><?= h($r['company_code']); ?></b></td>
    <td><div style="font-weight:700;color:var(--mm-ink)"><?= h($r['name']); ?></div><?php if (!empty($r['status_note'])) { ?><div class="small text-danger"><?= h($r['status_note']); ?></div><?php } ?></td>
    <td class="small"><?= h($r['owner_email'] ?? '-'); ?></td>
    <td><?= h($r['package_name'] ?? '-'); ?></td>
    <td><?php if ($own) { ?><span class="text-muted small">never expires</span><?php } elseif ($days === null) { ?>-<?php } else { ?>
        <span class="mma-days <?= $days < 0 ? 'over' : ($days <= 7 ? 'soon' : ''); ?>"><?= $days < 0 ? 'ended ' . abs($days) . ' day(s) ago' : $days . ' day(s) left'; ?></span><div class="small text-muted mt-1"><?= h($r['ends_at']); ?></div><?php } ?></td>
    <td><span class="badge text-bg-<?= $r['status'] === 'active' ? 'success' : 'danger'; ?>"><?= h($r['status']); ?></span></td>
    <td class="text-end" style="min-width:230px">
<?php if (!$own) { ?>
        <details class="text-start mb-2"><summary class="text-danger">Delete this business</summary>
            <form method="POST" class="border rounded-3 p-3 bg-white" onsubmit="return confirm('Delete <?= h($r['company_code']); ?> for good? Its database and all its data will be removed.');"><?= platform_csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="company_id" value="<?= (int) $r['id']; ?>">
                <div class="small text-muted mb-2">This removes the business, its database and its logo for good. Payments stay on record. It cannot be undone.</div>
                <label class="form-label small mb-1">Type <b><?= h($r['company_code']); ?></b> to confirm</label><input class="form-control form-control-sm mb-2" name="confirm_code" autocomplete="off" required>
                <button class="btn btn-sm btn-danger w-100" type="submit">Delete for good</button>
            </form>
        </details>
<?php } ?>
<?php if ($own) { ?><small class="text-muted">your own business</small>
<?php } else { ?>
        <form method="POST" class="d-inline"><?= platform_csrf_field(); ?><input type="hidden" name="company_id" value="<?= (int) $r['id']; ?>"><select name="package" class="form-select form-select-sm d-inline-block" style="width:auto" onchange="this.form.submit()"><?php foreach (['dream' => 'Dream', 'hasty' => 'Hasty'] as $pc => $pn) { echo '<option value="' . h($pc) . '"' . (($r['package_name'] ?? '') === $pn ? ' selected' : '') . ($pn === ($r['package_name'] ?? '') ? '' : '') . '>' . h($pn) . '</option>'; } ?><option value="" <?= !in_array($r['package_name'] ?? '', ['Dream', 'Hasty'], true) ? 'selected' : 'hidden'; ?>>Package</option></select></form>
<?php $next = $r['status'] === 'active' ? 'suspended' : 'active'; ?>
        <form method="POST" class="d-inline"><?= platform_csrf_field(); ?><input type="hidden" name="company_id" value="<?= (int) $r['id']; ?>"><input type="hidden" name="status" value="<?= h($next); ?>"><button class="btn btn-sm btn-outline-<?= $next === 'suspended' ? 'danger' : 'success'; ?>" type="submit"><?= $next === 'suspended' ? 'Suspend' : 'Re-activate'; ?></button></form>
<?php if ($sub) { ?>
        <details class="text-start mt-2"><summary>Record a renewal</summary>
            <form method="POST" class="border rounded-3 p-3 bg-white"><?= platform_csrf_field(); ?><input type="hidden" name="action" value="renew"><input type="hidden" name="company_id" value="<?= (int) $r['id']; ?>">
                <label class="form-label small mb-1">How did they pay?</label>
                <select class="form-select form-select-sm mb-2" name="method"><?php foreach (SIGNUP_PAYMENT_METHODS as $m) { echo '<option>' . h($m) . '</option>'; } ?></select>
                <label class="form-label small mb-1">Payment reference</label><input class="form-control form-control-sm mb-2" name="reference" maxlength="100" required>
                <label class="form-label small mb-1">Amount received (RWF)</label><input class="form-control form-control-sm mb-2" name="amount" value="<?= (int) $due; ?>" inputmode="numeric" required>
                <button class="btn btn-sm btn-primary w-100" type="submit">Record renewal</button>
                <div class="small text-muted mt-2">One renewal is <?= h(signup_money($due)); ?>. The new end date counts from the current end date.</div>
            </form>
        </details>
<?php } ?>
<?php } ?>
    </td>
</tr>
<?php } ?>
</tbody></table></div></div>
<?php platform_page_end();
