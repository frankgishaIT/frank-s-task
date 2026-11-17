<?php
// NEW FILE: learn/sitemap.php — search engines: the home page, the catalogue and every PUBLISHED course page.
require __DIR__ . '/lc_public.php';
header('Content-Type: application/xml; charset=UTF-8');
$base = lc_public_base($conn);
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
echo '<url><loc>' . lc_e($base . '/') . '</loc></url><url><loc>' . lc_e($base . '/courses.php') . '</loc></url>';
foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT code, COALESCE(updated_at, published_at, created_at) m FROM lc_courses WHERE status IN ('" . implode("','", LC_PUBLIC_VISIBLE) . "')"), MYSQLI_ASSOC) as $c) {
    echo '<url><loc>' . lc_e(lc_course_url($conn, $c)) . '</loc><lastmod>' . lc_e(substr((string) $c['m'], 0, 10)) . '</lastmod></url>';
}
echo '</urlset>';