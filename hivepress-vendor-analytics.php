<?php
/**
 * Plugin Name: Vendor Analytics Pro for HivePress
 * Description: A first-party analytics dashboard for HivePress vendors - views, phone/email click tracking, messages, bookings funnel, earnings, response-time trends, search terms and category benchmarks, stored as daily aggregates with no third-party services.
 * Version: 1.4.0
 * Author: ChrisB @ HivePress Community
 * Author URI: https://community.hivepress.io/u/chrisb
 * Requires Plugins: hivepress
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: hivepress-vendor-analytics
 *
 * @package HivePress\Vendor_Analytics
 *
 * DESIGN NOTES (all integration points verified against HivePress source, July 2026):
 * - Complements (never replaces) the official Statistics extension: when that
 *   extension is active, a first-party summary is injected above its Google
 *   Analytics chart via the 'hivepress/v1/templates/listing_statistics_page'
 *   filter; our own vendor dashboard lives at Account > Analytics regardless.
 * - Views and tel/mailto clicks are tracked with a tiny JS beacon rather than
 *   PHP, because full-page caching (FlyingPress etc.) serves cached pages
 *   without running PHP. The REST endpoint is deliberately nonce-free (cached
 *   pages bake stale nonces); integrity comes from a strict metric whitelist,
 *   server-side vendor resolution, bot filtering and per-IP rate limiting.
 *   Counts are best-effort visitor metrics, not audited analytics.
 * - Messages and bookings are recorded from RAW core WordPress hooks
 *   (wp_insert_comment, wp_insert_post, transition_post_status) rather than
 *   HivePress model hooks: verified in core Hook component, the model-specific
 *   create hooks fire only when the model registry resolves the type, so raw
 *   hooks with the verified storage schema (hp_message comments: sender =
 *   user_id, recipient = comment_karma, listing = comment_post_ID; hp_booking
 *   posts: listing = post_parent) are strictly more reliable. Marketplace
 *   orders are WooCommerce shop_order posts carrying the vendor ID in
 *   'hp_vendor' meta.
 * - Daily aggregates live in two custom tables; retention is configurable.
 */

defined( 'ABSPATH' ) || exit;

define( 'HPVA_VERSION', '1.4.0' );
define( 'HPVA_DB_VERSION', '1' );
define( 'HPVA_FILE', __FILE__ );

// Register this plugin with HivePress so core autoloads our controller,
// template and block classes. The explicit array form is required: with a
// bare directory, core expects the main file to be named after the plugin
// folder (basename( $dir ) . '.php'), so registration would silently fail
// whenever the installed folder name differs from the main file name.
add_filter(
	'hivepress/v1/extensions',
	/**
	 * @param array<int|string, mixed> $extensions Extension directories or details.
	 * @return array<int|string, mixed>
	 */
	function ( $extensions ) {
		$extensions['vendor_analytics'] = [
			'name'    => 'Vendor Analytics Pro',
			'version' => HPVA_VERSION,
			'path'    => __DIR__,
			'url'     => rtrim( plugin_dir_url( __FILE__ ), '/' ),
		];

		return $extensions;
	}
);

/*
--------------------------------------------------------------------------
Activation / deactivation.
--------------------------------------------------------------------------
*/

register_activation_hook( __FILE__, 'hpva_activate' );
register_deactivation_hook( __FILE__, 'hpva_deactivate' );

/**
 * Runs on plugin activation.
 *
 * @return void
 */
function hpva_activate() {
	hpva_install_tables();

	if ( ! wp_next_scheduled( 'hpva_daily_maintenance' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'hpva_daily_maintenance' );
	}
}

/**
 * Runs on plugin deactivation.
 *
 * @return void
 */
function hpva_deactivate() {
	wp_clear_scheduled_hook( 'hpva_daily_maintenance' );
}

/**
 * Creates or updates the aggregate tables.
 *
 * @return void
 */
function hpva_install_tables() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();
	$daily           = hpva_table();
	$terms           = hpva_terms_table();

	// dbDelta requires this exact formatting (two spaces after PRIMARY KEY,
	// named keys, one field per line).
	dbDelta(
		"CREATE TABLE {$daily} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stat_date date NOT NULL,
			metric varchar(32) NOT NULL,
			listing_id bigint(20) unsigned NOT NULL DEFAULT 0,
			vendor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			value bigint(20) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY hpva_unique (stat_date,metric,listing_id,vendor_id),
			KEY hpva_vendor (vendor_id,metric,stat_date),
			KEY hpva_listing (listing_id,metric,stat_date)
		) {$charset_collate};"
	);

	// term is capped at 140 chars so the unique index stays within the 767-byte
	// limit of older InnoDB row formats (140 x 4 bytes + date + bigint).
	dbDelta(
		"CREATE TABLE {$terms} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stat_date date NOT NULL,
			term varchar(140) NOT NULL,
			listing_id bigint(20) unsigned NOT NULL DEFAULT 0,
			impressions bigint(20) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY hpva_term_unique (stat_date,term,listing_id),
			KEY hpva_term_listing (listing_id,stat_date)
		) {$charset_collate};"
	);

	update_option( 'hpva_db_version', HPVA_DB_VERSION, false );
}

/**
 * Gets the daily aggregates table name.
 *
 * @return string
 */
function hpva_table() {
	global $wpdb;
	return $wpdb->prefix . 'hpva_daily';
}

/**
 * Gets the search terms table name.
 *
 * @return string
 */
function hpva_terms_table() {
	global $wpdb;
	return $wpdb->prefix . 'hpva_terms';
}

/*
--------------------------------------------------------------------------
Bootstrap.
--------------------------------------------------------------------------
*/

add_action( 'plugins_loaded', 'hpva_init' );

/**
 * Bootstraps the plugin once all plugins are loaded.
 *
 * @return void
 */
function hpva_init() {
	load_plugin_textdomain( 'hivepress-vendor-analytics', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! function_exists( 'hivepress' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Vendor Analytics Pro requires the HivePress plugin to be active.', 'hivepress-vendor-analytics' ) . '</p></div>';
			}
		);
		return;
	}

	// Settings tab under HivePress > Settings.
	add_filter( 'hivepress/v1/settings', 'hpva_register_settings' );

	// Settings quick link on the Plugins screen.
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'hpva_plugin_action_links' );

	// Account menu item (verified filter pattern: menus/{menu}/items).
	add_filter( 'hivepress/v1/menus/user_account/items', 'hpva_account_menu_item', 100 );

	// First-party summary above the official Statistics extension's GA chart.
	if ( class_exists( '\HivePress\Templates\Listing_Statistics_Page' ) ) {
		add_filter( 'hivepress/v1/templates/listing_statistics_page', 'hpva_inject_statistics_summary' );
	}

	// Tracking endpoint.
	add_action( 'rest_api_init', 'hpva_register_rest' );

	// Beacon script on listing/vendor pages.
	add_action( 'wp_enqueue_scripts', 'hpva_enqueue_tracker' );

	hpva_maybe_upgrade();

	// Event recorders via raw core hooks (see header notes for why).
	add_action( 'wp_insert_comment', 'hpva_on_comment_insert', 10, 2 );
	add_action( 'delete_comment', 'hpva_on_comment_delete', 10, 2 );
	add_action( 'transition_post_status', 'hpva_on_post_transition', 10, 3 );
	// Marketplace settles orders on 'processing' (services/goods/offers) or
	// 'completed' (downloadable); the handler records each order only once.
	add_action( 'woocommerce_order_status_processing', 'hpva_on_order_paid', 10, 1 );
	add_action( 'woocommerce_order_status_completed', 'hpva_on_order_paid', 10, 1 );

	// Per-listing Analytics tab in the listing manage menu (mirrors the
	// official Statistics extension's verified pattern).
	add_filter( 'hivepress/v1/menus/listing_manage/items', 'hpva_listing_manage_menu_item', 100, 2 );

	// Search term impressions.
	add_action( 'wp', 'hpva_track_search_terms' );

	// Retention pruning.
	add_action( 'hpva_daily_maintenance', 'hpva_prune' );

	// Late table install for updates without re-activation.
	if ( get_option( 'hpva_db_version' ) !== HPVA_DB_VERSION ) {
		hpva_install_tables();
	}
}

/*
--------------------------------------------------------------------------
Settings.
--------------------------------------------------------------------------
*/

/**
 * Registers the settings tab.
 *
 * @param array<string, mixed> $settings Settings configuration.
 * @return array<string, mixed>
 */
function hpva_register_settings( $settings ) {
	$settings['vendor_analytics'] = [
		'title'    => __( 'Analytics', 'hivepress-vendor-analytics' ),
		'_order'   => 910,

		'sections' => [
			'tracking' => [
				'title'       => __( 'Tracking', 'hivepress-vendor-analytics' ),
				'description' => __( 'Everything is measured on your own site with no third-party analytics services. Data collection starts when the plugin is activated.', 'hivepress-vendor-analytics' ),
				'_order'      => 10,

				'fields'      => [
					'vendor_analytics_views'     => [
						'label'   => __( 'Track page views', 'hivepress-vendor-analytics' ),
						'caption' => __( 'Count listing and vendor profile views (JavaScript beacon, cache-safe)', 'hivepress-vendor-analytics' ),
						'type'    => 'checkbox',
						'default' => true,
						'_order'  => 10,
					],

					'vendor_analytics_clicks'    => [
						'label'   => __( 'Track contact clicks', 'hivepress-vendor-analytics' ),
						'caption' => __( 'Count clicks on phone (tel:) and email (mailto:) links on listing and vendor pages', 'hivepress-vendor-analytics' ),
						'type'    => 'checkbox',
						'default' => true,
						'_order'  => 20,
					],

					'vendor_analytics_search'    => [
						'label'   => __( 'Track search terms', 'hivepress-vendor-analytics' ),
						'caption' => __( 'Record which keyword searches surfaced each listing in results', 'hivepress-vendor-analytics' ),
						'type'    => 'checkbox',
						'default' => true,
						'_order'  => 30,
					],

					'vendor_analytics_earnings'  => [
						'label'   => __( 'Track earnings', 'hivepress-vendor-analytics' ),
						'caption' => __( 'Record each vendor\'s payout from completed Marketplace orders, after commission (requires Marketplace)', 'hivepress-vendor-analytics' ),
						'type'    => 'checkbox',
						'default' => true,
						'_order'  => 40,
					],

					'vendor_analytics_sections'  => [
						'label'       => __( 'Visible sections', 'hivepress-vendor-analytics' ),
						'description' => __( 'Choose which sections vendors see on their analytics pages and in downloaded reports. Fewer sections can be less overwhelming, and hiding averages may suit newer sites with low figures.', 'hivepress-vendor-analytics' ),
						'type'        => 'checkboxes',
						'default'     => [ 'summary', 'funnel', 'trend', 'response', 'earnings', 'benchmark', 'terms', 'breakdown', 'export' ],
						'_order'      => 45,

						'options'     => [
							'summary'   => __( 'Summary cards', 'hivepress-vendor-analytics' ),
							'funnel'    => __( 'Conversion funnel', 'hivepress-vendor-analytics' ),
							'trend'     => __( 'Views & messages chart', 'hivepress-vendor-analytics' ),
							'response'  => __( 'Response-time chart', 'hivepress-vendor-analytics' ),
							'earnings'  => __( 'Earnings chart', 'hivepress-vendor-analytics' ),
							'benchmark' => __( 'Category benchmark', 'hivepress-vendor-analytics' ),
							'terms'     => __( 'Search terms', 'hivepress-vendor-analytics' ),
							'breakdown' => __( 'Per-listing breakdown', 'hivepress-vendor-analytics' ),
							'export'    => __( 'Report downloads', 'hivepress-vendor-analytics' ),
						],
					],

					'vendor_analytics_benchmark' => [
						'label'   => __( 'Category benchmark', 'hivepress-vendor-analytics' ),
						'caption' => __( 'Show vendors their average daily views per listing vs. their category average', 'hivepress-vendor-analytics' ),
						'type'    => 'checkbox',
						'default' => true,
						'_order'  => 50,
					],
				],
			],

			'data'     => [
				'title'  => __( 'Data', 'hivepress-vendor-analytics' ),
				'_order' => 20,

				'fields' => [
					'vendor_analytics_retention' => [
						'label'       => __( 'Retention (days)', 'hivepress-vendor-analytics' ),
						'description' => __( 'Daily aggregates older than this are deleted by a daily cron job. Set to 0 to keep data forever.', 'hivepress-vendor-analytics' ),
						'type'        => 'number',
						'min_value'   => 0,
						'max_value'   => 3650,
						'default'     => 0,
						'_order'      => 10,
					],
				],
			],
		],
	];

	return $settings;
}

/**
 * Reads a HivePress-prefixed option; default only when the option is absent
 * (deliberately cleared checkboxes are respected).
 *
 * @param string $name Option name without the hp_ prefix.
 * @param mixed  $fallback Value when the option is absent.
 * @return mixed
 */
function hpva_get_option( $name, $fallback ) {
	$value = get_option( 'hp_' . $name, null );

	if ( null === $value || false === $value ) {
		return $fallback;
	}

	return $value;
}

/**
 * Adds the Settings link on the Plugins screen.
 *
 * @param array<int|string, string> $links Action links.
 * @return array<int|string, string>
 */
function hpva_plugin_action_links( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'admin.php?page=hp_settings&tab=vendor_analytics' ) ) . '">' . esc_html__( 'Settings', 'hivepress-vendor-analytics' ) . '</a>'
	);

	return $links;
}

/*
--------------------------------------------------------------------------
Recording (write path).
--------------------------------------------------------------------------
*/

/**
 * Gets the recordable metric keys.
 *
 * @return array<int, string>
 */
function hpva_metrics_whitelist() {
	return [ 'view', 'vendor_view', 'phone_click', 'email_click', 'message', 'booking_new', 'booking_confirmed', 'order', 'earning_minor', 'response_sum', 'response_count', 'favorite', 'favorite_removed', 'offer_sent', 'offer_accepted' ];
}

/**
 * Increments a daily aggregate. Uses the site timezone for the day bucket and
 * INSERT ... ON DUPLICATE KEY UPDATE against the composite unique key, so
 * concurrent writes are safe.
 *
 * @param string $metric Metric key.
 * @param int    $vendor_id Vendor ID.
 * @param int    $listing_id Listing ID (0 for vendor-level).
 * @param int    $value Increment amount.
 * @return void
 */
function hpva_record( $metric, $vendor_id, $listing_id = 0, $value = 1 ) {
	global $wpdb;

	if ( ! in_array( $metric, hpva_metrics_whitelist(), true ) || $value <= 0 ) {
		return;
	}

	$vendor_id  = (int) $vendor_id;
	$listing_id = (int) $listing_id;
	$value      = (int) $value;

	if ( ! $vendor_id && ! $listing_id ) {
		return;
	}

	$wpdb->query( // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
		$wpdb->prepare(
			'INSERT INTO ' . hpva_table() . ' (stat_date, metric, listing_id, vendor_id, value)
			 VALUES (%s, %s, %d, %d, %d)
			 ON DUPLICATE KEY UPDATE value = value + %d',
			current_time( 'Y-m-d' ),
			$metric,
			$listing_id,
			$vendor_id,
			$value,
			$value
		)
	);
}

