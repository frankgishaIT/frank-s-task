<?php
/**
 * Emails sent by the platform (sign-up received, approved, rejected, "set your password").
 * They use the same sender as the rest of MY MOTIVE (includes/mailer.php and config/mail.php).
 *
 * Every attempt is recorded in the platform_emails table, so the admin panel (Emails page) can show what was sent,
 * what failed and why.
 *
 * Test mode: set  mail_dry_run  to true in config/platform.php. Nothing is sent. Each message is recorded in full
 * (links included) and also written to storage/platform_outbox.log, so you can read it without a mail server.
 * In live mode the recorded copy hides the secret part of one-time links.
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
        $ok = file_put_contents($dir . '/platform_outbox.log', $entry, FILE_APPEND | LOCK_EX) !== false;
        platform_email_log($toEmail, $toName, $subject, 'dry_run', null, $bodyHtml, true);
        return $ok;
    }

    $ok = false;
    $error = null;
    if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
        $error = 'The mail library is not installed on this server (vendor/autoload.php is missing).';
    } else {
        // includes/mailer.php writes the real reason for a failure to the PHP log: read it for the duration of the call
        $tmp = tempnam(sys_get_temp_dir(), 'mmmail');
        ini_set('error_log', $tmp);
        try {
            require_once __DIR__ . '/mailer.php';
            $ok = send_email($toEmail, $toName, $subject, $bodyHtml);
        } catch (Throwable $e) {
            $ok = false;
            error_log('Email send failed to ' . $toEmail . ': ' . $e->getMessage());
        }
        ini_restore('error_log');
        if (!$ok) {
            $log = (string) @file_get_contents($tmp);
            $error = trim((string) preg_replace('/^.*Email send failed to [^:]+: /s', '', trim($log)));
            if ($error === '') {
                $error = 'The mail server did not accept the message.';
            }
            error_log('[MY MOTIVE] email to ' . $toEmail . ' failed: ' . $error);
        }
        @unlink($tmp);
    }
    platform_email_log($toEmail, $toName, $subject, $ok ? 'sent' : 'failed', $error, $bodyHtml, false);
    return $ok;
}

/** Records one email. A problem here must never stop the email itself, so every error is swallowed. */
function platform_email_log(string $to, string $name, string $subject, string $status, ?string $error, string $bodyHtml, bool $keepLinks): void
{
    try {
        $body = $keepLinks ? $bodyHtml : (string) preg_replace('/token=[a-f0-9]{64}/', 'token=[hidden]', $bodyHtml);
        $to = substr($to, 0, 150);
        $name = substr($name, 0, 150);
        $subject = substr($subject, 0, 250);
        $error = $error === null ? null : substr($error, 0, 500);
        $st = mysqli_prepare(platform_conn(), 'INSERT INTO platform_emails (to_email, to_name, subject, status, error, body_html) VALUES (?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($st, 'ssssss', $to, $name, $subject, $status, $error, $body);
        mysqli_stmt_execute($st);
        if (random_int(1, 50) === 1) {   // now and then, forget emails older than 90 days
            mysqli_query(platform_conn(), 'DELETE FROM platform_emails WHERE created_at < (NOW() - INTERVAL 90 DAY)');
        }
    } catch (Throwable $e) {
        error_log('[MY MOTIVE] could not record an email in the log: ' . $e->getMessage());
    }
}

/** A plain, readable email frame. */
function platform_mail_wrap(string $heading, string $bodyHtml): string
{
    return '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#1E2333;line-height:1.55">'
        . '<h2 style="color:#2E3FC0;margin-bottom:6px">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h2>'
        . $bodyHtml
        . '<p style="color:#8A90A3;font-size:12px;margin-top:28px">MY MOTIVE</p></div>';
}
