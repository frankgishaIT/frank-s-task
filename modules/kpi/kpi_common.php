<?php
// modules/kpi/kpi_common.php — shared by the Performance & KPI pages.
require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/kpi_helpers.php';
kpi_ensure($conn);
if (!rm_feature_enabled('kpi')) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
$me = (int) current_user_id();
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
function kpi_tabs(string $active): void {
    $tabs = [];
    if (access_can('kpi.view')) { $tabs['index.php'] = 'Dashboard'; $tabs['kpis.php'] = 'KPIs'; }
    $tabs['reviews.php'] = 'Reviews, plans & recognition';
    if (access_can('kpi.create')) { $tabs['teams.php'] = 'Teams'; }
    if (access_can('kpi.manage')) { $tabs['settings.php'] = 'Rating levels'; }
    $tabs['my.php'] = 'My performance';
    echo '<h2 class="mb-2">Performance &amp; KPIs</h2><ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">';
    foreach ($tabs as $f => $l) { echo '<li class="nav-item"><a class="nav-link text-nowrap' . ($active === $f ? ' active' : '') . '" href="' . $f . '">' . $l . '</a></li>'; }
    echo '</ul>';
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) { if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $c . '">' . htmlspecialchars($_GET[$k], ENT_QUOTES, 'UTF-8') . '</div>'; } }
}
/** A coloured achievement bar. */
function kpi_bar(?float $a): string {
    if ($a === null) { return '<span class="text-muted small">no data yet</span>'; }
    $c = $a >= 100 ? '#0FA968' : ($a >= 70 ? '#1E2FE0' : '#E24B4A');
    return '<div class="d-flex align-items-center gap-2"><div style="flex:1; min-width:70px; height:8px; background:#EEF1F7; border-radius:4px;"><div style="width:' . min(100, $a / 1.2) . '%; height:8px; background:' . $c . '; border-radius:4px;"></div></div><span class="small fw-semibold" style="color:' . $c . ';">' . $a . '%</span></div>';
}