=== Formstead ===
Contributors: formstead
Tags: forms, rest-api, headless, submissions
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receive form submissions from static and headless sites on an existing WordPress install.

== Description ==

Formstead receives form submissions from static and headless sites on an existing WordPress install. Plain HTML, Astro, and Next.js post to WordPress. WordPress stores submissions in a private inbox. The site frontend stays with its author.

This is a pre-release skeleton. Domain logic is not implemented yet.

Network activation on WordPress Multisite is not supported.

== Installation ==

1. Upload the `formstead` directory to the `/wp-content/plugins/` directory.
2. Activate Formstead through the Plugins screen. Do not network-activate it.
3. Form configuration and the submissions inbox will appear in later releases.

== Frequently Asked Questions ==

= Does Formstead require a Formstead account? =

No. The plugin runs entirely on the WordPress install. There is no required Formstead server, account, or telemetry.

= Can I network-activate Formstead on Multisite? =

No. Multisite is outside Core 1.0. Activate the plugin on a single site instead.

== Changelog ==

= 0.0.1 =
* Initial installable skeleton.
