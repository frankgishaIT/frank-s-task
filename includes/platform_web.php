<?php
/**
 * Helpers for the pages that do not belong to one company: the public sign-up page,
 * the platform admin panel (/platform/) and the "set your password" page.
 * They never use tenant.php and never touch a company's data.
 */

require_once __DIR__ . '/platform.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1']) ? '/rm_os' : '');
}

/** Escapes text for safe display inside HTML. */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Starts a session with its own cookie name, so a platform session can never be mistaken for a company session. */
function platform_session_start(string $name): void
{
    // these pages must not be shown inside another site's frame, cached, or leak their address to other sites
    if (!headers_sent()) {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name($name);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------- CSRF: a form can only be sent from our own page

function platform_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function platform_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(platform_csrf_token()) . '">';
}

/** Stops the request unless the form carries the token of this session. */
function platform_csrf_check(): void
{
    $sent = $_POST['csrf'] ?? null;
    if (!is_string($sent) || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
        http_response_code(400);
        echo 'This form has expired or is not valid. Go back, reload the page and try again.';
        exit;
    }
}

// ---------------------------------------------------------------- limits on repeated attempts

function platform_client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** How many times this subject did this thing in the last N seconds. */
function platform_rate_count(string $bucket, string $subject, int $windowSeconds): int
{
    $st = mysqli_prepare(platform_conn(), 'SELECT COUNT(*) FROM platform_rate_limits WHERE bucket = ? AND subject = ? AND created_at > (NOW() - INTERVAL ? SECOND)');
    mysqli_stmt_bind_param($st, 'ssi', $bucket, $subject, $windowSeconds);
    mysqli_stmt_execute($st);
    return (int) mysqli_fetch_row(mysqli_stmt_get_result($st))[0];
}

function platform_rate_hit(string $bucket, string $subject): void
{
    $subject = substr($subject, 0, 150);
    $st = mysqli_prepare(platform_conn(), 'INSERT INTO platform_rate_limits (bucket, subject) VALUES (?, ?)');
    mysqli_stmt_bind_param($st, 'ss', $bucket, $subject);
    mysqli_stmt_execute($st);
    if (random_int(1, 50) === 1) {   // now and then, forget old entries
        mysqli_query(platform_conn(), 'DELETE FROM platform_rate_limits WHERE created_at < (NOW() - INTERVAL 2 DAY)');
    }
}

// ---------------------------------------------------------------- the platform admin

const PLATFORM_IDLE_SECONDS = 1800;   // an admin who does nothing for 30 minutes is signed out

function platform_admin_by_id(int $id): ?array
{
    $st = mysqli_prepare(platform_conn(), 'SELECT * FROM platform_admins WHERE id = ? AND is_active = 1 LIMIT 1');
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: null;
}

/** For every admin page: returns the signed-in admin, or sends the visitor to the admin login. */
function platform_require_admin(bool $allowPendingPasswordChange = false): array
{
    platform_session_start('MM_PLATFORM');
    $login = BASE_URL . '/platform/login.php';
    $id = (int) ($_SESSION['pa_id'] ?? 0);
    $last = (int) ($_SESSION['pa_last'] ?? 0);
    if ($id <= 0 || (time() - $last) > PLATFORM_IDLE_SECONDS) {
        $_SESSION = [];
        header('Location: ' . $login . ($id > 0 ? '?reason=timeout' : ''));
        exit;
    }
    $admin = platform_admin_by_id($id);
    if (!$admin) {
        $_SESSION = [];
        header('Location: ' . $login);
        exit;
    }
    $_SESSION['pa_last'] = time();
    if ((int) $admin['must_change_password'] === 1 && !$allowPendingPasswordChange) {
        header('Location: ' . BASE_URL . '/platform/password.php');
        exit;
    }
    return $admin;
}

function platform_flash(string $message, string $kind = 'success'): void
{
    $_SESSION['flash'] = ['m' => $message, 'k' => $kind];
}

/** The admin panel's page frame. */
function platform_page_start(string $title, ?array $admin = null, string $active = ''): void
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    $nav = [
        'dashboard' => ['index.php', 'Dashboard'],
        'requests'  => ['requests.php', 'Sign-up requests'],
        'companies' => ['companies.php', 'Companies'],
        'emails'    => ['emails.php', 'Emails'],
        'password'  => ['password.php', 'My password'],
    ];
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' - MY MOTIVE platform</title>'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">'
        . '<style>body{background:#F6F7FB}.navbar{background:#1E2333}.card{border:0;border-radius:14px;box-shadow:0 4px 18px rgba(30,35,51,.06)}</style>'
        . '</head><body>';
    echo '<nav class="navbar navbar-expand navbar-dark mb-4"><div class="container"><span class="navbar-brand fw-bold">MY MOTIVE platform</span>';
    if ($admin) {
        echo '<ul class="navbar-nav me-auto">';
        foreach ($nav as $key => [$file, $label]) {
            echo '<li class="nav-item"><a class="nav-link' . ($active === $key ? ' active' : '') . '" href="' . h(BASE_URL . '/platform/' . $file) . '">' . h($label) . '</a></li>';
        }
        echo '</ul><span class="navbar-text me-3">' . h($admin['name']) . '</span>'
            . '<a class="btn btn-sm btn-outline-light" href="' . h(BASE_URL . '/platform/logout.php') . '">Sign out</a>';
    }
    echo '</div></nav><div class="container pb-5">';
    if ($flash) {
        echo '<div class="alert alert-' . h($flash['k']) . '">' . h($flash['m']) . '</div>';
    }
}

function platform_page_end(): void
{
    echo '</div></body></html>';
}
