<?php
/**
 * NEW FILE: includes/access_control.php
 * Task II: centralized Roles & Permissions.
 *
 *   Permission  = "module.action", e.g. "sales.create", "transactions.approve", "loans.executive_delete".
 *   Role        = a reusable set of permissions (Cashier, Store Keeper ...). An employee can have several.
 *   Overrides   = per employee: GRANT (adds one permission) or DENY (removes one; a DENY always wins).
 *   Effective   = (union of the employee's roles) + grants - denies.
 *
 *   Every page is checked on the server (access_enforce_page(), called from config/db.php): the page's
 *   folder is the module, its file name the action. Direct URLs and hand-sent forms are refused.
 *   The existing role checks (require_role) stay in place: permissions only ever narrow access further.
 *
 *   Day one changes nothing: three system roles (Administrator, Manager, Employee) hold every permission
 *   (except the executive ones) and are given to each employee from their current role.
 */

/** module => [label, [actions]]. 'executive_delete' and 'manage' are high-risk. */
function access_catalog(): array
{
    $std = ['view', 'create', 'edit', 'approve', 'delete', 'reports'];
    return [
        'dashboard'         => ['Dashboard', ['view']],
        'departments'       => ['Departments', ['view', 'create', 'edit', 'delete']],
        'users'             => ['Employees', ['view', 'create', 'edit', 'delete']],
        'attendance'        => ['Attendance', ['view', 'create', 'edit', 'approve', 'delete', 'reports']],
        'timetable'         => ['Work Timetable', ['view', 'approve', 'reports', 'manage']], // NEW: WORK TIMETABLE module
        'branches'          => ['Branch Management', ['view', 'assign', 'approve', 'manage']], // NEW: MULTI-BRANCH
        'leave'             => ['Leave Requests', ['view', 'create', 'approve']],
        'projects'          => ['RM Targets', ['view', 'create', 'edit', 'delete']],
        'tasks'             => ['Tasks', $std],
        'transactions'      => ['Transactions', $std],
        'funds'             => ['RM Funds (capital, allocations, fund reports)', ['view', 'manage']],
        'categories'        => ['Categories', ['manage']],
        'products'          => ['RM Offerings / Inventory', ['view', 'create', 'edit', 'delete', 'reports']],
        'purchases'         => ['Purchase History', ['view']],
        'purchase_orders'   => ['Purchase Orders', ['view', 'create', 'edit', 'approve', 'delete', 'reports']],
        'business_parties'  => ['Business Parties', ['view', 'create', 'edit', 'delete', 'reports']],
        'customers'         => ['Customers', ['view', 'create', 'edit', 'delete', 'reports']],
        'sales'             => ['Sales', $std],
        'loans'             => ['Loans', ['view', 'create', 'edit', 'delete', 'reports', 'executive_delete']],
        'asset_management'  => ['Assets', ['view', 'create', 'edit', 'delete', 'reports', 'executive_delete']],
        'financial_reports' => ['Financial Reports (sensitive)', ['view']],
        'payroll'           => ['Payroll (sensitive)', ['view', 'create', 'edit', 'delete', 'reports']],
        'notifications'     => ['Notifications', ['view']],
        'messages'          => ['Messages (internal communication)', ['view', 'create']],
        'sms'               => ['SMS', ['view', 'create', 'templates', 'essential', 'test']],
        'market'            => ['RM Market Insights', ['view', 'request', 'research', 'finance', 'approve', 'reports', 'settings']],
        'customer_first'    => ['RM Customer First', ['record', 'manage']],
        'staff_cards'       => ['Staff Dashboard Cards (carousel)', ['manage', 'approve', 'settings']], // NEW (Task 4)
        'delegations'       => ['Delegations & RM Taskers', ['view', 'create', 'approve', 'taskers', 'finance', 'settings']], // NEW
        'audit_trail'       => ['Audit Trail (Market Insights, Financial Accounts, Customer First, carousel)', ['view']], // NEW (cross-task)
        'fin_accounts'      => ['Financial Accounts', ['view', 'manage', 'transfer', 'adjust', 'reconcile', 'approve', 'identifiers']],
        'portal'            => ['RM Customer Portal (staff side)', ['view', 'edit', 'manage']],
        'forecasting'       => ['RM Business Forecasting', ['view', 'reports', 'manage']],
        'rm_loans'          => ['RMC - Credit Hub (customer loans)', ['view', 'edit', 'approve', 'reports', 'manage']],
        'loan_intel'        => ['RM Loan Intelligence (corporate borrowing)', ['view', 'propose', 'finance', 'approve', 'configure']], // NEW
        'learning'          => ['RM Learning Centre (courses, admissions, students)', ['view', 'courses', 'admissions', 'academic', 'finance', 'accommodation', 'internship', 'settings']], // NEW
        'budget'            => ['Budget & Fund Control', ['view', 'create', 'approve', 'manage', 'reports']],
        'marketing'         => ['Marketing & Growth', ['view', 'create', 'leads', 'approve', 'finance', 'reports', 'manage']],
        'rm_invest'         => ['RMC - Investors Hub', ['view', 'create', 'edit', 'approve', 'reports', 'manage']],
        'kpi'               => ['Performance & KPI Management', ['view', 'create', 'edit', 'approve', 'reports', 'manage']],
        'library'           => ['RM Digital Library', ['view', 'create', 'edit', 'replace', 'download', 'share', 'publish', 'approve', 'archive', 'delete', 'manage']],
        'tools'             => ['Tools (database migrations)', ['manage']],
        'access'            => ['Roles & Permissions', ['manage']],
    ];
}

