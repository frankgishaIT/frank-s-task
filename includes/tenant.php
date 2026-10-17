<?php
/**
 * MY MOTIVE SaaS: decides WHICH company's database this request uses, then connects to it.
 *
 * config/db.php loads this file, and every page already loads config/db.php, so the
 * 159 pages and their SQL stay exactly as they are. They keep using $conn. The only
 * difference is that $conn now points at the database of the visitor's company.
 *
 * Rules:
 *   - A logged-in request uses the company stored in the SESSION (set at login, on the server).
 *     Nothing in the URL, a form or a cookie can change it.
 *   - The login page uses the company code the visitor typed.
 *   - A company that is not active cannot sign in, and a signed-in user is signed out.
 */

$isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1']);
if (!defined('BASE_URL')) {
    define('BASE_URL', $isLocalhost ? '/rm_os' : '');
}

require_once __DIR__ . '/platform.php';

$MM_COMPANY = null;   // the company of this request: a row of the platform "companies" table

if (defined('RM_OS_ALLOW_GUEST')) {
    // ---- The login page: the company comes from the code the visitor typed.
    if (isset($_POST['company_code']) && is_string($_POST['company_code'])) {
        $found = platform_company_by_code($_POST['company_code']);
        if ($found && $found['status'] === 'active') {
            $MM_COMPANY = $found;
        }
    }
} else {
    // ---- Every other page: sends visitors who are not signed in to the login page (as before) ...
    require_once __DIR__ . '/auth.php';

    // ... and then uses the company the visitor signed in to.
    $mmCompanyId = (int) ($_SESSION['company_id'] ?? 0);
    $found = $mmCompanyId > 0 ? platform_company_by_id($mmCompanyId) : null;
    if (!$found) {
        mm_leave_to_login('expired');          // an old session from before companies existed
    }
    if ($found['status'] !== 'active') {
        mm_leave_to_login('inactive');
    }
    $MM_COMPANY = $found;
    unset($mmCompanyId);
}

if ($MM_COMPANY) {
    $conn = tenant_connect($MM_COMPANY);
    unset($MM_COMPANY['db_pass_enc']);   // no page needs the encrypted password, so it is not kept in memory
}
unset($found);
