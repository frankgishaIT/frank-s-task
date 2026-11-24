<?php
/** NEW FILE: modules/fin_accounts/account.php — one financial account (details, funds held, activity): shown by activity.php. */
$_GET['account'] = (int) ($_GET['id'] ?? $_GET['account'] ?? 0);
require __DIR__ . '/activity.php';