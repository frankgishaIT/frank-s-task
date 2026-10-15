<?php
/**
 * NEW FILE: includes/admin_error_guard.php
 * Include right after config/db.php on Admin pages. If the page stops with an error (even a fatal PHP
 * error such as a missing file or function), the Admin sees the exact reason instead of a blank
 * "HTTP ERROR 500", so problems on the live server can be fixed without access to its error log.
 * Nothing about the page changes when there is no error.
 */
function admin_error_guard_box(string $detail): string
{
    return '<div style="margin:20px;padding:14px;border:1px solid #E24B4A;background:#FCE9E9;color:#8A1C1B;font-family:sans-serif;">'
        . '<strong>This page stopped with an error.</strong> Nothing was saved.<br>'
        . 'Technical detail for Admin: ' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8')
        . '<br><br>If it mentions an unknown column or table, open <a href="../tools/migrations.php">Database Migrations</a> and run the missing migration(s). '
        . 'If it mentions a missing file or an undefined function, that file was not updated on this server.</div>';
}

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo admin_error_guard_box($err['message'] . ' in ' . basename($err['file']) . ' line ' . $err['line']);
    }
});
set_exception_handler(function (Throwable $e) {
    error_log('page error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo admin_error_guard_box($e->getMessage() . ' in ' . basename($e->getFile()) . ' line ' . $e->getLine());
});