/**
 * Increments a daily search term impression.
 *
 * @param string $term Search term.
 * @param int    $listing_id Listing ID.
 * @return void
 */
function hpva_record_term( $term, $listing_id ) {
	global $wpdb;

	$listing_id = (int) $listing_id;

	if ( ! $listing_id || '' === $term ) {
		return;
	}

	$wpdb->query( // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
		$wpdb->prepare(
			'INSERT INTO ' . hpva_terms_table() . ' (stat_date, term, listing_id, impressions)
			 VALUES (%s, %s, %d, 1)
			 ON DUPLICATE KEY UPDATE impressions = impressions + 1',
			current_time( 'Y-m-d' ),
			$term,
			$listing_id
		)
	);
}

/**
 * Resolves a user ID to their vendor ID, cached per request.
 *
 * @param int $user_id User ID.
 * @return int
 */
function hpva_vendor_id_from_user( $user_id ) {
	static $cache = [];

	$user_id = (int) $user_id;

	if ( ! $user_id ) {
		return 0;
	}

	if ( ! isset( $cache[ $user_id ] ) ) {
		$cache[ $user_id ] = (int) \HivePress\Models\Vendor::query()->filter( [ 'user' => $user_id ] )->get_first_id();
	}

	return $cache[ $user_id ];
}

/**
 * Resolves a listing ID to its vendor ID.
 *
 * @param int $listing_id Listing ID.
 * @return int
 */
function hpva_vendor_id_from_listing( $listing_id ) {
	$post = get_post( (int) $listing_id );

	return ( $post && 'hp_listing' === $post->post_type && $post->post_parent ) ? (int) $post->post_parent : 0;
}

/* --- Messages ---------------------------------------------------------- */

/**
 * Records messages, favourites, offers and first responses.
 *
 * @param int             $comment_id Comment ID.
 * @param WP_Comment|null $comment Comment object.
 * @return void
 */
function hpva_on_comment_insert( $comment_id, $comment = null ) {
	if ( ! $comment ) {
		$comment = get_comment( $comment_id );
	}

	if ( ! $comment ) {
		return;
	}

	// Favourites (verified schema: listing = comment_post_ID).
	if ( 'hp_favorite' === $comment->comment_type ) {
		$listing_id = (int) $comment->comment_post_ID;
		$vendor_id  = hpva_vendor_id_from_listing( $listing_id );

		if ( $vendor_id ) {
			hpva_record( 'favorite', $vendor_id, $listing_id );
		}

		return;
	}

	// Offers (verified schema: bidder = user_id, request = comment_post_ID).
	// When attachments are enabled the Requests extension pre-inserts a blank
	// "offer draft" comment with comment_post_ID = 0 (no request yet) to hold
	// the upload; that is not a submitted offer, so a real request id is
	// required before counting.
	if ( 'hp_offer' === $comment->comment_type ) {
		if ( (int) $comment->comment_post_ID <= 0 ) {
			return;
		}

		$vendor_id = hpva_vendor_id_from_user( (int) $comment->user_id );

		if ( $vendor_id ) {
			hpva_record( 'offer_sent', $vendor_id, 0 );
		}

		return;
	}

	if ( 'hp_message' !== $comment->comment_type ) {
		return;
	}

	// Verified Messages schema: sender = user_id, recipient = comment_karma,
	// listing = comment_post_ID.
	$sender_id    = (int) $comment->user_id;
	$recipient_id = (int) $comment->comment_karma;
	$listing_id   = (int) $comment->comment_post_ID;

	// Message received by a vendor.
	$recipient_vendor = hpva_vendor_id_from_user( $recipient_id );

	if ( $recipient_vendor ) {
		hpva_record( 'message', $recipient_vendor, $listing_id );
	}

	// First-response detection: sender is a vendor replying to this
	// counterpart for the first time.
	$sender_vendor = hpva_vendor_id_from_user( $sender_id );

	if ( ! $sender_vendor || ! $recipient_id ) {
		return;
	}

	global $wpdb;

	$prior_replies = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->comments}
			 WHERE comment_type = 'hp_message'
			 AND user_id = %d AND comment_karma = %d
			 AND comment_ID != %d",
			$sender_id,
			$recipient_id,
			(int) $comment_id
		)
	);

	if ( $prior_replies > 0 ) {
		return;
	}

	$opened_at = $wpdb->get_var( // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
		$wpdb->prepare(
			"SELECT MIN(comment_date_gmt) FROM {$wpdb->comments}
			 WHERE comment_type = 'hp_message'
			 AND user_id = %d AND comment_karma = %d",
			$recipient_id,
			$sender_id
		)
	);

	if ( ! $opened_at ) {
		return; // Vendor initiated the conversation; measures nothing.
	}

	$delta = time() - strtotime( $opened_at . ' UTC' );

	// Ignore replies to conversations older than 30 days as response-time
	// samples; they would skew the trend without describing responsiveness.
	if ( $delta < 0 || $delta > 30 * DAY_IN_SECONDS ) {
		return;
	}

	hpva_record( 'response_sum', $sender_vendor, 0, max( 1, $delta ) );
	hpva_record( 'response_count', $sender_vendor, 0, 1 );
}

/**
 * Records favourite removals.
 *
 * @param int             $comment_id Comment ID.
 * @param WP_Comment|null $comment Comment object.
 * @return void
 */
function hpva_on_comment_delete( $comment_id, $comment = null ) {
	if ( ! $comment ) {
		$comment = get_comment( $comment_id );
	}

	if ( $comment && 'hp_favorite' === $comment->comment_type ) {
		$listing_id = (int) $comment->comment_post_ID;
		$vendor_id  = hpva_vendor_id_from_listing( $listing_id );

		if ( $vendor_id ) {
			hpva_record( 'favorite_removed', $vendor_id, $listing_id );
		}
	}
}

/* --- Bookings ----------------------------------------------------------- */

/**
 * Records booking creation and confirmation from status transitions.
 *
 * Bookings are born as an 'auto-draft' placeholder (verified in the Bookings
 * extension's checkout controller) and only later filled in and moved to
 * 'pending'/'publish' via an UPDATE, so a wp_insert_post listener never sees a
 * real "new booking". transition_post_status fires on every change (including
 * the initial insert, new -> auto-draft), so both events are detected here:
 *  - booking_new: the first move OUT of the pre-creation states into an active
 *    status (a booking request was actually placed).
 *  - booking_confirmed: reaching 'publish' (paid/confirmed) from any other
 *    status.
 * Consolidating both into one handler also avoids the double-count that two
 * separate insert+transition listeners would cause for a direct publish.
 *
 * @param string       $new_status New status.
 * @param string       $old_status Old status.
 * @param WP_Post|null $post Post object.
 * @return void
 */
function hpva_on_post_transition( $new_status, $old_status, $post ) {
	if ( ! $post || 'hp_booking' !== $post->post_type || $new_status === $old_status ) {
		return;
	}

	// Verified Bookings schema: listing = post_parent.
	$listing_id = (int) $post->post_parent;
	$vendor_id  = hpva_vendor_id_from_listing( $listing_id );

	if ( ! $vendor_id ) {
		return;
	}

	// Real customer booking states. Deliberately a whitelist: it excludes
	// 'private' (external iCal-imported blocks and vendor calendar blocks, which
	// are not customer bookings), 'trash' (cancelled), 'future', 'auto-draft'
	// and 'inherit'. Verified against the Bookings extension: the checkout
	// controller moves a real booking auto-draft -> pending/publish, while the
	// import routine creates blocks directly as 'private'.
	$active = [ 'draft', 'pending', 'publish' ];

	// A booking was placed: it left the placeholder state for a live status.
	if ( in_array( $old_status, [ 'new', 'auto-draft' ], true ) && in_array( $new_status, $active, true ) ) {
		hpva_record( 'booking_new', $vendor_id, $listing_id );
	}

	// A booking was confirmed: it reached 'publish' from a non-published state.
	if ( 'publish' === $new_status && 'publish' !== $old_status ) {
		hpva_record( 'booking_confirmed', $vendor_id, $listing_id );
	}
}

/* --- Earnings (Marketplace) --------------------------------------------- */

/**
 * Records order counts, earnings and accepted offers when a Marketplace order
 * is paid.
 *
 * Marketplace settles orders on payment via a status filter: downloadable
 * items go straight to 'completed', but everything else (services, physical
 * goods, accepted-offer purchases) settles on 'processing'. Listening only on
 * 'completed' would therefore miss the majority of real orders, so both hooks
 * funnel here and a per-order flag guarantees a single recording even as the
 * order later moves processing -> completed or re-enters a status.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function hpva_on_order_paid( $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	$order_id = (int) $order_id;

	// Marketplace stores the vendor ID in 'hp_vendor' order meta (verified);
	// orders without it are not marketplace orders and are skipped.
	$vendor_id = (int) get_post_meta( $order_id, 'hp_vendor', true );

	if ( ! $vendor_id ) {
		return;
	}

	// Record each order exactly once.
	if ( get_post_meta( $order_id, '_hpva_recorded', true ) ) {
		return;
	}

	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		return;
	}

	update_post_meta( $order_id, '_hpva_recorded', 1 );

	// Orders and earnings respect the "Track earnings" setting.
	if ( hpva_get_option( 'vendor_analytics_earnings', true ) ) {
		// Earnings should reflect what the vendor actually receives, so prefer
		// Marketplace's own payout calculation (net of platform commission,
		// refunds and - per the site's setting - taxes), which is the same
		// figure it shows in the vendor's balance. Fall back to the gross order
		// total only if that method is unavailable.
		$amount    = (float) $order->get_total();
		$component = function_exists( 'hivepress' ) ? hivepress()->marketplace : null;

		if ( $component && method_exists( $component, 'get_order_profit' ) ) {
			$amount = (float) $component->get_order_profit( $order );
		}

		list( $factor ) = hpva_currency_scale();
		$minor          = (int) round( $amount * $factor );

		hpva_record( 'order', $vendor_id, 0, 1 );

		if ( $minor > 0 ) {
			hpva_record( 'earning_minor', $vendor_id, 0, $minor );
		}
	}

	// Accepted-offer detection is a Requests conversion metric, recorded
	// independently of the earnings setting (verified in the Requests
	// extension: an accepted offer becomes an order whose first line-item
	// product has the request post as its post_parent).
	if ( class_exists( '\\HivePress\\Models\\Offer' ) ) {
		$items = $order->get_items( 'line_item' );
		$item  = is_array( $items ) ? reset( $items ) : false;

		if ( $item && method_exists( $item, 'get_product_id' ) ) {
			$request_id = wp_get_post_parent_id( (int) $item->get_product_id() );

			if ( $request_id && 'hp_request' === get_post_type( $request_id ) ) {
				hpva_record( 'offer_accepted', $vendor_id, 0 );
			}
		}
	}
}

/**
 * One-time upgrade routine: appends newly introduced section keys to a saved
 * sections option so existing installs see new features by default.
 *
 * @return void
 */
function hpva_maybe_upgrade() {
	$stored = get_option( 'hpva_version' );

	if ( HPVA_VERSION === $stored ) {
		return;
	}

	if ( ! $stored || version_compare( (string) $stored, '1.2.0', '<' ) ) {
		$sections = get_option( 'hp_vendor_analytics_sections', null );

		if ( is_array( $sections ) && ! in_array( 'export', $sections, true ) ) {
			$sections[] = 'export';
			update_option( 'hp_vendor_analytics_sections', $sections );
		}
	}

	update_option( 'hpva_version', HPVA_VERSION, false );
}

/* --- Search terms -------------------------------------------------------- */

/**
 * Records search term impressions for listings in results.
 *
 * @return void
 */
function hpva_track_search_terms() {
	if ( is_admin() || ! is_search() || hpva_is_bot() || ! hpva_get_option( 'vendor_analytics_search', true ) ) {
		return;
	}

	// HivePress listing searches are standard WP searches with
	// post_type=hp_listing (string or array depending on context).
	$post_type = get_query_var( 'post_type' );

	if ( is_array( $post_type ) ? ! in_array( 'hp_listing', $post_type, true ) : 'hp_listing' !== $post_type ) {
		return;
	}

	$term = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( get_search_query( false ) ) ) );

	if ( function_exists( 'mb_strtolower' ) ) {
		$term = mb_strtolower( $term );
		$term = mb_substr( $term, 0, 140 );
	} else {
		$term = strtolower( substr( $term, 0, 140 ) );
	}

	if ( strlen( $term ) < 2 ) {
		return;
	}

	global $wp_query;

	if ( empty( $wp_query->posts ) ) {
		return;
	}

	foreach ( $wp_query->posts as $post ) {
		if ( isset( $post->post_type, $post->ID ) && 'hp_listing' === $post->post_type ) {
			hpva_record_term( $term, (int) $post->ID );
		}
	}
}

/*
--------------------------------------------------------------------------
Beacon: script + REST endpoint.
--------------------------------------------------------------------------
*/

/**
 * Enqueues the beacon script on listing and vendor pages.
 *
 * @return void
 */
function hpva_enqueue_tracker() {
	$track_views  = (bool) hpva_get_option( 'vendor_analytics_views', true );
	$track_clicks = (bool) hpva_get_option( 'vendor_analytics_clicks', true );

	if ( ! $track_views && ! $track_clicks ) {
		return;
	}

	// Request contexts are set by core controllers on both page types
	// (the vendor page swaps the main query, so is_singular is unreliable).
	$listing = hivepress()->request->get_context( 'listing' );
	$vendor  = hivepress()->request->get_context( 'vendor' );

	$listing_id = ( is_object( $listing ) && $listing->get_id() ) ? (int) $listing->get_id() : 0;
	$vendor_id  = ( is_object( $vendor ) && $vendor->get_id() ) ? (int) $vendor->get_id() : 0;

	if ( ! $listing_id && ! $vendor_id ) {
		return;
	}

	wp_enqueue_script(
		'hpva-tracker',
		plugins_url( 'assets/js/tracker.js', HPVA_FILE ),
		[],
		HPVA_VERSION,
		true
	);

	wp_add_inline_script(
		'hpva-tracker',
		'window.hpvaConfig=' . wp_json_encode(
			[
				'endpoint' => esc_url_raw( rest_url( 'hpva/v1/track' ) ),
				'listing'  => $listing_id,
				'vendor'   => $listing_id ? hpva_vendor_id_from_listing( $listing_id ) : $vendor_id,
				'views'    => $track_views ? 1 : 0,
				'clicks'   => $track_clicks ? 1 : 0,
			]
		) . ';',
		'before'
	);
}

/**
 * Registers the REST routes.
 *
 * @return void
 */
function hpva_register_rest() {
	register_rest_route(
		'hpva/v1',
		'/export',
		[
			'methods'             => 'GET',
			// Cookie auth: WordPress authenticates the user when a valid
			// _wpnonce (action wp_rest) query parameter accompanies the
			// request; without it the user is 0 and this check fails.
			'permission_callback' => 'is_user_logged_in',
			'callback'            => 'hpva_rest_export',
		]
	);

	register_rest_route(
		'hpva/v1',
		'/track',
		[
			'methods'             => 'POST',
			// Deliberately public: cached pages cannot carry fresh nonces (see
			// header notes). Integrity is enforced below instead.
			'permission_callback' => '__return_true',
			'callback'            => 'hpva_rest_track',
		]
	);
}

