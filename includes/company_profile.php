<?php
/**
 * The identity of the company you are signed in to: its name, logo, tax number, address, payment methods and
 * invoice prefix. Everything a customer sees on an invoice, receipt, report or SMS comes from here, so no
 * company ever shows another company's name, logo or payment details.
 *
 * Loaded by includes/tenant.php, so every page already has it. A company edits its profile at Company profile.
 */

if (!defined('COMPANY_LOGO_DIR')) {
    define('COMPANY_LOGO_DIR', dirname(__DIR__) . '/assets/uploads/company');
}

/** Forgets the cached profile. The daily job calls it when it moves to the next company. */
function company_profile_reset(): void
{
    unset($GLOBALS['MM_PROFILE_CACHE']);
}

/** The profile as an array (never null). Missing details are empty strings, so templates print only what exists. */
function company_profile(): array
{
    if (isset($GLOBALS['MM_PROFILE_CACHE'])) {
        return $GLOBALS['MM_PROFILE_CACHE'];
    }
    $row = [];
    $methods = [];
    $conn = $GLOBALS['conn'] ?? null;
    if ($conn instanceof mysqli) {
        try {
            $res = mysqli_query($conn, 'SELECT * FROM company_profile WHERE id = 1 LIMIT 1');
            $row = $res ? (mysqli_fetch_assoc($res) ?: []) : [];
            $res = mysqli_query($conn, 'SELECT * FROM company_payment_methods WHERE is_active = 1 ORDER BY sort_order, id');
            while ($res && ($m = mysqli_fetch_assoc($res))) {
                $methods[] = $m;
            }
        } catch (Throwable $e) {
            // the profile tables are made by migration 029: until it has run, the company simply has no profile yet
        }
    }
    $name = trim((string) ($row['display_name'] ?? ''));
    if ($name === '') {
        $name = function_exists('mm_company_name') ? mm_company_name() : 'MY MOTIVE';
    }
    $cid = function_exists('mm_company_id') ? (int) mm_company_id() : 0;
    $logoFile = trim((string) ($row['logo_file'] ?? ''));
    $logoPath = $logoFile !== '' && $cid > 0 ? COMPANY_LOGO_DIR . '/' . $cid . '/' . $logoFile : '';
    $hasLogo = $logoPath !== '' && is_file($logoPath);
    $base = defined('BASE_URL') ? BASE_URL : '';

    $profile = [
        'name'           => $name,
        'tin'            => trim((string) ($row['tin'] ?? '')),
        'website'        => trim((string) ($row['website'] ?? '')),
        'address'        => trim((string) ($row['address'] ?? '')),
        'phone'          => trim((string) ($row['phone'] ?? '')),
        'email'          => trim((string) ($row['email'] ?? '')),
        'momo_phone'     => trim((string) ($row['momo_phone'] ?? '')),
        'help_phone'     => trim((string) ($row['help_phone'] ?? '')),
        'invoice_prefix' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($row['invoice_prefix'] ?? 'INV'))) ?: 'INV',
        'logo_file'      => $logoFile,
        'logo_path'      => $hasLogo ? $logoPath : dirname(__DIR__) . '/assets/images/logo-badge.png',
        'logo_url'       => $hasLogo ? $base . '/assets/uploads/company/' . $cid . '/' . rawurlencode($logoFile) . '?v=' . filemtime($logoPath) : $base . '/assets/images/logo-badge.png',
        'has_logo'       => $hasLogo,
        'methods'        => $methods,
        'sms_credit_reminders' => (int) ($row['sms_credit_reminders'] ?? 1) === 1,
        'sms_loyalty_weekly'   => (int) ($row['sms_loyalty_weekly'] ?? 0) === 1,
        'auto_credit_charges'  => (int) ($row['auto_credit_charges'] ?? 0) === 1,
    ];
    return $GLOBALS['MM_PROFILE_CACHE'] = $profile;
}

function company_name(): string
{
    return company_profile()['name'];
}

/** "RM000954": this company's prefix and the number padded to $pad digits. */
function company_doc_no(int $id, int $pad = 6): string
{
    return company_profile()['invoice_prefix'] . str_pad((string) $id, $pad, '0', STR_PAD_LEFT);
}

/** The lines under the company name on a document: TIN, website, address. Only what the company filled in. */
function company_header_lines_html(array $co): string
{
    $style = 'font-size:10px; font-weight:700; color:#1E2333; margin-top:8px; display:inline-block;';
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $out = '';
    if ($co['tin'] !== '') {
        $out .= '<span style="' . $style . '">TIN Number:' . $e($co['tin']) . '</span><br>';
    }
    if ($co['website'] !== '') {
        $out .= '<span style="' . $style . '">Website: ' . $e($co['website']) . '</span><br>';
    }
    if ($co['address'] !== '') {
        $out .= '<span style="' . $style . '">' . $e($co['address']) . '</span><br>';
    }
    if ($co['phone'] !== '') {
        $out .= '<span style="' . $style . '">Tel: ' . $e($co['phone']) . '</span><br>';
    }
    return $out;
}

/** True if this SMS-sending company has the "overdue reminders" switch on. */
function company_flag(string $name): bool
{
    $p = company_profile();
    return !empty($p[$name]);
}

// ---------------------------------------------------------------- the logo file

/**
 * Saves an uploaded logo for the signed-in company. Accepts JPEG, PNG or WebP up to 2 MB. The image is decoded and
 * re-drawn (at most 500 px wide), so whatever the file contained, only a clean picture is kept.
 *
 * @return string the saved file name
 * @throws RuntimeException with a message the person can read
 */
function company_save_logo(array $upload): string
{
    $cid = function_exists('mm_company_id') ? (int) mm_company_id() : 0;
    if ($cid <= 0) {
        throw new RuntimeException('No company is signed in.');
    }
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        throw new RuntimeException('The logo could not be uploaded. Please try again.');
    }
    if (($upload['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('The logo is larger than 2 MB. Please choose a smaller picture.');
    }
    $raw = (string) file_get_contents($upload['tmp_name']);
    $info = @getimagesizefromstring($raw);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('The logo must be a JPEG, PNG or WebP picture.');
    }
    if ($info[0] < 40 || $info[1] < 40 || $info[0] * $info[1] > 40000000) {
        throw new RuntimeException('The picture is too small or too large to be a logo.');
    }
    $src = @imagecreatefromstring($raw);
    if (!$src) {
        throw new RuntimeException('The picture could not be read.');
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, 500 / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $dir = COMPANY_LOGO_DIR . '/' . $cid;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new RuntimeException('The logo folder could not be created on the server.');
    }
    $file = 'logo-' . bin2hex(random_bytes(4)) . '.png';   // a new name each time, so browsers never show an old logo
    if (!imagepng($dst, $dir . '/' . $file, 6)) {
        throw new RuntimeException('The logo could not be saved.');
    }
    imagedestroy($dst);
    foreach (glob($dir . '/logo-*.png') ?: [] as $old) {   // keep only the newest
        if (basename($old) !== $file) {
            @unlink($old);
        }
    }
    return $file;
}
