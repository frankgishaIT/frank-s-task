<?php
/**
 * MY MOTIVE SaaS: creating and managing company databases.
 *
 * For command-line use by the server administrator (tools/companies.php and
 * tools/install_platform.php). It needs a MySQL account that may create databases
 * and users. By default that is the server's root account over the local socket,
 * which only works when the script runs as the Linux root user. The web site itself
 * never uses these powers.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/platform.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * A strong random password: letters and digits, then a fixed ending with a capital, a small letter,
 * a digit and special characters. That keeps it acceptable to servers that enforce a password policy,
 * and it contains nothing that needs escaping in SQL or PHP.
 */
function mm_random_password(int $length = 20): string
{
    $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out . '-Qz7!';
}

/** An invoice prefix from a company's initials: "Kigali Fresh Traders" -> "KFT". */
function provision_invoice_prefix(string $name): string
{
    $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = '';
    foreach ($words as $w) {
        $initials .= strtoupper($w[0]);
    }
    $initials = substr($initials, 0, 4);
    if (strlen($initials) < 2) {
        $initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 3));
    }
    return $initials !== '' ? $initials : 'INV';
}

/** Who is running this command, for the audit log. */
function mm_cli_actor(): string
{
    $name = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
    return 'cli:' . ($name !== '' ? $name : (getenv('USER') ?: 'unknown'));
}

/** The MySQL account that is allowed to create databases and users. */
function provision_admin_connection(): mysqli
{
    $host = getenv('MM_ADMIN_DB_HOST') ?: 'localhost';
    $user = getenv('MM_ADMIN_DB_USER') ?: 'root';
    $pass = getenv('MM_ADMIN_DB_PASS') ?: '';
    try {
        return mysqli_connect($host, $user, $pass);
    } catch (Throwable $e) {
        throw new RuntimeException('Cannot open the MySQL admin account (' . $user . '). Run this as the Linux root user, or set MM_ADMIN_DB_USER and MM_ADMIN_DB_PASS. ' . $e->getMessage());
    }
}

/** Runs a file full of SQL statements (the template, a migration). Stops at the first error. */
function provision_run_script(mysqli $c, string $sql): void
{
    mysqli_multi_query($c, $sql);
    do {
        if ($result = mysqli_store_result($c)) {
            mysqli_free_result($result);
        }
    } while (mysqli_more_results($c) && mysqli_next_result($c));
}

/**
 * Applies the migration files that the database has not seen yet, in the same order and with the same
 * ledger (schema_migrations) that deploy.sh uses. A brand-new company already has every migration that
 * existed when the template was made, so only migrations added later run here.
 *
 * @return string[] the names of the migrations that were applied
 */
function provision_apply_pending_migrations(mysqli $db): array
{
    $applied = [];
    $seen = [];
    $res = mysqli_query($db, 'SELECT migration_name FROM schema_migrations');
    while ($row = mysqli_fetch_row($res)) {
        $seen[$row[0]] = true;
    }
    $files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
    sort($files, SORT_STRING);
    foreach ($files as $file) {
        $name = basename($file);
        if (isset($seen[$name])) {
            continue;
        }
        provision_run_script($db, (string) file_get_contents($file));
        $st = mysqli_prepare($db, 'INSERT INTO schema_migrations (migration_name) VALUES (?)');
        mysqli_stmt_bind_param($st, 's', $name);
        mysqli_stmt_execute($st);
        $applied[] = $name;
    }
    return $applied;
}

/**
 * Creates a complete new company: its own database, its own MySQL user (who can reach ONLY that
 * database), the structure and defaults, the first owner login, and the entry in the platform.
 * If anything fails, everything created so far is removed again.
 *
 * @return array{company_id:int, code:string, db_name:string, owner_email:string, owner_password:string}
 */
