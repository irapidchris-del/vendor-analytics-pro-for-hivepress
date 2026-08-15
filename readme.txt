=== Vendor Analytics Pro for HivePress ===
Contributors: chrisb
Tags: hivepress, analytics, statistics, marketplace, vendors
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A first-party analytics dashboard for HivePress vendors: views, contact clicks, messages, bookings funnel, earnings, response times, search terms and category benchmarks.

== Description ==

Adds an Analytics page to the HivePress vendor account with daily first-party data - no Google Analytics, no third-party services:

* Listing and profile views (cache-safe JavaScript beacon)
* Phone (tel:) and email (mailto:) click tracking
* Messages received and first-response-time trend
* Bookings created/confirmed and a views > messages > bookings conversion funnel
* Marketplace order counts, earnings (each vendor's payout, net of commission), refunds and the net of the two, with trend charts
* Search terms that surfaced each listing in results, with impressions and clicks
* Average daily views per listing benchmarked against the category average
* Per-listing breakdown table and selectable periods (7/30/90/365 days, all time)

Complements the official Statistics extension rather than replacing it: when Statistics is active, a first-party 90-day summary appears above its Google Analytics chart on each listing's Statistics page.

Data is stored as compact daily aggregates in two custom tables, with configurable retention. Charts are dependency-free server-rendered SVG.

== Honest limitations (by design) ==

* Data collection starts at activation; there is no historical backfill.
* View counting requires JavaScript. If you delay JS with a performance plugin, exclude `hpva` / `tracker.js` from the delay for immediate counting.
* The tracking endpoint is public by design (cached pages cannot carry fresh nonces); it is protected by a strict metric whitelist, server-side vendor resolution, bot filtering and per-IP rate limiting. Treat counts as best-effort visitor metrics, not audited analytics.
* Vendors' own visits are excluded via a browser flag set when they open their Analytics page - other devices they have never opened it on will count.
* Message counts and response times need the Messages extension's "Store messages" setting switched on. With storage off, messages travel by email only, so there is nothing to count.
* If you use a page caching plugin, purge its cache after activating or updating this plugin, or visitors may be served older copies of your pages without the tracking code for a while.
* Earnings reflect each vendor's payout after Marketplace commission, mirroring the figure on their Marketplace balance screen - including or excluding taxes according to the site's own Marketplace "include taxes" setting. Orders and earnings are banked once, when an order is paid (Marketplace settles most orders on "processing" and downloadable ones on "completed" - both are counted). A later refund is recorded separately rather than rewriting that history, so the section shows earnings, refunds and the net of the two. Refunds are only tracked from version 1.7.0 onwards; anything refunded before that is not counted.
* Marketplace's aggregate screens (the vendor dashboard's daily totals and the Orders list) show gross order totals; only its per-order view shows the net figure. This plugin's earnings are always net of commission, so on commission-charging sites they will not match those two gross screens - by design on both sides.
* Search term clicks are counted when someone opens a listing within half an hour of a search, in the same browsing session. It is a good guide to which searches work, not a forensic attribution: a visitor who wanders off and comes back later is not counted.
* The information marks beside "Earnings" and "Avg first response" need a mouse, so like HivePress's own tooltips they are hidden on phones and do not print. The downloadable report states what both figures measure in plain text instead.
* The monthly email goes out when the site's daily scheduled job runs on the first of the month. If scheduled jobs are not running on your site, it will not send; the same job also handles data retention. Site Health reports this as "A scheduled event is late".
* The report button in the monthly email works for 90 days after the month it covers, then stops. Treat the link as private: anyone you forward it to can see that month's figures until it expires.

== Changelog ==

