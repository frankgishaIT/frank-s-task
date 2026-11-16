<?php
/**
 * NEW FILE: includes/branch_helpers.php — MULTI-BRANCH MANAGEMENT (foundation).
 *
 * ONE COMPANY (RISE MOTIVE Ltd), ONE PLATFORM, ONE DATABASE, MULTIPLE BRANCHES.
 *  - A branch is NOT a company: company identity (name, TIN, taxpayer data) stays in report_company()/rm_org.php.
 *  - Employees keep ONE account. Their branches are ASSIGNMENTS (primary / additional / temporary), and some people
 *    have company-wide access (user_branch_access.all_branches).
 *  - Records carry a branch_id (sales, transactions, attendance, purchase orders, tasks, assets, loans, payroll, leave,
 *    departments, employees...). Shared records (customers, suppliers, products) stay shared; they only remember the
 *    branch that created them (created_branch_id).
 *  - Nothing is enabled until an Administrator runs the setup (modules/branches/setup.php): it registers the existing
 *    operations as "RISE MOTIVE – Main Branch" (RM-BR-0001) and attaches every existing record to it. It only ADDS
 *    tables/columns and fills empty branch fields; it never deletes or rewrites existing data.
 *
 * Request-level context:
 *   br_enabled()        setup done?
 *   br_current()        the branch selected in the top bar (0 = Company View)
 *   br_write_branch()   the branch a NEW record belongs to (selected branch, else the user's primary branch)
 *   br_allowed_ids()    null = all branches, or the list the user may see (ALWAYS checked on the server)
 *   br_scope_sql()      SQL condition for lists/reports
 *   br_stamp()          set branch_id on a record just created
 *   br_operation_block() refuse new operations in Suspended / Closed branches
 */

const BR_SCHEMA_VERSION = '1';
const BR_TYPES = ['Head Office', 'Branch', 'Sales Point', 'Warehouse', 'Service Centre'];
const BR_STATUSES = ['Active', 'Suspended', 'Closed'];
const BR_MAIN_NAME = 'RISE MOTIVE – Main Branch';
const BR_MAIN_CODE = 'RM-BR-0001';

/** Tables that get a branch reference: table => [column, creator column used to guess the branch of a missed record]. */
const BR_TABLES = [
    'departments'      => ['branch_id', null],
    'users'            => ['branch_id', null],            // primary branch (copy of the active primary assignment)
    'attendance'       => ['branch_id', 'user_id'],
    'sales'            => ['branch_id', 'recorded_by'],
    'transactions'     => ['branch_id', 'recorded_by'],
    'purchase_orders'  => ['branch_id', 'created_by'],    // requesting / receiving branch
    'tasks'            => ['branch_id', 'assigned_to'],
    'assets'           => ['branch_id', 'created_by'],    // ownership / assigned location
    'loans'            => ['branch_id', 'created_by'],    // responsible branch
    'payroll'          => ['branch_id', 'user_id'],       // branch allocation of one company payroll
    'leave_requests'   => ['branch_id', 'user_id'],       // employee's organizational branch
    'customers'        => ['created_branch_id', null],    // shared customer, branch that registered them
    'business_parties' => ['created_branch_id', null],    // shared supplier/party
];

const BR_SUSPEND_RULES = [
    'sales' => 'New sales', 'expenses' => 'New expenses and other transactions', 'purchases' => 'New purchase orders and stock receipts',
    'stock_transfers' => 'New stock transfers', 'attendance' => 'Check-in / attendance',
];

function br_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

/* ------------------------------------------------------------------ storage */

function br_q(mysqli $conn, string $sql): void { mysqli_query($conn, $sql); }

