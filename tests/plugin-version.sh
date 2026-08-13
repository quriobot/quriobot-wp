#!/usr/bin/env bash
# Prints the plugin version as declared in the quriobot.php plugin header.
# The single reader of that header — check-version.sh proves every other copy
# of the version agrees with it.
set -euo pipefail

cd "$(dirname "$0")/.."

# `|| true` keeps a missing header from killing the script under `set -e`, so the
# diagnostic below is actually reachable.
version=$(grep -m1 '^ \* Version:' quriobot.php | sed -E 's/.*Version:[[:space:]]*//' || true)

if [ -z "$version" ]; then
  echo "could not read the plugin version from quriobot.php" >&2
  exit 1
fi

printf '%s\n' "$version"
