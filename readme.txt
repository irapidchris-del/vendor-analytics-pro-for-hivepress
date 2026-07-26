=== Vendor Analytics Pro for HivePress ===
Contributors: chrisb
Tags: hivepress, analytics, statistics, marketplace, vendors
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A first-party analytics dashboard for HivePress vendors: views, contact clicks, messages, bookings funnel, earnings, response times, search terms and category benchmarks.

== Description ==

Adds an Analytics page to the HivePress vendor account with daily first-party data - no Google Analytics, no third-party services:

* Listing and profile views (cache-safe JavaScript beacon)
* Phone (tel:) and email (mailto:) click tracking
* Messages received and first-response-time trend
* Bookings created/confirmed and a views > messages > bookings conversion funnel
* Marketplace order counts and earnings (each vendor's payout, net of commission), with trend charts
* Search terms that surfaced each listing in results
* Average daily views per listing benchmarked against the category average
* Per-listing breakdown table and selectable periods (7/30/90/365 days, all time)

Complements the official Statistics extension rather than replacing it: when Statistics is active, a first-party 90-day summary appears above its Google Analytics chart on each listing's Statistics page.

Data is stored as compact daily aggregates in two custom tables, with configurable retention. Charts are dependency-free server-rendered SVG.

== Honest limitations (by design) ==

* Data collection starts at activation; there is no historical backfill.
* View counting requires JavaScript. If you delay JS with a performance plugin, exclude `hpva` / `tracker.js` from the delay for immediate counting.
* The tracking endpoint is public by design (cached pages cannot carry fresh nonces); it is protected by a strict metric whitelist, server-side vendor resolution, bot filtering and per-IP rate limiting. Treat counts as best-effort visitor metrics, not audited analytics.
* Vendors' own visits are excluded via a browser flag set when they open their Analytics page - other devices they have never opened it on will count.
* Earnings reflect each vendor's payout after Marketplace commission (net of refunds and, where the site excludes them, taxes - the same figure as their Marketplace balance). Orders and earnings are recorded once, when an order is paid (Marketplace settles most orders on "processing" and downloadable ones on "completed" - both are counted). Later refunds or cancellations are not retroactively subtracted.

== Changelog ==

= 1.5.0 =
* Added: automatic updates from GitHub releases. New versions now appear on the WordPress Plugins screen with an update notice and a one-click "update now", and you can force an immediate check with the "Check for updates" link. Uses the widely used Plugin Update Checker library; the updater only loads in the admin and during cron, so it adds nothing to front-end page loads.

= 1.4.0 =
Verified end to end against the Bookings, Marketplace and Requests extensions running on a live site, which surfaced several integration corrections:
* Fixed: "Bookings created" never counted real bookings. Bookings are created as a hidden placeholder and only later filled in, so the previous detection missed every one; creation is now detected on the status change, and externally imported calendar blocks (iCal "private" bookings) are correctly excluded.
* Fixed: earnings and order counts were only recorded for "completed" orders, but Marketplace settles most orders (services, physical goods, accepted offers) as "processing" - so the vast majority of real orders were never counted. Orders are now recorded once, when paid, on either status.
* Changed: earnings now show each vendor's actual payout after Marketplace commission (net of refunds and, where excluded, taxes) - matching their Marketplace balance - instead of the gross order total, which overstated earnings on any commission-charging marketplace.
* Fixed: "Offers accepted" was only detected on completed orders (and was tied to the earnings toggle), so accepted offers whose order stayed in "processing" were missed. It is now detected when the offer's order is paid, independently of the earnings setting.
* Fixed: "Offers sent" over-counted - the blank offer "draft" used to hold an attachment was counted as a submitted offer. Only offers attached to a real request are now counted.
* Added: an order is recorded only once even if it moves through several paid statuses (idempotency guard), and that per-order flag is cleaned up on uninstall.
* Fixed: earnings now scale to the store's actual currency decimals, so zero-decimal currencies (e.g. JPY) and three-decimal currencies (e.g. KWD) are stored and displayed correctly instead of assuming two decimals.

= 1.3.0 =
* Added: "Download report" - a professional, self-contained HTML analytics report (summary with period-over-period changes, funnel, charts, benchmark, search terms, per-listing breakdown) that honours the admin's enabled sections and the vendor or listing scope. Mobile-friendly, and print-ready so the browser's print dialogue saves it as a clean PDF.
* Changed: "Export CSV" now downloads a readable, sectioned report (summary with previous-period comparison and change, per-listing breakdown, search terms) instead of a raw data dump.
* Added: introductions and tooltips on settings sections.
* Fixed: critical - stray code-style annotations had been inserted inside several SQL query strings by the 1.2.1 cleanup, which made MySQL reject those queries and silently broke all data recording, the per-listing breakdown, search terms and the category benchmark. All queries are valid again.
* Fixed: critical - the plugin now registers itself with HivePress using explicit extension details, so its classes load regardless of the installed folder name (previously the folder had to be named exactly "hivepress-vendor-analytics" for the Analytics pages to appear).
* Fixed: critical - the per-listing Analytics tab redirected back to the listings screen because the listing was never resolved on the child route; it now resolves the listing from the URL (with the same ownership checks) exactly like core's own child routes.
* Added: phone/email clicks are now also recorded on vendor profile pages (previously listing pages only, despite the setting's description).
* Fixed: the vendor self-view exclusion flag is now also set when viewing a single listing's Analytics tab, not just the account dashboard.
* Fixed: search impressions are no longer recorded for known crawlers.
* Fixed: uninstall now also removes the visible-sections and version options.
* Changed: the downloadable HTML report uses the site language attribute, and the translation template now includes all 1.3.0 strings.


= 1.2.2 =
* Added: admin diagnostics now report the saved sections option, every section's enabled/disabled resolution, and which sections additionally require data in the selected period, so "missing section" reports can be diagnosed from the page source on any device.
* Added: runtime smoke test suite executing every render path against stubbed WordPress/HivePress runtimes; all sections verified to render when enabled.


= 1.2.1 =
* Code quality release: zero violations against the HivePress coding-standards ruleset and zero PHPStan level 5 errors (full docblock coverage, justified annotations on intentional direct queries, docblock type corrections). No behaviour changes.


= 1.2.0 =
* Added: period-over-period deltas on all summary cards (e.g. "+32%" vs the equal-length previous period; response time is colour-inverted since lower is better).
* Added: favourites tracking (gained and removed, via verified hp_favorite comment events), with a summary card and per-listing breakdown column.
* Added: Requests extension metrics - offers sent (hp_offer comments, bidder verified as user_id) and offers accepted (detected via the verified order > product > parent-request chain).
* Added: CSV export buttons (metrics and search terms) with ownership checks, nonce-authenticated download, UTF-8 BOM for Excel, and spreadsheet formula-injection guarding.
* Changed: dashboard totals now load in one grouped query per period.


= 1.1.0 =
* Fixed: message and booking recording now uses raw core hooks (wp_insert_comment, wp_insert_post, transition_post_status) with the verified storage schema - the HivePress model-specific hooks only fire when the model registry resolves the type, which could silently skip events.
* Added: dedicated per-listing Analytics tab in the listing manage menu (mirrors the native Statistics page pattern, with identical ownership checks), with its own period switcher, cards, funnel, chart and search terms.
* Added: "Visible sections" admin setting to choose which dashboard sections vendors see.
* Added: admin-only diagnostics comment (table status, row counts, recent events) for verifying data collection.
* Fixed: per-listing breakdown and terms tables scroll horizontally on mobile instead of clipping.
* Changed: account and listing pages now clearly state their scope, and breakdown rows link to each listing's own analytics.
* Changed: removed admin-oriented wording from vendor-facing output.


= 1.0.0 =
* Initial release.
