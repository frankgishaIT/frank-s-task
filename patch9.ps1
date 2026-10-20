# patch9.ps1: run from the project folder (the one that contains login.php), on the saas branch.
# It checks every "find this" line first. If ANY line is missing, it changes NOTHING and tells you which one.
$ErrorActionPreference = 'Stop'
$root = (Get-Location).Path
if (-not (Test-Path (Join-Path $root 'login.php')) -or -not (Test-Path (Join-Path $root 'includes\platform_web.php'))) {
    Write-Host 'Run this from the project folder (the one that contains login.php).' -ForegroundColor Red; exit 1
}
$utf8 = New-Object System.Text.UTF8Encoding($false)
$pending = @{}
function Get-Text([string]$rel) {
    if ($pending.ContainsKey($rel)) { return $pending[$rel] }
    return [IO.File]::ReadAllText((Join-Path $root $rel), $utf8)
}
$problems = New-Object System.Collections.ArrayList

# ---- 1. exact edits: each line below must be found exactly once
$edits = New-Object System.Collections.ArrayList
[void]$edits.Add(@('tools\worker.php', 'require_once __DIR__ . ''/../includes/daily.php'';', (@('require_once __DIR__ . ''/../includes/daily.php'';','require_once __DIR__ . ''/../includes/business_delete.php'';') -join "`n")))
[void]$edits.Add(@('tools\worker.php', '    $daily = daily_maybe_run();', (@('    foreach (company_purge_pending() as $purgeLine) {','        echo date(''Y-m-d H:i:s'') . '' '' . $purgeLine . "\n";','    }','    $daily = daily_maybe_run();') -join "`n")))
[void]$edits.Add(@('includes\platform_web.php', 'require_once __DIR__ . ''/public_layout.php'';', (@('require_once __DIR__ . ''/public_layout.php'';','    require_once __DIR__ . ''/admin_profile.php'';') -join "`n")))
[void]$edits.Add(@('includes\platform_web.php', '''password''  => [''password.php'', ''My password'', ''key''],', '''profile''   => [''profile.php'', ''My profile'', ''users''],'))
[void]$edits.Add(@('includes\platform_web.php', '<div class="av">'' . h($initial) . ''</div>', '<div class="av">'' . (admin_avatar_url($admin) ? ''<img src="'' . h(admin_avatar_url($admin)) . ''" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover">'' : h($initial)) . ''</div>'))
[void]$edits.Add(@('platform\companies.php', 'require_once __DIR__ . ''/../includes/subscriptions.php'';', (@('require_once __DIR__ . ''/../includes/subscriptions.php'';','require_once __DIR__ . ''/../includes/business_delete.php'';') -join "`n")))
[void]$edits.Add(@('platform\companies.php', '        } elseif ($newPackage !== '''') {', (@('        } elseif ($action === ''delete'') {','            company_request_delete($companyId, $text(''confirm_code''), $admin[''email'']);','            platform_flash(''The business is being deleted. It disappears from this list within a minute.'');','        } elseif ($newPackage !== '''') {') -join "`n")))
[void]$edits.Add(@('platform\companies.php', '    <td class="text-end" style="min-width:230px">', (@('    <td class="text-end" style="min-width:230px">','<?php if (!$own) { ?>','        <details class="text-start mb-2"><summary class="text-danger">Delete this business</summary>','            <form method="POST" class="border rounded-3 p-3 bg-white" onsubmit="return confirm(''Delete <?= h($r[''company_code'']); ?> for good? Its database and all its data will be removed.'');"><?= platform_csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="company_id" value="<?= (int) $r[''id'']; ?>">','                <div class="small text-muted mb-2">This removes the business, its database and its logo for good. Payments stay on record. It cannot be undone.</div>','                <label class="form-label small mb-1">Type <b><?= h($r[''company_code'']); ?></b> to confirm</label><input class="form-control form-control-sm mb-2" name="confirm_code" autocomplete="off" required>','                <button class="btn btn-sm btn-danger w-100" type="submit">Delete for good</button>','            </form>','        </details>','<?php } ?>') -join "`n")))
foreach ($e in $edits) {
    $t = Get-Text $e[0]
    $c = ([regex]::Matches($t, [regex]::Escape($e[1]))).Count
    if ($c -ne 1) {
        [void]$problems.Add($e[0] + ': expected to find this once, found ' + $c + ' times: ' + $e[1].Substring(0, [Math]::Min(70, $e[1].Length)))
        continue
    }
    $pending[$e[0]] = $t.Replace($e[1], $e[2])
}

