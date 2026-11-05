#!/usr/bin/env bash
# Run Plugin Check against the mounted plugin, skipping development paths
# that .distignore keeps out of the distributable ZIP.
# Plugin Check prints findings with exit 0; fail on ERROR like CI.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

EXCLUDE_DIRECTORIES='tests,examples,bin,.github,.cursor,.claude,.codex,.agents,notes,coverage,test-results,playwright-report,.idea'
EXCLUDE_FILES='.gitignore,.distignore,.wp-env.json,.wp-env.override.json,.wp-env.tests.json,.wp-env.tests.override.json,.nvmrc,package.json,package-lock.json,composer.json,composer.lock,compose.yaml,CONTRIBUTING.md,CHANGELOG.md,README.md,phpunit.xml.dist,phpcs.xml.dist,phpstan.neon.dist,.phpunit.result.cache,.phpcs.cache,.phpstan.cache,formstead.zip,.DS_Store,skills-lock.json,AGENTS.md,CLAUDE.md,.cursorrules'

# Every root ZIP is a local build artifact, excluded by .distignore's /*.zip.
for archive in "$ROOT"/*.zip; do
	if [[ -f "$archive" ]]; then
		EXCLUDE_FILES+=",$(basename "$archive")"
	fi
done

CHECK_JSON="$(mktemp)"
trap 'rm -f "$CHECK_JSON"' EXIT

npx wp-env run cli bash -lc '
set -euo pipefail
cli_php="$(ls -d wp-content/plugins/plugin-check*/cli.php 2>/dev/null | head -n 1)"
if [[ -z "$cli_php" ]]; then
    echo "Plugin Check cli.php not found." >&2
    exit 1
fi
wp plugin check formstead \
    --require="./${cli_php}" \
    --format=json \
    --exclude-directories="$1" \
    --exclude-files="$2"
' formstead-plugin-check "$EXCLUDE_DIRECTORIES" "$EXCLUDE_FILES" | tee "$CHECK_JSON"

if grep -q '"type":"ERROR"' "$CHECK_JSON"; then
	echo "Plugin Check reported errors." >&2
	exit 1
fi

echo "Plugin Check reported no errors."
