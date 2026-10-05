=== CodeCaste Database Cleaner & Optimizer ===
Contributors: codecaste, bhushan-gohel
Tags: database, cleanup, optimization, health, transients
Donate link: https://www.codecaste.com
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find out what's making your WordPress database large, identify potentially unnecessary data, and safely clean it after review.

== Description ==

CodeCaste Database Cleaner & Optimizer is a WordPress database analysis and cleanup plugin designed to help administrators identify database bloat, unnecessary records, oversized tables, orphaned data and other potential database issues.

The plugin follows a strict workflow: **Scan → Analyze → Review → Clean**. Scanning never modifies your data. Every cleanup action requires explicit administrator confirmation and runs in safe, batched steps so it works reliably even on large databases.

= Included Scanners =

* **Autoloaded Options** – total autoloaded option data and the largest individual options. A large autoload size is worth reviewing, but is not automatically unsafe.
* **Expired Transients** – transient records that have already expired and are safe to remove.
* **Post Revisions** – stored revisions beyond a configurable number of most-recent revisions to keep per post.
* **Auto-Drafts** – abandoned auto-draft posts older than a day.
* **Orphaned Post Meta** – postmeta rows whose post no longer exists.
* **Orphaned Term Meta** – termmeta rows whose term no longer exists.
* **Orphaned Comment Meta** – commentmeta rows whose comment no longer exists.
* **Action Scheduler Records** – when Action Scheduler is present (e.g. via WooCommerce), old completed, failed and canceled actions.

Potentially orphaned records are always clearly labeled as *potentially* orphaned so you can review them before cleanup.

= Settings (explained simply) =

Open **DB Optimizer → Settings**. Every option has a **"What does this mean? (example)"** link that explains it in plain language. After changing a value, click **Save Settings** and then **Scan Database** again so the Cleanup list uses your new value.

**Cleanup Batch Size** (default 500, range 50–5,000)

Cleaning a large database in one go can overload your server, so the plugin cleans in small steps ("batches"). This number is how many records are removed in each step. It does *not* limit the total – the plugin keeps going until everything eligible is cleaned.

*Example:* you have 10,000 expired transients.

* Batch size 500 → 20 steps of 500 records (the default, fine for most sites).
* Batch size 100 → 100 smaller steps. Slower overall, but gentler on cheap shared hosting.
* Batch size 2,000 → 5 big steps. Faster, but only use this on a strong server.

*Not sure?* Leave it at 500. Lower it only if cleanup shows an error or times out.

**Revisions to Keep** (default 5, range 0–50)

Every time you click Update on a post or page, WordPress saves an old copy called a *revision*. This setting is how many of the newest revisions are kept **for each post**. Older ones become eligible for cleanup.

*Example:* the post "About Us" has 30 revisions.

* Keep 5 → the 5 newest are kept, 25 older ones can be cleaned (default).
* Keep 10 → the 10 newest are kept, 20 can be cleaned.
* Keep 0 → all 30 can be cleaned. You will no longer be able to restore earlier versions of that post.

**Action Scheduler Retention (days)** (default 30, range 1–365)

Plugins such as WooCommerce use a background task system called *Action Scheduler* (emails, syncing, subscription renewals and so on). Each task leaves a record behind, even after it has finished, and on busy stores these records can grow to hundreds of thousands of rows. This setting is how many days finished, failed or cancelled task records are kept before they can be cleaned. Tasks that are still waiting to run are never touched. It only applies when Action Scheduler exists on your site.

*Example:* today is 30 September. One finished task ran on 5 September (25 days ago) and another on 10 August (51 days ago).

* Retention 30 days → only the 10 August task can be cleaned (default).
* Retention 7 days → both tasks can be cleaned.
* Retention 90 days → neither can be cleaned yet; you keep a longer log for troubleshooting.

**Scan History** – keeps a record of every scan (date, size, score, issues, duration) on the History tab.

**Active Scanners** – switch off any scanner you do not want included in a scan.

= Safety =

* Scanning never deletes data.
* Every destructive operation requires explicit confirmation.
* Cleanup endpoints never trust client-supplied record IDs; scanners derive eligible records from live database queries.
* Database-table deletion requires the exact table name and a fresh reference scan immediately before the DROP TABLE operation.
* Plugin, theme, must-use plugin, and WordPress content/drop-in PHP sources are checked for common table references.
* Large cleanup operations use configurable batch processing to avoid PHP timeouts.
* All queries use the WordPress database API with prepared statements.
* All admin actions are protected by capability checks and nonces.
* The plugin never automatically deletes active plugin or theme data, and never performs automatic optimization.

