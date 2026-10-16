<?php
/**
 * CHANGED: retired. Deleting a notification is now done with the "Delete" button
 * (modules/notifications/action.php, POST, with a confirmation). This old address used a
 * plain link, which a browser preview or a shared link could trigger and so delete
 * notifications without a click. It now changes nothing and just goes back to the list.
 */
require '../../config/db.php';
header('Location: index.php');
exit;