# Astro example

A static contact form. It posts to the submissions URL copied from the form screen in WordPress. With plain permalinks that URL uses `/?rest_route=`. There is no SDK.

Copy `.env.example` to `.env` and set the URLs. Add `http://localhost:4321` to the form's origin allowlist. `http://127.0.0.1:4321` is a different origin. Build with `npm run build` and open the site with `npm run preview`.

## Pages

`/` is a normal HTML form. It does not load JavaScript. A successful post redirects to the success URL saved on the form.

`/fetch` is the same form, and a script takes over when JavaScript runs. Without JavaScript it behaves like `/`.

The script sends `Accept: application/json` and a JSON body. `Idempotency-Key` is a new UUID when the page loads. The same key is used again after a network failure or a `5xx`, so a retry does not create a second submission. A `201` or a `409` starts a new key. `_fs_elapsed` is the whole milliseconds since the script loaded. A value under the form's threshold is stored as spam. The page still shows the usual thanks, including the `receipt_id`.

A `422` body looks like `{ "error": { "code": "invalid_fields", "fields": { "message": ["required"] } } }`. The script marks the field with `aria-invalid` and `aria-describedby`, shows the message, and moves focus to the first invalid field. A `429` shows `Retry-After`. A `409` asks for a new submission. Nothing on the page repeats the values that were typed.

`/closed` posts to `PUBLIC_FORMSTEAD_CLOSED_ENDPOINT`. Point that at a form whose allowlist does not include this site to see the browser refuse the response.

A request with no `Origin` header is allowed. The browser sends one. A server-side client often does not.