/**
 * Checks whether the current request comes from a known crawler.
 *
 * @return bool
 */
function hpva_is_bot() {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	return '' === $ua || preg_match( '/bot|crawl|spider|slurp|preview|facebookexternalhit|headless/i', $ua );
}

/**
 * Handles beacon tracking requests.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function hpva_rest_track( $request ) {
	// Bot filter (JS beacons already exclude most crawlers; belt and braces).
	if ( hpva_is_bot() ) {
		return new WP_REST_Response( null, 204 );
	}

	// Per-IP rate limit: 60 events / 10 minutes.
	$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$bucket = 'hpva_rl_' . md5( $ip );
	$count  = (int) get_transient( $bucket );

	if ( $count >= 60 ) {
		return new WP_REST_Response( null, 429 );
	}

	set_transient( $bucket, $count + 1, 10 * MINUTE_IN_SECONDS );

	$metric     = sanitize_key( (string) $request->get_param( 'm' ) );
	$listing_id = (int) $request->get_param( 'l' );
	$vendor_id  = (int) $request->get_param( 'v' );

	$view_metrics  = [ 'view', 'vendor_view' ];
	$click_metrics = [ 'phone_click', 'email_click' ];

	if ( in_array( $metric, $view_metrics, true ) && ! hpva_get_option( 'vendor_analytics_views', true ) ) {
		return new WP_REST_Response( null, 204 );
	}

	if ( in_array( $metric, $click_metrics, true ) && ! hpva_get_option( 'vendor_analytics_clicks', true ) ) {
		return new WP_REST_Response( null, 204 );
	}

	if ( 'vendor_view' === $metric ) {
		$vendor_post = get_post( $vendor_id );

		if ( $vendor_post && 'hp_vendor' === $vendor_post->post_type && 'publish' === $vendor_post->post_status ) {
			hpva_record( 'vendor_view', $vendor_id, 0 );
		}

		return new WP_REST_Response( null, 204 );
	}

	if ( in_array( $metric, array_merge( [ 'view' ], $click_metrics ), true ) ) {
		if ( $listing_id ) {
			$listing_post = get_post( $listing_id );

			if ( $listing_post && 'hp_listing' === $listing_post->post_type && 'publish' === $listing_post->post_status ) {
				// Vendor is resolved server-side from the listing; the client's
				// value is never trusted for listing metrics.
				hpva_record( $metric, hpva_vendor_id_from_listing( $listing_id ), $listing_id );
			}
		} elseif ( $vendor_id && in_array( $metric, $click_metrics, true ) ) {
			// Contact clicks on vendor profile pages are vendor-scoped.
			$vendor_post = get_post( $vendor_id );

			if ( $vendor_post && 'hp_vendor' === $vendor_post->post_type && 'publish' === $vendor_post->post_status ) {
				hpva_record( $metric, $vendor_id, 0 );
			}
		}
	}

	return new WP_REST_Response( null, 204 );
}

/*
--------------------------------------------------------------------------
Read path (dashboard queries).
--------------------------------------------------------------------------
*/

/**
 * Resolves a period selection into [from, to] Y-m-d dates in site timezone.
 * Period 0 = all time (from the earliest recorded date).
 *
 * @param int $period Period in days (0 for all time).
 * @return array{0: string, 1: string}
 */
function hpva_range( $period ) {
	global $wpdb;

	$to = current_time( 'Y-m-d' );

	if ( 0 === $period ) {
		$min  = $wpdb->get_var( 'SELECT MIN(stat_date) FROM ' . hpva_table() ); // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
		$from = $min ? $min : $to;
	} else {
		$from = gmdate( 'Y-m-d', strtotime( $to . ' UTC' ) - ( $period - 1 ) * DAY_IN_SECONDS );
	}

	return [ $from, $to ];
}

/**
 * Sums a metric for a scope and date range.
 *
 * @param int      $vendor_id Vendor ID.
 * @param string   $metric Metric key.
 * @param string   $from Start date (Y-m-d).
 * @param string   $to End date (Y-m-d).
 * @param int|null $listing_id Optional listing scope.
 * @return int
 */
function hpva_total( $vendor_id, $metric, $from, $to, $listing_id = null ) {
	global $wpdb;

	$sql    = 'SELECT COALESCE(SUM(value),0) FROM ' . hpva_table() . ' WHERE metric = %s AND stat_date BETWEEN %s AND %s';
	$params = [ $metric, $from, $to ];

	if ( null !== $listing_id ) {
		$sql     .= ' AND listing_id = %d';
		$params[] = (int) $listing_id;
	} else {
		$sql     .= ' AND vendor_id = %d';
		$params[] = (int) $vendor_id;
	}

	return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
}

/**
 * All metric totals for a scope in ONE grouped query: metric => sum map.
 *
 * @param int      $vendor_id Vendor ID.
 * @param string   $from Start date (Y-m-d).
 * @param string   $to End date (Y-m-d).
 * @param int|null $listing_id Optional listing scope.
 * @return array<string, int>
 */
function hpva_totals_map( $vendor_id, $from, $to, $listing_id = null ) {
	global $wpdb;

	$sql    = 'SELECT metric, COALESCE(SUM(value),0) AS v FROM ' . hpva_table() . ' WHERE stat_date BETWEEN %s AND %s';
	$params = [ $from, $to ];

	if ( null !== $listing_id ) {
		$sql     .= ' AND listing_id = %d';
		$params[] = (int) $listing_id;
	} else {
		$sql     .= ' AND vendor_id = %d';
		$params[] = (int) $vendor_id;
	}

	$sql .= ' GROUP BY metric';

	$map = [];

	foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) as $row ) { // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
		$map[ $row->metric ] = (int) $row->v;
	}

	return $map;
}

/**
 * The equal-length period immediately before [from, to]. Pure.
 *
 * @param string $from Start date (Y-m-d).
 * @param string $to End date (Y-m-d).
 * @return array{0: string, 1: string}
 */
function hpva_prev_range( $from, $to ) {
	$from_ts = strtotime( $from . ' UTC' );
	$to_ts   = strtotime( $to . ' UTC' );
	$days    = (int) ( ( $to_ts - $from_ts ) / DAY_IN_SECONDS ) + 1;

	$prev_to   = $from_ts - DAY_IN_SECONDS;
	$prev_from = $prev_to - ( $days - 1 ) * DAY_IN_SECONDS;

	return [ gmdate( 'Y-m-d', $prev_from ), gmdate( 'Y-m-d', $prev_to ) ];
}

/**
 * Period-over-period change descriptor. Pure.
 * Returns null (nothing to show), or [ 'dir' => up|down|flat, 'text' => str ].
 *
 * @param int|float $current Current period value.
 * @param int|float $previous Previous period value.
 * @return array{dir: string, text: string}|null
 */
function hpva_delta( $current, $previous ) {
	$current  = (float) $current;
	$previous = (float) $previous;

	if ( $previous <= 0 && $current <= 0 ) {
		return null;
	}

	if ( $previous <= 0 ) {
		return [
			'dir'  => 'up',
			'text' => __( 'New', 'hivepress-vendor-analytics' ),
		];
	}

	$pct = (int) round( 100 * ( $current - $previous ) / $previous );

	if ( 0 === $pct ) {
		return [
			'dir'  => 'flat',
			'text' => '0%',
		];
	}

	return [
		'dir'  => $pct > 0 ? 'up' : 'down',
		'text' => ( $pct > 0 ? '+' : '' ) . $pct . '%',
	];
}

/**
 * Daily series for a metric, gap-filled with zeros between $from and $to.
 * For all-time views the chart caller caps the window; totals stay all-time.
 *
 * @param int      $vendor_id Vendor ID.
 * @param string   $metric Metric key.
 * @param string   $from Start date (Y-m-d).
 * @param string   $to End date (Y-m-d).
 * @param int|null $listing_id Optional listing scope.
 * @return array<string, int>
 */
function hpva_series( $vendor_id, $metric, $from, $to, $listing_id = null ) {
	global $wpdb;

	$sql    = 'SELECT stat_date, SUM(value) AS v FROM ' . hpva_table() . ' WHERE metric = %s AND stat_date BETWEEN %s AND %s';
	$params = [ $metric, $from, $to ];

	if ( null !== $listing_id ) {
		$sql     .= ' AND listing_id = %d';
		$params[] = (int) $listing_id;
	} else {
		$sql     .= ' AND vendor_id = %d';
		$params[] = (int) $vendor_id;
	}

	$sql .= ' GROUP BY stat_date';

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), OBJECT_K ); // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.

	return hpva_fill_series( $rows, $from, $to );
}

/**
 * Pure helper: turns keyed DB rows into a continuous date => int map.
 *
 * @param array<string, object> $rows Keyed database rows.
 * @param string                $from Start date (Y-m-d).
 * @param string                $to End date (Y-m-d).
 * @return array<string, int>
 */
function hpva_fill_series( $rows, $from, $to ) {
	$series = [];
	$cursor = strtotime( $from . ' UTC' );
	$end    = strtotime( $to . ' UTC' );

	if ( false === $cursor || false === $end || $cursor > $end ) {
		return $series;
	}

	// Hard safety cap: never build more than 400 points.
	$steps = 0;

	while ( $cursor <= $end && $steps < 400 ) {
		$day            = gmdate( 'Y-m-d', $cursor );
		$series[ $day ] = isset( $rows[ $day ] ) ? (int) $rows[ $day ]->v : 0;
		$cursor        += DAY_IN_SECONDS;
		++$steps;
	}

	return $series;
}

/**
 * Gets per-listing metric sums for a range.
 *
 * @param int    $vendor_id Vendor ID.
 * @param string $from Start date (Y-m-d).
 * @param string $to End date (Y-m-d).
 * @return array<int, array<string, int>>
 */
function hpva_listing_breakdown( $vendor_id, $from, $to ) {
	global $wpdb;

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
		$wpdb->prepare(
			'SELECT listing_id, metric, SUM(value) AS v FROM ' . hpva_table() . '
			 WHERE vendor_id = %d AND listing_id > 0 AND stat_date BETWEEN %s AND %s
			 GROUP BY listing_id, metric',
			(int) $vendor_id,
			$from,
			$to
		)
	);

	$breakdown = [];

	foreach ( (array) $rows as $row ) {
		$breakdown[ (int) $row->listing_id ][ $row->metric ] = (int) $row->v;
	}

	return $breakdown;
}

/**
 * Gets the top search terms for a scope and range.
 *
 * @param int      $vendor_id Vendor ID.
 * @param string   $from Start date (Y-m-d).
 * @param string   $to End date (Y-m-d).
 * @param int      $limit Maximum rows.
 * @param int|null $listing_id Optional listing scope.
 * @return array<int, object>
 */
function hpva_top_terms( $vendor_id, $from, $to, $limit = 10, $listing_id = null ) {
	global $wpdb;

	if ( null !== $listing_id ) {
		return $wpdb->get_results( // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
			$wpdb->prepare(
				'SELECT term, SUM(impressions) AS impressions FROM ' . hpva_terms_table() . '
				 WHERE listing_id = %d AND stat_date BETWEEN %s AND %s
				 GROUP BY term ORDER BY impressions DESC LIMIT %d',
				(int) $listing_id,
				$from,
				$to,
				(int) $limit
			)
		);
	}

	$table = hpva_terms_table();

	return $wpdb->get_results( // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
		$wpdb->prepare(
			"SELECT t.term, SUM(t.impressions) AS impressions FROM {$table} t
			 INNER JOIN {$wpdb->posts} p ON p.ID = t.listing_id
			 WHERE p.post_type = 'hp_listing' AND p.post_parent = %d
			 AND t.stat_date BETWEEN %s AND %s
			 GROUP BY t.term ORDER BY impressions DESC LIMIT %d",
			(int) $vendor_id,
			$from,
			$to,
			(int) $limit
		)
	);
}

/**
 * Average daily views per published listing for the vendor vs. their most
 * common listing category. Cached for 6 hours per (term, range).
 *
 * @return array{vendor_avg: float, category_avg: float, category_name: string}|null
 *
 * @param int    $vendor_id Vendor ID.
 * @param string $from      Start date (Y-m-d).
 * @param string $to        End date (Y-m-d).
 * @return array{vendor_avg: float, category_avg: float, category_name: string}|null
 */
function hpva_benchmark( $vendor_id, $from, $to ) {
	global $wpdb;

	$days = max( 1, (int) ( ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / DAY_IN_SECONDS ) + 1 );

	// Vendor side.
	$vendor_listings = get_posts(
		[
			'post_type'        => 'hp_listing',
			'post_status'      => 'publish',
			'post_parent'      => (int) $vendor_id,
			'fields'           => 'ids',
			'posts_per_page'   => 100,
			'suppress_filters' => false,
		]
	);

	if ( ! $vendor_listings ) {
		return null;
	}

	$vendor_views = hpva_total( $vendor_id, 'view', $from, $to );
	$vendor_avg   = $vendor_views / count( $vendor_listings ) / $days;

	// Most common category across the vendor's listings (hp_listing_category
	// is the verified core taxonomy).
	$term_counts = [];

	foreach ( $vendor_listings as $lid ) {
		foreach ( (array) wp_get_post_terms( $lid, 'hp_listing_category', [ 'fields' => 'ids' ] ) as $tid ) {
			$tid                 = (int) $tid;
			$term_counts[ $tid ] = isset( $term_counts[ $tid ] ) ? $term_counts[ $tid ] + 1 : 1;
		}
	}

	if ( ! $term_counts ) {
		return null;
	}

	arsort( $term_counts );
	$term_id = (int) array_key_first( $term_counts );
	$term    = get_term( $term_id, 'hp_listing_category' );

	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}

	$cache_key = 'hpva_bm_' . $term_id . '_' . md5( $from . $to );
	$cat_avg   = get_transient( $cache_key );

	if ( false === $cat_avg ) {
		$cat_listings = get_posts(
			[
				'post_type'        => 'hp_listing',
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'posts_per_page'   => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- deliberately capped benchmark sample.
				'suppress_filters' => false,
				'tax_query'        => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => 'hp_listing_category',
						'terms'    => $term_id,
					],
				],
			]
		);

		$cat_avg = 0;

		if ( $cat_listings ) {
			$placeholders = implode( ',', array_fill( 0, count( $cat_listings ), '%d' ) );

			$cat_views = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
				$wpdb->prepare( // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
					'SELECT COALESCE(SUM(value),0) FROM ' . hpva_table() . "
					 WHERE metric = 'view' AND stat_date BETWEEN %s AND %s
					 AND listing_id IN ( $placeholders )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( [ $from, $to ], array_map( 'intval', $cat_listings ) )
				)
			);

			$cat_avg = $cat_views / count( $cat_listings ) / $days;
		}

		set_transient( $cache_key, $cat_avg, 6 * HOUR_IN_SECONDS );
	}

	return [
		'vendor_avg'    => $vendor_avg,
		'category_avg'  => (float) $cat_avg,
		'category_name' => $term->name,
	];
}

