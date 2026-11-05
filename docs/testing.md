# Release checks

Use the disposable WordPress tests site. PHPUnit reinstalls WordPress and clears Formstead's test tables, including data left by browser tests. Never point the test bootstrap at a development or live database. PHP and Composer tools run in Docker through the project's `wp-env` commands.

## Local checks

```sh
npx wp-env start
npm run start:tests
npm run check
npm run test:explain
npm run mail:up
npm run e2e:setup
npm run build --prefix examples/next
npm test --prefix examples/next
npm run e2e
```

Set `FORMSTEAD_MAILPIT_UI_PORT` and `FORMSTEAD_MAILPIT_SMTP_PORT` when the default ports belong to another project; use the same UI port for browser tests. Setup builds the Astro example with the seeded URLs. Do not run another Astro build against that output at the same time. Run PHP tests before browser setup; a later PHPUnit run clears the seeded data again.

For example, use `FORMSTEAD_MAILPIT_UI_PORT=8026 FORMSTEAD_MAILPIT_SMTP_PORT=1026 npm run mail:up`
and `FORMSTEAD_MAILPIT_UI_PORT=8026 npm run e2e` when 8025/1025 are occupied.
Mail tests inspect the `formstead-mailpit` container's published port before
clearing its inbox. They refuse to clear an unrelated service on the configured
port. The guard test performs no inbox deletion.

Integration-code browser tests read source from the actual editor. Fetch tests
write temporary HTML under `tests/e2e/.auth/integration-code/`, serve it through
the local HTTP examples server and remove it after each test. Intercepting the
document response can change its browser address-space classification and block
loopback requests independently of Formstead CORS. API response interception is
used only for controlled failures; intake, replay and origin tests use the backend.
Do not disable browser network-access protection to make these tests pass.

The ordinary check runs PHPUnit, PHPCS, PHPStan and Plugin Check. `test:explain` is separate and includes indexed-query plans and the 100,000-submission acceptance benchmark. Full CI starts manually after a stage is merged into `main`; it also tests MySQL, supported PHP/WordPress pairs, the distributable ZIP and the examples.

## Large-inbox acceptance

`tests/Submissions/LargeInboxAcceptanceTest.php` creates ten synthetic forms, an uneven 100,000-row inbox, identifying-email entries, pending deliveries and idempotency claims. It includes old/fresh data, all statuses and a form with retention disabled. It does not schedule external sends.

The benchmark measures real hydrated list pages with totals and status counts: first page, page 1,000, form/date/status filters, and exact email search. After a read warmup, each case has thirty measurements. Retention is measured across thirty equivalent 500-row batches of the same age and shape, then an unbounded CLI-mode run drains the remaining backlog. The suite checks that identity entries and idempotency claims do not remain without submissions and that retention-disabled data survives. Cancelled delivery metadata intentionally follows its own retention policy.

Targets for the recorded reference environment: p95 up to one second for the first/filtered page, two seconds for deep/email pages, two seconds per 500-row retention batch, and peak PHP memory below 256 MiB. These are acceptance thresholds, not a hosting SLA. Record CPU, RAM, architecture, PHP/database versions, fixture shape and commit alongside results.

Results are emitted as a `FORMSTEAD_BENCHMARK` JSON line. The manual CI workflow saves MariaDB and MySQL logs as separate performance artifacts. The source of the DDL and expected index plans is in `tests/fixtures/schemas/README.md` and the `explain` tests.

## Package and screenshots

Build inside the configured PHP/Composer environment with `bash bin/build-zip.sh`. The build requires the translation template, GPL license, Composer's MIT notice and the third-party inventory. It excludes source guides, tests, local notes, examples, build tools and `.wordpress-org` publication assets. Run Plugin Check against the installed ZIP on a clean test site as well as the working tree.

The builder also requires the integration generator, admin view, shared field
template, HTML/fetch templates and copy/variant script in the staging directory.
Missing one fails the build. Source Plugin Check excludes root ZIP artifacts,
matching `.distignore`; it does not disable finding categories. PHPCS excludes
local notes and assistant files rather than inspecting third-party copies there.

