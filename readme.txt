=== Formstead ===
Contributors: formstead
Tags: forms, rest-api, headless, submissions
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receive form submissions from static and headless sites on an existing WordPress install.

== Description ==

Formstead receives form submissions from static and headless sites on an existing WordPress install. Plain HTML, Astro, and Next.js post to WordPress. WordPress stores submissions in a private inbox. The site frontend stays with its author.

The form editor generates starter code for HTML, JavaScript / fetch, Astro and React / Next.js from the last saved fields. Copy the code into your frontend and adapt its labels and styling. The fetch variant handles validation errors and retries without a required SDK. HTML and Astro post natively; JavaScript / fetch and React show confirmation on the page and retain native POST without JavaScript. The generator does not provide a finished design, deploy your frontend or synchronize later field changes. Configure the frontend origin and replace copied code after changing fields.

Version 1.0.0 closes a delivery whose worker died after the last attempt, so recovery does not send it again. A hosting pilot of the release candidate exercised a submission from another site, mail, a signed webhook, the submissions API from an administrator session, and personal-data export and erasure. There has been no independent security audit. The source repository includes the author's review at `docs/review.md`. Version 1.0.0-rc.1 hardens webhook transport, stabilizes personal-data export pagination, and verifies historical upgrades. Version 0.9.0 ended Core beta. It adds an authenticated submissions API and a signed webhook for each form. The Next.js example and the integration notes stay in the source repository. Version 0.7.0 is the technical MVP. The source repository includes HTML and Astro examples that post with and without JavaScript, including from another site. The installable package does not include those examples. Version 0.6.0 keeps each form's submissions for a number of days, 30 by default. Forms that already exist get the same 30 days, and the first cleanup deletes older submissions. Use 0 to keep a form's submissions until someone deletes them. Settings shows the last cleanup and warns when it is overdue. The inbox lists submissions; opening a new one marks it read. The list can be filtered by form and date, and exported as CSV for one form. A submission filled in under three seconds, or an identical submission within ten minutes, is stored as spam and is not emailed. The visitor still sees the normal success response. A form can notify recipients by email and can list the sites allowed to submit it. Forms that already exist do not start sending mail until recipients are saved.

Network activation on WordPress Multisite is not supported.

== Privacy ==

Formstead stores submissions on this site. An email field can be marked as identifying the person. That address is indexed as an HMAC so the inbox can search for an exact address and so WordPress can export or erase that person's submissions. The index does not keep a second copy of the address. Erasure deletes the whole submission, including a waiting notification. An audit log records the action, and other form, settings, export, deletion, and retention actions, without the field values or the address. Audit rows are kept for 180 days. Submissions follow the retention period set on the form. A host backup, a server log, and mail already handed to the site mail system are outside the plugin. A form can also send a signed webhook. Field values are included only when that option is on, and the signing secret is stored on the form. The source repository describes these flows in `docs/data-flows.md`. The plugin suggests wording for the site privacy policy.

== Installation ==

1. Upload the `formstead` directory to the `/wp-content/plugins/` directory.
2. Activate Formstead through the Plugins screen. Do not network-activate it.
3. Open Forms, create and save a form, then choose HTML, JavaScript / fetch, Astro or React / Next.js in Integration code and copy it to your frontend. Configure Allowed origins for another site. Submissions are listed under Formstead.

== Frequently Asked Questions ==

= Does Formstead require a Formstead account? =

No. The plugin runs entirely on the WordPress install. There is no required Formstead server, account, or telemetry.

= Can I network-activate Formstead on Multisite? =

No. Multisite is outside Core 1.0. Activate the plugin on a single site instead.

= Can a form work without JavaScript? =

Yes. Choose HTML in Integration code, or copy its endpoint into a normal HTML form's action and use method="post". A valid submission redirects to the success URL saved on the form. The generated Astro component posts natively too. JavaScript / fetch and React / Next.js retain native POST when JavaScript is unavailable. Examples and the full contract are in the source repository's docs and examples directories.

= Why is mail still waiting? =

Deliveries use WP-Cron and the site's mail setup. On a quiet site, schedule wp formstead deliveries run from system cron. A handed-off email has been accepted by the mail system; that does not prove mailbox delivery. Full submission content is included only when the form explicitly enables it.

