=== The Bonnie Situation ===
Contributors: nicksull
Tags: contact form 7, cf7, gdpr, data retention, privacy
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Store Contact Form 7 submissions and auto-delete them on a schedule — capture and browse without hoarding PII. Clean it up before Bonnie gets home.

== Description ==

Contact Form 7 does not store submissions: once the email is sent, the data is gone. The usual fix is to bolt on a storage add-on that keeps everything forever, including IP addresses, with no expiry. The moment a form collects personal data, that becomes unbounded risk.

**The Bonnie Situation** captures every Contact Form 7 submission to the database *and* treats stored data as a liability. It suppresses IP storage by default and automatically deletes entries after a configurable number of days, enforced by a scheduled job. Clean it up before Bonnie gets home.

= What it does =

* **Capture** every CF7 submission to a custom table, after mail handling, so it never delays or blocks your email.
* **Browse, search, sort and filter** submissions in a modern DataViews admin, with a per-submission detail view.
* **Delete** individual submissions permanently, on demand.
* **Delete-only retention:** suppress IP storage and automatically delete submissions after a configurable number of days — enforced daily by WP-Cron, with an audit log of every purge.
* **Subject-request tooling:** integrates with WordPress's Tools → Export/Erase Personal Data so requests by email include captured submissions.

= Privacy-first defaults =

IP storage is off, spam is discarded, user agent and referrer are off, and only mapped fields are stored. The plugin provides controls that support GDPR / UK GDPR / Australian Privacy Act / CCPA compliance; lawful basis and configuration remain the site operator's responsibility. This is not legal advice.

= Bonnie Pro =

This plugin is fully functional on its own: it captures submissions and enforces delete-only retention. [Bonnie Pro](https://beforebonnie.com/pro/), a separate add-on distributed off WordPress.org, adds:

* **CSV / JSON export** of the current view or selected rows.
* **Address book** of deduplicated contacts, with click-through to each contact's submissions.
* **Trash workflow** — soft-delete with a restore window and a hard cap that force-deletes trashed entries, instead of the free delete-only model.
* **Bulk delete** across selected submissions.
* **Per-form overrides** — each form sets its own policy, window, IP rule and field mapping on the Contact Form 7 editor.
* **IP anonymisation** — store a masked address (last octet / last 80 bits) instead of the full IP.
* **WP-CLI** — `wp bonnie purge` runs the retention sweep on demand.

== Installation ==

1. Install and activate **Contact Form 7** (Bonnie is an add-on for it).
2. Upload the `bonnie` folder to `/wp-content/plugins/`, or install through the Plugins screen.
3. Activate Bonnie through the **Plugins** screen.
4. Visit **Bonnie → Settings** to choose your retention policy. Submissions are captured automatically from then on.

== Frequently Asked Questions ==

= Does this interfere with Contact Form 7 sending email? =

No. Capture runs after mail handling and is wrapped so a storage failure is logged silently rather than breaking the form.

= Are IP addresses stored? =

Not by default. IP storage is off out of the box. Bonnie Pro adds the option to anonymise (mask the last octet / last 80 bits) instead of storing the full address.

= Does the free version keep a trash or recycle bin? =

No. The free core is delete-only: submissions past their retention window are permanently deleted, with no second store to manage. Bonnie Pro adds an optional trash workflow — soft-delete with a restore window and a hard cap that force-deletes trashed entries.

= How do I guarantee purge timing on a low-traffic site? =

Retention runs daily via WP-Cron. On sites with little traffic, trigger `wp-cron.php` from a real system cron so the sweep fires on schedule.

== Screenshots ==

1. Submissions list with status views, filters and bulk actions.
2. Single submission detail view.
3. Privacy & Retention settings.

== Development ==

Bonnie is free software (GPLv2 or later). The complete, human-readable source —
including the React admin under `src/` and the build tooling — is developed in
the open:

https://github.com/nicksull/the-bonnie-situation

That repository holds the un-minified source for every shipped asset — the
React admin entry points `src/index.js` and `src/settings.js` that compile to
`build/index.js` and `build/settings.js`. Build them with
[@wordpress/scripts](https://www.npmjs.com/package/@wordpress/scripts):

`npm install`
`npm run build`

Bonnie Pro is a separate add-on, distributed off WordPress.org. This plugin is
fully functional without it.

== Changelog ==

= 1.0.0 =
* Initial release: capture Contact Form 7 submissions to a custom table, a DataViews submissions admin (search/sort/filter with a per-submission detail view and permanent delete), delete-only retention with a scheduled daily purge and audit log, IP-storage suppression, and WordPress Personal Data export/erase integration.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
