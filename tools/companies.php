<?php
/**
 * MY MOTIVE SaaS: manage companies from the command line (run as the Linux root user).
 *
 *   php tools/companies.php list
 *   php tools/companies.php create --code=ACME --name="Acme Ltd" --owner-name="Jane Doe" --owner-email=jane@acme.test [--owner-phone=0788000000]
 *   php tools/companies.php package  --code=ACME --package=hasty      (dream, hasty or owner)
 *   php tools/companies.php suspend  --code=ACME
 *   php tools/companies.php activate --code=ACME
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/provisioning.php';
require_once __DIR__ . '/../includes/subscriptions.php';
require_once __DIR__ . '/../includes/features.php';

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
        . "  php tools/companies.php create --code=ACME --name=\"Acme Ltd\" --owner-name=\"Jane Doe\" --owner-email=jane@acme.test [--package=dream|hasty] [--owner-phone=0788000000]\n"
        . "  php tools/companies.php package  --code=ACME --package=hasty\n"
        . "  php tools/companies.php renew    --code=ACME --method=\\\"MTN MoMo\\\" --reference=R1 --amount=40000\n"
        . "  php tools/companies.php setcode  --code=OLD --to=NEWCODE\n"
        . "  php tools/companies.php suspend  --code=ACME\n"
        . "  php tools/companies.php activate --code=ACME\n";
}

try {
    switch ($command) {
        case 'list':
            $res = mysqli_query(platform_conn(), 'SELECT id, company_code, name, status, db_name, owner_email, created_at FROM companies ORDER BY id');
            printf("%-4s %-14s %-26s %-10s %-9s %-20s %s\n", 'ID', 'CODE', 'NAME', 'STATUS', 'PACKAGE', 'DATABASE', 'OWNER');
            while ($r = mysqli_fetch_assoc($res)) {
                $pkg = platform_package_for_company((int) $r['id']);
                printf("%-4d %-14s %-26s %-10s %-9s %-20s %s\n", $r['id'], $r['company_code'], mb_substr($r['name'], 0, 26), $r['status'], $pkg['code'] ?? 'none', $r['db_name'], $r['owner_email']);
            }
            break;

        case 'create':
            foreach (['name', 'owner-name', 'owner-email'] as $need) {
                if (empty($opt[$need])) {
                    fwrite(STDERR, "Missing --$need\n\n");
                    usage();
                    exit(2);
                }
            }
            $package = strtolower($opt['package'] ?? 'dream');
            if (!in_array($package, ['dream', 'hasty', 'owner'], true)) {
                fwrite(STDERR, "The package must be dream, hasty or owner.\n");
                exit(2);
            }
            $code = !empty($opt['code']) ? $opt['code'] : platform_next_company_code();   // no code given: the next automatic one
            $r = provision_company($code, $opt['name'], $opt['owner-name'], $opt['owner-email'], $opt['owner-phone'] ?? null);
            company_set_package((int) $r['company_id'], $package, mm_cli_actor());
            echo "\nCompany created.\n";
            echo "  Business code:       {$r['code']}\n";
            echo "  Database:           {$r['db_name']}\n";
            echo "  Package:            $package\n";
            echo "  Owner login email:  {$r['owner_email']}\n";
            echo "  Temporary password: {$r['owner_password']}     (shown ONCE: give it to the owner and ask them to change it)\n";
            break;

        case 'package':
            $pk = strtolower($opt['package'] ?? '');
            $co = platform_company_by_code($opt['code'] ?? '');
            if (!$co || !in_array($pk, ['dream', 'hasty', 'owner'], true)) {
                fwrite(STDERR, "Give an existing --code and --package=dream|hasty|owner.\n");
                exit(2);
            }
            company_set_package((int) $co['id'], $pk, mm_cli_actor());
            echo "Business {$co['company_code']} is now on the $pk package.\n";
            break;

        case 'renew':
            $co = platform_company_by_code($opt['code'] ?? '');
            if (!$co || empty($opt['method']) || empty($opt['reference']) || !isset($opt['amount'])) {
                fwrite(STDERR, "Give --code, --method=\"MTN MoMo\", --reference and --amount.\n");
                exit(2);
            }
            $end = company_record_renewal((int) $co['id'], $opt['method'], $opt['reference'], (int) $opt['amount'], mm_cli_actor());
            echo "Business {$co['company_code']} is active until $end.\n";
            break;

        case 'setcode':
            $co = platform_company_by_code($opt['code'] ?? '');
            $to = platform_normalize_code($opt['to'] ?? '');
            if (!$co || !platform_valid_code($to)) {
                fwrite(STDERR, "Give an existing --code and a valid --to=NEWCODE.\n");
                exit(2);
            }
            if (platform_company_by_code($to)) {
                fwrite(STDERR, "The code $to is already used.\n");
                exit(1);
            }
            $st = mysqli_prepare(platform_conn(), 'UPDATE companies SET company_code = ? WHERE id = ?');
            $cid = (int) $co['id'];
            mysqli_stmt_bind_param($st, 'si', $to, $cid);
            mysqli_stmt_execute($st);
            platform_audit($cid, mm_cli_actor(), 'company_code_changed', $co['company_code'] . ' -> ' . $to);
            echo "The code of business {$co['name']} is now $to (it was {$co['company_code']}). People must type the new code to sign in.\n";
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
