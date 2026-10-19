#!/usr/bin/env bash
# Build an installable Formstead ZIP with production Composer autoload only.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUTPUT="${FORMSTEAD_ZIP:-$ROOT/formstead.zip}"
STAGING="$(mktemp -d)"
trap 'rm -rf "$STAGING"' EXIT

PLUGIN_DIR="$STAGING/formstead"
mkdir -p "$PLUGIN_DIR"

rsync -a \
	--exclude-from="$ROOT/.distignore" \
	--exclude vendor \
	--exclude vendor/ \
	"$ROOT/" "$PLUGIN_DIR/"

COMPOSER_ROOT_VERSION="${COMPOSER_ROOT_VERSION:-0.0.1}" \
	composer install \
	--working-dir="$PLUGIN_DIR" \
	--no-dev \
	--optimize-autoloader \
	--no-interaction \
	--prefer-dist

# Lockfile is a development artifact; the ZIP only needs production autoload.
rm -f "$PLUGIN_DIR/composer.lock"

if [[ ! -f "$PLUGIN_DIR/formstead.php" ]] || [[ ! -f "$PLUGIN_DIR/vendor/autoload.php" ]]; then
	echo "ZIP staging is missing formstead.php or vendor/autoload.php." >&2
	exit 1
fi

rm -f "$OUTPUT"
(
	cd "$STAGING"
	zip -r "$OUTPUT" formstead
)

if unzip -l "$OUTPUT" | grep -E 'formstead/(tests/|bin/|node_modules/|\.github/)|formstead/composer\.lock|\.wp-env|phpunit\.xml|phpcs\.xml|phpstan\.neon'; then
	echo "Distributable ZIP contains development files." >&2
	exit 1
fi

echo "Wrote $OUTPUT"
