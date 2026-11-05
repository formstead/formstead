# Next.js example

A contact form on the App Router. There is no SDK. One page posts from the browser. The other posts from a route handler, which does not send an `Origin` header.

Copy `.env.example` to `.env`. Set `FORMSTEAD_ENDPOINT` to the submissions URL from the form screen. Add `http://localhost:4500` to that form's origin allowlist. `http://127.0.0.1:4500` is a different origin. The server route does not need to be on the allowlist, because a missing `Origin` is allowed.

`npm run dev` serves the example at `http://localhost:4500`.

## Pages

For your own saved schema, choose **React / Next.js** in the WordPress form editor
and copy its standalone component. See [Integrations](../../docs/integrations.md)
for the import example. This repository example retains a fixed email/message
schema to demonstrate direct posting and the optional server route. That proxy
parses only those fixed fields; it is not a general proxy for generated schemas.
Replace copied components after saving schema changes, and supply your own styling
and deployment.

`/` posts straight to Formstead with `fetch`, multipart `FormData`,
`credentials: 'omit'` and `Accept: application/json`. `/server` posts to
`/api/contact`, whose route converts those fixed fields to JSON for Formstead.
The route forwards the client's idempotency key and `Retry-After`; it does not
invent an elapsed-time value when the client omits it.

Both pages use the same behavior as the generated component: independent `useId`
instance IDs, a synchronous sending guard, accessible field errors, a honeypot,
and lazy key generation. No browser API is accessed during server rendering.
No elapsed-time signal is added. After an uncertain response or `429`, a manual
retry keeps its key; `422` clears it for corrected details, and `409` warns before
a new manual attempt. A valid `201` confirms acceptance; an identical confirmed
submission is not sent again; the status explains it was accepted earlier and was
not resent. The component keeps values and retry state in memory.

## Webhook

`/api/formstead-webhook` checks the Standard Webhooks signature before it does anything else. The secret is `FORMSTEAD_WEBHOOK_SECRET` (`whsec_` plus base64). The timestamp has to be within five minutes. `webhook-signature` may contain more than one `v1` value. The comparison uses `crypto.timingSafeEqual`.

When `FORMSTEAD_API_BASE`, `FORMSTEAD_API_USER`, and `FORMSTEAD_API_PASSWORD` are all set, a valid webhook then requests `GET /submissions/{receipt_id}` with that application password. Without those values the route returns `204` after the signature checks.

`npm test` checks the signature against `tests/fixtures/webhook-vector.json`, the same vector the PHP tests use.

CORS, the admin API, and the webhook request are described in [Integrations](../../docs/integrations.md).
