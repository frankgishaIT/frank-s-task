<?php
/**
 * One-time setup of the SaaS platform on a server. Run it as the Linux root user:
 *
 *   php tools/install_platform.php --company1-code=RM --company1-name="RISE MOTIVE"
 *
 * Optional: --platform-db=mm_platform  --platform-user=mm_platform
 *           --credentials-file=/path/to/saved/db.php   (read the database login from this saved copy of the old config/db.php)
 *           --public-url=https://mymotive.risemotive.rw   (used in the links inside emails)
 *           --mail-dry-run=1   (emails are written to storage/platform_outbox.log instead of being sent: for staging)
 *
 * What it does:
 *   1. Reads the database login from the CURRENT config/db.php. That database becomes company 1.
 *   2. Creates the platform database and its own MySQL user.
 *   3. Writes config/platform.php (the platform login and a new random master key).
 *   4. Registers company 1 in the platform, with its database password encrypted.
 *   5. Copies the old config/db.php to /root (it holds secrets) and replaces it with a file that holds NONE.
 * Nothing inside company 1's database is changed. If any step fails, everything is put back.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/provisioning.php';

$opt = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)=(.*)$/s', $arg, $m)) {
        $opt[$m[1]] = $m[2];
    }
}

function fail(string $message): void
{
    fwrite(STDERR, "\nSTOPPED: $message\n");
    exit(1);
}

$root = dirname(__DIR__);
$dbFile = $root . '/config/db.php';
$platformFile = $root . '/config/platform.php';
$code = platform_normalize_code($opt['company1-code'] ?? '');
$companyName = trim($opt['company1-name'] ?? '');
$platformDb = $opt['platform-db'] ?? 'mm_platform';
$platformUser = $opt['platform-user'] ?? 'mm_platform';
$webGroup = getenv('MM_WEB_GROUP') ?: 'www-data';

if (!platform_valid_code($code) || $companyName === '') {
    fail('Give the code and name of company 1, for example: --company1-code=RM --company1-name="RISE MOTIVE"');
}
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $platformDb) || !preg_match('/^[A-Za-z0-9_]{1,32}$/', $platformUser)) {
    fail('The platform database and user names may only use letters, digits and _.');
}
if (is_file($platformFile)) {
    fail('config/platform.php already exists, so the platform is already installed here.');
}
if (!is_file($dbFile)) {
    fail('config/db.php was not found.');
}

// ---- 1. read the current database login (company 1)
$source = $opt['credentials-file'] ?? $dbFile;          // normally the current config/db.php
if (!is_file($source)) {
    fail("The credentials file $source was not found.");
}
$old = (string) file_get_contents($source);
if ($source === $dbFile && strpos($old, 'includes/tenant.php') !== false) {
    fail('config/db.php has already been converted.');
}
$creds = [];
foreach (['host', 'user', 'password', 'database'] as $name) {
    if (!preg_match('/^\s*\$' . $name . '\s*=\s*(["\'])(.*?)\1\s*;/m', $old, $m)) {
        fail("I could not read \$$name from config/db.php. It does not look like the usual file.");
    }
    $creds[$name] = stripcslashes($m[2]);
}
try {
    $probe = mysqli_connect($creds['host'], $creds['user'], $creds['password'], $creds['database']);
    mysqli_close($probe);
} catch (Throwable $e) {
    fail('The login in config/db.php does not work (' . $e->getMessage() . '). Nothing was changed.');
}
echo "1/5  Company 1 will be the database '{$creds['database']}' (login read, and it works).\n";

$done = ['db' => false, 'user' => false, 'file' => false, 'backup' => null];
$admin = null;
try {
    // ---- 2. the platform database and its own MySQL user
    $admin = provision_admin_connection();
    $q = static fn(string $v): string => mysqli_real_escape_string($admin, $v);
    $exists = mysqli_fetch_row(mysqli_query($admin, "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '" . $q($platformDb) . "'"))[0];
    if ((int) $exists > 0) {
        throw new RuntimeException("A database called $platformDb already exists.");
    }
    $platformPass = mm_random_password();
    mysqli_query($admin, 'CREATE DATABASE `' . $platformDb . '` CHARACTER SET utf8mb4');
    $done['db'] = true;
    mysqli_query($admin, "CREATE USER '" . $q($platformUser) . "'@'localhost' IDENTIFIED BY '" . $q($platformPass) . "'");
    $done['user'] = true;
    mysqli_query($admin, 'GRANT ALL PRIVILEGES ON `' . $platformDb . "`.* TO '" . $q($platformUser) . "'@'localhost'");
    mysqli_query($admin, 'FLUSH PRIVILEGES');
    mysqli_select_db($admin, $platformDb);
    provision_run_script($admin, (string) file_get_contents($root . '/database/platform_schema.sql'));
    platform_apply_migrations($admin);
    echo "2/5  Platform database '$platformDb' created, with its own MySQL user.\n";

    // ---- 3. config/platform.php
    $settings = [
        'db_host' => 'localhost',
        'db_name' => $platformDb,
        'db_user' => $platformUser,
        'db_pass' => $platformPass,
        'key'     => 'base64:' . base64_encode(random_bytes(32)),
        'public_url'   => rtrim($opt['public-url'] ?? '', '/'),
        'mail_dry_run' => ($opt['mail-dry-run'] ?? '0') === '1',
    ];
    $text = "<?php\n// MY MOTIVE SaaS platform settings. NOT in git. Keep this file private.\n"
        . "// 'key' encrypts every company's database password. If it is lost, those passwords cannot be recovered: back this file up.\n"
        . 'return ' . var_export($settings, true) . ";\n";
    if (file_put_contents($platformFile, $text) === false) {
        throw new RuntimeException('Could not write config/platform.php.');
    }
    $done['file'] = true;
    chmod($platformFile, 0640);
    @chgrp($platformFile, $webGroup);
    echo "3/5  config/platform.php written (new random master key).\n";

    // ---- 4. register company 1
    $enc = platform_encrypt($creds['password']);
    $st = mysqli_prepare(platform_conn(), "INSERT INTO companies (company_code, name, db_host, db_name, db_user, db_pass_enc, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
    mysqli_stmt_bind_param($st, 'ssssss', $code, $companyName, $creds['host'], $creds['database'], $creds['user'], $enc);
    mysqli_stmt_execute($st);
    $companyId = (int) mysqli_insert_id(platform_conn());
    platform_audit($companyId, mm_cli_actor(), 'platform_installed', "company1=$code db={$creds['database']}");
    echo "4/5  Company 1 registered: code $code, database {$creds['database']} (password stored encrypted).\n";

    // ---- 5. keep the old file safely, then replace it with one that has no secrets
    $backup = '/root/db.php.pre-saas.' . date('Ymd-His');
    if (!@copy($source, $backup)) {
        throw new RuntimeException("Could not save a copy of the old database login to $backup.");
    }
    chmod($backup, 0600);
    $done['backup'] = $backup;
    $stub = "<?php\n// MY MOTIVE SaaS. The database is now chosen per company (see includes/tenant.php).\n"
        . "// This file holds NO secrets any more: the platform login is in config/platform.php.\n"
        . "require_once __DIR__ . '/../includes/tenant.php';\n";
    if (file_put_contents($dbFile, $stub) === false) {
        throw new RuntimeException('Could not write the new config/db.php. The old one is untouched.');
    }
    chmod($dbFile, 0640);
    @chgrp($dbFile, $webGroup);
    echo "5/5  config/db.php replaced. The old one is saved at $backup (it holds secrets: keep it safe, delete it when you no longer need it).\n";
} catch (Throwable $e) {
    // put everything back
    if ($done['backup'] !== null) {
        @copy($done['backup'], $dbFile);
    }
    if ($done['file']) {
        @unlink($platformFile);
    }
    try {
        if ($admin && $done['user']) {
            mysqli_query($admin, "DROP USER IF EXISTS '" . $q($platformUser) . "'@'localhost'");
        }
        if ($admin && $done['db']) {
            mysqli_query($admin, 'DROP DATABASE IF EXISTS `' . $platformDb . '`');
        }
    } catch (Throwable $ignored) {
    }
    fail('Something failed, and everything was put back: ' . $e->getMessage());
}

echo "\nDONE. Sign in with company code $code and your usual email and password.\n";
