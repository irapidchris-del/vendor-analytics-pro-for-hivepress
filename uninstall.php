<?php
/**
 * Uninstall routine. Runs only on plugin deletion, never on deactivation.
 *
 * Data is RETAINED by default: an owner who deletes the plugin by accident, or
 * removes it to reinstall a clean copy, gets their analytics and settings back.
 * Everything is wiped only when the "Delete all data" box on the settings tab
 * was ticked (option hp_vendor_analytics_delete_data). A delete-time prompt is
 * not possible: the wp-admin delete-confirmation form is hard-coded with no
 * hooks, so the setting is the only control.
 *
 * Regenerable runtime state (transients, the scheduled cron event) is removed
 * on both paths - a scheduled hook must not keep firing for a plugin that no
 * longer exists, and caches rebuild themselves.
 *
 * @package HivePress\Vendor_Analytics
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// --- Both paths: regenerable runtime state -------------------------------

// Update check cache (a site transient; stored in sitemeta on multisite).
delete_site_transient( 'hpva_github_release' );

// Transients: rate-limit buckets, benchmark caches and, on single-site
// installs, the update cache rows. Wildcard keys need SQL, and the timeout
// twins are separate rows. Under an external object cache these live outside
// wp_options and simply expire on their own short TTLs.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- wildcard transient cleanup has no API; uninstall runs once.
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_hpva\_%'
	 OR option_name LIKE '\_transient\_timeout\_hpva\_%'
	 OR option_name LIKE '\_site\_transient\_hpva\_%'
	 OR option_name LIKE '\_site\_transient\_timeout\_hpva\_%'"
);

wp_clear_scheduled_hook( 'hpva_daily_maintenance' );

// --- Everything below only with explicit consent -------------------------

if ( ! get_option( 'hp_vendor_analytics_delete_data' ) ) {
	return;
}

// Aggregate tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hpva_daily" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, prefix-derived name, owner opted in.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hpva_terms" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, prefix-derived name, owner opted in.

// Options (HivePress saves settings fields with the hp_ prefix). The
// delete-data flag itself is deliberately NOT in this list: it goes last, so
// a failure part-way through leaves it set and a second delete finishes the
// job instead of silently reverting to "retain" with half the data gone.
$hpva_options = [
	'hp_vendor_analytics_views',
	'hp_vendor_analytics_clicks',
	'hp_vendor_analytics_search',
	'hp_vendor_analytics_earnings',
	'hp_vendor_analytics_sections',
	'hp_vendor_analytics_hide_statistics',
	'hp_vendor_analytics_hide_dashboard',
	// Removed in 1.6.0 but may exist on earlier installs.
	'hp_vendor_analytics_benchmark',
	'hp_vendor_analytics_retention',
	'hpva_db_version',
	'hpva_version',
];

foreach ( $hpva_options as $hpva_option ) {
	delete_option( $hpva_option );
}

// Per-order "already recorded" flag and banked net, per-booking "already
// confirmed" flag.
delete_post_meta_by_key( '_hpva_recorded' );
delete_post_meta_by_key( '_hpva_net' );
delete_post_meta_by_key( '_hpva_confirmed' );

// Per-vendor "already responded to this person" markers.
delete_metadata( 'user', 0, '_hpva_responded', '', true );

// The consent flag goes last (see above).
delete_option( 'hp_vendor_analytics_delete_data' );
