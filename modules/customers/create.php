<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
if (isset($_POST['save'])) {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? ''); 
    $email = trim($_POST['email'] ?? ''); 
    $address = trim($_POST['address'] ?? '');
    if ($name === '') { $error = 'Customer name is required.'; }
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
         $error = 'Please enter a valid email address.'; }
    else {
        $statement = mysqli_prepare($conn, 'INSERT INTO customers (name, phone, email, address) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($statement, 'ssss', $name, $phone, $email, $address);
        if (mysqli_stmt_execute($statement)) { 
            header('Location: index.php?success=Customer added successfully.'); exit; }
        $error = 'Unable to add customer.';
    }
}
include '../../includes/header.php'; 
include '../../includes/sidebar.php';
$modal_icon = 'bi-person-plus-fill'; 
$modal_title = 'Add Customer'; 
$modal_subtitle = 'Create a new customer profile.';
?>
<div class="rm-modal-backdrop">
    <div class="rm-modal">
    <?php include '../../includes/model_header.php'; ?>
    <div class="rm-modal-body">
        <?php if (isset($error)) { ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;"><i class="bi bi-exclamation-circle-fill"></i><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
        <form method="POST">
            <div class="mb-3"><label class="form-label small fw-semibold text-muted">Customer Name</label><input type="text" name="name" class="form-control rm-input" value="<?= htmlspecialchars($name ?? '', ENT_QUOTES, 'UTF-8'); ?>" required></div>
            <div class="row g-3 mb-3">
                <div class="col-6"><label class="form-label small fw-semibold text-muted">Phone</label><input type="text" name="phone" class="form-control rm-input" value="<?= htmlspecialchars($phone ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="col-6"><label class="form-label small fw-semibold text-muted">Email</label><input type="email" name="email" class="form-control rm-input" value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
            </div>
            <div class="mb-4"><label class="form-label small fw-semibold text-muted">Address</label><textarea name="address" class="form-control rm-input" rows="3" style="height:auto;"><?= htmlspecialchars($address ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea></div>
            <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                <button type="submit" name="save" class="rm-btn rm-btn-primary">
                    <i class="bi bi-check-circle-fill me-2"></i>Save Customer</button>
                <a href="index.php" class="rm-btn rm-btn-secondary">
                    <i class="bi bi-x-circle-fill me-2"></i>Cancel
                </a>
            </div>
        </form>
    </div>
</div></div>
<?php include '../../includes/footer.php'; ?>