/**
 * Deletes aggregates older than the retention setting.
 *
 * @return void
 */
function hpva_prune() {
	global $wpdb;

	$days = (int) hpva_get_option( 'vendor_analytics_retention', 0 );

	if ( $days < 1 ) {
		return;
	}

	$cutoff = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' UTC' ) - $days * DAY_IN_SECONDS );

	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . hpva_table() . ' WHERE stat_date < %s', $cutoff ) ); // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . hpva_terms_table() . ' WHERE stat_date < %s', $cutoff ) ); // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
}

/*
--------------------------------------------------------------------------
Account menu + Statistics page injection.
--------------------------------------------------------------------------
*/

/**
 * Adds the Analytics item to the account menu for vendors.
 *
 * @param array<string, mixed> $items Menu items.
 * @return array<string, mixed>
 */
function hpva_account_menu_item( $items ) {
	if ( is_user_logged_in() && hpva_vendor_id_from_user( get_current_user_id() ) ) {
		$items['vendor_analytics'] = [
			'label'  => esc_html__( 'Analytics', 'hivepress-vendor-analytics' ),
			'url'    => hivepress()->router->get_url( 'vendor_analytics_page' ),
			'_order' => 25,
		];
	}

	return $items;
}

/**
 * Adds the Analytics tab to the listing manage menu.
 *
 * @param array<string, mixed> $items Menu items.
 * @param object               $menu Menu object.
 * @return array<string, mixed>
 */
function hpva_listing_manage_menu_item( $items, $menu ) {
	$listing = $menu->get_context( 'listing' );

	if ( is_object( $listing ) && method_exists( $listing, 'get_status' ) && 'publish' === $listing->get_status() ) {
		$items['listing_analytics'] = [
			'label'  => esc_html__( 'Analytics', 'hivepress-vendor-analytics' ),
			'url'    => hivepress()->router->get_url( 'listing_analytics_page', [ 'listing_id' => $listing->get_id() ] ),
			'_order' => 55,
		];
	}

	return $items;
}

/**
 * Checks whether a dashboard section is enabled.
 *
 * @param string $key Section key.
 * @return bool
 */
function hpva_section_on( $key ) {
	$sections = hpva_get_option( 'vendor_analytics_sections', [ 'summary', 'funnel', 'trend', 'response', 'earnings', 'benchmark', 'terms', 'breakdown', 'export' ] );

	return is_array( $sections ) && in_array( $key, $sections, true );
}

/**
 * Injects the summary above the Statistics extension chart.
 *
 * @param array<string, mixed> $template Template arguments.
 * @return array<string, mixed>
 */
function hpva_inject_statistics_summary( $template ) {
	return hivepress()->helper->merge_trees(
		$template,
		[
			'blocks' => [
				'page_content' => [
					'blocks' => [
						'vendor_analytics_summary' => [
							'type'   => 'vendor_analytics_summary',
							'_order' => 5,
						],
					],
				],
			],
		]
	);
}

/*
--------------------------------------------------------------------------
Formatting + SVG charts (pure functions, unit-tested in isolation).
--------------------------------------------------------------------------
*/

/**
 * Formats a minor-units amount with the shop currency symbol.
 *
 * @param int $minor Amount in minor units.
 * @return string
 */
function hpva_money( $minor ) {
	$symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '';

	list( $factor, $decimals ) = hpva_currency_scale();

	return $symbol . number_format_i18n( $minor / $factor, $decimals );
}

/**
 * The shop currency's minor-unit scale as [ factor, decimals ]. WooCommerce
 * currencies range from 0 decimals (e.g. JPY) to 3 (e.g. KWD); the store's
 * "number of decimals" setting is authoritative, defaulting to 2. Both the
 * write path (earnings stored as amount x factor) and the read path
 * (formatted as value / factor) use this, so earnings scale correctly for any
 * currency.
 *
 * @return array{0: int, 1: int}
 */
function hpva_currency_scale() {
	$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

	if ( $decimals < 0 || $decimals > 4 ) {
		$decimals = 2;
	}

	return [ (int) pow( 10, $decimals ), $decimals ];
}

/**
 * Formats seconds as a human-readable duration.
 *
 * @param int $seconds Duration in seconds.
 * @return string
 */
function hpva_duration( $seconds ) {
	$seconds = (int) $seconds;

	if ( $seconds < 90 * 60 ) {
		// translators: %s: number of minutes.
		return sprintf( __( '%s min', 'hivepress-vendor-analytics' ), number_format_i18n( max( 1, round( $seconds / 60 ) ) ) );
	}

	if ( $seconds < DAY_IN_SECONDS ) {
		// translators: %s: number of hours.
		return sprintf( __( '%s h', 'hivepress-vendor-analytics' ), number_format_i18n( round( $seconds / HOUR_IN_SECONDS, 1 ), 1 ) );
	}

	// translators: %s: number of days.
	return sprintf( __( '%s days', 'hivepress-vendor-analytics' ), number_format_i18n( round( $seconds / DAY_IN_SECONDS, 1 ), 1 ) );
}

/**
 * Rounds up to a "nice" axis maximum (1/2/5 x 10^k).
 *
 * @return int
 *
 * @param int|float $value Raw maximum.
 * @return int
 */
function hpva_nice_max( $value ) {
	if ( $value <= 1 ) {
		return 1;
	}

	$power = pow( 10, floor( log10( $value ) ) );
	$unit  = $value / $power;

	if ( $unit <= 1 ) {
		$nice = 1;
	} elseif ( $unit <= 2 ) {
		$nice = 2;
	} elseif ( $unit <= 5 ) {
		$nice = 5;
	} else {
		$nice = 10;
	}

	return (int) ( $nice * $power );
}

/**
 * Maps a series to SVG polyline points. Pure; returns array of [x, y] floats.
 *
 * @return array<int, array{0: float, 1: float}>
 *
 * @param array<int, int|float> $values Series values.
 * @param int                   $width  Chart width.
 * @param int                   $height Chart height.
 * @param int                   $pad    Chart padding.
 * @param int                   $max    Axis maximum.
 * @return array<int, array{0: float, 1: float}>
 */
function hpva_chart_points( $values, $width, $height, $pad, $max ) {
	$count  = count( $values );
	$points = [];

	if ( ! $count || $max <= 0 ) {
		return $points;
	}

	$inner_w = $width - 2 * $pad;
	$inner_h = $height - 2 * $pad;
	$step    = $count > 1 ? $inner_w / ( $count - 1 ) : 0;
	$i       = 0;

	foreach ( $values as $value ) {
		$x        = $count > 1 ? $pad + $i * $step : $width / 2;
		$y        = $pad + $inner_h - ( min( $value, $max ) / $max ) * $inner_h;
		$points[] = [ round( $x, 2 ), round( $y, 2 ) ];
		++$i;
	}

	return $points;
}

/**
 * Renders a responsive multi-series SVG line chart.
 *
 * @param array<int, array{label: string, color: string, series: array<string, int>}> $datasets [ [ 'label' => string, 'color' => hex, 'series' => [date => int] ], ... ]
 *
 * @param array<int, array{label: string, color: string, series: array<string, int>}> $datasets Chart datasets.
 * @param int                                                                         $height Chart height.
 * @return string
 */
function hpva_svg_line( $datasets, $height = 200 ) {
	$width = 640;
	$pad   = 28;

	$all_values = [];
	$labels     = [];

	foreach ( $datasets as $set ) {
		$all_values = array_merge( $all_values, array_values( $set['series'] ) );

		if ( ! $labels ) {
			$labels = array_keys( $set['series'] );
		}
	}

	if ( ! $labels ) {
		return '';
	}

	$max = hpva_nice_max( max( 1, max( $all_values ) ) );

	$svg = '<svg class="hpva-chart" viewBox="0 0 ' . (int) $width . ' ' . (int) $height . '" preserveAspectRatio="xMidYMid meet" role="img">';

	// Grid: bottom, middle, top.
	foreach ( [ 0, 0.5, 1 ] as $frac ) {
		$y    = $pad + ( $height - 2 * $pad ) * ( 1 - $frac );
		$val  = (int) round( $max * $frac );
		$svg .= '<line x1="' . $pad . '" y1="' . round( $y, 2 ) . '" x2="' . ( $width - $pad ) . '" y2="' . round( $y, 2 ) . '" stroke="rgba(0,0,0,.08)" stroke-width="1"/>';
		$svg .= '<text x="' . ( $pad - 6 ) . '" y="' . round( $y + 4, 2 ) . '" text-anchor="end" font-size="10" fill="#8a94a5">' . esc_html( number_format_i18n( $val ) ) . '</text>';
	}

	// First/last date labels.
	$first = reset( $labels );
	$last  = end( $labels );
	$svg  .= '<text x="' . $pad . '" y="' . ( $height - 6 ) . '" font-size="10" fill="#8a94a5">' . esc_html( date_i18n( 'j M', strtotime( $first . ' UTC' ) ) ) . '</text>';
	$svg  .= '<text x="' . ( $width - $pad ) . '" y="' . ( $height - 6 ) . '" text-anchor="end" font-size="10" fill="#8a94a5">' . esc_html( date_i18n( 'j M', strtotime( $last . ' UTC' ) ) ) . '</text>';

	foreach ( $datasets as $set ) {
		$points = hpva_chart_points( array_values( $set['series'] ), $width, $height, $pad, $max );

		if ( count( $points ) < 2 ) {
			continue;
		}

		$attr = implode(
			' ',
			array_map(
				function ( $p ) {
					return $p[0] . ',' . $p[1];
				},
				$points
			)
		);

		$color = hpva_safe_hex( $set['color'] );
		$svg  .= '<polyline points="' . esc_attr( $attr ) . '" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
	}

	$svg .= '</svg>';

	// Legend.
	if ( count( $datasets ) > 1 ) {
		$svg .= '<div class="hpva-legend">';

		foreach ( $datasets as $set ) {
			$svg .= '<span class="hpva-legend__item"><span class="hpva-legend__dot" style="background:' . esc_attr( hpva_safe_hex( $set['color'] ) ) . '"></span>' . esc_html( $set['label'] ) . '</span>';
		}

		$svg .= '</div>';
	}

	return $svg;
}

/**
 * Validates a hex colour, falling back to the default accent.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function hpva_safe_hex( $value ) {
	return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string) $value ) ? $value : '#6b7cf6';
}

/**
 * Funnel step percentages relative to the previous step. Pure.
 *
 * @return array<int, array{label: string, value: int, rate: float|null, width: int|float}>
 *
 * @param array<int, array{label: string, value: int}> $steps Raw funnel steps.
 * @return array<int, array{label: string, value: int, rate: float|null, width: int|float}>
 */
function hpva_funnel_steps( $steps ) {
	$out  = [];
	$prev = null;
	$top  = 0;

	foreach ( $steps as $step ) {
		$value = max( 0, (int) $step['value'] );
		$top   = max( $top, $value );

		$out[] = [
			'label' => $step['label'],
			'value' => $value,
			'rate'  => ( null !== $prev && $prev > 0 ) ? round( 100 * $value / $prev, 1 ) : null,
			'width' => 0, // filled below once $top known
		];

		$prev = $value;
	}

	foreach ( $out as $i => $step ) {
		$out[ $i ]['width'] = $top > 0 ? max( 2, round( 100 * $step['value'] / $top ) ) : 2;
	}

	return $out;
}

/**
 * Renders the conversion funnel HTML.
 *
 * @param array<int, array{label: string, value: int}> $steps Funnel steps.
 * @return string
 */
function hpva_render_funnel( $steps ) {
	$html = '<div class="hpva-funnel">';

	foreach ( hpva_funnel_steps( $steps ) as $step ) {
		$html .= '<div class="hpva-funnel__row">';
		$html .= '<span class="hpva-funnel__label">' . esc_html( $step['label'] ) . '</span>';
		$html .= '<span class="hpva-funnel__track"><span class="hpva-funnel__bar" style="width:' . (int) $step['width'] . '%"></span></span>';
		$html .= '<span class="hpva-funnel__value">' . esc_html( number_format_i18n( $step['value'] ) );

		if ( null !== $step['rate'] ) {
			$html .= ' <small>(' . esc_html( number_format_i18n( $step['rate'], 1 ) ) . '%)</small>';
		}

		$html .= '</span></div>';
	}

	return $html . '</div>';
}

/*
--------------------------------------------------------------------------
Dashboard + listing summary renderers (called by the block class).
--------------------------------------------------------------------------
*/

/**
 * Gets the requested period from the query string.
 *
 * @return int
 */
function hpva_current_period() {
	$allowed = [ 7, 30, 90, 365, 0 ];
	$period  = isset( $_GET['hpva_period'] ) ? (int) $_GET['hpva_period'] : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	return in_array( $period, $allowed, true ) ? $period : 30;
}

/**
 * Renders the period switcher links.
 *
 * @param int $current Current period in days (0 for all time).
 * @return string
 */
function hpva_period_switcher( $current ) {
	$options = [
		7   => __( '7 days', 'hivepress-vendor-analytics' ),
		30  => __( '30 days', 'hivepress-vendor-analytics' ),
		90  => __( '90 days', 'hivepress-vendor-analytics' ),
		365 => __( '1 year', 'hivepress-vendor-analytics' ),
		0   => __( 'All time', 'hivepress-vendor-analytics' ),
	];

	$html = '<div class="hpva-periods">';

	foreach ( $options as $value => $label ) {
		$url   = esc_url( add_query_arg( 'hpva_period', $value ) );
		$class = ( $value === $current ) ? ' hpva-periods__item--active' : '';
		$html .= '<a class="hpva-periods__item' . $class . '" href="' . $url . '">' . esc_html( $label ) . '</a>';
	}

	return $html . '</div>';
}

/**
 * Renders a summary card.
 *
 * @param string                                $label Card label.
 * @param string                                $value Card value.
 * @param string                                $note Optional note.
 * @param array{dir: string, text: string}|null $delta Optional delta descriptor.
 * @param bool                                  $invert Whether a downward change is positive.
 * @return string
 */
function hpva_card( $label, $value, $note = '', $delta = null, $invert = false ) {
	$html = '<div class="hpva-card"><span class="hpva-card__value">' . esc_html( $value );

	if ( is_array( $delta ) ) {
		$good  = ( 'up' === $delta['dir'] ) !== $invert;
		$tone  = 'flat' === $delta['dir'] ? 'flat' : ( $good ? 'good' : 'bad' );
		$icon  = 'up' === $delta['dir'] ? '&#8593;' : ( 'down' === $delta['dir'] ? '&#8595;' : '' );
		$html .= ' <span class="hpva-delta hpva-delta--' . esc_attr( $tone ) . '">' . $icon . esc_html( $delta['text'] ) . '</span>';
	}

	$html .= '</span>';
	$html .= '<span class="hpva-card__label">' . esc_html( $label ) . '</span>';

	if ( $note ) {
		$html .= '<span class="hpva-card__note">' . esc_html( $note ) . '</span>';
	}

	return $html . '</div>';
}

