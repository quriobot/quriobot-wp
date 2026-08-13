#!/usr/bin/env bash
# Builds the distributable from .distignore and asserts its contents.
#
# This is the ONLY place that decides whether a build is safe to ship. Both the
# test harness and the release workflow call it, so the packaging that ships is
# the packaging that is tested.
set -euo pipefail

cd "$(dirname "$0")/.."

make dist >/dev/null

echo "== distributable contents =="
# `! -type d` rather than `-type f`: a symlink is neither, and zip -r follows it,
# so a stray link at the repo root would otherwise package its target's contents.
find .dist/quriobot ! -type d | sed 's|^.dist/quriobot/|  |' | sort

# Nothing from the development tree may ever reach wordpress.org. mysql_data in
# particular holds MySQL's generated private keys.
leaked=$(find .dist/quriobot \
  | grep -E '/(tests|\.github|\.dist|mysql_data|wp-content|\.git)(/|$)|docker-compose|Makefile|\.distignore|\.pem$|\.DS_Store' \
  || true)
if [ -n "$leaked" ]; then
  echo "FAIL: development files leaked into the distributable:" >&2
  echo "$leaked" >&2
  exit 1
fi

# An allowlist, not just a denylist: a new top-level file (an .env, an editor
# backup, a stray config) would match no leak pattern and would otherwise ship
# silently. Adding a file to the plugin means adding it here on purpose.
expected=$(cat <<'MANIFEST'
LICENSE
admin/static/quriobot-admin.css
admin/static/quriobot_logo_2x.png
admin/views/settings.php
includes/class-quriobot.php
quriobot.php
readme.txt
MANIFEST
)
actual=$(cd .dist/quriobot && find . ! -type d | sed 's|^\./||' | LC_ALL=C sort)

if [ "$actual" != "$(printf '%s' "$expected" | LC_ALL=C sort)" ]; then
  echo "FAIL: the distributable does not match the expected manifest" >&2
  diff --label expected --label actual -u \
    <(printf '%s\n' "$expected" | LC_ALL=C sort) <(printf '%s\n' "$actual") >&2 || true
  echo "If this change is intended, update the manifest in tests/check-dist.sh." >&2
  exit 1
fi

echo "OK: distributable matches the expected manifest"
