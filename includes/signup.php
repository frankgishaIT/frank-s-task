<?php
/**
 * MY MOTIVE SaaS: company sign-up, approval, payment and activation.
 *
 * The life of a request:
 *   pending      the company filled in the form                      (nothing is created yet)
 *   approved     you accepted it; the company must now pay           (the company code is fixed here)
 *   activating   payment recorded; waiting for the worker            (a worker running as root creates the database)
 *   provisioning the worker is busy with it
 *   active       the company exists and the owner was invited
 *   rejected     you refused it (with a reason)
 *   failed       the worker hit a problem; "Retry" puts it back to activating
 *
 * Every change of status is made with "... WHERE status = <the status we expect>", so a double click,
 * or two admins acting at once, can never push a request through twice.
 */

require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/platform_mail.php';

class SignupException extends RuntimeException
{
}

const SIGNUP_OPEN_STATUSES = "'pending','approved','activating','provisioning','failed'";
const SIGNUP_PAYMENT_METHODS = ['MTN MoMo', 'Airtel Money', 'Bank transfer', 'Cash', 'Other'];

// ---------------------------------------------------------------- packages and prices

function signup_packages(bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM packages' . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id';
    $out = [];
    $res = mysqli_query(platform_conn(), $sql);
    while ($row = mysqli_fetch_assoc($res)) {
        $out[] = $row;
    }
    return $out;
}

function signup_package(int $id): ?array
{
    $st = mysqli_prepare(platform_conn(), 'SELECT * FROM packages WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: null;
}

/** The price for one period, in RWF. A year is 12 months minus the yearly discount. */
function signup_price(array $package, string $period): int
{
    $monthly = (int) $package['monthly_price'];
    if ($period === 'yearly') {
        return (int) round($monthly * 12 * (100 - (int) $package['yearly_discount_percent']) / 100);
    }
    return $monthly;
}

function signup_money(int $rwf): string
{
    return number_format($rwf, 0, '.', ',') . ' RWF';
}

/** Adds whole months to a date without overflowing: 31 January + 1 month = 28 (or 29) February. */
function signup_add_months(DateTimeImmutable $date, int $months): DateTimeImmutable
{
    $day = (int) $date->format('j');
    $first = $date->modify('first day of this month')->modify("+$months month");
    $last = (int) $first->format('t');
    return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, $last));
}

// ---------------------------------------------------------------- the sign-up form

/**
 * Checks what the visitor typed. Returns the cleaned values, or null (and fills $errors) if something is wrong.
 * Only plain text is accepted for every field.
 *
 * @param array<string,string> $errors
 */
function signup_clean(array $in, array &$errors): ?array
{
    $errors = [];
    $text = static function (string $key, int $min, int $max, string $label) use ($in, &$errors): string {
        $v = $in[$key] ?? '';
        if (!is_string($v)) {
            $errors[$key] = "$label is not valid.";
            return '';
        }
        $v = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v)));
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) {
            $errors[$key] = $min > 0 ? "$label must be between $min and $max characters." : "$label is too long.";
        }
        return $v;
    };

    $companyName = $text('company_name', 2, 150, 'The company name');
    $ownerName = $text('owner_name', 2, 150, 'Your name');
    $email = strtolower($text('owner_email', 5, 150, 'The email address'));
    if (!isset($errors['owner_email']) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['owner_email'] = 'Please enter a valid email address.';
    }
    $phone = preg_replace('/[\s().-]+/', '', (string) (is_string($in['owner_phone'] ?? null) ? $in['owner_phone'] : ''));
    if (!preg_match('/^\+?[0-9]{9,15}$/', $phone)) {
        $errors['owner_phone'] = 'Please enter a valid phone number (digits only, 9 to 15 digits).';
    }
    $tin = $text('tin', 0, 30, 'The TIN');
    if (!isset($errors['tin']) && $tin !== '' && !preg_match('/^[A-Za-z0-9\/ -]+$/', $tin)) {
        $errors['tin'] = 'The TIN may only contain letters, digits, spaces, - and /.';
    }
    $packageId = (is_string($in['package_id'] ?? null) && ctype_digit($in['package_id'])) ? (int) $in['package_id'] : 0;
    $package = $packageId > 0 ? signup_package($packageId) : null;
    if (!$package || !(int) $package['is_active']) {
        $errors['package_id'] = 'Please choose a package.';
    }
    $period = $in['billing_period'] ?? '';
    if (!is_string($period) || !in_array($period, ['monthly', 'yearly'], true)) {
        $errors['billing_period'] = 'Please choose monthly or yearly billing.';
    }
    if (($in['terms'] ?? '') !== '1') {
        $errors['terms'] = 'You must accept the terms to continue.';
    }
    if ($errors) {
        return null;
    }
    return [
        'company_name'   => $companyName,
        'owner_name'     => $ownerName,
        'owner_email'    => $email,
        'owner_phone'    => $phone,
        'tin'            => $tin === '' ? null : $tin,
        'package_id'     => (int) $package['id'],
        'billing_period' => $period,
        'amount_due'     => signup_price($package, $period),
    ];
}