/**
 * The vendor account dashboard.
 *
 * @return string
 */
function hpva_render_dashboard() {
	$vendor_id = hpva_vendor_id_from_user( get_current_user_id() );

	if ( ! $vendor_id ) {
		return '<p>' . esc_html__( 'Analytics are available once your vendor profile is set up.', 'hivepress-vendor-analytics' ) . '</p>';
	}

	$period            = hpva_current_period();
	list( $from, $to ) = hpva_range( $period );

	// Charts are capped at the most recent 365 days even on "All time".
	$chart_from = $from;

	if ( 0 === $period ) {
		$year_ago   = gmdate( 'Y-m-d', strtotime( $to . ' UTC' ) - 364 * DAY_IN_SECONDS );
		$chart_from = max( $from, $year_ago );
	}

	$cur  = hpva_totals_map( $vendor_id, $from, $to );
	$prev = [];

	if ( 0 !== $period ) {
		list( $p_from, $p_to ) = hpva_prev_range( $from, $to );
		$prev                  = hpva_totals_map( $vendor_id, $p_from, $p_to );
	}

	/**
	 * @param string $key Metric key.
	 * @return int
	 */
	$m = function ( $key ) use ( $cur ) {
		return isset( $cur[ $key ] ) ? $cur[ $key ] : 0;
	};

	/**
	 * @param string $key Metric key.
	 * @return array{dir: string, text: string}|null
	 */
	$d = function ( $key ) use ( $cur, $prev, $period ) {
		if ( 0 === $period ) {
			return null;
		}

		return hpva_delta(
			isset( $cur[ $key ] ) ? $cur[ $key ] : 0,
			isset( $prev[ $key ] ) ? $prev[ $key ] : 0
		);
	};

	$views    = $m( 'view' );
	$vviews   = $m( 'vendor_view' );
	$messages = $m( 'message' );
	$b_new    = $m( 'booking_new' );
	$b_conf   = $m( 'booking_confirmed' );
	$phone    = $m( 'phone_click' );
	$email    = $m( 'email_click' );
	$orders   = $m( 'order' );
	$earnings = $m( 'earning_minor' );
	$resp_sum = $m( 'response_sum' );
	$resp_n   = $m( 'response_count' );

	$out  = hpva_css();
	$out .= '<div class="hpva">';

	// Cache-safe self-view exclusion: flag this browser as the vendor's so the
	// front-end beacon skips their own visits (cached pages cannot identify
	// the viewer server-side).
	$out .= '<script>try{localStorage.setItem("hpvaOwner","' . (int) $vendor_id . '");}catch(e){}</script>';

	$out .= '<p class="hpva-sub">' . esc_html__( 'All your listings combined. Open a listing\'s own Analytics tab for listing-specific figures.', 'hivepress-vendor-analytics' ) . '</p>';
	$out .= hpva_period_switcher( $period );

	if ( 0 !== $period ) {
		$out .= '<p class="hpva-sub hpva-sub--delta">' . sprintf(
			// translators: %s: number of days.
			esc_html__( 'Changes compare against the previous %s days.', 'hivepress-vendor-analytics' ),
			number_format_i18n( $period )
		) . '</p>';
	}

	$out .= hpva_export_buttons( $period );

	// Summary cards.
	if ( hpva_section_on( 'summary' ) ) :
		$out .= '<div class="hpva-cards">';
		$out .= hpva_card( __( 'Listing views', 'hivepress-vendor-analytics' ), number_format_i18n( $views ), '', $d( 'view' ) );
		$out .= hpva_card( __( 'Profile views', 'hivepress-vendor-analytics' ), number_format_i18n( $vviews ), '', $d( 'vendor_view' ) );
		$out .= hpva_card( __( 'Messages received', 'hivepress-vendor-analytics' ), number_format_i18n( $messages ), '', $d( 'message' ) );

		if ( class_exists( '\HivePress\Models\Booking' ) ) {
			$out .= hpva_card( __( 'Bookings confirmed', 'hivepress-vendor-analytics' ), number_format_i18n( $b_conf ), '', $d( 'booking_confirmed' ) );
		}

		if ( class_exists( '\HivePress\Models\Favorite' ) ) {
			$out .= hpva_card( __( 'Favourites gained', 'hivepress-vendor-analytics' ), number_format_i18n( $m( 'favorite' ) ), '', $d( 'favorite' ) );
		}

		$out .= hpva_card( __( 'Phone clicks', 'hivepress-vendor-analytics' ), number_format_i18n( $phone ), '', $d( 'phone_click' ) );
		$out .= hpva_card( __( 'Email clicks', 'hivepress-vendor-analytics' ), number_format_i18n( $email ), '', $d( 'email_click' ) );

		if ( class_exists( '\HivePress\Models\Offer' ) ) {
			$out .= hpva_card( __( 'Offers sent', 'hivepress-vendor-analytics' ), number_format_i18n( $m( 'offer_sent' ) ), '', $d( 'offer_sent' ) );

			if ( $m( 'offer_accepted' ) || ( isset( $prev['offer_accepted'] ) && $prev['offer_accepted'] ) ) {
				$out .= hpva_card( __( 'Offers accepted', 'hivepress-vendor-analytics' ), number_format_i18n( $m( 'offer_accepted' ) ), '', $d( 'offer_accepted' ) );
			}
		}

		if ( $orders || $earnings ) {
			$out .= hpva_card( __( 'Orders completed', 'hivepress-vendor-analytics' ), number_format_i18n( $orders ), '', $d( 'order' ) );
			$out .= hpva_card( __( 'Earnings', 'hivepress-vendor-analytics' ), hpva_money( $earnings ), '', $d( 'earning_minor' ) );
		}

		if ( $resp_n > 0 ) {
			$prev_avg  = ( isset( $prev['response_count'] ) && $prev['response_count'] > 0 ) ? $prev['response_sum'] / $prev['response_count'] : 0;
			$avg_delta = ( 0 !== $period ) ? hpva_delta( $resp_sum / $resp_n, $prev_avg ) : null;
			$out      .= hpva_card( __( 'Avg first response', 'hivepress-vendor-analytics' ), hpva_duration( $resp_sum / $resp_n ), '', $avg_delta, true );
		}

		$out .= '</div>';
	endif;

	// Funnel.
	$funnel_steps = [
		[
			'label' => __( 'Views', 'hivepress-vendor-analytics' ),
			'value' => $views,
		],
		[
			'label' => __( 'Messages', 'hivepress-vendor-analytics' ),
			'value' => $messages,
		],
	];

	if ( class_exists( '\HivePress\Models\Booking' ) ) {
		$funnel_steps[] = [
			'label' => __( 'Bookings', 'hivepress-vendor-analytics' ),
			'value' => $b_conf,
		];
	}

	if ( hpva_section_on( 'funnel' ) ) {
		$out .= '<h3 class="hpva-h">' . esc_html__( 'Conversion funnel', 'hivepress-vendor-analytics' ) . '</h3>';
		$out .= hpva_render_funnel( $funnel_steps );
	}

	// Views + messages trend.
	if ( hpva_section_on( 'trend' ) ) :
		$out .= '<h3 class="hpva-h">' . esc_html__( 'Views & messages', 'hivepress-vendor-analytics' ) . '</h3>';
		$out .= hpva_svg_line(
			[
				[
					'label'  => __( 'Views', 'hivepress-vendor-analytics' ),
					'color'  => '#6b7cf6',
					'series' => hpva_series( $vendor_id, 'view', $chart_from, $to ),
				],
				[
					'label'  => __( 'Messages', 'hivepress-vendor-analytics' ),
					'color'  => '#f6a56b',
					'series' => hpva_series( $vendor_id, 'message', $chart_from, $to ),
				],
			]
		);

	endif;

	// Response-time trend (daily average where samples exist).
	if ( hpva_section_on( 'response' ) && $resp_n > 0 ) {
		$sum_series   = hpva_series( $vendor_id, 'response_sum', $chart_from, $to );
		$count_series = hpva_series( $vendor_id, 'response_count', $chart_from, $to );
		$avg_series   = [];

		foreach ( $sum_series as $day => $sum ) {
			$n                  = isset( $count_series[ $day ] ) ? $count_series[ $day ] : 0;
			$avg_series[ $day ] = $n > 0 ? (int) round( $sum / $n / 60 ) : 0; // minutes
		}

		$out .= '<h3 class="hpva-h">' . esc_html__( 'First response time (minutes, daily average)', 'hivepress-vendor-analytics' ) . '</h3>';
		$out .= hpva_svg_line(
			[
				[
					'label'  => __( 'Minutes', 'hivepress-vendor-analytics' ),
					'color'  => '#4fb286',
					'series' => $avg_series,
				],
			]
		);
	}

	// Earnings trend.
	if ( hpva_section_on( 'earnings' ) && $earnings > 0 ) {
		$e_series       = hpva_series( $vendor_id, 'earning_minor', $chart_from, $to );
		list( $factor ) = hpva_currency_scale();

		foreach ( $e_series as $day => $minor ) {
			$e_series[ $day ] = (int) round( $minor / $factor );
		}

		$out .= '<h3 class="hpva-h">' . esc_html__( 'Earnings', 'hivepress-vendor-analytics' ) . '</h3>';
		$out .= hpva_svg_line(
			[
				[
					'label'  => __( 'Earnings', 'hivepress-vendor-analytics' ),
					'color'  => '#4fb286',
					'series' => $e_series,
				],
			]
		);
	}

	// Benchmark.
	if ( hpva_section_on( 'benchmark' ) && hpva_get_option( 'vendor_analytics_benchmark', true ) ) {
		$benchmark = hpva_benchmark( $vendor_id, $from, $to );

		if ( $benchmark ) {
			$out .= '<h3 class="hpva-h">' . esc_html__( 'Category benchmark', 'hivepress-vendor-analytics' ) . '</h3>';
			$out .= '<div class="hpva-cards">';
			$out .= hpva_card( __( 'Your avg daily views / listing', 'hivepress-vendor-analytics' ), number_format_i18n( $benchmark['vendor_avg'], 2 ) );
			$out .= hpva_card(
				// translators: %s: category name.
				sprintf( __( '%s average', 'hivepress-vendor-analytics' ), $benchmark['category_name'] ),
				number_format_i18n( $benchmark['category_avg'], 2 )
			);
			$out .= '</div>';
		}
	}

	// Top search terms.
	$terms = hpva_section_on( 'terms' ) ? hpva_top_terms( $vendor_id, $from, $to ) : [];

	if ( $terms ) {
		$out .= '<h3 class="hpva-h">' . esc_html__( 'Search terms that surfaced your listings', 'hivepress-vendor-analytics' ) . '</h3>';
		$out .= '<div class="hpva-table-wrap"><table class="hpva-table"><thead><tr><th>' . esc_html__( 'Term', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Impressions', 'hivepress-vendor-analytics' ) . '</th></tr></thead><tbody>';

		foreach ( $terms as $row ) {
			$out .= '<tr><td>' . esc_html( $row->term ) . '</td><td>' . esc_html( number_format_i18n( (int) $row->impressions ) ) . '</td></tr>';
		}

		$out .= '</tbody></table></div>';
	}

	// Per-listing breakdown.
	$breakdown = hpva_section_on( 'breakdown' ) ? hpva_listing_breakdown( $vendor_id, $from, $to ) : [];

	if ( $breakdown ) {
		$out .= '<h3 class="hpva-h">' . esc_html__( 'Per-listing breakdown', 'hivepress-vendor-analytics' ) . '</h3>';
		$out .= '<div class="hpva-table-wrap"><table class="hpva-table hpva-table--wide"><thead><tr><th>' . esc_html__( 'Listing', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Views', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Messages', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Bookings', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Favourites', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Phone', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Email', 'hivepress-vendor-analytics' ) . '</th></tr></thead><tbody>';

		foreach ( $breakdown as $listing_id => $metrics ) {
			$title = get_the_title( $listing_id );
			$link  = hivepress()->router->get_url( 'listing_analytics_page', [ 'listing_id' => $listing_id ] );
			$cell  = $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );

			$out .= '<tr><td>' . $cell . '</td>';

			foreach ( [ 'view', 'message', 'booking_confirmed', 'favorite', 'phone_click', 'email_click' ] as $metric ) {
				$out .= '<td>' . esc_html( number_format_i18n( isset( $metrics[ $metric ] ) ? $metrics[ $metric ] : 0 ) ) . '</td>';
			}

			$out .= '</tr>';
		}

		$out .= '</tbody></table></div>';
	}

	if ( ! $views && ! $messages && ! $b_new ) {
		$out .= '<p class="hpva-empty">' . esc_html__( 'No data recorded for this period yet.', 'hivepress-vendor-analytics' ) . '</p>';
	}

	return $out . hpva_admin_diagnostics() . '</div>';
}

/**
 * CSV export buttons (account pages are uncached, so the REST nonce is fresh).
 *
 * @param int $period Period in days.
 * @param int $listing_id Optional listing scope.
 * @return string
 */
function hpva_export_buttons( $period, $listing_id = 0 ) {
	if ( ! hpva_section_on( 'export' ) ) {
		return '';
	}

	$base = [
		'period'   => (int) $period,
		'listing'  => (int) $listing_id,
		'_wpnonce' => wp_create_nonce( 'wp_rest' ),
	];

	$report_url  = add_query_arg( array_merge( $base, [ 'type' => 'report' ] ), rest_url( 'hpva/v1/export' ) );
	$metrics_url = add_query_arg( array_merge( $base, [ 'type' => 'metrics' ] ), rest_url( 'hpva/v1/export' ) );

	return '<p class="hpva-export">'
		. '<a class="hpva-export__btn hpva-export__btn--primary" href="' . esc_url( $report_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Download report', 'hivepress-vendor-analytics' ) . '</a>'
		. '<a class="hpva-export__btn" href="' . esc_url( $metrics_url ) . '">' . esc_html__( 'Export CSV', 'hivepress-vendor-analytics' ) . '</a>'
		. '</p>';
}

/**
 * Guards CSV cells against spreadsheet formula injection.
 *
 * @param mixed $value Raw cell value.
 * @return string
 */
function hpva_csv_field( $value ) {
	$value = (string) $value;

	if ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
		$value = "'" . $value;
	}

	return $value;
}

