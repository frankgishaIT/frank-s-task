<?php
require_once __DIR__ . '/../includes/platform_web.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/admin_profile.php';

$admin = platform_require_admin();
$id = (int) $admin['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    $do = is_string($_POST['do'] ?? null) ? $_POST['do'] : '';
    $text = static fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    $raw = static fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    $db = platform_conn();
    $run = static function (string $sql, string $types, ...$vals) use ($db): void {
        $st = mysqli_prepare($db, $sql);
        mysqli_stmt_bind_param($st, $types, ...$vals);
        mysqli_stmt_execute($st);
    };
    try {
        if ($do === 'details') {
            $name = $text('name');
            $email = strtolower($text('email'));
            $phone = $text('phone');
            $title = $text('job_title');
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                throw new RuntimeException('Please enter your name (2 to 120 letters).');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
                throw new RuntimeException('That email address is not valid.');
            }
            if ($phone !== '' && !preg_match('/^\+?[0-9 ]{7,20}$/', $phone)) {
                throw new RuntimeException('The phone number must be digits only (it may start with +).');
            }
            if (mb_strlen($title) > 80) {
                throw new RuntimeException('The job title is too long (80 letters at most).');
            }
            if ($email !== strtolower($admin['email'])) {
                if (!password_verify($raw('current_password'), $admin['password_hash'])) {
                    throw new RuntimeException('To change your email, type your current password correctly.');
                }
                $st = mysqli_prepare($db, 'SELECT id FROM platform_admins WHERE email = ? AND id <> ? LIMIT 1');
                mysqli_stmt_bind_param($st, 'si', $email, $id);
                mysqli_stmt_execute($st);
                if (mysqli_fetch_row(mysqli_stmt_get_result($st))) {
                    throw new RuntimeException('Another admin already uses that email address.');
                }
                platform_audit(null, 'admin:' . $admin['email'], 'admin_email_changed', $admin['email'] . ' -> ' . $email);
            }
            $run('UPDATE platform_admins SET name = ?, email = ?, phone = ?, job_title = ? WHERE id = ?', 'ssssi', $name, $email, $phone, $title, $id);
            platform_flash('Your details were saved.');
        } elseif ($do === 'password') {
            if (!password_verify($raw('current_password'), $admin['password_hash'])) {
                throw new RuntimeException('Your current password is not correct.');
            }
            $new = $raw('new_password');
            if ($new !== $raw('confirm_password')) {
                throw new RuntimeException('The two new passwords are not the same.');
            }
            $problem = signup_password_problem($new, $admin['email']);
            if ($problem !== null) {
                throw new RuntimeException($problem);
            }
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $run('UPDATE platform_admins SET password_hash = ?, must_change_password = 0 WHERE id = ?', 'si', $hash, $id);
            session_regenerate_id(true);
            platform_audit(null, 'admin:' . $admin['email'], 'admin_password_changed', '');
            platform_flash('Your password was changed.');
        } elseif ($do === 'avatar') {
            $file = admin_save_avatar($id, $_FILES['avatar'] ?? []);
            $run('UPDATE platform_admins SET avatar_file = ? WHERE id = ?', 'si', $file, $id);
            platform_flash('Your picture was saved.');
        } elseif ($do === 'avatar_remove') {
            admin_remove_avatar($id);
            $none = null;
            $run('UPDATE platform_admins SET avatar_file = ? WHERE id = ?', 'si', $none, $id);
            platform_flash('Your picture was removed.');
        }
    } catch (RuntimeException $e) {
        platform_flash($e->getMessage(), 'danger');
    }
    header('Location: profile.php');
    exit;
}

$avatar = admin_avatar_url($admin);
platform_page_start('My profile', $admin, 'profile');
?>
<h4>My profile</h4>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card p-4 text-center">
            <div style="width:120px;height:120px;border-radius:50%;margin:0 auto 14px;overflow:hidden;background:var(--mm-tint);display:grid;place-items:center;font-size:44px;font-weight:800;color:var(--mm-primary-dark)">
                <?php if ($avatar) { ?><img src="<?= h($avatar); ?>" alt="" style="width:100%;height:100%;object-fit:cover"><?php } else { echo h(strtoupper(mb_substr((string) $admin['name'], 0, 1))); } ?>
            </div>
            <div style="font-weight:800;color:var(--mm-ink)"><?= h($admin['name']); ?></div>
            <div class="small text-muted mb-3"><?= h($admin['job_title'] ?: 'Platform admin'); ?></div>
            <form method="POST" enctype="multipart/form-data" class="text-start"><?= platform_csrf_field(); ?><input type="hidden" name="do" value="avatar">
                <label class="form-label small mb-1">New picture (JPEG, PNG or WebP, under 2 MB)</label>
                <input class="form-control form-control-sm mb-2" type="file" name="avatar" accept="image/png,image/jpeg,image/webp" required>
                <button class="btn btn-sm btn-primary w-100" type="submit">Save picture</button>
            </form>
            <?php if ($avatar) { ?><form method="POST" class="mt-2"><?= platform_csrf_field(); ?><input type="hidden" name="do" value="avatar_remove"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Remove picture</button></form><?php } ?>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card p-4 mb-4">
            <h6 class="mb-3">Your details</h6>
            <form method="POST"><?= platform_csrf_field(); ?><input type="hidden" name="do" value="details">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label small mb-1">Full name</label><input class="form-control" name="name" value="<?= h($admin['name']); ?>" required maxlength="120"></div>
                    <div class="col-md-6"><label class="form-label small mb-1">Job title</label><input class="form-control" name="job_title" value="<?= h($admin['job_title'] ?? ''); ?>" maxlength="80" placeholder="e.g. Operations"></div>
                    <div class="col-md-6"><label class="form-label small mb-1">Email address (you sign in with it)</label><input class="form-control" type="email" name="email" value="<?= h($admin['email']); ?>" required></div>
                    <div class="col-md-6"><label class="form-label small mb-1">Phone</label><input class="form-control" name="phone" value="<?= h($admin['phone'] ?? ''); ?>" placeholder="0788 000 000"></div>
                    <div class="col-12"><label class="form-label small mb-1">Current password <span class="text-muted">(only needed if you change your email)</span></label><input class="form-control" type="password" name="current_password" autocomplete="current-password"></div>
                </div>
                <button class="btn btn-primary mt-3" type="submit">Save my details</button>
            </form>
        </div>
        <div class="card p-4">
            <h6 class="mb-3">Change your password</h6>
            <form method="POST"><?= platform_csrf_field(); ?><input type="hidden" name="do" value="password">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label small mb-1">Current password</label><input class="form-control" type="password" name="current_password" required autocomplete="current-password"></div>
                    <div class="col-md-6"><label class="form-label small mb-1">New password</label><input class="form-control" type="password" name="new_password" required autocomplete="new-password"></div>
                    <div class="col-md-6"><label class="form-label small mb-1">Repeat the new password</label><input class="form-control" type="password" name="confirm_password" required autocomplete="new-password"></div>
                </div>
                <div class="small text-muted mt-2">At least 10 characters, not your email, not a common password.</div>
                <button class="btn btn-primary mt-3" type="submit">Change password</button>
            </form>
        </div>
    </div>
</div>
<?php platform_page_end();