function signup_new_reference(): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 20; $try++) {
        $tail = '';
        for ($i = 0; $i < 4; $i++) {
            $tail .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $ref = 'SU-' . date('ymd') . '-' . $tail;
        $st = mysqli_prepare(platform_conn(), 'SELECT COUNT(*) FROM signup_requests WHERE reference = ?');
        mysqli_stmt_bind_param($st, 's', $ref);
        mysqli_stmt_execute($st);
        if ((int) mysqli_fetch_row(mysqli_stmt_get_result($st))[0] === 0) {
            return $ref;
        }
    }
    throw new RuntimeException('Could not make a reference number.');
}

/** Saves a cleaned request. Refuses an email that already has an open request or a company. */
function signup_create(array $clean, string $ip): array
{
    $db = platform_conn();
    $st = mysqli_prepare($db, 'SELECT COUNT(*) FROM signup_requests WHERE owner_email = ? AND status IN (' . SIGNUP_OPEN_STATUSES . ')');
    mysqli_stmt_bind_param($st, 's', $clean['owner_email']);
    mysqli_stmt_execute($st);
    $open = (int) mysqli_fetch_row(mysqli_stmt_get_result($st))[0];
    $st = mysqli_prepare($db, 'SELECT COUNT(*) FROM companies WHERE owner_email = ?');
    mysqli_stmt_bind_param($st, 's', $clean['owner_email']);
    mysqli_stmt_execute($st);
    $existing = (int) mysqli_fetch_row(mysqli_stmt_get_result($st))[0];
    if ($open > 0 || $existing > 0) {
        throw new SignupException('This email address already has a request or a company. Please contact support if you need help.');
    }

    $reference = signup_new_reference();
    $st = mysqli_prepare($db, 'INSERT INTO signup_requests (reference, company_name, owner_name, owner_email, owner_phone, tin, package_id, billing_period, amount_due, terms_accepted_at, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)');
    mysqli_stmt_bind_param($st, 'ssssssisis', $reference, $clean['company_name'], $clean['owner_name'], $clean['owner_email'], $clean['owner_phone'], $clean['tin'], $clean['package_id'], $clean['billing_period'], $clean['amount_due'], $ip);
    mysqli_stmt_execute($st);
    $id = (int) mysqli_insert_id($db);
    platform_audit(null, 'applicant:' . $clean['owner_email'], 'signup_received', "ref=$reference");
    $req = signup_get($id);

    signup_email_received($req);
    $notify = (string) platform_setting('admin_notify_email', '');
    if ($notify !== '') {
        platform_send_email($notify, 'MY MOTIVE admin', 'New sign-up request ' . $reference, platform_mail_wrap('New sign-up request', '<p><b>' . h_mail($req['company_name']) . '</b> (' . h_mail($req['owner_email']) . ') has asked to join. Reference ' . h_mail($reference) . '.</p>'));
    }
    return $req;
}

