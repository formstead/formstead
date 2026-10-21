# Data flows

This note describes what Formstead stores, where it lives, how long it stays, and how it is removed. It is a description of the plugin. It is not a statement that a site meets any privacy law.

Report a vulnerability to security@formstead.dev. See [SECURITY.md](../SECURITY.md). The controls are in the [threat model](threat-model.md).

## Submissions

A public `POST` stores one row in `wp_formstead_submissions` (the WordPress table prefix may differ).

The row holds:

- the field values as JSON text, including any email address the person typed
- a receipt id
- the form id and the field-schema version that was current
- a status (`new`, `read`, `spam`, or `archived`)
- the time it was stored, in UTC
- a SHA-256 hash of the canonical content

The content hash stays for the life of the row. It is used to recognize the same content on the same form within 10 minutes (`FORMSTEAD_DUPLICATE_WINDOW`, 600 seconds by default). The visitor still receives the normal success response; the row is stored as spam and is not emailed.

The visitor IP address is not a column on the submission.

## Client address

Rate limiting hashes the client address with HMAC-SHA256 and a secret in the option `formstead_ip_secret`. The secret is not autoloaded. It rotates on the next request once it is a day old. The counter lives in `wp_formstead_rate_limits` and expires with its window (one minute for the address, one hour for the form). Retention deletes expired counters. The raw address is not stored. The host's own logs may still contain it.

## Identity index

Email fields marked "Identifies the person" are indexed in `wp_formstead_identities`. A new email field is marked by default. An update to schema version 7 marks email fields that already exist.

The index stores the submission id, the form id, the field name, and an HMAC-SHA256 of the address. Normalization is `trim` and `strtolower` of the whole address. A `+tag` and dots in the local part are kept, so `ada+tag@example.com` and `ada@example.com` are different people.

The HMAC secret is the option `formstead_identity_secret` (`random_bytes( 32 )`, not autoloaded). It does not rotate. Rotating it would make every existing row unusable. This is not `formstead_ip_secret`.

The address itself is not written in the index. It remains in the submission JSON.

Only fields of type `email` can be identifying. A text field is not indexed, even when the value looks like an email address. Spam is indexed, because it is still that person's data.

The index row is written in the same database transaction as the submission. If the index insert fails, the submission is not kept.

Rows that existed before a field was marked are indexed in the background, in batches of 500, by the cron event `formstead_identity_backfill` or by `wp formstead identities rebuild`. The migration does not index them inline. Settings shows whether that job is finished. Removing the mark deletes that field's index rows immediately.

## Inbox search

The inbox has a "Search by email" field for people with `formstead_read_submissions`. It matches the exact normalized address through the index. It does not scan submission JSON.

The address is sent as the `s` query parameter, the same way WordPress search does on `users.php`. It can therefore appear in the browser history, in server access logs, and in a `Referer` header if the person follows a link away from that screen.

## Personal-data export and erase

WordPress Tools → Export Personal Data and Erase Personal Data call Formstead. WordPress checks `export_others_personal_data` and `erase_others_personal_data` before it calls the plugin. Formstead capabilities are not the gate for these tools.

Both tools look up the address in the identity index. While the backfill is unfinished they return an error and do not report a partial result. The message asks for `wp formstead identities rebuild`. A failed read of the index returns an error as well, instead of an empty export or a finished erasure.

Export returns pages of 100 submissions. Each item includes the receipt id, the form name, the date, the status, and the field values with labels from the schema snapshot. The group name is "Formstead submissions".

Erase deletes the whole submission through the same deletion path as the inbox. That cancels a waiting notification, removes the idempotency key, and removes the index rows, then deletes the submission. It does not leave the row in place with the fields blanked. Each call deletes one page of 100. It reads the first page again, because the previous page is already gone.

A submission whose address sits only in a non-email field is not found by these tools. It is still in the inbox until retention or a person deletes it.

## Mail

A form can list notification recipients. After a submission is stored, a row in `wp_formstead_deliveries` is queued and WP-Cron hands it to `wp_mail()`. The delivery row stores the channel, the status, and error codes. It does not store the message body. The body is built from the submission at send time: an inbox link, plus the field values only when the form has "include content" on. The subject does not include field values. `From` stays with the site mail setup. `Reply-To` is one validated email field, with line breaks removed.

