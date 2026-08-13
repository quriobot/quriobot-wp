#!/usr/bin/env bash
# Asserts the plugin version is consistent everywhere it is written down.
# Releases break silently when one of these drifts, so CI gates on it.
set -euo pipefail

cd "$(dirname "$0")/.."

header=$(./tests/plugin-version.sh)
main_const=$(grep -m1 "const VERSION" quriobot.php | sed -E "s/.*'([^']+)'.*/\1/")
inc_const=$(grep -m1 "const VERSION" includes/class-quriobot.php | sed -E "s/.*'([^']+)'.*/\1/")
stable_tag=$(grep -m1 '^Stable tag:' readme.txt | sed -E 's/.*Stable tag:[[:space:]]*//')

echo "  quriobot.php header ......... $header"
echo "  WP_Quriobot::VERSION ........ $main_const"
echo "  Quriobot::VERSION ........... $inc_const"
echo "  readme.txt Stable tag ....... $stable_tag"

fail=0
for v in "$main_const" "$inc_const" "$stable_tag"; do
  if [ "$v" != "$header" ]; then fail=1; fi
done

if [ "$fail" -ne 0 ]; then
  echo "FAIL: version mismatch across quriobot.php / includes/class-quriobot.php / readme.txt" >&2
  exit 1
fi

if ! grep -qE "^= ${header//./\\.} =$" readme.txt; then
  echo "FAIL: readme.txt has no '= $header =' changelog entry" >&2
  exit 1
fi

# The support floors are declared twice and must not drift apart.
for field in "Requires at least" "Requires PHP"; do
  in_php=$(grep -m1 " \* ${field}:" quriobot.php | sed -E "s/.*${field}:[[:space:]]*//")
  in_readme=$(grep -m1 "^${field}:" readme.txt | sed -E "s/.*${field}:[[:space:]]*//")
  echo "  ${field} ......... $in_php (readme: $in_readme)"
  if [ -z "$in_php" ] || [ "$in_php" != "$in_readme" ]; then
    echo "FAIL: '${field}' differs between quriobot.php and readme.txt" >&2
    exit 1
  fi
done

# "Tested up to" must not claim a WordPress version that does not exist yet.
# The upcoming release is allowed once it reaches beta/RC, which is exactly when
# the release squad asks plugin authors to test against it and bump this value.
tested=$(grep -m1 '^Tested up to:' readme.txt | sed -E 's/.*Tested up to:[[:space:]]*//')

branch_of() { echo "${1%%-*}" | cut -d. -f1,2; }

wp_stable=$(./tests/wp-current-version.sh)
wp_beta=$(./tests/wp-current-version.sh beta)

allowed=$(printf '%s\n%s\n' "$(branch_of "$wp_stable")" "$(branch_of "$wp_beta")" | sort -V | tail -1)
echo "  readme.txt Tested up to ..... $tested (stable: $wp_stable, beta: $wp_beta, max allowed: $allowed)"

if [ "$(printf '%s\n%s\n' "$tested" "$allowed" | sort -V | tail -1)" != "$allowed" ]; then
  echo "FAIL: 'Tested up to: $tested' is ahead of the newest WordPress branch ($allowed)" >&2
  exit 1
fi

echo "OK: version $header is consistent"
