#!/usr/bin/env bash
# Runs the Quriobot plugin integration suite against a throwaway WordPress
# install.
#
#   tests/run.sh                              # latest stable WordPress
#   WP_IMAGE=wordpress:6.2 tests/run.sh       # a specific released version
#   WP_VERSION=7.1-RC3 tests/run.sh           # upgrade core to a beta/RC first
#   PLUGIN_CHECK=1 tests/run.sh               # also run the wordpress.org Plugin Check
set -euo pipefail

cd "$(dirname "$0")/.."

# Resolved once and exported: docker-compose.test.yml reads it too, so linting
# and the suite always run on the same image.
export WP_IMAGE="${WP_IMAGE:-wordpress:latest}"

COMPOSE="docker compose -f docker-compose.test.yml -p quriobot-test"

cleanup() {
  $COMPOSE down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

# .distignore is the single source of truth for what ships. Everything below —
# linting, the integration suite, Plugin Check — runs against this tree, so a new
# plugin file cannot ship without also being tested. This is the same script the
# release workflow runs before publishing.
./tests/check-dist.sh

echo
echo "== static checks =="
fail=0
php_files=$(cd .dist/quriobot && find . -name '*.php' | sed 's|^\./||')
for f in $php_files; do
  if docker run --rm -v "$PWD/.dist/quriobot:/src:ro" -w /src "$WP_IMAGE" php -l "$f" >/dev/null; then
    echo "  ok   php -l $f"
  else
    echo "  FAIL php -l $f"
    fail=1
  fi
done
for f in tests/*.php; do
  if docker run --rm -v "$PWD:/src:ro" -w /src "$WP_IMAGE" php -l "$f" >/dev/null; then
    echo "  ok   php -l $f"
  else
    echo "  FAIL php -l $f"
    fail=1
  fi
done
[ "$fail" -eq 0 ] || exit 1

echo
echo "== booting WordPress ($WP_IMAGE) =="
cleanup
$COMPOSE up -d --wait db wordpress cli

# The wordpress image unpacks core on first run; wait for it to land.
for _ in $(seq 1 60); do
  if $COMPOSE exec -T cli test -f /var/www/html/wp-settings.php; then break; fi
  sleep 2
done

wp() { $COMPOSE exec -T cli wp --allow-root --path=/var/www/html "$@"; }

wp core install \
  --url=http://localhost \
  --title="Quriobot Test" \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=test@example.com \
  --skip-email >/dev/null

if [ -n "${WP_VERSION:-}" ]; then
  echo "  upgrading core to ${WP_VERSION}"
  wp core update --version="${WP_VERSION}" --force >/dev/null
  wp core update-db >/dev/null
fi

echo "  running WordPress $(wp core version)"
wp plugin activate quriobot

echo
echo "== integration suite =="
wp eval-file /tests/test-quriobot.php

if [ "${PLUGIN_CHECK:-}" = "1" ]; then
  echo
  echo "== wordpress.org Plugin Check =="
  # The plugin directory will start running this against submitted updates.
  # This gate must fail closed: if Plugin Check cannot be installed or produces
  # output we do not recognise, that is a failure, not a pass.
  if ! wp plugin install plugin-check --activate; then
    echo "FAIL: could not install plugin-check"
    exit 1
  fi

  set +e
  wp plugin check quriobot --fields=type,code,file,line,message --format=csv >/tmp/qb-check.out 2>&1
  set -e
  cat /tmp/qb-check.out

  if grep -qE '^ERROR,' /tmp/qb-check.out; then
    echo "FAIL: Plugin Check reported errors"
    exit 1
  fi

  # Require positive evidence that the checks ran, rather than inferring success
  # from the absence of the word ERROR. Plugin Check prints the "Checks complete"
  # banner only when it finds nothing at all, so a findings table counts too.
  if ! grep -qE 'Checks complete|^type,code,file,line,message$' /tmp/qb-check.out; then
    echo "FAIL: Plugin Check produced no recognisable output"
    exit 1
  fi
  echo "OK: Plugin Check reported no errors"
fi