const ACCESS_ACTION_LABELS = [
    'view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'approve' => 'Approve / reject', 'delete' => 'Delete / cancel',
    'reports' => 'Reports', 'manage' => 'Manage', 'executive_delete' => 'Executive delete (fully paid / processed)',
    'replace' => 'Replace (new version)', 'download' => 'Download', 'share' => 'Share', 'publish' => 'Publish to customers / students',
    'archive' => 'Archive', 'templates' => 'Manage SMS templates and rules', 'essential' => 'Switch off essential SMS', 'test' => 'Send test SMS',
    'record' => 'Record and handle customer requests',
    'transfer' => 'Transfers between accounts', 'adjust' => 'Account adjustments', 'reconcile' => 'Bank reconciliation', 'identifiers' => 'See full account numbers',
    'request' => 'Record customer requests and discoveries', 'research' => 'Review demand (research)', 'finance' => 'See costs, prices and funds', 'settings' => 'Settings',
];
/**
 * Permissions added after Roles & Permissions was first set up, and which starting roles get them.
 * (Everything else that is new goes to the Administrator role only.)
 */
/** One-time grants: see access_ensure(). */
const ACCESS_ONE_TIME_GRANTS = [
    'marketing_everyone_v1' => ['roles' => ['manager' => ['marketing.view', 'marketing.create', 'marketing.leads', 'marketing.reports'],
                                            'employee' => ['marketing.view', 'marketing.create', 'marketing.leads', 'marketing.reports']],
        'note' => 'Marketing & Growth opened to everyone (management decision): Managers and Employees can view, create, work leads and see reports; approvals, finance and settings stay with Administrators.'],
];

/**
 * NEW (cross-task section 5.2): the SUGGESTED roles of the specification, created once and EMPTY. Nobody holds them
 * until an Administrator assigns them in Roles & Permissions, in addition to the person's Employee role.
 * Ordinary staff keep no access to financial adjustments, sensitive account details or management approvals.
 */
const ACCESS_SUGGESTED_ROLES = [
    'Supervisor' => ['desc' => 'Suggested role: reviews customer requests, validates information and assigns follow-ups. Give it in addition to Employee.',
        'perms' => ['customer_first.record', 'customer_first.manage', 'market.view', 'market.request', 'market.research', 'market.reports', 'tasks.view', 'tasks.create', 'tasks.edit']],
    'Finance User' => ['desc' => 'Suggested role: manages permitted financial records and reconciliation (no adjustments, approvals or account set-up). Give it in addition to Employee.',
        'perms' => ['fin_accounts.view', 'fin_accounts.transfer', 'fin_accounts.reconcile', 'transactions.view', 'transactions.create', 'transactions.edit', 'transactions.reports', 'funds.view', 'market.finance', 'audit_trail.view']],
];

