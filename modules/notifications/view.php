<?php
require '../../config/db.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

$id = $_GET['id'];

$query = "
SELECT notifications.*, users.names
FROM notifications
LEFT JOIN users
ON notifications.user_id = users.id
WHERE notifications.id = $id
";

$result = mysqli_query($conn, $query);
$notification = mysqli_fetch_assoc($result);
?>

<div class="card shadow">

    <div class="card-header">
        <h3>Notification Details</h3>
    </div>

    <div class="card-body">

        <table class="table table-bordered">

            <tr>
                <th width="200">User</th>
                <td><?= $notification['names']; ?></td>
            </tr>

            <tr>
                <th>Title</th>
                <td><?= $notification['title']; ?></td>
            </tr>

            <tr>
                <th>Message</th>
                <td><?= $notification['message']; ?></td>
            </tr>

            <tr>
                <th>Status</th>
                <td>
                    <?= $notification['is_read'] ? "Read" : "Unread"; ?>
                </td>
            </tr>

            <tr>
                <th>Date</th>
                <td><?= date('d M Y H:i', strtotime($notification['created_at'])); ?></td>
            </tr>

        </table>

        <a href="index.php" class="btn btn-secondary">
            Back
        </a>

    </div>

</div>

<?php
include '../../includes/footer.php';
?>