= 1.8.0 =
* Added: a monthly summary email. On the first of each month vendors can be sent the figures for the month just gone, with a button that opens their full report for that month. Suggested by the community.
* Added: the email is a normal HivePress email, so you can rewrite the subject and wording yourself under HivePress > Emails, using the tokens listed there.
* Added: vendors choose for themselves on their account settings page whether to receive it, and whether to receive it in a month with no activity at all. Site owners set what a vendor gets before they have chosen, under HivePress > Settings > Analytics.
* Added: the report button in the email carries a signed link, so it opens on any device without signing in, and cannot be edited to reach another vendor's figures or another month. It stops working 90 days after the month it covers.
* Changed: every PHP class and file name now carries an "Hpva" prefix, as HivePress asks extension authors to do. This prevents a name clash with HivePress itself or a future official extension, which would otherwise stop one of the two loading with no error at all. If you have customised a template that referenced this plugin's blocks by name, they are now `hpva_vendor_analytics` and `hpva_vendor_analytics_summary`.

= 1.7.1 =
* Changed: the card explanations now work like HivePress's own settings tooltips. A small information mark beside the label shows the detail on hover, rather than the click-to-open panel 1.7.0 introduced, and like HivePress's tooltips it is hidden on phones.
* Changed: only Earnings and Avg first response keep an explanation now. Refunded and Net earnings read for themselves, so the grid is quieter.
* Changed: the Earnings wording says plainly that the figure is your share after commission, and that totals elsewhere on the site show the full order value and so read higher. That gap had nothing on the page to explain it.
* Fixed: the explanation text is no longer set in faint grey on the downloadable report, where it could come out close to unreadable once printed.

= 1.7.0 =
* Added: refunds are now tracked. The earnings section shows three figures side by side - what was banked when orders were paid, how much has been refunded since, and the net of the two - so it no longer disagrees with your Marketplace balance after a refund.
* Added: the search terms table now shows clicks as well as impressions, so vendors can see which searches actually led someone to open a listing rather than just scroll past it.
* Added: an option to hide the Marketplace vendor dashboard (its earnings summary) from the account menu, for sites where this plugin's Analytics page replaces it.
* Changed: the conversion funnel now says what each percentage is measuring ("0.7% of views", "48% of messages") and carries a one-line explanation, instead of leaving a bare percentage to be puzzled over.
* Changed: the "Avg first response" figure now says what it measures - the time from a customer's first message to the vendor's first reply.
* Changed: section descriptions are set in normal body text rather than small print, which was hard to read on a phone. Small type is kept for table headers and card labels.
* Changed: the small print under the Earnings, Refunded, Net earnings and Avg first response cards now sits behind an information icon rather than on the card itself, so the summary grid reads cleanly. Click or tap the card's label to read the explanation, and click it again to put it away. The downloadable report still shows every explanation in full.

= 1.6.4 =
* Fixed: hiding the Statistics tab now also hides the Stats button on the My Listings cards - both routes led to the same page, so hiding one without the other made the setting a half-measure. The setting's wording now says so.

= 1.6.3 =
* Fixed: on each listing's Statistics page, the plugin's "Last 90 days" summary appeared above the navigation tabs, pushing them down the page. The tabs now come first, then the summary, then the Google Analytics chart.
* Added: the per-listing breakdown is now a ranking - listings are ordered by views for the selected period, best performer first, with position numbers, on the dashboard, in the report and in the CSV (which gains a Rank column).
* Added: a "Statistics tab" setting (shown only while the official Statistics extension is active) lets site admins hide that extension's tab on listings, since the Analytics tab covers the same ground. The extension itself is untouched.

= 1.6.2 =
* Fixed: on phones, the per-listing breakdown no longer cuts off its right-hand columns. Each listing now stacks as its own block with labelled figures that wrap to fit the screen, in both the dashboard and the downloadable report; printing the report keeps the full table.

= 1.6.1 =
* Fixed: the per-listing Analytics tab no longer appears to visitors and other vendors on public listing pages (it led nowhere for them; figures were never exposed). It now shows only to the listing's owner, exactly like the Edit tab.
* Added: the Earnings card notes that figures are recorded when an order is paid and refunds are not deducted, so it no longer silently disagrees with Marketplace's own Earnings screen after a refund.
* Fixed: the admin-only diagnostics comment now also appears when an administrator who is not a vendor opens the Analytics page - the exact situation the diagnostics exist for.

