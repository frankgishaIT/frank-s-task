<?php
/**
 * Shared helpers for every report in the system (Sales, Stock, Payroll, ...).
 * Include with: require '../../includes/report_helpers.php';
 */

/**
 * Presets offered in every report's period dropdown.
 * Pass true for Customer reports, which also allow "All History".
 */
function report_period_options(bool $allowAllHistory = false): array
{
    $options = [
        'today'      => 'Today',
        'this_week'  => 'This Week',
        'this_month' => 'This Month',
        'this_year'  => 'This Year',
        'custom'     => 'Custom Date Range',
    ];
    if ($allowAllHistory) {
        $options['all_history'] = 'All History';
    }
    return $options;
}

/**
 * Turns a preset (and optional custom dates) into a concrete date range.
 * Weeks run Monday to Sunday.
 *
 * Returns: [
 *   'start' => 'Y-m-d' | null,   // null only for all_history
 *   'end'   => 'Y-m-d' | null,
 *   'label' => 'This Month (01 Oct 2026 - 31 Oct 2026)',
 *   'preset'=> string,
 *   'error' => string | null,
 * ]
 */
function report_resolve_period(string $preset, ?string $from = null, ?string $to = null, bool $allowAllHistory = false): array
{
    $today = new DateTimeImmutable('today');
    $fmt   = 'd M Y';
    $names = report_period_options($allowAllHistory);

    if (!isset($names[$preset])) {
        $preset = 'this_month';
    }

    switch ($preset) {
        case 'today':
            $start = $end = $today;
            break;
        case 'this_week':
            $start = $today->modify('monday this week');
            $end   = $start->modify('+6 days');
            break;
        case 'this_year':
            $start = new DateTimeImmutable($today->format('Y') . '-01-01');
            $end   = new DateTimeImmutable($today->format('Y') . '-12-31');
            break;
        case 'custom':
            $s = $from ? DateTimeImmutable::createFromFormat('Y-m-d', $from) : false;
            $e = $to   ? DateTimeImmutable::createFromFormat('Y-m-d', $to)   : false;
            if (!$s || $s->format('Y-m-d') !== $from || !$e || $e->format('Y-m-d') !== $to) {
                return ['start' => null, 'end' => null, 'label' => '', 'preset' => $preset,
                        'error' => 'Please choose a valid start and end date.'];
            }
            if ($s > $e) {
                return ['start' => null, 'end' => null, 'label' => '', 'preset' => $preset,
                        'error' => 'The start date cannot be after the end date.'];
            }
            $start = $s;
            $end   = $e;
            break;
        case 'all_history':
            return ['start' => null, 'end' => null, 'label' => 'All History', 'preset' => $preset, 'error' => null];
        case 'this_month':
        default:
            $start = new DateTimeImmutable($today->format('Y-m-01'));
            $end   = new DateTimeImmutable($today->format('Y-m-t'));
            break;
    }

    $range = $start->format($fmt) . ($start == $end ? '' : ' - ' . $end->format($fmt));
    return [
        'start'  => $start->format('Y-m-d'),
        'end'    => $end->format('Y-m-d'),
        'label'  => $names[$preset] . ' (' . $range . ')',
        'preset' => $preset,
        'error'  => null,
    ];
}

/** Money formatting used on screen and in PDFs. */
function report_money($amount): string
{
    return 'RWF ' . number_format((float) $amount, 2);
}

/** Escape for HTML output. */
function report_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Reads period / from / to out of $_GET (or any array) and resolves them. */
function report_period_from_request(array $src, bool $allowAllHistory = false): array
{
    return report_resolve_period(
        (string) ($src['period'] ?? 'this_month'),
        isset($src['from']) ? (string) $src['from'] : null,
        isset($src['to']) ? (string) $src['to'] : null,
        $allowAllHistory
    );
}

/** Formats one table cell for screen and PDF. $type: text|money|int|date|datetime */
function report_format_cell($value, string $type = 'text'): string
{
    if ($value === null || $value === '') {
        return '';
    }
    switch ($type) {
        case 'money':
            return number_format((float) $value, 2);
        case 'int':
            return number_format((int) $value);
        case 'date':
            $t = strtotime((string) $value);
            return $t ? date('d M Y', $t) : (string) $value;
        case 'datetime':
            $t = strtotime((string) $value);
            return $t ? date('d M Y H:i', $t) : (string) $value;
        default:
            return (string) $value;
    }
}
