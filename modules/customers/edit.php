<?php
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../../includes/session_boot.php'; rm_session_start(); /* CHANGED: one staff session (RMSTAFF) */
}
require '../../config/db.php';
require '../../includes/notification_helper.php';
// CHANGED (Task 5): only Admins and Managers may edit customer records. Employees are refused here,
// on the server, so a direct address or a manually sent form cannot get around it.
require_role(['Admin', 'Manager']);
require_once '../../includes/customer_name_helpers.php'; // NEW (Task 2A): First / Last Name
customer_names_ensure($conn);

// CHANGED: problems are sent as "error" (they were sent as "success"), and messages are urlencoded.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid customer selected.')); exit; }
$statement = mysqli_prepare($conn, 'SELECT * FROM customers WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$customer = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$customer) { header('Location: index.php?error=' . urlencode('Customer not found.')); exit; }

if (isset($_POST['update'])) {
    // CHANGED (Task 2A): First Name and Last Name; the full name is kept for every page that shows it.
    $firstName = mb_substr(trim($_POST['first_name'] ?? ''), 0, 80);
    $lastName = mb_substr(trim($_POST['last_name'] ?? ''), 0, 80);
    $name = customer_full_name($firstName, $lastName);
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $district = trim($_POST['district'] ?? '');
    $sector = trim($_POST['sector'] ?? '');
    $address = trim($_POST['address'] ?? '');

    // NEW: the same phone rules as Add Customer. Before, ANY phone (or none) was saved here,
    // e.g. 079999999999 (12 digits), and such customers could never receive an SMS.
    $dup = null;
    if (preg_match('/^07\d{8}$/', $phone)) {
        $dupStatement = mysqli_prepare($conn, 'SELECT id, name FROM customers WHERE phone = ? AND id <> ?');
        mysqli_stmt_bind_param($dupStatement, 'si', $phone, $id);
        mysqli_stmt_execute($dupStatement);
        $dup = mysqli_fetch_assoc(mysqli_stmt_get_result($dupStatement));
    }

    if ($firstName === '') { $error = 'The First Name is required.'; }
    elseif ($phone === '') { $error = 'Phone number is required.'; }
    elseif (!preg_match('/^\d{10}$/', $phone)) { $error = 'Phone number must be exactly 10 digits.'; }
    elseif (strpos($phone, '07') !== 0) { $error = 'Please enter a mobile number starting with 07 (e.g. 0788123456).'; }
    elseif ($dup) { $error = 'This phone number already belongs to another customer (' . $dup['name'] . ').'; }
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Please enter a valid email address.'; }
    elseif ($province === '' || $district === '' || $sector === '') { $error = 'Please select Province, District, and Sector.'; }
    else {
        $lastValue = $lastName !== '' ? $lastName : null;
        $statement = mysqli_prepare($conn, 'UPDATE customers SET name = ?, first_name = ?, last_name = ?, phone = ?, email = ?, province = ?, district = ?, sector = ?, address = ? WHERE id = ?');
        mysqli_stmt_bind_param($statement, 'sssssssssi', $name, $firstName, $lastValue, $phone, $email, $province, $district, $sector, $address, $id);
        mysqli_stmt_execute($statement);

        $editorName = $_SESSION['user_name'] ?? 'A user';
        notify(
            $conn,
            'Customer Updated',
            $editorName . ' updated customer "' . $name . '".'
        );

        header('Location: index.php?success=' . urlencode('Customer updated successfully.')); exit;
    }
    $customer = array_merge($customer, ['name' => $name, 'first_name' => $firstName, 'last_name' => $lastName, 'phone' => $phone, 'email' => $email, 'province' => $province, 'district' => $district, 'sector' => $sector, 'address' => $address]);
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
$modal_icon = 'bi-person-gear'; $modal_title = 'Edit Customer'; $modal_subtitle = 'Update this customer\'s details.';
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div class="rm-modal-backdrop"><div class="rm-modal">
    <?php include '../../includes/model_header.php'; ?>
    <div class="rm-modal-body">
        <?php if (isset($error)) { ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;"><i class="bi bi-exclamation-circle-fill"></i>
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
        <form method="POST">
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">First Name <span class="text-danger">*</span></label>
                    <input type="text" name="first_name" maxlength="80" class="form-control rm-input" value="<?= htmlspecialchars($customer['first_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Last Name</label>
                    <input type="text" name="last_name" maxlength="80" class="form-control rm-input" value="<?= htmlspecialchars($customer['last_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Phone</label>
                    <!-- CHANGED: same rules as Add Customer (10 digits, starting with 07, required). -->
                    <input type="text" name="phone" class="form-control rm-input" value="<?= htmlspecialchars($customer['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" inputmode="numeric" pattern="07\d{8}" maxlength="10" placeholder="0788888888" title="A 10-digit mobile number starting with 07" required>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Email</label>
                    <input type="email" name="email" class="form-control rm-input" value="<?= htmlspecialchars($customer['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <label class="form-label small fw-semibold text-muted">Location</label>
            <div class="row g-3 mb-3">
                <div class="col-4">
                    <select name="province" id="provinceSelect" class="form-select rm-input" required>
                        <option value="">Select province</option>
                    </select>
                </div>
                <div class="col-4">
                    <select name="district" id="districtSelect" class="form-select rm-input" required disabled>
                        <option value="">Select district</option>
                    </select>
                </div>
                <div class="col-4">
                    <select name="sector" id="sectorSelect" class="form-select rm-input" required disabled>
                        <option value="">Select sector</option>
                    </select>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label small fw-semibold text-muted">Address (optional detail e.g. landmark, street)</label>
                <textarea name="address" class="form-control rm-input" rows="3" style="height:auto;"><?= htmlspecialchars($customer['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                <button class="rm-btn rm-btn-primary" type="submit" name="update">
                    <i class="bi bi-check-circle-fill me-2"></i>Update Customer</button>
                <a href="index.php" class="rm-btn rm-btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div></div>

<?php /* NEW: original registration branch (permanent; every branch can still serve this customer) */
require_once '../../includes/customer_registration.php';
$crRec = cr_get($conn, (int) $id);
if ($crRec) {
    if (empty($_SESSION['cr_csrf'])) { $_SESSION['cr_csrf'] = bin2hex(random_bytes(16)); }
    $crBranches = br_enabled($conn) ? br_all($conn) : [];
    $crH = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }; ?>
<div class="card border-0 shadow-sm mt-3" id="origin" style="max-width:900px;"><div class="card-body">
    <div class="d-flex justify-content-between"><h6 class="mb-1">Original registration</h6><a class="small" href="registrations.php">Report</a></div>
    <div class="small"><span class="text-muted">Branch:</span> <strong><?= $crH($crRec['branch_id'] !== null ? cr_branch_name($conn, (int) $crRec['branch_id']) : 'Not established'); ?></strong>
        <?= $crRec['status'] === 'review' ? ' <span class="badge bg-warning text-dark">to review</span>' . ($crRec['suggested_branch_id'] ? ' <span class="text-muted">(suggestion: ' . $crH(cr_branch_name($conn, (int) $crRec['suggested_branch_id'])) . ')</span>' : '') : ''; ?>
        · <span class="text-muted">Date:</span> <?= $crH($crRec['registered_at'] ? substr($crRec['registered_at'], 0, 16) : '—'); ?>
        · <span class="text-muted">Registered by:</span> <?= $crH($crRec['registered_by_name'] ?: '—'); ?></div>
    <div class="small text-muted"><?= $crH($crRec['basis']); ?>. It never changes when the customer is served at another branch.</div>
    <?php if (current_user_role() === 'Admin' && $crBranches) { ?>
    <form method="POST" action="registrations.php" class="row g-2 mt-1"><input type="hidden" name="csrf" value="<?= $crH($_SESSION['cr_csrf']); ?>"><input type="hidden" name="do" value="set"><input type="hidden" name="customer_id" value="<?= (int) $id; ?>"><input type="hidden" name="back" value="edit.php?id=<?= (int) $id; ?>">
        <div class="col-md-3"><select name="branch_id" class="form-select form-select-sm"><?php foreach ($crBranches as $bid => $b) { ?><option value="<?= (int) $bid; ?>" <?= (int) ($crRec['branch_id'] ?? $crRec['suggested_branch_id']) === (int) $bid ? 'selected' : ''; ?>><?= $crH($b['name']); ?></option><?php } ?></select></div>
        <div class="col-md-2"><input type="date" name="date" class="form-control form-control-sm" value="<?= $crH(substr((string) $crRec['registered_at'], 0, 10)); ?>"></div>
        <div class="col-md-5"><input name="reason" class="form-control form-control-sm" placeholder="Reason / source of the information (required)" required></div>
        <div class="col-md-2"><button name="mode" value="<?= $crRec['status'] === 'review' ? 'confirm' : 'correct'; ?>" class="btn btn-sm btn-outline-primary w-100" onclick="return confirm('Change the permanent original registration record?');"><?= $crRec['status'] === 'review' ? 'Confirm' : 'Correct'; ?></button></div>
    </form><?php } ?>
</div></div>
<?php } ?>

<script src="../../includes/rwanda_locations.js"></script>
<script>
(function () {
    var provinceSelect = document.getElementById('provinceSelect');
    var districtSelect = document.getElementById('districtSelect');
    var sectorSelect = document.getElementById('sectorSelect');

    // CHANGED: JSON_HEX_* so a stored or typed value can never close this <script> tag.
    var selectedProvince = <?= json_encode($customer['province'] ?? '', $jsonFlags); ?>;
    var selectedDistrict = <?= json_encode($customer['district'] ?? '', $jsonFlags); ?>;
    var selectedSector = <?= json_encode($customer['sector'] ?? '', $jsonFlags); ?>;

    function fillSelect(select, options, selectedValue, placeholder) {
        select.innerHTML = '<option value="">' + placeholder + '</option>';
        options.forEach(function (opt) {
            var el = document.createElement('option');
            el.value = opt;
            el.textContent = opt;
            if (opt === selectedValue) { el.selected = true; }
            select.appendChild(el);
        });
    }

    function populateProvinces() {
        fillSelect(provinceSelect, Object.keys(RWANDA_LOCATIONS), selectedProvince, 'Select province');
    }

    function populateDistricts() {
        var districts = provinceSelect.value ? Object.keys(RWANDA_LOCATIONS[provinceSelect.value] || {}) : [];
        districtSelect.disabled = districts.length === 0;
        fillSelect(districtSelect, districts, selectedDistrict, 'Select district');
    }

    function populateSectors() {
        var sectors = (provinceSelect.value && districtSelect.value)
            ? (RWANDA_LOCATIONS[provinceSelect.value][districtSelect.value] || [])
            : [];
        sectorSelect.disabled = sectors.length === 0;
        fillSelect(sectorSelect, sectors, selectedSector, 'Select sector');
    }

    provinceSelect.addEventListener('change', function () {
        selectedDistrict = '';
        selectedSector = '';
        populateDistricts();
        populateSectors();
    });

    districtSelect.addEventListener('change', function () {
        selectedSector = '';
        populateSectors();
    });

    populateProvinces();
    populateDistricts();
    populateSectors();
})();
</script>

<?php include '../../includes/footer.php'; ?>