<?php
/**
 * Platform admins: the people who may approve businesses. Run as any user that can read config/platform.php.
 *
 *   php tools/platform_admin.php create --email=you@example.com --name="Your Name"
 *   php tools/platform_admin.php reset  --email=you@example.com
 *   php tools/platform_admin.php list
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/provisioning.php';

$command = $argv[1] ?? '';
$opt = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $arg, $m)) {
        $opt[$m[1]] = $m[2];
    }
}
try {
    $db = platform_conn();
    $email = strtolower(trim($opt['email'] ?? ''));
    switch ($command) {
        case 'create':
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || trim($opt['name'] ?? '') === '') {
                throw new RuntimeException('Give a valid --email and a --name.');
            }
            $password = mm_random_password(14);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $name = trim($opt['name']);
            $st = mysqli_prepare($db, 'INSERT INTO platform_admins (email, name, password_hash) VALUES (?, ?, ?)');
            mysqli_stmt_bind_param($st, 'sss', $email, $name, $hash);
            try {
                mysqli_stmt_execute($st);
            } catch (mysqli_sql_exception $e) {
                throw new RuntimeException('An admin with that email already exists. Use "reset" to give them a new password.');
            }
            platform_audit(null, mm_cli_actor(), 'admin_created', $email);
            echo "Admin created: $email\nTemporary password: $password\n(Shown once. The admin must choose their own password at the first sign-in.)\n";
            break;
        case 'reset':
            $password = mm_random_password(14);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $st = mysqli_prepare($db, 'UPDATE platform_admins SET password_hash = ?, must_change_password = 1, is_active = 1 WHERE email = ?');
            mysqli_stmt_bind_param($st, 'ss', $hash, $email);
            mysqli_stmt_execute($st);
            if (mysqli_stmt_affected_rows($st) !== 1) {
                throw new RuntimeException('There is no admin with that email.');
            }
            platform_audit(null, mm_cli_actor(), 'admin_password_reset', $email);
            echo "New temporary password for $email: $password\n(Shown once. They must change it at the next sign-in.)\n";
            break;
        case 'list':
            $res = mysqli_query($db, 'SELECT email, name, is_active, last_login_at FROM platform_admins ORDER BY id');
            while ($r = mysqli_fetch_assoc($res)) {
                printf("%-34s %-22s %-8s last sign-in: %s\n", $r['email'], $r['name'], $r['is_active'] ? 'active' : 'DISABLED', $r['last_login_at'] ?? 'never');
            }
            break;
        default:
            echo "Usage:\n  php tools/platform_admin.php create --email=you@example.com --name=\"Your Name\"\n  php tools/platform_admin.php reset --email=you@example.com\n  php tools/platform_admin.php list\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
