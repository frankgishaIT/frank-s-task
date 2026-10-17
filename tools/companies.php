<?php
/**
 * MY MOTIVE SaaS: manage companies from the command line (run as the Linux root user).
 *
 *   php tools/companies.php list
 *   php tools/companies.php create --code=ACME --name="Acme Ltd" --owner-name="Jane Doe" --owner-email=jane@acme.test [--owner-phone=0788000000]
 *   php tools/companies.php suspend  --code=ACME
 *   php tools/companies.php activate --code=ACME
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/provisioning.php';

$command = $argv[1] ?? '';
$opt = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $arg, $m)) {
        $opt[$m[1]] = $m[2];
    }
}

function usage(): void
{
    echo "Usage:\n"
        . "  php tools/companies.php list\n"
        . "  php tools/companies.php create --code=ACME --name=\"Acme Ltd\" --owner-name=\"Jane Doe\" --owner-email=jane@acme.test [--owner-phone=0788000000]\n"
        . "  php tools/companies.php suspend  --code=ACME\n"
        . "  php tools/companies.php activate --code=ACME\n";
}

try {
    switch ($command) {
        case 'list':
            $res = mysqli_query(platform_conn(), 'SELECT id, company_code, name, status, db_name, owner_email, created_at FROM companies ORDER BY id');
            printf("%-4s %-14s %-28s %-10s %-22s %s\n", 'ID', 'CODE', 'NAME', 'STATUS', 'DATABASE', 'OWNER');
            while ($r = mysqli_fetch_assoc($res)) {
                printf("%-4d %-14s %-28s %-10s %-22s %s\n", $r['id'], $r['company_code'], mb_substr($r['name'], 0, 28), $r['status'], $r['db_name'], $r['owner_email']);
            }
            break;

        case 'create':
            foreach (['code', 'name', 'owner-name', 'owner-email'] as $need) {
                if (empty($opt[$need])) {
                    fwrite(STDERR, "Missing --$need\n\n");
                    usage();
                    exit(2);
                }
            }
            $r = provision_company($opt['code'], $opt['name'], $opt['owner-name'], $opt['owner-email'], $opt['owner-phone'] ?? null);
            echo "\nCompany created.\n";
            echo "  Company code:       {$r['code']}\n";
            echo "  Database:           {$r['db_name']}\n";
            echo "  Owner login email:  {$r['owner_email']}\n";
            echo "  Temporary password: {$r['owner_password']}     (shown ONCE: give it to the owner and ask them to change it)\n";
            break;

        case 'suspend':
        case 'activate':
            if (empty($opt['code'])) {
                fwrite(STDERR, "Missing --code\n\n");
                usage();
                exit(2);
            }
            provision_set_status($opt['code'], $command === 'suspend' ? 'suspended' : 'active');
            echo "Company " . platform_normalize_code($opt['code']) . " is now " . ($command === 'suspend' ? 'SUSPENDED' : 'ACTIVE') . ".\n";
            break;

        default:
            usage();
            exit($command === '' ? 0 : 2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");
    exit(1);
}
