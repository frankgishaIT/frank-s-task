#!/usr/bin/env bash
# Checks that the parts of the staff portal that we added are all still there.
# It exists because a change to includes/sidebar.php, made from an OLD copy of the file, silently removed the
# "Digital Payments" menu link twice. Run it any time:   bash tools/check_required.sh
# It prints what is missing and exits with 1 (GitHub then shows a red cross and emails you). Exit 0 means all is well.
# Windows line endings (CRLF) would break this script. If it has them, it runs a clean copy of itself (this must stay ONE line).
[ -z "$CHECK_REQUIRED_CLEAN" ] && grep -q $'\r' "$0" 2>/dev/null && CHECK_REQUIRED_CLEAN=1 CHECK_REQUIRED_DIR="$(cd "$(dirname "$0")/.." && pwd)" exec bash <(tr -d '\r' < "$0") "$@"

cd "${CHECK_REQUIRED_DIR:-$(dirname "$0")/..}" || exit 2
fail=0
need_file() { [ -f "$1" ] || { echo "MISSING FILE: $1"; fail=1; }; }
need_text() { grep -qF -- "$2" "$1" 2>/dev/null || { echo "LOST: $1 no longer contains: $2"; fail=1; }; }

# ---- the Digital Payment (MTN MoMo) feature ----
for f in modules/sales/digital_payment.php modules/sales/digital_payments.php modules/sales/digital_payment_callback.php \
         includes/xentripay.php includes/digital_payment_helpers.php includes/credit_payment_helpers.php includes/digital_payment_ui.php \
         tools/dp_install.php tools/dp_reconcile.php; do need_file "$f"; done

need_text includes/sidebar.php            "../sales/digital_payments.php"      # the menu link (the one that was lost twice)
need_text modules/sales/create.php        "digital_payment_helpers.php"        # a new sale can use Digital Payment
need_text modules/sales/pay_credit.php    "credit_payment_helpers.php"         # a credit payment goes through the shared code
need_text modules/sales/pay_credit.php    "digital_payment_ui.php"             # ...and can offer Digital Payment
need_text includes/sales_report_queries.php "'Digital Payment'"                # reports can filter by it

# ---- EVERY PHP file of the project must be valid PHP (one typo in any page gives visitors a 500 error) ----
if command -v php >/dev/null 2>&1; then
  checked=0
  while IFS= read -r -d '' f; do
    checked=$((checked + 1))
    if ! out=$(php -l "$f" 2>&1); then
      echo "BROKEN PHP: $f"; echo "    $(printf '%s' "$out" | head -1 | cut -c1-200)"; fail=1
    fi
  done < <(find . -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' -not -path './.git/*' -print0)
  echo "(checked $checked PHP files for typos)"
fi

# ---- the same key files, once more, by name (so a missing file is reported even if find found nothing) ----
if command -v php >/dev/null 2>&1; then
  for f in includes/sidebar.php modules/sales/create.php modules/sales/pay_credit.php includes/sales_report_queries.php \
           modules/sales/digital_payment.php modules/sales/digital_payments.php includes/digital_payment_helpers.php; do
    [ -f "$f" ] && ! php -l "$f" >/dev/null 2>&1 && { echo "BROKEN PHP: $f"; fail=1; }
  done
fi

if [ "$fail" -eq 0 ]; then echo "OK: everything we added is still there."; else echo; echo "Something we added was lost or broken. Compare with the previous commit:  git log -p -3 -- <the file named above>"; fi
exit "$fail"
