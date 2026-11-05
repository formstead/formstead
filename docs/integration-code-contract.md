# Integration code: implementation contract

Status: stages 11.A–11.G completed locally on 2026-11-04. HTML and JavaScript / fetch,
clipboard controls, variant selection and unsaved-change notices are implemented.
The stage E ZIP passed fresh-install and upgrade acceptance. A standalone Astro
variant has since been added and compiled/submitted in browser acceptance.
The standalone React / Next.js component has also been implemented and tested.
No release or deployment has been performed.

## Delivery scope

All implementation stages use the `integration-code` branch. Multiple working
commits belong on that branch, followed by a reviewed squash merge when the whole
feature is ready. The first implementation scope is HTML and JavaScript / fetch;
Astro and React / Next.js follow on the same branch. They are not separate branches.

## Source and architecture

- Generate from the persisted `Form`, its `Field` objects and `FieldSettings`.
  Never generate from posted editor values after a failed save.
- Resolve the endpoint with `rest_url()` in `FormsPage::render_edit()` and pass
  it explicitly to `Formstead\Integration\CodeGenerator`.
- Add `src/Integration/CodeGenerator.php` with an explicit list of supported
  variants; no registry, SDK, schema endpoint, migrations or persistent cache.
- Keep runtime templates under `templates/integration/`: `html.php`,
  `javascript.php`, `astro.php`, `react.php`, shared `fields.php` and `messages.php`. They must be in the distributable ZIP.
- Include `templates/admin/integration-code.php` after the main settings form
  closes, before the separate webhook-rotation form in `form-edit.php`.
- Load `assets/admin/integration-code.js` only for the form editor via the
  existing `FormsPage::enqueue()` hook. Add local CSS only if needed.
- The generator performs no writes, network requests or submission attempts.
  Viewing or copying code requires the existing manage-forms capability.
- The panel contains escaped source in readonly controls, never an executing
  preview or nested submission form. Keep code controls outside saved values.

## Field and transport decisions

| Field | Generated control |
| --- | --- |
| Text / email / textarea | Matching HTML control with label, required flag and maximum length. |
| Number | `type="number" step="any"`, textual min/max, precision hint. No JavaScript numeric conversion. The server enforces scale. |
| Boolean | A single checkbox with `value="1"`; absence means false, required means true. |
| Single choice | Select with an empty initial option and all saved value/label pairs. |
| Multiple choice | Multiple select with `name[]`; preserve all selections in transport. |

The backend returns multiple choices in **schema option order**, removing
duplicates. The generator must preserve the option order in the UI and must not
promise that stored values retain click or request order.

The editor currently requires a nonempty label. Blank-label fallback is defensive
support for legacy or programmatically created `Field` objects, not a new editor
capability. Test it when the renderer is added; do not weaken editor validation.

Use a prefixed ID per field, accessible labels and a visually hidden empty
`_fs_honeypot`. Native HTML posts to the endpoint and uses the saved success URL.
Never generate a static idempotency key shared by visitors.

The fetch variant enhances the native form and sends `FormData` with
`Accept: application/json`, `Idempotency-Key` and `credentials: 'omit'`.
Do not set multipart `Content-Type`, collapse repeated names with
`Object.fromEntries`, send files, add `_fs_elapsed` or use `no-cors`.
Use form-scoped selectors and APIs that cannot be shadowed by fields named
`action`, `method`, `submit` or `reset`.

Retain the logical key after network failures, unreadable responses, `5xx` and
`429`. Rotate after confirmed success or validation rejection. On `409`, explain
the conflict and prepare a new key for a subsequent manual send; do not retry
automatically. Field errors must include selects and checkboxes, preserve entered
values, announce status and focus the first invalid control. Never interpret a
proxy's HTML error body as executable markup or confirmed success.

## Panel states and copy behavior

- New form: save-first message, no invented endpoint or code.
- Saved form: code from the last persisted schema.
- Dirty editor or failed save: keep persisted code and show the unsaved notice.
- Disabled form: allow copying with an explicit disabled notice.
- Platform selection affects neither persistence nor the dirty state.
- Without panel JavaScript, all available variants remain manually copyable.
- Try Clipboard API only after a user action; on absence or refusal, focus and
  select the code and explain Ctrl+C / Cmd+C. Announce success only on success.
