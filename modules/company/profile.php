<?php
require '../../config/db.php';
require_once '../../includes/sms_helpers.php';
require_role(['Admin']);

$errors = [];
$notice = $_SESSION['profile_notice'] ?? '';
unset($_SESSION['profile_notice']);
if (empty($_SESSION['csrf_profile'])) {
    $_SESSION['csrf_profile'] = bin2hex(random_bytes(32));
}

/** One text field from the form: trimmed, single line, limited in length. */
function profile_text(string $key, int $max): string
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v));
    return mb_substr($v, 0, $max);
}

$row = [];
$res = mysqli_query($conn, 'SELECT * FROM company_profile WHERE id = 1 LIMIT 1');
if ($res) {
    $row = mysqli_fetch_assoc($res) ?: [];
}
$methodRows = [];
$res = mysqli_query($conn, 'SELECT * FROM company_payment_methods WHERE is_active = 1 ORDER BY sort_order, id');
while ($res && ($m = mysqli_fetch_assoc($res))) {
    $methodRows[] = $m;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // the server throws away a form that is bigger than its limit, so without this check the page would just reload in silence
    $errors[] = 'The picture is too large to upload (the server accepts up to ' . ini_get('post_max_size') . '). Please choose a smaller logo.';
}
if (isset($_POST['save'])) {
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf_profile'], $sent)) {
        $errors[] = 'This form has expired. Please reload the page and try again.';
    } else {
        $in = [
            'display_name' => profile_text('display_name', 150),
            'tin'          => profile_text('tin', 30),
            'website'      => profile_text('website', 150),
            'address'      => profile_text('address', 255),
            'phone'        => profile_text('phone', 30),
            'email'        => profile_text('email', 150),
            'momo_phone'   => profile_text('momo_phone', 30),
            'help_phone'   => profile_text('help_phone', 30),
            'invoice_prefix' => strtoupper(profile_text('invoice_prefix', 8)),
        ];
        if ($in['display_name'] === '') {
            $errors[] = 'The business name is required.';
        }
        if ($in['email'] !== '' && !filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'The business email address is not valid.';
        }
        if ($in['tin'] !== '' && !preg_match('/^[A-Za-z0-9\/ -]+$/', $in['tin'])) {
            $errors[] = 'The TIN may only contain letters, digits, spaces, - and /.';
        }
        foreach (['phone' => 'The business phone', 'momo_phone' => 'The MoMo phone', 'help_phone' => 'The help phone'] as $k => $label) {
            if ($in[$k] !== '' && !preg_match('/^\+?[0-9 ]{9,20}$/', $in[$k])) {
                $errors[] = "$label must be a phone number (digits only).";
            }
        }
        if (!preg_match('/^[A-Z0-9]{1,8}$/', $in['invoice_prefix'])) {
            $errors[] = 'The invoice prefix must be 1 to 8 letters or digits, for example INV or ACME.';
        }
        $flags = [
            'sms_credit_reminders' => isset($_POST['sms_credit_reminders']) ? 1 : 0,
            'sms_loyalty_weekly'   => isset($_POST['sms_loyalty_weekly']) ? 1 : 0,
            'auto_credit_charges'  => isset($_POST['auto_credit_charges']) ? 1 : 0,
        ];

        // payment methods: up to 6 rows; an empty row is ignored
        $methods = [];
        $names = $_POST['pm_name'] ?? [];
        $labels = $_POST['pm_label'] ?? [];
        $numbers = $_POST['pm_number'] ?? [];
        $accounts = $_POST['pm_account'] ?? [];
        if (is_array($names) && is_array($numbers)) {
            foreach (array_slice(array_keys($names), 0, 6) as $i) {
                $n = is_string($names[$i] ?? null) ? trim(mb_substr($names[$i], 0, 80)) : '';
                $num = is_string($numbers[$i] ?? null) ? trim(mb_substr($numbers[$i], 0, 60)) : '';
                if ($n === '' && $num === '') {
                    continue;
                }
                if ($n === '' || $num === '') {
                    $errors[] = 'Each payment method needs a name and an account number.';
                    break;
                }
                $methods[] = [
                    $n,
                    is_string($labels[$i] ?? null) ? trim(mb_substr($labels[$i], 0, 60)) : '',
                    $num,
                    is_string($accounts[$i] ?? null) ? trim(mb_substr($accounts[$i], 0, 100)) : '',
                ];
            }
        }

        // the logo
        $logoFile = $row['logo_file'] ?? null;
        if (!$errors && !empty($_POST['remove_logo'])) {
            $logoFile = null;
        }
        if (!$errors && isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $logoFile = company_save_logo($_FILES['logo']);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (!$errors) {
            mysqli_begin_transaction($conn);
            try {
                $st = mysqli_prepare($conn, 'INSERT INTO company_profile (id, display_name, tin, website, address, phone, email, momo_phone, help_phone, invoice_prefix, logo_file, sms_credit_reminders, sms_loyalty_weekly, auto_credit_charges)
                    VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), tin = VALUES(tin), website = VALUES(website), address = VALUES(address), phone = VALUES(phone),
                        email = VALUES(email), momo_phone = VALUES(momo_phone), help_phone = VALUES(help_phone), invoice_prefix = VALUES(invoice_prefix), logo_file = VALUES(logo_file),
                        sms_credit_reminders = VALUES(sms_credit_reminders), sms_loyalty_weekly = VALUES(sms_loyalty_weekly), auto_credit_charges = VALUES(auto_credit_charges)');
                mysqli_stmt_bind_param($st, 'ssssssssssiii', $in['display_name'], $in['tin'], $in['website'], $in['address'], $in['phone'], $in['email'],
                    $in['momo_phone'], $in['help_phone'], $in['invoice_prefix'], $logoFile, $flags['sms_credit_reminders'], $flags['sms_loyalty_weekly'], $flags['auto_credit_charges']);
                mysqli_stmt_execute($st);
                mysqli_query($conn, 'DELETE FROM company_payment_methods');
                $ins = mysqli_prepare($conn, 'INSERT INTO company_payment_methods (method_name, account_label, account_number, account_name, sort_order) VALUES (?, ?, ?, ?, ?)');
                foreach ($methods as $order => [$n, $label, $num, $acc]) {
                    mysqli_stmt_bind_param($ins, 'ssssi', $n, $label, $num, $acc, $order);
                    mysqli_stmt_execute($ins);
                }
                mysqli_commit($conn);
                $_SESSION['profile_notice'] = 'The business profile was saved.';
                header('Location: profile.php');
                exit;
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                error_log('business profile save failed: ' . $e->getMessage());
                $errors[] = 'The profile could not be saved. Please try again.';
            }
        }
        // keep what the person typed
        $row = array_merge($row, $in, $flags);
        $methodRows = array_map(static fn($m) => ['method_name' => $m[0], 'account_label' => $m[1], 'account_number' => $m[2], 'account_name' => $m[3]], $methods);
    }
}