/**
 * Handles authenticated CSV export requests.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function hpva_rest_export( $request ) {
	$user_id   = get_current_user_id();
	$vendor_id = hpva_vendor_id_from_user( $user_id );

	if ( ! $vendor_id ) {
		return new WP_REST_Response( [ 'error' => 'no_vendor' ], 403 );
	}

	$allowed = [ 7, 30, 90, 365, 0 ];
	$period  = (int) $request->get_param( 'period' );
	$period  = in_array( $period, $allowed, true ) ? $period : 30;
	$type    = in_array( $request->get_param( 'type' ), [ 'terms', 'report' ], true ) ? $request->get_param( 'type' ) : 'metrics';

	$listing_id = (int) $request->get_param( 'listing' );

	// A listing scope must belong to the requesting vendor (verified relation:
	// listing post_parent = vendor).
	if ( $listing_id && hpva_vendor_id_from_listing( $listing_id ) !== $vendor_id ) {
		return new WP_REST_Response( [ 'error' => 'not_owner' ], 403 );
	}

	list( $from, $to ) = hpva_range( $period );

	nocache_headers();

	// The HTML report opens inline so it can be read on any device and
	// printed or saved as a PDF from the browser.
	if ( 'report' === $type ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="analytics-report-' . $from . '-to-' . $to . '.html"' );

		echo hpva_report_html( $vendor_id, $period, $listing_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- complete standalone document, escaped at build time.
		exit;
	}

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="analytics-' . $type . '-' . $from . '-to-' . $to . '.csv"' );

	$output = fopen( 'php://output', 'w' );

	// UTF-8 BOM for Excel.
	fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming CSV to php://output.

	if ( 'terms' === $type ) {
		fputcsv( $output, [ 'term', 'impressions' ] );

		foreach ( (array) hpva_top_terms( $vendor_id, $from, $to, 1000, $listing_id ? $listing_id : null ) as $row ) {
			fputcsv( $output, [ hpva_csv_field( $row->term ), (int) $row->impressions ] );
		}
	} else {
		foreach ( hpva_report_csv_rows( $vendor_id, $period, $listing_id ) as $csv_row ) {
			fputcsv( $output, $csv_row );
		}
	}

	fclose( $output ); // phpcs:ignore
	exit;
}

/**
 * Admin-only diagnostics as an HTML comment (invisible to vendors), so data
 * collection can be verified without database access.
 *
 * @return string
 */