- Local previews use HTTP. Allowed origins include scheme, host and port;
  `localhost` and `127.0.0.1` differ. Never change origins based on platform.
- Escape output separately for generated HTML/JS and the enclosing admin
  textarea. Do not include webhook secrets, notification settings or submission
  data in the generated output.

## Reusable fixture and evidence

[`all-fields.json`](../tests/fixtures/integration-code/all-fields.json) contains:

- `form_input`: editor-valid input covering all six types, both boolean states,
  single and multiple choices, optional controls, scales 0 and 6, fractional
  minimum and a decimal above JavaScript's safe integer range;
- `submission`: browser-shaped synthetic strings/arrays, omitted unchecked
  boolean, empty optional select, repeated selections, DOM property names and
  characters significant to HTML, JavaScript, JSX and Unicode;
- `normalized`: independently declared expected intake values.

[`IntakeContractTest`](../tests/Integration/IntakeContractTest.php) verifies that
the fixture passes editor validation, survives repository persistence and
normalizes correctly. It also tests repeated `name[]` values through form encoding
and field-specific validation failures. These tests establish backend assumptions;
they do not claim that generated HTML, browser multipart, clipboard or framework
components work before those features exist.

Stage B adds [`CodeGeneratorTest`](../tests/Integration/CodeGeneratorTest.php)
for parsed controls, escaping, endpoint formats, defensive label fallback,
permissions, failed saves and new/disabled forms. The browser test
[`integration-code.spec.ts`](../tests/e2e/integration-code.spec.ts) reads the
actual source from the editor and serves it through an intercepted HTTP fixture
at the allowed local origin. With JavaScript disabled it verifies `303`, the
submission count and the complete stored payload against the shared fixture.
The section is also checked with axe.

Stage C adds a form-editor-only script, loaded after the field-row script has
initialized. Input/change events and add/remove actions reveal the unsaved notice;
they never regenerate the source from unsaved values. Copy controls are outside
the settings form and become visible only after script initialization. Their
container is hidden for the no-JavaScript path, since WordPress button styling
can override `hidden` on the button itself. Live status messages distinguish a
confirmed clipboard write from the manual-selection fallback.

Browser coverage includes a stubbed successful clipboard write, refused and
unavailable APIs on HTTP, keyboard focus, no accidental save, row changes,
validation failure, and manual selection plus a successful editor save with
JavaScript disabled. Existing PHP tests also exercise save and webhook rotation.
The global browser setup now waits for WordPress's initial username focus before
filling credentials; otherwise the delayed focus can interrupt password input.

Stage D uses `templates/integration/javascript.php`, with the same HTML/field
templates and safely JSON-encoded translations. Each script binds to the form
immediately before it, with local errors, status, pending state and key. Successful
identical values are not submitted again; a separate message explicitly says
they were accepted earlier and have not been resent; a new value set starts another logical
submission. Keys survive ambiguous outcomes and rate limits; `Retry-After` also
prevents early manual retries. Field errors do not replace existing descriptions.
The optional elapsed-time signal is omitted. No automatic retry or reset occurs.

[`integration-fetch.spec.ts`](../tests/e2e/integration-fetch.spec.ts) exercises
actual generated source through HTTP fixture files served by `serve-examples.mjs`,
not an intercepted document response. This preserves the document's loopback
address space for real browser fetch/CORS checks. It verifies full multipart
values, omitted cookies, native fallback, real `422` and `409`, response loss after
commit with one submission and delivery, rate limits, fallback random keys,
concurrent/confirmed repeat prevention, two independent forms, rejected origins,
honeypots and malformed/HTML replies. Simulated replies cover client error paths;
success, validation, conflicts, replay and origins use the real backend.

Stage E refreshed the POT and setup/readme documentation. The standard
`npm run check` passes without manual flags; root ZIPs and local notes follow
the distribution exclusions. A full local browser run passed all 47 tests.
The candidate ZIP contains production autoload only and no examples or source
checkout. It passed fresh installation and replacement of a populated clean-HEAD
installation on separate `fse_` prefixes: every plugin row, option and public ID
survived unchanged. Installed files matched all 144 payload files byte for byte.
HTML and fetch were copied from the installed editor and produced real `303`/`201`
responses, visible inbox rows and exact decimal values in both installations.
Plugin Check 2.1.0 on the active installed package reported zero errors and 178
warnings; these remain visible and do not constitute directory approval.

