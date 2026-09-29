#!/usr/bin/env bash
# What must be true of the package before it goes anywhere.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
fail=0
note() { echo "FAIL: $1"; fail=1; }

grep -rn "curl_" "$root/includes" "$root/p2flux-agent-paywall.php" "$root/uninstall.php" | grep -q . && note "shipped code calls curl directly"
grep -rln "p2flux_ap_fake" "$root/includes" "$root/p2flux-agent-paywall.php" | grep -q . && note "shipped code references the fake API"

lint_php="php"; command -v php8.1 >/dev/null 2>&1 && lint_php="php8.1"
while IFS= read -r f; do "$lint_php" -l "$f" >/dev/null || note "syntax error in $f ($lint_php)"; done \
  < <(find "$root/includes" "$root/p2flux-agent-paywall.php" "$root/uninstall.php" -name '*.php')

v_header="$(grep -m1 '^ \* Version:' "$root/p2flux-agent-paywall.php" | awk '{print $3}')"
v_const="$(grep -m1 "P2FLUX_AP_VERSION'" "$root/p2flux-agent-paywall.php" | sed -E "s/.*'([0-9.]+)'.*/\1/")"
v_readme="$(grep -m1 '^Stable tag:' "$root/readme.txt" | awk '{print $3}')"
[ "$v_header" = "$v_const" ] && [ "$v_header" = "$v_readme" ] || note "versions differ: header $v_header, constant $v_const, readme $v_readme"

php "$root/tests/unit.php" >/dev/null || note "unit tests fail"
[ "$fail" = 0 ] && echo "release check: ok ($v_header)"
exit "$fail"