function hpva_admin_diagnostics() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return '';
	}

	global $wpdb;

	$daily = hpva_table();
	$terms = hpva_terms_table();
	$lines = [];

	$daily_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $daily ) ) === $daily ); // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
	$terms_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $terms ) ) === $terms ); // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.

	$lines[] = 'db_version option: ' . get_option( 'hpva_db_version', '(missing)' );

	// Section resolution: exactly what the renderer sees, so "missing section"
	// reports can be diagnosed from a phone.
	$sections_option = get_option( 'hp_vendor_analytics_sections', '(missing - defaults apply)' );
	$lines[]         = 'sections option: ' . ( is_array( $sections_option ) ? implode( ',', $sections_option ) : (string) $sections_option );

	foreach ( [ 'summary', 'funnel', 'trend', 'response', 'earnings', 'benchmark', 'terms', 'breakdown', 'export' ] as $hpva_section ) {
		$lines[] = 'section ' . $hpva_section . ': ' . ( hpva_section_on( $hpva_section ) ? 'enabled' : 'DISABLED in settings' );
	}

	$lines[] = 'data-conditional sections also require data in the selected period: response (needs replies), earnings (needs completed orders), benchmark (needs published listings + category), terms/breakdown (need rows), offers cards (need Requests extension + events).';
	$lines[] = 'daily table: ' . ( $daily_exists ? 'exists, ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$daily}" ) . ' rows' : 'MISSING - deactivate and reactivate the plugin' ); // phpcs:ignore
	$lines[] = 'terms table: ' . ( $terms_exists ? 'exists, ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$terms}" ) . ' rows' : 'MISSING - deactivate and reactivate the plugin' ); // phpcs:ignore

	if ( $daily_exists ) {
		$recent = $wpdb->get_results( "SELECT stat_date, metric, listing_id, vendor_id, value FROM {$daily} ORDER BY id DESC LIMIT 5" ); // phpcs:ignore

		foreach ( (array) $recent as $row ) {
			$lines[] = sprintf( 'recent: %s %s listing:%d vendor:%d value:%d', $row->stat_date, $row->metric, $row->listing_id, $row->vendor_id, $row->value );
		}
	}

	return "\n<!-- Vendor Analytics diagnostics (admins only):\n- " . implode( "\n- ", array_map( 'esc_html', $lines ) ) . "\n-->\n";
}

/**
 * Full analytics dashboard for a single listing (the listing's Analytics tab).
 *
 * @param object $listing Listing model object.
 * @return string
 */
function hpva_render_listing_dashboard( $listing ) {
	$listing_id = (int) $listing->get_id();
	$vendor_id  = hpva_vendor_id_from_listing( $listing_id );

	$period            = hpva_current_period();
	list( $from, $to ) = hpva_range( $period );

	$chart_from = $from;

	if ( 0 === $period ) {
		$year_ago   = gmdate( 'Y-m-d', strtotime( $to . ' UTC' ) - 364 * DAY_IN_SECONDS );
		$chart_from = max( $from, $year_ago );
	}

	$cur  = hpva_totals_map( $vendor_id, $from, $to, $listing_id );
	$prev = [];

	if ( 0 !== $period ) {
		list( $p_from, $p_to ) = hpva_prev_range( $from, $to );
		$prev                  = hpva_totals_map( $vendor_id, $p_from, $p_to, $listing_id );
	}

	/**
	 * @param string $key Metric key.
	 * @return int
	 */
	$m = function ( $key ) use ( $cur ) {
		return isset( $cur[ $key ] ) ? $cur[ $key ] : 0;
	};

	/**
	 * @param string $key Metric key.
	 * @return array{dir: string, text: string}|null
	 */
	$d = function ( $key ) use ( $cur, $prev, $period ) {
		if ( 0 === $period ) {
			return null;
		}

		return hpva_delta(
			isset( $cur[ $key ] ) ? $cur[ $key ] : 0,
			isset( $prev[ $key ] ) ? $prev[ $key ] : 0
		);
	};

	$views    = $m( 'view' );
	$messages = $m( 'message' );
	$b_conf   = $m( 'booking_confirmed' );
	$phone    = $m( 'phone_click' );
	$email    = $m( 'email_click' );

	$out  = hpva_css();
	$out .= '<div class="hpva">';

	// Cache-safe self-view exclusion, same as the account dashboard.
	if ( $vendor_id ) {
		$out .= '<script>try{localStorage.setItem("hpvaOwner","' . (int) $vendor_id . '");}catch(e){}</script>';
	}

	$out .= '<p class="hpva-sub">' . sprintf(
		// translators: %s: listing title.
		esc_html__( 'Figures for "%s" only. See Account > Analytics for all listings combined.', 'hivepress-vendor-analytics' ),
		esc_html( get_the_title( $listing_id ) )
	) . '</p>';
	$out .= hpva_period_switcher( $period );

	if ( 0 !== $period ) {
		$out .= '<p class="hpva-sub hpva-sub--delta">' . sprintf(
			// translators: %s: number of days.
			esc_html__( 'Changes compare against the previous %s days.', 'hivepress-vendor-analytics' ),
			number_format_i18n( $period )
		) . '</p>';
	}

	$out .= hpva_export_buttons( $period, $listing_id );

	if ( hpva_section_on( 'summary' ) ) {
		$out .= '<div class="hpva-cards">';
		$out .= hpva_card( __( 'Views', 'hivepress-vendor-analytics' ), number_format_i18n( $views ), '', $d( 'view' ) );
		$out .= hpva_card( __( 'Messages', 'hivepress-vendor-analytics' ), number_format_i18n( $messages ), '', $d( 'message' ) );

		if ( class_exists( '\HivePress\Models\Booking' ) ) {
			$out .= hpva_card( __( 'Bookings confirmed', 'hivepress-vendor-analytics' ), number_format_i18n( $b_conf ), '', $d( 'booking_confirmed' ) );
		}

		if ( class_exists( '\HivePress\Models\Favorite' ) ) {
			$out .= hpva_card( __( 'Favourites gained', 'hivepress-vendor-analytics' ), number_format_i18n( $m( 'favorite' ) ), '', $d( 'favorite' ) );
		}

		$out .= hpva_card( __( 'Phone clicks', 'hivepress-vendor-analytics' ), number_format_i18n( $phone ), '', $d( 'phone_click' ) );
		$out .= hpva_card( __( 'Email clicks', 'hivepress-vendor-analytics' ), number_format_i18n( $email ), '', $d( 'email_click' ) );
		$out .= '</div>';
	}

	if ( hpva_section_on( 'funnel' ) ) {
		$funnel_steps = [
			[
				'label' => __( 'Views', 'hivepress-vendor-analytics' ),
				'value' => $views,
			],
			[
				'label' => __( 'Messages', 'hivepress-vendor-analytics' ),
				'value' => $messages,
			],
		];

		if ( class_exists( '\HivePress\Models\Booking' ) ) {
			$funnel_steps[] = [
				'label' => __( 'Bookings', 'hivepress-vendor-analytics' ),
				'value' => $b_conf,
			];
		}

		$out .= '<h3 class="hpva-h">' . esc_html__( 'Conversion funnel', 'hivepress-vendor-analytics' ) . '</h3>';
		$out .= hpva_render_funnel( $funnel_steps );
	}

	if ( hpva_section_on( 'trend' ) ) {
		$out .= '<h3 class="hpva-h">' . esc_html__( 'Views & messages', 'hivepress-vendor-analytics' ) . '</h3>';
		$out .= hpva_svg_line(
			[
				[
					'label'  => __( 'Views', 'hivepress-vendor-analytics' ),
					'color'  => '#6b7cf6',
					'series' => hpva_series( $vendor_id, 'view', $chart_from, $to, $listing_id ),
				],
				[
					'label'  => __( 'Messages', 'hivepress-vendor-analytics' ),
					'color'  => '#f6a56b',
					'series' => hpva_series( $vendor_id, 'message', $chart_from, $to, $listing_id ),
				],
			]
		);
	}

	if ( hpva_section_on( 'terms' ) ) {
		$terms = hpva_top_terms( $vendor_id, $from, $to, 10, $listing_id );

		if ( $terms ) {
			$out .= '<h3 class="hpva-h">' . esc_html__( 'Search terms that surfaced this listing', 'hivepress-vendor-analytics' ) . '</h3>';
			$out .= '<div class="hpva-table-wrap"><table class="hpva-table"><thead><tr><th>' . esc_html__( 'Term', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Impressions', 'hivepress-vendor-analytics' ) . '</th></tr></thead><tbody>';

			foreach ( $terms as $row ) {
				$out .= '<tr><td>' . esc_html( $row->term ) . '</td><td>' . esc_html( number_format_i18n( (int) $row->impressions ) ) . '</td></tr>';
			}

			$out .= '</tbody></table></div>';
		}
	}

	if ( ! $views && ! $messages ) {
		$out .= '<p class="hpva-empty">' . esc_html__( 'No data recorded for this period yet.', 'hivepress-vendor-analytics' ) . '</p>';
	}

	return $out . hpva_admin_diagnostics() . '</div>';
}

/**
 * Compact first-party summary for a single listing, injected above the
 * official Statistics extension's Google Analytics chart.
 *
 * @param object $listing Listing model object.
 * @return string
 */
function hpva_render_listing_summary( $listing ) {
	$listing_id = (int) $listing->get_id();
	$vendor_id  = hpva_vendor_id_from_listing( $listing_id );

	list( $from, $to ) = hpva_range( 90 );

	$out  = hpva_css();
	$out .= '<div class="hpva hpva--listing">';
	$out .= '<h3 class="hpva-h">' . esc_html__( 'Last 90 days', 'hivepress-vendor-analytics' ) . '</h3>';
	$out .= '<div class="hpva-cards">';
	$out .= hpva_card( __( 'Views', 'hivepress-vendor-analytics' ), number_format_i18n( hpva_total( $vendor_id, 'view', $from, $to, $listing_id ) ) );
	$out .= hpva_card( __( 'Messages', 'hivepress-vendor-analytics' ), number_format_i18n( hpva_total( $vendor_id, 'message', $from, $to, $listing_id ) ) );

	if ( class_exists( '\HivePress\Models\Booking' ) ) {
		$out .= hpva_card( __( 'Bookings confirmed', 'hivepress-vendor-analytics' ), number_format_i18n( hpva_total( $vendor_id, 'booking_confirmed', $from, $to, $listing_id ) ) );
	}

	$out .= hpva_card( __( 'Phone clicks', 'hivepress-vendor-analytics' ), number_format_i18n( hpva_total( $vendor_id, 'phone_click', $from, $to, $listing_id ) ) );
	$out .= hpva_card( __( 'Email clicks', 'hivepress-vendor-analytics' ), number_format_i18n( hpva_total( $vendor_id, 'email_click', $from, $to, $listing_id ) ) );
	$out .= '</div>';

	$out .= hpva_svg_line(
		[
			[
				'label'  => __( 'Views', 'hivepress-vendor-analytics' ),
				'color'  => '#6b7cf6',
				'series' => hpva_series( $vendor_id, 'view', $from, $to, $listing_id ),
			],
		],
		160
	);

	$terms = hpva_top_terms( $vendor_id, $from, $to, 5, $listing_id );

	if ( $terms ) {
		$out .= '<h3 class="hpva-h">' . esc_html__( 'Top search terms', 'hivepress-vendor-analytics' ) . '</h3><ul class="hpva-terms">';

		foreach ( $terms as $row ) {
			$out .= '<li>' . esc_html( $row->term ) . ' <small>(' . esc_html( number_format_i18n( (int) $row->impressions ) ) . ')</small></li>';
		}

		$out .= '</ul>';
	}

	return $out . '</div>';
}

/**
 * Gets the inline CSS, output once per page.
 *
 * @return string
 */
function hpva_css() {
	static $done = false;

	if ( $done ) {
		return '';
	}

	$done = true;

	return '<style id="hpva-css">'
		. '.hpva-periods{display:flex;flex-wrap:wrap;gap:.4rem;margin:0 0 1.25rem}'
		. '.hpva-periods__item{padding:.35em .9em;border-radius:999px;background:#eaecf0;color:#4a5568;font-size:.85em;text-decoration:none}'
		. '.hpva-periods__item--active{background:#4a5568;color:#fff}'
		. '.hpva-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.75rem;margin:0 0 1.5rem}'
		. '.hpva-card{padding:1rem;border:1px solid rgba(7,36,86,.075);border-radius:3px;box-shadow:0 2px 4px 0 rgba(7,36,86,.075);background:#fff;display:flex;flex-direction:column;gap:.15rem}'
		. '.hpva-card__value{font-size:1.35em;font-weight:700}'
		. '.hpva-card__label{font-size:.8em;opacity:.7}'
		. '.hpva-card__note{font-size:.72em;opacity:.55}'
		. '.hpva-h{margin:1.75rem 0 .75rem}'
		. '.hpva-chart{width:100%;height:auto;display:block;border:1px solid rgba(7,36,86,.075);border-radius:3px;background:#fff}'
		. '.hpva-legend{display:flex;gap:1rem;margin:.5rem 0 0;font-size:.8em;opacity:.8}'
		. '.hpva-legend__dot{display:inline-block;width:.7em;height:.7em;border-radius:50%;margin-right:.35em}'
		. '.hpva-funnel__row{display:flex;align-items:center;gap:.75rem;margin:0 0 .5rem}'
		. '.hpva-funnel__label{flex:0 0 90px;font-size:.85em;opacity:.75}'
		. '.hpva-funnel__track{flex:1;background:#eaecf0;border-radius:999px;height:1.1em;overflow:hidden}'
		. '.hpva-funnel__bar{display:block;height:100%;background:#6b7cf6;border-radius:999px}'
		. '.hpva-funnel__value{flex:0 0 110px;font-size:.85em;font-weight:600;text-align:right}'
		. '.hpva-table{width:100%;border-collapse:collapse;margin:0 0 1rem;font-size:.9em}'
		. '.hpva-table th,.hpva-table td{padding:.5rem .6rem;border-bottom:1px solid rgba(0,0,0,.06);text-align:left}'
		. '.hpva-table th{font-size:.8em;text-transform:uppercase;letter-spacing:.03em;opacity:.6}'
		. '.hpva-terms{list-style:none;margin:0;padding:0}'
		. '.hpva-terms li{padding:.3rem 0;border-bottom:1px solid rgba(0,0,0,.05)}'
		. '.hpva-empty{opacity:.7}'
		. '.hpva-sub{margin:0 0 1rem;font-size:.9em;opacity:.7}'
		. '.hpva-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 0 1rem}'
		. '.hpva-table-wrap .hpva-table{margin:0}'
		. '.hpva-table--wide{min-width:560px}'
		. '.hpva-delta{font-size:.55em;font-weight:600;vertical-align:middle;padding:.15em .5em;border-radius:999px;white-space:nowrap}'
		. '.hpva-delta--good{background:rgba(79,178,134,.15);color:#2f7d5c}'
		. '.hpva-delta--bad{background:rgba(214,88,88,.12);color:#b04a4a}'
		. '.hpva-delta--flat{background:#eaecf0;color:#4a5568}'
		. '.hpva-export{margin:0 0 1.25rem;display:flex;gap:.5rem;flex-wrap:wrap}'
		. '.hpva-export__btn{padding:.4em 1em;border:1px solid rgba(7,36,86,.15);border-radius:3px;font-size:.85em;text-decoration:none;color:#4a5568;background:#fff}'
		. '.hpva-export__btn--primary{background:#4a5568;color:#fff;border-color:#4a5568}'
		. '.hpva--listing{margin:0 0 2rem}'
		. '</style>';
}

/*
--------------------------------------------------------------------------
Downloadable reports (HTML + sectioned CSV).
--------------------------------------------------------------------------
*/

/**
 * Gets a human-readable period label with its date range.
 *
 * @param int    $period Period in days (0 for all time).
 * @param string $from   Start date (Y-m-d).
 * @param string $to     End date (Y-m-d).
 * @return string
 */
function hpva_period_label( $period, $from, $to ) {
	$range = date_i18n( 'j M Y', strtotime( $from . ' UTC' ) ) . ' - ' . date_i18n( 'j M Y', strtotime( $to . ' UTC' ) );

	if ( 0 === $period ) {
		/* translators: %s: date range. */
		return sprintf( __( 'All time (%s)', 'hivepress-vendor-analytics' ), $range );
	}

	/* translators: 1: number of days, 2: date range. */
	return sprintf( __( 'Last %1$s days (%2$s)', 'hivepress-vendor-analytics' ), number_format_i18n( $period ), $range );
}

/**
 * Gets the standalone stylesheet for the HTML report (screen, mobile, print).
 *
 * @return string
 */
function hpva_report_css() {
	return '<style>'
		. 'body.hpva-report{margin:0;padding:2rem 1rem;background:#f6f7f9;color:#222;font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}'
		. '.hpva-report__sheet{max-width:840px;margin:0 auto;background:#fff;border:1px solid rgba(7,36,86,.075);border-radius:4px;box-shadow:0 2px 4px 0 rgba(7,36,86,.075);padding:2rem}'
		. '.hpva-report__head{border-bottom:2px solid #4a5568;padding:0 0 1rem;margin:0 0 1.5rem}'
		. '.hpva-report__site{font-size:.85em;text-transform:uppercase;letter-spacing:.06em;opacity:.6;margin:0}'
		. '.hpva-report__title{margin:.2rem 0 .4rem;font-size:1.6em}'
		. '.hpva-report__meta{margin:0;font-size:.9em;opacity:.75}'
		. '.hpva-h2{margin:2rem 0 .75rem;font-size:1.15em;border-bottom:1px solid rgba(0,0,0,.08);padding:0 0 .35rem}'
		. '.hpva-sub{margin:0 0 1rem;font-size:.9em;opacity:.7}'
		. '.hpva-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.75rem;margin:0 0 .5rem}'
		. '.hpva-card{padding:1rem;border:1px solid rgba(7,36,86,.075);border-radius:3px;background:#fff;display:flex;flex-direction:column;gap:.15rem}'
		. '.hpva-card__value{font-size:1.35em;font-weight:700}'
		. '.hpva-card__label{font-size:.8em;opacity:.7}'
		. '.hpva-delta{font-size:.55em;font-weight:600;vertical-align:middle;padding:.15em .5em;border-radius:999px;white-space:nowrap}'
		. '.hpva-delta--good{background:rgba(79,178,134,.15);color:#2f7d5c}'
		. '.hpva-delta--bad{background:rgba(214,88,88,.12);color:#b04a4a}'
		. '.hpva-delta--flat{background:#eaecf0;color:#4a5568}'
		. '.hpva-chart{width:100%;height:auto;display:block;border:1px solid rgba(7,36,86,.075);border-radius:3px;background:#fff}'
		. '.hpva-legend{display:flex;gap:1rem;margin:.5rem 0 0;font-size:.8em;opacity:.8}'
		. '.hpva-legend__dot{display:inline-block;width:.7em;height:.7em;border-radius:50%;margin-right:.35em}'
		. '.hpva-funnel__row{display:flex;align-items:center;gap:.75rem;margin:0 0 .5rem}'
		. '.hpva-funnel__label{flex:0 0 90px;font-size:.85em;opacity:.75}'
		. '.hpva-funnel__track{flex:1;background:#eaecf0;border-radius:999px;height:1.1em;overflow:hidden}'
		. '.hpva-funnel__bar{display:block;height:100%;background:#6b7cf6;border-radius:999px}'
		. '.hpva-funnel__value{flex:0 0 110px;font-size:.85em;font-weight:600;text-align:right}'
		. '.hpva-table{width:100%;border-collapse:collapse;margin:0 0 1rem;font-size:.9em}'
		. '.hpva-table th,.hpva-table td{padding:.5rem .6rem;border-bottom:1px solid rgba(0,0,0,.06);text-align:left}'
		. '.hpva-table th{font-size:.8em;text-transform:uppercase;letter-spacing:.03em;opacity:.6}'
		. '.hpva-report__foot{margin:2.5rem 0 0;padding:1rem 0 0;border-top:1px solid rgba(0,0,0,.08);font-size:.8em;opacity:.6}'
		. '.hpva-no-print{max-width:840px;margin:0 auto 1rem;display:flex;gap:.75rem;align-items:center;font-size:.9em}'
		. '.hpva-no-print button{padding:.5em 1.2em;border:0;border-radius:3px;background:#4a5568;color:#fff;font-size:1em;cursor:pointer}'
		. '.hpva-empty{opacity:.7}'
		. '@media print{body.hpva-report{background:#fff;padding:0}.hpva-report__sheet{border:0;box-shadow:none;max-width:none;padding:0}.hpva-no-print{display:none}.hpva-h2{page-break-after:avoid}.hpva-cards,.hpva-table,.hpva-chart,.hpva-funnel__row{page-break-inside:avoid}}'
		. '</style>';
}

/**
 * Builds the standalone HTML analytics report for a vendor or single listing,
 * honouring the admin's enabled sections and the same data conditions as the
 * dashboards. Opens in the browser; printing it produces a clean PDF.
 *
 * @param int $vendor_id  Vendor ID.
 * @param int $period     Period in days (0 for all time).
 * @param int $listing_id Optional listing scope.
 * @return string
 */
function hpva_report_html( $vendor_id, $period, $listing_id = 0 ) {
	list( $from, $to ) = hpva_range( $period );

	$chart_from = $from;

	if ( 0 === $period ) {
		$year_ago   = gmdate( 'Y-m-d', strtotime( $to . ' UTC' ) - 364 * DAY_IN_SECONDS );
		$chart_from = max( $from, $year_ago );
	}

	$scope = $listing_id ? $listing_id : null;
	$cur   = hpva_totals_map( $vendor_id, $from, $to, $scope );
	$prev  = [];

	if ( 0 !== $period ) {
		list( $p_from, $p_to ) = hpva_prev_range( $from, $to );
		$prev                  = hpva_totals_map( $vendor_id, $p_from, $p_to, $scope );
	}

	/**
	 * @param string $key Metric key.
	 * @return int
	 */
	$m = function ( $key ) use ( $cur ) {
		return isset( $cur[ $key ] ) ? $cur[ $key ] : 0;
	};

	/**
	 * @param string $key Metric key.
	 * @return array{dir: string, text: string}|null
	 */
	$d = function ( $key ) use ( $cur, $prev, $period ) {
		if ( 0 === $period ) {
			return null;
		}

		return hpva_delta(
			isset( $cur[ $key ] ) ? $cur[ $key ] : 0,
			isset( $prev[ $key ] ) ? $prev[ $key ] : 0
		);
	};

	$subject = $listing_id ? get_the_title( $listing_id ) : get_the_title( $vendor_id );

	$out  = '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">';
	$out .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
	$out .= '<title>' . esc_html( $subject ) . ' - ' . esc_html__( 'Analytics report', 'hivepress-vendor-analytics' ) . '</title>';
	$out .= hpva_report_css();
	$out .= '</head><body class="hpva-report">';

	$out .= '<div class="hpva-no-print"><button onclick="window.print()">' . esc_html__( 'Print or save as PDF', 'hivepress-vendor-analytics' ) . '</button>';
	$out .= '<span>' . esc_html__( 'This report is print-ready: your browser\'s print dialogue can save it as a PDF.', 'hivepress-vendor-analytics' ) . '</span></div>';

	$out .= '<div class="hpva-report__sheet">';
	$out .= '<header class="hpva-report__head">';
	$out .= '<p class="hpva-report__site">' . esc_html( get_bloginfo( 'name' ) ) . '</p>';
	$out .= '<h1 class="hpva-report__title">' . esc_html( $subject ) . ' - ' . esc_html__( 'Analytics report', 'hivepress-vendor-analytics' ) . '</h1>';
	$out .= '<p class="hpva-report__meta">' . esc_html( hpva_period_label( $period, $from, $to ) );

	if ( $listing_id ) {
		$out .= ' &middot; ' . esc_html__( 'This listing only', 'hivepress-vendor-analytics' );
	}

	/* translators: %s: date. */
	$out .= ' &middot; ' . esc_html( sprintf( __( 'Generated %s', 'hivepress-vendor-analytics' ), date_i18n( 'j M Y', strtotime( current_time( 'Y-m-d' ) . ' UTC' ) ) ) ) . '</p>';
	$out .= '</header>';

	$views    = $m( 'view' );
	$messages = $m( 'message' );
	$b_conf   = $m( 'booking_confirmed' );
	$resp_sum = $m( 'response_sum' );
	$resp_n   = $m( 'response_count' );
	$earnings = $m( 'earning_minor' );
	$orders   = $m( 'order' );

	if ( hpva_section_on( 'summary' ) ) {
		$out .= '<h2 class="hpva-h2">' . esc_html__( 'Summary', 'hivepress-vendor-analytics' ) . '</h2>';

		if ( 0 !== $period ) {
			$out .= '<p class="hpva-sub">' . sprintf(
				/* translators: %s: number of days. */
				esc_html__( 'Changes compare against the previous %s days.', 'hivepress-vendor-analytics' ),
				number_format_i18n( $period )
			) . '</p>';
		}

		$out .= '<div class="hpva-cards">';
		$out .= hpva_card( $listing_id ? __( 'Views', 'hivepress-vendor-analytics' ) : __( 'Listing views', 'hivepress-vendor-analytics' ), number_format_i18n( $views ), '', $d( 'view' ) );

		if ( ! $listing_id ) {
			$out .= hpva_card( __( 'Profile views', 'hivepress-vendor-analytics' ), number_format_i18n( $m( 'vendor_view' ) ), '', $d( 'vendor_view' ) );
		}

		$out .= hpva_card( __( 'Messages received', 'hivepress-vendor-analytics' ), number_format_i18n( $messages ), '', $d( 'message' ) );

		if ( class_exists( '\HivePress\Models\Booking' ) ) {
			$out .= hpva_card( __( 'Bookings confirmed', 'hivepress-vendor-analytics' ), number_format_i18n( $b_conf ), '', $d( 'booking_confirmed' ) );
		}

		if ( class_exists( '\HivePress\Models\Favorite' ) ) {
			$out .= hpva_card( __( 'Favourites gained', 'hivepress-vendor-analytics' ), number_format_i18n( $m( 'favorite' ) ), '', $d( 'favorite' ) );
		}

		$out .= hpva_card( __( 'Phone clicks', 'hivepress-vendor-analytics' ), number_format_i18n( $m( 'phone_click' ) ), '', $d( 'phone_click' ) );
		$out .= hpva_card( __( 'Email clicks', 'hivepress-vendor-analytics' ), number_format_i18n( $m( 'email_click' ) ), '', $d( 'email_click' ) );

		if ( ! $listing_id && class_exists( '\HivePress\Models\Offer' ) ) {
			$out .= hpva_card( __( 'Offers sent', 'hivepress-vendor-analytics' ), number_format_i18n( $m( 'offer_sent' ) ), '', $d( 'offer_sent' ) );

			if ( $m( 'offer_accepted' ) || ( isset( $prev['offer_accepted'] ) && $prev['offer_accepted'] ) ) {
				$out .= hpva_card( __( 'Offers accepted', 'hivepress-vendor-analytics' ), number_format_i18n( $m( 'offer_accepted' ) ), '', $d( 'offer_accepted' ) );
			}
		}

		if ( ! $listing_id && ( $orders || $earnings ) ) {
			$out .= hpva_card( __( 'Orders completed', 'hivepress-vendor-analytics' ), number_format_i18n( $orders ), '', $d( 'order' ) );
			$out .= hpva_card( __( 'Earnings', 'hivepress-vendor-analytics' ), hpva_money( $earnings ), '', $d( 'earning_minor' ) );
		}

		if ( ! $listing_id && $resp_n > 0 ) {
			$prev_avg  = ( isset( $prev['response_count'] ) && $prev['response_count'] > 0 ) ? $prev['response_sum'] / $prev['response_count'] : 0;
			$avg_delta = ( 0 !== $period ) ? hpva_delta( $resp_sum / $resp_n, $prev_avg ) : null;
			$out      .= hpva_card( __( 'Avg first response', 'hivepress-vendor-analytics' ), hpva_duration( $resp_sum / $resp_n ), '', $avg_delta, true );
		}

		$out .= '</div>';
	}

	if ( hpva_section_on( 'funnel' ) ) {
		$funnel_steps = [
			[
				'label' => __( 'Views', 'hivepress-vendor-analytics' ),
				'value' => $views,
			],
			[
				'label' => __( 'Messages', 'hivepress-vendor-analytics' ),
				'value' => $messages,
			],
		];

		if ( class_exists( '\HivePress\Models\Booking' ) ) {
			$funnel_steps[] = [
				'label' => __( 'Bookings', 'hivepress-vendor-analytics' ),
				'value' => $b_conf,
			];
		}

		$out .= '<h2 class="hpva-h2">' . esc_html__( 'Conversion funnel', 'hivepress-vendor-analytics' ) . '</h2>';
		$out .= hpva_render_funnel( $funnel_steps );
	}

	if ( hpva_section_on( 'trend' ) ) {
		$out .= '<h2 class="hpva-h2">' . esc_html__( 'Views & messages', 'hivepress-vendor-analytics' ) . '</h2>';
		$out .= hpva_svg_line(
			[
				[
					'label'  => __( 'Views', 'hivepress-vendor-analytics' ),
					'color'  => '#6b7cf6',
					'series' => hpva_series( $vendor_id, 'view', $chart_from, $to, $scope ),
				],
				[
					'label'  => __( 'Messages', 'hivepress-vendor-analytics' ),
					'color'  => '#f6a56b',
					'series' => hpva_series( $vendor_id, 'message', $chart_from, $to, $scope ),
				],
			]
		);
	}

	if ( ! $listing_id && hpva_section_on( 'response' ) && $resp_n > 0 ) {
		$sum_series   = hpva_series( $vendor_id, 'response_sum', $chart_from, $to );
		$count_series = hpva_series( $vendor_id, 'response_count', $chart_from, $to );
		$avg_series   = [];

		foreach ( $sum_series as $day => $sum ) {
			$n                  = isset( $count_series[ $day ] ) ? $count_series[ $day ] : 0;
			$avg_series[ $day ] = $n > 0 ? (int) round( $sum / $n / 60 ) : 0;
		}

		$out .= '<h2 class="hpva-h2">' . esc_html__( 'First response time (minutes, daily average)', 'hivepress-vendor-analytics' ) . '</h2>';
		$out .= hpva_svg_line(
			[
				[
					'label'  => __( 'Minutes', 'hivepress-vendor-analytics' ),
					'color'  => '#4fb286',
					'series' => $avg_series,
				],
			]
		);
	}

	if ( ! $listing_id && hpva_section_on( 'earnings' ) && $earnings > 0 ) {
		$e_series       = hpva_series( $vendor_id, 'earning_minor', $chart_from, $to );
		list( $factor ) = hpva_currency_scale();

		foreach ( $e_series as $day => $minor ) {
			$e_series[ $day ] = (int) round( $minor / $factor );
		}

		$out .= '<h2 class="hpva-h2">' . esc_html__( 'Earnings', 'hivepress-vendor-analytics' ) . '</h2>';
		$out .= hpva_svg_line(
			[
				[
					'label'  => __( 'Earnings', 'hivepress-vendor-analytics' ),
					'color'  => '#4fb286',
					'series' => $e_series,
				],
			]
		);
	}

	if ( ! $listing_id && hpva_section_on( 'benchmark' ) && hpva_get_option( 'vendor_analytics_benchmark', true ) ) {
		$benchmark = hpva_benchmark( $vendor_id, $from, $to );

		if ( $benchmark ) {
			$out .= '<h2 class="hpva-h2">' . esc_html__( 'Category benchmark', 'hivepress-vendor-analytics' ) . '</h2>';
			$out .= '<div class="hpva-cards">';
			$out .= hpva_card( __( 'Your avg daily views / listing', 'hivepress-vendor-analytics' ), number_format_i18n( $benchmark['vendor_avg'], 2 ) );
			$out .= hpva_card(
				/* translators: %s: category name. */
				sprintf( __( '%s average', 'hivepress-vendor-analytics' ), $benchmark['category_name'] ),
				number_format_i18n( $benchmark['category_avg'], 2 )
			);
			$out .= '</div>';
		}
	}

	if ( hpva_section_on( 'terms' ) ) {
		$terms = hpva_top_terms( $vendor_id, $from, $to, 15, $scope );

		if ( $terms ) {
			$out .= '<h2 class="hpva-h2">' . esc_html__( 'Search terms', 'hivepress-vendor-analytics' ) . '</h2>';
			$out .= '<table class="hpva-table"><thead><tr><th>' . esc_html__( 'Term', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Impressions', 'hivepress-vendor-analytics' ) . '</th></tr></thead><tbody>';

			foreach ( $terms as $row ) {
				$out .= '<tr><td>' . esc_html( $row->term ) . '</td><td>' . esc_html( number_format_i18n( (int) $row->impressions ) ) . '</td></tr>';
			}

			$out .= '</tbody></table>';
		}
	}

	if ( ! $listing_id && hpva_section_on( 'breakdown' ) ) {
		$breakdown = hpva_listing_breakdown( $vendor_id, $from, $to );

		if ( $breakdown ) {
			$out .= '<h2 class="hpva-h2">' . esc_html__( 'Per-listing breakdown', 'hivepress-vendor-analytics' ) . '</h2>';
			$out .= '<table class="hpva-table"><thead><tr><th>' . esc_html__( 'Listing', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Views', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Messages', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Bookings', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Favourites', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Phone', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Email', 'hivepress-vendor-analytics' ) . '</th></tr></thead><tbody>';

			foreach ( $breakdown as $breakdown_listing_id => $metrics ) {
				$out .= '<tr><td>' . esc_html( get_the_title( $breakdown_listing_id ) ) . '</td>';

				foreach ( [ 'view', 'message', 'booking_confirmed', 'favorite', 'phone_click', 'email_click' ] as $metric ) {
					$out .= '<td>' . esc_html( number_format_i18n( isset( $metrics[ $metric ] ) ? $metrics[ $metric ] : 0 ) ) . '</td>';
				}

				$out .= '</tr>';
			}

			$out .= '</tbody></table>';
		}
	}

	if ( ! $views && ! $messages ) {
		$out .= '<p class="hpva-empty">' . esc_html__( 'No data recorded for this period yet.', 'hivepress-vendor-analytics' ) . '</p>';
	}

	$out .= '<p class="hpva-report__foot">' . esc_html__( 'Generated by Vendor Analytics Pro for HivePress', 'hivepress-vendor-analytics' ) . ' &middot; ' . esc_html( get_bloginfo( 'name' ) ) . '</p>';

	return $out . '</div></body></html>';
}

/**
 * Builds the sectioned, human-readable CSV rows for the export, honouring the
 * enabled sections and the same data conditions as the dashboards.
 *
 * @param int $vendor_id  Vendor ID.
 * @param int $period     Period in days (0 for all time).
 * @param int $listing_id Optional listing scope.
 * @return array<int, array<int, string|int>>
 */
function hpva_report_csv_rows( $vendor_id, $period, $listing_id = 0 ) {
	list( $from, $to ) = hpva_range( $period );

	$scope = $listing_id ? $listing_id : null;
	$cur   = hpva_totals_map( $vendor_id, $from, $to, $scope );
	$prev  = [];

	if ( 0 !== $period ) {
		list( $p_from, $p_to ) = hpva_prev_range( $from, $to );
		$prev                  = hpva_totals_map( $vendor_id, $p_from, $p_to, $scope );
	}

	/**
	 * @param string $key Metric key.
	 * @return int
	 */
	$m = function ( $key ) use ( $cur ) {
		return isset( $cur[ $key ] ) ? $cur[ $key ] : 0;
	};

	/**
	 * @param string $key Metric key.
	 * @return int
	 */
	$p = function ( $key ) use ( $prev ) {
		return isset( $prev[ $key ] ) ? $prev[ $key ] : 0;
	};

	/**
	 * @param string $key Metric key.
	 * @return string
	 */
	$change = function ( $key ) use ( $m, $p, $period ) {
		if ( 0 === $period ) {
			return '';
		}

		$delta = hpva_delta( $m( $key ), $p( $key ) );

		return $delta ? $delta['text'] : '';
	};

	$rows   = [];
	$rows[] = [ hpva_csv_field( __( 'Vendor analytics report', 'hivepress-vendor-analytics' ) ) ];
	$rows[] = [ hpva_csv_field( __( 'Site', 'hivepress-vendor-analytics' ) ), hpva_csv_field( get_bloginfo( 'name' ) ) ];
	$rows[] = [ hpva_csv_field( __( 'Vendor', 'hivepress-vendor-analytics' ) ), hpva_csv_field( get_the_title( $vendor_id ) ) ];

	if ( $listing_id ) {
		$rows[] = [ hpva_csv_field( __( 'Listing', 'hivepress-vendor-analytics' ) ), hpva_csv_field( get_the_title( $listing_id ) ) ];
	}

	$rows[] = [ hpva_csv_field( __( 'Period', 'hivepress-vendor-analytics' ) ), hpva_csv_field( hpva_period_label( $period, $from, $to ) ) ];
	$rows[] = [ hpva_csv_field( __( 'Generated', 'hivepress-vendor-analytics' ) ), hpva_csv_field( date_i18n( 'j M Y', strtotime( current_time( 'Y-m-d' ) . ' UTC' ) ) ) ];

	if ( hpva_section_on( 'summary' ) ) {
		$rows[] = [];
		$rows[] = [ hpva_csv_field( __( 'SUMMARY', 'hivepress-vendor-analytics' ) ) ];
		$rows[] = [
			hpva_csv_field( __( 'Metric', 'hivepress-vendor-analytics' ) ),
			hpva_csv_field( __( 'This period', 'hivepress-vendor-analytics' ) ),
			hpva_csv_field( __( 'Previous period', 'hivepress-vendor-analytics' ) ),
			hpva_csv_field( __( 'Change', 'hivepress-vendor-analytics' ) ),
		];

		$metric_rows = [
			[ 'view', $listing_id ? __( 'Views', 'hivepress-vendor-analytics' ) : __( 'Listing views', 'hivepress-vendor-analytics' ) ],
		];

		if ( ! $listing_id ) {
			$metric_rows[] = [ 'vendor_view', __( 'Profile views', 'hivepress-vendor-analytics' ) ];
		}

		$metric_rows[] = [ 'message', __( 'Messages received', 'hivepress-vendor-analytics' ) ];

		if ( class_exists( '\HivePress\Models\Booking' ) ) {
			$metric_rows[] = [ 'booking_new', __( 'Bookings created', 'hivepress-vendor-analytics' ) ];
			$metric_rows[] = [ 'booking_confirmed', __( 'Bookings confirmed', 'hivepress-vendor-analytics' ) ];
		}

		if ( class_exists( '\HivePress\Models\Favorite' ) ) {
			$metric_rows[] = [ 'favorite', __( 'Favourites gained', 'hivepress-vendor-analytics' ) ];
			$metric_rows[] = [ 'favorite_removed', __( 'Favourites removed', 'hivepress-vendor-analytics' ) ];
		}

		$metric_rows[] = [ 'phone_click', __( 'Phone clicks', 'hivepress-vendor-analytics' ) ];
		$metric_rows[] = [ 'email_click', __( 'Email clicks', 'hivepress-vendor-analytics' ) ];

		if ( ! $listing_id && class_exists( '\HivePress\Models\Offer' ) ) {
			$metric_rows[] = [ 'offer_sent', __( 'Offers sent', 'hivepress-vendor-analytics' ) ];
			$metric_rows[] = [ 'offer_accepted', __( 'Offers accepted', 'hivepress-vendor-analytics' ) ];
		}

		foreach ( $metric_rows as $metric_row ) {
			$rows[] = [
				hpva_csv_field( $metric_row[1] ),
				$m( $metric_row[0] ),
				0 === $period ? '' : $p( $metric_row[0] ),
				hpva_csv_field( $change( $metric_row[0] ) ),
			];
		}

		if ( ! $listing_id && ( $m( 'order' ) || $m( 'earning_minor' ) || $p( 'order' ) ) ) {
			$rows[] = [ hpva_csv_field( __( 'Orders completed', 'hivepress-vendor-analytics' ) ), $m( 'order' ), 0 === $period ? '' : $p( 'order' ), hpva_csv_field( $change( 'order' ) ) ];
			$rows[] = [ hpva_csv_field( __( 'Earnings', 'hivepress-vendor-analytics' ) ), hpva_csv_field( hpva_money( $m( 'earning_minor' ) ) ), 0 === $period ? '' : hpva_csv_field( hpva_money( $p( 'earning_minor' ) ) ), hpva_csv_field( $change( 'earning_minor' ) ) ];
		}

		if ( ! $listing_id && $m( 'response_count' ) > 0 ) {
			$prev_avg = $p( 'response_count' ) > 0 ? hpva_duration( $p( 'response_sum' ) / $p( 'response_count' ) ) : '';
			$rows[]   = [
				hpva_csv_field( __( 'Avg first response', 'hivepress-vendor-analytics' ) ),
				hpva_csv_field( hpva_duration( $m( 'response_sum' ) / $m( 'response_count' ) ) ),
				0 === $period ? '' : hpva_csv_field( $prev_avg ),
				'',
			];
		}
	}

	if ( ! $listing_id && hpva_section_on( 'breakdown' ) ) {
		$breakdown = hpva_listing_breakdown( $vendor_id, $from, $to );

		if ( $breakdown ) {
			$rows[] = [];
			$rows[] = [ hpva_csv_field( __( 'PER-LISTING BREAKDOWN', 'hivepress-vendor-analytics' ) ) ];
			$rows[] = [
				hpva_csv_field( __( 'Listing', 'hivepress-vendor-analytics' ) ),
				hpva_csv_field( __( 'Views', 'hivepress-vendor-analytics' ) ),
				hpva_csv_field( __( 'Messages', 'hivepress-vendor-analytics' ) ),
				hpva_csv_field( __( 'Bookings', 'hivepress-vendor-analytics' ) ),
				hpva_csv_field( __( 'Favourites', 'hivepress-vendor-analytics' ) ),
				hpva_csv_field( __( 'Phone clicks', 'hivepress-vendor-analytics' ) ),
				hpva_csv_field( __( 'Email clicks', 'hivepress-vendor-analytics' ) ),
			];

			foreach ( $breakdown as $breakdown_listing_id => $metrics ) {
				$row = [ hpva_csv_field( get_the_title( $breakdown_listing_id ) ) ];

				foreach ( [ 'view', 'message', 'booking_confirmed', 'favorite', 'phone_click', 'email_click' ] as $metric ) {
					$row[] = isset( $metrics[ $metric ] ) ? $metrics[ $metric ] : 0;
				}

				$rows[] = $row;
			}
		}
	}

	if ( hpva_section_on( 'terms' ) ) {
		$terms = hpva_top_terms( $vendor_id, $from, $to, 1000, $scope );

		if ( $terms ) {
			$rows[] = [];
			$rows[] = [ hpva_csv_field( __( 'SEARCH TERMS', 'hivepress-vendor-analytics' ) ) ];
			$rows[] = [ hpva_csv_field( __( 'Term', 'hivepress-vendor-analytics' ) ), hpva_csv_field( __( 'Impressions', 'hivepress-vendor-analytics' ) ) ];

			foreach ( $terms as $row ) {
				$rows[] = [ hpva_csv_field( $row->term ), (int) $row->impressions ];
			}
		}
	}

	return $rows;
}
