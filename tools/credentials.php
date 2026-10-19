<?php
/**
 * Writes ONE private file that lists where to sign in and who can. Run as root:
 *
 *   php tools/credentials.php                                  the file, without any new password
 *   php tools/credentials.php --new-admin-password=you@x.com   also sets a NEW temporary password for that platform admin and writes it in the file
 *   php tools/credentials.php --file=/root/my-sign-ins.txt     another file name
 *
 * Passwords are never stored in readable form anywhere, so an existing password can NOT be looked up. A company owner's password
 * is chosen by the owner through the emailed link. The only password this file can contain is a freshly made temporary one.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/provisioning.php';

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $a, $m)) {
        $opt[$m[1]] = $m[2];
    }
}
$file = $opt['file'] ?? '/root/mymotive-credentials.txt';
$base = platform_public_url() ?: '(set public_url: php tools/platform_config.php set public_url https://your-address)';
$L = [];
$L[] = 'MY MOTIVE: WHERE TO SIGN IN        (made ' . date('Y-m-d H:i') . ')';
$L[] = 'KEEP THIS FILE PRIVATE. Delete it when you have saved what you need.';
$L[] = '';
$L[] = '1. ADDRESSES';
$L[] = "   Companies sign in at:           $base/login.php      (company code + email + password)";
$L[] = "   New companies sign up at:       $base/signup.php";
$L[] = "   Platform admin (you) sign in:   $base/platform/login.php";
$L[] = '';
$L[] = '2. PLATFORM ADMINS (the people who approve companies)';
$res = mysqli_query(platform_conn(), 'SELECT id, email, name, is_active, must_change_password, last_login_at FROM platform_admins ORDER BY id');
$admins = [];
while ($r = mysqli_fetch_assoc($res)) {
    $admins[] = $r;
}
if (!$admins) {
    $L[] = '   (none yet)  Create one:  php tools/platform_admin.php create --email=you@example.com --name="Your Name"';
}
$newFor = strtolower(trim($opt['new-admin-password'] ?? ''));
$tempPw = null;
foreach ($admins as $r) {
    $line = sprintf('   %-34s %-20s %s, last sign-in: %s', $r['email'], $r['name'], $r['is_active'] ? 'active' : 'DISABLED', $r['last_login_at'] ?? 'never');
    if ($newFor !== '' && $r['email'] === $newFor) {
        $tempPw = mm_random_password(14);
        $st = mysqli_prepare(platform_conn(), 'UPDATE platform_admins SET password_hash = ?, must_change_password = 1, is_active = 1 WHERE id = ?');
        $hash = password_hash($tempPw, PASSWORD_DEFAULT);
        $id = (int) $r['id'];
        mysqli_stmt_bind_param($st, 'si', $hash, $id);
        mysqli_stmt_execute($st);
        platform_audit(null, mm_cli_actor(), 'admin_password_reset', $r['email'] . ' (credentials file)');
        $line .= "\n      NEW temporary password: $tempPw      (you must choose your own at the first sign-in)";
    }
    $L[] = $line;
}
if ($newFor !== '' && $tempPw === null) {
    $L[] = "   !! There is no platform admin called $newFor, so no password was made.";
}
$L[] = '   Existing passwords cannot be shown (they are stored scrambled). To get a new one:';
$L[] = '     php tools/credentials.php --new-admin-password=EMAIL';
$L[] = '';
$L[] = '3. COMPANIES';
$L[] = sprintf('   %-10s %-30s %-10s %-9s %s', 'CODE', 'COMPANY', 'STATUS', 'PACKAGE', 'OWNER EMAIL');
$res = mysqli_query(platform_conn(), "SELECT c.company_code, c.name, c.status, c.owner_email, (SELECT p.name FROM subscriptions s JOIN packages p ON p.id = s.package_id WHERE s.company_id = c.id ORDER BY s.id DESC LIMIT 1) AS package FROM companies c ORDER BY c.id");
while ($r = mysqli_fetch_assoc($res)) {
    $L[] = sprintf('   %-10s %-30s %-10s %-9s %s', $r['company_code'], mb_substr($r['name'], 0, 30), $r['status'], $r['package'] ?? '-', $r['owner_email'] ?? '(your own staff)');
}
$L[] = '   Every person signs in with: company code + their email + their own password.';
$L[] = '   A company owner chooses the password through the link in the "Your account is ready" email.';
$L[] = '   Someone who forgot theirs uses "Forgot password?" on the sign-in page.';
$staging = '/root/mymotive-staging-credentials.txt';
if (is_file($staging)) {
    $L[] = '';
    $L[] = '4. THE STAGING (TEST) SITE, copied from ' . $staging;
    foreach (file($staging, FILE_IGNORE_NEW_LINES) as $line) {
        $L[] = '   ' . $line;
    }
}
$text = implode("\n", $L) . "\n";
$old = umask(0077);
file_put_contents($file, $text);
umask($old);
chmod($file, 0600);
echo "Written: $file   (only root can read it)\n";
if ($tempPw !== null) {
    echo "A new temporary password was made for $newFor. Read it with:  cat $file\n";
}
