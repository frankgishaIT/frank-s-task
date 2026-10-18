<?php
/**
 * "I forgot my password", for the people of every company.
 *
 * The person gives the company code and their email. If they match an active account, an email with a one-hour,
 * one-time link is sent. The page ALWAYS gives the same answer, so nobody can use it to find out who has an account.
 */

require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/mail_templates.php';
require_once __DIR__ . '/platform_mail.php';

const PWRESET_VALID_MINUTES = 60;

/** Sends the reset email if (and only if) the company and the email match an active account. Prints nothing, returns nothing. */
function pwreset_request(string $companyCode, string $email, string $ip): void
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $company = platform_company_by_code($companyCode);
    if (!$company || $company['status'] !== 'active') {
        return;
    }
    try {
        $tenant = tenant_connect($company);
        $st = mysqli_prepare($tenant, 'SELECT names FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        mysqli_stmt_bind_param($st, 's', $email);
        mysqli_stmt_execute($st);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        mysqli_close($tenant);
    } catch (Throwable $e) {
        error_log('[MY MOTIVE reset] ' . $e->getMessage());
        return;
    }
    if (!$user) {
        return;
    }
    $db = platform_conn();
    $companyId = (int) $company['id'];
    $st = mysqli_prepare($db, 'UPDATE password_resets SET expires_at = (NOW() - INTERVAL 1 SECOND) WHERE company_id = ? AND email = ? AND used_at IS NULL');
    mysqli_stmt_bind_param($st, 'is', $companyId, $email);
    mysqli_stmt_execute($st);

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $minutes = PWRESET_VALID_MINUTES;
    $st = mysqli_prepare($db, 'INSERT INTO password_resets (company_id, email, token_hash, expires_at, ip) VALUES (?, ?, ?, (NOW() + INTERVAL ? MINUTE), ?)');
    mysqli_stmt_bind_param($st, 'issis', $companyId, $email, $hash, $minutes, $ip);
    mysqli_stmt_execute($st);
    platform_audit($companyId, 'user:' . $email, 'password_reset_requested', '');

    $link = platform_public_url() . '/reset_password.php?token=' . $token;
    [$subject, $html] = mm_mail_password_reset($company['name'], (string) $user['names'], $link);
    platform_send_email($email, (string) $user['names'], $subject, $html);
}

/** The reset behind a link, if the link is genuine, unused and not expired. */
function pwreset_lookup(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $hash = hash('sha256', $token);
    $st = mysqli_prepare(platform_conn(), 'SELECT r.*, c.company_code, c.name AS company_name FROM password_resets r JOIN companies c ON c.id = r.company_id WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() AND c.status = \'active\' LIMIT 1');
    mysqli_stmt_bind_param($st, 's', $hash);
    mysqli_stmt_execute($st);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: null;
}

/** Sets the new password in the company's own database and uses up the link. Throws if it cannot. */
function pwreset_apply(array $reset, string $newPassword): void
{
    $company = platform_company_by_id((int) $reset['company_id']);
    if (!$company || $company['status'] !== 'active') {
        throw new RuntimeException('This company account is not active.');
    }
    $db = platform_conn();
    $id = (int) $reset['id'];
    // use up the link FIRST, atomically: two clicks at once cannot both succeed
    $st = mysqli_prepare($db, 'UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL AND expires_at > NOW()');
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    if (mysqli_stmt_affected_rows($st) !== 1) {
        throw new RuntimeException('This link was already used or has expired.');
    }
    try {
        $tenant = tenant_connect($company);
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $st = mysqli_prepare($tenant, 'UPDATE users SET password_hash = ? WHERE email = ? AND is_active = 1 LIMIT 1');
        mysqli_stmt_bind_param($st, 'ss', $hash, $reset['email']);
        mysqli_stmt_execute($st);
        if (mysqli_stmt_affected_rows($st) !== 1) {
            throw new RuntimeException('The account was not found.');
        }
        mysqli_close($tenant);
    } catch (Throwable $e) {
        $st = mysqli_prepare($db, 'UPDATE password_resets SET used_at = NULL WHERE id = ?');
        mysqli_stmt_bind_param($st, 'i', $id);
        mysqli_stmt_execute($st);
        throw $e;
    }
    // any other link for this person is no longer needed
    $cid = (int) $company['id'];
    $st = mysqli_prepare($db, 'UPDATE password_resets SET expires_at = (NOW() - INTERVAL 1 SECOND) WHERE company_id = ? AND email = ? AND used_at IS NULL');
    mysqli_stmt_bind_param($st, 'is', $cid, $reset['email']);
    mysqli_stmt_execute($st);
    platform_audit($cid, 'user:' . $reset['email'], 'password_reset_done', '');
}
