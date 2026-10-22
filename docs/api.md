# API contract

The namespace is `formstead/v1`. Copy URLs from the form screen: WordPress installations without pretty permalinks use `/?rest_route=/formstead/v1/...` instead of `/wp-json/formstead/v1/...`. Examples below use pretty permalinks.

## Public intake

`POST /wp-json/formstead/v1/forms/{public_form_id}/submissions`

The form id is `fs_` followed by 16 lowercase base32 characters. This route accepts submissions without authentication; it never returns stored submissions. Unknown or disabled forms return `404`.

Accepted media types are `application/x-www-form-urlencoded`, `multipart/form-data` without files, and `application/json`. JSON must be a UTF-8 object. A file part is refused rather than silently discarded. Unknown field names are ignored and not stored, but still count toward the raw input limit. Names beginning `_fs_` are reserved.

Send `Accept: application/json` to request JSON. A browser's ordinary HTML Accept header selects the HTML response. There is no receipt lookup on the public route.

```http
POST /wp-json/formstead/v1/forms/fs_abcdefghijklmnop/submissions
Content-Type: application/json
Accept: application/json
Idempotency-Key: contact-unique-attempt-1

{"email":"person@example.com","message":"Hello"}
```

```json
{"receipt_id":"fsr_abcdefghijklmnop"}
```

The success status is `201` for JSON, or `303` with `Location` for HTML. The receipt contains no field values. A storage failure never returns success. Honeypot and spam responses deliberately look like ordinary success; see [limits and spam](#limits-and-spam).

## Values

The form schema sets field names, types, required flags, lengths and allowed values. Names match `[a-z][a-z0-9_]{0,63}`. Text is trimmed and checked as UTF-8. Textarea line endings become LF. Optional empty text-like fields are omitted; a boolean is stored as true or false.

| Type | Accepted value and normalization |
| --- | --- |
| `text` | String; JSON number tokens can be read as text. Default maximum 255 characters. |
| `email` | Valid email text, trimmed. Default maximum 255 characters. Identity search separately normalizes case. |
| `textarea` | String allowing line breaks; default maximum 10,000 characters. |
| `number` | Decimal text or a JSON number token, with configured scale/minimum/maximum. Stored as canonical decimal text to avoid floating-point rounding. |
| `boolean` | JSON boolean, or text `1`, `on`, `true`, `0`, `off`, `false`, ignoring case. Missing means false. Required means it must be true. JSON numeric `1` is not a boolean. |
| `choice` | One configured string value, or a list of configured string values when multiple selection is enabled. HTML uses repeated `name[]` values. Duplicates are removed. |

Control characters and values of the wrong shape are refused. Each submission retains its field-schema version; later edits do not relabel historical data when its snapshot exists.

## Errors

JSON errors use the following shape. Messages may be translated; clients should inspect stable codes.

```json
{"error":{"code":"invalid_fields","message":"Check the form and try again.","fields":{"email":["invalid_email"]}}}
```

| HTTP status | Error code |
| --- | --- |
| 400 | `malformed_body`, `invalid_idempotency_key` |
| 403 | `origin_not_allowed` |
| 404 | `form_not_found` |
| 409 | `idempotency_conflict` |
| 413 | `payload_too_large` |
| 415 | `unsupported_media_type`, `files_not_supported` |
| 422 | `invalid_fields` with a `fields` map |
| 429 | `rate_limited`, with `Retry-After` in seconds |
| 500 | `storage_failed` |

Field error codes are `required`, `too_long`, `invalid_email`, `invalid_number`, `too_small`, `too_large`, `too_precise`, `invalid_choice`, and `invalid`. HTML errors use the same HTTP status with an accessible page. Submitted values are not echoed into the public error page or URL. WordPress, a proxy or a WAF can refuse a request before Formstead runs and may use another error shape.

## Idempotency

Use a fresh `Idempotency-Key` for each logical submission, or `_fs_idempotency_key` in an HTML body. Keys are 1–255 printable ASCII characters without spaces; the header wins when both are supplied. An empty form field means no key. Do not reuse one key for every visitor. Within 24 hours, the same key and normalized content return the original success result and `Idempotent-Replayed: true`; changed content with that key returns `409`. Parallel retries do not create another submission or another delivery per channel. Removing a submission also removes its idempotency claim. Retry a temporary failure with the same logical key; follow `Retry-After` for rate limits.

## Limits and spam

Defaults: 65,536 request bytes, 50 raw fields/array elements, JSON depth 8, 5 submissions per IP HMAC and form per minute, and 100 per form per hour. The host can set [global limits](operations.md#limits-and-proxies); a form can override the two rate limits.

- `_fs_honeypot`: a filled value returns apparent success without storing or sending.
- `_fs_elapsed`: optional milliseconds since the form was shown. Below 3,000 marks the stored submission as spam. Omitting it keeps the no-JavaScript path usable.
- Identical normalized content on the same form within ten minutes is another spam signal. Stored spam queues neither email nor webhook. Changing its status later does not send a skipped notification.

The timing and duplicate signals are configurable. They are not proof that a sender is human. CORS permits browser access only from configured origins; it does not authenticate a sender. Requests without `Origin` are allowed for server clients and still rate limited. `Origin: null` is refused. See [Integrations](integrations.md#origins) for preflight and exposed headers.

## Authenticated administration

The administrative routes list, read, update status, and delete by receipt id. They require a WordPress user and the appropriate capability, plus HTTPS outside `local` and `development`. A WordPress application password is suitable for server clients. Browser cookie requests still need the WordPress REST nonce. These routes grant no CORS access and use `Cache-Control: no-store`.

[Integrations](integrations.md#admin-api) specifies every route, filter, response field and capability. Lists default to 20 entries and accept at most 100 per page. `GET` does not mark a submission read. `DELETE` cancels waiting deliveries through the same service as inbox deletion, retention and personal-data erasure. It cannot retract an already sent message.

Webhook payloads, Standard Webhooks signatures, stable event ids, receiver timestamp checks, and retry limits are described in [Integrations](integrations.md#webhooks).
