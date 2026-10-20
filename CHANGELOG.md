# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-20

### Added

- Activation creates the forms and submissions tables and grants the four Formstead capabilities to administrators.
- Forms can be stored with a public id, field schema, and an absolute success URL.
- The admin can create and edit forms. The endpoint URL comes from `rest_url()`.
- A public form POST stores the submission and redirects to the form's success URL. Invalid fields get an HTML error page.
- Administrators can read submissions. Opening one does not change its status.
- The package job sends a real POST on a clean install and checks that uninstall keeps the tables.

### Changed

- The PHP 7.4 syntax job uses the pinned setup-php action instead of a floating `php:7.4-cli` image.
- The package job treats HTTP 200 as success. It no longer treats `wp-login.php` in the effective URL as a failure, because that curl call does not follow redirects.

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

[Unreleased]: https://github.com/formstead/formstead/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/formstead/formstead/compare/v0.0.2...v0.1.0
[0.0.2]: https://github.com/formstead/formstead/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/formstead/formstead/releases/tag/v0.0.1