Stage F adds `templates/integration/astro.php`. It serializes the same persisted
`Field` definitions into JSON frontmatter and uses Astro expressions for rendering,
so braces, quotes and closing tags stay data. It exposes an optional `idPrefix`
prop for repeat instances and renders native POST without client scripts or SDK
imports. The template retains exact number strings and all existing field rules.
`integration-astro.spec.ts` copies the actual panel source, compiles a temporary
isolated project with the existing Astro 7.4.1 installation, renders two instances,
checks unique IDs and hostile labels, then submits every field with JavaScript off
and compares the stored payload. Temporary projects and HTML are removed afterward.
The stage E ZIP remains a historical HTML/fetch artifact; rebuild and repeat acceptance for Astro and React.

Tests are assigned to the stage implementing the relevant behavior:

| Stage | Required evidence |
| --- | --- |
| B: HTML | Render every control, blank-label fallback, escaping, honeypot, plain permalinks and subdirectory endpoints; execute code read from the panel with JS disabled. |
| C: panel | Saved vs posted values, permissions, dirty state, no accidental save, clipboard success/refusal/unavailable API, keyboard and axe. |
| D: fetch | Actual multipart and CORS, exact saved values, retry after lost response, `409`, `422`, `429`, malformed JSON, DOM property names and independent instances. |
| E: packaging | Runtime assets in ZIP, fresh install and upgrade, documented workflow and full relevant checks. |
| F/G: frameworks | Compile actual generated Astro/TSX including hostile text, then verify SSR/hydration and browser submission. |

Use the same fixture in subsequent stages rather than maintaining a separately
handwritten example that could hide generator errors. The disposable WordPress
test database is used; the fixture creates nothing in the development site.

Stage G adds `templates/integration/react.php`, a standalone TSX component with
only React imports and the Next.js client directive. Schema and translated messages
are JSON encoded; JSX renders them as text. `useId` creates stable SSR/hydration
IDs and `useRef` guards submissions synchronously. Each instance retains its own
key, accepted body and rate deadline. The multipart transport, exact number strings,
validation errors, manual retry policy and no-JavaScript fallback match the fetch
variant. `messages.php` shares translated fetch/React strings on the PHP side;
there is no shared client runtime or SDK. The fixed Next.js contact example now
uses the same transport and retry behavior; its optional route still converts
fixed email/message fields to JSON and forwards the incoming key.

`integration-react.spec.ts` copies actual panel source, builds an isolated app
using existing Next.js 16.4.2, React 19.3.1 and TypeScript 5.9.3, then checks server
rendering, hydration, two instances, full stored payload, accessible validation,
real response loss/replay and conflict, controlled retry/status cases and native
POST. Only the local synthetic fixture allows its temporary HTTP port 4501.

Stage G validation: `npm run check` passed (435 PHPUnit tests, 4112 assertions,
one skipped; PHPCS/PHPStan passed; Plugin Check zero errors and 179 retained
warnings). Final React/Next acceptance passed 9/9. An initial full browser run
passed 52/55: two new fixture expectations were corrected and passed in that 9/9
rerun; the existing WordPress privacy-download timeout passed its separate 1/1
rerun. This is evidence across runs, not a single full green 55/55 run. No commit,
push, merge or release was performed. Framework package acceptance remains a
pre-distribution task; the stage E artifact contains only the earlier variants.

Section 12 aligned the user guides, distributable readme and fixed-example READMEs
with all four starter-code variants. React identifier prefixes are configured at
the application root, not through a form prop. The fixed Astro JSON/timing demo
now freezes elapsed time with its idempotency key across uncertain retries and
uses the same manual-retry and confirmation policy. A real lost-response test
checks equal request bodies/keys, one submission and one delivery. Example builds,
strict TypeScript checking of the script and 14/14 example E2E tests passed locally.
This does not replace acceptance of a new distributable package.
