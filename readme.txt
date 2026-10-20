=== Formstead ===
Contributors: formstead
Tags: forms, rest-api, headless, submissions
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receive form submissions from static and headless sites on an existing WordPress install.

== Description ==

Formstead receives form submissions from static and headless sites on an existing WordPress install. Plain HTML, Astro, and Next.js post to WordPress. WordPress stores submissions in a private inbox. The site frontend stays with its author.

Version 0.1.1 accepts a public form POST and lists the submissions in the admin.

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
