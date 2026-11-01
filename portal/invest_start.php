<?php
// portal/invest_start.php — shared start of the RM Invest pages.
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/inv_helpers.php';
inv_ensure($conn);
if (inv_setting($conn, 'enabled') !== '1') { portal_header('RM Invest', $me); echo '<div class="alert alert-info">RM Invest is not available at the moment.</div>'; portal_footer(); exit; }
function inv_disclaimer(): string { return '<div class="small text-muted"><i class="bi bi-info-circle"></i> Projected figures are <strong>estimates, not guaranteed earnings</strong>. Actual profit may be lower, and the project may make a loss.</div>'; }
function inv_photo(mysqli $conn, int $pid): ?int { $r = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM inv_project_files WHERE project_id = $pid AND kind = 'photo' ORDER BY id LIMIT 1")); return $r ? (int) $r[0] : null; }