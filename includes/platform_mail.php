<?php
/**
 * Emails sent by the platform (sign-up received, approved, rejected, "set your password").
 * They use the same sender as the rest of MY MOTIVE (includes/mailer.php and config/mail.php).
 *
 * On a staging server set  mail_dry_run  to true in config/platform.php: nothing is sent, and each
 * message is written to storage/platform_outbox.log instead, so you can read it without a mail server.
 */

require_once __DIR__ . '/platform.php';

function platform_send_email(string $toEmail, string $toName, string $subject, string $bodyHtml): bool
{
    if (platform_setting('mail_dry_run', false)) {
        $dir = __DIR__ . '/../storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $text = trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/h[1-6]|/li)\s*/?>#i', "\n", $bodyHtml)), ENT_QUOTES, 'UTF-8'));
        $entry = "==== " . date('Y-m-d H:i:s') . " ====\nTo: $toName <$toEmail>\nSubject: $subject\n\n$text\n\n";
        return file_put_contents($dir . '/platform_outbox.log', $entry, FILE_APPEND | LOCK_EX) !== false;
    }
    require_once __DIR__ . '/mailer.php';
    return send_email($toEmail, $toName, $subject, $bodyHtml);
}

/** A plain, readable email frame. */
function platform_mail_wrap(string $heading, string $bodyHtml): string
{
    return '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#1E2333;line-height:1.55">'
        . '<h2 style="color:#2E3FC0;margin-bottom:6px">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h2>'
        . $bodyHtml
        . '<p style="color:#8A90A3;font-size:12px;margin-top:28px">MY MOTIVE</p></div>';
}
