<?php
/**
 * CHANGED: retired. Marking a notification as read is now done with the "Mark Read" button
 * (modules/notifications/action.php, POST, own notifications only). This old address used a
 * plain link, which a browser preview or a shared link could trigger, and let an Admin mark
 * other people's notifications as read. It now changes nothing and just goes back to the list.
 */
require '../../config/db.php';
header('Location: index.php');
exit;