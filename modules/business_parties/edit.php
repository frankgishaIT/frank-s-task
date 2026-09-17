<?php
require '../../config/db.php';
require_role(['Admin', 'Manager']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid business party.'); exit; }

$statement = mysqli_prepare($conn, 'SELECT * FROM business_parties WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$party = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$party) { header('Location: index.php?success=Business party not found.'); exit; }

$type = $party['type']; // type is fixed at creation, not editable
$tin = $party['tin']; $businessName = $party['business_name']; $representativeName = $party['representative_name'];
$name = $party['name']; $phone = $party['phone']; $email = $party['email'];
$paymentAccountType = $party['payment_account_type']; $paymentAccountDetails = $party['payment_account_details'];
$province = $party['province']; $district = $party['district']; $sector = $party['sector'];

if (isset($_POST['save'])) {
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $district = trim($_POST['district'] ?? '');
    $sector = trim($_POST['sector'] ?? '');
    $tin = trim($_POST['tin'] ?? '');
    $businessName = trim($_POST['business_name'] ?? '');
    $representativeName = trim($_POST['representative_name'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $paymentAccountType = $_POST['payment_account_type'] ?? '';
    $paymentAccountDetails = trim($_POST['payment_account_details'] ?? '');

    if ($phone === '' || !preg_match('/^\d{10}$/', $phone)) {
        $error = 'Phone number is required and must be exactly 10 digits.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($province === '' || $district === '' || $sector === '') {
        $error = 'Please select Province, District, and Sector.';
    } elseif ($type === 'Supplier' && ($tin === '' || $businessName === '' || $representativeName === '')) {
        $error = 'TIN, Business Name, and Representative Name are required for an RM Supplier.';
    } elseif (in_array($type, ['Payee', 'Partner'], true) && $name === '') {
        $error = 'Name is required.';
    } elseif ($type === 'Payee' && (!in_array($paymentAccountType, ['Bank', 'Phone'], true) || $paymentAccountDetails === '')) {
        $error = 'Payment Account type and details are required for an RM Payee.';
    } else {
        $tinValue = $type === 'Supplier' ? $tin : null;
        $businessNameValue = $type === 'Supplier' ? $businessName : null;
        $representativeNameValue = $type === 'Supplier' ? $representativeName : null;
        $nameValue = in_array($type, ['Payee', 'Partner'], true) ? $name : null;
        $paymentAccountTypeValue = $type === 'Payee' ? $paymentAccountType : null;
        $paymentAccountDetailsValue = $type === 'Payee' ? $paymentAccountDetails : null;

        $update = mysqli_prepare($conn, 'UPDATE business_parties SET
            tin = ?, business_name = ?, representative_name = ?, name = ?, phone = ?, email = ?,
            payment_account_type = ?, payment_account_details = ?, province = ?, district = ?, sector = ?
            WHERE id = ?');
        mysqli_stmt_bind_param($update, 'sssssssssssi',
            $tinValue, $businessNameValue, $representativeNameValue, $nameValue, $phone, $email,
            $paymentAccountTypeValue, $paymentAccountDetailsValue, $province, $district, $sector, $id);

        if (mysqli_stmt_execute($update)) {
            header('Location: index.php?success=Business party updated successfully.'); exit;
        }
        $error = 'Unable to update the business party.';
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
$modal_icon = 'bi-briefcase-fill'; $modal_title = 'Edit Business Party'; $modal_subtitle = 'RM ' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
?>
<div class="rm-modal-backdrop"><div class="rm-modal">
    <?php include '../../includes/model_header.php'; ?>
    <div class="rm-modal-body">
        <?php if (isset($error)) { ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php } ?>
        <form method="POST">
            <?php if ($type === 'Supplier') { ?>
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">TIN</label>
                    <input type="text" name="tin" class="form-control rm-input" value="<?= htmlspecialchars($tin ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Business Name</label>
                    <input type="text" name="business_name" class="form-control rm-input" value="<?= htmlspecialchars($businessName ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">Representative Name</label>
                <input type="text" name="representative_name" class="form-control rm-input" value="<?= htmlspecialchars($representativeName ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <?php } ?>

            <?php if (in_array($type, ['Payee', 'Partner'], true)) { ?>
            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">Name</label>
                <input type="text" name="name" class="form-control rm-input" value="<?= htmlspecialchars($name ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <?php } ?>

            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Phone</label>
                    <input type="text" name="phone" class="form-control rm-input" value="<?= htmlspecialchars($phone ?? '', ENT_QUOTES, 'UTF-8'); ?>" inputmode="numeric" pattern="\d{10}" maxlength="10" required>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Email</label>
                    <input type="email" name="email" class="form-control rm-input" value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <?php if ($type === 'Payee') { ?>
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Payment Account</label>
                    <select name="payment_account_type" class="form-select rm-input" required>
                        <option value="Bank" <?= $paymentAccountType === 'Bank' ? 'selected' : ''; ?>>Bank</option>
                        <option value="Phone" <?= $paymentAccountType === 'Phone' ? 'selected' : ''; ?>>Phone (Mobile Money)</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Account Number / Phone</label>
                    <input type="text" name="payment_account_details" class="form-control rm-input" value="<?= htmlspecialchars($paymentAccountDetails ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
            </div>
            <?php } ?>

            <label class="form-label small fw-semibold text-muted">Location</label>
            <div class="row g-3 mb-4">
                <div class="col-4"><select name="province" id="provinceSelect" class="form-select rm-input" required><option value="">Select province</option></select></div>
                <div class="col-4"><select name="district" id="districtSelect" class="form-select rm-input" required disabled><option value="">Select district</option></select></div>
                <div class="col-4"><select name="sector" id="sectorSelect" class="form-select rm-input" required disabled><option value="">Select sector</option></select></div>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                <button type="submit" name="save" class="rm-btn rm-btn-primary"><i class="bi bi-check-circle-fill me-2"></i>Save Changes</button>
                <a href="index.php" class="rm-btn rm-btn-secondary"><i class="bi bi-x-circle-fill me-2"></i>Cancel</a>
            </div>
        </form>
    </div>
</div></div>

<script src="../../includes/rwanda_locations.js"></script>
<script>
(function () {
    var provinceSelect = document.getElementById('provinceSelect');
    var districtSelect = document.getElementById('districtSelect');
    var sectorSelect = document.getElementById('sectorSelect');
    var selectedProvince = <?= json_encode($province ?? '', JSON_UNESCAPED_UNICODE); ?>;
    var selectedDistrict = <?= json_encode($district ?? '', JSON_UNESCAPED_UNICODE); ?>;
    var selectedSector = <?= json_encode($sector ?? '', JSON_UNESCAPED_UNICODE); ?>;

    function fillSelect(select, options, selectedValue, placeholder) {
        select.innerHTML = '<option value="">' + placeholder + '</option>';
        options.forEach(function (opt) {
            var el = document.createElement('option');
            el.value = opt; el.textContent = opt;
            if (opt === selectedValue) { el.selected = true; }
            select.appendChild(el);
        });
    }
    function populateProvinces() { fillSelect(provinceSelect, Object.keys(RWANDA_LOCATIONS), selectedProvince, 'Select province'); }
    function populateDistricts() {
        var districts = provinceSelect.value ? Object.keys(RWANDA_LOCATIONS[provinceSelect.value] || {}) : [];
        districtSelect.disabled = districts.length === 0;
        fillSelect(districtSelect, districts, selectedDistrict, 'Select district');
    }
    function populateSectors() {
        var sectors = (provinceSelect.value && districtSelect.value) ? (RWANDA_LOCATIONS[provinceSelect.value][districtSelect.value] || []) : [];
        sectorSelect.disabled = sectors.length === 0;
        fillSelect(sectorSelect, sectors, selectedSector, 'Select sector');
    }
    provinceSelect.addEventListener('change', function () { selectedDistrict = ''; selectedSector = ''; populateDistricts(); populateSectors(); });
    districtSelect.addEventListener('change', function () { selectedSector = ''; populateSectors(); });
    populateProvinces(); populateDistricts(); populateSectors();
})();
</script>
<?php include '../../includes/footer.php'; ?>