= What does a webhook require? =

A public HTTPS destination on port 443, PHP cURL with HTTPS support, and direct outbound access. Redirects and private destinations are refused. Unsupported address pinning fails the delivery safely. The receiver must verify the signature and timestamp and deduplicate the event id. Webhooks are optional; email and stored submissions work independently.

= What happens to data on update or uninstall? =

Back up the database and plugin files together before updating. Forms keep their configured retention; the default is 30 days. Uninstall keeps data unless an administrator explicitly enabled deletion. Opt-in deletion removes plugin tables, settings, permissions, scheduled work and temporary export page boundaries. Host backups, sent mail, receiver data and WordPress privacy export files remain outside Formstead.

= Where are the setup and operations instructions? =

The source repository contains docs/getting-started.md, docs/api.md, docs/operations.md, docs/integrations.md and docs/data-flows.md. They cover the first form, CORS, application passwords, cron, retentions, permissions, privacy tools and recovery. The installable ZIP includes this readme; the detailed source guides are not bundled.

== Screenshots ==

1. The private inbox with status views, exact-email search, form and date filters, and CSV export.
2. Form configuration with field types, origin controls, notification options and per-form retention.
3. Settings with cleanup status, privacy-index progress and the explicit uninstall-data choice.

== Changelog ==

= Unreleased =
* Generate starter HTML, JavaScript / fetch, Astro and React / Next.js from saved fields. Include a honeypot, native POST, accessible fetch errors and retry-safe idempotency.
* Copy the selected variant with a manual fallback and show unsaved editor changes without changing the generated source.

= 1.0.0 =
* Close a delivery whose worker died after the last attempt, so recovery does not send it again.
* Record the hosting pilot and the author's review. There has been no independent security audit.

= 1.0.0-rc.1 =
* Require verified cURL address pinning for webhooks and restrict local test exceptions.
* Keep personal-data export pages stable when submissions change during export.
* Refuse to mark failed migrations as complete. Add historical upgrade, concurrency and performance acceptance coverage.
* Add operator and API guides, translation template and license notices.

= 0.9.0 =
* Add an authenticated submissions API addressed by receipt id. It requires HTTPS outside local development, uses a WordPress application password, and sends no CORS headers. Reading a submission does not mark it read.
* Add a signed webhook per form. Private addresses and redirects are refused. A waiting webhook is cancelled when the submission is deleted, and retries stop after five attempts.
* Add a Next.js example and integration notes in the source repository. The installable package does not include them.

= 0.8.1 =
* Index submissions already passed when an email field is marked during a backfill.
* Refuse personal-data export and erase when the identity index cannot be read, instead of reporting that the person has no submissions.
* Queue the backfill even if clearing the index fails. Repair a form already missing that queue with `wp formstead identities rebuild --form=<public_id>`.

= 0.8.0 =
* Index an identifying email field as an HMAC, and search the inbox for that exact address.
* Export and erase one person's submissions from Tools, including when there are more than one page of them.
* Record form, settings, export, deletion, retention, and privacy actions without storing the submission text. Keep that log for 180 days.
* Describe what is stored and how it is removed, and suggest privacy-policy text in WordPress.
* Mark existing email fields as identifying on update, and build their index in the background.

= 0.7.1 =
* End-to-end tests stop only this project's database.
* The accessibility check opens a submission it has just stored.
* Parallel checks run in the browser, so `Retry-After` and `Idempotent-Replayed` must be exposed to JavaScript.

= 0.7.0 =
* HTML and Astro examples show how to post without JavaScript and with `fetch()`. They are in the source repository, not in the installable package.
* The public error page has a main landmark, and each field control in the editor has a name.
* Parallel posts that share an idempotency key are retried after a database lock conflict, so they replay instead of failing.
* Publish the threat model for the technical MVP.

= 0.6.1 =
* Keep the retention lock for the whole run, so a long cleanup is not started twice.
* `wp formstead retention run` reports every deleted count, warns when rows are still waiting, and exits with an error when deletion fails.
* Do not warn that retention is overdue before the first scheduled run. Settings shows when that run will happen.
* On a network, uninstall removes capabilities only on sites that chose to delete their data.

