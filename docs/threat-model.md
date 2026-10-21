# Threat model

This note describes the Formstead plugin, including personal-data export and erase and the audit log. It is not a certification, and it does not cover the host, WordPress core, or other plugins. What is stored, and how it is removed, is in [data-flows.md](data-flows.md).

Report a vulnerability to security@formstead.dev. See [SECURITY.md](../SECURITY.md).

## Assets

- Submission text, stored as JSON, and the receipt id handed back to the sender.
- Form configuration: fields, success URL, origin allowlist, recipients, and retention.
- Idempotency keys, stored only as SHA-256 hashes, and rate-limit counters.
- The HMAC secret used to hash client addresses. It rotates about once a day. The raw address is not stored on the submission.
- The separate HMAC secret used to index identifying email fields. It does not rotate. The index stores the HMAC, not the address. The address remains in the submission JSON.
- The audit log: event codes, user ids, and short context. It does not store field values, email addresses, or IP addresses.
- Mail handed to the site's mail system. A successful hand-off is not proof the mailbox received it.

The public form id is an address, not a secret. Anyone who knows it can try to submit.

## Trust boundaries

| Boundary | What it is trusted to do |
| --- | --- |
| Person submitting a form | Send field values. Not trusted to choose the success URL, skip validation, or read other submissions. |
| Browser on another site | Send `Origin`. CORS is not authentication. A missing `Origin` is allowed, because server-side clients often send none. `Origin: null` is refused. |
| WordPress administrator session | Configure forms and handle submissions, but only with the capability for that action. A logged-in cookie without the REST nonce is not treated as that user on the public route. |
| WP-Cron and WP-CLI | Run delivery, retention, and the identity-index backfill. They use the same deletion and delivery code as the admin screens. |
| WordPress personal-data tools | Export or erase one email address. WordPress checks `export_others_personal_data` or `erase_others_personal_data` before it calls Formstead. Those are core capabilities, not Formstead capabilities. |
| Site mail system | Accept a message. `From` stays with the site. `Reply-To` is a validated email field, with line breaks removed. |
| Host backups and server logs | Outside the plugin. Deleting a row does not delete a backup. |

## Entry points

- `POST /wp-json/formstead/v1/forms/{public_form_id}/submissions`, and the same route as `/?rest_route=`.
- `OPTIONS` preflight for that namespace.
- Formstead screens in wp-admin: forms, inbox, submission detail, CSV export, audit log, and Settings.
- Inbox search by an exact email address (`s` on the inbox URL).
- Tools → Export Personal Data and Erase Personal Data.
- `wp formstead deliveries run`, `wp formstead retention run`, and `wp formstead identities rebuild`.
- Uninstall, which deletes data only when the site has opted in.

The public route does not list, export, or change stored submissions.

## Controls

