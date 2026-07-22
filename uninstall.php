<?php
/**
 * Uninstall routine: drops the aggregate tables and removes all plugin
 * options, transients and scheduled events. Runs only on plugin deletion.
 *
 * @package HivePress\Vendor_Analytics
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Aggregate tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hpva_daily" ); // phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hpva_terms" ); // phpcs:ignore

// Options (HivePress saves settings fields with the hp_ prefix).
$hpva_options = [
	'hp_vendor_analytics_views',
	'hp_vendor_analytics_clicks',
	'hp_vendor_analytics_search',
	'hp_vendor_analytics_earnings',
	'hp_vendor_analytics_sections',
	'hp_vendor_analytics_benchmark',
	'hp_vendor_analytics_retention',
	'hpva_db_version',
	'hpva_version',
];

foreach ( $hpva_options as $hpva_option ) {
	delete_option( $hpva_option );
}

// Transients (rate-limit buckets and benchmark caches).
$wpdb->query( // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_hpva\_%'
	 OR option_name LIKE '\_transient\_timeout\_hpva\_%'"
);

wp_clear_scheduled_hook( 'hpva_daily_maintenance' );
