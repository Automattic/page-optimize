#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${DB_NAME:-wordpress_test}"
DB_USER="${DB_USER:-wordpress}"
DB_PASS="${DB_PASS:-wordpress}"
DB_HOST="${DB_HOST:-db}"
WP_VERSION="${WP_VERSION:-latest}"
SKIP_DB_CREATE="${SKIP_DB_CREATE:-true}"
PHPUNIT_POLYFILLS_VERSION="${PHPUNIT_POLYFILLS_VERSION:-^1.0}"

db_host="$DB_HOST"
db_port=""
if [[ "$DB_HOST" == *:* ]]; then
  db_host="${DB_HOST%%:*}"
  db_port="${DB_HOST##*:}"
fi

mysql_pass_args=()
if [ -n "$DB_PASS" ]; then
  mysql_pass_args=(-p"$DB_PASS")
fi

mysql_args=(-h"$db_host" -u"$DB_USER" "${mysql_pass_args[@]}" --protocol=tcp --connect-timeout=2)
if [ -n "$db_port" ]; then
  mysql_args+=(-P"$db_port")
fi

ssl_args=()
if mysqladmin --help 2>&1 | grep -q -- '--ssl-mode'; then
  ssl_args+=(--ssl-mode=DISABLED)
elif mysqladmin --help 2>&1 | grep -q -- '--ssl-verify-server-cert'; then
  ssl_args+=(--ssl-verify-server-cert=0)
elif mysqladmin --help 2>&1 | grep -q -- '--skip-ssl'; then
  ssl_args+=(--skip-ssl)
fi
mysql_args+=("${ssl_args[@]}")

printf 'Waiting for MySQL at %s...\n' "$DB_HOST"
for i in $(seq 1 30); do
  if mysqladmin ping "${mysql_args[@]}" --silent; then
    break
  fi
  sleep 1
  printf '.'
  if [ "$i" -eq 30 ]; then
    printf '\n'
  fi
done

if ! mysqladmin ping "${mysql_args[@]}" --silent; then
  echo "MySQL ping failed. Diagnostics:" >&2
  mysqladmin ping "${mysql_args[@]}" || true
  mysqladmin status "${mysql_args[@]}" || true
  echo "MySQL did not become ready in time." >&2
  exit 1
fi

polyfills_base="/tmp/phpunit-polyfills"
polyfills_path="${polyfills_base}/vendor/yoast/phpunit-polyfills"
if [ ! -f "${polyfills_path}/phpunitpolyfills-autoload.php" ]; then
  mkdir -p "${polyfills_base}"
  cat > "${polyfills_base}/composer.json" <<JSON
{
  "require": {
    "yoast/phpunit-polyfills": "${PHPUNIT_POLYFILLS_VERSION}"
  }
}
JSON
  (cd "${polyfills_base}" && composer install --no-interaction --no-plugins --no-scripts --prefer-dist)
fi

export WP_TESTS_PHPUNIT_POLYFILLS_PATH="${polyfills_path}"

bin/install-wp-tests.sh "$DB_NAME" "$DB_USER" "$DB_PASS" "$DB_HOST" "$WP_VERSION" "$SKIP_DB_CREATE"

if [ "$#" -gt 0 ]; then
  phpunit "$@"
else
  phpunit
fi
