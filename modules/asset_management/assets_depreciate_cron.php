<?php
// Optional: point Windows Task Scheduler (or any cron) at this file once a
// day, e.g. just after midnight, for exact daily timing instead of relying
// on someone opening the Assets module. Safe to run more than once a day —
// it only ever adds today's row if it isn't there yet (asset_run_depreciation_catchup
// uses INSERT IGNORE keyed on asset_id + date).
//
// Example (Windows Task Scheduler, daily at 00:05):
//   Program: C:\xampp2\php\php.exe
//   Arguments: "C:\xampp2\htdocs\rm_os\modules\assets\depreciate_cron.php"

require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../includes/asset_helpers.php';

asset_run_depreciation_catchup_all($conn);

echo 'Asset depreciation catch-up complete: ' . date('Y-m-d H:i:s') . "\n";