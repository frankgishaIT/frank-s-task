<?php
/**
 * NEW FILE: includes/customer_name_helpers.php
 * Task 2A: customers have a First Name and a Last Name. The full name (customers.name) is kept and always
 * equals "First Last", so every page, invoice, report and search that shows the full name keeps working.
 * SMS greet customers by first name only (see sms_first_name_sql()).
 */

/** Adds first_name / last_name if missing and splits existing customers once (first word / the rest). */
function customer_names_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $has = function (string $c) use ($conn): bool {
        $s = mysqli_prepare($conn, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = ?");
        mysqli_stmt_bind_param($s, 's', $c);
        mysqli_stmt_execute($s);
        return (int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0] > 0;
    };
    if (!$has('first_name')) { mysqli_query($conn, 'ALTER TABLE customers ADD COLUMN first_name VARCHAR(80) NULL'); }
    if (!$has('last_name')) { mysqli_query($conn, 'ALTER TABLE customers ADD COLUMN last_name VARCHAR(80) NULL'); }
    // Existing customers (saved with one name field): first word = First Name, the rest = Last Name.
    mysqli_query($conn, "UPDATE customers
        SET first_name = SUBSTRING_INDEX(TRIM(name), ' ', 1),
            last_name = NULLIF(TRIM(SUBSTRING(TRIM(name), CHAR_LENGTH(SUBSTRING_INDEX(TRIM(name), ' ', 1)) + 1)), '')
        WHERE first_name IS NULL AND name IS NOT NULL");
}

/** The full name stored in customers.name. */
function customer_full_name(string $first, string $last): string
{
    return trim(preg_replace('/\s+/', ' ', trim($first) . ' ' . trim($last)));
}