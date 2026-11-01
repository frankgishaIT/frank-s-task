<?php
/**
 * NEW FILE: includes/rm_org.php
 * Section 6 (organization isolation): RISE MOTIVE-specific developments apply to RISE MOTIVE only.
 *
 *   rm_is_rise_motive()        true when the signed-in organization is RISE MOTIVE. On a single-organization
 *                              install (RISE MOTIVE's own system) that is always the case; on the multi-company
 *                              platform it is business 1 (RISE MOTIVE), never another company.
 *   rm_feature_enabled($key)   a RISE MOTIVE module (digital_library, customer_portal, forecasting, kpi) is
 *                              available to RISE MOTIVE, and to another company ONLY when the platform
 *                              administrator has deliberately added that feature to its package.
 */
function rm_is_rise_motive(): bool
{
    static $is = null;
    if ($is !== null) { return $is; }
    if (function_exists('company_profile')) {          // multi-company platform
        try {
            $p = company_profile();
            foreach (['id', 'company_id'] as $k) { if (isset($p[$k])) { return $is = ((int) $p[$k] === 1); } }
            return $is = (stripos((string) ($p['name'] ?? ''), 'RISE MOTIVE') !== false);
        } catch (Throwable $e) {
            return $is = false;                        // unsure: never apply RISE MOTIVE changes to someone else
        }
    }
    foreach (['MM_COMPANY_ID', 'COMPANY_ID'] as $g) { if (isset($GLOBALS[$g])) { return $is = ((int) $GLOBALS[$g] === 1); } }
    return $is = true;                                 // single-organization install: this is RISE MOTIVE's system
}

function rm_feature_enabled(string $key): bool
{
    if (rm_is_rise_motive()) { return true; }
    return function_exists('mm_has_feature') && mm_has_feature($key);
}