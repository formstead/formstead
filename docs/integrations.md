# Integrations

This note is how another site posts a form, how an authenticated client reads submissions, and how a webhook receiver checks a delivery. Storage and deletion are in [data-flows.md](data-flows.md). The controls are in the [threat model](threat-model.md).

Examples: [HTML](../examples/html/README.md), [Astro](../examples/astro/README.md), and [Next.js](../examples/next/README.md). There is no SDK.

## Posting a form

The public route is `POST /wp-json/formstead/v1/forms/{public_form_id}/submissions`. With plain permalinks the same route is `/?rest_route=/formstead/v1/forms/{public_form_id}/submissions`. The public form id is an address, not a secret. Copy it from the form screen.

Send URL-encoded, multipart, or JSON. A client that sends `Accept: application/json` receives the receipt id. Other clients are redirected or shown an HTML page. Send a new `Idempotency-Key` for each submission. The same key and body return the original result for 24 hours. A different body with that key is `409`.

`422` means a field failed validation. `429` means the rate limit, and the response includes `Retry-After`. `413` means the body or the field count is too large.

### Origins

The form's allowlist is a list of origins: scheme, host, and port. `http://localhost:4321` and `http://127.0.0.1:4321` are different origins. An empty allowlist accepts the WordPress site's own origin.

A browser on an allowed origin receives CORS headers for `POST` and `OPTIONS` only. The response does not send `Access-Control-Allow-Credentials`. Allowed request headers are `Content-Type` and `Idempotency-Key`. `Retry-After` and `Idempotent-Replayed` are exposed.

A request with no `Origin` header is allowed. That is the path for a server-side client, including the Next.js route handler. The request still counts toward the rate limit. `Origin: null` is refused.

CORS is not authentication. Anyone who can reach the route can try to submit.

## Admin API

Authenticated clients use `formstead/v1/submissions`. Address a submission by its receipt id (`fsr_` and 16 characters). The database id is not in the URL or the body.

WordPress application passwords are the client this API is written for. Create one under the user's profile. Send it with HTTP Basic authentication. Any other WordPress authentication that sets the current user also works. A logged-in browser cookie without the REST nonce does not.

The route requires HTTPS. A request without TLS gets `403` and `https_required`, unless `wp_get_environment_type()` is `local` or `development`. Production and staging do not get that exception. `is_ssl()` is false behind some proxies; those hosts should terminate TLS so WordPress sees HTTPS, or the environment check will refuse the call.

These routes send no `Access-Control-*` headers. A preflight does not gain any. A page on another origin cannot read the response in a browser.

| Request | Capability | Effect |
| --- | --- | --- |
| `GET /wp-json/formstead/v1/submissions` | `formstead_read_submissions` | One page of submissions. |
| `GET /wp-json/formstead/v1/submissions/{receipt_id}` | `formstead_read_submissions` | One submission. Does not mark it read. |
| `PATCH /wp-json/formstead/v1/submissions/{receipt_id}` | `formstead_read_submissions` | Sets `status`. Same capability as the inbox status control. |
| `DELETE /wp-json/formstead/v1/submissions/{receipt_id}` | `formstead_delete_submissions` | Deletes the submission, cancels a waiting delivery, and writes an audit row with `source` `api`. |

List filters are `form` (public id), `status` (`new`, `read`, `spam`, `archived`), `after` and `before` (`Y-m-d`, UTC), `email` (the exact normalized address, the same match as inbox search), `page`, and `per_page`. `per_page` defaults to 20 and stops at 100. The response headers `X-WP-Total` and `X-WP-TotalPages` give the size of the filtered set. Responses send `Cache-Control: no-store`.

A submission looks like this:

```json
{
  "receipt_id": "fsr_bbbbbbbbbbbbbbbb",
  "form": { "public_id": "fs_ccccccccccccccc", "name": "Contact" },
  "status": "new",
  "created_at": "2026-01-01 00:00:00",
  "fields": [
    { "name": "email", "label": "Email", "value": "ada@example.com" }
  ]
}
```

