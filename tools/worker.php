<?php
/**
 * The worker: creates the database of every business whose payment was recorded.
 * It must run as the Linux root user (it creates databases), every minute, from cron:
 *
 *   * * * * * cd /var/www/mymotive-staging && flock -n /tmp/mm-worker.lock php tools/worker.php >> /var/log/mm-worker.log 2>&1
 *
 * "flock -n" makes sure two runs never overlap. The website itself never creates databases.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/provisioning.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/daily.php';
require_once __DIR__ . '/../includes/business_delete.php';

try {
    $db = platform_conn();
    @touch(signup_worker_heartbeat_file());
    @chmod(signup_worker_heartbeat_file(), 0644);

    // a request stuck in "provisioning" for 15 minutes means a worker died: put it back in the queue
    mysqli_query($db, "UPDATE signup_requests SET status = 'activating' WHERE status = 'provisioning' AND updated_at < (NOW() - INTERVAL 15 MINUTE)");

    $ids = [];
    $res = mysqli_query($db, "SELECT id FROM signup_requests WHERE status = 'activating' ORDER BY id LIMIT 20");
    while ($row = mysqli_fetch_row($res)) {
        $ids[] = (int) $row[0];
    }
    // payments still waiting for the phone: ask XentriPay, so no payment is lost if the customer closed the page
    foreach (payment_reconcile() as $line) {
        echo date('Y-m-d H:i:s') . ' payment ' . $line . "\n";
    }

    // a payment confirmed just now may have queued a company
    $ids = [];
    $res = mysqli_query($db, "SELECT id FROM signup_requests WHERE status = 'activating' ORDER BY id LIMIT 20");
    while ($row = mysqli_fetch_row($res)) {
        $ids[] = (int) $row[0];
    }
    foreach ($ids as $id) {
        echo date('Y-m-d H:i:s') . ' ' . signup_activate_one($id) . "\n";
    }

    // once a day: renewal reminders, pausing companies that did not renew, and each business's SMS reminders
    foreach (company_purge_pending() as $purgeLine) {
        echo date('Y-m-d H:i:s') . ' ' . $purgeLine . "\n";
    }
    $daily = daily_maybe_run();
    if ($daily !== null) {
        echo date('Y-m-d H:i:s') . " daily job:\n";
        foreach ($daily as $line) {
            echo '    ' . $line . "\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' WORKER ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
