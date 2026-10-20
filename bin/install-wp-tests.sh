#!/usr/bin/env bash
#
# Install WordPress and its PHPUnit library for a database other than MariaDB.
# wp-env only runs MariaDB, so the MySQL CI job uses this script instead.
#
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> <db-host> <wp-version>

set -euo pipefail

if [[ $# -lt 5 ]]; then
	echo "Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> <db-host> <wp-version>" >&2
	exit 1
fi

DB_NAME="$1"
DB_USER="$2"
DB_PASS="$3"
DB_HOST="$4"
WP_VERSION="$5"

WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"

rm -rf "$WP_CORE_DIR" "$WP_TESTS_DIR" /tmp/wordpress-develop
mkdir -p "$(dirname "$WP_CORE_DIR")" /tmp

curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" | tar -xz -C /tmp
if [[ "/tmp/wordpress" != "$WP_CORE_DIR" ]]; then
	mv /tmp/wordpress "$WP_CORE_DIR"
fi

curl -fsSL "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WP_VERSION}.tar.gz" | tar -xz -C /tmp
develop="/tmp/wordpress-develop-${WP_VERSION}/tests/phpunit"
mkdir -p "$WP_TESTS_DIR"
cp -a "${develop}/includes" "$WP_TESTS_DIR/includes"
cp -a "${develop}/data" "$WP_TESTS_DIR/data"

cat > "${WP_TESTS_DIR}/wp-tests-config.php" <<PHP
<?php
define( 'ABSPATH', '${WP_CORE_DIR}/' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'formstead-tests' );
define( 'SECURE_AUTH_KEY', 'formstead-tests' );
define( 'LOGGED_IN_KEY', 'formstead-tests' );
define( 'NONCE_KEY', 'formstead-tests' );
define( 'AUTH_SALT', 'formstead-tests' );
define( 'SECURE_AUTH_SALT', 'formstead-tests' );
define( 'LOGGED_IN_SALT', 'formstead-tests' );
define( 'NONCE_SALT', 'formstead-tests' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Formstead tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
PHP

echo "WordPress ${WP_VERSION} tests installed in ${WP_TESTS_DIR}."