`bin/ci/integration-package.php` supports `seed <fixture-path>`, `verify` and `code`
on a local site with `FORMSTEAD_PACKAGE_ACCEPTANCE=true` and a table prefix starting
`fse_`. Use a separate WordPress root with no source-checkout mount, disable cron,
and set its own `WP_HOME`/`WP_SITEURL`. Seed the previous ZIP from a clean checkout,
replace it with the candidate, then verify the fingerprints of every plugin row
and configuration option. Export and execute both code variants from the installed
ZIP and confirm their submissions in the inbox. Keep fixtures outside the plugin.
Activate Plugin Check on that site before using its CLI runtime loader; merely
copying the tool without activation does not initialize all its constants.

The local HTML/fetch candidate is `formstead-integration-code-e.zip`, with an
unreleased 1.0.0 header. It is an acceptance artifact, not a published version.
Framework variants and the eventual release version remain separate work.

Generate screenshots after browser setup and login with `npm run screenshots`. This command accepts only the local tests site on port 8889 and uses its synthetic data. Review every image for readable content and absence of secrets before publication. It does not upload anything.

Project checks include stored-XSS and SQL-filter regression cases, TLS/capability checks, a real pinned HTTP connection, a missing-cURL refusal, parallel idempotency, two independent delivery-worker processes, historical schema upgrades and interrupted-migration retries. There has been no independent security audit. The author's review is in [review.md](review.md). A hosting pilot of this release candidate exercised a submission from another site, mail delivery, a signed webhook, the submissions API from an administrator session, and personal-data export and erasure.

Plugin Check 2.1.0 reports no blocking errors in the candidate package. Reviewed warning categories remain: template variables included inside page methods are not globals exposed by the plugin; SQL identifiers come from the fixed schema/prefix while variable values use prepared parameters; and the production ZIP omits Composer manifests while retaining its generated autoloader and MIT notice. These warnings are retained in the report, not hidden by disabling their checks. Reassess them when the affected code changes.

## Generated React / Next.js acceptance

`integration-react.spec.ts` copies actual TSX through the editor's clipboard
control and builds a temporary app under `examples/next/.integration-*` with the
existing Next.js/React/TypeScript installation. It starts that app on HTTP port
4501, which must be free. Only its synthetic fixture permits that extra origin.
Its reusable synthetic form has a 60/min and 1000/hour limit, leaving product
defaults unchanged. The test checks SSR/hydration IDs for two instances, hostile labels, real multipart
payloads without cookies, real field errors, response loss after commit with one
submission/delivery, synchronous duplicate prevention, retry statuses and native
POST with JavaScript disabled. It removes its app and stops its server afterward.
Do not run PHPUnit after browser setup, as it resets the seeded WordPress data.
The historical stage E ZIP predates the Astro and React templates; repeat package
acceptance on a final candidate before distributing framework variants.

## Generator documentation and example checks

After seeding the local tests site, run the actual generated-source acceptance:

```sh
FORMSTEAD_MAILPIT_UI_PORT=8026 npm run e2e -- tests/e2e/integration-code.spec.ts tests/e2e/integration-fetch.spec.ts tests/e2e/integration-astro.spec.ts tests/e2e/integration-react.spec.ts
```

Use the UI port belonging to your Formstead Mailpit instance. These tests exercise
copied source, not hand-maintained substitutes. Astro compiles a temporary project
with the installed example dependencies and posts with JavaScript off; React
builds and starts a temporary Next.js app, checks SSR/hydration and submits fields.
Both temporary roots are removed afterward; no SDK or repository import is needed
by the generated components. `astro.spec.ts`, `html.spec.ts` and `next.spec.ts`
cover the fixed demonstration forms separately. The Astro JSON/timing example also
checks response loss after commit: retries must retain the key and frozen elapsed
value, with a single stored submission and delivery.
