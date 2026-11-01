<?php
// NEW FILE: portal/profile.php — the customer's profile: permitted contact details and password.
require __DIR__ . '/portal_start.php';
$acct = portal_account($conn, $cid);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!portal_csrf_ok()) { $error = 'Your session expired. Please try again.'; }
    elseif ($action === 'contact') {
        $email = trim((string) ($_POST['email'] ?? '')); $address = mb_substr(trim((string) ($_POST['address'] ?? '')), 0, 255);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Enter a valid email address.'; }
        else {
            $ev = $email ?: null; $av = $address ?: null;
            $s = mysqli_prepare($conn, 'UPDATE customers SET email = ?, address = ? WHERE id = ?');
            mysqli_stmt_bind_param($s, 'ssi', $ev, $av, $cid);
            mysqli_stmt_execute($s);
            portal_log($conn, $cid, 'profile_changed', 'Email / address');
            header('Location: profile.php?success=' . urlencode('Your contact details were saved.')); exit;
        }
    } elseif ($action === 'password') {
        $new = (string) ($_POST['new'] ?? '');
        if (!password_verify((string) ($_POST['current'] ?? ''), (string) $acct['password_hash'])) { $error = 'The current password is not right.'; }
        elseif (strlen($new) < 8 || !preg_match('/\d/', $new) || !preg_match('/[A-Za-z]/', $new)) { $error = 'The new password needs at least 8 characters, with letters and numbers.'; }
        elseif ($new !== (string) ($_POST['new2'] ?? '')) { $error = 'The two new passwords are not the same.'; }
        else {
            $h = password_hash($new, PASSWORD_DEFAULT);
            $s = mysqli_prepare($conn, 'UPDATE portal_accounts SET password_hash = ? WHERE customer_id = ?');
            mysqli_stmt_bind_param($s, 'si', $h, $cid);
            mysqli_stmt_execute($s);
            portal_log($conn, $cid, 'password_changed', '');
            header('Location: profile.php?success=' . urlencode('Your password was changed.')); exit;
        }
    }
}
portal_header('Profile', $me);
portal_alerts();
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= portal_e($error); ?></div><?php } ?>
<div class="row g-3">
<div class="col-md-6"><div class="card"><div class="card-body">
    <h1 class="h6">My details</h1>
    <div class="small mb-3"><strong><?= portal_e($me['name']); ?></strong> · <?= portal_customer_code($cid); ?><br>Phone: <?= portal_e($me['phone']); ?>
        <?= $acct['phone_verified_at'] ? '<span class="badge bg-success">verified</span>' : ''; ?><br>
        <span class="text-muted">To change your name or phone number, send a request to RISE MOTIVE.</span></div>
    <form method="POST"><?= portal_csrf_field(); ?><input type="hidden" name="action" value="contact">
        <label class="form-label small text-muted">Email</label><input type="email" name="email" class="form-control mb-2" value="<?= portal_e($me['email'] ?? ''); ?>">
        <label class="form-label small text-muted">Address</label><input name="address" class="form-control mb-2" maxlength="255" value="<?= portal_e($me['address'] ?? ''); ?>">
        <button class="btn btn-primary btn-sm">Save</button></form>
</div></div></div>
<div class="col-md-6"><div class="card"><div class="card-body">
    <h2 class="h6">Change password</h2>
    <form method="POST"><?= portal_csrf_field(); ?><input type="hidden" name="action" value="password">
        <input type="password" name="current" class="form-control mb-2" required placeholder="Current password" autocomplete="current-password">
        <input type="password" name="new" class="form-control mb-2" required minlength="8" placeholder="New password" autocomplete="new-password">
        <input type="password" name="new2" class="form-control mb-2" required minlength="8" placeholder="Repeat the new password" autocomplete="new-password">
        <button class="btn btn-primary btn-sm">Change password</button></form>
</div></div></div>
</div>
<?php portal_footer();