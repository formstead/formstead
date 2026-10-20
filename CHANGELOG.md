# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.0] - 2026-10-20

### Added

- An `Idempotency-Key` header, or the `_fs_idempotency_key` field, returns the original result for 24 hours. The same key with different content returns `409`.
- Email notifications for a form that has recipients. The message links to the submission. Full content is included only when that option is on. A failed send stays queued and is retried, then marked failed. `wp formstead deliveries run` processes the queue from system cron when the site has little traffic.
- The submission screen shows whether the notification is waiting, handed to the mail system, failed, or cancelled.

### Changed

- Forms that already exist keep an empty recipient list, so this update does not start sending mail until recipients are saved.

## [0.3.1] - 2026-10-20

### Fixed

- A submission already over its per-minute limit no longer consumes the form's hourly limit.
- JSON that is too deep for WordPress to parse returns `413` `payload_too_large`.

## [0.3.0] - 2026-10-20

### Added

- Request size, field count, and JSON depth limits. Oversized input returns `413` `payload_too_large`.
- Rate limits per IP address and per form, with `429` and `Retry-After`. A form can override the global limits. Global values can be set with `FORMSTEAD_MAX_REQUEST_BYTES`, `FORMSTEAD_MAX_FIELDS`, `FORMSTEAD_RATE_PER_MINUTE`, `FORMSTEAD_RATE_PER_HOUR`, and `FORMSTEAD_TRUSTED_PROXIES` in `wp-config.php`.
- A honeypot field, `_fs_honeypot`. A filled field looks like success and is not stored. The rejection count is shown in the admin.
- An origin allowlist on each form. An empty list allows only this site. A missing `Origin` header stays allowed. Another origin gets `403` `origin_not_allowed`.
- CORS for `formstead/v1` without `Access-Control-Allow-Credentials`.

### Changed

- Forms that are submitted from another site need that origin on the allowlist after this update.

## [0.2.0] - 2026-10-20

### Added

- Number, checkbox, and choice fields. A choice can allow more than one value.
- `Accept: application/json` returns `201` and a receipt id. Field errors use stable codes.
- URL-encoded, multipart, and JSON bodies. An unsupported type returns `415`, a file part returns `415`, and malformed JSON returns `400`.
- Immutable field-schema snapshots. A submission keeps the labels from the schema it was saved with.
- Pull requests run on PHP 8.3 with WordPress 7.1 and on PHP 8.5 with WordPress 7.0. The nightly run covers PHP 8.3–8.5 with WordPress 7.0 and 7.1, plus MySQL 8.0.

### Changed

- An optional empty value is left out of the stored submission. A checkbox is always stored as true or false.
- Submissions saved before 0.2.0 only have a snapshot of the schema that was current at upgrade. An older version uses the current labels when the field still exists, and the raw field name when it does not.

## [0.1.1] - 2026-10-20

### Fixed

- The forms screen keeps a percent-encoded success URL and form name, and it rejects a field name that does not match the pattern instead of rewriting it.
- The public submissions route stays registered when a migration lock is held and the tables already exist.
- A field row shows the remove checkbox without JavaScript and the remove button once the script runs.
- The package job checks that an invalid POST returns an HTML page and does not echo the submitted value.

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

[Unreleased]: https://github.com/formstead/formstead/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/formstead/formstead/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/formstead/formstead/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/formstead/formstead/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/formstead/formstead/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/formstead/formstead/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/formstead/formstead/compare/v0.0.2...v0.1.0
[0.0.2]: https://github.com/formstead/formstead/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/formstead/formstead/releases/tag/v0.0.1
