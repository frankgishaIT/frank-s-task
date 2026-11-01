<?php
/**
 * NEW FILE: includes/portal_helpers.php
 * Task 2: RM CUSTOMER PORTAL (customer-facing, outside the staff workspace).
 *
 * Security rules:
 *   - Its own session (cookie "RMPORTAL"): a staff login never opens the portal, and the other way round.
 *   - A customer sees ONLY their own records: every invoice, document, request and record is checked against
 *     the signed-in customer. Knowing a number is never enough; someone else's number answers "not found",
 *     exactly like a number that does not exist.
 *   - SMS codes: 6 digits, stored hashed, valid 10 minutes, 5 attempts; at most 1 code per minute and 5 per hour.
 *   - Login: locked for 15 minutes after 5 failed attempts. Every form carries a CSRF token.
 */
require_once __DIR__ . '/rm_org.php';

const PORTAL_SESSION = 'RMPORTAL';
const PORTAL_OTP_MINUTES = 10;

/** Starts the portal (call first on every portal page). */
function portal_boot(): mysqli
{
    if (!defined('RM_OS_ALLOW_GUEST')) { define('RM_OS_ALLOW_GUEST', true); }
    date_default_timezone_set('Africa/Kigali');
    if (session_status() === PHP_SESSION_NONE) {
        session_name(PORTAL_SESSION);
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
        session_start();
    }
    require __DIR__ . '/../config/db.php';   // the database connection ($conn)
    /** @var mysqli $conn */
    $GLOBALS['conn'] = $conn;
    portal_ensure($conn);
    return $conn;
}