A failed hand-off stays pending. It does not retry forever. A site with little traffic should run `wp formstead deliveries run` from system cron.

Mail that the site mail system has already accepted is outside the plugin. Deleting the submission later does not recall it. Deleting the submission first cancels a delivery that has not been sent.

## Webhooks

A form can set an HTTPS webhook URL. After a submission is stored, a second row in `wp_formstead_deliveries` is queued when that URL is set. Spam does not queue one. The row stores the channel `webhook`, the status, an error code, and an event id (`fse_` and 16 characters). It does not store the request body, the URL, the secret, or the receiver's response.

The body is built at send time. It names the event, the receipt id, the form, the status, and the time. Field values are included only when the form has "Include submission fields" on. That option is off until someone turns it on. The same cron and `wp formstead deliveries run` send webhooks and mail. They are separate rows, so one can fail while the other is handed off.

The signing secret is `whsec_` plus base64, in `forms.webhook_secret`, in plaintext, because the plugin has to sign. People who can manage forms see it on the form screen. Rotate secret replaces it immediately. The audit row for that rotation, and the audit row for a URL or content-option change, do not contain the secret or the URL.

A receiver that has already accepted the request is outside the plugin. Deleting the submission cancels a webhook that has not been sent. It does not recall one that was handed off. The secret is in the database, so it is in host and database backups until it is rotated and those copies age out.

## Admin API

`GET`, `PATCH`, and `DELETE` on `formstead/v1/submissions` read or change the same submission rows as the inbox. A read does not mark a submission read and does not write a second copy. The response includes the field values. `DELETE` uses the inbox deletion path and adds `source: api` on the audit row. The request itself is not stored.

## Audit log

`wp_formstead_audit` records that an action happened. The row stores an event code, the user id (or `0` when cron or WP-CLI has no user), an object type and id, a time, and a JSON context of numbers, booleans, and short codes such as setting names.

The context is filtered before insert. A field value, an email address, an IP address, a webhook URL, or a webhook secret is dropped. The events are: a form was created or updated, a webhook secret was rotated, settings were updated, submissions were exported or deleted, retention ran, and personal data was exported or erased.

The row is written after the action has succeeded. A failed insert does not undo the action and does not write to the PHP error log.

People with `formstead_manage_forms` can read the log under Formstead → Audit log. A removed user is shown as "User #id". User `0` is shown as "Automatic".

Audit rows older than 180 days are deleted by the same retention run as submissions (`FORMSTEAD_AUDIT_RETENTION_DAYS`, filter key `audit_days` on `formstead_retention_periods`, clamped to 1–3650).

## How long rows stay

| Data | How long | Removed by |
| --- | --- | --- |
| Submission, not spam | Days set on the form. Default 30. Zero means until someone deletes it. | Retention, inbox delete, personal-data erase, or uninstall when the site opted in |
| Spam submission | 7 days (`FORMSTEAD_SPAM_RETENTION_DAYS`), even when the form keeps other rows longer | The same paths |
| Identity index row | As long as its submission, or until that field is no longer identifying | The submission's deletion, or clearing the mark |
| Finished delivery | 30 days (`FORMSTEAD_DELIVERY_RETENTION_DAYS`) | Retention |
| Idempotency key | 24 hours, stored only as SHA-256 hashes of the key and the body | Retention, or deletion of the submission |
| Rate-limit counter | Until the minute or hour window ends | The limiter, then retention |
| Audit row | 180 days | Retention |
| Form configuration | Until the form is deleted or the site uninstalls with the option on | The forms screen, or uninstall |

Retention runs hourly on WP-Cron, or when someone runs `wp formstead retention run`. Settings shows the last run and warns when it looks overdue. A site that must delete on time should call that command from system cron.

## Uninstall

Uninstall drops the Formstead tables and deletes options and transients whose names start with `formstead_`, including both HMAC secrets, only when an administrator who can activate plugins has opted in. Without that option, deactivation and uninstall leave the rows in place.

## Outside the plugin

These copies are not updated when a row is deleted:

- host and database backups
- web server access logs, including an inbox search that put an email address in the URL
- PHP and mail logs the host keeps
- a notification the mail system has already accepted
- a webhook the receiver has already accepted
- the webhook signing secret, which is stored on the form and therefore copied with the database
- the WordPress record of an export or erase request, which core stores with the address the administrator entered

The plugin does not encrypt submissions at rest.