function br_tables(mysqli $conn): void
{
    br_q($conn, "CREATE TABLE IF NOT EXISTS branches (
        id INT(11) NOT NULL AUTO_INCREMENT, code VARCHAR(20) NOT NULL, name VARCHAR(160) NOT NULL, branch_type VARCHAR(40) NOT NULL DEFAULT 'Branch',
        is_main TINYINT(1) NOT NULL DEFAULT 0, province VARCHAR(60) NULL, district VARCHAR(60) NULL, sector VARCHAR(60) NULL, cell VARCHAR(80) NULL, village VARCHAR(80) NULL,
        address VARCHAR(255) NULL, phone VARCHAR(40) NULL, email VARCHAR(150) NULL, manager_user_id INT(11) NULL, opening_date DATE NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'Active', status_reason VARCHAR(500) NULL, status_changed_at DATETIME NULL, description TEXT NULL,
        created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        PRIMARY KEY (id), UNIQUE KEY code (code), KEY status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    br_q($conn, "CREATE TABLE IF NOT EXISTS branch_audit_logs (
        id INT(11) NOT NULL AUTO_INCREMENT, branch_id INT(11) NULL, user_id INT(11) NULL, action VARCHAR(60) NOT NULL, details TEXT NOT NULL,
        old_values TEXT NULL, new_values TEXT NULL, ip VARCHAR(45) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY branch_id (branch_id), KEY created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    br_q($conn, "CREATE TABLE IF NOT EXISTS employee_branch_assignments (
        id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, branch_id INT(11) NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'primary',
        start_date DATE NOT NULL, end_date DATE NULL, status VARCHAR(10) NOT NULL DEFAULT 'active', note VARCHAR(500) NULL, transfer_id INT(11) NULL,
        created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY user_id (user_id, status), KEY branch_id (branch_id, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    br_q($conn, "CREATE TABLE IF NOT EXISTS branch_transfers (
        id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, from_branch_id INT(11) NULL, to_branch_id INT(11) NOT NULL, effective_date DATE NOT NULL,
        reason TEXT NOT NULL, status VARCHAR(10) NOT NULL DEFAULT 'Pending', requested_by INT(11) NULL, decided_by INT(11) NULL, decided_at DATETIME NULL,
        decision_note VARCHAR(500) NULL, applied_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY user_id (user_id), KEY status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    br_q($conn, "CREATE TABLE IF NOT EXISTS user_branch_access (
        user_id INT(11) NOT NULL, all_branches TINYINT(1) NOT NULL DEFAULT 0, updated_by INT(11) NULL, updated_at DATETIME NULL, PRIMARY KEY (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    br_q($conn, "CREATE TABLE IF NOT EXISTS branch_settings (branch_id INT(11) NOT NULL DEFAULT 0, k VARCHAR(60) NOT NULL, v TEXT NULL, PRIMARY KEY (branch_id, k)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    br_q($conn, "CREATE TABLE IF NOT EXISTS branch_locations (
        id INT(11) NOT NULL AUTO_INCREMENT, branch_id INT(11) NOT NULL, code VARCHAR(30) NOT NULL, name VARCHAR(120) NOT NULL, kind VARCHAR(40) NOT NULL DEFAULT 'Shop floor',
        address VARCHAR(255) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY code (code), KEY branch_id (branch_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    br_q($conn, "CREATE TABLE IF NOT EXISTS branch_cash_registers (
        id INT(11) NOT NULL AUTO_INCREMENT, branch_id INT(11) NOT NULL, location_id INT(11) NULL, code VARCHAR(30) NOT NULL, name VARCHAR(120) NOT NULL,
        cashier_user_id INT(11) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY code (code), KEY branch_id (branch_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function br_has_column(mysqli $conn, string $table, string $col): bool
{
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `" . preg_replace('/\W/', '', $table) . "` LIKE '" . preg_replace('/\W/', '', $col) . "'");
    return $r && mysqli_num_rows($r) > 0;
}

function br_table_exists(mysqli $conn, string $table): bool
{
    $r = mysqli_query($conn, "SHOW TABLES LIKE '" . preg_replace('/\W/', '', $table) . "'");
    return $r && mysqli_num_rows($r) > 0;
}

/** Adds the branch columns (+ index) that are missing. Never changes existing columns. */
function br_add_columns(mysqli $conn): array
{
    $added = [];
    foreach (BR_TABLES as $t => [$col]) {
        if (!br_table_exists($conn, $t) || br_has_column($conn, $t, $col)) { continue; }
        br_q($conn, "ALTER TABLE `$t` ADD COLUMN `$col` INT(11) NULL, ADD KEY `idx_$col` (`$col`)");
        $added[] = "$t.$col";
    }
    if (br_table_exists($conn, 'purchase_orders') && !br_has_column($conn, 'purchase_orders', 'receiving_branch_id')) {
        br_q($conn, 'ALTER TABLE purchase_orders ADD COLUMN receiving_branch_id INT(11) NULL, ADD KEY idx_receiving_branch_id (receiving_branch_id)');
        $added[] = 'purchase_orders.receiving_branch_id';
    }
    // Future EBM/EIS: a sale keeps its operational location (point of sale) as well as its branch.
    if (br_table_exists($conn, 'sales') && !br_has_column($conn, 'sales', 'branch_location_id')) {
        br_q($conn, 'ALTER TABLE sales ADD COLUMN branch_location_id INT(11) NULL');
        $added[] = 'sales.branch_location_id';
    }
    return $added;
}

function br_setting(mysqli $conn, string $k, int $branchId = 0, ?string $default = null): ?string
{
    $s = mysqli_prepare($conn, 'SELECT v FROM branch_settings WHERE k = ? AND branch_id IN (0, ?) ORDER BY branch_id DESC LIMIT 1');
    mysqli_stmt_bind_param($s, 'si', $k, $branchId);
    mysqli_stmt_execute($s);
    $r = mysqli_fetch_row(mysqli_stmt_get_result($s));
    return $r ? $r[0] : $default;
}

function br_setting_set(mysqli $conn, string $k, ?string $v, int $branchId = 0): void
{
    $s = mysqli_prepare($conn, 'INSERT INTO branch_settings (branch_id, k, v) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    mysqli_stmt_bind_param($s, 'iss', $branchId, $k, $v);
    mysqli_stmt_execute($s);
}

/** Setup done (Main Branch registered and records attached)? Cached per request. */
function br_enabled(mysqli $conn): bool
{
    static $on = null;
    if ($on !== null) { return $on; }
    try {
        if (!br_table_exists($conn, 'branch_settings')) { return $on = false; }
        return $on = br_setting($conn, 'setup_version') !== null;
    } catch (Throwable $e) { return $on = false; }
}

function br_audit(mysqli $conn, ?int $branchId, string $action, string $details, $old = null, $new = null): void
{
    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $o = $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE);
    $n = $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE);
    $s = mysqli_prepare($conn, 'INSERT INTO branch_audit_logs (branch_id, user_id, action, details, old_values, new_values, ip) VALUES (?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'iisssss', $branchId, $uid, $action, $details, $o, $n, $ip);
    mysqli_stmt_execute($s);
}

/* ------------------------------------------------------------------ branches */

function br_all(mysqli $conn, bool $includeClosed = true): array
{
    $cache = &$GLOBALS['__br_all_cache'];
    if ($cache === null) {
        $cache = [];
        foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM branches ORDER BY is_main DESC, code'), MYSQLI_ASSOC) as $b) { $cache[(int) $b['id']] = $b; }
    }
    return $includeClosed ? $cache : array_filter($cache, function ($b) { return $b['status'] !== 'Closed'; });
}

function br_get(mysqli $conn, int $id): ?array { return br_all($conn)[$id] ?? null; }

/** Forget the branch list read in this request (after a branch is created or its status changes). */
function br_forget(): void { $GLOBALS['__br_all_cache'] = null; }

function br_main_id(mysqli $conn): int
{
    foreach (br_all($conn) as $b) { if ((int) $b['is_main'] === 1) { return (int) $b['id']; } }
    return (int) (array_key_first(br_all($conn)) ?? 0);
}

function br_next_code(mysqli $conn): string
{
    $n = 0;
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT code FROM branches WHERE code LIKE 'RM-BR-%'"), MYSQLI_ASSOC) as $r) { $n = max($n, (int) substr($r['code'], 6)); }
    return 'RM-BR-' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
}

function br_label(?array $b): string { return $b ? $b['name'] . ' (' . $b['code'] . ')' : '—'; }

function br_badge(string $status): string
{
    $c = ['Active' => 'success', 'Suspended' => 'warning text-dark', 'Closed' => 'secondary'][$status] ?? 'secondary';
    return '<span class="badge bg-' . $c . '">' . br_e($status === 'Suspended' ? 'Temporarily Suspended' : $status) . '</span>';
}

/* ------------------------------------------------------------------ people & access */

function br_is_admin(): bool { return ($_SESSION['user_role'] ?? '') === 'Admin'; }

/** Branch ids a user is assigned to (active assignments today). */
function br_assigned_ids(mysqli $conn, int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) { return $cache[$userId]; }
    $today = date('Y-m-d');
    $s = mysqli_prepare($conn, "SELECT DISTINCT branch_id FROM employee_branch_assignments WHERE user_id = ? AND status = 'active' AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)");
    mysqli_stmt_bind_param($s, 'iss', $userId, $today, $today);
    mysqli_stmt_execute($s);
    return $cache[$userId] = array_map('intval', array_column(mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC), 'branch_id'));
}

function br_primary_id(mysqli $conn, int $userId): int
{
    $today = date('Y-m-d');
    $s = mysqli_prepare($conn, "SELECT branch_id FROM employee_branch_assignments WHERE user_id = ? AND kind = 'primary' AND status = 'active' AND start_date <= ? AND (end_date IS NULL OR end_date >= ?) ORDER BY start_date DESC, id DESC LIMIT 1");
    mysqli_stmt_bind_param($s, 'iss', $userId, $today, $today);
    mysqli_stmt_execute($s);
    $r = mysqli_fetch_row(mysqli_stmt_get_result($s));
    return $r ? (int) $r[0] : br_main_id($conn);
}

function br_has_all_access(mysqli $conn, int $userId, ?string $role = null): bool
{
    static $cache = [];
    if (!isset($cache[$userId])) {
        $s = mysqli_prepare($conn, 'SELECT all_branches FROM user_branch_access WHERE user_id = ?');
        mysqli_stmt_bind_param($s, 'i', $userId);
        mysqli_stmt_execute($s);
        $r = mysqli_fetch_row(mysqli_stmt_get_result($s));
        $cache[$userId] = $r ? (int) $r[0] === 1 : null;
    }
    if ($cache[$userId] !== null) { return $cache[$userId]; }
    return ($role ?? ($userId === (int) ($_SESSION['user_id'] ?? 0) ? ($_SESSION['user_role'] ?? '') : '')) === 'Admin'; // default: Administrators see all branches
}

/** The branches the signed-in user may see: null = all (company-wide), else a list of ids. */
function br_allowed_ids(mysqli $conn): ?array
{
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if (!$uid) { return []; }
    if (br_has_all_access($conn, $uid)) { return null; }
    $ids = br_assigned_ids($conn, $uid);
    return $ids ?: [br_primary_id($conn, $uid)];
}

function br_can_access(mysqli $conn, int $branchId): bool
{
    $a = br_allowed_ids($conn);
    return $a === null || in_array($branchId, $a, true);
}

/** Branch selected in the top bar: 0 = Company View (only for company-wide users). Always re-validated. */
function br_current(mysqli $conn): int
{
    if (!br_enabled($conn)) { return 0; }
    $allowed = br_allowed_ids($conn);
    $sel = (int) ($_SESSION['branch_view'] ?? -1);
    if ($sel === 0 && $allowed === null) { return 0; }
    if ($sel > 0 && br_get($conn, $sel) && ($allowed === null || in_array($sel, $allowed, true))) { return $sel; }
    // Default: company-wide users -> Company View; others -> their primary branch (or first allowed).
    if ($allowed === null) { $_SESSION['branch_view'] = 0; return 0; }
    $p = br_primary_id($conn, (int) $_SESSION['user_id']);
    $sel = in_array($p, $allowed, true) ? $p : (int) ($allowed[0] ?? $p);
    $_SESSION['branch_view'] = $sel;
    return $sel;
}

/** The branch a NEW record belongs to: the selected branch, or (Company View) the user's primary branch. */
function br_write_branch(mysqli $conn): ?int
{
    if (!br_enabled($conn)) { return null; }
    $c = br_current($conn);
    return $c > 0 ? $c : br_primary_id($conn, (int) ($_SESSION['user_id'] ?? 0));
}

/**
 * SQL condition limiting a list/report to what the user may see and to the selected branch.
 * $col e.g. 's.branch_id'. Company View with all access = no limit. Records without branch count as Main Branch.
 */
function br_scope_sql(mysqli $conn, string $col, ?int $onlyBranch = null): string
{
    if (!br_enabled($conn)) { return '1=1'; }
    $main = br_main_id($conn);
    $expr = "COALESCE($col, $main)";
    $sel = $onlyBranch ?? br_current($conn);
    if ($sel > 0) {
        if (!br_can_access($conn, $sel)) { return '1=0'; }
        return "$expr = " . (int) $sel;
    }
    $a = br_allowed_ids($conn);
    if ($a === null) { return '1=1'; }
    return $a ? "$expr IN (" . implode(',', array_map('intval', $a)) . ')' : '1=0';
}

/** Sets the branch of a record just created (only if empty), e.g. br_stamp($conn, 'sales', $saleId). */
function br_stamp(mysqli $conn, string $table, int $id, ?int $branchId = null): void
{
    try {
        if (!$id || !br_enabled($conn) || !isset(BR_TABLES[$table])) { return; }
        $branchId = $branchId ?? br_write_branch($conn);
        if (!$branchId) { return; }
        $col = BR_TABLES[$table][0];
        $s = mysqli_prepare($conn, "UPDATE `$table` SET `$col` = ? WHERE id = ? AND `$col` IS NULL");
        mysqli_stmt_bind_param($s, 'ii', $branchId, $id);
        mysqli_stmt_execute($s);
    } catch (Throwable $e) { error_log("br_stamp $table#$id: " . $e->getMessage()); }
}

/** The branch of an existing record (e.g. a sale), for related records (its income transaction...). */
function br_of(mysqli $conn, string $table, int $id): ?int
{
    try {
        if (!br_enabled($conn) || !isset(BR_TABLES[$table])) { return null; }
        $col = BR_TABLES[$table][0];
        $r = mysqli_fetch_row(mysqli_query($conn, "SELECT `$col` FROM `$table` WHERE id = " . (int) $id));
        return $r && $r[0] !== null ? (int) $r[0] : null;
    } catch (Throwable $e) { return null; }
}

/** Branch of an employee's work today (attendance): the selected branch if they are assigned there, else primary. */
function br_employee_work_branch(mysqli $conn, int $userId): ?int
{
    if (!br_enabled($conn)) { return null; }
    $sel = br_current($conn);
    if ($sel > 0 && in_array($sel, br_assigned_ids($conn, $userId), true)) { return $sel; }
    return br_primary_id($conn, $userId);
}

/**
 * Suspended / Closed branches: may this NEW operation happen? Returns null (yes) or the reason (no).
 * $op: one of BR_SUSPEND_RULES keys. Closed = nothing new. Suspended = per the settings (all blocked by default).
 */
function br_operation_block(mysqli $conn, ?int $branchId, string $op): ?string
{
    if (!$branchId || !br_enabled($conn)) { return null; }
    $b = br_get($conn, $branchId);
    if (!$b || $b['status'] === 'Active') { return null; }
    if ($b['status'] === 'Closed') { return $b['name'] . ' is CLOSED: no new operations can be recorded there. Its history stays available.'; }
    if (br_setting($conn, 'suspend_block_' . $op, $branchId, '1') === '1') {
        return $b['name'] . ' is TEMPORARILY SUSPENDED: ' . strtolower(BR_SUSPEND_RULES[$op] ?? 'this operation') . ' are not allowed until it is reactivated.';
    }
    return null;
}

/* ------------------------------------------------------------------ setup / migration */

/** What the setup will find and do (read-only). */
function br_assessment(mysqli $conn): array
{
    $counts = [];
    foreach (BR_TABLES as $t => [$col]) {
        if (!br_table_exists($conn, $t)) { $counts[$t] = null; continue; }
        $counts[$t] = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM `$t`"))[0];
    }
    // Opening date: the earliest dated record already in MY MOTIVE (verified from the data, not invented).
    $cands = [['transactions', 'transaction_date'], ['sales', 'sale_date'], ['attendance', 'attendance_date'], ['purchase_orders', 'order_date'], ['users', 'created_at'], ['customers', 'created_at']];
    $first = null; $source = null;
    foreach ($cands as [$t, $c]) {
        try {
            if (!br_table_exists($conn, $t) || !br_has_column($conn, $t, $c)) { continue; }
            $d = mysqli_fetch_row(mysqli_query($conn, "SELECT DATE(MIN(`$c`)) FROM `$t` WHERE `$c` IS NOT NULL AND `$c` > '1990-01-01'"))[0] ?? null;
            if ($d && ($first === null || $d < $first)) { $first = $d; $source = "$t.$c"; }
        } catch (Throwable $e) { /* ignore */ }
    }
    require_once __DIR__ . '/report_pdf.php';
    $co = report_company();
    return ['counts' => $counts, 'opening_date' => $first ?: date('Y-m-d'), 'opening_source' => $source, 'company' => $co,
            'phone' => defined('SMS_HELP_PHONE') ? SMS_HELP_PHONE : '0795344768'];
}

/**
 * Registers the existing operations as the Main Branch and attaches existing records to it.
 * Idempotent and additive: running it again changes nothing that is already set.
 */
function br_run_setup(mysqli $conn, int $actor, ?int $managerId = null): array
{
    br_tables($conn);
    $a = br_assessment($conn);
    $log = [];
    $main = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT id FROM branches WHERE is_main = 1 LIMIT 1'))[0] ?? 0);
    if (!$main) {
        $name = BR_MAIN_NAME; $code = BR_MAIN_CODE; $type = 'Head Office'; $prov = 'Kigali'; $dist = 'Kicukiro';
        $addr = $a['company']['address']; $phone = $a['phone']; $open = $a['opening_date'];
        $desc = 'The existing RISE MOTIVE operations (Head Office), registered as the Main Branch when multi-branch management was set up. '
              . 'Opening date taken from the earliest record in MY MOTIVE (' . ($a['opening_source'] ?: 'none') . '); please confirm it.';
        $s = mysqli_prepare($conn, "INSERT INTO branches (code, name, branch_type, is_main, province, district, address, phone, manager_user_id, opening_date, status, description, created_by)
            VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
        mysqli_stmt_bind_param($s, 'sssssssissi', $code, $name, $type, $prov, $dist, $addr, $phone, $managerId, $open, $desc, $actor);
        mysqli_stmt_execute($s);
        $main = (int) mysqli_insert_id($conn);
        br_forget();
        $log[] = "Main Branch registered: $name ($code), opening date $open.";
    }
    $added = br_add_columns($conn);
    if ($added) { $log[] = 'Branch columns added: ' . implode(', ', $added) . '.'; }
    foreach (BR_TABLES as $t => [$col]) {
        if (!br_table_exists($conn, $t) || !br_has_column($conn, $t, $col)) { continue; }
        mysqli_query($conn, "UPDATE `$t` SET `$col` = $main WHERE `$col` IS NULL");
        $n = mysqli_affected_rows($conn);
        if ($n > 0) { $log[] = "$t: $n existing record(s) attached to the Main Branch."; }
    }
    if (br_has_column($conn, 'purchase_orders', 'receiving_branch_id')) { mysqli_query($conn, "UPDATE purchase_orders SET receiving_branch_id = branch_id WHERE receiving_branch_id IS NULL"); }
    // Employees: a primary assignment to the Main Branch (from their account creation date). Accounts are untouched.
    $users = mysqli_fetch_all(mysqli_query($conn, "SELECT u.id, DATE(u.created_at) d FROM users u WHERE NOT EXISTS (SELECT 1 FROM employee_branch_assignments a WHERE a.user_id = u.id AND a.kind = 'primary' AND a.status = 'active')"), MYSQLI_ASSOC);
    $ins = mysqli_prepare($conn, "INSERT INTO employee_branch_assignments (user_id, branch_id, kind, start_date, note, created_by) VALUES (?, ?, 'primary', ?, 'Set up: existing employee of the Main Branch', ?)");
    foreach ($users as $u) {
        $uid = (int) $u['id']; $d = $u['d'] ?: $a['opening_date'];
        mysqli_stmt_bind_param($ins, 'iisi', $uid, $main, $d, $actor);
        mysqli_stmt_execute($ins);
    }
    if ($users) { $log[] = count($users) . ' employee(s) assigned to the Main Branch (same accounts, same passwords).'; }
    // Company-wide access: Administrators.
    mysqli_query($conn, "INSERT IGNORE INTO user_branch_access (user_id, all_branches, updated_by, updated_at) SELECT id, 1, $actor, NOW() FROM users WHERE role = 'Admin'");
    $n = mysqli_affected_rows($conn);
    if ($n > 0) { $log[] = "$n Administrator(s) given access to all branches (Company View)."; }
    br_setting_set($conn, 'setup_version', BR_SCHEMA_VERSION);
    br_setting_set($conn, 'setup_at', date('Y-m-d H:i:s'));
    foreach (array_keys(BR_SUSPEND_RULES) as $op) { if (br_setting($conn, 'suspend_block_' . $op) === null) { br_setting_set($conn, 'suspend_block_' . $op, '1'); } }
    br_audit($conn, $main, 'setup', "Multi-branch setup run. " . implode(' ', $log), null, ['counts' => $a['counts']]);
    return $log;
}

/** Keeps the branch data consistent for records created by pages that do not stamp a branch yet (safety net). */
function br_sweep(mysqli $conn): void
{
    if (!br_enabled($conn)) { return; }
    $last = (int) br_setting($conn, 'last_sweep', 0, '0');
    if ($last > time() - 300) { return; }
    br_setting_set($conn, 'last_sweep', (string) time());
    $main = br_main_id($conn);
    foreach (BR_TABLES as $t => [$col, $creator]) {
        try {
            if (!br_table_exists($conn, $t) || !br_has_column($conn, $t, $col)) { continue; }
            if ($t === 'users') {   // new employee accounts: primary = Main Branch until assigned elsewhere
                $new = mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM users WHERE branch_id IS NULL"), MYSQLI_ASSOC);
                foreach ($new as $u) { br_set_primary($conn, (int) $u['id'], $main, date('Y-m-d'), 'New employee account: Main Branch by default', null); }
                continue;
            }
            if ($creator && br_has_column($conn, $t, $creator)) {
                mysqli_query($conn, "UPDATE `$t` x JOIN users u ON u.id = x.`$creator` SET x.`$col` = u.branch_id WHERE x.`$col` IS NULL AND u.branch_id IS NOT NULL");
            }
            mysqli_query($conn, "UPDATE `$t` SET `$col` = $main WHERE `$col` IS NULL");
        } catch (Throwable $e) { error_log("br_sweep $t: " . $e->getMessage()); }
    }
}

/* ------------------------------------------------------------------ assignments & transfers */

/** Makes $branchId the user's primary branch from $from (ends the previous primary the day before). */
function br_set_primary(mysqli $conn, int $userId, int $branchId, string $from, string $note, ?int $transferId): void
{
    $prevDay = date('Y-m-d', strtotime($from . ' -1 day'));
    $s = mysqli_prepare($conn, "UPDATE employee_branch_assignments SET end_date = IF(start_date > ?, start_date, ?), status = 'ended' WHERE user_id = ? AND kind = 'primary' AND status = 'active'");
    mysqli_stmt_bind_param($s, 'ssi', $prevDay, $prevDay, $userId);
    mysqli_stmt_execute($s);
    $actor = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $i = mysqli_prepare($conn, "INSERT INTO employee_branch_assignments (user_id, branch_id, kind, start_date, note, transfer_id, created_by) VALUES (?, ?, 'primary', ?, ?, ?, ?)");
    mysqli_stmt_bind_param($i, 'iissii', $userId, $branchId, $from, $note, $transferId, $actor);
    mysqli_stmt_execute($i);
    $u = mysqli_prepare($conn, 'UPDATE users SET branch_id = ? WHERE id = ?');
    mysqli_stmt_bind_param($u, 'ii', $branchId, $userId);
    mysqli_stmt_execute($u);
}

/** Applies approved transfers whose effective date has come (called on Branch pages and by the sweep). */
function br_apply_due_transfers(mysqli $conn): int
{
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM branch_transfers WHERE status = 'Approved' AND applied_at IS NULL AND effective_date <= '" . date('Y-m-d') . "'"), MYSQLI_ASSOC);
    foreach ($rows as $t) {
        br_set_primary($conn, (int) $t['user_id'], (int) $t['to_branch_id'], $t['effective_date'], 'Transfer #' . $t['id'] . ': ' . mb_substr($t['reason'], 0, 400), (int) $t['id']);
        mysqli_query($conn, 'UPDATE branch_transfers SET applied_at = NOW() WHERE id = ' . (int) $t['id']);
        br_audit($conn, (int) $t['to_branch_id'], 'transfer_applied', 'Transfer #' . $t['id'] . ' applied: employee #' . $t['user_id'] . ' now belongs to branch #' . $t['to_branch_id'] . ' from ' . $t['effective_date'] . '. History unchanged.');
    }
    return count($rows);
}

/* ------------------------------------------------------------------ figures (dashboard / profile) */

/** Per-branch figures for a period: revenue, expenses, employees, departments, stock value, pending. */
function br_figures(mysqli $conn, string $from, string $to): array
{
    $main = br_main_id($conn);
    $out = [];
    foreach (br_all($conn) as $id => $b) { $out[$id] = ['revenue' => 0.0, 'expenses' => 0.0, 'sales' => 0, 'employees' => 0, 'departments' => 0, 'stock_value' => 0.0, 'pending' => 0]; }
    $add = function (string $sql, string $key, bool $count = false) use ($conn, &$out) {
        try { foreach (mysqli_fetch_all(mysqli_query($conn, $sql), MYSQLI_ASSOC) as $r) { if (isset($out[(int) $r['b']])) { $out[(int) $r['b']][$key] = $count ? (int) $r['v'] : (float) $r['v']; } } }
        catch (Throwable $e) { error_log("br_figures $key: " . $e->getMessage()); }
    };
    $f = mysqli_real_escape_string($conn, $from); $t = mysqli_real_escape_string($conn, $to);
    $add("SELECT COALESCE(branch_id, $main) b, SUM(total_amount) v FROM sales WHERE status IN ('Paid', 'Partially Paid', 'Credit') AND sale_date BETWEEN '$f' AND '$t' GROUP BY b", 'revenue');
    $add("SELECT COALESCE(branch_id, $main) b, COUNT(*) v FROM sales WHERE status IN ('Paid', 'Partially Paid', 'Credit') AND sale_date BETWEEN '$f' AND '$t' GROUP BY b", 'sales', true);
    $add("SELECT COALESCE(branch_id, $main) b, SUM(amount) v FROM transactions WHERE transaction_type = 'Expense' AND status = 'approved' AND transaction_date BETWEEN '$f' AND '$t' GROUP BY b", 'expenses');
    $add("SELECT COALESCE(branch_id, $main) b, COUNT(*) v FROM users WHERE is_active = 1 GROUP BY b", 'employees', true);
    $add("SELECT COALESCE(branch_id, $main) b, COUNT(*) v FROM departments GROUP BY b", 'departments', true);
    $add("SELECT from_branch_id b, COUNT(*) v FROM branch_transfers WHERE status = 'Pending' GROUP BY b", 'pending', true);
    // Stock: until branch stock is set up (next stage), the whole company stock is held by the Main Branch.
    if (br_table_exists($conn, 'branch_stock')) {
        $add("SELECT bs.branch_id b, SUM(bs.quantity * p.buying_price) v FROM branch_stock bs JOIN products p ON p.id = bs.product_id GROUP BY bs.branch_id", 'stock_value');
    } else {
        try { $out[$main]['stock_value'] = (float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(quantity * buying_price), 0) FROM products WHERE item_type = 'Item' OR item_type IS NULL"))[0]; } catch (Throwable $e) { }
    }
    return $out;
}

/* ------------------------------------------------------------------ top bar switcher */

/** The branch selector shown in the top bar (nothing before setup). */
function br_switcher_html(mysqli $conn): string
{
    try {
        if (!br_enabled($conn) || empty($_SESSION['user_id'])) { return ''; }
        br_sweep($conn);
        $cur = br_current($conn);
        $allowed = br_allowed_ids($conn);
        $list = array_filter(br_all($conn), function ($b) use ($allowed) { return $allowed === null || in_array((int) $b['id'], $allowed, true); });
        $label = $cur ? (br_get($conn, $cur)['name'] ?? 'Branch') : 'Company View · All branches';
        $b = $cur ? br_get($conn, $cur) : null;
        $warn = $b && $b['status'] !== 'Active' ? ' <span class="badge bg-' . ($b['status'] === 'Closed' ? 'secondary' : 'warning text-dark') . '">' . br_e($b['status']) . '</span>' : '';
        $base = rtrim((string) (defined('BASE_URL') ? BASE_URL : ''), '/');
        $h = '<div class="dropdown rm-branch-switch"><a href="#" class="rm-branch-btn" data-bs-toggle="dropdown" aria-expanded="false" title="Branch you are working in">'
            . '<i class="bi bi-diagram-3"></i> <span class="rm-branch-name">' . br_e($label) . '</span>' . $warn . ' <i class="bi bi-chevron-down small"></i></a>'
            . '<div class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:280px;"><form method="POST" action="' . br_e($base) . '/modules/branches/switch.php" class="m-0">'
            . '<input type="hidden" name="back" value="' . br_e($_SERVER['REQUEST_URI'] ?? '') . '"><h6 class="dropdown-header">Work in</h6>';
        if ($allowed === null) {
            $h .= '<button class="dropdown-item' . ($cur === 0 ? ' active' : '') . '" name="branch" value="0"><i class="bi bi-buildings"></i> Company View · All branches</button><div class="dropdown-divider"></div>';
        }
        foreach ($list as $x) {
            $h .= '<button class="dropdown-item d-flex justify-content-between gap-2' . ((int) $x['id'] === $cur ? ' active' : '') . '" name="branch" value="' . (int) $x['id'] . '"><span>'
                . br_e($x['name']) . ' <span class="text-muted small">' . br_e($x['code']) . '</span></span>' . ($x['status'] !== 'Active' ? '<span class="small">' . br_e($x['status']) . '</span>' : '') . '</button>';
        }
        $h .= '</form></div></div>';
        return $h;
    } catch (Throwable $e) { error_log('branch switcher: ' . $e->getMessage()); return ''; }
}