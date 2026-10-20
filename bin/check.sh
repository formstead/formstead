#!/usr/bin/env bash
# Local merge gate: PHPUnit, PHPCS, PHPStan, and Plugin Check.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> PHPUnit"
npx wp-env run tests-cli --env-cwd=wp-content/plugins/formstead vendor/bin/phpunit

echo "==> PHPCS"
npx wp-env run cli --env-cwd=wp-content/plugins/formstead vendor/bin/phpcs

echo "==> PHPStan"
npx wp-env run cli --env-cwd=wp-content/plugins/formstead vendor/bin/phpstan analyse --memory-limit=1G

echo "==> Plugin Check"
bash "$ROOT/bin/plugin-check.sh"

echo "==> All checks passed."
