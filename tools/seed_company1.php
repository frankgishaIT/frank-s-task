<?php
/**
 * Gives business 1 (RISE MOTIVE) its profile: the name, TIN, website, address, payment methods and logo that used to be
 * written into the documents. Run once at go-live, as root. It only fills a profile that is still empty (use --force to overwrite).
 *
 *   php tools/seed_company1.php [--name="RISE MOTIVE"] [--tin=122923513] [--website=www.risemotive.rw] [--address="Kicukiro District, Kigali, Rwanda"]
 *                               [--phone=0795344768] [--help-phone=0795344768] [--momo-phone=...] [--prefix=RM] [--logo=assets/images/rise.jpg] [--force]
 *
 * SMS options start OFF for business 1, so nothing is sent to its customers until you switch them on (after the preview).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/provisioning.php';
require_once __DIR__ . '/../includes/company_profile.php';

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? '1';
    }
}
$company = platform_company_by_id(1);
if (!$company) {
    fwrite(STDERR, "Business 1 is not registered yet.\n");
    exit(1);
}
$conn = tenant_try_connect($company);
$existing = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM company_profile WHERE id = 1')) ?: null;
if ($existing && !empty($existing['display_name']) && empty($opt['force'])) {
    echo "Business 1 already has a profile. Nothing changed (use --force to overwrite).\n";
    exit(0);
}
$v = static fn(string $k, string $default): string => trim($opt[$k] ?? $default);
$st = mysqli_prepare($conn, 'INSERT INTO company_profile (id, display_name, tin, website, address, phone, help_phone, momo_phone, invoice_prefix, sms_credit_reminders, sms_loyalty_weekly, auto_credit_charges)
    VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0)
    ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), tin = VALUES(tin), website = VALUES(website), address = VALUES(address), phone = VALUES(phone), help_phone = VALUES(help_phone), momo_phone = VALUES(momo_phone), invoice_prefix = VALUES(invoice_prefix)');
$name = $v('name', 'RISE MOTIVE');
$tin = $v('tin', '122923513');
$web = $v('website', 'www.risemotive.rw');
$addr = $v('address', 'Kicukiro District, Kigali, Rwanda');
$phone = $v('phone', '0795344768');
$help = $v('help-phone', '0795344768');
$momo = $v('momo-phone', '');
$prefix = strtoupper($v('prefix', 'RM'));
mysqli_stmt_bind_param($st, 'ssssssss', $name, $tin, $web, $addr, $phone, $help, $momo, $prefix);
mysqli_stmt_execute($st);

// the payment methods that used to be written into the invoice
if (!empty($opt['force']) || (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM company_payment_methods'))[0] === 0) {
    mysqli_query($conn, 'DELETE FROM company_payment_methods');
    $ins = mysqli_prepare($conn, 'INSERT INTO company_payment_methods (method_name, account_label, account_number, account_name, sort_order) VALUES (?, ?, ?, ?, ?)');
    foreach ([['Bank Transfer - EQUITY BANK', 'Account No', '4020201146022', 'RISE MOTIVE Ltd', 0], ['MTN-MoMo Code', 'MoMo Code', '070600', 'RISE MOTIVE Ltd', 1]] as [$a, $b, $c, $d, $e]) {
        mysqli_stmt_bind_param($ins, 'ssssi', $a, $b, $c, $d, $e);
        mysqli_stmt_execute($ins);
    }
}

// the logo: the picture that used to be written into the documents, saved as this business's own logo
$logoSrc = $opt['logo'] ?? 'assets/images/rise.jpg';
$logoSrc = $logoSrc[0] === '/' ? $logoSrc : dirname(__DIR__) . '/' . $logoSrc;
$logoNote = 'no logo file found at ' . $logoSrc . ' (upload one on the Business profile page)';
if (is_file($logoSrc) && function_exists('imagecreatefromstring')) {
    $img = @imagecreatefromstring((string) file_get_contents($logoSrc));
    if ($img) {
        $w = imagesx($img);
        $h = imagesy($img);
        $s = min(1, 500 / max($w, $h));
        $nw = max(1, (int) round($w * $s));
        $nh = max(1, (int) round($h * $s));
        $out = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $dir = COMPANY_LOGO_DIR . '/1';
        @mkdir($dir, 0775, true);
        $file = 'logo-' . bin2hex(random_bytes(4)) . '.png';
        if (imagepng($out, $dir . '/' . $file, 6)) {
            $u = mysqli_prepare($conn, 'UPDATE company_profile SET logo_file = ? WHERE id = 1');
            mysqli_stmt_bind_param($u, 's', $file);
            mysqli_stmt_execute($u);
            $logoNote = "saved as $file";
        }
    }
}
platform_audit(1, mm_cli_actor(), 'company1_profile_seeded', $name);
echo "Business 1 profile saved: $name, TIN $tin, invoice prefix $prefix, 2 payment methods, logo $logoNote.\n";
echo "Its SMS options are OFF. Preview first:  php tools/daily.php --dry-run --code={$company['company_code']}\n";
