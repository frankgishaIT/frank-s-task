<?php
// NEW FILE: modules/asset_management/executive_delete.php — Task I: delete an asset (Managing Director / authorized only).
require '../../config/db.php';
require_once '../../includes/executive_delete_page.php';
exec_delete_page($conn, 'asset');