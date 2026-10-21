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

# composer.json and composer.lock stay out of the ZIP, but install needs both.
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$PLUGIN_DIR/"

VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT/formstead.php" | head -n 1)"
if [[ -z "$VERSION" ]]; then
	echo "Could not read Version from formstead.php." >&2
	exit 1
fi

COMPOSER_ROOT_VERSION="$VERSION" \
	composer install \
	--working-dir="$PLUGIN_DIR" \
	--no-dev \
	--optimize-autoloader \
	--no-interaction \
	--prefer-dist

rm -f "$PLUGIN_DIR/composer.json" "$PLUGIN_DIR/composer.lock"

if [[ ! -f "$PLUGIN_DIR/formstead.php" ]] || [[ ! -f "$PLUGIN_DIR/vendor/autoload.php" ]]; then
	echo "ZIP staging is missing formstead.php or vendor/autoload.php." >&2
	exit 1
fi

rm -f "$OUTPUT"
(
	cd "$STAGING"
	zip -r "$OUTPUT" formstead
)

if unzip -l "$OUTPUT" | grep -E 'formstead/(tests/|bin/|node_modules/|\.github/|examples/|docs/|dev/)|formstead/playwright\.config|formstead/composer\.(lock|json)|formstead/README\.md|\.wp-env|phpunit\.xml|phpcs\.xml|phpstan\.neon'; then
	echo "Distributable ZIP contains development files." >&2
	exit 1
fi

echo "Wrote $OUTPUT"