function provision_company(string $code, string $name, string $ownerName, string $ownerEmail, ?string $ownerPhone = null, string $status = 'active'): array
{
    $code = platform_normalize_code($code);
    if (!platform_valid_code($code)) {
        throw new RuntimeException('The company code must be 2 to 30 characters: letters, digits, - or _.');
    }
    $name = trim($name);
    $ownerName = trim($ownerName);
    if ($name === '' || $ownerName === '') {
        throw new RuntimeException('The company name and the owner name are required.');
    }
    if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The owner email address is not valid.');
    }
    if (!in_array($status, ['pending', 'active', 'suspended'], true)) {
        throw new RuntimeException('Unknown status.');
    }
    if (platform_company_by_code($code)) {
        throw new RuntimeException("The company code $code is already used.");
    }

    $slug = strtolower(preg_replace('/[^A-Za-z0-9]/', '_', $code));
    $dbName = substr('mm_' . $slug, 0, 64);
    $dbUser = substr('mmu_' . $slug, 0, 32);
    $dbPass = mm_random_password();
    $ownerPassword = mm_random_password(12);
    $template = __DIR__ . '/../database/company_template.sql';
    if (!is_file($template)) {
        throw new RuntimeException('database/company_template.sql is missing.');
    }

    $admin = provision_admin_connection();
    $q = static fn(string $v): string => mysqli_real_escape_string($admin, $v);

    $exists = mysqli_fetch_row(mysqli_query($admin, "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '" . $q($dbName) . "'"))[0];
    if ((int) $exists > 0) {
        throw new RuntimeException("A database called $dbName already exists on this server.");
    }
    $userExists = mysqli_fetch_row(mysqli_query($admin, "SELECT COUNT(*) FROM mysql.user WHERE user = '" . $q($dbUser) . "'"))[0];
    if ((int) $userExists > 0) {
        throw new RuntimeException("A MySQL user called $dbUser already exists on this server.");
    }

    $created = ['db' => false, 'user' => false, 'row' => false];
    try {
        mysqli_query($admin, 'CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4');
        $created['db'] = true;
        mysqli_query($admin, "CREATE USER '" . $q($dbUser) . "'@'localhost' IDENTIFIED BY '" . $q($dbPass) . "'");
        $created['user'] = true;
        mysqli_query($admin, 'GRANT ALL PRIVILEGES ON `' . $dbName . "`.* TO '" . $q($dbUser) . "'@'localhost'");
        mysqli_query($admin, 'FLUSH PRIVILEGES');

        // structure, default departments and roles
        mysqli_select_db($admin, $dbName);
        provision_run_script($admin, (string) file_get_contents($template));
        $pending = provision_apply_pending_migrations($admin);

        // the first owner: an Admin in the Operations department, with a random temporary password
        $hash = password_hash($ownerPassword, PASSWORD_DEFAULT);
        $role = 'Admin';
        $st = mysqli_prepare($admin, "INSERT INTO users (names, email, password_hash, role, phone, department_id, is_active) VALUES (?, ?, ?, ?, ?, (SELECT id FROM departments WHERE name = 'Operations' LIMIT 1), 1)");
        mysqli_stmt_bind_param($st, 'sssss', $ownerName, $ownerEmail, $hash, $role, $ownerPhone);
        mysqli_stmt_execute($st);

        // the company's own profile: its name, the owner's phone and email, and an invoice prefix from its initials
        $prefix = provision_invoice_prefix($name);
        $profilePhone = $ownerPhone ?? '';
        $st = mysqli_prepare($admin, 'INSERT INTO company_profile (id, display_name, phone, email, invoice_prefix) VALUES (1, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id');
        mysqli_stmt_bind_param($st, 'ssss', $name, $profilePhone, $ownerEmail, $prefix);
        mysqli_stmt_execute($st);

        // register it in the platform
        $enc = platform_encrypt($dbPass);
        $st = mysqli_prepare(platform_conn(), 'INSERT INTO companies (company_code, name, db_host, db_name, db_user, db_pass_enc, status, owner_name, owner_email, owner_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $host = 'localhost';
        mysqli_stmt_bind_param($st, 'ssssssssss', $code, $name, $host, $dbName, $dbUser, $enc, $status, $ownerName, $ownerEmail, $ownerPhone);
        mysqli_stmt_execute($st);
        $companyId = (int) mysqli_insert_id(platform_conn());
        $created['row'] = true;

        platform_audit($companyId, mm_cli_actor(), 'company_created', "code=$code db=$dbName pendingMigrations=" . count($pending));
    } catch (Throwable $e) {
        // undo everything that was created
        try {
            if ($created['row']) {
                $st = mysqli_prepare(platform_conn(), 'DELETE FROM companies WHERE company_code = ?');
                mysqli_stmt_bind_param($st, 's', $code);
                mysqli_stmt_execute($st);
            }
            if ($created['user']) {
                mysqli_query($admin, "DROP USER IF EXISTS '" . $q($dbUser) . "'@'localhost'");
            }
            if ($created['db']) {
                mysqli_query($admin, 'DROP DATABASE IF EXISTS `' . $dbName . '`');
            }
        } catch (Throwable $ignored) {
        }
        throw new RuntimeException('Could not create the company (everything was undone): ' . $e->getMessage(), 0, $e);
    }

    return [
        'company_id'     => $companyId,
        'code'           => $code,
        'db_name'        => $dbName,
        'owner_email'    => $ownerEmail,
        'owner_password' => $ownerPassword,
    ];
}

/**
 * Applies the PLATFORM database migrations (database/platform_migrations/*.sql) that have not run yet.
 * Version 001 is database/platform_schema.sql (made by the installer), so it is marked as done here.
 *
 * @return string[] the names of the migrations that were applied
 */
function platform_apply_migrations(mysqli $db): array
{
    mysqli_query($db, "CREATE TABLE IF NOT EXISTS platform_migrations (migration_name VARCHAR(190) NOT NULL, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (migration_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    mysqli_query($db, "INSERT IGNORE INTO platform_migrations (migration_name) VALUES ('001_initial')");
    $seen = [];
    $res = mysqli_query($db, 'SELECT migration_name FROM platform_migrations');
    while ($row = mysqli_fetch_row($res)) {
        $seen[$row[0]] = true;
    }
    $files = glob(__DIR__ . '/../database/platform_migrations/*.sql') ?: [];
    sort($files, SORT_STRING);
    $applied = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (isset($seen[$name])) {
            continue;
        }
        provision_run_script($db, (string) file_get_contents($file));
        $st = mysqli_prepare($db, 'INSERT INTO platform_migrations (migration_name) VALUES (?)');
        mysqli_stmt_bind_param($st, 's', $name);
        mysqli_stmt_execute($st);
        $applied[] = $name;
    }
    return $applied;
}

/** Changes a company's status (active / suspended / pending) and records who did it. */
function provision_set_status(string $code, string $status): void
{
    if (!in_array($status, ['pending', 'active', 'suspended'], true)) {
        throw new RuntimeException('Unknown status.');
    }
    $company = platform_company_by_code($code);
    if (!$company) {
        throw new RuntimeException('There is no company with that code.');
    }
    $st = mysqli_prepare(platform_conn(), 'UPDATE companies SET status = ? WHERE id = ?');
    $id = (int) $company['id'];
    mysqli_stmt_bind_param($st, 'si', $status, $id);
    mysqli_stmt_execute($st);
    platform_audit($id, mm_cli_actor(), 'status_' . $status, 'was ' . $company['status']);
}
