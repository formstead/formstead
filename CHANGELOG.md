# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.0.2] - 2026-10-20

### Added

- `npm run check` runs PHPUnit, PHPCS, PHPStan, and Plugin Check locally before pushing.
- GitHub Actions lints `formstead.php` on PHP 7.4, because PHPCS only checks PHP 8.3 and newer.
- Schema tests assert that a second `dbDelta()` call leaves `bigint(20) unsigned` unchanged.

### Changed

- Merge only after the `checks` and `package` jobs are green. Do not push directly to `main`.
- The package job signs into wp-admin and uninstalls the plugin. Plugin Check stays on 2.1.0 and WP-CLI on 2.12.0.
- The distributable ZIP omits `README.md` and `composer.json`.

### Fixed

- Activate Twenty Twenty-Five on `wp-env start` so the development site is not a blank page when the database still points at a missing `default` theme.
- Requirement notices are shown only to users who can activate plugins.

## [0.0.1] - 2026-10-19

### Added

- Project license, security policy, and contribution guide.
- Installable plugin bootstrap with PHP and WordPress minimums, a Multisite network-activation guard, and Composer autoloading.
- Local `wp-env` environment, PHPUnit smoke tests, PHPCS, and PHPStan.
- `SchemaTestCase` for activation and migration tests that need real tables.
- GitHub Actions checks for PHPUnit, PHPCS, and PHPStan.
- Distributable ZIP build and Plugin Check on a clean WordPress install.

[Unreleased]: https://github.com/formstead/formstead/compare/v0.0.2...HEAD
[0.0.2]: https://github.com/formstead/formstead/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/formstead/formstead/releases/tag/v0.0.1
