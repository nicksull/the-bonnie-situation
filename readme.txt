=== The Bonnie Situation – Contact Form 7 Database & Auto-Delete ===
Contributors: nicksull
Tags: contact form 7, cf7 database, form submissions, gdpr, data retention
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Save Contact Form 7 submissions to your database, then auto-delete them on a schedule. GDPR-friendly CF7 storage that imports Flamingo & CFDB7.

== Description ==

Contact Form 7 does not store submissions: once the email is sent, the data is gone. The usual fix is to bolt on a storage add-on that keeps everything forever, including IP addresses, with no expiry. The moment a form collects personal data, that becomes unbounded risk.

**The Bonnie Situation** captures every Contact Form 7 submission to the database *and* treats stored data as a liability. It suppresses IP storage by default and automatically deletes entries after a configurable number of days, enforced by a scheduled job. Clean it up before Bonnie gets home.

= What it does =

* **Capture** every CF7 submission to a custom table, after mail handling, so it never delays or blocks your email.
* **Browse, search, sort and filter** submissions in a modern DataViews admin, with a per-submission detail view.
* **Delete** individual submissions permanently, on demand.
* **Delete-only retention:** suppress IP storage and automatically delete submissions after a configurable number of days — enforced daily by WP-Cron, with an audit log of every purge.
* **Subject-request tooling:** integrates with WordPress's Tools → Export/Erase Personal Data so requests by email include captured submissions.
* **Import from Flamingo and CFDB7:** bring existing entries across in one click, under your privacy settings and retention window — the source plugin doesn't even need to be active.

Clean it up before Bonnie gets home.

= Privacy-first defaults =

IP storage is off, spam is discarded, user agent and referrer are off, CF7's internal fields are never stored, and Contact Form 7's own `do_not_store` and `consent_for:storage` opt-outs are honoured. The plugin provides controls that support GDPR / UK GDPR / Australian Privacy Act / CCPA compliance; lawful basis and configuration remain the site operator's responsibility. This is not legal advice.

= Bonnie Pro =

This plugin is fully functional on its own: it captures submissions and enforces delete-only retention. [Bonnie Pro](https://beforebonnie.com/pricing?utm_source=wordpress.org&utm_medium=plugin-listing&utm_campaign=upgrade), a separate add-on distributed off WordPress.org, adds:

* **CSV / JSON export** of the current view or selected rows.
* **Address book** of deduplicated contacts, with click-through to each contact's submissions.
* **Trash workflow** — soft-delete with a restore window and a hard cap that force-deletes trashed entries, instead of the free delete-only model.
* **Bulk delete** across selected submissions.
* **Per-form overrides** — each form sets its own policy, window, IP rule and field mapping on the Contact Form 7 editor.
* **IP anonymisation** — store a masked address (last octet / last 80 bits) instead of the full IP.
* **Encryption at rest** — names, emails, subjects and form fields encrypted in the database, with the key kept in wp-config.php.
* **WP-CLI** — `wp bonnie purge` runs the retention sweep on demand, and `wp bonnie import` runs the Flamingo / CFDB7 import from a script.

== Installation ==

1. Install and activate **Contact Form 7** (Bonnie is an add-on for it).
2. Upload the `the-bonnie-situation` folder to `/wp-content/plugins/`, or install through the Plugins screen.
3. Activate Bonnie through the **Plugins** screen.
4. Visit **Bonnie → Settings** to choose your retention policy. Submissions are captured automatically from then on.

== Frequently Asked Questions ==

= Does this interfere with Contact Form 7 sending email? =

No. Capture runs after mail handling and is wrapped so a storage failure is logged silently rather than breaking the form.

= Are IP addresses stored? =

Not by default. IP storage is off out of the box. Bonnie Pro adds the option to anonymise (mask the last octet / last 80 bits) instead of storing the full address.

= Does the free version keep a trash or recycle bin? =

No. The free core is delete-only: submissions past their retention window are permanently deleted, with no second store to manage. Bonnie Pro adds an optional trash workflow — soft-delete with a restore window and a hard cap that force-deletes trashed entries.

= Does Contact Form 7 save form submissions? =

No. Contact Form 7 emails each submission and keeps nothing in the database. To keep a record you need a storage plugin — Bonnie stores every submission, then deletes it when your retention window ends.

= How is Bonnie different from Flamingo or CFDB7? =

Flamingo and CFDB7 save submissions but do not expire them: entries stay in your database until someone deletes them by hand, and neither removes its data when uninstalled. Flamingo also records the sender's IP address and user agent by default. Bonnie stores no IP by default, deletes each submission automatically after the number of days you choose, logs every purge, and supports both the WordPress personal-data exporter and eraser.

= Can I import my existing Flamingo or CFDB7 entries? =

Yes. Go to **Bonnie → Settings → Import**. Bonnie finds Flamingo and CFDB7 data in your database (the plugins can be active or deactivated) and imports it in batches. Imported entries follow your current settings: IP addresses, user agents and referrers are dropped unless you've turned them on, spam is skipped unless you capture spam, and entries already older than your retention window are skipped. Running it again only imports what is new. Nothing in the source plugin is changed — delete the old entries there once you've checked the import.

= How long should I keep contact form submissions? =

As short a time as you actually need them. GDPR's storage-limitation principle (and the Australian Privacy Act's equivalent) says personal data shouldn't be kept longer than its purpose requires; for enquiry forms, many sites choose 30 to 90 days. Bonnie enforces whatever window you set. This is not legal advice.