company_profile_reset();
$co = company_profile();
$quota = mm_sms_quota();
$used = sms_month_usage($conn);
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$val = static fn(string $k, string $default = ''): string => $h($row[$k] ?? $default);
if (!$methodRows) {
    $methodRows[] = ['method_name' => '', 'account_label' => '', 'account_number' => '', 'account_name' => ''];
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>
<div class="mb-4">
    <h4 class="mb-1" style="font-weight:800;color:#1E2333">Business profile</h4>
    <p class="text-muted mb-0">This is how <?= $h($co['name']); ?> appears on its invoices, receipts, reports and SMS messages.</p>
</div>

<?php if ($notice !== '') { ?><div class="alert alert-success"><?= $h($notice); ?></div><?php } ?>
<?php if ($errors) { ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e) { echo '<li>' . $h($e) . '</li>'; } ?></ul></div><?php } ?>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <div class="fw-bold" style="font-size:15px">SMS this month</div>
            <div class="text-muted small"><?= $quota === null ? 'Your package has no SMS limit.' : 'Your ' . $h(mm_package_name()) . ' package includes ' . (int) $quota . ' SMS per month. It restarts on the 1st.'; ?></div>
        </div>
        <div class="text-end"><span style="font-size:26px;font-weight:800;color:#1E2333"><?= (int) $used; ?></span><span class="text-muted"> <?= $quota === null ? 'sent' : '/ ' . (int) $quota; ?></span></div>
    </div>