function h_mail($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------- reading requests

function signup_get(int $id): ?array
{
    $st = mysqli_prepare(platform_conn(), 'SELECT r.*, p.name AS package_name, p.code AS package_code FROM signup_requests r JOIN packages p ON p.id = r.package_id WHERE r.id = ? LIMIT 1');
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: null;
}

function signup_list(?string $status = null): array
{
    $sql = 'SELECT r.*, p.name AS package_name FROM signup_requests r JOIN packages p ON p.id = r.package_id';
    if ($status !== null) {
        $st = mysqli_prepare(platform_conn(), $sql . ' WHERE r.status = ? ORDER BY r.id DESC LIMIT 300');
        mysqli_stmt_bind_param($st, 's', $status);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
    } else {
        $res = mysqli_query(platform_conn(), $sql . ' ORDER BY r.id DESC LIMIT 300');
    }
    $out = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $out[] = $row;
    }
    return $out;
}

function signup_counts(): array
{
    $counts = ['pending' => 0, 'approved' => 0, 'activating' => 0, 'failed' => 0, 'active_companies' => 0, 'suspended_companies' => 0];
    $res = mysqli_query(platform_conn(), "SELECT status, COUNT(*) FROM signup_requests GROUP BY status");
    while ($r = mysqli_fetch_row($res)) {
        if (isset($counts[$r[0]])) {
            $counts[$r[0]] = (int) $r[1];
        }
    }
    $counts['activating'] += (int) mysqli_fetch_row(mysqli_query(platform_conn(), "SELECT COUNT(*) FROM signup_requests WHERE status = 'provisioning'"))[0];
    $res = mysqli_query(platform_conn(), "SELECT status, COUNT(*) FROM companies GROUP BY status");
    while ($r = mysqli_fetch_row($res)) {
        if ($r[0] === 'active') {
            $counts['active_companies'] = (int) $r[1];
        } elseif ($r[0] === 'suspended') {
            $counts['suspended_companies'] = (int) $r[1];
        }
    }
    return $counts;
}

// ---------------------------------------------------------------- the admin's decisions

/** True if this company code is already used by a company or reserved by another open request. */
function signup_code_taken(string $code, ?int $exceptRequestId = null): bool
{
    $db = platform_conn();
    $st = mysqli_prepare($db, 'SELECT COUNT(*) FROM companies WHERE company_code = ?');
    mysqli_stmt_bind_param($st, 's', $code);
    mysqli_stmt_execute($st);
    if ((int) mysqli_fetch_row(mysqli_stmt_get_result($st))[0] > 0) {
        return true;
    }
    $except = $exceptRequestId ?? 0;
    $st = mysqli_prepare($db, "SELECT COUNT(*) FROM signup_requests WHERE company_code = ? AND status <> 'rejected' AND id <> ?");
    mysqli_stmt_bind_param($st, 'si', $code, $except);
    mysqli_stmt_execute($st);
    return (int) mysqli_fetch_row(mysqli_stmt_get_result($st))[0] > 0;
}

/** A short code taken from the company name, made unique with a number if needed. */
function signup_suggest_code(string $companyName): string
{
    $first = strtok(trim($companyName), ' ') ?: '';
    $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $first));
    if (strlen($base) < 2) {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $companyName));
    }
    $base = substr($base, 0, 12);
    if (strlen($base) < 2) {
        $base = str_pad($base, 2, 'X');
    }
    $code = $base;
    for ($n = 2; signup_code_taken($code) && $n < 1000; $n++) {
        $code = $base . $n;
    }
    return $code;
}