# ---- 2. new files
$pending['database\platform_migrations\007_delete_profile.sql'] = @'
-- Patch 9: a business can be marked "deleting" (the worker then removes it), and admins get a profile.
ALTER TABLE companies MODIFY status ENUM('pending','active','suspended','deleting') NOT NULL DEFAULT 'pending';
ALTER TABLE platform_admins ADD COLUMN phone VARCHAR(30) NULL, ADD COLUMN job_title VARCHAR(80) NULL, ADD COLUMN avatar_file VARCHAR(120) NULL;
'@
$pending['includes\business_delete.php'] = @'
<?php
/**
 * Deleting a business (mostly for businesses made for testing).
 *
 * The admin panel only MARKS a business "deleting" (the website must never be able to drop a database).
 * The worker, running as root, then removes its database, its MySQL user, its logo folder and its platform records.
 * Payments stay on record (without the business), because they are money records. Business 1 can never be deleted.
 */

if (!defined('COMPANY_LOGO_DIR')) {
    define('COMPANY_LOGO_DIR', dirname(__DIR__) . '/assets/uploads/company');
}

/** Marks a business as "deleting". The admin must type the business code to confirm. */
function company_request_delete(int $companyId, string $typedCode, string $adminEmail): void
{
    if ($companyId === 1) {
        throw new SignupException('Business 1 is your own business. It can never be deleted.');
    }
    $company = platform_company_by_id($companyId);
    if (!$company) {
        throw new SignupException('There is no such business.');
    }
    if (platform_normalize_code($typedCode) !== $company['company_code']) {
        throw new SignupException('To delete it, type the business code ' . $company['company_code'] . ' exactly.');
    }
    $st = mysqli_prepare(platform_conn(), "UPDATE companies SET status = 'deleting', status_note = 'Being deleted' WHERE id = ? AND id <> 1");
    mysqli_stmt_bind_param($st, 'i', $companyId);
    mysqli_stmt_execute($st);
    platform_audit($companyId, 'admin:' . $adminEmail, 'delete_requested', $company['company_code'] . ' ' . $company['name']);
}

/** Really removes one business. Command line (root) only. Returns a one-line report. */
function company_purge(array $company): string
{
    require_once __DIR__ . '/provisioning.php';      // command line only: the website itself can never drop a database
    $id = (int) $company['id'];
    $db = (string) $company['db_name'];
    $user = (string) $company['db_user'];
    if ($id === 1) {
        throw new RuntimeException('Business 1 can never be deleted.');
    }
    // only databases and users that this platform itself created ever get dropped
    if (!preg_match('/^mm_[a-z0-9_]+$/', $db) || !preg_match('/^mmu_[a-z0-9_]+$/', $user)) {
        throw new RuntimeException("Refusing to drop '$db' / '$user': not a database made by this platform.");
    }
    $admin = provision_admin_connection();
    mysqli_query($admin, 'DROP DATABASE IF EXISTS `' . $db . '`');
    mysqli_query($admin, "DROP USER IF EXISTS '" . mysqli_real_escape_string($admin, $user) . "'@'localhost'");
    mysqli_query($admin, 'FLUSH PRIVILEGES');

    $dir = COMPANY_LOGO_DIR . '/' . $id;
    if (is_dir($dir) && strpos(realpath($dir) ?: '', realpath(COMPANY_LOGO_DIR) ?: '#') === 0) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
    $p = platform_conn();
    mysqli_begin_transaction($p);
    try {
        foreach (['DELETE FROM owner_invites WHERE company_id = ?', 'DELETE FROM password_resets WHERE company_id = ?', 'DELETE FROM subscriptions WHERE company_id = ?',
                  'UPDATE payments SET company_id = NULL WHERE company_id = ?', 'UPDATE signup_requests SET company_id = NULL WHERE company_id = ?', 'DELETE FROM companies WHERE id = ?'] as $sql) {
            $st = mysqli_prepare($p, $sql);
            mysqli_stmt_bind_param($st, 'i', $id);
            mysqli_stmt_execute($st);
        }
        mysqli_commit($p);
    } catch (Throwable $e) {
        mysqli_rollback($p);
        throw $e;
    }
    platform_audit(null, 'worker', 'company_deleted', $company['company_code'] . ' ' . $company['name'] . " (database $db)");
    return 'deleted business ' . $company['company_code'] . ' (' . $company['name'] . ')';
}

