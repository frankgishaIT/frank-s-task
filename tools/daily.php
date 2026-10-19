<?php
/**
 * The daily job, by hand. Run as the Linux root user.
 *
 *   php tools/daily.php --dry-run                 show what would happen today, send and change NOTHING
 *   php tools/daily.php --dry-run --code=RMCP0001 the same for one company
 *   php tools/daily.php --run                     really run it now (the worker also runs it once a day by itself)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/daily.php';

$dry = in_array('--dry-run', $argv, true);
$run = in_array('--run', $argv, true);
$code = null;
foreach ($argv as $a) {
    if (strpos($a, '--code=') === 0) {
        $code = substr($a, 7);
    }
}
if (!$dry && !$run) {
    echo "Usage:\n  php tools/daily.php --dry-run [--code=RMCP0001]   preview (nothing is sent or changed)\n  php tools/daily.php --run [--code=RMCP0001]       really run it now\n";
    exit(0);
}
try {
    echo ($dry ? "PREVIEW: nothing is sent or changed.\n" : "RUNNING for real.\n");
    foreach (daily_run($dry, $code) as $line) {
        echo '  ' . $line . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
