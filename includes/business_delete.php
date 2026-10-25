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
