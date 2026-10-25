<?php
/** A platform admin's profile picture: saved as a small PNG in assets/uploads/admins/<id>/. */
if (!defined('ADMIN_AVATAR_DIR')) {
    define('ADMIN_AVATAR_DIR', dirname(__DIR__) . '/assets/uploads/admins');
}

/** The web address of the admin's picture, or null when there is none. */
function admin_avatar_url(array $admin): ?string
{
    if (empty($admin['avatar_file'])) {
        return null;
    }
    return BASE_URL . '/assets/uploads/admins/' . (int) $admin['id'] . '/' . rawurlencode($admin['avatar_file']);
}

/** Saves an uploaded picture and returns its file name. Throws RuntimeException with a message the admin can read. */
function admin_save_avatar(int $adminId, array $file): string
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('That picture is too large to upload. Please use one under 2 MB.');
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('Please choose a picture to upload.');
    }
    if ((int) $file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('That picture is too large to upload. Please use one under 2 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !function_exists('imagecreatefromstring')) {
        throw new RuntimeException('The picture must be a JPEG, PNG or WebP image.');
    }
    $img = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
    if (!$img) {
        throw new RuntimeException('The picture must be a JPEG, PNG or WebP image.');
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $side = min($w, $h);                       // a centred square, then 320 px
    $out = imagecreatetruecolor(320, 320);
    imagecopyresampled($out, $img, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), 320, 320, $side, $side);
    $dir = ADMIN_AVATAR_DIR . '/' . $adminId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new RuntimeException('The picture folder cannot be created on this server.');
    }
    foreach (glob($dir . '/avatar-*.png') ?: [] as $old) {
        @unlink($old);
    }
    $name = 'avatar-' . bin2hex(random_bytes(4)) . '.png';
    if (!imagepng($out, $dir . '/' . $name, 6)) {
        throw new RuntimeException('The picture could not be saved.');
    }
    return $name;
}

function admin_remove_avatar(int $adminId): void
{
    foreach (glob(ADMIN_AVATAR_DIR . '/' . $adminId . '/avatar-*.png') ?: [] as $old) {
        @unlink($old);
    }
}
