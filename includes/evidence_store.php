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
        mime VARCHAR(150) NOT NULL,
        ext VARCHAR(10) NOT NULL,
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
    // FIX: the file type column was 50 characters, too short for Word / Excel / PowerPoint types
    // (e.g. "application/vnd.openxmlformats-officedocument.wordprocessingml.document" = 71). Widened once,
    // also on databases where the table already exists.
    $c = mysqli_query($conn, "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'evidence_files' AND column_name = 'mime'");
    $len = $c ? (int) (mysqli_fetch_row($c)[0] ?? 0) : 0;
    if ($len > 0 && $len < 150) {
        mysqli_query($conn, 'ALTER TABLE evidence_files MODIFY mime VARCHAR(150) NOT NULL, MODIFY ext VARCHAR(10) NOT NULL');
    }
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
function evidence_save(mysqli $conn, ?array $file, ?int $userId = null, ?array $types = null, int $maxBytes = EVIDENCE_MAX_BYTES): ?string
{
    // NEW: a caller may allow other document types ($types: mime => extension), e.g. Messages.
    $types = $types ?? EVIDENCE_TYPES;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new RuntimeException('The evidence file is larger than this server accepts. Please use a smaller file (maximum 5 MB).');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('The evidence file could not be uploaded. Please try again.');
    }
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('The file is too large (maximum ' . round($maxBytes / 1048576) . ' MB).');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    // NEW: Word, Excel, PowerPoint and Publisher files are often detected only as a generic container (ZIP for .docx/.xlsx/
    // .pptx, "CDFV2"/OLE for .doc/.xls/.ppt/.pub). Accept them by their extension when that extension is allowed here.
    if (!isset($types[$mime]) && in_array($mime, ['application/zip', 'application/octet-stream', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/encrypted'], true)) {
        $byExt = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $canon = array_search($byExt, $types, true);
        if ($canon !== false) { $mime = $canon; }
    }
    if (!isset($types[$mime])) {
        throw new RuntimeException($types === EVIDENCE_TYPES ? 'Evidence must be a PDF or a photo (JPG, PNG).'
            : 'This type of file is not allowed (' . $mime . ').');
    }
    $content = file_get_contents($file['tmp_name']);
    if ($content === false) {
        throw new RuntimeException('The evidence file could not be read. Please try again.');
    }

    $ext = $types[$mime];
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
function evidence_output(mysqli $conn, ?string $ref, string $downloadName, ?string $legacyDir = null, bool $forceDownload = false): void
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
            // NEW: discard anything already queued for output (e.g. the line break after the closing PHP tag of config/db.php): a single
            // extra byte corrupts Word / Excel / PowerPoint files (they are ZIP files). Also no output compression, so the
            // declared length is exact.
            while (ob_get_level()) { ob_end_clean(); }
            if (function_exists('ini_set')) { @ini_set('zlib.output_compression', 'Off'); }
            header_remove('Content-Encoding');
            if (strlen((string) $f['content']) === 0) {
                http_response_code(410);
                echo '<div style="font-family:sans-serif;margin:20px;padding:14px;border:1px solid #E24B4A;background:#FDECEC;"><strong>This file is empty or incomplete.</strong> Please upload it again.</div>';
                exit;
            }
            $known = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'txt' => 'text/plain', 'csv' => 'text/csv',
                'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'pub' => 'application/x-mspublisher'];
            $f['mime'] = $known[$f['ext']] ?? $f['mime'];
            header('Cache-Control: private, no-transform');
            header('Content-Type: ' . $f['mime']);
            header('Content-Length: ' . strlen($f['content']));
            $inline = !$forceDownload && in_array($f['ext'], ['pdf', 'jpg', 'png', 'webp', 'txt'], true);
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $downloadName . '.' . $f['ext'] . '"');
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
    echo '<div style="font-family:sans-serif;margin:20px;padding:14px;border:1px solid #1E2FE0;background:#EEF3FF;">'
        . '<strong>Evidence not found.</strong><br>' . htmlspecialchars($why, ENT_QUOTES, 'UTF-8') . '</div>';
    exit;
}