/** For the worker: removes every business marked "deleting". */
function company_purge_pending(): array
{
    $log = [];
    $res = mysqli_query(platform_conn(), "SELECT * FROM companies WHERE status = 'deleting' AND id <> 1 ORDER BY id LIMIT 10");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
    foreach ($rows as $company) {
        try {
            $log[] = company_purge($company);
        } catch (Throwable $e) {
            $log[] = 'could not delete ' . $company['company_code'] . ': ' . $e->getMessage();
            error_log('[MY MOTIVE delete] ' . $e->getMessage());
        }
    }
    return $log;
}
'@
$pending['includes\admin_profile.php'] = @'
<?php
/** A platform admin's profile picture: saved as a small PNG in assets/uploads/admins/<id>/. */
if (!defined('ADMIN_AVATAR_DIR')) {
    define('ADMIN_AVATAR_DIR', dirname(__DIR__) . '/assets/uploads/admins');
}

/** The web address of the admin's picture, or null when there is none. */
function admin_avatar_url(array $admin): ?string
{
    if (empty($admin['avatar_file'])) {
        return null;
    }
    return BASE_URL . '/assets/uploads/admins/' . (int) $admin['id'] . '/' . rawurlencode($admin['avatar_file']);
}

/** Saves an uploaded picture and returns its file name. Throws RuntimeException with a message the admin can read. */
function admin_save_avatar(int $adminId, array $file): string
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('That picture is too large to upload. Please use one under 2 MB.');
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('Please choose a picture to upload.');
    }
    if ((int) $file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('That picture is too large to upload. Please use one under 2 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !function_exists('imagecreatefromstring')) {
        throw new RuntimeException('The picture must be a JPEG, PNG or WebP image.');
    }
    $img = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
    if (!$img) {
        throw new RuntimeException('The picture must be a JPEG, PNG or WebP image.');
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $side = min($w, $h);                       // a centred square, then 320 px
    $out = imagecreatetruecolor(320, 320);
    imagecopyresampled($out, $img, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), 320, 320, $side, $side);
    $dir = ADMIN_AVATAR_DIR . '/' . $adminId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new RuntimeException('The picture folder cannot be created on this server.');
    }
    foreach (glob($dir . '/avatar-*.png') ?: [] as $old) {
        @unlink($old);
    }
    $name = 'avatar-' . bin2hex(random_bytes(4)) . '.png';
    if (!imagepng($out, $dir . '/' . $name, 6)) {
        throw new RuntimeException('The picture could not be saved.');
    }
    return $name;
}

