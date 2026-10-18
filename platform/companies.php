<?php
require_once __DIR__ . '/../includes/platform_web.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/features.php';

$admin = platform_require_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    $companyId = (is_string($_POST['company_id'] ?? null) && ctype_digit($_POST['company_id'])) ? (int) $_POST['company_id'] : 0;
    $to = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
    $newPackage = is_string($_POST['package'] ?? null) ? $_POST['package'] : '';
    try {
        if ($newPackage !== '') {
            if ($companyId === 1 || !in_array($newPackage, ['dream', 'hasty'], true)) {
                throw new SignupException('That package change is not allowed.');
            }
            company_set_package($companyId, $newPackage, 'admin:' . $admin['email']);
            platform_flash('The company is now on the ' . $newPackage . ' package.');
        } else {
            company_set_status($companyId, $to, $admin['email']);
            platform_flash('The company is now ' . $to . '.');
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
platform_page_start('Companies', $admin, 'companies');
?>
<h4 class="mb-3">Companies</h4>
<div class="card p-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
<thead><tr><th>Code</th><th>Name</th><th>Owner</th><th>Package</th><th>Renews on</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r) { ?>
<tr><td><b><?= h($r['company_code']); ?></b></td><td><?= h($r['name']); ?></td><td><?= h($r['owner_email'] ?? '-'); ?></td><td><?= h($r['package_name'] ?? '-'); ?></td><td><?= h($r['ends_at'] ?? '-'); ?></td>
<td><span class="badge bg-<?= $r['status'] === 'active' ? 'success' : 'danger'; ?>"><?= h($r['status']); ?></span></td>
<td class="text-end">
<?php if ((int) $r['id'] === 1) { ?><small class="text-muted">your own company</small>
<?php } else { ?>
<form method="POST" class="d-inline"><?= platform_csrf_field(); ?><input type="hidden" name="company_id" value="<?= (int) $r['id']; ?>"><select name="package" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()"><option value="">Package...</option><option value="dream">Dream</option><option value="hasty">Hasty</option></select></form>
<?php $next = $r['status'] === 'active' ? 'suspended' : 'active'; ?>
<form method="POST" class="d-inline"><?= platform_csrf_field(); ?><input type="hidden" name="company_id" value="<?= (int) $r['id']; ?>"><input type="hidden" name="status" value="<?= h($next); ?>"><button class="btn btn-sm btn-outline-<?= $next === 'suspended' ? 'danger' : 'success'; ?>" type="submit"><?= $next === 'suspended' ? 'Suspend' : 'Re-activate'; ?></button></form>
<?php } ?>
</td></tr>
<?php } ?>
</tbody></table></div></div>
<?php platform_page_end();