function portal_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $q = function ($sql) use ($conn) { mysqli_query($conn, $sql); };
    $q("CREATE TABLE IF NOT EXISTS portal_accounts (customer_id INT(11) NOT NULL, password_hash VARCHAR(255) NULL,
        status ENUM('active','blocked') NOT NULL DEFAULT 'active', phone_verified_at DATETIME NULL, last_login_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (customer_id)) ENGINE=InnoDB");
    $q("CREATE TABLE IF NOT EXISTS portal_otps (id INT(11) NOT NULL AUTO_INCREMENT, customer_id INT(11) NOT NULL, purpose VARCHAR(20) NOT NULL,
        code_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, attempts INT(11) NOT NULL DEFAULT 0, used_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY customer_id (customer_id)) ENGINE=InnoDB");
    $q("CREATE TABLE IF NOT EXISTS portal_documents (id INT(11) NOT NULL AUTO_INCREMENT, customer_id INT(11) NOT NULL, title VARCHAR(200) NOT NULL,
        category VARCHAR(60) NOT NULL DEFAULT 'Other', file_ref VARCHAR(30) NOT NULL, original_name VARCHAR(255) NOT NULL, note VARCHAR(255) NULL,
        published_by INT(11) NULL, published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, unpublished_at DATETIME NULL,
        PRIMARY KEY (id), KEY customer_id (customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS portal_requests (id INT(11) NOT NULL AUTO_INCREMENT, customer_id INT(11) NOT NULL, type VARCHAR(40) NOT NULL,
        subject VARCHAR(200) NOT NULL, status ENUM('Open','In progress','Resolved','Closed') NOT NULL DEFAULT 'Open',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL, PRIMARY KEY (id), KEY customer_id (customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS portal_request_messages (id INT(11) NOT NULL AUTO_INCREMENT, request_id INT(11) NOT NULL,
        from_customer TINYINT(1) NOT NULL, user_id INT(11) NULL, body TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY request_id (request_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS portal_record_types (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(80) NOT NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS portal_records (id INT(11) NOT NULL AUTO_INCREMENT, customer_id INT(11) NOT NULL, type_id INT(11) NOT NULL,
        title VARCHAR(200) NOT NULL, value VARCHAR(100) NULL, record_date DATE NULL, notes VARCHAR(500) NULL, created_by INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY customer_id (customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS portal_activity (id INT(11) NOT NULL AUTO_INCREMENT, customer_id INT(11) NULL, action VARCHAR(40) NOT NULL,
        details VARCHAR(500) NULL, ip VARCHAR(45) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY customer_id (customer_id), KEY action (action)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS portal_settings (name VARCHAR(40) NOT NULL, value TEXT NULL, PRIMARY KEY (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ((int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM portal_record_types'))[0] === 0) {
        foreach (['Scores', 'Grades', 'Assessments', 'Attendance', 'Progress reports'] as $t) {
            $q("INSERT IGNORE INTO portal_record_types (name, is_enabled) VALUES ('" . mysqli_real_escape_string($conn, $t) . "', 0)");
        }
    }
}

function portal_setting(mysqli $conn, string $name, string $default = ''): string
{
    $s = mysqli_prepare($conn, 'SELECT value FROM portal_settings WHERE name = ?');
    mysqli_stmt_bind_param($s, 's', $name);
    mysqli_stmt_execute($s);
    $r = mysqli_fetch_row(mysqli_stmt_get_result($s));
    return $r ? (string) $r[0] : $default;
}

function portal_set_setting(mysqli $conn, string $name, string $value): void
{
    $s = mysqli_prepare($conn, 'INSERT INTO portal_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    mysqli_stmt_bind_param($s, 'ss', $name, $value);
    mysqli_stmt_execute($s);
}

function portal_is_open(mysqli $conn): bool
{
    return rm_feature_enabled('customer_portal') && portal_setting($conn, 'enabled', '1') === '1';
}

function portal_log(mysqli $conn, ?int $customerId, string $action, string $details = ''): void
{
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $d = mb_substr($details, 0, 500);
    $s = mysqli_prepare($conn, 'INSERT INTO portal_activity (customer_id, action, details, ip) VALUES (?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'isss', $customerId, $action, $d, $ip);
    mysqli_stmt_execute($s);
}

/* ------------------------------------------------------------------ CSRF */

function portal_csrf_field(): string
{
    if (empty($_SESSION['portal_csrf'])) { $_SESSION['portal_csrf'] = bin2hex(random_bytes(32)); }
    return '<input type="hidden" name="csrf" value="' . $_SESSION['portal_csrf'] . '">';
}

function portal_csrf_ok(): bool
{
    return !empty($_SESSION['portal_csrf']) && hash_equals($_SESSION['portal_csrf'], (string) ($_POST['csrf'] ?? ''));
}

/* ------------------------------------------------------------------ customers and sign-in */

function portal_customer_code(int $id): string { return 'RMC' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }

function portal_digits(string $phone): string
{
    $d = preg_replace('/\D/', '', $phone);
    if (strpos($d, '250') === 0 && strlen($d) === 12) { $d = '0' . substr($d, 3); }
    return $d;
}

/** Finds a customer by phone number or Customer ID (RMC000025). */
function portal_find_customer(mysqli $conn, string $identifier): ?array
{
    $identifier = trim($identifier);
    if (preg_match('/^RMC0*(\d+)$/i', $identifier, $m)) {
        $s = mysqli_prepare($conn, 'SELECT * FROM customers WHERE id = ?');
        $id = (int) $m[1];
        mysqli_stmt_bind_param($s, 'i', $id);
        mysqli_stmt_execute($s);
        return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
    }
    $digits = portal_digits($identifier);
    if (strlen($digits) < 9) { return null; }
    $tail = substr($digits, -9); // last 9 digits: 07xxxxxxxx / +2507xxxxxxxx both match
    $s = mysqli_prepare($conn, "SELECT * FROM customers WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), 9) = ? ORDER BY id LIMIT 2");
    mysqli_stmt_bind_param($s, 's', $tail);
    mysqli_stmt_execute($s);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
    return count($rows) === 1 ? $rows[0] : null; // two customers with one phone: they must use their Customer ID
}

function portal_account(mysqli $conn, int $customerId): ?array
{
    $r = mysqli_query($conn, 'SELECT * FROM portal_accounts WHERE customer_id = ' . $customerId);
    return mysqli_fetch_assoc($r) ?: null;
}

/** The signed-in customer, or null. Blocked or reset accounts are signed out at once. */
function portal_customer(mysqli $conn): ?array
{
    $id = (int) ($_SESSION['portal_customer_id'] ?? 0);
    if ($id <= 0) { return null; }
    $a = portal_account($conn, $id);
    if (!$a || $a['status'] !== 'active' || !$a['password_hash']) { unset($_SESSION['portal_customer_id']); return null; }
    $c = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM customers WHERE id = ' . $id));
    return $c ?: null;
}

function portal_require_login(mysqli $conn): array
{
    if (!portal_is_open($conn)) { http_response_code(503); echo 'The customer portal is not available at the moment.'; exit; }
    $c = portal_customer($conn);
    if (!$c) { header('Location: login.php'); exit; }
    return $c;
}

function portal_login_locked(mysqli $conn, string $identifier): bool
{
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $s = mysqli_prepare($conn, "SELECT COUNT(*) FROM portal_activity WHERE action = 'login_failed' AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND (details = ? OR ip = ?)");
    $id = mb_substr(strtolower(trim($identifier)), 0, 500);
    mysqli_stmt_bind_param($s, 'ss', $id, $ip);
    mysqli_stmt_execute($s);
    return (int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0] >= 5;
}

/* ------------------------------------------------------------------ one-time SMS codes */

/** Sends a 6-digit code by SMS. Throws RuntimeException with a clear reason when it cannot. */
function portal_send_otp(mysqli $conn, array $customer, string $purpose): void
{
    $cid = (int) $customer['id'];
    $recent = mysqli_fetch_row(mysqli_query($conn, "SELECT SUM(created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)), COUNT(*) FROM portal_otps
        WHERE customer_id = $cid AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"));
    if ((int) $recent[0] > 0) { throw new RuntimeException('A code was just sent. Please wait one minute before asking for another.'); }
    if ((int) $recent[1] >= 5) { throw new RuntimeException('Too many codes were requested. Please try again in an hour, or contact RISE MOTIVE.'); }
    $code = (string) random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $exp = date('Y-m-d H:i:s', time() + 60 * PORTAL_OTP_MINUTES);
    mysqli_query($conn, "UPDATE portal_otps SET used_at = NOW() WHERE customer_id = $cid AND used_at IS NULL"); // older codes stop working
    $s = mysqli_prepare($conn, 'INSERT INTO portal_otps (customer_id, purpose, code_hash, expires_at) VALUES (?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'isss', $cid, $purpose, $hash, $exp);
    mysqli_stmt_execute($s);
    $sent = false;
    if (is_file(__DIR__ . '/sms_helpers.php')) {
        require_once __DIR__ . '/sms_helpers.php';
        if (function_exists('sms_send')) {
            $sent = sms_send($conn, (string) $customer['phone'], 'RISE MOTIVE: your customer portal code is ' . $code . '. It expires in ' . PORTAL_OTP_MINUTES . ' minutes. Do not share it with anyone.', 'portal_otp', 'customer', $cid, null);
        }
    }
    portal_log($conn, $cid, 'otp_sent', $purpose . ($sent ? '' : ' (SMS NOT delivered)'));
    if (!$sent) { throw new RuntimeException('The SMS could not be sent right now. Please try again later, or contact RISE MOTIVE.'); }
}

/** Checks a code. True once; wrong codes count (5 attempts). */
function portal_check_otp(mysqli $conn, int $customerId, string $purpose, string $code): bool
{
    $s = mysqli_prepare($conn, 'SELECT * FROM portal_otps WHERE customer_id = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
    mysqli_stmt_bind_param($s, 'is', $customerId, $purpose);
    mysqli_stmt_execute($s);
    $o = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if (!$o) { return false; }
    if ((int) $o['attempts'] >= 5) { mysqli_query($conn, 'UPDATE portal_otps SET used_at = NOW() WHERE id = ' . (int) $o['id']); return false; }
    if (!password_verify(trim($code), $o['code_hash'])) { mysqli_query($conn, 'UPDATE portal_otps SET attempts = attempts + 1 WHERE id = ' . (int) $o['id']); return false; }
    mysqli_query($conn, 'UPDATE portal_otps SET used_at = NOW() WHERE id = ' . (int) $o['id']);
    return true;
}

/* ------------------------------------------------------------------ the customer's own records */

/** A sale of THIS customer by its number (RM000058 / 58), or null (also when it belongs to someone else). */
function portal_own_sale(mysqli $conn, int $customerId, string $number): ?array
{
    if (!preg_match('/^(?:RM|INV|RCPT|REC)?[-\s]?0*(\d{1,9})$/i', trim($number), $m)) { return null; }
    $id = (int) $m[1];
    $s = mysqli_prepare($conn, "SELECT * FROM sales WHERE id = ? AND customer_id = ? AND status <> 'Cancelled'");
    mysqli_stmt_bind_param($s, 'ii', $id, $customerId);
    mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

function portal_sale_number(int $id): string { return 'RM' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }
function portal_request_number(int $id): string { return 'CR-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }

function portal_sale_balance(array $sale): float
{
    return round((float) $sale['total_amount'] - (float) $sale['amount_paid'] + (float) ($sale['charges_accrued'] ?? 0) - (float) ($sale['charges_paid'] ?? 0), 2);
}

/** Tells the staff about something new in the portal (the Admins and Managers with portal access). */
function portal_notify_staff(mysqli $conn, string $title, string $message): void
{
    require_once __DIR__ . '/notification_helper.php';
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM users WHERE is_active = 1 AND LOWER(role) IN ('admin', 'manager')"), MYSQLI_ASSOC) as $u) {
        try { notifyUser($conn, (int) $u['id'], $title, $message); } catch (Throwable $e) { error_log('portal notify: ' . $e->getMessage()); }
    }
}