= 1.6.0 =
* Added: deleting the plugin now keeps your analytics and settings by default, so an accidental delete or a clean reinstall loses nothing. A new "Delete all data" setting (off by default, under Analytics settings) opts in to a full wipe. WordPress's own delete screen always warns that data will be removed; unless that box is ticked, it is kept.
* Fixed: a PHP warning ("Array to string conversion") appeared on every page load on sites running HivePress without any premium extension, caused by the way the plugin registers itself with HivePress. Registration now sidesteps the core code path responsible.
* Fixed: the Analytics pages could show "not found" on a brand-new install until something else refreshed the site's permalinks; activation now refreshes them itself.
* Fixed: update checks no longer send your site's address and WordPress version to GitHub; the request now identifies itself as the plugin alone.
* Fixed: site names containing characters such as "&" now display correctly in the CSV export and downloadable report instead of showing "&amp;".
* Changed: the report and CSV download buttons now use your theme's own button styling instead of the plugin's, so they match the rest of your site on every theme.
* Fixed: the Analytics tab was missing from each listing's manage menu (the page itself worked when reached from the account dashboard). It now appears alongside Edit and Statistics as intended.
* Fixed: deleting a listing no longer floods "Favourites removed" with one entry per favourite the listing had; only a person unfavouriting counts.
* Fixed: listings that were deleted after collecting data showed as blank rows in the per-listing breakdown; they are now labelled "(deleted listing)".
* Fixed: the Analytics menu item stays highlighted when switching periods.
* Fixed: the category benchmark now measures your side and the category side over the same listings, so the comparison is fair; previously views from since-deleted listings could inflate your average.
* Fixed: the very first "Avg first response" figure no longer shows a red "worse" badge; first-ever data is neutral.
* Fixed: response times stay accurate on sites where the Messages extension deletes old messages after a storage period.
* Fixed: the CSV export shows listing names and change percentages cleanly in Excel and Google Sheets (no stray apostrophes or HTML character codes).
* Fixed: search recording now has the same per-visitor rate limit as view tracking, and malformed tracking requests can no longer write warnings to the site's error log.
* Fixed: translations saved into the plugin's own languages folder (Loco Translate's "Author" location) now load; previously only the system location worked.
* Changed: the settings screen has one "Category benchmark" switch instead of two identical ones; the entry under "Visible sections" is now the single control.
* Changed: daily data housekeeping keeps running even while HivePress is temporarily deactivated.
* Changed: the "requires HivePress" notice can now be dismissed.

= 1.5.2 =
* Fixed: importing content no longer registers as customer activity. A WordPress or HivePress import fires the same events as real visits and bookings, so migrating a site could fill a vendor's analytics with figures dated to the day of the import.
* Fixed: search impressions were never recorded for featured listings. When "featured listings per page" is enabled, HivePress serves featured results from a separate query, so a search whose matches were all featured recorded nothing at all; featured results are now counted alongside regular ones.
* Fixed: views were also counted on account-side pages that carry a listing or vendor context (for example the listing edit and renew screens), which inflated a vendor's own figures until they first opened their Analytics page. The tracking beacon now loads only on public listing and vendor profile pages.
* Fixed: a confirmed booking that was cancelled and later restored (or re-published) was counted as confirmed twice; each booking is now counted once.
* Fixed: the "All time" period now also covers search-term data recorded before the first daily metric.
* Fixed: the update check cache is now removed on uninstall.
* Changed: the tracker script version now includes the file modification time, so browsers always fetch the current script after an update instead of a stale cached copy.
* Changed: CSV exports pass the CSV escape parameter explicitly, avoiding a deprecation notice on PHP 8.4.
* Changed: recording a vendor's first reply now reads the conversation in a single database query instead of two, halving the work done when a message is sent.
* Changed: the Data settings section now explains where figures are stored.

= 1.5.1 =
* Changed: the GitHub auto-updater is now a self-contained implementation built on WordPress's native update API (the "Update URI" header and the update_plugins_github.com filter), with no bundled third-party library - a smaller footprint with the same behaviour.

= 1.5.0 =
* Added: automatic updates from GitHub releases. New versions appear on the WordPress Plugins screen with an update notice and a one-click "update now", the "View version details" popup shows the release changelog, and a "Check for updates" link forces an immediate check.

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
