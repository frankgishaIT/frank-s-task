<?php
/**
 * MY MOTIVE SaaS: the platform side.
 *
 * The PLATFORM database knows every company (its code, its own database, its status).
 * Each company's real data lives in that company's OWN database, opened with that
 * company's OWN MySQL user. This file finds the company and opens its database.
 * One connection never mixes two companies.
 *
 * Settings come from config/platform.php (not in git: it holds the platform database
 * login and the master key). It is created once by tools/install_platform.php.
 */

/** The platform settings: database login and the master key. */
function platform_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config/platform.php';
        $loaded = is_file($file) ? require $file : [];
        if (!is_array($loaded) || empty($loaded['db_name']) || empty($loaded['db_user']) || empty($loaded['key'])) {
            platform_fail('config/platform.php is missing or incomplete. Run tools/install_platform.php once.');
        }
        $cfg = $loaded;
    }
    return $cfg;
}

/** Stops the request. The reason goes to the server log, never to the visitor. */
function platform_fail(string $logMessage): void
{
    error_log('[MY MOTIVE platform] ' . $logMessage);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "PLATFORM ERROR: $logMessage\n");
        exit(1);
    }
    http_response_code(503);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Service unavailable</title></head>'
        . '<body style="font-family:system-ui,sans-serif;text-align:center;padding:60px">'
        . '<h2>MY MOTIVE is temporarily unavailable</h2><p>Please try again in a few minutes.</p></body></html>';
    exit;
}

// ---------------------------------------------------------------- secrets (a company's database password)

/** The 32-byte master key. */
function platform_key(): string
{
    $key = (string) platform_config()['key'];
    if (strpos($key, 'base64:') === 0) {
        $key = (string) base64_decode(substr($key, 7), true);
    }
    if (strlen($key) !== 32) {
        platform_fail('The master key in config/platform.php must be exactly 32 bytes.');
    }
    return $key;
}

/** Encrypts a secret (AES-256-GCM). The result is safe to store in the platform database. */
function platform_encrypt(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', platform_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        platform_fail('Encryption failed.');
    }
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

/** Reverses platform_encrypt(). Stops the request if the data was changed or the key is wrong. */
function platform_decrypt(string $blob): string
{
    if (strpos($blob, 'v1:') !== 0) {
        platform_fail('A stored secret has an unknown format.');
    }
    $raw = base64_decode(substr($blob, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        platform_fail('A stored secret is damaged.');
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', platform_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($plain === false) {
        platform_fail('A stored secret could not be decrypted (wrong key, or it was changed).');
    }
    return $plain;
}

// ---------------------------------------------------------------- the platform database

function platform_conn(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $cfg = platform_config();
        try {
            $conn = mysqli_connect($cfg['db_host'] ?? 'localhost', $cfg['db_user'], (string) ($cfg['db_pass'] ?? ''), $cfg['db_name']);
            mysqli_set_charset($conn, 'utf8mb4');
        } catch (Throwable $e) {
            platform_fail('Could not open the platform database: ' . $e->getMessage());
        }
    }
    return $conn;
}

// ---------------------------------------------------------------- finding a company

/** Upper case, no spaces. "acme " becomes "ACME". */
function platform_normalize_code(string $code): string
{
    return strtoupper(trim($code));
}

/** 2 to 30 characters: letters, digits, - and _. Checked BEFORE any lookup. */
function platform_valid_code(string $code): bool
{
    return (bool) preg_match('/^[A-Z0-9][A-Z0-9_-]{1,29}$/', $code);
}

function platform_company_by_code(string $code): ?array
{
    $code = platform_normalize_code($code);
    if (!platform_valid_code($code)) {
        return null;
    }
    $st = mysqli_prepare(platform_conn(), 'SELECT * FROM companies WHERE company_code = ? LIMIT 1');
    mysqli_stmt_bind_param($st, 's', $code);
    mysqli_stmt_execute($st);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: null;
}

function platform_company_by_id(int $id): ?array
{
    $st = mysqli_prepare(platform_conn(), 'SELECT * FROM companies WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: null;
}

/** Opens the company's OWN database with the company's OWN MySQL user. */
function tenant_connect(array $company): mysqli
{
    try {
        return mysqli_connect($company['db_host'], $company['db_user'], platform_decrypt($company['db_pass_enc']), $company['db_name']);
    } catch (Throwable $e) {
        platform_fail('Could not open the database of company ' . $company['company_code'] . ': ' . $e->getMessage());
    }
}

function platform_audit(?int $companyId, string $actor, string $action, string $details = ''): void
{
    $st = mysqli_prepare(platform_conn(), 'INSERT INTO platform_audit_log (company_id, actor, action, details) VALUES (?, ?, ?, ?)');
    mysqli_stmt_bind_param($st, 'isss', $companyId, $actor, $action, $details);
    mysqli_stmt_execute($st);
}

// ---------------------------------------------------------------- small helpers for pages

/** The company of the current request (a row of the companies table), or null on the login page. */
function mm_company(): ?array
{
    return $GLOBALS['MM_COMPANY'] ?? null;
}

function mm_company_id(): ?int
{
    $c = mm_company();
    return $c ? (int) $c['id'] : null;
}

function mm_company_name(): string
{
    $c = mm_company();
    return $c ? (string) $c['name'] : 'MY MOTIVE';
}

/** Remembers the company code in the browser, so the login form is already filled in next time. */
function mm_remember_company(string $code): void
{
    setcookie('mm_company', $code, [
        'expires'  => time() + 365 * 24 * 3600,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Ends the session and sends the visitor to the login page with a short explanation. */
function mm_leave_to_login(string $reason): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    $base = defined('BASE_URL') ? BASE_URL : '';
    header('Location: ' . $base . '/login.php?reason=' . rawurlencode($reason));
    exit;
}