const ACCESS_NEW_DEFAULTS = [
    'library.view' => ['admin', 'manager', 'employee'], 'library.download' => ['admin', 'manager', 'employee'],
    'library.create' => ['admin', 'manager'], 'library.edit' => ['admin', 'manager'], 'library.replace' => ['admin', 'manager'],
    'sms.templates' => ['admin'], 'sms.essential' => ['admin'], 'sms.test' => ['admin'],
    // NEW: RM Customer First (every employee records customer requests; managers monitor all of them)
    'customer_first.record' => ['admin', 'manager', 'employee'], 'customer_first.manage' => ['admin', 'manager'],
    // NEW: Staff Dashboard Cards — everyone sees the carousel; these are for managing it.
    'audit_trail.view' => ['admin', 'manager'], // NEW: each line is still shown only to those who may see its module
    // NEW: Delegations — staff delegate and compare; managers approve; admins manage Taskers, rates and settings; finance records payments.
    'delegations.view' => ['admin', 'manager', 'employee'], 'delegations.create' => ['admin', 'manager', 'employee'], 'delegations.approve' => ['admin', 'manager'],
    'delegations.taskers' => ['admin'], 'delegations.finance' => ['admin'], 'delegations.settings' => ['admin'],
    'staff_cards.manage' => ['admin', 'manager'], 'staff_cards.approve' => ['admin', 'manager'], 'staff_cards.settings' => ['admin'],
    // NEW: Financial Accounts (where the money is held). Finance users get transfer / reconcile in Roles & Permissions.
    'fin_accounts.view' => ['admin', 'manager'], 'fin_accounts.manage' => ['admin'], 'fin_accounts.transfer' => ['admin'], 'fin_accounts.adjust' => ['admin'],
    'fin_accounts.reconcile' => ['admin'], 'fin_accounts.approve' => ['admin'], 'fin_accounts.identifiers' => ['admin'],
    // NEW: RM Market Insights
    'market.view' => ['admin', 'manager', 'employee'], 'market.request' => ['admin', 'manager', 'employee'], 'market.research' => ['admin', 'manager'],
    'market.reports' => ['admin', 'manager'], 'market.finance' => ['admin'], 'market.approve' => ['admin'], 'market.settings' => ['admin'],
    'library.share' => ['admin', 'manager'], 'library.publish' => ['admin', 'manager'], 'library.approve' => ['admin', 'manager'], 'library.archive' => ['admin'],
    'library.delete' => ['admin'], 'library.manage' => ['admin'],
    'portal.view' => ['admin', 'manager'], 'portal.edit' => ['admin', 'manager'], 'portal.manage' => ['admin'],
    'forecasting.view' => ['admin', 'manager'], 'forecasting.reports' => ['admin', 'manager'], 'forecasting.manage' => ['admin'],
    'kpi.view' => ['admin', 'manager'], 'kpi.create' => ['admin', 'manager'], 'kpi.edit' => ['admin', 'manager'],
    'kpi.approve' => ['admin', 'manager'], 'kpi.reports' => ['admin', 'manager'], 'kpi.manage' => ['admin'],
    'rm_loans.view' => ['admin', 'manager'], 'rm_loans.edit' => ['admin', 'manager'], 'rm_loans.reports' => ['admin', 'manager'],
    'rm_loans.approve' => ['admin'], 'rm_loans.manage' => ['admin'],
    'budget.view' => ['admin', 'manager'], 'budget.create' => ['admin', 'manager'], 'budget.reports' => ['admin', 'manager'],
    'budget.approve' => ['admin'], 'budget.manage' => ['admin'],
    // CHANGED: everyone uses Marketing & Growth; only Administrators approve, decide on money and change settings.
    'marketing.view' => ['admin', 'manager', 'employee'], 'marketing.create' => ['admin', 'manager', 'employee'], 'marketing.leads' => ['admin', 'manager', 'employee'],
    'marketing.reports' => ['admin', 'manager', 'employee'], 'marketing.approve' => ['admin'], 'marketing.finance' => ['admin'], 'marketing.manage' => ['admin'],
    // NEW: Work Timetable. Everyone sees their own; supervisors approve and see reports; Administrators set timetables.
    'timetable.view' => ['admin', 'manager', 'employee'], 'timetable.approve' => ['admin', 'manager'],
    'timetable.reports' => ['admin', 'manager'], 'timetable.manage' => ['admin'],
    // NEW: Branch Management. Head Office (company-wide users) registers and controls branches; managers/HR assign staff.
    'loan_intel.view' => ['admin', 'manager'], 'loan_intel.propose' => ['admin', 'manager'], 'loan_intel.finance' => ['admin'], 'loan_intel.approve' => ['admin'], 'loan_intel.configure' => ['admin'], // NEW
    'learning.view' => ['admin', 'manager'], 'learning.courses' => ['admin'], 'learning.admissions' => ['admin', 'manager'], 'learning.academic' => ['admin'], // NEW
    'learning.finance' => ['admin'], 'learning.internship' => ['admin'], 'learning.settings' => ['admin'], 'learning.accommodation' => ['admin', 'manager'], // NEW
    'branches.view' => ['admin', 'manager'], 'branches.assign' => ['admin', 'manager'], 'branches.approve' => ['admin'], 'branches.manage' => ['admin'],
    'rm_invest.view' => ['admin', 'manager'], 'rm_invest.create' => ['admin', 'manager'], 'rm_invest.edit' => ['admin', 'manager'],
    'rm_invest.reports' => ['admin', 'manager'], 'rm_invest.approve' => ['admin'], 'rm_invest.manage' => ['admin'],
];
/** Permissions only the Managing Director holds at the start (not in any system role). */
const ACCESS_EXECUTIVE = ['loans.executive_delete', 'asset_management.executive_delete'];

