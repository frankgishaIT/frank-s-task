<?php
// Shared profile-photo helpers (used by modules/users/edit.php and profile.php).

// Checks an uploaded profile photo WITHOUT saving it. Returns:
//   ['none' => true]                 no file was chosen
//   ['error' => '...']               file rejected
//   ['tmp' => ..., 'ext' => ...]     file is valid and ready to save
// The type is detected from the file's real contents, not its name, so a
// renamed non-image file cannot get through.
function validate_user_photo($file) {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['none' => true];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'The photo could not be uploaded. Please try again.'];
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return ['error' => 'The photo is too large. Maximum size is 2 MB.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
        return ['error' => 'The photo must be a JPG, PNG or WEBP image.'];
    }
    return ['tmp' => $file['tmp_name'], 'ext' => $allowed[$mime]];
}

// Saves (or removes) the photo and updates users.photo. The old file is
// deleted so the uploads folder doesn't fill up with unused pictures.
function save_user_photo($conn, $userId, $check, $removePhoto, $oldPhoto) {
    $dir = __DIR__ . '/../assets/uploads/users/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    if (!empty($check['tmp'])) {
        $newName = 'u' . (int) $userId . '_' . bin2hex(random_bytes(8)) . '.' . $check['ext'];
        if (!move_uploaded_file($check['tmp'], $dir . $newName)) {
            return false;
        }
        $value = $newName;
    } elseif ($removePhoto) {
        $value = null;
    } else {
        return true; // nothing to change
    }

    $statement = mysqli_prepare($conn, 'UPDATE users SET photo = ? WHERE id = ?');
    mysqli_stmt_bind_param($statement, 'si', $value, $userId);
    mysqli_stmt_execute($statement);

    if ($oldPhoto) {
        $oldPath = $dir . basename($oldPhoto);
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }
    return true;
}