function signup_approve(int $id, string $code, string $adminEmail): void
{
    $code = platform_normalize_code($code);
    if (!platform_valid_code($code)) {
        throw new SignupException('The company code must be 2 to 30 characters: letters, digits, - or _.');
    }
    if (signup_code_taken($code, $id)) {
        throw new SignupException("The code $code is already used. Choose another one.");
    }
    $st = mysqli_prepare(platform_conn(), "UPDATE signup_requests SET status = 'approved', company_code = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
    mysqli_stmt_bind_param($st, 'ssi', $code, $adminEmail, $id);
    mysqli_stmt_execute($st);
    if (mysqli_stmt_affected_rows($st) !== 1) {
        throw new SignupException('This request was already handled by someone else.');
    }
    $req = signup_get($id);
    platform_audit(null, 'admin:' . $adminEmail, 'signup_approved', 'ref=' . $req['reference'] . " code=$code");
    signup_email_approved($req);
}

function signup_reject(int $id, string $reason, string $adminEmail): void
{
    $reason = trim(preg_replace('/\s+/u', ' ', $reason));
    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
        throw new SignupException('Please give a reason (3 to 500 characters). The applicant will read it.');
    }
    $st = mysqli_prepare(platform_conn(), "UPDATE signup_requests SET status = 'rejected', reject_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status IN ('pending','approved')");
    mysqli_stmt_bind_param($st, 'ssi', $reason, $adminEmail, $id);
    mysqli_stmt_execute($st);
    if (mysqli_stmt_affected_rows($st) !== 1) {
        throw new SignupException('This request can no longer be rejected.');
    }
    $req = signup_get($id);
    platform_audit(null, 'admin:' . $adminEmail, 'signup_rejected', 'ref=' . $req['reference']);
    signup_email_rejected($req);
}

