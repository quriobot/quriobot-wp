#!/usr/bin/env bash
# Prints the newest WordPress version on a channel.
#
#   tests/wp-current-version.sh          # newest stable release
#   tests/wp-current-version.sh beta     # newest beta/RC, if one is open
set -euo pipefail

url="https://api.wordpress.org/core/version-check/1.7/"
if [ "${1:-stable}" = "beta" ]; then
  url="${url}?channel=beta"
fi

body=$(curl -fsSL "$url")

# Capture grep's whole output before taking the first line: piping straight into
# head can kill grep with SIGPIPE, which fails the caller under pipefail.
matches=$(printf '%s' "$body" | grep -o '"current":"[^"]*"' || true)
version=$(printf '%s\n' "$matches" | sed -n '1s/.*:"\(.*\)"/\1/p')

if [ -z "$version" ]; then
  echo "could not read the current WordPress version from api.wordpress.org" >&2
  exit 1
fi

printf '%s\n' "$version"