function admin_remove_avatar(int $adminId): void
{
    foreach (glob(ADMIN_AVATAR_DIR . '/' . $adminId . '/avatar-*.png') ?: [] as $old) {
        @unlink($old);
    }
}
'@
$pending['platform\profile.php'] = @'
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
'@
$pending['tools\sms_check.php'] = @'
<?php
/**
 * Shows, in plain words, why SMS does or does not work for one business, and can send a real test message.
 *
 *   php tools/sms_check.php --code=RMCP0002                       the settings, the monthly count, the last 5 attempts
 *   php tools/sms_check.php --code=RMCP0002 --to=0788123456       also sends one real test message to that number
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/provisioning.php';
require_once __DIR__ . '/../includes/features.php';
require_once __DIR__ . '/../includes/company_profile.php';
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $a, $m)) {
        $opt[$m[1]] = $m[2];
    }
}
$code = platform_normalize_code($opt['code'] ?? '');
$company = $code !== '' ? platform_company_by_code($code) : null;
if (!$company) {
    fwrite(STDERR, "Usage: php tools/sms_check.php --code=RMCP0002 [--to=0788123456]\n");
    exit(1);
}
$GLOBALS['MM_COMPANY'] = $company;
$GLOBALS['MM_PACKAGE'] = platform_package_for_company((int) $company['id']);
$GLOBALS['MM_FEATURES'] = $GLOBALS['MM_PACKAGE']['features'] ?? [];
$conn = tenant_try_connect($company);
$GLOBALS['conn'] = $conn;
company_profile_reset();
require_once __DIR__ . '/../config/sms.php';
require_once __DIR__ . '/../includes/sms_helpers.php';
require_once __DIR__ . '/../includes/sms_messages.php';

$quota = mm_sms_quota();
$say = static fn(string $k, string $v) => print(str_pad($k, 26) . $v . "\n");
echo "SMS check for {$company['company_code']} ({$company['name']})\n\n";
$say('SMS switched on:', SMS_ENABLED ? 'yes' : 'NO  -> set SMS_ENABLED to true in config/sms.php');
$tokenOk = PINDO_API_TOKEN !== '' && PINDO_API_TOKEN !== 'PASTE_YOUR_PINDO_TOKEN_HERE';
$say('Pindo token set:', $tokenOk ? 'yes (' . strlen(PINDO_API_TOKEN) . ' characters)' : 'NO  -> put the token in config/sms.php');
$say('Sender name:', SMS_SENDER_ID);
$say('PHP cURL:', function_exists('curl_init') ? 'yes' : 'NO  -> install php-curl');
$say('Package:', mm_package_name());
$say('Monthly limit:', $quota === null ? 'none (your own business)' : (string) $quota);
$say('Used this month:', (string) sms_month_usage($conn));
$say('Business name in texts:', company_profile()['name']);
if (isset($opt['to'])) {
    $ok = sms_send($conn, $opt['to'], 'Test SMS from ' . company_profile()['name'] . ' via MY MOTIVE.', 'SMS_CHECK');
    echo "\nTest message to {$opt['to']}: " . ($ok ? 'SENT' : 'NOT SENT') . "\n";
}
echo "\nLast 5 attempts:\n";
$res = mysqli_query($conn, 'SELECT created_at, status, phone, error FROM sms_log ORDER BY id DESC LIMIT 5');
$n = 0;
while ($r = mysqli_fetch_assoc($res)) {
    $n++;
    echo '  ' . $r['created_at'] . '  ' . str_pad($r['status'], 8) . ' ' . str_pad((string) $r['phone'], 14) . ' ' . ($r['error'] ?: 'ok') . "\n";
}
if ($n === 0) {
    echo "  (none yet)\n";
}
'@

# ---- 3. the wording: Business, Inventory, the closing line, no customer ID on invoices
$phrases = New-Object System.Collections.ArrayList
[void]$phrases.Add(@('<td><?php if ($sale[''customer_id'']) { ?><span class="label"><strong>Customer ID:&nbsp;</strong></span>RMC<?= str_pad((string) $sale[''customer_id''], 6, ''0'', STR_PAD_LEFT); ?><?php } ?></td>', '<td></td>'))
[void]$phrases.Add(@('platform_page_start(''Companies''', 'platform_page_start(''Businesses'''))
[void]$phrases.Add(@('Company 1 is your own company', 'Business 1 is your own business'))
[void]$phrases.Add(@('Could not create the company', 'Could not create the business'))
[void]$phrases.Add(@('Rise Motive Ltd acknowledges', '<?= $h($co[''name'']); ?> acknowledges'))
[void]$phrases.Add(@('Rise Motive Ltd recognizes', '<?= $h($co[''name'']); ?> recognizes'))
[void]$phrases.Add(@('''Companies'', ''building''', '''Businesses'', ''building'''))
[void]$phrases.Add(@('No company is signed in', 'No business is signed in'))
[void]$phrases.Add(@('Approve new companies', 'Approve new businesses'))
[void]$phrases.Add(@('<span>Company</span>', '<span>Business</span>'))
[void]$phrases.Add(@('Rise Beyond Limits!', 'Thank You For Choosing <?= htmlspecialchars($co[''name''], ENT_QUOTES, ''UTF-8''); ?>'))
[void]$phrases.Add(@('Suspended companies', 'Suspended businesses'))
[void]$phrases.Add(@('(creates companies)', '(creates businesses)'))
[void]$phrases.Add(@('database of company', 'database of business'))
[void]$phrases.Add(@('<h4>Companies</h4>', '<h4>Businesses</h4>'))
[void]$phrases.Add(@('Open your company', 'Open your business'))
[void]$phrases.Add(@('approve companies', 'approve businesses'))
[void]$phrases.Add(@('Active companies', 'Active businesses'))
[void]$phrases.Add(@('your own company', 'your own business'))
[void]$phrases.Add(@('<th>Company</th>', '<th>Business</th>'))
[void]$phrases.Add(@('Company profile', 'Business profile'))
[void]$phrases.Add(@('company profile', 'business profile'))
[void]$phrases.Add(@('no such company', 'no such business'))
[void]$phrases.Add(@('no company with', 'no business with'))
[void]$phrases.Add(@('you@company.com', 'you@business.com'))
[void]$phrases.Add(@('A company owner', 'A business owner'))
[void]$phrases.Add(@('of company {$co', 'of business {$co'))
[void]$phrases.Add(@('A paid company', 'A paid business'))
[void]$phrases.Add(@('when a company', 'when a business'))
[void]$phrases.Add(@('every company', 'every business'))
[void]$phrases.Add(@('RM Offerings', 'Inventory'))
[void]$phrases.Add(@('New company?', 'New business?'))
[void]$phrases.Add(@('Company code', 'Business code'))
[void]$phrases.Add(@('company code', 'business code'))
[void]$phrases.Add(@('Company name', 'Business name'))
[void]$phrases.Add(@('company name', 'business name'))
[void]$phrases.Add(@('Your company', 'Your business'))
[void]$phrases.Add(@('your company', 'your business'))
[void]$phrases.Add(@('This company', 'This business'))
[void]$phrases.Add(@('this company', 'this business'))
[void]$phrases.Add(@('that company', 'that business'))
[void]$phrases.Add(@('each company', 'each business'))
[void]$phrases.Add(@('or a company', 'or a business'))
[void]$phrases.Add(@('Company {$co', 'Business {$co'))
[void]$phrases.Add(@('The company', 'The business'))
[void]$phrases.Add(@('the company', 'the business'))
[void]$phrases.Add(@('Company 1', 'Business 1'))
[void]$phrases.Add(@('company 1', 'business 1'))
$renamed = 0
$onDisk = Get-ChildItem -Path $root -Recurse -Filter *.php -File | ForEach-Object { $_.FullName.Substring($root.Length + 1) } | Where-Object { $_ -notmatch '^(vendor|\.git|storage|tmp|config|node_modules)\\' }
$all = @($onDisk) + @($pending.Keys) | Sort-Object -Unique
foreach ($rel in $all) {
    if ($rel -notlike '*.php') { continue }
    $t = Get-Text $rel
    $o = $t
    foreach ($p in $phrases) { $t = $t.Replace($p[0], $p[1]) }
    if ($t -ne $o) { $pending[$rel] = $t; $renamed++ }
}
if ($problems.Count -gt 0) {
    Write-Host ''
    foreach ($p in $problems) { Write-Host ('PROBLEM  ' + $p) -ForegroundColor Red }
    Write-Host ''
    Write-Host 'NOTHING WAS CHANGED. Send me the PROBLEM lines above. (If you already ran this script once, that is why.)' -ForegroundColor Yellow
    exit 1
}
$newRel = @('database\platform_migrations\007_delete_profile.sql', 'includes\business_delete.php', 'includes\admin_profile.php', 'platform\profile.php', 'tools\sms_check.php')
foreach ($rel in ($pending.Keys | Sort-Object)) {
    $path = Join-Path $root $rel
    $text = $pending[$rel]
    if (($newRel -contains $rel) -and -not $text.EndsWith("`n")) { $text = $text + "`n" }
    New-Item -ItemType Directory -Force -Path (Split-Path $path) | Out-Null
    [IO.File]::WriteAllText($path, $text, $utf8)
}
Write-Host ''
Write-Host ('Done. ' + $pending.Count + ' files written (' + $edits.Count + ' exact edits, 5 new files, wording changed in ' + $renamed + ' files).') -ForegroundColor Green
Write-Host 'Next:  git status --short   then   git add -A ; git commit -m "Patch 9: business wording, deletion, admin profile, invoice" ; git push origin saas'

