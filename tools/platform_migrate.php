<?php
/**
 * Applies the platform database migrations that have not run yet. Run it after every update:
 *   php tools/platform_migrate.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/provisioning.php';
try {
    $applied = platform_apply_migrations(platform_conn());
    echo $applied ? "Applied: " . implode(', ', $applied) . "\n" : "The platform database is up to date.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