Labels come from the schema snapshot stored with the submission, then from the current form. A choice list is an array of strings. The list and the single read both include fields. That is separate from the webhook option below.

No user is `401` `unauthenticated`. A user without the capability is `403` `forbidden`. An unknown receipt or public id is `404`. A bad status or date is `400`. The body is `{ "error": { "code", "message" } }`.

`DELETE` returns `{ "deleted": 1 }`.

## Webhooks

Each form has a Webhook section on the form screen. The URL must be `https`, with no username or password in it, on port 443. The plugin resolves the name to A and AAAA records and refuses a destination that is not a public address. That includes loopback, private ranges, link-local, CGNAT (`100.64.0.0/10`), multicast, reserved ranges, `169.254.169.254`, IPv4-mapped IPv6, and `2002::/16`. The same check runs when the URL is saved and again before every attempt.

The first time a URL is saved, the plugin generates a secret: `whsec_` plus base64 of 32 random bytes. It is stored on the form so the plugin can sign. People with `formstead_manage_forms` can see it on the form screen. Rotate secret invalidates the old secret immediately. Rotation and a change to the URL or the content option are audited. The audit row does not contain the URL or the secret.

Include submission fields is off by default. Turn it on only when the receiver should get the field values.

A submission that is stored as spam does not queue a webhook, just as it does not queue mail. A form with both a URL and recipients gets two deliveries. One can fail while the other is handed off. `wp formstead deliveries run` sends both. A site with little traffic should run that command from system cron.

Deleting the submission cancels a webhook that has not been sent.

### Request

The body is JSON. `type` is `submission.created`. The rest is the receipt id, the form public id and name, the status, and `created_at`. `fields` is present only when the form includes content, in the same shape as the admin API.

Headers:

- `Content-Type: application/json`
- `webhook-id` — `fse_` and 16 characters. The same value on every attempt for that delivery.
- `webhook-timestamp` — Unix time of this attempt, in seconds.
- `webhook-signature` — `v1,` plus base64 HMAC-SHA256.

The signed text is `{webhook-id}.{webhook-timestamp}.{raw body}`. The key is the secret with `whsec_` removed, then base64-decoded. Compare with a constant-time function. Reject a timestamp more than five minutes from now. The [Next.js example](../examples/next/README.md) does this, and `examples/next` tests the same vector as the PHP suite (`tests/fixtures/webhook-vector.json`).

The plugin does not follow a redirect. A `3xx` fails the delivery. The receiver's body is not stored and is not written to the error log. A `2xx` is handed off.

### Failures

A delivery is attempted at most five times. After a failure the waits are 1 minute, 5 minutes, 30 minutes, and 2 hours.

| Code | What happened | Retried |
| --- | --- | --- |
| `webhook_blocked_url` | The URL is not allowed, or the secret cannot sign. | No |
| `webhook_redirect` | The receiver answered `3xx`. | No |
| `webhook_unsafe_transport` | The host cannot enforce a direct connection to the checked address. | No |
| `webhook_http_4xx` | The receiver answered another `4xx`. | No |
| `webhook_timeout` | The connection exceeded 5 seconds. | Yes |
| `webhook_connect_failed` | The connection could not be opened. | Yes |
| `webhook_http_429` | The receiver answered `429`. | Yes |
| `webhook_http_5xx` | The receiver answered `5xx`. | Yes |

Webhook requests require PHP cURL with HTTPS support. The transport is selected explicitly, pins the checked address, and connects directly without an HTTP proxy. If pinning cannot be enforced, `webhook_unsafe_transport` fails the delivery without retry. Receiver response bodies are limited to 1,024 bytes and discarded.

The filter `formstead_webhook_allowed_hosts` can allow a host, `host:port`, or `[ipv6]:port` to use HTTP, another port, or a private address only when `FORMSTEAD_E2E` is enabled and the environment is `local` or `development`. Staging and production ignore that exception. It exists for isolated tests.
