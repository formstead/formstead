# Operating Formstead

Use [Your first form](getting-started.md) for initial setup and [API contract](api.md) for client behavior. This guide covers the WordPress installation that stores submissions.

## Cron and delivery

WP-Cron needs incoming traffic. On a quiet site, configure the host's scheduler to run these commands with the site's PHP version and filesystem user. Replace `/path/to/wordpress` with the real installation directory. Avoid overlapping runs of the delivery command.

```sh
wp --path=/path/to/wordpress formstead deliveries run
wp --path=/path/to/wordpress formstead retention run --batches=10
```

Run deliveries every minute or five minutes, according to the delay you accept; run retention hourly. An unbounded `retention run` drains an existing backlog from the command line. Keep the host's ordinary WordPress cron working for other plugins. Do not disable WP-Cron without replacing the site's complete scheduled-task processing.

Deliveries are recorded with the submission before a success response. A failed mail or webhook attempt does not remove it. Delivery statuses distinguish waiting, handed off, failed, and cancelled. Handed off email means `wp_mail()` accepted it, not that the recipient read or received it. Configure SMTP and sender-domain settings through the host or a mail plugin; Formstead does not store SMTP passwords.

Email and webhook are separate jobs. Temporary failures retry at most five attempts, waiting one minute, five minutes, thirty minutes and two hours between attempts. A blocked URL, redirect, unsafe webhook transport or another non-retryable 4xx fails immediately. See the [webhook error table](integrations.md#failures).

Webhook delivery requires PHP cURL with HTTPS support. It connects directly to the checked address; HTTP proxy settings are not used for that connection. Missing transport support fails the webhook safely while submissions and email remain available. Keep certificate verification enabled. A receiver must verify the signature and timestamp and deduplicate the stable event id: delivery is not guaranteed exactly once.

## Retention and deletion

Each form defaults to 30 days. `0` keeps its ordinary submissions until someone deletes them. Spam has a separate default of seven days, completed deliveries thirty days, and audit events 180 days. Expired idempotency keys and rate counters are also cleaned up. Settings shows the last cleanup, the next scheduled cleanup, and an overdue warning.

Deletion cancels deliveries that are still waiting or processing and removes the submission, its identity entries and idempotency keys. Cancelled delivery metadata can remain until its own retention expires. A worker that already started an external request cannot retract it; mail, receivers and host backups may keep their own copies.

Email fields marked as identifying are indexed for exact-address search and WordPress's **Tools → Export/Erase Personal Data**. Text fields are not searched for hidden addresses. After an upgrade or a change to identifying fields, Settings may report an unfinished index. Finish it before processing a privacy request:

```sh
wp --path=/path/to/wordpress formstead identities rebuild
wp --path=/path/to/wordpress formstead identities rebuild --form=PUBLIC_FORM_ID
```

An export fixes its highest submission id at its first page and remembers page boundaries on the WordPress privacy request. Deletion cannot shift the next page. New submissions after that boundary need another export. Repeating a page uses the same id range and reads only values that still exist. Temporary boundaries contain ids, not payloads or addresses; they are removed when WordPress builds the export file or deletes the request. Core manages the resulting export file and its lifetime.

## Permissions

Administrators receive four capabilities at activation or migration. To delegate, use the site's role-management process and grant only the capability the person needs.

| Capability | Access |
| --- | --- |
| `formstead_manage_forms` | Form configuration, webhook secret, settings that use this capability, and audit log |
| `formstead_read_submissions` | Inbox, details, status changes and corresponding admin API reads/status changes |
| `formstead_export_submissions` | CSV export; reading and searching the inbox still require the read capability |
| `formstead_delete_submissions` | Permanent submission deletion |

WordPress privacy tools use `export_others_personal_data` and `erase_others_personal_data`. The uninstall deletion setting requires `activate_plugins`. Form management alone does not grant submission access. Protect application passwords like the user's access: revoke a compromised one in the user's WordPress profile. A leaked webhook secret should be rotated on the form and replaced at the receiver.

## Limits and proxies

`FORMSTEAD_MAX_REQUEST_BYTES`, `FORMSTEAD_MAX_FIELDS`, `FORMSTEAD_RATE_PER_MINUTE`, `FORMSTEAD_RATE_PER_HOUR`, `FORMSTEAD_MIN_FILL_MS`, and `FORMSTEAD_DUPLICATE_WINDOW` can be defined in `wp-config.php`. Values are clamped to the ceilings in `Limits`; `0` disables only the timing or duplicate-content signal. Do not remove protections just to avoid investigating a 429.

`REMOTE_ADDR` is used unless the peer is in the explicitly configured `FORMSTEAD_TRUSTED_PROXIES` list. Configure only trusted proxy addresses. A forwarded header from an untrusted client must not choose the rate-limit identity. If TLS terminates at a reverse proxy, configure WordPress to recognize HTTPS through that trusted setup; do not label a deployed site `local` to bypass the API's TLS check.

## Updates, backup and recovery

Before an update, back up the database and the installed plugin files together. Record the current version, retention policy and any pending jobs. Test the new ZIP on a copy first. Updating old forms can start enforcing their default retention, so review the policy before enabling cron.

The database has its own version, independent of the plugin version. A failed migration is reported without its database message and is not marked complete. Resolve database permissions/storage errors and retry. Do not repeatedly deactivate or uninstall to force an upgrade.

For recovery after a schema-changing update, restore a matching backup of database and plugin files. Replacing PHP files alone is not a database rollback. Pause outbound delivery during recovery; an old backup can contain a job that a receiver already processed. Receivers should deduplicate event ids.

Deactivation retains data and capabilities and removes scheduled Formstead work. Reactivation recreates the schedules. Uninstall keeps data by default. If an administrator explicitly enabled deletion on uninstall, Formstead removes its tables, options, capabilities, scheduled work and temporary export boundaries. It does not remove WordPress's privacy requests, existing export files, server logs, backups, sent email or data at webhook receivers.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Public endpoint is 404/blocked before Formstead | Copy the current endpoint, check whether the form is enabled, then inspect REST/security-plugin and WAF rules. Test the plain-permalink URL if necessary. |
| Browser receives a CORS error | Exact frontend origin including scheme and port, preflight response, CDN/header overrides. Do not add credentials to the public request. |
| Admin API returns `https_required` | HTTPS recognition at WordPress behind the trusted proxy; environment must reflect the deployed site. |
| Email remains pending | Scheduler and `deliveries run`; inspect the delivery status, then test the site's mail setup. |
| Webhook fails immediately | Public HTTPS destination, cURL availability, TLS trust, direct network access, no redirect, and current receiver secret. |
| Retention is overdue | Host scheduler, WP-CLI exit code, Settings report, and database permissions. Drain the backlog with the bounded CLI command first. |
| Privacy export/erase refuses to run | Finish or repair the identity index; do not interpret an index error as an empty result. |

When reporting a problem, include versions and stable error codes. Remove submission content, addresses, IPs, application passwords, webhook secrets and private URLs from reports. The complete storage inventory is in [Data flows](data-flows.md).