= How do I guarantee purge timing on a low-traffic site? =

Retention runs daily via WP-Cron. On sites with little traffic, trigger `wp-cron.php` from a real system cron so the sweep fires on schedule.

== Screenshots ==

1. Submissions list with status views, search and filtering.
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

= 1.2.0 =
* New: import existing entries from Flamingo and CFDB7 (Settings → Import). Works with the source plugin active or deactivated, applies your privacy settings, skips spam and entries already past their retention window, and never imports the same entry twice. Source data is left untouched.
* Fix: honour Contact Form 7's own storage opt-outs. Forms with `do_not_store: true`, and submissions where a `consent_for:storage` checkbox is left unticked, are no longer stored; values of `do-not-store` form tags (quiz answers, CAPTCHA) are dropped.
* Imported entries pass through the same store filters as live captures, so add-ons (encryption at rest, address book) apply to them too.

= 1.1.0 =
* Add the `bonnie.settings.cards` JS filter so add-ons can add their own cards to the settings screen.
* Database update: name, email, subject and user-agent columns become TEXT, and a new `email_hash` column supports exact-email lookups, so add-ons can store submissions encrypted. Runs automatically on update.
* Add the `bonnie_email_hash` filter; search and the WordPress personal-data export/erase tools find encrypted submissions by exact email.
* Encrypted values are shown as "[Encrypted]" rather than raw if the add-on that can decrypt them isn't active.
* Add the `bonnie_submission_display_ip` filter so add-ons can annotate the IP shown on a submission's detail view (display only; the stored value is unchanged).
* Upgrade links to Bonnie Pro now carry campaign tags.

= 1.0.0 =
* Initial release: capture Contact Form 7 submissions to a custom table, a DataViews submissions admin (search/sort/filter with a per-submission detail view and permanent delete), delete-only retention with a scheduled daily purge and audit log, IP-storage suppression, and WordPress Personal Data export/erase integration.

== Upgrade Notice ==

= 1.2.0 =
Adds a one-click importer for Flamingo and CFDB7 entries, and honours Contact Form 7's do_not_store / consent_for:storage opt-outs. No database changes.

= 1.1.0 =
Includes a small automatic database update (wider columns and an email-hash column) to support encryption at rest in add-ons. No changes to capture or retention.

= 1.0.0 =
Initial release.