function access_all_permissions(): array
{
    $out = [];
    foreach (access_catalog() as $m => [$label, $actions]) { foreach ($actions as $a) { $out[] = $m . '.' . $a; } }
    return $out;
}

/** High-risk: deletions, approvals, payroll, funds, financial reports, access management, executive. */
function access_is_high_risk(string $perm): bool
{
    [$m, $a] = explode('.', $perm) + [1 => ''];
    return in_array($a, ['delete', 'approve', 'manage', 'executive_delete'], true)
        || in_array($m, ['payroll', 'funds', 'financial_reports', 'access'], true);
}

/* ------------------------------------------------------------------ storage */

function access_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS acl_roles (
        id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(80) NOT NULL, description VARCHAR(255) NULL,
        system_key VARCHAR(20) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS acl_role_permissions (
        role_id INT(11) NOT NULL, permission VARCHAR(60) NOT NULL, PRIMARY KEY (role_id, permission)) ENGINE=InnoDB');
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS acl_user_roles (
        user_id INT(11) NOT NULL, role_id INT(11) NOT NULL, PRIMARY KEY (user_id, role_id)) ENGINE=InnoDB');
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS acl_user_overrides (
        user_id INT(11) NOT NULL, permission VARCHAR(60) NOT NULL, effect ENUM('grant','deny') NOT NULL,
        PRIMARY KEY (user_id, permission)) ENGINE=InnoDB");
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS acl_audit (
        id INT(11) NOT NULL AUTO_INCREMENT, actor_id INT(11) NULL, target_user_id INT(11) NULL, role_id INT(11) NULL,
        action VARCHAR(40) NOT NULL, details TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY target_user_id (target_user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // First run: the three system roles (same access as today) and each employee's role from their current one.
    if ((int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM acl_roles'))[0] === 0) {
        // (Permissions with their own starting rule, ACCESS_NEW_DEFAULTS, are given just below.)
        $everything = array_values(array_diff(access_all_permissions(), ACCESS_EXECUTIVE, array_keys(ACCESS_NEW_DEFAULTS)));
        foreach (['admin' => 'Administrator', 'manager' => 'Manager', 'employee' => 'Employee'] as $key => $name) {
            $s = mysqli_prepare($conn, 'INSERT INTO acl_roles (name, description, system_key) VALUES (?, ?, ?)');
            $desc = 'Starting role: same access as the "' . $name . '" account type had before Roles & Permissions. '
                  . 'The pages\' own role rules still apply on top.';
            mysqli_stmt_bind_param($s, 'sss', $name, $desc, $key);
            mysqli_stmt_execute($s);
            $rid = (int) mysqli_insert_id($conn);
            $p = mysqli_prepare($conn, 'INSERT INTO acl_role_permissions (role_id, permission) VALUES (?, ?)');
            foreach ($everything as $perm) {
                if ($key !== 'admin' && $perm === 'access.manage') { continue; } // only Administrators manage access
                mysqli_stmt_bind_param($p, 'is', $rid, $perm);
                mysqli_stmt_execute($p);
            }
            mysqli_query($conn, "INSERT IGNORE INTO acl_user_roles (user_id, role_id) SELECT id, $rid FROM users WHERE LOWER(role) = '$key'");
        }
        // The Managing Director: the first Administrator account gets the executive permissions.
        $md = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM users WHERE LOWER(role) = 'admin' AND is_active = 1 ORDER BY id LIMIT 1"));
        if ($md) {
            foreach (ACCESS_EXECUTIVE as $perm) {
                $g = mysqli_prepare($conn, "INSERT IGNORE INTO acl_user_overrides (user_id, permission, effect) VALUES (?, ?, 'grant')");
                $mid = (int) $md[0];
                mysqli_stmt_bind_param($g, 'is', $mid, $perm);
                mysqli_stmt_execute($g);
            }
        }
        access_log($conn, null, null, null, 'setup', 'Roles & Permissions started: Administrator, Manager and Employee roles created with the same access as before; executive delete given to the first Administrator account.');
    }
    // NEW: permissions added to the catalog later (new modules) reach the starting roles once.
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS acl_known_permissions (permission VARCHAR(60) NOT NULL, PRIMARY KEY (permission)) ENGINE=InnoDB');
    $known = array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT permission FROM acl_known_permissions'), MYSQLI_ASSOC), 'permission');
    if (!$known) { // first time: everything that exists now is already handled by the setup above
        $known = array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT DISTINCT permission FROM acl_role_permissions'), MYSQLI_ASSOC), 'permission');
        $known = array_values(array_diff($known, array_keys(ACCESS_NEW_DEFAULTS)));
        foreach (array_merge($known, ACCESS_EXECUTIVE) as $p) { mysqli_query($conn, "INSERT IGNORE INTO acl_known_permissions VALUES ('" . mysqli_real_escape_string($conn, $p) . "')"); }
        $known = array_merge($known, ACCESS_EXECUTIVE);
    }
    foreach (array_diff(access_all_permissions(), $known) as $perm) {
        $roles = ACCESS_NEW_DEFAULTS[$perm] ?? ['admin'];
        foreach ($roles as $key) {
            $k = mysqli_real_escape_string($conn, $key); $pp = mysqli_real_escape_string($conn, $perm);
            mysqli_query($conn, "INSERT IGNORE INTO acl_role_permissions (role_id, permission) SELECT id, '$pp' FROM acl_roles WHERE system_key = '$k'");
        }
        mysqli_query($conn, "INSERT IGNORE INTO acl_known_permissions VALUES ('" . mysqli_real_escape_string($conn, $perm) . "')");
        access_log($conn, null, null, null, 'permission_added', 'New permission "' . access_label($perm) . '" given to the starting roles: ' . implode(', ', $roles) . '.');
    }

    // NEW: one-time grants (a decision taken after a permission already existed). Each runs ONCE, is logged, and is
    // remembered: if an Administrator later removes the permission from a role, it stays removed.
    foreach (ACCESS_ONE_TIME_GRANTS as $key => $grant) {
        $marker = mysqli_real_escape_string($conn, 'grant:' . $key);
        if ((int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM acl_known_permissions WHERE permission = '$marker'"))[0]) { continue; }
        foreach ($grant['roles'] as $roleKey => $perms) {
            foreach ($perms as $perm) {
                $k = mysqli_real_escape_string($conn, $roleKey); $pp = mysqli_real_escape_string($conn, $perm);
                mysqli_query($conn, "INSERT IGNORE INTO acl_role_permissions (role_id, permission) SELECT id, '$pp' FROM acl_roles WHERE system_key = '$k'");
            }
        }
        mysqli_query($conn, "INSERT IGNORE INTO acl_known_permissions VALUES ('$marker')");
        access_log($conn, null, null, null, 'permission_added', $grant['note']);
    }

    // NEW: the suggested roles (Supervisor, Finance User), once, empty. A role an Administrator deleted is not re-created.
    foreach (ACCESS_SUGGESTED_ROLES as $name => $r) {
        $marker = mysqli_real_escape_string($conn, 'role:' . $name);
        if ((int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM acl_known_permissions WHERE permission = '$marker'"))[0]) { continue; }
        mysqli_query($conn, "INSERT IGNORE INTO acl_known_permissions VALUES ('$marker')");
        $n = mysqli_real_escape_string($conn, $name);
        if (mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM acl_roles WHERE name = '$n'"))) { continue; } // a role with that name already exists: leave it
        $s = mysqli_prepare($conn, 'INSERT INTO acl_roles (name, description) VALUES (?, ?)');
        mysqli_stmt_bind_param($s, 'ss', $name, $r['desc']);
        mysqli_stmt_execute($s);
        $rid = (int) mysqli_insert_id($conn);
        $p = mysqli_prepare($conn, 'INSERT IGNORE INTO acl_role_permissions (role_id, permission) VALUES (?, ?)');
        foreach (array_intersect($r['perms'], access_all_permissions()) as $perm) { mysqli_stmt_bind_param($p, 'is', $rid, $perm); mysqli_stmt_execute($p); }
        access_log($conn, null, null, $rid, 'role_created', 'Suggested role "' . $name . '" created (nobody holds it yet): ' . implode(', ', $r['perms']) . '.');
    }

    // An employee added later without any role gets the starting role of their account type.
    mysqli_query($conn, "INSERT IGNORE INTO acl_user_roles (user_id, role_id)
        SELECT u.id, r.id FROM users u JOIN acl_roles r ON r.system_key = LOWER(u.role)
        WHERE NOT EXISTS (SELECT 1 FROM acl_user_roles x WHERE x.user_id = u.id)");
}

function access_log(mysqli $conn, ?int $actor, ?int $target, ?int $roleId, string $action, string $details): void
{
    $s = mysqli_prepare($conn, 'INSERT INTO acl_audit (actor_id, target_user_id, role_id, action, details) VALUES (?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'iiiss', $actor, $target, $roleId, $action, $details);
    mysqli_stmt_execute($s);
}

/** ['roles' => [id => name], 'from_roles' => [perm], 'grants' => [perm], 'denies' => [perm], 'effective' => [perm]] */
function access_user_breakdown(mysqli $conn, int $userId, ?array $roleIds = null, ?array $grants = null, ?array $denies = null): array
{
    access_ensure($conn);
    if ($roleIds === null) {
        $roleIds = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT role_id FROM acl_user_roles WHERE user_id = ' . $userId), MYSQLI_ASSOC), 'role_id'));
    }
    if ($grants === null || $denies === null) {
        $rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT permission, effect FROM acl_user_overrides WHERE user_id = ' . $userId), MYSQLI_ASSOC);
        $grants = $grants ?? array_column(array_filter($rows, function ($r) { return $r['effect'] === 'grant'; }), 'permission');
        $denies = $denies ?? array_column(array_filter($rows, function ($r) { return $r['effect'] === 'deny'; }), 'permission');
    }
    $roles = [];
    $fromRoles = [];
    if ($roleIds) {
        $in = implode(',', array_map('intval', $roleIds));
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id, name FROM acl_roles WHERE id IN ($in)"), MYSQLI_ASSOC) as $r) { $roles[(int) $r['id']] = $r['name']; }
        $fromRoles = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT DISTINCT permission FROM acl_role_permissions WHERE role_id IN ($in)"), MYSQLI_ASSOC), 'permission');
    }
    $effective = array_values(array_diff(array_unique(array_merge($fromRoles, $grants)), $denies));
    sort($effective);
    return ['roles' => $roles, 'from_roles' => $fromRoles, 'grants' => $grants, 'denies' => $denies, 'effective' => $effective];
}

/** Does the signed-in employee have this permission? (cached for the request) */
function access_can(string $perm, ?mysqli $conn = null): bool
{
    static $cache = [];   // per user, for the request
    $conn = $conn ?? ($GLOBALS['conn'] ?? null);
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if (!$conn instanceof mysqli || $uid <= 0) { return false; }
    if (!isset($cache[$uid])) {
        try { $cache[$uid] = array_flip(access_user_breakdown($conn, $uid)['effective']); }
        catch (Throwable $e) { error_log('access_can: ' . $e->getMessage()); $cache[$uid] = []; }
    }
    return isset($cache[$uid][$perm]);
}

/** The permission a page needs: '*' = everyone signed in, null = not a module page. */
function access_page_permission(string $script): ?string
{
    if (!preg_match('#/modules/([A-Za-z0-9_]+)/([A-Za-z0-9_]+)\.php$#', $script, $m)) { return null; }
    [, $module, $file] = $m;
    $f = strtolower($file);

    // Pages anyone signed in may open (they show only the person's own data, or are general).
    $open = ['dashboard/index', 'search/index', 'search/live', 'users/profile', 'payroll/my', 'payroll/payslip',
             'notifications/index', 'notifications/view', 'notifications/open', 'notifications/action', 'notifications/mark_read', 'notifications/delete',
             'leave/index', 'leave/create', 'leave/view', 'company/profile', 'tools/images'];
    if (in_array($module . '/' . $f, $open, true) || $module === 'search') { return '*'; }

    // Pages with their own permission.
    if ($module === 'transactions') {
        if (preg_match('/^(capital_|allocate_profit|fund_report)/', $f)) {
            return preg_match('/^fund_report/', $f) ? 'funds.view' : 'funds.manage';
        }
        if ($f === 'audit' || $f === 'delete_confirm') { return 'transactions.delete'; }
        if ($f === 'get_fund_balance') { return 'transactions.create'; }
    }
    if ($module === 'access') { return 'access.manage'; }
    if ($module === 'categories') { return 'categories.manage'; }
    if ($module === 'tools') { return 'tools.manage'; }
    if ($module === 'messages') { return $f === 'create' ? 'messages.create' : 'messages.view'; }
    // RM Digital Library: each page checks its finer permissions itself (download, share, archive ...).
    // Performance & KPIs: "My performance", a KPI page and reviews check the person's own records inside the page.
    if ($module === 'marketing') { return ['settings' => 'marketing.manage', 'reports' => 'marketing.reports'][$f] ?? 'marketing.view'; }
    if ($module === 'budget') { return ['reports' => 'budget.reports', 'settings' => 'budget.manage'][$f] ?? 'budget.view'; }
    if ($module === 'rm_invest') { return ['settings' => 'rm_invest.manage', 'reports' => 'rm_invest.reports'][$f] ?? 'rm_invest.view'; }
    if ($module === 'rm_loans') { return ['settings' => 'rm_loans.manage', 'reports' => 'rm_loans.reports'][$f] ?? 'rm_loans.view'; }
    // NEW: Work Timetable. Own-data pages are open to everyone signed in (each page checks whose data it shows).
    // NEW: Branch Management. switch = everyone (the page itself checks which branches the user may use).
    if ($module === 'branches') {
        return ['switch' => '*', 'index' => 'branches.view', 'reports' => 'branches.view', 'branches' => 'branches.view', 'view' => 'branches.view', 'assignments' => 'branches.assign',
                'transfers' => '*'][$f] ?? 'branches.manage';
    }
    // NEW: Company Loans & Investment Intelligence. Proposal and audit pages check view / propose / approve inside.
    if ($module === 'loan_intel') { return in_array($f, ['proposals', 'proposal', 'proposal_edit', 'audit', 'common'], true) ? '*' : ($f === 'settings' ? 'loan_intel.configure' : 'loan_intel.view'); }
    // NEW: RM Learning Centre. Every page checks its own permission (view / courses / admissions / settings).
    if ($module === 'learning') { return in_array($f, ['settings', 'templates', 'template_edit'], true) ? 'learning.settings' : '*'; }
    if ($module === 'timetable') {
        return ['index' => '*', 'my' => '*', 'action' => '*', 'api' => '*', 'timetable_pdf' => '*', 'day' => '*', 'corrections' => '*', 'cron' => '*',
                'board' => 'timetable.approve', 'approvals' => 'timetable.approve', 'reports' => 'timetable.reports'][$f] ?? 'timetable.manage';
    }
    if ($module === 'kpi') { return ['my' => '*', 'kpi' => '*', 'reviews' => '*', 'settings' => 'kpi.manage', 'teams' => 'kpi.create'][$f] ?? 'kpi.view'; }
    if ($module === 'forecasting') { return 'forecasting.view'; } // finer checks inside (export, scenario settings)
    if ($module === 'portal') { return in_array($f, ['accounts', 'settings'], true) ? 'portal.manage' : (in_array($f, ['documents', 'records'], true) ? 'portal.edit' : 'portal.view'); }
    // NEW (Task 10): SMS Management. Template / log / usage pages need sms.view; each page checks sms.templates, sms.essential, sms.test itself.
    if ($module === 'sms' && in_array($f, ['index', 'template', 'log', 'usage', 'settings'], true)) { return $f === 'settings' ? 'sms.templates' : 'sms.view'; }
    if ($module === 'audit_trail') { return 'audit_trail.view'; } // NEW
    if ($module === 'delegations') { return '*'; } // NEW: each Delegations page checks its own permission
    if ($module === 'staff_cards') { return '*'; } // NEW: the carousel feed is for everyone; each management page checks its own permission
    if ($module === 'customer_first') { return '*'; } // NEW: every Customer First page checks its own permission
    if ($module === 'fin_accounts') { return '*'; } // NEW: every Financial Accounts page checks its own permission
    if ($module === 'market') { return $f === 'settings' ? 'market.settings' : '*'; } // NEW: every Market Insights page checks its own permission
    if ($module === 'library') { return ['upload' => 'library.create', 'folders' => 'library.manage', 'access_settings' => 'library.manage', 'customer_lookup' => 'library.publish'][$f] ?? 'library.view'; }
    if (($module === 'loans' || $module === 'asset_management') && strpos($f, 'executive') !== false) { return $module . '.executive_delete'; }
    if ($module === 'asset_management' && in_array($f, ['valuation', 'valuation_evidence', 'refund_receive', 'sync_capital_fund'], true)) { return 'asset_management.edit'; }
    if ($module === 'sales' && in_array($f, ['ledger', 'invoice', 'receipt'], true)) { return 'sales.view'; }
    if ($module === 'sales' && in_array($f, ['pay_credit', 'record_payment'], true)) { return 'sales.create'; }
    if ($module === 'sales' && in_array($f, ['category_profit', 'category_profit_pdf'], true)) { return 'sales.reports'; }
    if ($module === 'customers' && in_array($f, ['analytics', 'registrations'], true)) { return 'customers.reports'; } // CHANGED: + Registration branches report // NEW (Task 2): Customer Analytics
    if ($module === 'customers' && preg_match('/^reward/', $f)) { return in_array($f, ['rewards', 'reward_evidence'], true) ? 'customers.view' : 'customers.edit'; }

    // Everything else: the module = folder, the action = file name.
    $catalog = access_catalog();
    if (!isset($catalog[$module])) { return '*'; } // a module this file does not know yet: the page's own role check applies
    $actions = $catalog[$module][1];
    $action = 'view';
    if (strpos($f, 'request_') === 0) { $action = 'edit'; } // e.g. Request Cancel: asking, not deciding
    elseif (preg_match('/approve|reject|review|accept/', $f)) { $action = 'approve'; }
    elseif (preg_match('/delete|cancel|deactivate|dispose|remove|undo/', $f)) { $action = 'delete'; }
    elseif (preg_match('/edit|update/', $f)) { $action = 'edit'; }
    elseif (preg_match('/report|pdf|export|statement|print/', $f)) { $action = 'reports'; }
    elseif (preg_match('/create|new|add|restock|record|pay|submit|start|compose|send/', $f)) { $action = 'create'; }
    if (!in_array($action, $actions, true)) { $action = in_array('view', $actions, true) ? 'view' : $actions[0]; }
    return $module . '.' . $action;
}

/** Refuses the current page when the signed-in employee lacks its permission. Called from config/db.php. */
function access_enforce_page(mysqli $conn): void
{
    if (empty($_SESSION['user_id'])) { return; }
    $perm = access_page_permission((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($perm === null || $perm === '*') { return; }
    try { access_ensure($conn); } catch (Throwable $e) { error_log('access_ensure: ' . $e->getMessage()); return; }
    if (!access_can($perm, $conn)) {
        http_response_code(403);
        $GLOBALS['ACCESS_DENIED_PERMISSION'] = $perm;
        if (is_file(__DIR__ . '/access_denied.php')) { include __DIR__ . '/access_denied.php'; }
        else { echo 'You do not have permission to open this page (' . htmlspecialchars($perm, ENT_QUOTES, 'UTF-8') . ').'; }
        exit;
    }
}

/** Removes sidebar links to pages the employee may not open. */
function access_filter_menu(string $html): string
{
    return (string) preg_replace_callback(
        '~[ \t]*<a href="\.\./([A-Za-z0-9_]+)/([A-Za-z0-9_]+\.php)"[^>]*>.*?</a>[ \t]*\r?\n?~s',
        static function (array $m): string {
            $perm = access_page_permission('/modules/' . $m[1] . '/' . $m[2]);
            return ($perm === null || $perm === '*' || access_can($perm)) ? $m[0] : '';
        },
        $html
    );
}

function access_label(string $perm): string
{
    [$m, $a] = explode('.', $perm) + [1 => ''];
    return (access_catalog()[$m][0] ?? $m) . ': ' . (ACCESS_ACTION_LABELS[$a] ?? $a);
}