<?php
require_once __DIR__ . '/../includes/platform_web.php';
require_once __DIR__ . '/../includes/platform_mail.php';

$admin = platform_require_admin();
$dry = (bool) platform_setting('mail_dry_run', false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    $ok = platform_send_email($admin['email'], $admin['name'], 'MY MOTIVE test email', platform_mail_wrap('It works', '<p>This is a test message from the MY MOTIVE platform. If you can read it, your mail settings are correct.</p>'));
    if ($dry) {
        platform_flash('Test mode is ON, so nothing was really sent. The message is recorded below.', 'warning');
    } elseif ($ok) {
        platform_flash('Test email sent to ' . $admin['email'] . '. Check that inbox (and the spam folder).');
    } else {
        $why = '';
        $row = mysqli_fetch_row(mysqli_query(platform_conn(), "SELECT error FROM platform_emails WHERE status = 'failed' ORDER BY id DESC LIMIT 1"));
        $why = $row[0] ?? '';
        platform_flash('The test email could not be sent. ' . $why, 'danger');
    }
    header('Location: emails.php');
    exit;
}

$id = (is_string($_GET['id'] ?? null) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
$statusFilter = (is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['sent', 'failed', 'dry_run'], true)) ? $_GET['status'] : null;

platform_page_start('Emails', $admin, 'emails');

if ($id > 0) {
    $st = mysqli_prepare(platform_conn(), 'SELECT * FROM platform_emails WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $mail = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    echo '<p><a href="emails.php">&larr; All emails</a></p>';
    if (!$mail) {
        echo '<div class="alert alert-warning">That email does not exist.</div>';
    } else {
        $text = trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/h[1-6]|/li)\s*/?>#i', "\n", (string) $mail['body_html'])), ENT_QUOTES, 'UTF-8'));
        $shown = h($text);
        $shown = preg_replace('~(https?://[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener">$1</a>', $shown);
        echo '<div class="card p-3"><table class="table table-sm mb-3">'
            . '<tr><th style="width:110px">To</th><td>' . h($mail['to_name']) . ' &lt;' . h($mail['to_email']) . '&gt;</td></tr>'
            . '<tr><th>Subject</th><td>' . h($mail['subject']) . '</td></tr>'
            . '<tr><th>Status</th><td>' . h($mail['status'] === 'dry_run' ? 'not sent (test mode)' : $mail['status']) . '</td></tr>'
            . '<tr><th>Time</th><td>' . h($mail['created_at']) . '</td></tr>'
            . ($mail['error'] ? '<tr><th>Reason</th><td class="text-danger">' . h($mail['error']) . '</td></tr>' : '')
            . '</table><div style="white-space:pre-wrap;background:#F6F7FB;border-radius:10px;padding:14px">' . $shown . '</div></div>';
    }
} else {
    echo '<div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0">Emails</h4>'
        . '<form method="POST">' . platform_csrf_field() . '<button class="btn btn-outline-primary btn-sm" type="submit">Send a test email to me</button></form></div>';
    echo $dry
        ? '<div class="alert alert-warning">Test mode is <b>ON</b>: emails are <b>not sent</b>. They are only recorded here, so you can read them and open their links.</div>'
        : '<div class="alert alert-success">Live mode: emails are really sent to their recipients.</div>';
    echo '<p><a class="btn btn-sm ' . ($statusFilter === null ? 'btn-primary' : 'btn-outline-primary') . '" href="emails.php">All</a> ';
    foreach (['sent' => 'sent', 'failed' => 'failed', 'dry_run' => 'test mode'] as $key => $label) {
        echo '<a class="btn btn-sm ' . ($statusFilter === $key ? 'btn-primary' : 'btn-outline-primary') . '" href="emails.php?status=' . h($key) . '">' . h($label) . '</a> ';
    }
    echo '</p>';
    if ($statusFilter !== null) {
        $st = mysqli_prepare(platform_conn(), 'SELECT id, to_email, subject, status, error, created_at FROM platform_emails WHERE status = ? ORDER BY id DESC LIMIT 150');
        mysqli_stmt_bind_param($st, 's', $statusFilter);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
    } else {
        $res = mysqli_query(platform_conn(), 'SELECT id, to_email, subject, status, error, created_at FROM platform_emails ORDER BY id DESC LIMIT 150');
    }
    echo '<div class="card p-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Time</th><th>To</th><th>Subject</th><th>Status</th><th></th></tr></thead><tbody>';
    $n = 0;
    while ($r = mysqli_fetch_assoc($res)) {
        $n++;
        $badge = $r['status'] === 'sent' ? 'success' : ($r['status'] === 'failed' ? 'danger' : 'secondary');
        echo '<tr><td>' . h($r['created_at']) . '</td><td>' . h($r['to_email']) . '</td><td>' . h($r['subject']) . ($r['error'] ? '<br><small class="text-danger">' . h($r['error']) . '</small>' : '') . '</td>'
            . '<td><span class="badge bg-' . $badge . '">' . h($r['status'] === 'dry_run' ? 'test mode' : $r['status']) . '</span></td>'
            . '<td class="text-end"><a href="emails.php?id=' . (int) $r['id'] . '">Open</a></td></tr>';
    }
    if ($n === 0) {
        echo '<tr><td colspan="5" class="text-muted">No emails yet.</td></tr>';
    }
    echo '</tbody></table></div></div>';
}
platform_page_end();