= 0.6.0 =
* Keep each form's submissions for a number of days, 30 by default. Use 0 to keep them until someone deletes them.
* Delete spam after the shorter period, and delete finished deliveries, expired idempotency keys, and rate-limit counters on the same cleanup.
* Run the cleanup every hour, or with `wp formstead retention run` from system cron. Settings shows the last cleanup and warns when it is overdue.
* Choose to delete all Formstead data on uninstall. The choice stays off until someone who can activate plugins turns it on.
* Existing forms keep submissions for 30 days. The first cleanup deletes older submissions.

= 0.5.1 =
* Show the inbox views, with counts for the current form and date filters.
* Mark as unread returns to the list, so the submission stays unread.
* Roll back a deletion when cancelling its delivery or removing its idempotency key fails.
* Export CSV in creation order, including rows that were stored at the same second.

= 0.5.0 =
* List submissions as Inbox, Unread, Archived, Spam, or All, and filter by form and date.
* Opening a new submission marks it read. The menu shows how many are still unread.
* Delete a submission permanently. A waiting notification is cancelled, and the same idempotency key can be used again.
* Export one form as CSV. A cell that starts with `=`, `+`, `-`, `@`, a tab, or a carriage return is prefixed so a spreadsheet does not treat it as a formula.
* Store a submission as spam, without an email, when `_fs_elapsed` is under three seconds or the same content was stored in the last ten minutes. The visitor still gets the normal success response.

= 0.4.1 =
* Two submissions sent at the same time with the same idempotency key no longer fail with a database deadlock on MySQL.
* Reject an idempotency key that ends with a newline.
* Decode HTML entities in the site name before using it as the notification subject.

= 0.4.0 =
* Return the original result when the same idempotency key is sent again, and return 409 when that key is reused with different content.
* Email the recipients saved on a form after a submission is stored. A failed send stays queued. Run `wp formstead deliveries run` from system cron when the site has little traffic.
* Leave the recipient list empty on forms that already exist, so this update does not start sending mail until recipients are saved.

= 0.3.1 =
* Keep a per-minute rejection from using up the form's hourly submission limit.
* Return 413 when JSON is deeper than WordPress can parse.

= 0.3.0 =
* Limit the size, field count, and rate of public submissions.
* Refuse a submission from an origin that is not on the form's allowlist. An empty allowlist allows only this site.
* Count a filled honeypot field without storing the submission.

= 0.2.0 =
* Accept number, checkbox, and choice fields, including several choices.
* Answer `Accept: application/json` with a receipt id, and answer other clients with the HTML page or redirect.
* Reject an unsupported content type, a file upload, and malformed JSON.
* Keep a snapshot of each field schema so older submissions keep their labels.

= 0.1.1 =
* Keep a percent-encoded success URL and an invalid field name unchanged until validation.
* Keep the public submissions route registered when the tables already exist.
* Show one remove control on a field row.

= 0.1.0 =
* Create a form and copy its public endpoint URL.
* Accept a URL-encoded POST and redirect to the form's success URL.
* List submissions for users who can read them.

= 0.0.2 =
* Show requirement notices only to users who can activate plugins.
* Leave development files out of the installable package.

= 0.0.1 =
* Initial installable skeleton.

== Upgrade Notice ==

= 1.0.0 =
Closes a delivery whose worker died after the last attempt. Back up files and database together. Schema remains v8.

= 1.0.0-rc.1 =
Release candidate. The hosting pilot is complete. Back up files and database together. Schema remains v8. Webhooks require cURL with address pinning and direct outbound access.

= 0.9.0 =
Existing forms do not send a webhook until a URL is saved. The database upgrade adds the webhook columns and does not change stored submissions.

= 0.8.1 =
Personal-data export and erase no longer treat a failed index read as an empty result. If a form's identity index was left unfinished after a failed save, run `wp formstead identities rebuild --form=<public_id>`.

= 0.8.0 =
Existing email fields are marked as identifying the person. The index is built in the background. Until it finishes, personal-data export and erase ask you to run `wp formstead identities rebuild`.

= 0.6.0 =
Existing forms now keep submissions for 30 days. The first cleanup deletes older submissions. Set a form to 0 to keep them until someone deletes them.
