=== Formstead ===
Contributors: formstead
Tags: forms, rest-api, headless, submissions
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receive form submissions from static and headless sites on an existing WordPress install.

== Description ==

Formstead receives form submissions from static and headless sites on an existing WordPress install. Plain HTML, Astro, and Next.js post to WordPress. WordPress stores submissions in a private inbox. The site frontend stays with its author.

Version 0.5.0 lists submissions in an inbox. Opening a new submission marks it read. The list can be filtered by form and date, and exported as CSV for one form. A submission filled in under three seconds, or an identical submission within ten minutes, is stored as spam and is not emailed. The visitor still sees the normal success response. A form can notify recipients by email and can list the sites allowed to submit it. Forms that already exist do not start sending mail until recipients are saved.

Network activation on WordPress Multisite is not supported.

== Installation ==

1. Upload the `formstead` directory to the `/wp-content/plugins/` directory.
2. Activate Formstead through the Plugins screen. Do not network-activate it.
3. Open Forms to create a form and copy its endpoint URL. Submissions are listed under Formstead.

== Frequently Asked Questions ==

= Does Formstead require a Formstead account? =

No. The plugin runs entirely on the WordPress install. There is no required Formstead server, account, or telemetry.

= Can I network-activate Formstead on Multisite? =

No. Multisite is outside Core 1.0. Activate the plugin on a single site instead.

== Changelog ==

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