| Threat | Control | Checked by |
| --- | --- | --- |
| Read or change submissions through the public route | The route only accepts `POST`. A browser check requests `GET` and does not see the submitted value. | `tests/e2e/smoke.spec.ts` |
| Send the browser to an address chosen in the request | The success URL is the one saved on the form. | `tests/Rest/SubmissionsControllerTest.php` |
| Store an unknown field or a value that fails validation | Unknown fields are dropped. Invalid fields return 422 and store nothing. The HTML error page does not repeat the submitted value. | `tests/Rest/ContractTest.php`, `tests/e2e/html.spec.ts` |
| Oversized body, too many fields, or deep JSON | Rejected with 413 before the submission is stored. | `tests/Rest/ProtectionTest.php` |
| Flood a form | Per-address and per-form counters. Excess requests get 429 and `Retry-After`. A failed counter write does not block a submission that is otherwise allowed. | `tests/Rest/ProtectionTest.php`, `tests/e2e/failures.spec.ts` |
| Spoof the client address | The address comes from `REMOTE_ADDR` unless a trusted proxy is configured. | `tests/Rest/ProtectionTest.php` |
| Cross-site reads | Allowlist of scheme, host, and port. Responses do not send `Access-Control-Allow-Credentials`. Core CORS for other routes is left alone. | `tests/Rest/CorsTest.php`, `tests/e2e/html.spec.ts`, `tests/e2e/astro.spec.ts` |
| Automated posts that fill the honeypot | Pretend success, store nothing, and count the rejection. A form without JavaScript still works. | `tests/Rest/ProtectionTest.php`, `tests/e2e/html.spec.ts` |
| Very fast or repeated posts | Stored as spam and not mailed. The sender still sees the normal success response. | `tests/Rest/SpamSignalsTest.php`, `tests/e2e/astro.spec.ts` |
| Double submit | The same idempotency key and body replay the original result for 24 hours. A different body is 409. A lock conflict is retried in the request. | `tests/Rest/IdempotencyTest.php`, `tests/e2e/failures.spec.ts` |
| Lose a submission when mail fails | The submission is stored first. Mail is a delivery row. A failed hand-off stays pending with `mail_failed` and does not retry forever. | `tests/Delivery/DeliveryWorkerTest.php`, `tests/e2e/failures.spec.ts` |
| Inject mail headers from a field | The subject has no field values. `Reply-To` must be a single valid email. | `tests/Delivery/EmailChannelTest.php` |
| Script injection in the inbox | Submission text is escaped before it is printed. | The submission template escapes values. A dedicated injection pass is part of later hardening. |
| Formula injection in CSV | Cells that start with `=`, `+`, `-`, `@`, tab, or CR get a quote prefix. Export requires `formstead_export_submissions`. | `tests/Submissions/CsvExportTest.php`, `tests/Admin/SubmissionActionsTest.php` |
| Delete or export without the capability | Each admin action checks its own capability and a nonce. Reading does not grant export or deletion. | `tests/Admin/SubmissionActionsTest.php` |
| Send mail after the submission is gone | Deletion cancels pending deliveries and removes the idempotency key in one transaction. | `tests/Submissions/SubmissionDeleterTest.php` |
| Keep data past the configured period | Retention deletes through that same deletion path. Pending deliveries are left for the worker. | `tests/Retention/RetentionRunnerTest.php` |
| SQL built from request text | Queries go through `$wpdb->prepare()`. Table names are fixed identifiers. | A dedicated injection pass is part of later hardening. |
| Wipe a site on uninstall by surprise | Data is removed only when an administrator who can activate plugins has opted in. | `tests/Lifecycle/UninstallerTest.php` |
| Network activation on Multisite | Activation as a network plugin is refused. | `tests/Lifecycle/ActivatorTest.php` |
| Export or erase while the identity index is unfinished | Both tools return an error and do not report a partial result. The message names `wp formstead identities rebuild`. | `tests/Privacy/PrivacyToolsTest.php` |
| Export or erase when the identity index cannot be read | Both tools return an error. Erasure does not report that it finished. | `tests/Privacy/PrivacyToolsTest.php` |
| Miss a person who has more submissions than one page | Export pages 100 rows at a time until a short page. Erase deletes one page of 100 and reads the first page again, because the previous page is gone. | `tests/Privacy/PrivacyToolsTest.php` |
| Erase one person and leave someone else, or leave a waiting notification | Erasure deletes the whole submission through the inbox deletion path, including the index row. Another person's rows stay. A pending delivery is cancelled. | `tests/Privacy/PrivacyToolsTest.php`, `tests/e2e/privacy.spec.ts` |
| Find a person by scanning every submission | Inbox search and the privacy tools match an HMAC of the normalized address. The query uses the `hash_submission` index. | `tests/Submissions/SubmissionIndexTest.php`, `tests/e2e/inbox.spec.ts` |
| Store a field value or an email address in the audit log | Context keeps numbers, booleans, and short codes. A failed audit insert does not undo the action and does not write to the error log. | `tests/Audit/AuditLogTest.php` |
| Read the audit log without managing forms | The screen requires `formstead_manage_forms`. | `tests/Admin/AuditPageTest.php` |
| Keep audit rows past their period | Retention deletes rows older than 180 days. A row at the cutoff stays. | `tests/Retention/RetentionRunnerTest.php` |

A focused pass over injection, open redirect, and the other Core 1.0 abuse cases is part of later hardening. The table is what this version already checks.

## Not in this version

These are intentionally absent. They are not silent controls.

- An authenticated admin API. The inbox is the WordPress screen.
- Webhooks. There is no outbound request to a form-configured URL, and no SSRF surface of that kind yet.
- File uploads, captcha, and Multisite as a supported layout.

## Operating limits

WP-Cron runs when the site is visited. A site that must send mail or delete old submissions on time should call the WP-CLI commands from system cron. Settings warns when retention looks overdue.

The plugin does not encrypt submissions at rest and does not manage host backups. Mail leaves the site only when a form has recipients, or when the host's own mail path sends it.

An inbox search puts the email address in the page URL. Server logs and the browser history can keep it after the submission is gone. Details are in [data-flows.md](data-flows.md).