<?php if ($quota !== null) { $pct = min(100, (int) round($used * 100 / max(1, $quota))); ?>
    <div class="progress mt-3" style="height:10px"><div class="progress-bar <?= $pct >= 100 ? 'bg-danger' : ($pct >= 80 ? 'bg-warning' : ''); ?>" style="width:<?= $pct; ?>%"></div></div>
<?php if ($pct >= 100) { ?><div class="text-danger small mt-2">The limit is reached: no more SMS are sent until next month, or until you upgrade the package.</div>
<?php } elseif ($pct >= 80) { ?><div class="text-warning small mt-2">You have used <?= $pct; ?>% of this month's SMS.</div><?php } ?>
<?php } ?>
</div></div>

<form method="POST" enctype="multipart/form-data" autocomplete="off">
<input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf_profile']); ?>">

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <h6 class="fw-bold mb-3">Identity</h6>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Business name</label><input class="form-control rm-input" name="display_name" value="<?= $val('display_name', $co['name']); ?>" maxlength="150" required></div>
        <div class="col-md-3"><label class="form-label">TIN</label><input class="form-control rm-input" name="tin" value="<?= $val('tin'); ?>" maxlength="30"></div>
        <div class="col-md-3"><label class="form-label">Website</label><input class="form-control rm-input" name="website" value="<?= $val('website'); ?>" maxlength="150" placeholder="www.yourcompany.rw"></div>
        <div class="col-md-6"><label class="form-label">Address</label><input class="form-control rm-input" name="address" value="<?= $val('address'); ?>" maxlength="255" placeholder="District, City, Country"></div>
        <div class="col-md-3"><label class="form-label">Phone</label><input class="form-control rm-input" name="phone" value="<?= $val('phone'); ?>" maxlength="30"></div>
        <div class="col-md-3"><label class="form-label">Email</label><input class="form-control rm-input" type="email" name="email" value="<?= $val('email'); ?>" maxlength="150"></div>
    </div>
    <h6 class="fw-bold mt-4 mb-2">Logo</h6>
    <div class="d-flex flex-wrap align-items-center gap-3">
        <img src="<?= $h($co['logo_url']); ?>" alt="Logo" style="height:72px;width:72px;object-fit:contain;border:1px solid #E4E7F0;border-radius:14px;padding:6px;background:#fff">
        <div>
            <input class="form-control" type="file" name="logo" accept="image/png,image/jpeg,image/webp">
            <div class="form-text">JPEG, PNG or WebP, up to 2 MB. It appears on your invoices, receipts, reports and the menu.</div>
<?php if ($co['has_logo']) { ?><label class="form-check mt-1"><input class="form-check-input" type="checkbox" name="remove_logo" value="1"> <span class="form-check-label">Remove my logo</span></label><?php } ?>
        </div>
    </div>
</div></div>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <h6 class="fw-bold mb-3">Invoices and messages</h6>
    <div class="row g-3">
        <div class="col-md-3"><label class="form-label">Invoice prefix</label><input class="form-control rm-input" name="invoice_prefix" value="<?= $val('invoice_prefix', 'INV'); ?>" maxlength="8" required style="text-transform:uppercase"><div class="form-text">Invoice <?= $h($co['invoice_prefix']); ?>000954 starts with it.</div></div>
        <div class="col-md-4"><label class="form-label">MoMo phone for payments</label><input class="form-control rm-input" name="momo_phone" value="<?= $val('momo_phone'); ?>" maxlength="30" placeholder="0788 123 456"><div class="form-text">Printed in the payment reminder SMS.</div></div>
        <div class="col-md-4"><label class="form-label">Help phone <span class="text-muted">(optional)</span></label><input class="form-control rm-input" name="help_phone" value="<?= $val('help_phone'); ?>" maxlength="30"><div class="form-text">Shown in some SMS messages.</div></div>
    </div>
</div></div>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <h6 class="fw-bold mb-1">Payment methods on your invoices</h6>
    <p class="text-muted small">Customers see these on every invoice and receipt. Leave empty to show none.</p>
    <div id="pm-rows">
<?php foreach ($methodRows as $m) { ?>
        <div class="row g-2 mb-2 pm-row">
            <div class="col-md-3"><input class="form-control rm-input" name="pm_name[]" value="<?= $h($m['method_name']); ?>" placeholder="e.g. MTN MoMo Code" maxlength="80"></div>
            <div class="col-md-2"><input class="form-control rm-input" name="pm_label[]" value="<?= $h($m['account_label']); ?>" placeholder="MoMo Code" maxlength="60"></div>
            <div class="col-md-3"><input class="form-control rm-input" name="pm_number[]" value="<?= $h($m['account_number']); ?>" placeholder="Number" maxlength="60"></div>
            <div class="col-md-3"><input class="form-control rm-input" name="pm_account[]" value="<?= $h($m['account_name']); ?>" placeholder="Account name" maxlength="100"></div>
            <div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100 pm-del" title="Remove">&times;</button></div>
        </div>
<?php } ?>
    </div>
    <button type="button" class="btn btn-sm btn-outline-primary" id="pm-add">+ Add a payment method</button>
</div></div>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <h6 class="fw-bold mb-3">Automatic SMS</h6>
    <label class="form-check d-block mb-2"><input class="form-check-input" type="checkbox" name="sms_credit_reminders" value="1" <?= ((int) ($row['sms_credit_reminders'] ?? 1) === 1) ? 'checked' : ''; ?>>
        <span class="form-check-label"><b>Payment reminders.</b> Customers with an unpaid invoice get an SMS about every two days.</span></label>
    <label class="form-check d-block mb-2"><input class="form-check-input" type="checkbox" name="sms_loyalty_weekly" value="1" <?= ((int) ($row['sms_loyalty_weekly'] ?? 0) === 1) ? 'checked' : ''; ?>>
        <span class="form-check-label"><b>Weekly loyalty points message.</b> Uses a lot of SMS if you have many customers.</span></label>
    <label class="form-check d-block"><input class="form-check-input" type="checkbox" name="auto_credit_charges" value="1" <?= ((int) ($row['auto_credit_charges'] ?? 0) === 1) ? 'checked' : ''; ?>>
        <span class="form-check-label"><b>Daily overdue charges.</b> Adds the overdue charge to the balance of late credit invoices every day. <span class="text-danger">This changes what customers owe: switch it on only if you want that.</span></span></label>
</div></div>

<button class="rm-btn rm-btn-primary" type="submit" name="save" value="1">Save the profile</button>
</form>

<script>
(function () {
    var box = document.getElementById('pm-rows');
    document.getElementById('pm-add').addEventListener('click', function () {
        if (box.querySelectorAll('.pm-row').length >= 6) { return; }
        var row = box.querySelector('.pm-row').cloneNode(true);
        row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        box.appendChild(row);
    });
    box.addEventListener('click', function (e) {
        if (!e.target.classList.contains('pm-del')) { return; }
        var rows = box.querySelectorAll('.pm-row');
        if (rows.length > 1) { e.target.closest('.pm-row').remove(); } else { rows[0].querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
    });
})();
</script>
<?php include '../../includes/footer.php'; ?>
