# Astro example

The WordPress editor also offers **Integration code → Astro** for the actual saved
schema. Save that source as `src/components/FormsteadForm.astro`, import and render
it on a page. Use different `idPrefix` values for multiple instances. It posts
natively and requires no client JavaScript. The fixed example below demonstrates
additional JSON/fetch behavior separately.

A static contact form. It posts to the submissions URL copied from the form screen in WordPress. With plain permalinks that URL uses `/?rest_route=`. There is no SDK.

For code matching your own saved schema, use **Integration code → JavaScript / fetch**
in WordPress. That standalone variant uses FormData and omits the elapsed-time
signal. This example demonstrates JSON submission and timing with a fixed contact form.

Copy `.env.example` to `.env` and set the URLs. Add `http://localhost:4321` to the form's origin allowlist. `http://127.0.0.1:4321` is a different origin. Build with `npm run build` and open the site with `npm run preview`.

## Pages

`/` is a normal HTML form. It does not load JavaScript. A successful post redirects to the success URL saved on the form.

`/fetch` is the same form, and a script takes over when JavaScript runs. Without JavaScript it behaves like `/`.

The script sends `Accept: application/json` and a JSON body. `Idempotency-Key` is generated lazily on the first submit. The same key is used again after a network failure or a `5xx`, so a retry does not create a second submission. A valid `201` completes the submission; `422` and `409` clear the key for the next manual attempt. `_fs_elapsed` is measured at the first attempt and frozen with its key so a later retry has the same body. It is measured again for a new logical submission. A value under the form's threshold is stored as spam. The page still shows the usual thanks, including the `receipt_id`.

A `422` body looks like `{ "error": { "code": "invalid_fields", "fields": { "message": ["required"] } } }`. The script marks the field with `aria-invalid` and `aria-describedby`, shows the message, and moves focus to the first invalid field. A `429` shows `Retry-After` and blocks earlier manual attempts; it does not retry automatically. A `409` warns to check before another manual send. A synchronous guard prevents overlapping sends, cookies are omitted, and an
identical confirmed submission is not sent again; a separate message explains why.
Unreadable or malformed success replies and network/5xx failures leave the key and
values available for retry and report an unconfirmed outcome. Nothing on the page
repeats the values that were typed.

The JSON/timing demonstration intentionally differs from generated fetch/React,
which use multipart data and omit `_fs_elapsed`. Both paths share the same retry,
honeypot and confirmation rules. Generated Astro uses native POST only. Copying a
component does not synchronize later schema edits or deploy/style the frontend.

`/closed` posts to `PUBLIC_FORMSTEAD_CLOSED_ENDPOINT`. Point that at a form whose allowlist does not include this site to see the browser refuse the response.

A request with no `Origin` header is allowed. The browser sends one. A server-side client often does not.

CORS, the admin API, and webhooks are described in [Integrations](../../docs/integrations.md).
