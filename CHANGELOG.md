# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Keep one branch and squash pull request per roadmap stage, with automatic branch deletion. Tags and GitHub Releases are reserved for distributed versions. Stages 0–9 are consolidated into ten commits; the previous history is archived.

## 1.0.0-rc.1 - 2026-10-22

### Fixed

- Require verified cURL address pinning for webhooks and refuse delivery when the transport cannot guarantee it. Restrict test-only network exceptions to explicit local development environments.
- Keep stable personal-data export page boundaries when earlier submissions are deleted or new submissions arrive during export.
- Keep failed migration reads and incomplete schema changes from being recorded as successful upgrades.

### Added

- Historical schema fixtures, concurrent delivery and reactivation coverage, real HTTP transport and signed webhook tests, and a repeatable 100,000-submission acceptance benchmark.
- Getting-started, API, operations and testing guides, translation template, screenshot capture tooling and third-party license notices.

## [0.9.0] - 2026-10-21

### Added

- Authenticated submissions API addressed by receipt id. It requires HTTPS outside local development, accepts a WordPress application password, and sends no CORS headers. A read does not mark the submission read. Deletion cancels a waiting delivery and is audited.
- Signed webhook per form, using Standard Webhooks. Private addresses and redirects are refused before a request is sent. Retries stop after five attempts, and deleting the submission cancels a webhook that has not been sent.
- Next.js example that posts from the server without an `Origin` header and verifies the webhook signature.
- Integration notes for CORS, the admin API, and webhooks.

### Changed

- GitHub Actions starts only when someone runs the CI workflow by hand. Run it on `main` after the last pull request of a plan. Pull requests, pushes, and the daily schedule no longer start it.

## [0.8.1] - 2026-10-21

### Fixed

- Adding an identifying email field while its backfill is in progress indexes submissions that were already passed.
- A failed read of the identity index keeps the backfill unfinished, and personal-data export and erase report the failure instead of an empty result.
- Saving a new identifying field still queues that backfill when the index cannot be cleared. A form already left without that queue can be repaired with `wp formstead identities rebuild --form=<public_id>`.

## [0.8.0] - 2026-10-21

### Added

- Index identifying email fields as an HMAC, so one person's submissions can be found without scanning every payload. Search the inbox for an exact address.
- Export and erase that person's submissions through the WordPress personal-data tools, a page at a time, including when there are more submissions than one page.
- Record form, settings, export, deletion, retention, and privacy actions in an audit log. The log stores event codes, not submission text, and is kept for 180 days.
- Describe what is stored, how long it stays, and how it is removed, and suggest that wording on the WordPress privacy screen.

### Changed

- Updating marks existing email fields as identifying and builds their index in the background. Export and erase wait until that index is finished.

## [0.7.1] - 2026-10-21

### Fixed

- End-to-end tests stop only this project's database container.
- The accessibility check opens a submission it has just stored.
- Parallel idempotency and rate-limit checks run in the browser, so `Retry-After` and `Idempotent-Replayed` must be exposed to JavaScript.

## [0.7.0] - 2026-10-21

### Added

- HTML and Astro examples for a form without JavaScript and with `fetch()`, including a post from another origin. Browser tests cover those paths.
- A public threat model for the technical MVP.

### Fixed

- The public error page has a viewport and a main landmark. Field controls in the editor have accessible names, and a row can be added or removed from the keyboard.
- Parallel requests that share an idempotency key are retried after a lock conflict, so they replay the original result instead of failing.

## [0.6.1] - 2026-10-21

### Fixed

- A long retention run keeps its lock, so a second cleanup does not start after 15 minutes.
- `wp formstead retention run` lists every deleted count, warns when rows are still waiting, and exits with an error when submissions could not be deleted.
- Settings does not warn that retention is overdue before the first scheduled run. Until then it shows when that run will happen.
- Uninstall on a network removes capabilities only on sites that opted in.

## [0.6.0] - 2026-10-21

### Added

- Each form keeps submissions for a number of days, 30 by default. Zero keeps them until someone deletes them. The period covers every status, including archived and spam.
- Spam is deleted after the shorter of the form period and `FORMSTEAD_SPAM_RETENTION_DAYS` (7 days). Finished deliveries are deleted after `FORMSTEAD_DELIVERY_RETENTION_DAYS` (30 days). Expired idempotency keys and rate-limit counters are deleted on the same run.
- Hourly cleanup through WP-Cron, and `wp formstead retention run` for system cron. A run that finds more rows than it can delete reports a backlog.
- Settings shows the last cleanup and warns when the last full run is older than 48 hours or a backlog has lasted longer than 24 hours.
- An option to delete all Formstead data when the plugin is uninstalled. It stays off until someone who can activate plugins turns it on. Uninstall without the option keeps the data.

### Changed

- Forms that already exist keep submissions for 30 days. The first cleanup deletes older submissions.

## [0.5.1] - 2026-10-21

### Fixed

- The inbox shows its views: Inbox, Unread, Archived, Spam, and All, with counts for the current form and date filters.
- Mark as unread stays unread. The screen returns to the list instead of opening the submission and marking it read again.
- Deleting a submission rolls back when cancelling its delivery or removing its idempotency key fails.
- CSV export reads each batch in creation order. Rows stored at the same second are all included, and a large form is not sorted again for every batch.

## [0.5.0] - 2026-10-21

### Added

- Inbox views for new and read submissions, unread, archived, spam, and everything. The list filters by form and by date, and the menu shows how many submissions are still unread.
- Opening a new submission marks it read. Mark as unread puts it back.
- Permanent deletion. A notification that is still waiting is cancelled, and the same idempotency key can store a new submission afterwards.
- CSV export for one form, using the status and dates of the current view. Dates are UTC. A cell that starts with `=`, `+`, `-`, `@`, a tab, or a carriage return is prefixed so a spreadsheet does not treat it as a formula.
- Optional `_fs_elapsed`, the number of milliseconds since the form was shown. A value under three seconds, or the same content on the same form within ten minutes, is stored as spam and is not emailed. The visitor still receives the normal success response. Changing that status later does not send the skipped email.

## [0.4.1] - 2026-10-21

### Fixed

- Two requests with the same idempotency key no longer deadlock on MySQL. The second request waits, then receives the original result.
- An idempotency key that ends with a newline is rejected.
- The notification subject decodes HTML entities in the site name.

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

[Unreleased]: https://github.com/formstead/formstead/compare/v0.9.0...HEAD
[0.9.0]: https://github.com/formstead/formstead/compare/v0.8.1...v0.9.0
[0.8.1]: https://github.com/formstead/formstead/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/formstead/formstead/compare/v0.7.1...v0.8.0
[0.7.1]: https://github.com/formstead/formstead/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/formstead/formstead/compare/v0.6.1...v0.7.0
[0.6.1]: https://github.com/formstead/formstead/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/formstead/formstead/compare/v0.5.1...v0.6.0
[0.5.1]: https://github.com/formstead/formstead/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/formstead/formstead/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/formstead/formstead/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/formstead/formstead/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/formstead/formstead/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/formstead/formstead/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/formstead/formstead/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/formstead/formstead/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/formstead/formstead/compare/v0.0.2...v0.1.0
[0.0.2]: https://github.com/formstead/formstead/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/formstead/formstead/releases/tag/v0.0.1
