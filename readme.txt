=== Entry Reports for Gravity Forms ===
Contributors: robinrsa
Tags: gravity forms, reports, email, entries, notifications
Requires at least: 6.0
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Emails scheduled weekly or monthly summary reports of Gravity Forms entries to chosen recipients.

== Description ==

Entry Reports for Gravity Forms adds an "Entry Reports" feed to any Gravity Forms form, letting you schedule automatic email summaries of the entries a form receives.

* Add one or more report feeds per form, each with its own recipients and schedule.
* Send weekly reports on a chosen day of the week, or monthly reports covering the previous calendar month.
* Choose the time of day each report is sent.
* List received entries directly in the email body, or attach the full list as a CSV file.
* Send a test report at any time from the feed list, without waiting for the schedule.

This plugin requires [Gravity Forms](https://www.gravityforms.com/) to be installed and active.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/entryreports-for-gravityforms` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Make sure Gravity Forms is installed and active.
4. Open a form's settings and go to "Entry Reports" to add a report feed.

== Frequently Asked Questions ==

= Does this plugin require Gravity Forms? =

Yes. Entry Reports for Gravity Forms is an add-on and will not do anything without Gravity Forms installed and active.

= How often does the plugin check for reports to send? =

An hourly scheduled task checks every active report feed and sends any report that's due at its configured day and time.

= Can I send a report immediately, without waiting for the schedule? =

Yes, use the "Send Test Now" link next to a feed on the Entry Reports feed list.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
