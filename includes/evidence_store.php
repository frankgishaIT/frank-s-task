<?php
/**
 * NEW FILE: includes/evidence_store.php
 * Stores uploaded evidence documents (PDF, JPG, PNG, max 5 MB) IN THE DATABASE, so no folder on the
 * server has to be writable (on the live server PHP may not write into the project folders).
 * A stored document is referenced as "db:<id>". Older references (a plain file name) are still
 * served from their folder, so nothing uploaded before is lost.
 */

const EVIDENCE_MAX_BYTES = 5 * 1024 * 1024;
const EVIDENCE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];

function evidence_ensure_table(mysqli $conn): void
{
    static $ok = false;
    if ($ok) { return; }
    // (If this fails, e.g. the database user may not create tables, the caller shows the real reason.)
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS evidence_files (
        id INT(11) NOT NULL AUTO_INCREMENT,
        mime VARCHAR(50) NOT NULL,
        ext VARCHAR(5) NOT NULL,
        size_bytes INT(11) NOT NULL,
        content LONGBLOB NOT NULL,
        uploaded_by INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // NEW: the document itself, in small pieces (one row each), so it never exceeds the database
    // server's message size limit (max_allowed_packet), however small that limit is.
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS evidence_file_chunks (
        file_id INT(11) NOT NULL,
        seq INT(11) NOT NULL,
        data MEDIUMBLOB NOT NULL,
        PRIMARY KEY (file_id, seq)
    ) ENGINE=InnoDB');
    $ok = true;
}

/** NEW: a piece size safely below this server's message limit (between 16 KB and 256 KB). */
function evidence_chunk_size(mysqli $conn): int
{
    $limit = 1048576;
    try {
        $limit = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT @@max_allowed_packet'))[0];
    } catch (Throwable $e) {
        // keep the default
    }
    return max(16384, min(262144, intdiv($limit, 4)));
}

/**
 * Saves an uploaded file ($_FILES[...] entry) and returns its reference "db:<id>",
 * null when nothing was uploaded, or throws RuntimeException with a clear reason.
 */
function evidence_save(mysqli $conn, ?array $file, ?int $userId = null): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new RuntimeException('The evidence file is larger than this server accepts. Please use a smaller file (maximum 5 MB).');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('The evidence file could not be uploaded. Please try again.');
    }
    if ($file['size'] > EVIDENCE_MAX_BYTES) {
        throw new RuntimeException('The evidence file is too large (maximum 5 MB).');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(EVIDENCE_TYPES[$mime])) {
        throw new RuntimeException('Evidence must be a PDF or a photo (JPG, PNG).');
    }
    $content = file_get_contents($file['tmp_name']);
    if ($content === false) {
        throw new RuntimeException('The evidence file could not be read. Please try again.');
    }

    $ext = EVIDENCE_TYPES[$mime];
    $size = strlen($content);
    try {
        evidence_ensure_table($conn);
        // CHANGED: the document is saved in small pieces, each well below the database server's
        // message limit (on the live server that limit is small: a whole file at once was refused
        // with "MySQL server has gone away").
        $empty = '';
        $s = mysqli_prepare($conn, 'INSERT INTO evidence_files (mime, ext, size_bytes, content, uploaded_by) VALUES (?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'ssisi', $mime, $ext, $size, $empty, $userId);
        mysqli_stmt_execute($s);
        $fileId = (int) mysqli_insert_id($conn);
        $c = mysqli_prepare($conn, 'INSERT INTO evidence_file_chunks (file_id, seq, data) VALUES (?, ?, ?)');
        $seq = 0;
        foreach (str_split($content, evidence_chunk_size($conn)) as $piece) {
            mysqli_stmt_bind_param($c, 'iis', $fileId, $seq, $piece);
            mysqli_stmt_execute($c);
            $seq++;
        }
        return 'db:' . $fileId;
    } catch (mysqli_sql_exception $e) {
        error_log('evidence_save failed: ' . $e->getMessage());
        // CHANGED: the real reason is shown (the pages that upload are for Admins / Managers).
        $hint = stripos($e->getMessage(), 'max_allowed_packet') !== false || stripos($e->getMessage(), 'gone away') !== false
            ? ' The database server accepts smaller files only: please use a smaller file.'
            : '';
        throw new RuntimeException('The evidence file could not be saved. Technical detail: ' . $e->getMessage() . '.' . $hint);
    }
}

/**
 * Sends a stored document to the browser (inline) and ends the request.
 * $legacyDir: folder of older references that are plain file names.
 */
function evidence_output(mysqli $conn, ?string $ref, string $downloadName, ?string $legacyDir = null): void
{
    $downloadName = preg_replace('/[^A-Za-z0-9_-]/', '', $downloadName) ?: 'evidence';
    if ($ref && strpos($ref, 'db:') === 0) {
        evidence_ensure_table($conn);
        $id = (int) substr($ref, 3);
        $s = mysqli_prepare($conn, 'SELECT mime, ext, content FROM evidence_files WHERE id = ?');
        mysqli_stmt_bind_param($s, 'i', $id);
        mysqli_stmt_execute($s);
        $f = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if ($f) {
            // NEW: documents saved in pieces are joined back together, in order.
            $c = mysqli_prepare($conn, 'SELECT data FROM evidence_file_chunks WHERE file_id = ? ORDER BY seq');
            mysqli_stmt_bind_param($c, 'i', $id);
            mysqli_stmt_execute($c);
            $pieces = mysqli_fetch_all(mysqli_stmt_get_result($c), MYSQLI_NUM);
            if ($pieces) { $f['content'] = implode('', array_column($pieces, 0)); }
            header('Content-Type: ' . $f['mime']);
            header('Content-Length: ' . strlen($f['content']));
            header('Content-Disposition: inline; filename="' . $downloadName . '.' . $f['ext'] . '"');
            header('X-Content-Type-Options: nosniff');
            echo $f['content'];
            exit;
        }
    } elseif ($ref && $legacyDir) {
        $path = $legacyDir . '/' . basename($ref);
        if (is_file($path)) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            header('Content-Type: ' . (array_search($ext, EVIDENCE_TYPES, true) ?: 'application/octet-stream'));
            header('Content-Length: ' . filesize($path));
            header('Content-Disposition: inline; filename="' . $downloadName . '.' . $ext . '"');
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;
        }
    }
    // CHANGED: says exactly WHY nothing can be shown (the viewers are Admin / Manager pages).
    http_response_code(404);
    if (!$ref) {
        $why = 'No document was uploaded with this record.';
    } elseif (strpos($ref, 'db:') === 0) {
        $why = 'The document (' . $ref . ') is not in the database. It was probably lost when an earlier upload failed; '
            . 'please upload it again with a new record.';
    } else {
        $why = 'The document file "' . basename($ref) . '" is not in the folder ' . ($legacyDir ? basename(dirname($legacyDir)) . '/' . basename($legacyDir) : '(unknown)')
            . ' on this server. It was saved by an older version of MY MOTIVE (possibly on another computer).';
    }
    echo '<div style="font-family:sans-serif;margin:20px;padding:14px;border:1px solid #E68A1C;background:#FDF1DF;">'
        . '<strong>Evidence not found.</strong><br>' . htmlspecialchars($why, ENT_QUOTES, 'UTF-8') . '</div>';
    exit;
}