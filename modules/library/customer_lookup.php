<?php
/** NEW FILE: modules/library/customer_lookup.php — finds customers by name, phone or Customer ID for "Selected customers" (JSON, max 15). */
require '../../config/db.php';
require_once '../../includes/library_helpers.php';
require_once '../../includes/lib_access.php';
lib_guard($conn, 'library.publish');
header('Content-Type: application/json; charset=utf-8');
$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo '[]'; exit; }
$like = '%' . $q . '%'; $id = ctype_digit($q) ? (int) $q : 0;
$s = mysqli_prepare($conn, 'SELECT id, name, phone FROM customers WHERE id = ? OR name LIKE ? OR phone LIKE ? ORDER BY (id = ?) DESC, name LIMIT 15');
mysqli_stmt_bind_param($s, 'issi', $id, $like, $like, $id);
mysqli_stmt_execute($s);
$out = [];
foreach (mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC) as $r) {
    $st = false; try { $st = (bool) mysqli_fetch_row(mysqli_query($conn, 'SELECT 1 FROM lc_enrollments WHERE customer_id = ' . (int) $r['id'] . ' LIMIT 1')); } catch (Throwable $e) { }
    $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'phone' => (string) $r['phone'], 'student' => $st];
}
echo json_encode($out);