= Database Health Score =

The dashboard shows a simple 0–100 health score based on autoloaded data, table sizes, expired transients, revisions, orphaned metadata and Action Scheduler buildup. This score is an indicator, not an absolute technical measurement — large legitimate tables (for example WooCommerce order tables) are not automatically treated as unhealthy.

== Installation ==

1. Upload the `codecaste-database-cleaner-optimizer` folder to `/wp-content/plugins/`, or install the zip via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. **Take a database backup** (recommended before any cleanup).
4. Go to **DB Optimizer** in the admin menu and click **Scan Database**. Results appear straight away under **Cleanup Items**.
5. Review each item, then click **Clean** on a single item, or **Optimize All**, and confirm.

== How it works ==

* **Cleanup Items** – everything the scan found, with record count and estimated size. Items that are already clean show a green tick.
* **Database Tables** – custom WordPress-prefixed tables that appear to be left behind by plugins or themes that are no longer installed. Tables belonging to any currently installed plugin/theme (active or inactive) are excluded. Deleting requires typing the exact table name.
* **History** – the results of past scans.
* **Settings** – the options described above.

== Frequently Asked Questions ==

= Will this plugin delete anything automatically? =

No. Scanning never modifies data, and cleanup only runs after you explicitly review and confirm an action.

= Why is a table from an installed plugin not shown as an orphan? =

Database Tables is intended to find leftover tables from components that have been removed from the site. The scanner therefore inspects every currently installed plugin and theme, not only active components. This means a plugin that is installed but inactive can still have its custom database table protected from the orphan list. The scanner invalidates its source cache when the installed plugin/theme inventory or the current database table set changes, so a newly installed plugin or a newly created custom table is not hidden behind an older 30-minute cache.

= How does the scanner detect a custom plugin table? =

The scanner checks current installed component source for WordPress table-prefix patterns, CREATE TABLE definitions, exact table identifiers, custom database/table prefixes, and matching unprefixed table identifiers. It also keeps historical ownership information from earlier scans so a table can still be attributed after its plugin has been removed. Because WordPress/MySQL does not store a universal creator field for arbitrary custom tables, the feature is intentionally described as possible orphan detection rather than absolute proof.

= I ran a scan and "Items to Clean" shows a number. Where are the items? =

They are listed under **Cleanup Items**, right below the stat cards.

= Why did nothing change after I edited a setting? =

Settings apply to the next scan. Click **Scan Database** again to refresh the list.

= Does this support multisite? =

The initial release targets standard single-site WordPress installs. Multisite support is planned for a future release since network and site-specific tables require additional handling.

= What happens to my data if I uninstall the plugin? =

Deactivating the plugin leaves all of your WordPress data, plugin settings, and scan history untouched. Deleting the plugin from the Plugins screen removes only the plugin's own settings and scan-history records — it never touches your posts, comments, users, or other plugins' data.

== Screenshots ==

1. Dashboard with health score, overview stats, and Cleanup Items ready for review.
2. Database Tables scanner listing possible leftover tables from removed plugins.
3. Settings for batch size, revisions to keep, Action Scheduler retention, and active scanners.

== Changelog ==

= 1.0.0 =
* Initial release.
* Scanners for database & table size, autoloaded options, expired transients, post revisions, auto-drafts, orphaned post/term/comment meta, and Action Scheduler records.
* Single-page dashboard with health score, stat cards, and a tabbed Cleanup Items / Database Tables / History / Settings layout.
* Batched, confirmation-gated cleanup for every supported scanner, plus an "Optimize All" runner.
* Database Tables scanner that flags possible orphaned tables left behind by removed plugins/themes, with exact-name confirmation before any deletion.
* Scan progress bar and loader (scan buttons are disabled while scanning), Health Score stat card (0-100), "Optimize All" inside the Cleanup Items tab, and admin notices shown above the header.
* Unsaved-changes prompt when leaving the Settings tab, and Last Scan / History times shown in the site's timezone.
* Scan history log and a Settings screen with plain-language, example-based explanations for Cleanup Batch Size, Revisions to Keep, and Action Scheduler Retention (days).

== Upgrade Notice ==

= 1.0.0 =
Initial release.
