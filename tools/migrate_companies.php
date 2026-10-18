<?php
/**
 * Applies the database changes (files in migrations/) that a company's database has not seen yet.
 * Run it as the Linux root user after every update that adds a file to migrations/.
 *
 *   php tools/migrate_companies.php                 every company EXCEPT company 1
 *   php tools/migrate_companies.php --code=ACME     one company
 *   php tools/migrate_companies.php --code=RM       company 1 as well, when you ask for it by name
 *
 * Company 1 (your live database) is normally updated by deploy.sh when you push to main. The two use the
 * same ledger (the schema_migrations table), so whichever runs first, the other sees it is done.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/provisioning.php';

$only = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--code=(.+)$/', $arg, $m)) {
        $only = platform_normalize_code($m[1]);
    }
}
$failed = 0;
try {
    $admin = provision_admin_connection();
    $res = mysqli_query(platform_conn(), 'SELECT id, company_code, db_name FROM companies ORDER BY id');
    $companies = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $companies[] = $row;
    }
    foreach ($companies as $co) {
        if ($only !== null ? $co['company_code'] !== $only : (int) $co['id'] === 1) {
            continue;
        }
        try {
            mysqli_select_db($admin, $co['db_name']);
            $applied = provision_apply_pending_migrations($admin);
            printf("%-14s %s\n", $co['company_code'], $applied ? 'applied: ' . implode(', ', $applied) : 'already up to date');
        } catch (Throwable $e) {
            $failed++;
            printf("%-14s FAILED: %s\n", $co['company_code'], $e->getMessage());
        }
    }
    if ($only !== null && !array_filter($companies, static fn($c) => $c['company_code'] === $only)) {
        fwrite(STDERR, "There is no company with the code $only.\n");
        exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
exit($failed > 0 ? 1 : 0);
