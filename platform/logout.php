<?php
require_once __DIR__ . '/../includes/platform_web.php';
platform_session_start('MM_PLATFORM');
$_SESSION = [];
session_destroy();
header('Location: ' . BASE_URL . '/platform/login.php');
exit;
