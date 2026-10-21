# Next.js example

A contact form on the App Router. There is no SDK. One page posts from the browser. The other posts from a route handler, which does not send an `Origin` header.

Copy `.env.example` to `.env`. Set `FORMSTEAD_ENDPOINT` to the submissions URL from the form screen. Add `http://localhost:4500` to that form's origin allowlist. `http://127.0.0.1:4500` is a different origin. The server route does not need to be on the allowlist, because a missing `Origin` is allowed.

`npm run dev` serves the example at `http://localhost:4500`.

## Pages

`/` posts straight to Formstead with `fetch`. The browser sends `Origin`. The script sends `Accept: application/json`, a JSON body, and a new `Idempotency-Key` for each submit. `_fs_elapsed` is the whole milliseconds since the form was shown.

`/server` posts to `/api/contact`. That route handler sends the same JSON to Formstead and does not set `Origin`. It passes `422`, `409`, and `429` through, including `Retry-After`.

A `201` shows the `receipt_id`. A `422` shows the first field error. A `409` asks for a new submission. A `429` shows `Retry-After`.

## Webhook

`/api/formstead-webhook` checks the Standard Webhooks signature before it does anything else. The secret is `FORMSTEAD_WEBHOOK_SECRET` (`whsec_` plus base64). The timestamp has to be within five minutes. `webhook-signature` may contain more than one `v1` value. The comparison uses `crypto.timingSafeEqual`.

When `FORMSTEAD_API_BASE`, `FORMSTEAD_API_USER`, and `FORMSTEAD_API_PASSWORD` are all set, a valid webhook then requests `GET /submissions/{receipt_id}` with that application password. Without those values the route returns `204` after the signature checks.

`npm test` checks the signature against `tests/fixtures/webhook-vector.json`, the same vector the PHP tests use.