/** Records that the company paid, and queues the creation of its database. The worker does the rest. */
function signup_record_payment(int $id, string $method, string $reference, int $amount, string $adminEmail): void
{
    $reference = trim($reference);
    if (!in_array($method, SIGNUP_PAYMENT_METHODS, true)) {
        throw new SignupException('Choose how the company paid.');
    }
    if ($reference === '' || mb_strlen($reference) > 100) {
        throw new SignupException('Enter the payment reference (for example the MoMo transaction number).');
    }
    $req = signup_get($id);
    if (!$req || $req['status'] !== 'approved') {
        throw new SignupException('Only an approved request can be activated.');
    }
    if ($amount < (int) $req['amount_due']) {
        throw new SignupException('The amount is less than the ' . signup_money((int) $req['amount_due']) . ' due.');
    }
    $db = platform_conn();
    mysqli_begin_transaction($db);
    try {
        $st = mysqli_prepare($db, "UPDATE signup_requests SET status = 'activating', last_error = NULL WHERE id = ? AND status = 'approved'");
        mysqli_stmt_bind_param($st, 'i', $id);
        mysqli_stmt_execute($st);
        if (mysqli_stmt_affected_rows($st) !== 1) {
            throw new SignupException('This request was already activated by someone else.');
        }
        $st = mysqli_prepare($db, 'INSERT INTO payments (signup_request_id, amount, method, reference, recorded_by) VALUES (?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($st, 'iisss', $id, $amount, $method, $reference, $adminEmail);
        mysqli_stmt_execute($st);
        mysqli_commit($db);
    } catch (Throwable $e) {
        mysqli_rollback($db);
        throw $e;
    }
    platform_audit(null, 'admin:' . $adminEmail, 'payment_recorded', 'ref=' . $req['reference'] . " amount=$amount method=$method");
}

function signup_retry(int $id, string $adminEmail): void
{
    $st = mysqli_prepare(platform_conn(), "UPDATE signup_requests SET status = 'activating' WHERE id = ? AND status = 'failed'");
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    if (mysqli_stmt_affected_rows($st) !== 1) {
        throw new SignupException('Only a failed request can be retried.');
    }
    platform_audit(null, 'admin:' . $adminEmail, 'activation_retry', "request=$id");
}

// ---------------------------------------------------------------- activation (run by the worker, as root)

/**
 * Creates the company for a paid request. Safe to run again after a failure: what already exists is reused.
 * Returns a one-line result for the worker's log.
 */
function signup_activate_one(int $id): string
{
    $db = platform_conn();
    $st = mysqli_prepare($db, "UPDATE signup_requests SET status = 'provisioning', last_error = NULL WHERE id = ? AND status = 'activating'");
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    if (mysqli_stmt_affected_rows($st) !== 1) {
        return "request $id: skipped (someone else is handling it, or it is not waiting)";
    }
    $req = signup_get($id);
    try {
        if (empty($req['company_code'])) {
            throw new RuntimeException('The request has no company code.');
        }
        $company = platform_company_by_code($req['company_code']);
        if (!$company) {
            $made = provision_company($req['company_code'], $req['company_name'], $req['owner_name'], $req['owner_email'], $req['owner_phone'], 'active');
            $company = platform_company_by_id($made['company_id']);
            // the owner's temporary password is thrown away on purpose: the owner sets their own through the invite link
        }
        $companyId = (int) $company['id'];

        $st = mysqli_prepare($db, 'SELECT COUNT(*) FROM subscriptions WHERE signup_request_id = ?');
        mysqli_stmt_bind_param($st, 'i', $id);
        mysqli_stmt_execute($st);
        if ((int) mysqli_fetch_row(mysqli_stmt_get_result($st))[0] === 0) {
            $start = new DateTimeImmutable('today');
            $end = signup_add_months($start, $req['billing_period'] === 'yearly' ? 12 : 1);
            $startSql = $start->format('Y-m-d');
            $endSql = $end->format('Y-m-d');
            $st = mysqli_prepare($db, "INSERT INTO subscriptions (company_id, package_id, billing_period, status, starts_at, ends_at, signup_request_id) VALUES (?, ?, ?, 'active', ?, ?, ?)");
            $packageId = (int) $req['package_id'];
            mysqli_stmt_bind_param($st, 'iisssi', $companyId, $packageId, $req['billing_period'], $startSql, $endSql, $id);
            mysqli_stmt_execute($st);
        }
        $st = mysqli_prepare($db, 'UPDATE payments SET company_id = ? WHERE signup_request_id = ?');
        mysqli_stmt_bind_param($st, 'ii', $companyId, $id);
        mysqli_stmt_execute($st);

        $st = mysqli_prepare($db, "UPDATE signup_requests SET status = 'active', company_id = ?, last_error = NULL WHERE id = ?");
        mysqli_stmt_bind_param($st, 'ii', $companyId, $id);
        mysqli_stmt_execute($st);
        platform_audit($companyId, 'worker', 'company_activated', 'ref=' . $req['reference'] . ' code=' . $company['company_code']);
    } catch (Throwable $e) {
        $msg = substr($e->getMessage(), 0, 1000);
        $st = mysqli_prepare($db, "UPDATE signup_requests SET status = 'failed', last_error = ? WHERE id = ?");
        mysqli_stmt_bind_param($st, 'si', $msg, $id);
        mysqli_stmt_execute($st);
        platform_audit(null, 'worker', 'activation_failed', 'ref=' . $req['reference'] . ' ' . $msg);
        return "request $id: FAILED: $msg";
    }

    // The company exists now. A problem with the email must not undo that: the admin can resend the invite.
    $sent = signup_send_invite($req, $company);
    return "request $id: company " . $company['company_code'] . ' is active' . ($sent ? ', invite sent' : ', INVITE NOT SENT (use "Resend invite")');
}

// ---------------------------------------------------------------- the owner's one-time link to set a password

/** Makes a new link and cancels the older unused ones. Returns the secret part of the link. */
function invite_create(int $companyId, string $email, int $validDays = 7): string
{
    $db = platform_conn();
    $st = mysqli_prepare($db, 'UPDATE owner_invites SET expires_at = (NOW() - INTERVAL 1 SECOND) WHERE company_id = ? AND used_at IS NULL');
    mysqli_stmt_bind_param($st, 'i', $companyId);
    mysqli_stmt_execute($st);
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $st = mysqli_prepare($db, 'INSERT INTO owner_invites (company_id, email, token_hash, expires_at) VALUES (?, ?, ?, (NOW() + INTERVAL ? DAY))');
    mysqli_stmt_bind_param($st, 'issi', $companyId, $email, $hash, $validDays);
    mysqli_stmt_execute($st);
    return $token;
}

/** The invite behind a link, if the link is genuine, unused and not expired. */
function invite_lookup(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $hash = hash('sha256', $token);
    $st = mysqli_prepare(platform_conn(), 'SELECT i.*, c.company_code, c.name AS company_name FROM owner_invites i JOIN companies c ON c.id = i.company_id WHERE i.token_hash = ? AND i.used_at IS NULL AND i.expires_at > NOW() LIMIT 1');
    mysqli_stmt_bind_param($st, 's', $hash);
    mysqli_stmt_execute($st);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: null;
}

/** Why a password is not acceptable, or null if it is fine. */
function signup_password_problem(string $password, string $email): ?string
{
    if (mb_strlen($password) < 10) {
        return 'The password must have at least 10 characters.';
    }
    if (mb_strlen($password) > 100) {
        return 'The password is too long (100 characters at most).';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'The password must contain at least one letter and one digit.';
    }
    if (strtolower($password) === strtolower($email)) {
        return 'The password must not be your email address.';
    }
    return null;
}

/** Sets the owner's password in the company's own database and uses up the link. */
function invite_set_password(array $invite, string $newPassword): void
{
    $company = platform_company_by_id((int) $invite['company_id']);
    if (!$company || $company['status'] === 'suspended') {
        throw new SignupException('This company account is not active.');
    }
    $db = platform_conn();
    // use up the link FIRST, atomically: two people clicking at once cannot both succeed
    $st = mysqli_prepare($db, 'UPDATE owner_invites SET used_at = NOW() WHERE id = ? AND used_at IS NULL AND expires_at > NOW()');
    $inviteId = (int) $invite['id'];
    mysqli_stmt_bind_param($st, 'i', $inviteId);
    mysqli_stmt_execute($st);
    if (mysqli_stmt_affected_rows($st) !== 1) {
        throw new SignupException('This link was already used or has expired.');
    }
    try {
        $tenant = tenant_connect($company);
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $st = mysqli_prepare($tenant, "UPDATE users SET password_hash = ?, is_active = 1 WHERE email = ? AND role = 'Admin' LIMIT 1");
        mysqli_stmt_bind_param($st, 'ss', $hash, $invite['email']);
        mysqli_stmt_execute($st);
        if (mysqli_stmt_affected_rows($st) !== 1) {
            throw new RuntimeException('The owner account was not found.');
        }
    } catch (Throwable $e) {
        // give the link back so the owner can try again
        $st = mysqli_prepare($db, 'UPDATE owner_invites SET used_at = NULL WHERE id = ?');
        mysqli_stmt_bind_param($st, 'i', $inviteId);
        mysqli_stmt_execute($st);
        throw $e;
    }
    platform_audit((int) $company['id'], 'owner:' . $invite['email'], 'owner_password_set', '');
}

function signup_send_invite(array $req, array $company): bool
{
    $token = invite_create((int) $company['id'], $req['owner_email']);
    $link = platform_public_url() . '/setup_password.php?token=' . $token;
    $sent = signup_email_invite($req, $company, $link);
    if ($sent) {
        $st = mysqli_prepare(platform_conn(), 'UPDATE signup_requests SET invite_sent_at = NOW() WHERE id = ?');
        $id = (int) $req['id'];
        mysqli_stmt_bind_param($st, 'i', $id);
        mysqli_stmt_execute($st);
    }
    return $sent;
}

// ---------------------------------------------------------------- the emails

function signup_email_received(array $req): void
{
    $body = '<p>Hello ' . h_mail($req['owner_name']) . ',</p>'
        . '<p>We have received the request to open <b>' . h_mail($req['company_name']) . '</b> on MY MOTIVE.</p>'
        . '<p>Your reference number is <b>' . h_mail($req['reference']) . '</b>. We will review your request and email you the result.</p>'
        . '<p>Package: ' . h_mail($req['package_name']) . ' (' . h_mail($req['billing_period']) . '), ' . h_mail(signup_money((int) $req['amount_due'])) . '.</p>';
    platform_send_email($req['owner_email'], $req['owner_name'], 'We received your request ' . $req['reference'], platform_mail_wrap('Request received', $body));
}

function signup_email_approved(array $req): void
{
    $instructions = (string) platform_setting('payment_instructions', 'We will contact you shortly with the payment details.');
    $body = '<p>Hello ' . h_mail($req['owner_name']) . ',</p>'
        . '<p>Good news: your request <b>' . h_mail($req['reference']) . '</b> for <b>' . h_mail($req['company_name']) . '</b> is approved.</p>'
        . '<p>To open your account please pay <b>' . h_mail(signup_money((int) $req['amount_due'])) . '</b> (' . h_mail($req['package_name']) . ', ' . h_mail($req['billing_period']) . ').</p>'
        . '<p>' . nl2br(h_mail($instructions)) . '</p>'
        . '<p>Please quote the reference <b>' . h_mail($req['reference']) . '</b> with your payment. Your account is created as soon as we confirm it.</p>';
    platform_send_email($req['owner_email'], $req['owner_name'], 'Your request ' . $req['reference'] . ' is approved', platform_mail_wrap('Request approved', $body));
}

function signup_email_rejected(array $req): void
{
    $body = '<p>Hello ' . h_mail($req['owner_name']) . ',</p>'
        . '<p>We are sorry: we could not approve your request <b>' . h_mail($req['reference']) . '</b> for ' . h_mail($req['company_name']) . '.</p>'
        . '<p><b>Reason:</b> ' . h_mail($req['reject_reason']) . '</p>'
        . '<p>You are welcome to apply again after fixing this.</p>';
    platform_send_email($req['owner_email'], $req['owner_name'], 'About your request ' . $req['reference'], platform_mail_wrap('Request not approved', $body));
}

function signup_email_invite(array $req, array $company, string $link): bool
{
    $body = '<p>Hello ' . h_mail($req['owner_name']) . ',</p>'
        . '<p>Your payment is confirmed and <b>' . h_mail($company['name']) . '</b> is ready on MY MOTIVE.</p>'
        . '<p>Your <b>company code</b> is <b style="font-size:18px">' . h_mail($company['company_code']) . '</b>. You and your staff type it on the sign-in page.</p>'
        . '<p>Set your own password with this one-time link (valid for 7 days):</p>'
        . '<p><a href="' . h_mail($link) . '" style="background:#3B4FE0;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Set my password</a></p>'
        . '<p style="font-size:12px;color:#8A90A3">If the button does not work, copy this address into your browser:<br>' . h_mail($link) . '</p>'
        . '<p>Sign in at: ' . h_mail(platform_public_url()) . '/login.php</p>';
    return platform_send_email($req['owner_email'], $req['owner_name'], 'Your MY MOTIVE account is ready', platform_mail_wrap('Welcome to MY MOTIVE', $body));
}

// ---------------------------------------------------------------- companies and the worker, as the admin panel sees them

/** Suspends or re-activates a company. Company 1 is your own company and cannot be suspended from the panel. */
function company_set_status(int $companyId, string $status, string $adminEmail): void
{
    if (!in_array($status, ['active', 'suspended'], true)) {
        throw new SignupException('Unknown status.');
    }
    if ($companyId === 1 && $status === 'suspended') {
        throw new SignupException('Company 1 is your own company. It cannot be suspended from here.');
    }
    $company = platform_company_by_id($companyId);
    if (!$company) {
        throw new SignupException('There is no such company.');
    }
    $st = mysqli_prepare(platform_conn(), 'UPDATE companies SET status = ? WHERE id = ?');
    mysqli_stmt_bind_param($st, 'si', $status, $companyId);
    mysqli_stmt_execute($st);
    platform_audit($companyId, 'admin:' . $adminEmail, 'status_' . $status, 'was ' . $company['status']);
}

function signup_companies(): array
{
    $sql = "SELECT c.id, c.company_code, c.name, c.status, c.owner_email, c.created_at,
                   (SELECT p.name FROM subscriptions s JOIN packages p ON p.id = s.package_id WHERE s.company_id = c.id ORDER BY s.id DESC LIMIT 1) AS package_name,
                   (SELECT s.ends_at FROM subscriptions s WHERE s.company_id = c.id ORDER BY s.id DESC LIMIT 1) AS ends_at
            FROM companies c ORDER BY c.id";
    $out = [];
    $res = mysqli_query(platform_conn(), $sql);
    while ($row = mysqli_fetch_assoc($res)) {
        $out[] = $row;
    }
    return $out;
}

/** The worker touches this file every time it runs. */
function signup_worker_heartbeat_file(): string
{
    return __DIR__ . '/../storage/worker.heartbeat';
}

/** Seconds since the worker last ran, or null if it never did. */
function signup_worker_age(): ?int
{
    $file = signup_worker_heartbeat_file();
    return is_file($file) ? max(0, time() - (int) filemtime($file)) : null;
}
