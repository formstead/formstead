# Release checks

Use the disposable WordPress tests site. PHPUnit reinstalls WordPress and clears Formstead's test tables, including data left by browser tests. Never point the test bootstrap at a development or live database. PHP and Composer tools run in Docker through the project's `wp-env` commands.

## Local checks

```sh
npx wp-env start
npm run check
npm run test:explain
npm run mail:up
npm run e2e:setup
npm run build --prefix examples/next
npm test --prefix examples/next
npm run e2e
```

Set `FORMSTEAD_MAILPIT_UI_PORT` and `FORMSTEAD_MAILPIT_SMTP_PORT` when the default ports belong to another project; use the same UI port for browser tests. Setup builds the Astro example with the seeded URLs. Do not run another Astro build against that output at the same time. Run PHP tests before browser setup; a later PHPUnit run clears the seeded data again.

The ordinary check runs PHPUnit, PHPCS, PHPStan and Plugin Check. `test:explain` is separate and includes indexed-query plans and the 100,000-submission acceptance benchmark. Full CI starts manually after a stage is merged into `main`; it also tests MySQL, supported PHP/WordPress pairs, the distributable ZIP and the examples.

## Large-inbox acceptance

`tests/Submissions/LargeInboxAcceptanceTest.php` creates ten synthetic forms, an uneven 100,000-row inbox, identifying-email entries, pending deliveries and idempotency claims. It includes old/fresh data, all statuses and a form with retention disabled. It does not schedule external sends.

The benchmark measures real hydrated list pages with totals and status counts: first page, page 1,000, form/date/status filters, and exact email search. After a read warmup, each case has thirty measurements. Retention is measured across thirty equivalent 500-row batches of the same age and shape, then an unbounded CLI-mode run drains the remaining backlog. The suite checks that identity entries and idempotency claims do not remain without submissions and that retention-disabled data survives. Cancelled delivery metadata intentionally follows its own retention policy.

Targets for the recorded reference environment: p95 up to one second for the first/filtered page, two seconds for deep/email pages, two seconds per 500-row retention batch, and peak PHP memory below 256 MiB. These are acceptance thresholds, not a hosting SLA. Record CPU, RAM, architecture, PHP/database versions, fixture shape and commit alongside results.

Results are emitted as a `FORMSTEAD_BENCHMARK` JSON line. The manual CI workflow saves MariaDB and MySQL logs as separate performance artifacts. The source of the DDL and expected index plans is in `tests/fixtures/schemas/README.md` and the `explain` tests.

## Package and screenshots

Build inside the configured PHP/Composer environment with `bash bin/build-zip.sh`. The build requires the translation template, GPL license, Composer's MIT notice and the third-party inventory. It excludes source guides, tests, local notes, examples, build tools and `.wordpress-org` publication assets. Run Plugin Check against the installed ZIP on a clean test site as well as the working tree.

Generate screenshots after browser setup and login with `npm run screenshots`. This command accepts only the local tests site on port 8889 and uses its synthetic data. Review every image for readable content and absence of secrets before publication. It does not upload anything.

Project checks include stored-XSS and SQL-filter regression cases, TLS/capability checks, a real pinned HTTP connection, a missing-cURL refusal, parallel idempotency, two independent delivery-worker processes, historical schema upgrades and interrupted-migration retries. They do not constitute an independent audit or the hosting pilot required before stable 1.0.

Plugin Check 2.1.0 reports no blocking errors in the candidate package. Reviewed warning categories remain: template variables included inside page methods are not globals exposed by the plugin; SQL identifiers come from the fixed schema/prefix while variable values use prepared parameters; and the production ZIP omits Composer manifests while retaining its generated autoloader and MIT notice. These warnings are retained in the report, not hidden by disabling their checks. Reassess them when the affected code changes.
