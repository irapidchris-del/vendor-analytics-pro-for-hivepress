<?php
/**
 * Plugin Name: Vendor Analytics Pro for HivePress
 * Plugin URI: https://github.com/irapidchris-del/vendor-analytics-pro-for-hivepress
 * Description: A first-party analytics dashboard for HivePress vendors - views, phone/email click tracking, messages, bookings funnel, earnings, response-time trends, search terms and category benchmarks, stored as daily aggregates with no third-party services.
 * Version: 1.8.1
 * Author: ChrisB @ HivePress Community
 * Author URI: https://community.hivepress.io/u/chrisb/summary
 * Requires Plugins: hivepress
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: hivepress-vendor-analytics
 * Domain Path: /languages/
 * Update URI: https://github.com/irapidchris-del/vendor-analytics-pro-for-hivepress
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
 *   (wp_insert_comment, delete_comment, transition_post_status) rather than
 *   HivePress model hooks: verified in core Hook component, the model-specific
 *   create hooks fire only when the model registry resolves the type, so raw
 *   hooks with the verified storage schema (hp_message comments: sender =
 *   user_id, recipient = comment_karma, listing = comment_post_ID; hp_booking
 *   posts: listing = post_parent) are strictly more reliable. wp_insert_post
 *   is deliberately NOT used: bookings are born as auto-draft placeholders,
 *   so creation is only visible as a status transition. Marketplace orders
 *   are WooCommerce shop_order posts carrying the vendor ID in 'hp_vendor'
 *   meta.
 * - Daily aggregates live in two custom tables; retention is configurable.
 */

defined( 'ABSPATH' ) || exit;

define( 'HPVA_VERSION', '1.8.1' );
define( 'HPVA_DB_VERSION', '2' );
define( 'HPVA_FILE', __FILE__ );

// Auto-updater: the GitHub repo releases are read from, the plugin slug
// (which must equal the installed plugin folder name) and the release cache
// key. See the "Updates" section below.
define( 'HPVA_UPDATE_REPO', 'irapidchris-del/vendor-analytics-pro-for-hivepress' );
define( 'HPVA_UPDATE_SLUG', 'vendor-analytics-pro-for-hivepress' );
define( 'HPVA_UPDATE_CACHE_KEY', 'hpva_github_release' );

// Register this plugin with HivePress so core autoloads our controller,
// template and block classes. The explicit array form is required: with a
// bare directory, core expects the main file to be named after the plugin
// folder (basename( $dir ) . '.php'), and ours deliberately differs, so the
// string form would silently register nothing.
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

		// Core's premium-updater probe concatenates EVERY entry into
		// file_exists() (class-core.php:249), so an array entry raises
		// "PHP Warning: Array to string conversion" on every request unless
		// $extensions['updates'] is already set when the probe runs. This
		// filter therefore registers at priority 100 (string-form extensions
		// are in the array by then) and runs the same probe itself over the
		// string entries; when nothing bundles the package, a never-existing
		// string path stands in, which core's string branch silently skips
		// via its own file_exists() guard (class-core.php:277) - identical
		// outcome to the probe finding nothing, minus the warning.
		if ( ! isset( $extensions['updates'] ) ) {
			$updates = __DIR__ . '/updates-not-bundled';

			foreach ( $extensions as $extension_dir ) {
				if ( is_string( $extension_dir ) && file_exists( $extension_dir . '/vendor/hivepress/hivepress-updates/hivepress-updates.php' ) ) {
					$updates = $extension_dir . '/vendor/hivepress/hivepress-updates';

					break;
				}
			}

			$extensions['updates'] = $updates;
		}

		return $extensions;
	},
	100
);

/*
--------------------------------------------------------------------------
Updates.

Distributed via GitHub releases rather than wp.org, so update checks use the
native `update_plugins_{$hostname}` API introduced in WordPress 5.8, keyed off
the "Update URI" header above (host: github.com). The update package is the
release asset named `*.zip`, which contains a single
`vendor-analytics-pro-for-hivepress` directory. No third-party library.
--------------------------------------------------------------------------
*/

/**
 * Gets the latest GitHub release details, cached in a site transient.
 *
 * @param bool $force Bypass the cache.
 * @return array<string, string>|null
 */
function hpva_get_latest_release( $force = false ) {
	$release = $force ? false : get_site_transient( HPVA_UPDATE_CACHE_KEY );

	if ( ! is_array( $release ) ) {
		$release = hpva_fetch_latest_release();

		// Success is cached for 6 hours; failures briefly, so a flaky network
		// does not hammer the API on every admin page load.
		set_site_transient( HPVA_UPDATE_CACHE_KEY, $release, $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	}

	return $release ? $release : null;
}

/**
 * Fetches the latest release details from the GitHub API.
 *
 * The /releases/latest endpoint excludes drafts and pre-releases, so tagging a
 * pre-release never triggers an update notice.
 *
 * @return array<string, string>
 */
function hpva_fetch_latest_release() {
	$response = wp_remote_get(
		'https://api.github.com/repos/' . HPVA_UPDATE_REPO . '/releases/latest',
		[
			'timeout'    => 10,
			// Without an explicit user-agent WordPress sends
			// "WordPress/{version}; {site url}", leaking the site's address
			// and WordPress version to GitHub on every update check. GitHub
			// only requires that the header identifies something.
			'user-agent' => HPVA_UPDATE_SLUG . '/' . HPVA_VERSION,
			'headers'    => [ 'Accept' => 'application/vnd.github+json' ],
		]
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return [];
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $data ) ) {
		return [];
	}

	// Version is the release tag, with or without a leading "v".
	$version = ltrim( (string) ( isset( $data['tag_name'] ) ? $data['tag_name'] : '' ), 'vV' );

	if ( ! $version ) {
		return [];
	}

	// The update package is the first release asset named `*.zip`.
	$package = '';

	foreach ( (array) ( isset( $data['assets'] ) ? $data['assets'] : [] ) as $asset ) {
		$name = strtolower( (string) ( isset( $asset['name'] ) ? $asset['name'] : '' ) );

		if ( '.zip' === substr( $name, -4 ) && ! empty( $asset['browser_download_url'] ) ) {
			$package = (string) $asset['browser_download_url'];

			break;
		}
	}

	if ( ! $package ) {
		return [];
	}

	return [
		'version'   => $version,
		'package'   => $package,
		'url'       => (string) ( isset( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . HPVA_UPDATE_REPO ),
		'notes'     => (string) ( isset( $data['body'] ) ? $data['body'] : '' ),
		'published' => (string) ( isset( $data['published_at'] ) ? $data['published_at'] : '' ),
	];
}

/**
 * Feeds the update details to the WordPress update system. WordPress matches
 * this plugin to the filter via the Update URI header host and compares the
 * versions itself.
 *
 * @param array<string, mixed>|false $update Update data.
 * @param array<string, string>      $plugin_data Plugin headers.
 * @param string                     $plugin_file Plugin basename.
 * @return array<string, mixed>|false
 */
function hpva_check_for_update( $update, $plugin_data, $plugin_file ) {
	if ( plugin_basename( HPVA_FILE ) !== $plugin_file ) {
		return $update;
	}

	$release = hpva_get_latest_release();

	if ( ! $release ) {
		return $update;
	}

	return [
		'id'      => 'https://github.com/' . HPVA_UPDATE_REPO,
		'slug'    => HPVA_UPDATE_SLUG,
		'plugin'  => $plugin_file,
		'version' => $release['version'],
		'url'     => $release['url'],
		'package' => $release['package'],
	];
}

add_filter( 'update_plugins_github.com', 'hpva_check_for_update', 10, 3 );

/**
 * Provides the plugin details for the "View version details" popup, since the
 * plugin is not hosted on wp.org.
 *
 * @param object|array|false $result Result object.
 * @param string             $action API action.
 * @param object             $args API arguments.
 * @return object|array|false
 */
function hpva_update_plugin_info( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || ! is_object( $args ) || HPVA_UPDATE_SLUG !== ( isset( $args->slug ) ? $args->slug : '' ) ) {
		return $result;
	}

	$release = hpva_get_latest_release();

	if ( ! $release ) {
		return $result;
	}

	$plugin_data = get_file_data(
		HPVA_FILE,
		[
			'Name'        => 'Plugin Name',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'RequiresWP'  => 'Requires at least',
			'RequiresPHP' => 'Requires PHP',
		]
	);

	return (object) [
		'name'          => $plugin_data['Name'],
		'slug'          => HPVA_UPDATE_SLUG,
		'version'       => $release['version'],
		'author'        => '<a href="' . esc_url( $plugin_data['AuthorURI'] ) . '">' . esc_html( $plugin_data['Author'] ) . '</a>',
		'homepage'      => 'https://github.com/' . HPVA_UPDATE_REPO,
		'requires'      => $plugin_data['RequiresWP'],
		'requires_php'  => $plugin_data['RequiresPHP'],
		'last_updated'  => $release['published'],
		'download_link' => $release['package'],
		'sections'      => [
			'description' => wpautop( esc_html( $plugin_data['Description'] ) ),
			'changelog'   => $release['notes'] ? hpva_release_notes_html( $release['notes'] ) : '<p>' . esc_html__( 'See the GitHub releases page for the changelog.', 'hivepress-vendor-analytics' ) . '</p>',
		],
	];
}

add_filter( 'plugins_api', 'hpva_update_plugin_info', 10, 3 );

/**
 * Adds a "Check for updates" link to the plugin's row on the Plugins screen.
 *
 * @param array<string> $links Plugin action links.
 * @return array<string>
 */
function hpva_update_check_link( $links ) {
	if ( current_user_can( 'update_plugins' ) ) {
		$links[] = '<a href="' . esc_url( wp_nonce_url( self_admin_url( 'plugins.php?hpva_check_updates=1' ), 'hpva_check_updates' ) ) . '">' . esc_html__( 'Check for updates', 'hivepress-vendor-analytics' ) . '</a>';
	}

	return $links;
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'hpva_update_check_link' );
add_filter( 'network_admin_plugin_action_links_' . plugin_basename( __FILE__ ), 'hpva_update_check_link' );

/**
 * Handles the manual "Check for updates" click: refreshes the cached release,
 * re-runs the update check and redirects back with the result.
 *
 * @return void
 */
function hpva_handle_update_check() {
	if ( ! isset( $_GET['hpva_check_updates'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	check_admin_referer( 'hpva_check_updates' );

	$release = hpva_get_latest_release( true );

	wp_clean_plugins_cache();
	wp_update_plugins();

	$status = 'none';

	if ( ! $release ) {
		$status = 'error';
	} elseif ( version_compare( $release['version'], HPVA_VERSION, '>' ) ) {
		$status = 'available';
	}

	wp_safe_redirect( add_query_arg( 'hpva_checked', $status, self_admin_url( 'plugins.php' ) ) );

	exit;
}

add_action( 'admin_init', 'hpva_handle_update_check' );

/**
 * Shows the result of a manual update check.
 *
 * @return void
 */
function hpva_update_check_notice() {
	// Read-only: this displays the outcome of the redirect performed by
	// hpva_handle_update_check(), which is where the nonce is verified. The
	// value only selects one of three fixed messages and is capability-gated,
	// so there is nothing here for a forged request to accomplish.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['hpva_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$status = sanitize_key( wp_unslash( $_GET['hpva_checked'] ) );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( 'available' === $status ) {
		$release = hpva_get_latest_release();

		/* translators: %s: new version number. */
		$message = sprintf( __( 'A new version of Vendor Analytics Pro for HivePress (%s) is available.', 'hivepress-vendor-analytics' ), $release ? $release['version'] : '' );
		$class   = 'notice-success';
	} elseif ( 'none' === $status ) {
		$message = __( 'Vendor Analytics Pro for HivePress is up to date.', 'hivepress-vendor-analytics' );
		$class   = 'notice-success';
	} elseif ( 'error' === $status ) {
		$message = __( 'Could not reach GitHub to check for updates. Please try again later.', 'hivepress-vendor-analytics' );
		$class   = 'notice-error';
	} else {
		return;
	}

	echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}

add_action( 'admin_notices', 'hpva_update_check_notice' );
add_action( 'network_admin_notices', 'hpva_update_check_notice' );

/**
 * Renames the extracted update folder to the currently installed plugin
 * directory, so an update always installs back into the same folder even if
 * the release zip is ever packaged with a different top-level directory name.
 *
 * @param string               $source Extracted update source.
 * @param string               $remote_source Remote source directory.
 * @param object               $upgrader Upgrader instance.
 * @param array<string, mixed> $hook_extra Extra hook arguments.
 * @return string|\WP_Error
 */
function hpva_fix_update_directory( $source, $remote_source, $upgrader, $hook_extra = [] ) {
	global $wp_filesystem;

	if ( plugin_basename( HPVA_FILE ) !== ( isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '' ) || ! $wp_filesystem ) {
		return $source;
	}

	$directory = dirname( plugin_basename( HPVA_FILE ) );

	if ( '.' === $directory ) {
		return $source;
	}

	$target = trailingslashit( $remote_source ) . $directory . '/';

	if ( trailingslashit( $source ) === $target ) {
		return $source;
	}

	if ( ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ) ) ) {
		return new \WP_Error( 'hpva_rename_failed', __( 'Could not rename the update directory.', 'hivepress-vendor-analytics' ) );
	}

	return $target;
}

add_filter( 'upgrader_source_selection', 'hpva_fix_update_directory', 10, 4 );

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

	// This plugin adds routes, and their rewrite rules only exist after a
	// permalink flush. Core's own flush is exactly this delete; WordPress
	// rebuilds the rules lazily on the next request (class-router.php:503).
	delete_option( 'rewrite_rules' );
}

/**
 * Runs on plugin deactivation.
 *
 * @return void
 */
function hpva_deactivate() {
	wp_clear_scheduled_hook( 'hpva_daily_maintenance' );

	// Drop our routes' rewrite rules the same way they were added.
	delete_option( 'rewrite_rules' );
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
			clicks bigint(20) NOT NULL DEFAULT 0,
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

// Retention pruning must survive HivePress being deactivated (an update, a
// conflict investigation): the cron event keeps firing regardless, and
// hpva_prune() touches only our own tables and options, so its listener
// lives outside the function_exists( 'hivepress' ) gate in hpva_init().
add_action( 'hpva_daily_maintenance', 'hpva_prune' );

// Our folder name (vendor-analytics-pro-for-hivepress) deliberately differs
// from our text domain (hivepress-vendor-analytics), so core's automatic
// per-extension textdomain load - which derives the domain from the folder
// name - registers the wrong domain and our bundled languages/ folder would
// never load. Registering the custom path ourselves (on init, per the WP
// 6.7+ timing rule) makes a .mo shipped or Loco-saved in the plugin's own
// languages/ work; translations in wp-content/languages/plugins/ load
// just-in-time either way.
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'hivepress-vendor-analytics', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

/**
 * Bootstraps the plugin once all plugins are loaded.
 *
 * @return void
 */
function hpva_init() {
	if ( ! function_exists( 'hivepress' ) ) {
		add_action(
			'admin_notices',
			function () {
				// Dismissible but not persistent: it reappears while the
				// problem remains, and the admin can clear the screen.
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Vendor Analytics Pro requires the HivePress plugin to be active.', 'hivepress-vendor-analytics' ) . '</p></div>';
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

	// Integrations that depend on another extension's classes are wired on
	// 'init' - see hpva_register_optional_integrations() for why.
	add_action( 'init', 'hpva_register_optional_integrations' );

	// Statistics and Marketplace integrations are registered on 'init' too.

	// Tracking endpoint.
	add_action( 'rest_api_init', 'hpva_register_rest' );

	// Beacon script on listing/vendor pages.
	add_action( 'wp_enqueue_scripts', 'hpva_enqueue_tracker' );

	hpva_maybe_upgrade();

	// Event recorders via raw core hooks (see header notes for why).
	add_action( 'wp_insert_comment', 'hpva_on_comment_insert', 10, 2 );
	add_action( 'delete_comment', 'hpva_on_comment_delete', 10, 2 );
	add_action( 'before_delete_post', 'hpva_on_before_delete_post' );
	add_action( 'transition_post_status', 'hpva_on_post_transition', 10, 3 );
	// Marketplace settles orders on 'processing' (services/goods/offers) or
	// 'completed' (downloadable); the handler records each order only once.
	add_action( 'woocommerce_order_status_processing', 'hpva_on_order_paid', 10, 1 );
	add_action( 'woocommerce_order_status_completed', 'hpva_on_order_paid', 10, 1 );
	add_action( 'woocommerce_order_refunded', 'hpva_on_order_refunded', 10, 2 );

	// Monthly summary email: the vendor's own two controls on their account
	// settings form, and the send itself on HivePress's daily event.
	add_filter( 'hivepress/v1/forms/user_update', 'hpva_add_monthly_fields', 10, 2 );
	add_filter( 'hivepress/v1/forms/user_update/errors', 'hpva_capture_monthly_choice', 10, 2 );
	add_action( 'hivepress/v1/models/user/update', 'hpva_save_monthly_choice' );
	add_action( 'hivepress/v1/events/daily', 'hpva_send_monthly_summaries' );

	// Per-listing Analytics tab in the listing manage menu (mirrors the
	// official Statistics extension's verified pattern).
	add_filter( 'hivepress/v1/menus/listing_manage/items', 'hpva_listing_manage_menu_item', 100, 2 );

	// Search term impressions.
	add_action( 'wp', 'hpva_track_search_terms' );

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
			'tracking'  => [
				'title'       => __( 'Tracking', 'hivepress-vendor-analytics' ),
				'description' => __( 'Everything is measured on your own site with no third-party analytics services. Data collection starts when the plugin is activated.', 'hivepress-vendor-analytics' ),
				'_order'      => 10,

				'fields'      => [
					'vendor_analytics_views'    => [
						'label'   => __( 'Track page views', 'hivepress-vendor-analytics' ),
						'caption' => __( 'Count listing and vendor profile views (JavaScript beacon, cache-safe)', 'hivepress-vendor-analytics' ),
						'type'    => 'checkbox',
						'default' => true,
						'_order'  => 10,
					],

					'vendor_analytics_clicks'   => [
						'label'   => __( 'Track contact clicks', 'hivepress-vendor-analytics' ),
						'caption' => __( 'Count clicks on phone (tel:) and email (mailto:) links on listing and vendor pages', 'hivepress-vendor-analytics' ),
						'type'    => 'checkbox',
						'default' => true,
						'_order'  => 20,
					],

					'vendor_analytics_search'   => [
						'label'   => __( 'Track search terms', 'hivepress-vendor-analytics' ),
						'caption' => __( 'Record which keyword searches surfaced each listing in results', 'hivepress-vendor-analytics' ),
						'type'    => 'checkbox',
						'default' => true,
						'_order'  => 30,
					],

					'vendor_analytics_earnings' => [
						'label'   => __( 'Track earnings', 'hivepress-vendor-analytics' ),
						'caption' => __( 'Record each vendor\'s payout when a Marketplace order is paid, after commission (requires Marketplace)', 'hivepress-vendor-analytics' ),
						'type'    => 'checkbox',
						'default' => true,
						'_order'  => 40,
					],

					'vendor_analytics_sections' => [
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

				],
			],

			'monthly'   => [
				'title'       => __( 'Monthly report email', 'hivepress-vendor-analytics' ),
				'description' => __( 'On the first of each month, vendors can be sent a summary of the month just gone, with a button that opens their full report. Switching it on sends to every vendor; the second setting hands each vendor the choice for themselves. Edit the wording under HivePress > Emails.', 'hivepress-vendor-analytics' ),
				'_order'      => 15,

				'fields'      => [
					'vendor_analytics_monthly'         => [
						'label'       => __( 'Monthly reports', 'hivepress-vendor-analytics' ),
						'caption'     => __( 'Email vendors a monthly summary of their analytics', 'hivepress-vendor-analytics' ),
						'description' => __( 'While this is off nothing is sent and nothing is shown to vendors. Switching it on means every vendor is emailed on the first of the month, unless they have turned it off for themselves.', 'hivepress-vendor-analytics' ),
						'type'        => 'checkbox',
						'default'     => false,
						'_order'      => 10,
					],

					'vendor_analytics_monthly_vendors' => [
						'label'       => __( 'Vendor choice', 'hivepress-vendor-analytics' ),
						'caption'     => __( 'Let vendors decide whether to receive them', 'hivepress-vendor-analytics' ),
						'description' => __( 'Adds the choice to each vendor\'s own settings page, so they can turn the email off. Switch this off to keep the decision yourself: vendors then see nothing about it and everyone is emailed. Any choices vendors have already made are remembered and come back if you switch this on again.', 'hivepress-vendor-analytics' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 20,
					],

					'vendor_analytics_monthly_quiet'   => [
						'label'       => __( 'Quiet months', 'hivepress-vendor-analytics' ),
						'caption'     => __( 'Include vendors with no activity to report', 'hivepress-vendor-analytics' ),
						'description' => __( 'A month with no views, messages, bookings or earnings produces a page of zeros, so this is off by default. While vendors can decide for themselves, each one can override this on their own settings page.', 'hivepress-vendor-analytics' ),
						'type'        => 'checkbox',
						'default'     => false,
						'_order'      => 30,
					],
				],
			],

			'data'      => [
				'title'       => __( 'Data', 'hivepress-vendor-analytics' ),
				'description' => __( 'Figures are stored as daily totals in two small database tables on your own site.', 'hivepress-vendor-analytics' ),
				'_order'      => 20,

				'fields'      => [
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

			'uninstall' => [
				'title'       => __( 'Removing the plugin', 'hivepress-vendor-analytics' ),
				'description' => __( 'What happens if you ever delete this plugin. WordPress always shows a "will also delete its data" warning on the delete screen, but unless the box below is ticked your analytics and settings are kept, so you can reinstall later and carry on where you left off. Deactivating never removes anything.', 'hivepress-vendor-analytics' ),
				'_order'      => 30,

				'fields'      => [
					'vendor_analytics_delete_data' => [
						'label'       => __( 'Delete all data', 'hivepress-vendor-analytics' ),
						'caption'     => __( 'Permanently delete all analytics data when this plugin is deleted', 'hivepress-vendor-analytics' ),
						'description' => __( 'Removes the two analytics tables, every setting on this tab and the internal order and booking markers. This cannot be undone.', 'hivepress-vendor-analytics' ),
						'type'        => 'checkbox',
						'_order'      => 10,
					],
				],
			],
		],
	];

	// Offered only while Marketplace is active, for the same reason as the
	// Statistics control below: a switch that cannot do anything is worse
	// than no switch.
	if ( class_exists( '\HivePress\Templates\Vendor_Dashboard_Page' ) ) {
		$settings['vendor_analytics']['sections']['tracking']['fields']['vendor_analytics_hide_dashboard'] = [
			'label'       => __( 'Vendor dashboard', 'hivepress-vendor-analytics' ),
			'caption'     => __( 'Hide the Marketplace vendor dashboard, its earnings summary, from the account menu', 'hivepress-vendor-analytics' ),
			'description' => __( 'Marketplace stays active and the page still works if opened directly; only its account menu item is hidden.', 'hivepress-vendor-analytics' ),
			'type'        => 'checkbox',
			'_order'      => 55,
		];
	}

	// Offered only while the official Statistics extension is active - on any
	// other site the control would do nothing and only confuse.
	if ( class_exists( '\HivePress\Templates\Listing_Statistics_Page' ) ) {
		$settings['vendor_analytics']['sections']['tracking']['fields']['vendor_analytics_hide_statistics'] = [
			'label'       => __( 'Statistics tab', 'hivepress-vendor-analytics' ),
			'caption'     => __( 'Hide the official Statistics tab and Stats button on listings (the Analytics tab replaces them)', 'hivepress-vendor-analytics' ),
			'description' => __( 'The Statistics extension itself stays active; only its tab and its button on each listing are hidden.', 'hivepress-vendor-analytics' ),
			'type'        => 'checkbox',
			'_order'      => 50,
		];
	}

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
	return [ 'view', 'vendor_view', 'phone_click', 'email_click', 'message', 'booking_new', 'booking_confirmed', 'order', 'earning_minor', 'refund_minor', 'response_sum', 'response_count', 'favorite', 'favorite_removed', 'offer_sent', 'offer_accepted' ];
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

	// An import fires the same raw post and comment hooks we listen on, so a
	// content migration would otherwise be recorded as today's customer
	// activity. HP_IMPORT is defined from core's import_start handler
	// (components/class-hook.php:97) and every core model hook bails on it;
	// raw hooks do not, so we check it ourselves.
	if ( defined( 'HP_IMPORT' ) && HP_IMPORT ) {
		return;
	}

	$vendor_id  = (int) $vendor_id;
	$listing_id = (int) $listing_id;
	$value      = (int) $value;

	if ( ! $vendor_id && ! $listing_id ) {
		return;
	}

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own aggregate table; no core API for it, and caching happens at the read path.
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table name comes from $wpdb->prefix and cannot be a placeholder; every value below is.
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

	// See hpva_record(): never record during an import.
	if ( defined( 'HP_IMPORT' ) && HP_IMPORT ) {
		return;
	}

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own aggregate table; no core API for it, and caching happens at the read path.
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table name comes from $wpdb->prefix and cannot be a placeholder; every value below is.
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
 * Increments a daily search term click: someone searched, then opened this
 * listing from the results. Recorded on the same row as the impression when
 * the click lands on the same day, so a term's impressions and clicks read
 * together as "shown this often, opened this often".
 *
 * @param string $term Search term.
 * @param int    $listing_id Listing ID.
 * @return void
 */
function hpva_record_term_click( $term, $listing_id ) {
	global $wpdb;

	$listing_id = (int) $listing_id;

	if ( ! $listing_id || '' === $term ) {
		return;
	}

	// See hpva_record(): never record during an import.
	if ( defined( 'HP_IMPORT' ) && HP_IMPORT ) {
		return;
	}

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own aggregate table; no core API for it, and caching happens at the read path.
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table name comes from $wpdb->prefix and cannot be a placeholder; every value below is.
			'INSERT INTO ' . hpva_terms_table() . ' (stat_date, term, listing_id, impressions, clicks)
			 VALUES (%s, %s, %d, 0, 1)
			 ON DUPLICATE KEY UPDATE clicks = clicks + 1',
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

	// Both facts in one table scan. Neither user_id nor comment_karma is
	// indexed in the default comments table, so a pair lookup is a full scan;
	// conditional aggregation answers "has this vendor replied to this person
	// before?" and "when did that person first write?" in a single pass rather
	// than two, on the message-send write path.
	$conversation = $wpdb->get_row( // phpcs:ignore WordPress.DB -- source-verified hp_message schema; unindexed pair lookup is inherent to core's storage and runs once per sent message.
		$wpdb->prepare(
			"SELECT
				SUM( CASE WHEN user_id = %d AND comment_karma = %d AND comment_ID != %d THEN 1 ELSE 0 END ) AS prior_replies,
				MIN( CASE WHEN user_id = %d AND comment_karma = %d THEN comment_date_gmt END ) AS opened_at
			 FROM {$wpdb->comments}
			 WHERE comment_type = 'hp_message'
			 AND ( ( user_id = %d AND comment_karma = %d ) OR ( user_id = %d AND comment_karma = %d ) )",
			$sender_id,
			$recipient_id,
			(int) $comment_id,
			$recipient_id,
			$sender_id,
			$sender_id,
			$recipient_id,
			$recipient_id,
			$sender_id
		)
	);

	if ( ! $conversation || (int) $conversation->prior_replies > 0 ) {
		return;
	}

	// The comments-table check above cannot survive the Messages extension's
	// storage period, which permanently deletes old messages daily: once a
	// vendor's earlier replies to this counterpart are pruned, the pair would
	// read as brand new and an established conversation would record a bogus
	// slow "first response". A compact plugin-owned marker on the vendor's
	// user row is the durable memory.
	$responded = get_user_meta( $sender_id, '_hpva_responded', true );
	$responded = is_array( $responded ) ? $responded : [];

	if ( in_array( $recipient_id, $responded, true ) ) {
		return;
	}

	$opened_at = $conversation->opened_at;

	if ( ! $opened_at ) {
		return; // Vendor initiated the conversation; measures nothing.
	}

	// Mark the pair before the range check: a reply to a stale opener is
	// still a first response, it is just not a usable timing sample.
	$responded[] = $recipient_id;
	update_user_meta( $sender_id, '_hpva_responded', $responded );

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

		// Hard-deleting a listing force-deletes every comment on it while
		// the post row still exists, so without this guard a listing with 50
		// favourites records a burst of 50 phantom "favourites removed" on
		// deletion day - and listings are hard-deleted in normal use (the
		// trash emptying cron, the storage-period cron, an admin's Delete
		// Permanently). Only a person unfavouriting should count.
		if ( in_array( $listing_id, hpva_deleting_posts(), true ) ) {
			return;
		}

		$vendor_id = hpva_vendor_id_from_listing( $listing_id );

		if ( $vendor_id ) {
			hpva_record( 'favorite_removed', $vendor_id, $listing_id );
		}
	}
}

/**
 * Tracks post IDs currently being hard-deleted, so comment-deletion listeners
 * can tell a cascading cleanup from a real user action. before_delete_post
 * fires before WordPress deletes the post's comments.
 *
 * @param int|null $post_id Post ID to mark as deleting.
 * @return array<int, int>
 */
function hpva_deleting_posts( $post_id = null ) {
	static $deleting = [];

	if ( null !== $post_id ) {
		$deleting[] = (int) $post_id;
	}

	return $deleting;
}

/**
 * Marks a post as mid-deletion (see hpva_deleting_posts).
 *
 * @param int $post_id Post ID.
 * @return void
 */
function hpva_on_before_delete_post( $post_id ) {
	hpva_deleting_posts( (int) $post_id );
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

	// A booking was confirmed: it reached 'publish' from a non-published
	// state. Guarded once per booking so a confirmation is never counted
	// twice. wp_untrash_post() restores to 'draft', not the pre-trash status
	// (wp-includes/post.php:4090), so cancelling and restoring a confirmed
	// booking leaves it unpaid and the vendor confirms it again - a second
	// draft -> publish transition for one real booking.
	if ( 'publish' === $new_status && 'publish' !== $old_status && ! get_post_meta( $post->ID, '_hpva_confirmed', true ) ) {
		update_post_meta( $post->ID, '_hpva_confirmed', 1 );

		hpva_record( 'booking_confirmed', $vendor_id, $listing_id );
	}
}

/* --- Earnings (Marketplace) --------------------------------------------- */

/**
 * The vendor's take from an order, in the shop currency.
 *
 * Prefers Marketplace's own payout calculation, which is net of platform
 * commission and refunds and - per the site's "include taxes" setting - taxes,
 * and is the same figure it shows in the vendor's balance. Falls back to the
 * gross total only when that method is unavailable.
 *
 * @param \WC_Order $order Order object.
 * @return float
 */
function hpva_order_net( $order ) {
	$component = function_exists( 'hivepress' ) ? hivepress()->marketplace : null;

	if ( $component && method_exists( $component, 'get_order_profit' ) ) {
		return (float) $component->get_order_profit( $order );
	}

	return (float) $order->get_total();
}

/**
 * Records the vendor's share of a refund.
 *
 * Earnings are banked when an order is paid, so a later refund needs its own
 * entry rather than a rewrite of history: the vendor did receive that money and
 * then gave some back, and both facts belong on the dashboard. The amount is
 * the drop in Marketplace's own payout figure, which already accounts for the
 * commission and tax rules in play, so partial and repeated refunds each
 * record only their own difference.
 *
 * @param int $order_id Order ID.
 * @param int $refund_id Refund ID.
 * @return void
 */
function hpva_on_order_refunded( $order_id, $refund_id ) {
	if ( ! function_exists( 'wc_get_order' ) || ! hpva_get_option( 'vendor_analytics_earnings', true ) ) {
		return;
	}

	$order_id  = (int) $order_id;
	$vendor_id = (int) get_post_meta( $order_id, 'hp_vendor', true );

	// Only orders we actually counted can be refunded off that count.
	if ( ! $vendor_id || ! get_post_meta( $order_id, '_hpva_recorded', true ) ) {
		return;
	}

	$order = wc_get_order( $order_id );

	if ( ! $order instanceof \WC_Order ) {
		return;
	}

	$banked = (float) get_post_meta( $order_id, '_hpva_net', true );
	$now    = hpva_order_net( $order );
	$drop   = $banked - $now;

	if ( $drop <= 0 ) {
		return;
	}

	list( $factor ) = hpva_currency_scale();
	$minor          = (int) round( $drop * $factor );

	if ( $minor > 0 ) {
		hpva_record( 'refund_minor', $vendor_id, 0, $minor );

		// Track the new baseline so a second refund records only its own part.
		update_post_meta( $order_id, '_hpva_net', $now );
	}
}

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

	// wc_get_order() resolves refunds too, and a WC_Order_Refund has no
	// get_total_refunded(), which Marketplace's payout calculation calls - so
	// anything but a real order is not ours to record.
	if ( ! $order instanceof \WC_Order ) {
		return;
	}

	update_post_meta( $order_id, '_hpva_recorded', 1 );

	// Orders and earnings respect the "Track earnings" setting.
	if ( hpva_get_option( 'vendor_analytics_earnings', true ) ) {
		$amount = hpva_order_net( $order );

		list( $factor ) = hpva_currency_scale();
		$minor          = (int) round( $amount * $factor );

		hpva_record( 'order', $vendor_id, 0, 1 );

		if ( $minor > 0 ) {
			hpva_record( 'earning_minor', $vendor_id, 0, $minor );
		}

		// Remember what was banked, so a later refund can record the drop
		// rather than guessing at commission and tax rules a second time.
		update_post_meta( $order_id, '_hpva_net', $amount );
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

	// 1.8.1 dropped the "Send to vendors" setting: switching the monthly report
	// on is itself the decision to send, so a second box asking whether to send
	// had no separate meaning. Nothing reads the option any more, but a 1.8.0
	// install would otherwise carry the dead row for the life of the site.
	// Uninstall clears it too, for anyone who never passes through this path.
	if ( $stored && version_compare( (string) $stored, '1.8.1', '<' ) ) {
		delete_option( 'hp_vendor_analytics_monthly_default' );
	}

	update_option( 'hpva_version', HPVA_VERSION, false );
}

/* --- Search terms -------------------------------------------------------- */

/**
 * Normalises a search term the same way everywhere: lowercased, whitespace
 * collapsed, tags stripped and capped to the column width. The impression path
 * and the click path must agree exactly or the two would never match up.
 *
 * @param string $raw Raw search term.
 * @return string Normalised term, or '' when too short to be meaningful.
 */
function hpva_normalise_term( $raw ) {
	$term = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $raw ) ) );

	if ( function_exists( 'mb_strtolower' ) ) {
		$term = mb_substr( mb_strtolower( $term ), 0, 140 );
	} else {
		$term = strtolower( substr( $term, 0, 140 ) );
	}

	return strlen( $term ) < 2 ? '' : $term;
}

/**
 * The search term for the current request, once it has been recorded.
 * Read by the beacon enqueue so the browser can carry it to the listing a
 * visitor clicks through to.
 *
 * @param string|null $set Term to remember.
 * @return string
 */
function hpva_current_search_term( $set = null ) {
	static $term = '';

	if ( null !== $set ) {
		$term = (string) $set;
	}

	return $term;
}

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

	$term = hpva_normalise_term( get_search_query( false ) );

	if ( '' === $term ) {
		return;
	}

	// Searches are an unauthenticated write path just like the beacon, so
	// they get the same per-IP budget (their own bucket - a person browsing
	// listings should not have searches eat their view-tracking quota).
	if ( hpva_rate_limited( 'search' ) ) {
		return;
	}

	global $wp_query;

	// Listings surfaced in results: the main query, plus any featured
	// listings core pulled out of it. When "featured per page" is enabled,
	// the Attribute component queries matching featured listings separately,
	// stores their IDs in the 'featured_ids' request context and excludes
	// them from the main query via post__not_in (verified in core), so
	// reading the main query alone would miss every featured result.
	$listing_ids = [];

	foreach ( (array) $wp_query->posts as $post ) {
		if ( isset( $post->post_type, $post->ID ) && 'hp_listing' === $post->post_type ) {
			$listing_ids[] = (int) $post->ID;
		}
	}

	foreach ( (array) hivepress()->request->get_context( 'featured_ids', [] ) as $featured_id ) {
		if ( 'hp_listing' === get_post_type( (int) $featured_id ) ) {
			$listing_ids[] = (int) $featured_id;
		}
	}

	foreach ( array_unique( $listing_ids ) as $listing_id ) {
		hpva_record_term( $term, $listing_id );
	}

	// Hand the term to the beacon so a click through to any of these listings
	// can be credited back to the search that produced it.
	hpva_current_search_term( $term );
}

/*
--------------------------------------------------------------------------
Beacon: script + REST endpoint.
--------------------------------------------------------------------------
*/

/**
 * Enqueues the beacon script on listing and vendor pages, and on listing
 * search results so a search can be linked to the listing a visitor opens.
 *
 * @return void
 */
function hpva_enqueue_tracker() {
	$track_views  = (bool) hpva_get_option( 'vendor_analytics_views', true );
	$track_clicks = (bool) hpva_get_option( 'vendor_analytics_clicks', true );
	$search_term  = hpva_current_search_term();

	if ( ! $track_views && ! $track_clicks && '' === $search_term ) {
		return;
	}

	// Public view pages only. Core also sets the listing/vendor request
	// contexts on account-side routes (listing edit/renew/submit, statistics,
	// this plugin's own analytics tabs), where the beacon would count the
	// vendor's own visits, so the route name is the gate (verified core
	// routes: both match via is_singular on hp_listing / hp_vendor). A search
	// results page is not one of those routes at all, hence the extra branch:
	// it records nothing itself, it only hands the term to the browser.
	$route = hivepress()->router->get_current_route_name();

	$listing_id = 0;
	$vendor_id  = 0;

	if ( 'listing_view_page' === $route ) {
		$listing = hivepress()->request->get_context( 'listing' );

		// The context is set by the core route title callable, which runs
		// before scripts are enqueued; the queried object covers any setup
		// where it has not.
		$listing_id = ( is_object( $listing ) && $listing->get_id() ) ? (int) $listing->get_id() : (int) get_queried_object_id();
	} elseif ( 'vendor_view_page' === $route ) {
		$vendor = hivepress()->request->get_context( 'vendor' );

		$vendor_id = ( is_object( $vendor ) && $vendor->get_id() ) ? (int) $vendor->get_id() : (int) get_queried_object_id();
	} elseif ( '' === $search_term ) {
		return;
	}

	if ( ! $listing_id && ! $vendor_id && '' === $search_term ) {
		return;
	}

	wp_enqueue_script(
		'hpva-tracker',
		plugins_url( 'assets/js/tracker.js', HPVA_FILE ),
		[],
		HPVA_VERSION . '.' . (int) filemtime( __DIR__ . '/assets/js/tracker.js' ),
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
				'term'     => $search_term,
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
/**
 * Decides who may reach the export route.
 *
 * Two ways in, and only two. Normally it is cookie auth: WordPress
 * authenticates the user when a valid _wpnonce (action wp_rest) accompanies the
 * request, and without it the user is 0. The monthly summary email's button is
 * the exception, because it is opened from a mail client with no session, often
 * on a different device, so it carries a signed token instead and that
 * signature IS the authorisation. It is verified here rather than waved through
 * to the callback, so an unsigned or edited link never reaches any code that
 * reads a vendor id from the request.
 *
 * @param WP_REST_Request $request Request object.
 * @return bool
 */
function hpva_export_permission( $request ) {
	$month = hpva_sanitise_month( $request->get_param( 'month' ) );
	$token = $request->get_param( 'token' );

	if ( $month || $token ) {
		return hpva_verify_report_token( $request->get_param( 'vendor' ), $month, is_string( $token ) ? $token : '' );
	}

	return is_user_logged_in();
}

function hpva_register_rest() {
	register_rest_route(
		'hpva/v1',
		'/export',
		[
			'methods'             => 'GET',
			'permission_callback' => 'hpva_export_permission',
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

			// Declared types make the REST layer 400 malformed payloads
			// before the callback; the callback still type-checks (defence
			// in depth for clients that skip the schema).
			'args'                => [
				'm' => [ 'type' => 'string' ],
				'l' => [ 'type' => 'integer' ],
				'v' => [ 'type' => 'integer' ],
				't' => [ 'type' => 'string' ],
			],
		]
	);
}

/**
 * Per-IP fixed-window rate limiter: at most 60 events per 10-minute clock
 * window per scope. The bucket key carries the time slot, so incrementing
 * never extends the window (a plain counter transient refreshed on every
 * write quietly turns into a sliding window that a busy IP can never leave),
 * and stale buckets expire on their own.
 *
 * @param string $scope Bucket scope key.
 * @return bool Whether the current request exceeds the limit.
 */
function hpva_rate_limited( $scope ) {
	$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$slot   = (int) floor( time() / ( 10 * MINUTE_IN_SECONDS ) );
	$bucket = 'hpva_rl_' . md5( $scope . '|' . $ip . '|' . $slot );
	$count  = (int) get_transient( $bucket );

	if ( $count >= 60 ) {
		return true;
	}

	set_transient( $bucket, $count + 1, 10 * MINUTE_IN_SECONDS );

	return false;
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

	// Per-IP rate limit: 60 events per 10-minute window.
	if ( hpva_rate_limited( 'track' ) ) {
		return new WP_REST_Response( null, 429 );
	}

	// A public endpoint receives arbitrary JSON, and PHP's (string) cast of
	// an array logs a warning - so a body like {"m":["view"]} would let
	// anyone write warnings into debug.log at will. Type-check before
	// casting; non-strings are simply not a known metric.
	$raw_metric = $request->get_param( 'm' );
	$metric     = is_string( $raw_metric ) ? sanitize_key( $raw_metric ) : '';
	$listing_id = is_scalar( $request->get_param( 'l' ) ) ? (int) $request->get_param( 'l' ) : 0;
	$vendor_id  = is_scalar( $request->get_param( 'v' ) ) ? (int) $request->get_param( 'v' ) : 0;

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

				// A view that followed a search credits that search. The term
				// is re-normalised here rather than trusted: it arrives from a
				// public endpoint, and it has to match the impression rows
				// character for character to be worth anything.
				if ( 'view' === $metric && hpva_get_option( 'vendor_analytics_search', true ) ) {
					$raw_term = $request->get_param( 't' );

					if ( is_string( $raw_term ) ) {
						hpva_record_term_click( hpva_normalise_term( $raw_term ), $listing_id );
					}
				}
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
		$mins = array_filter(
			[
				$wpdb->get_var( 'SELECT MIN(stat_date) FROM ' . hpva_table() ), // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
				$wpdb->get_var( 'SELECT MIN(stat_date) FROM ' . hpva_terms_table() ), // phpcs:ignore WordPress.DB -- custom analytics tables / source-verified hp_ schema; table names derive from $wpdb->prefix and cannot be placeholders; caching by design at the transient layer.
			]
		);

		$from = $mins ? min( $mins ) : $to;
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
		// A distinct direction, not 'up': first-ever data is not a
		// deterioration on cards where lower is better (response time), so
		// the renderer must be able to tell "new" from "worse".
		return [
			'dir'  => 'new',
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

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own aggregate table; no core API for it, and the dashboard renders once per request.
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table name comes from $wpdb->prefix and cannot be a placeholder; every value below is.
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

	// Ranked by views, best first, so the table doubles as a league table of
	// the vendor's listings - the row order IS the ranking, everywhere the
	// breakdown appears (dashboard, report, CSV).
	uasort(
		$breakdown,
		function ( $a, $b ) {
			$a_views = isset( $a['view'] ) ? $a['view'] : 0;
			$b_views = isset( $b['view'] ) ? $b['view'] : 0;

			return $b_views <=> $a_views;
		}
	);

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
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own aggregate table; no core API for it, and the dashboard renders once per request.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the table name comes from $wpdb->prefix and cannot be a placeholder; every value below is.
				'SELECT term, SUM(impressions) AS impressions, SUM(clicks) AS clicks FROM ' . hpva_terms_table() . '
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

	return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own aggregate table joined to core posts; no core API for it, and the dashboard renders once per request.
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- both table names come from $wpdb and cannot be placeholders; every value below is.
			"SELECT t.term, SUM(t.impressions) AS impressions, SUM(t.clicks) AS clicks FROM {$table} t
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

	// Numerator and denominator must describe the same set of listings, so
	// this sums views for exactly the published listings counted below -
	// never hpva_total() over the whole vendor, whose rows include deleted
	// listings' historic views and would inflate the average against the
	// category side, which already samples this way.
	$vendor_placeholders = implode( ',', array_fill( 0, count( $vendor_listings ), '%d' ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	// Our own aggregate table (name from $wpdb->prefix, not preparable);
	// $vendor_placeholders is a generated run of %d and every value - dates
	// and listing IDs alike - arrives through prepare()'s parameter array.
	$vendor_views = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COALESCE(SUM(value),0) FROM ' . hpva_table() . "
			 WHERE metric = 'view' AND stat_date BETWEEN %s AND %s
			 AND listing_id IN ( $vendor_placeholders )",
			array_merge( [ $from, $to ], array_map( 'intval', $vendor_listings ) )
		)
	);
	// phpcs:enable

	$vendor_avg = $vendor_views / count( $vendor_listings ) / $days;

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

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			// Same shape as the vendor query above: own table, generated %d
			// run, all values prepared; the six-hour transient is the cache.
			$cat_views = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(SUM(value),0) FROM ' . hpva_table() . "
					 WHERE metric = 'view' AND stat_date BETWEEN %s AND %s
					 AND listing_id IN ( $placeholders )",
					array_merge( [ $from, $to ], array_map( 'intval', $cat_listings ) )
				)
			);
			// phpcs:enable

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
			// Route name keeps the item highlighted under ?hpva_period URLs.
			'route'  => 'vendor_analytics_page',
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

	// The listing_manage menu also renders on the PUBLIC listing page, where
	// it is empty for everyone but the owner (a zero-item menu renders
	// nothing). Core adds 'listing_edit' only for the listing's owner
	// (components/class-listing.php:719), so requiring it here means our tab
	// never surfaces the menu to strangers - the same guard the official
	// Statistics extension uses. And instanceof, never method_exists: model
	// getters resolve through Model::__call, so method_exists() is false for
	// all of them and would silently hide this tab on every site.
	if ( isset( $items['listing_edit'] ) && $listing instanceof \HivePress\Models\Listing && 'publish' === $listing->get_status() ) {
		$items['listing_analytics'] = [
			'label'  => esc_html__( 'Analytics', 'hivepress-vendor-analytics' ),
			'url'    => hivepress()->router->get_url( 'listing_analytics_page', [ 'listing_id' => $listing->get_id() ] ),
			// The route name keeps the tab highlighted when the period
			// switcher adds ?hpva_period to the URL - current-item matching
			// is exact-URL or route name, and the URL match fails then.
			'route'  => 'listing_analytics_page',
			'_order' => 55,
		];
	}

	return $items;
}

/**
 * Wires up the integrations that depend on another extension's classes.
 *
 * Deliberately on 'init' rather than at plugins_loaded with the rest of the
 * bootstrap: class_exists() on a HivePress class triggers the autoloader,
 * which requires the file AND calls its static init() (class-core.php:124).
 * Those init() methods translate strings, so probing for one at plugins_loaded
 * translates before WordPress is ready and logs "translation loading was
 * triggered too early" for that extension and everything its class hierarchy
 * pulls in. Measured on this site: one such probe filled debug.log with 14 KB
 * of notices for a dozen domains on every front-end request.
 *
 * @return void
 */
function hpva_register_optional_integrations() {
	// First-party summary above the official Statistics extension's Google
	// Analytics chart, and the optional hiding of its listing tab. The hide
	// filter runs at priority 150 because Statistics adds its item at 100 -
	// earlier and there would be nothing to remove yet.
	if ( class_exists( '\HivePress\Templates\Listing_Statistics_Page' ) ) {
		add_filter( 'hivepress/v1/templates/listing_statistics_page', 'hpva_inject_statistics_summary' );
		add_filter( 'hivepress/v1/menus/listing_manage/items', 'hpva_hide_statistics_tab', 150 );

		// The Statistics extension also puts a "Stats" button on each card in
		// Account > My Listings (block listing_statistics_link, injected at
		// priority 10); hiding the tab but leaving a one-click route to the
		// same page would make the setting a half-truth. Priority 100 so the
		// button exists before we remove it.
		add_filter( 'hivepress/v1/templates/listing_edit_block', 'hpva_hide_statistics_link', 100 );
	}

	// Optional hiding of Marketplace's own earnings dashboard item.
	// Marketplace adds its items through the menu PROPERTIES filter, which
	// runs in the constructor, so by the time this items filter fires they
	// are all present.
	if ( class_exists( '\HivePress\Templates\Vendor_Dashboard_Page' ) ) {
		add_filter( 'hivepress/v1/menus/user_account/items', 'hpva_hide_vendor_dashboard', 200 );
	}
}

/**
 * Removes Marketplace's vendor dashboard (its earnings summary) from the
 * account menu when the admin has opted to, since this plugin's Analytics
 * page covers the same ground. The page itself is untouched.
 *
 * @param array<string, mixed> $items Menu items.
 * @return array<string, mixed>
 */
function hpva_hide_vendor_dashboard( $items ) {
	if ( hpva_get_option( 'vendor_analytics_hide_dashboard', false ) ) {
		unset( $items['vendor_dashboard'] );
	}

	return $items;
}

/**
 * Removes the official Statistics tab from the listing manage menu when the
 * admin has opted to, since this plugin's Analytics tab covers the same need.
 * The extension itself is untouched - only its tab is hidden.
 *
 * @param array<string, mixed> $items Menu items.
 * @return array<string, mixed>
 */
function hpva_hide_statistics_tab( $items ) {
	if ( hpva_get_option( 'vendor_analytics_hide_statistics', false ) ) {
		unset( $items['listing_statistics'] );
	}

	return $items;
}

/**
 * Removes the Statistics extension's "Stats" button from the My Listings
 * cards when the admin has hidden its tab - the two are one decision.
 * fetch_block() removes by design and is a silent no-op when absent.
 *
 * @param array<string, mixed> $template Template arguments.
 * @return array<string, mixed>
 */
function hpva_hide_statistics_link( $template ) {
	if ( hpva_get_option( 'vendor_analytics_hide_statistics', false ) ) {
		hivepress()->template->fetch_block( $template, 'listing_statistics_link' );
	}

	return $template;
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
	// merge_blocks takes a flat "block name => args" map and finds that block
	// wherever it sits in the tree, so no nesting path is needed; Ihor has said
	// merge_trees will be deprecated in its favour. Adding a block means
	// targeting an existing parent, here 'page_content' - an entry whose name
	// matches nothing in the tree is silently discarded. The parent tree is
	// fully merged by the time this constructor filter runs.
	//
	// Orders are chosen around page_content's OWN children: the manage-menu
	// tabs are page_topbar at _order 9 INSIDE page_content (Page_Wide,
	// class-page-wide.php:63-67), and the GA statistics block sits at 10. An
	// order below 9 puts our summary between the page title and the
	// navigation tabs, which reads as broken. So: summary at 15 (below the
	// tabs), GA chart nudged to 20 (still below the summary, preserving the
	// "first-party summary above the GA chart" feature).
	hivepress()->template->merge_blocks(
		$template,
		[
			'page_content' => [
				'blocks' => [
					'hpva_vendor_analytics_summary' => [
						'type'   => 'hpva_vendor_analytics_summary',
						'_order' => 15,
					],
				],
			],
		]
	);

	// Separate call by design: one map targeting a parent AND one of its
	// descendants silently drops the descendant (merge_blocks never visits a
	// matched block's children in the same pass).
	return hivepress()->template->merge_blocks(
		$template,
		[
			'listing_statistics' => [
				'_order' => 20,
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
 * @param array<int, array{label: string, color: string, series: array<string, int>}> $datasets Chart datasets, each [ 'label' => string, 'color' => hex, 'series' => [ date => int ] ].
 * @param int                                                                         $height   Chart height.
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
 * @param array<int, array{label: string, value: int}> $steps Raw funnel steps.
 * @return array<int, array{label: string, value: int, rate: float|null, of: string, width: int|float}>
 */
function hpva_funnel_steps( $steps ) {
	$out        = [];
	$prev       = null;
	$prev_label = '';
	$top        = 0;

	foreach ( $steps as $step ) {
		$value = max( 0, (int) $step['value'] );
		$top   = max( $top, $value );

		$out[] = [
			'label' => $step['label'],
			'value' => $value,
			'rate'  => ( null !== $prev && $prev > 0 ) ? round( 100 * $value / $prev, 1 ) : null,
			// The step this rate is a percentage OF, so the reader is never
			// left guessing what "0.7%" is measuring.
			'of'    => $prev_label,
			'width' => 0, // filled below once $top known
		];

		$prev       = $value;
		$prev_label = $step['label'];
	}

	foreach ( $out as $i => $step ) {
		$out[ $i ]['width'] = $top > 0 ? max( 2, round( 100 * $step['value'] / $top ) ) : 2;
	}

	return $out;
}

/**
 * Lowercases a funnel step label for use mid-sentence ("0.7% of views").
 * Left alone when the label starts with more than one capital, which is the
 * sign of an acronym or a proper noun in a translation.
 *
 * @param string $label Step label.
 * @return string
 */
function hpva_lowercase_label( $label ) {
	$label = (string) $label;

	if ( '' === $label || preg_match( '/^\p{Lu}\p{Lu}/u', $label ) ) {
		return $label;
	}

	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $label ) : strtolower( $label );
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
			$html .= ' <small>' . esc_html(
				sprintf(
					/* translators: 1: percentage, 2: name of the previous funnel step, for example "views". */
					__( '%1$s%% of %2$s', 'hivepress-vendor-analytics' ),
					number_format_i18n( $step['rate'], 1 ),
					hpva_lowercase_label( $step['of'] )
				)
			) . '</small>';
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
 * @param bool                                  $collapsible Whether the note hides behind an information icon.
 * @return string
 */
function hpva_card( $label, $value, $note = '', $delta = null, $invert = false, $collapsible = false ) {
	$html = '<div class="hpva-card"><span class="hpva-card__value">' . esc_html( $value );

	if ( is_array( $delta ) ) {
		$good = ( 'up' === $delta['dir'] ) !== $invert;
		$tone = 'flat' === $delta['dir'] ? 'flat' : ( $good ? 'good' : 'bad' );
		$icon = 'up' === $delta['dir'] ? '&#8593;' : ( 'down' === $delta['dir'] ? '&#8595;' : '' );

		// First-ever data: encouraging on normal cards, neutral on inverted
		// ones (a first response-time sample is not a deterioration), and
		// never an arrow - nothing moved.
		if ( 'new' === $delta['dir'] ) {
			$tone = $invert ? 'flat' : 'good';
			$icon = '';
		}

		$html .= ' <span class="hpva-delta hpva-delta--' . esc_attr( $tone ) . '">' . $icon . esc_html( $delta['text'] ) . '</span>';
	}

	$html .= '</span>';

	// $collapsible stays false unless a caller asks for the icon, and the
	// downloadable report depends on that default. A hover tooltip is nothing
	// at all on paper, so a note tucked into one there is a note nobody can
	// ever read. When one of the two surfaces is a PDF, the only safe way to
	// fail is towards "the words are on the page". Never flip this default.
	if ( $note && $collapsible ) {

		// Styled after HivePress's own admin tooltip (hp-tooltip in
		// backend.less): a muted mark that brightens on hover, revealing a
		// dark bubble to its left. The class names are ours because that CSS
		// is loaded only in wp-admin, so the look has to be restated here
		// rather than reused, and render_tooltip() is a protected method on
		// the Admin component in any case. Core's icon is a dashicon, which
		// is also admin-only, so this uses the Font Awesome 5 solid set that
		// core enqueues site-wide. fa-info-circle is the FA 5 name; the FA 6
		// name fa-circle-info does not exist in it.
		//
		// The label becomes a flex row so the mark sits beside the text
		// instead of after it. A long label such as "Avg first response"
		// wraps to two lines in a narrow card, and as an inline element the
		// mark was landing alone on a third line and making that card taller
		// than its neighbours.
		$html .= '<span class="hp-meta hpva-card__label hpva-card__label--tip">' . esc_html( $label );

		// tabindex makes the mark reachable by keyboard, which a hover-only
		// tooltip otherwise is not; :focus-within reveals the bubble. Core's
		// admin tooltip does not do this, but it costs nothing and the note
		// is the only explanation the card carries.
		$html .= '<span class="hpva-tooltip" tabindex="0">';
		$html .= '<i class="fas fa-info-circle hpva-tooltip__icon" aria-hidden="true"></i>';
		$html .= '<span class="hpva-tooltip__text">' . esc_html( $note ) . '</span>';
		$html .= '</span></span>';

		return $html . '</div>';
	}

	$html .= '<span class="hp-meta hpva-card__label">' . esc_html( $label ) . '</span>';

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
		// Diagnostics still ship on this path: an admin investigating "why
		// is analytics empty" is usually not a vendor themselves, and this
		// early return is exactly the page they will be looking at.
		return '<p>' . esc_html__( 'Analytics are available once your vendor profile is set up.', 'hivepress-vendor-analytics' ) . '</p>' . hpva_admin_diagnostics();
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
	$refunds  = $m( 'refund_minor' );
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

		// Refunded and Net earnings read for themselves. Earnings does not:
		// it is the vendor's share after commission, while Marketplace's own
		// vendor Dashboard charts the full order value and calls it Revenue
		// (blocks/class-vendor-statistics.php:100 sums get_order_total(), we
		// sum get_order_profit()). Someone comparing the two screens finds a
		// gap with nothing on the page to explain it, so this one keeps a
		// mark. The wording avoids naming a screen, because the Dashboard can
		// be switched off from our own settings tab.
		if ( $orders || $earnings ) {
			$out .= hpva_card( __( 'Orders completed', 'hivepress-vendor-analytics' ), number_format_i18n( $orders ), '', $d( 'order' ) );
			$out .= hpva_card( __( 'Earnings', 'hivepress-vendor-analytics' ), hpva_money( $earnings ), __( 'What you keep after commission, counted when each order is paid. Totals elsewhere on the site may show the full order value, which is higher.', 'hivepress-vendor-analytics' ), $d( 'earning_minor' ), false, true );
			$out .= hpva_card( __( 'Refunded', 'hivepress-vendor-analytics' ), hpva_money( $refunds ), '', $d( 'refund_minor' ), true );
			$out .= hpva_card( __( 'Net earnings', 'hivepress-vendor-analytics' ), hpva_money( max( 0, $earnings - $refunds ) ) );
		}

		// The one figure that genuinely is not self-explanatory: "49 min"
		// says nothing about what was measured from what. The last two
		// arguments read alike and are easy to swap: $invert first (a shorter
		// response time is good news), then $collapsible.
		if ( $resp_n > 0 ) {
			$prev_avg  = ( isset( $prev['response_count'] ) && $prev['response_count'] > 0 ) ? $prev['response_sum'] / $prev['response_count'] : 0;
			$avg_delta = ( 0 !== $period ) ? hpva_delta( $resp_sum / $resp_n, $prev_avg ) : null;
			$out      .= hpva_card( __( 'Avg first response', 'hivepress-vendor-analytics' ), hpva_duration( $resp_sum / $resp_n ), __( 'From a customer\'s first message to your first reply', 'hivepress-vendor-analytics' ), $avg_delta, true, true );
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
		$out .= '<h3 class="hp-section__title hpva-h">' . esc_html__( 'Conversion funnel', 'hivepress-vendor-analytics' ) . '</h3>'
			. '<p class="hpva-sub">' . esc_html__( 'How many of the people who saw your listings went on to contact you, and how many of those went on to book.', 'hivepress-vendor-analytics' ) . '</p>';
		$out .= hpva_render_funnel( $funnel_steps );
	}

	// Views + messages trend.
	if ( hpva_section_on( 'trend' ) ) :
		$out .= '<h3 class="hp-section__title hpva-h">' . esc_html__( 'Views & messages', 'hivepress-vendor-analytics' ) . '</h3>';
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

		$out .= '<h3 class="hp-section__title hpva-h">' . esc_html__( 'First response time (minutes, daily average)', 'hivepress-vendor-analytics' ) . '</h3>';
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

		$out .= '<h3 class="hp-section__title hpva-h">' . esc_html__( 'Earnings', 'hivepress-vendor-analytics' ) . '</h3>';
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
	// One switch, not two: the 'benchmark' entry in Visible sections is the
	// single control (a second identically-labelled checkbox used to gate
	// this too, leaving the screen showing "on" while nothing rendered).
	if ( hpva_section_on( 'benchmark' ) ) {
		$benchmark = hpva_benchmark( $vendor_id, $from, $to );

		if ( $benchmark ) {
			$out .= '<h3 class="hp-section__title hpva-h">' . esc_html__( 'Category benchmark', 'hivepress-vendor-analytics' ) . '</h3>';
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
		$out .= '<h3 class="hp-section__title hpva-h">' . esc_html__( 'Search terms that surfaced your listings', 'hivepress-vendor-analytics' ) . '</h3>'
			. '<p class="hpva-sub">' . esc_html__( 'What people typed, how often your listings appeared in those results, and how often they were opened from them.', 'hivepress-vendor-analytics' ) . '</p>';
		$out .= '<div class="hpva-table-wrap"><table class="hpva-table"><thead><tr><th>' . esc_html__( 'Term', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Impressions', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Clicks', 'hivepress-vendor-analytics' ) . '</th></tr></thead><tbody>';

		foreach ( $terms as $row ) {
			$out .= '<tr><td>' . esc_html( $row->term ) . '</td><td>' . esc_html( number_format_i18n( (int) $row->impressions ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $row->clicks ) ) . '</td></tr>';
		}

		$out .= '</tbody></table></div>';
	}

	// Per-listing breakdown.
	$breakdown = hpva_section_on( 'breakdown' ) ? hpva_listing_breakdown( $vendor_id, $from, $to ) : [];

	if ( $breakdown ) {
		$out .= '<h3 class="hp-section__title hpva-h">' . esc_html__( 'Per-listing breakdown', 'hivepress-vendor-analytics' ) . '</h3>';
		$out .= '<p class="hpva-sub">' . esc_html__( 'Ranked by views for the selected period, best performer first.', 'hivepress-vendor-analytics' ) . '</p>';
		$out .= '<div class="hpva-table-wrap">' . hpva_breakdown_table( $breakdown, true ) . '</div>';
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

	// Theme button classes, not bespoke CSS: button--primary and
	// button--secondary are the two appearance modifiers all six official
	// themes style, and hp-button is core's structural partner, so these
	// inherit each theme's native look with no styling of our own.
	return '<p class="hpva-export">'
		. '<a class="hp-button button button--primary hpva-export__btn" href="' . esc_url( $report_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Download report', 'hivepress-vendor-analytics' ) . '</a>'
		. '<a class="hp-button button button--secondary hpva-export__btn" href="' . esc_url( $metrics_url ) . '">' . esc_html__( 'Export CSV', 'hivepress-vendor-analytics' ) . '</a>'
		. '</p>';
}

/**
 * Writes one CSV row with explicit parameters. Passing the escape parameter
 * explicitly (empty, so quoting is standard RFC 4180) avoids the PHP 8.4
 * deprecation for the implicit proprietary escape default.
 *
 * @param resource                     $stream Output stream.
 * @param array<int, string|int|float> $row    Row values.
 * @return void
 */
function hpva_put_csv( $stream, $row ) {
	fputcsv( $stream, $row, ',', '"', '' );
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
	$month = hpva_sanitise_month( $request->get_param( 'month' ) );
	$token = (string) $request->get_param( 'token' );

	// The monthly email's button is opened from a mail client: no session, no
	// nonce, and often a different device from the one the vendor logged in on.
	// The signed token therefore has to carry the authorisation itself. It is
	// checked before anything else so a valid token never depends on being
	// logged in, and an invalid one never falls through to the cookie path with
	// a vendor id an attacker chose.
	if ( $token || $month ) {
		if ( ! $month || ! hpva_verify_report_token( $request->get_param( 'vendor' ), $month, $token ) ) {
			return new WP_REST_Response( [ 'error' => 'bad_token' ], 403 );
		}

		$vendor_id = (int) $request->get_param( 'vendor' );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		echo hpva_report_html( $vendor_id, 30, 0, hpva_month_range( $month ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- complete standalone document, escaped at build time.
		exit;
	}

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
		hpva_put_csv( $output, [ 'term', 'impressions', 'clicks' ] );

		foreach ( (array) hpva_top_terms( $vendor_id, $from, $to, 1000, $listing_id ? $listing_id : null ) as $row ) {
			hpva_put_csv( $output, [ hpva_csv_field( $row->term ), (int) $row->impressions, (int) $row->clicks ] );
		}
	} else {
		foreach ( hpva_report_csv_rows( $vendor_id, $period, $listing_id ) as $csv_row ) {
			hpva_put_csv( $output, $csv_row );
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
	// Same raw read as the Messages extension itself (no default): absent
	// means storage off and messages go by email only.
	$lines[] = 'message + response metrics need the Messages extension AND its "Store messages" setting: ' . ( class_exists( '\HivePress\Models\Message' ) ? ( get_option( 'hp_message_enable_storage' ) ? 'storage on' : 'storage OFF - messages are email-only and can never be counted' ) : 'Messages extension inactive' );
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

		$out .= '<h3 class="hp-section__title hpva-h">' . esc_html__( 'Conversion funnel', 'hivepress-vendor-analytics' ) . '</h3>'
			. '<p class="hpva-sub">' . esc_html__( 'How many of the people who saw your listings went on to contact you, and how many of those went on to book.', 'hivepress-vendor-analytics' ) . '</p>';
		$out .= hpva_render_funnel( $funnel_steps );
	}

	if ( hpva_section_on( 'trend' ) ) {
		$out .= '<h3 class="hp-section__title hpva-h">' . esc_html__( 'Views & messages', 'hivepress-vendor-analytics' ) . '</h3>';
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
			$out .= '<h3 class="hp-section__title hpva-h">' . esc_html__( 'Search terms that surfaced this listing', 'hivepress-vendor-analytics' ) . '</h3>'
				. '<p class="hpva-sub">' . esc_html__( 'What people typed, how often this listing appeared in those results, and how often it was opened from them.', 'hivepress-vendor-analytics' ) . '</p>';
			$out .= '<div class="hpva-table-wrap"><table class="hpva-table"><thead><tr><th>' . esc_html__( 'Term', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Impressions', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Clicks', 'hivepress-vendor-analytics' ) . '</th></tr></thead><tbody>';

			foreach ( $terms as $row ) {
				$out .= '<tr><td>' . esc_html( $row->term ) . '</td><td>' . esc_html( number_format_i18n( (int) $row->impressions ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $row->clicks ) ) . '</td></tr>';
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
	$out .= '<h3 class="hp-section__title hpva-h">' . esc_html__( 'Last 90 days', 'hivepress-vendor-analytics' ) . '</h3>';
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
		$out .= '<h3 class="hp-section__title hpva-h">' . esc_html__( 'Top search terms', 'hivepress-vendor-analytics' ) . '</h3><ul class="hpva-terms">';

		foreach ( $terms as $row ) {
			$out .= '<li>' . esc_html( $row->term ) . ' <small>' . esc_html(
				sprintf(
					/* translators: 1: number of times shown, 2: number of times opened. */
					__( '%1$s shown, %2$s opened', 'hivepress-vendor-analytics' ),
					number_format_i18n( (int) $row->impressions ),
					number_format_i18n( (int) $row->clicks )
				)
			) . '</small></li>';
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
		// .hpva-cards uses a fixed 150px track minimum rather than min-content,
		// so a long unbroken translated word (German and Finnish compounds
		// reach thirty-odd characters) spills out of the card instead of
		// widening the track. This closes that on every card, not just the
		// four that gain an icon.
		// position:relative makes the card the containing block for its
		// tooltip bubble (see below). No z-index, so no stacking context is
		// created and nothing else on the page changes layer.
		. '.hpva-card{position:relative;padding:1rem;border:1px solid rgba(7,36,86,.075);border-radius:3px;box-shadow:0 2px 4px 0 rgba(7,36,86,.075);background:#fff;display:flex;flex-direction:column;gap:.15rem;overflow-wrap:break-word}'
		. '.hpva-card__value{font-size:1.35em;font-weight:700}'
		// Card labels, subtitles and the empty state carry core's hp-meta class
		// for their muted look. The note names its own colour instead: hp-meta
		// is rgba(15,23,39,.45) in all six themes, and multiplying that by an
		// opacity landed the note near 2.3:1 on the white card. That was poor
		// when it was decoration and is worse now, because this text is
		// something a vendor has deliberately opened. #4a5568 is about 7.5:1
		// and is already the plugin's muted ink (period pills, flat deltas).
		. '.hpva-card__note{font-size:.72em;color:#4a5568}'
		// A flex row so the mark sits beside the label text rather than after
		// it. "Avg first response" wraps to two lines in a narrow card, and an
		// inline mark was dropping onto a third line of its own and making
		// that card taller than the ones beside it. align-items:flex-start
		// keeps the mark level with the first line when the text wraps.
		. '.hpva-card__label--tip{display:flex;align-items:flex-start;gap:.35em}'
		// Values copied from core's admin tooltip (hp-tooltip in
		// backend.less), which is not loaded on the front end and so has to be
		// restated rather than reused. Same muted mark at half opacity, same
		// dark bubble to its left, same 782px cut-off.
		. '.hpva-tooltip{flex:0 0 auto;cursor:help;line-height:1}'
		. '.hpva-tooltip__icon{color:#82878c;opacity:.5;font-size:1.1em}'
		. '.hpva-tooltip:hover .hpva-tooltip__icon,.hpva-tooltip:focus-within .hpva-tooltip__icon{opacity:1}'
		// The bubble hangs off the CARD, not off the mark, which is why
		// .hpva-tooltip itself is not positioned. Core's admin tooltip opens
		// leftwards from its mark because it sits at the end of a wide table
		// row and has the room; inside a 180px card the same rule put the
		// bubble straight over the card's own label and hung it off the left
		// edge. Anchored to the card and stretched left:0/right:0 it can only
		// ever be exactly as wide as the card it explains, so it cannot
		// overflow the page at any width and needs no RTL handling.
		//
		// Sized in rem, not em: the bubble sits inside a label already reduced
		// to 80% by hp-meta, so an em size would compound to about 10px. The
		// transform and spacing resets stop a theme's uppercased card label
		// from being inherited by the sentence inside the bubble.
		. '.hpva-tooltip__text{display:none;position:absolute;z-index:1000;left:0;right:0;top:calc(100% + 6px);padding:.5em 10px;font-size:.8rem;line-height:1.4em;font-weight:400;text-transform:none;letter-spacing:normal;text-align:left;background:rgba(0,0,0,.75);color:#fff;border-radius:2px}'
		. '.hpva-tooltip__text::after{content:" ";display:block;border:4px solid transparent;border-bottom-color:rgba(0,0,0,.75);position:absolute;left:1rem;top:-8px}'
		. '.hpva-tooltip:hover .hpva-tooltip__text,.hpva-tooltip:focus-within .hpva-tooltip__text{display:block}'
		// Hidden on phones exactly as core hides its own, at the same
		// breakpoint. A hover tooltip has no meaning on a touchscreen, and the
		// figures these mark are self-explanatory enough to live without it;
		// the downloadable report carries the wording in full either way.
		. '@media screen and (max-width:782px){.hpva-tooltip{display:none}}'
		. '@media print{.hpva-tooltip{display:none}}'
		// hp-section__title supplies the bottom gap; only the gap between
		// stacked dashboard sections is ours.
		. '.hpva-h{margin-top:1.75rem}'
		. '.hpva-chart{width:100%;height:auto;display:block;border:1px solid rgba(7,36,86,.075);border-radius:3px;background:#fff}'
		. '.hpva-legend{display:flex;gap:1rem;margin:.5rem 0 0;font-size:.8em;opacity:.8}'
		. '.hpva-legend__dot{display:inline-block;width:.7em;height:.7em;border-radius:50%;margin-right:.35em}'
		. '.hpva-funnel__row{display:flex;align-items:center;gap:.75rem;margin:0 0 .5rem}'
		. '.hpva-funnel__label{flex:0 0 90px;font-size:.85em;opacity:.75}'
		. '.hpva-funnel__track{flex:1;background:#eaecf0;border-radius:999px;height:1.1em;overflow:hidden}'
		. '.hpva-funnel__bar{display:block;height:100%;background:#6b7cf6;border-radius:999px}'
		. '.hpva-funnel__value{flex:0 0 9rem;font-size:.85em;font-weight:600;text-align:right}'
		. '.hpva-funnel__value small{opacity:.7}'
		. '.hpva-table{width:100%;border-collapse:collapse;margin:0 0 1rem;font-size:.9em}'
		. '.hpva-table th,.hpva-table td{padding:.5rem .6rem;border-bottom:1px solid rgba(0,0,0,.06);text-align:left}'
		. '.hpva-table th{font-size:.8em;text-transform:uppercase;letter-spacing:.03em;opacity:.6}'
		. '.hpva-terms{list-style:none;margin:0;padding:0}'
		. '.hpva-terms li{padding:.3rem 0;border-bottom:1px solid rgba(0,0,0,.05)}'
		. '.hpva-sub{margin:0 0 1rem;opacity:.8}'
		. '.hpva-empty{opacity:.8}'
		. '.hpva-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 0 1rem}'
		. '.hpva-table-wrap .hpva-table{margin:0}'
		. '.hpva-table--wide{min-width:560px}'
		. '.hpva-rank{margin-right:.15em}'
		// Below the grid's own first breakpoint a seven-column table cannot
		// fit, and a sideways scroll cut off mid-column reads as broken. Each
		// listing stacks as a block instead: title, then labelled figures
		// (from data-th) wrapping naturally, so nothing is hidden.
		. '@media(max-width:47.99em){'
		. '.hpva-table--wide{min-width:0}'
		. '.hpva-table--wide thead{display:none}'
		. '.hpva-table--wide,.hpva-table--wide tbody,.hpva-table--wide tr{display:block}'
		. '.hpva-table--wide tr{padding:.6rem 0;border-bottom:1px solid rgba(0,0,0,.06)}'
		. '.hpva-table--wide td{display:inline-block;border:0;padding:.1rem 1rem .1rem 0}'
		. '.hpva-table--wide td.hpva-table__listing{display:block;font-weight:600;padding:0 0 .3rem}'
		. '.hpva-table--wide td[data-th]:before{content:attr(data-th) ": ";opacity:.6}'
		. '.hpva-funnel__label{flex-basis:5rem}'
		. '.hpva-funnel__value{flex-basis:6rem}'
		. '.hpva-funnel__value small{display:block}'
		. '}'
		. '.hpva-delta{font-size:.55em;font-weight:600;vertical-align:middle;padding:.15em .5em;border-radius:999px;white-space:nowrap}'
		. '.hpva-delta--good{background:rgba(79,178,134,.15);color:#2f7d5c}'
		. '.hpva-delta--bad{background:rgba(214,88,88,.12);color:#b04a4a}'
		. '.hpva-delta--flat{background:#eaecf0;color:#4a5568}'
		. '.hpva-export{margin:0 0 1.25rem;display:flex;gap:.5rem;flex-wrap:wrap}'
		// The theme supplies the buttons' look; only the hover-colour trap
		// needs restating: HiveTheme's bare a:hover outranks the themes'
		// .button--*{color:#fff} on link-buttons, turning the label blue.
		. 'a.hpva-export__btn:hover{color:#fff}'
		. '.hpva--listing{margin:0 0 2rem}'
		. '</style>';
}

/*
--------------------------------------------------------------------------
Downloadable reports (HTML + sectioned CSV).
--------------------------------------------------------------------------
*/

/**
 * Gets the site name with WordPress's stored escaping undone. The blogname
 * option is saved already HTML-escaped, so "Bob & Sons" reads back as
 * "Bob &amp; Sons"; harmless when re-escaped for HTML (esc_html does not
 * double-encode) but wrong anywhere the raw text is the output, such as a
 * CSV cell. Decode once here and escape at each point of output as usual.
 *
 * @return string
 */
function hpva_site_name() {
	return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
}

/**
 * Decodes HTML entities out of text bound for a non-HTML surface (CSV cells).
 * get_the_title() applies wptexturize, which emits entities like &#8217; for
 * apostrophes - correct in HTML, garbage in a spreadsheet cell.
 *
 * @param string $text Raw text.
 * @return string
 */
function hpva_plain_text( $text ) {
	return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * Gets the per-listing breakdown columns as a metric => label map, shared by
 * the dashboard table, the report table and the stacked mobile layout's
 * data-th labels so the three can never drift apart.
 *
 * @return array<string, string>
 */
function hpva_breakdown_columns() {
	return [
		'view'              => __( 'Views', 'hivepress-vendor-analytics' ),
		'message'           => __( 'Messages', 'hivepress-vendor-analytics' ),
		'booking_confirmed' => __( 'Bookings', 'hivepress-vendor-analytics' ),
		'favorite'          => __( 'Favourites', 'hivepress-vendor-analytics' ),
		'phone_click'       => __( 'Phone', 'hivepress-vendor-analytics' ),
		'email_click'       => __( 'Email', 'hivepress-vendor-analytics' ),
	];
}

/**
 * Renders the per-listing breakdown table. Every metric cell carries its
 * column label in data-th: below 48em the stylesheet hides the header row and
 * stacks each listing as a block, where the attribute becomes the visible
 * label - a seven-column table cannot fit a phone, and a sideways scroll cut
 * off mid-column reads as broken rather than scrollable.
 *
 * @param array<int, array<string, int>> $breakdown Per-listing metric sums.
 * @param bool                           $link_rows Whether to link listing titles.
 * @return string
 */
function hpva_breakdown_table( $breakdown, $link_rows ) {
	$columns = hpva_breakdown_columns();

	$html = '<table class="hpva-table hpva-table--wide"><thead><tr><th>' . esc_html__( 'Listing', 'hivepress-vendor-analytics' ) . '</th>';

	foreach ( $columns as $label ) {
		$html .= '<th>' . esc_html( $label ) . '</th>';
	}

	$html .= '</tr></thead><tbody>';

	$rank = 0;

	foreach ( $breakdown as $listing_id => $metrics ) {
		++$rank;

		$row  = hpva_breakdown_listing( $listing_id );
		$cell = esc_html( $row['title'] );

		if ( $link_rows && $row['link'] ) {
			$link = hivepress()->router->get_url( 'listing_analytics_page', [ 'listing_id' => $listing_id ] );
			$cell = '<a href="' . esc_url( $link ) . '">' . $cell . '</a>';
		}

		$cell = '<span class="hp-meta hpva-rank">' . esc_html( number_format_i18n( $rank ) ) . '.</span> ' . $cell;

		$html .= '<tr><td class="hpva-table__listing">' . $cell . '</td>';

		foreach ( $columns as $metric => $label ) {
			$html .= '<td data-th="' . esc_attr( $label ) . '">' . esc_html( number_format_i18n( isset( $metrics[ $metric ] ) ? $metrics[ $metric ] : 0 ) ) . '</td>';
		}

		$html .= '</tr>';
	}

	return $html . '</tbody></table>';
}

/**
 * Resolves a breakdown row's listing into a display title and linkability.
 * Aggregate rows outlive their listings by design (only retention pruning
 * deletes them), so a deleted listing must not render as a blank link, and a
 * non-published one has no reachable analytics page to link to.
 *
 * @param int $listing_id Listing ID.
 * @return array{title: string, link: bool}
 */
function hpva_breakdown_listing( $listing_id ) {
	$post = get_post( (int) $listing_id );

	if ( ! $post ) {
		return [
			'title' => __( '(deleted listing)', 'hivepress-vendor-analytics' ),
			'link'  => false,
		];
	}

	$title = get_the_title( $post );

	return [
		'title' => '' !== $title ? $title : __( '(untitled listing)', 'hivepress-vendor-analytics' ),
		'link'  => 'publish' === $post->post_status,
	];
}

/**
 * Gets a human-readable period label with its date range.
 *
 * @param int    $period Period in days (0 for all time).
 * @param string $from   Start date (Y-m-d).
 * @param string $to     End date (Y-m-d).
 * @return string
 */
function hpva_period_label( $period, $from, $to, $is_month = false ) {
	$range = date_i18n( 'j M Y', strtotime( $from . ' UTC' ) ) . ' - ' . date_i18n( 'j M Y', strtotime( $to . ' UTC' ) );

	// The monthly email's report covers a calendar month, and calling that
	// "Last 30 days" is wrong twice over: it is not the last 30 days, and half
	// of them are not 30 days long. Passed in rather than detected from the
	// dates, because a genuine 30-day period viewed on the last day of a
	// 30-day month would look identical to a calendar month and would then be
	// mislabelled the other way.
	if ( $is_month ) {
		/* translators: 1: month and year, 2: date range. */
		return sprintf( __( '%1$s (%2$s)', 'hivepress-vendor-analytics' ), date_i18n( 'F Y', strtotime( $from . ' UTC' ) ), $range );
	}

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
		. '.hpva-sub{margin:0 0 1rem;opacity:.8}'
		. '.hpva-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.75rem;margin:0 0 .5rem}'
		// Same fixed 150px track minimum as the dashboard, and this is the
		// surface that becomes a PDF: a long translated word spilling out of a
		// card cannot be recovered by scrolling sideways on a printed page.
		. '.hpva-card{padding:1rem;border:1px solid rgba(7,36,86,.075);border-radius:3px;background:#fff;display:flex;flex-direction:column;gap:.15rem;overflow-wrap:break-word}'
		. '.hpva-card__value{font-size:1.35em;font-weight:700}'
		. '.hpva-card__label{font-size:.8em;opacity:.7}'
		// Colour named rather than an opacity over the body colour: .55
		// composited to roughly 2.8:1, faint on screen and fainter once a
		// printer has had its way with it. These notes stay visible in the
		// report precisely because nobody can open a disclosure in a PDF, so
		// they need to be readable ink.
		. '.hpva-card__note{font-size:.72em;color:#4a5568}'
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
		. '.hpva-funnel__value{flex:0 0 9rem;font-size:.85em;font-weight:600;text-align:right}'
		. '.hpva-funnel__value small{opacity:.7}'
		. '.hpva-table{width:100%;border-collapse:collapse;margin:0 0 1rem;font-size:.9em}'
		. '.hpva-table th,.hpva-table td{padding:.5rem .6rem;border-bottom:1px solid rgba(0,0,0,.06);text-align:left}'
		. '.hpva-table th{font-size:.8em;text-transform:uppercase;letter-spacing:.03em;opacity:.6}'
		. '.hpva-rank{font-size:.8em;opacity:.6;margin-right:.15em}'
		. '.hpva-report__foot{margin:2.5rem 0 0;padding:1rem 0 0;border-top:1px solid rgba(0,0,0,.08);font-size:.8em;opacity:.6}'
		// Same stacked treatment as the dashboard for phones reading the
		// report; scoped to screen so printing keeps the full table.
		. '@media screen and (max-width:47.99em){'
		. '.hpva-table--wide thead{display:none}'
		. '.hpva-table--wide,.hpva-table--wide tbody,.hpva-table--wide tr{display:block}'
		. '.hpva-table--wide tr{padding:.6rem 0;border-bottom:1px solid rgba(0,0,0,.06)}'
		. '.hpva-table--wide td{display:inline-block;border:0;padding:.1rem 1rem .1rem 0}'
		. '.hpva-table--wide td.hpva-table__listing{display:block;font-weight:600;padding:0 0 .3rem}'
		. '.hpva-table--wide td[data-th]:before{content:attr(data-th) ": ";opacity:.6}'
		. '.hpva-funnel__label{flex-basis:5rem}'
		. '.hpva-funnel__value{flex-basis:6rem}'
		. '.hpva-funnel__value small{display:block}'
		. '}'
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
 * @param int                   $vendor_id  Vendor ID.
 * @param int                   $period     Period in days (0 for all time).
 * @param int                   $listing_id Optional listing scope.
 * @param array{0:string,1:string}|null $range Optional explicit [ from, to ] dates, used by the
 *                                             monthly email so the report covers a calendar month
 *                                             rather than a rolling window.
 * @return string
 */
function hpva_report_html( $vendor_id, $period, $listing_id = 0, $range = null ) {
	list( $from, $to ) = $range ? $range : hpva_range( $period );

	$chart_from = $from;

	if ( 0 === $period ) {
		$year_ago   = gmdate( 'Y-m-d', strtotime( $to . ' UTC' ) - 364 * DAY_IN_SECONDS );
		$chart_from = max( $from, $year_ago );
	}

	$scope      = $listing_id ? $listing_id : null;
	$cur        = hpva_totals_map( $vendor_id, $from, $to, $scope );
	$prev       = [];
	$prev_label = '';

	if ( $range ) {

		// A calendar month must be compared with the whole previous CALENDAR
		// month, not with an equal-length window. hpva_prev_range() counts back
		// the same number of days, so 30-day June would be compared against
		// 2 to 31 May and quietly drop 1 May, while 31-day August would line up
		// with July exactly. That made the arrows right or wrong depending on
		// the length of the month, which is the worst kind of wrong: correct
		// often enough to look fine. Caught on staging, 2026-08-16.
		list( $p_from, $p_to ) = hpva_month_range( gmdate( 'Y-m', strtotime( $from . ' UTC' ) - DAY_IN_SECONDS ) );

		$prev       = hpva_totals_map( $vendor_id, $p_from, $p_to, $scope );
		$prev_label = date_i18n( 'F Y', strtotime( $p_from . ' UTC' ) );
	} elseif ( 0 !== $period ) {
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
	$out .= '<p class="hpva-report__site">' . esc_html( hpva_site_name() ) . '</p>';
	$out .= '<h1 class="hpva-report__title">' . esc_html( $subject ) . ' - ' . esc_html__( 'Analytics report', 'hivepress-vendor-analytics' ) . '</h1>';
	$out .= '<p class="hpva-report__meta">' . esc_html( hpva_period_label( $period, $from, $to, (bool) $range ) );

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
	$refunds  = $m( 'refund_minor' );
	$orders   = $m( 'order' );

	if ( hpva_section_on( 'summary' ) ) {
		$out .= '<h2 class="hpva-h2">' . esc_html__( 'Summary', 'hivepress-vendor-analytics' ) . '</h2>';

		if ( $prev_label ) {
			$out .= '<p class="hpva-sub">' . sprintf(
				/* translators: %s: month and year, e.g. July 2026. */
				esc_html__( 'Changes compare against %s.', 'hivepress-vendor-analytics' ),
				esc_html( $prev_label )
			) . '</p>';
		} elseif ( 0 !== $period ) {
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
			$out .= hpva_card( __( 'Earnings', 'hivepress-vendor-analytics' ), hpva_money( $earnings ), __( 'What you keep after commission, counted when each order is paid. Totals elsewhere on the site may show the full order value, which is higher.', 'hivepress-vendor-analytics' ), $d( 'earning_minor' ) );
			$out .= hpva_card( __( 'Refunded', 'hivepress-vendor-analytics' ), hpva_money( $refunds ), __( 'Your share of anything refunded since', 'hivepress-vendor-analytics' ), $d( 'refund_minor' ), true );
			$out .= hpva_card( __( 'Net earnings', 'hivepress-vendor-analytics' ), hpva_money( max( 0, $earnings - $refunds ) ), __( 'Earnings after refunds', 'hivepress-vendor-analytics' ) );
		}

		if ( ! $listing_id && $resp_n > 0 ) {
			$prev_avg  = ( isset( $prev['response_count'] ) && $prev['response_count'] > 0 ) ? $prev['response_sum'] / $prev['response_count'] : 0;
			$avg_delta = ( 0 !== $period ) ? hpva_delta( $resp_sum / $resp_n, $prev_avg ) : null;
			$out      .= hpva_card( __( 'Avg first response', 'hivepress-vendor-analytics' ), hpva_duration( $resp_sum / $resp_n ), __( 'From a customer\'s first message to your first reply', 'hivepress-vendor-analytics' ), $avg_delta, true );
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

		$out .= '<h2 class="hpva-h2">' . esc_html__( 'Conversion funnel', 'hivepress-vendor-analytics' ) . '</h2>'
			. '<p class="hpva-sub">' . esc_html__( 'How many of the people who saw your listings went on to contact you, and how many of those went on to book.', 'hivepress-vendor-analytics' ) . '</p>';
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

	if ( ! $listing_id && hpva_section_on( 'benchmark' ) ) {
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
			$out .= '<table class="hpva-table"><thead><tr><th>' . esc_html__( 'Term', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Impressions', 'hivepress-vendor-analytics' ) . '</th><th>' . esc_html__( 'Clicks', 'hivepress-vendor-analytics' ) . '</th></tr></thead><tbody>';

			foreach ( $terms as $row ) {
				$out .= '<tr><td>' . esc_html( $row->term ) . '</td><td>' . esc_html( number_format_i18n( (int) $row->impressions ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $row->clicks ) ) . '</td></tr>';
			}

			$out .= '</tbody></table>';
		}
	}

	if ( ! $listing_id && hpva_section_on( 'breakdown' ) ) {
		$breakdown = hpva_listing_breakdown( $vendor_id, $from, $to );

		if ( $breakdown ) {
			$out .= '<h2 class="hpva-h2">' . esc_html__( 'Per-listing breakdown', 'hivepress-vendor-analytics' ) . '</h2>';
			$out .= '<p class="hpva-sub">' . esc_html__( 'Ranked by views for the selected period, best performer first.', 'hivepress-vendor-analytics' ) . '</p>';
			$out .= hpva_breakdown_table( $breakdown, false );
		}
	}

	if ( ! $views && ! $messages ) {
		$out .= '<p class="hpva-empty">' . esc_html__( 'No data recorded for this period yet.', 'hivepress-vendor-analytics' ) . '</p>';
	}

	$out .= '<p class="hpva-report__foot">' . esc_html__( 'Generated by Vendor Analytics Pro for HivePress', 'hivepress-vendor-analytics' ) . ' &middot; ' . esc_html( hpva_site_name() ) . '</p>';

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
	 * Change column as words rather than signed percentages: a leading "+"
	 * or "-" would trip the spreadsheet formula-injection guard, which
	 * prefixes an apostrophe that several spreadsheet apps then display.
	 *
	 * @param string $key Metric key.
	 * @return string
	 */
	$change = function ( $key ) use ( $m, $p, $period ) {
		if ( 0 === $period ) {
			return '';
		}

		$delta = hpva_delta( $m( $key ), $p( $key ) );

		if ( ! $delta ) {
			return '';
		}

		if ( 'up' === $delta['dir'] ) {
			/* translators: %s: percentage. */
			return sprintf( __( 'Up %s', 'hivepress-vendor-analytics' ), ltrim( $delta['text'], '+' ) );
		}

		if ( 'down' === $delta['dir'] ) {
			/* translators: %s: percentage. */
			return sprintf( __( 'Down %s', 'hivepress-vendor-analytics' ), ltrim( $delta['text'], '-' ) );
		}

		return $delta['text'];
	};

	$rows   = [];
	$rows[] = [ hpva_csv_field( __( 'Vendor analytics report', 'hivepress-vendor-analytics' ) ) ];
	$rows[] = [ hpva_csv_field( __( 'Site', 'hivepress-vendor-analytics' ) ), hpva_csv_field( hpva_site_name() ) ];
	$rows[] = [ hpva_csv_field( __( 'Vendor', 'hivepress-vendor-analytics' ) ), hpva_csv_field( hpva_plain_text( get_the_title( $vendor_id ) ) ) ];

	if ( $listing_id ) {
		$rows[] = [ hpva_csv_field( __( 'Listing', 'hivepress-vendor-analytics' ) ), hpva_csv_field( hpva_plain_text( get_the_title( $listing_id ) ) ) ];
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
			$rows[] = [ hpva_csv_field( __( 'Refunded', 'hivepress-vendor-analytics' ) ), hpva_csv_field( hpva_money( $m( 'refund_minor' ) ) ), 0 === $period ? '' : hpva_csv_field( hpva_money( $p( 'refund_minor' ) ) ), hpva_csv_field( $change( 'refund_minor' ) ) ];
			$rows[] = [ hpva_csv_field( __( 'Net earnings', 'hivepress-vendor-analytics' ) ), hpva_csv_field( hpva_money( max( 0, $m( 'earning_minor' ) - $m( 'refund_minor' ) ) ) ), 0 === $period ? '' : hpva_csv_field( hpva_money( max( 0, $p( 'earning_minor' ) - $p( 'refund_minor' ) ) ) ), '' ];
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

			$header_row = [
				hpva_csv_field( __( 'Rank', 'hivepress-vendor-analytics' ) ),
				hpva_csv_field( __( 'Listing', 'hivepress-vendor-analytics' ) ),
			];

			foreach ( hpva_breakdown_columns() as $column_label ) {
				$header_row[] = hpva_csv_field( $column_label );
			}

			$rows[] = $header_row;

			$rank = 0;

			foreach ( $breakdown as $breakdown_listing_id => $metrics ) {
				++$rank;

				$breakdown_row = hpva_breakdown_listing( $breakdown_listing_id );
				$row           = [ $rank, hpva_csv_field( hpva_plain_text( $breakdown_row['title'] ) ) ];

				foreach ( hpva_breakdown_columns() as $metric => $column_label ) {
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
			$rows[] = [ hpva_csv_field( __( 'Term', 'hivepress-vendor-analytics' ) ), hpva_csv_field( __( 'Impressions', 'hivepress-vendor-analytics' ) ), hpva_csv_field( __( 'Clicks', 'hivepress-vendor-analytics' ) ) ];

			foreach ( $terms as $row ) {
				$rows[] = [ hpva_csv_field( $row->term ), (int) $row->impressions, (int) $row->clicks ];
			}
		}
	}

	return $rows;
}

/*
--------------------------------------------------------------------------
Monthly summary email.
--------------------------------------------------------------------------
*/

/**
 * Normalises a month parameter to YYYY-MM, or an empty string when it is not a
 * real month. Rejecting here means every caller downstream can treat the value
 * as trusted.
 *
 * @param mixed $raw Raw month value.
 * @return string
 */
function hpva_sanitise_month( $raw ) {
	$month = is_scalar( $raw ) ? trim( (string) $raw ) : '';

	if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $month, $parts ) ) {
		return '';
	}

	$year  = (int) $parts[1];
	$index = (int) $parts[2];

	if ( $index < 1 || $index > 12 || $year < 2000 || $year > 2100 ) {
		return '';
	}

	return $month;
}

/**
 * Gets the first and last dates of a calendar month.
 *
 * @param string $month Month as YYYY-MM.
 * @return array{0:string,1:string}
 */
function hpva_month_range( $month ) {
	$first = $month . '-01';

	return [ $first, gmdate( 'Y-m-t', strtotime( $first . ' UTC' ) ) ];
}

/**
 * Signs a vendor's monthly report link.
 *
 * Stateless on purpose: nothing is stored, so there is no table to grow, no
 * cleanup to schedule and nothing left behind when the plugin is removed. The
 * signature covers the vendor and the month together, so a recipient cannot
 * edit either one in the URL and reach somebody else's figures or a different
 * period. wp_salt() keys it, which also means regenerating the site's salts
 * invalidates every link ever issued, the emergency revocation should it ever
 * be needed.
 *
 * @param int    $vendor_id Vendor ID.
 * @param string $month Month as YYYY-MM.
 * @return string
 */
function hpva_report_token( $vendor_id, $month ) {
	return hash_hmac( 'sha256', 'hpva|' . (int) $vendor_id . '|' . $month, wp_salt( 'auth' ) );
}

/**
 * Checks a monthly report link.
 *
 * Validity is derived from the month rather than carried in the URL, so there is
 * no expiry parameter for anyone to edit: a link works for 90 days after the
 * month it covers has ended, which is long enough for somebody who reads their
 * email late and short enough that a forwarded link does not live forever.
 *
 * @param mixed  $vendor_id Vendor ID from the request.
 * @param string $month Sanitised month as YYYY-MM.
 * @param mixed  $token Token from the request.
 * @return bool
 */
function hpva_verify_report_token( $vendor_id, $month, $token ) {
	$vendor_id = (int) $vendor_id;

	if ( $vendor_id <= 0 || ! $month || ! is_string( $token ) || ! $token ) {
		return false;
	}

	list( , $last ) = hpva_month_range( $month );

	if ( time() > strtotime( $last . ' 23:59:59 UTC' ) + 90 * DAY_IN_SECONDS ) {
		return false;
	}

	// hash_equals, not a plain comparison, so a wrong token cannot be narrowed
	// down by timing the response.
	return hash_equals( hpva_report_token( $vendor_id, $month ), $token );
}

/**
 * Builds the full report URL for the email button.
 *
 * @param int    $vendor_id Vendor ID.
 * @param string $month Month as YYYY-MM.
 * @return string
 */
function hpva_report_token_url( $vendor_id, $month ) {
	return add_query_arg(
		[
			'month'  => $month,
			'vendor' => (int) $vendor_id,
			'token'  => hpva_report_token( $vendor_id, $month ),
		],
		rest_url( 'hpva/v1/export' )
	);
}

/**
 * Reads a vendor's own monthly email choice, falling back to what the site
 * owner's settings say.
 *
 * The meta is deliberately three-state: '1' and '0' are the vendor's own
 * decision, and no meta at all means they have not chosen one, so the fallback
 * applies. Storing a plain boolean would collapse "no thanks" and "never asked"
 * into the same value, and the fallback would then silently switch an email
 * back on for somebody who had turned it off.
 *
 * The fallback is passed in rather than read from an option here, because the
 * two questions have different answers. Whether to send at all has no separate
 * setting: switching the feature on IS the decision to send, so the fallback is
 * simply true. Quiet months has its own setting, and that value is passed in.
 *
 * @param int    $user_id User ID.
 * @param string $key Meta key.
 * @param bool   $fallback What applies when the vendor has not chosen, or
 *                         cannot.
 * @return bool
 */
function hpva_monthly_choice( $user_id, $key, $fallback ) {

	// With vendor choice switched off, the site owner's settings govern every
	// vendor, including any who had chosen otherwise while the choice was
	// offered. That is exactly what the setting says it does, and a control
	// promising the owner the decision while quietly deferring to old vendor
	// preferences would be the worse kind of wrong. Their stored choice is
	// read past, never deleted, so switching the choice back on restores it.
	if ( ! hpva_get_option( 'vendor_analytics_monthly_vendors', true ) ) {
		return (bool) $fallback;
	}

	$stored = get_user_meta( $user_id, $key, true );

	if ( '' === $stored ) {
		return (bool) $fallback;
	}

	return '1' === $stored;
}

/**
 * Adds the vendor's own monthly email controls to their account settings form.
 *
 * @param array $form Form arguments.
 * @return array
 */
function hpva_add_monthly_fields( $form, $object = null ) {

	// Core applies a parent form's filter to its children too: Form::__construct
	// loops hp\get_class_parents (forms/class-form.php:159), and
	// User_Update_Profile extends User_Update. That child form is the profile
	// STEP of listing submission and vendor registration, so without this guard
	// somebody part-way through submitting a listing is asked whether they want
	// a monthly analytics email. Verified on the dev site, both forms carried
	// the fields. Match one exact class, which is what the framework notes say
	// to do and what this failed to do in 1.8.0.
	if ( is_object( $object ) && 'HivePress\Forms\User_Update' !== get_class( $object ) ) {
		return $form;
	}

	// Two gates, not one: the feature has to be on at all, and the site owner
	// has to be delegating the decision. With vendor choice off there is
	// nothing here for a vendor to change, so showing them a control that does
	// nothing would be worse than showing them none.
	if ( ! hpva_get_option( 'vendor_analytics_monthly', false ) || ! hpva_get_option( 'vendor_analytics_monthly_vendors', true ) ) {
		return $form;
	}

	$user_id = get_current_user_id();

	if ( ! $user_id || ! hpva_vendor_id_from_user( $user_id ) ) {
		return $form;
	}

	$form['fields']['hpva_monthly'] = [
		'label'   => __( 'Monthly analytics email', 'hivepress-vendor-analytics' ),
		'caption' => __( 'Email me a summary of my listings each month', 'hivepress-vendor-analytics' ),
		'type'    => 'checkbox',
		'default' => hpva_monthly_choice( $user_id, '_hpva_monthly', true ),
		'_order'  => 610,
	];

	$form['fields']['hpva_monthly_quiet'] = [
		'label'   => __( 'Quiet months', 'hivepress-vendor-analytics' ),
		'caption' => __( 'Send it even if there was no activity that month', 'hivepress-vendor-analytics' ),
		'type'    => 'checkbox',
		'default' => hpva_monthly_choice( $user_id, '_hpva_monthly_quiet', (bool) hpva_get_option( 'vendor_analytics_monthly_quiet', false ) ),
		'_order'  => 620,
	];

	return $form;
}

/**
 * Holds the pending choice between validation and the model update.
 *
 * @param string|false|null $name Field name to record, false to clear the
 *                                store, or null to read everything back.
 * @param string|null       $value Value to record.
 * @return array<string, string>
 */
function hpva_pending_monthly_choice( $name = null, $value = null ) {
	static $pending = [];

	// false clears the store, so a second models/user/update in the same
	// request cannot replay a write that has already been applied.
	if ( false === $name ) {
		$pending = [];
	} elseif ( null !== $name ) {
		$pending[ $name ] = $value;
	}

	return $pending;
}

/**
 * Records the vendor's choice while the settings form validates.
 *
 * Two traps are avoided here, both of which have shipped as real bugs in these
 * plugins. An unticked checkbox is omitted from the request entirely, and the
 * form layer sets every registered field to null for absent values, so the value
 * alone cannot tell "unticked" from "not on this form": the field has to be
 * detected by PRESENCE and a null then read as false. And the save itself must
 * not happen during validation, because a current-password check runs afterwards
 * and can still reject the whole submission, so the choice is applied from the
 * model update action instead.
 *
 * @param array  $errors Validation errors.
 * @param object $form Form object.
 * @return array
 */
function hpva_capture_monthly_choice( $errors, $form ) {
	if ( ! is_object( $form ) || ! method_exists( $form, 'get_fields' ) ) {
		return $errors;
	}

	$fields = $form->get_fields();

	foreach ( [ 'hpva_monthly', 'hpva_monthly_quiet' ] as $name ) {
		if ( isset( $fields[ $name ] ) ) {
			hpva_pending_monthly_choice( $name, $form->get_value( $name ) ? '1' : '0' );
		}
	}

	return $errors;
}

/**
 * Applies the recorded choice once the user has actually been saved.
 *
 * @param int $user_id User ID.
 */
function hpva_save_monthly_choice( $user_id ) {

	// The fields are only ever added for whoever is logged in, but this action
	// fires with whatever user was saved, and core's REST route lets an
	// edit_users holder update anybody (controllers/class-user.php:640). Their
	// own absent checkboxes would then be stamped onto the target as an
	// explicit '0' - a silent unsubscribe that the admin default can never
	// undo, because '0' is no longer "never chose". There is no case where the
	// target should differ from the actor.
	if ( (int) $user_id !== get_current_user_id() ) {
		return;
	}

	foreach ( hpva_pending_monthly_choice() as $name => $value ) {
		update_user_meta( (int) $user_id, '_' . $name, $value );
	}

	hpva_pending_monthly_choice( false );
}

/**
 * Sends the monthly summaries, on the first of the month.
 *
 * Hooked to HivePress's daily event rather than scheduled separately: core
 * already registers hivepress/v1/events/daily on activation, and there is no
 * monthly interval available to schedule (Scheduler::add_action only understands
 * hourly, twicedaily, daily and weekly). These run on Action Scheduler rather
 * than WP-Cron, so they never appear in WP-Crontrol.
 */
function hpva_send_monthly_summaries() {
	if ( ! hpva_get_option( 'vendor_analytics_monthly', false ) ) {
		return;
	}

	if ( '01' !== current_time( 'd' ) ) {
		return;
	}

	// The month just gone, worked out in the site's own timezone so a site well
	// ahead of UTC does not report on a month it is still in.
	$month = gmdate( 'Y-m', strtotime( current_time( 'Y-m' ) . '-01 UTC' ) - DAY_IN_SECONDS );

	// Guard against a second run for the same month: the daily event can fire
	// more than once when Action Scheduler works through a backlog.
	if ( get_option( 'hpva_monthly_sent' ) === $month ) {
		return;
	}

	update_option( 'hpva_monthly_sent', $month, false );

	list( $from, $to ) = hpva_month_range( $month );

	$vendors = get_posts(
		[
			'post_type'      => 'hp_vendor',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]
	);

	foreach ( $vendors as $vendor_id ) {
		$user_id = (int) get_post_field( 'post_author', $vendor_id );

		if ( ! $user_id ) {
			continue;
		}

		if ( ! hpva_monthly_choice( $user_id, '_hpva_monthly', true ) ) {
			continue;
		}

		hpva_send_monthly_summary( (int) $vendor_id, $user_id, $month, $from, $to );
	}
}

/**
 * Sends one vendor's monthly summary.
 *
 * @param int    $vendor_id Vendor ID.
 * @param int    $user_id User ID.
 * @param string $month Month as YYYY-MM.
 * @param string $from Start date.
 * @param string $to End date.
 * @return bool
 */
function hpva_send_monthly_summary( $vendor_id, $user_id, $month, $from, $to ) {
	$user = get_userdata( $user_id );

	if ( ! $user || ! $user->user_email ) {
		return false;
	}

	$totals = hpva_totals_map( $vendor_id, $from, $to );

	/**
	 * @param string $key Metric key.
	 * @return int
	 */
	$m = function ( $key ) use ( $totals ) {
		return isset( $totals[ $key ] ) ? (int) $totals[ $key ] : 0;
	};

	$activity = $m( 'view' ) + $m( 'vendor_view' ) + $m( 'message' ) + $m( 'booking_confirmed' )
		+ $m( 'order' ) + $m( 'earning_minor' ) + $m( 'phone_click' ) + $m( 'email_click' ) + $m( 'favorite' );

	if ( ! $activity && ! hpva_monthly_choice( $user_id, '_hpva_monthly_quiet', (bool) hpva_get_option( 'vendor_analytics_monthly_quiet', false ) ) ) {
		return false;
	}

	// Instantiated directly rather than through hp\create_class_instance():
	// this file is plain procedural code in the global namespace with no
	// Helpers alias imported, so hp\... would resolve to \hp\... and fatal.
	// The class_exists() guard is safe here because this only ever runs from
	// HivePress's daily event, long after init, so the autoloader running the
	// class's init() cannot trigger the too-early translation notices.
	if ( ! class_exists( '\HivePress\Emails\Hpva_Analytics_Summary' ) ) {
		return false;
	}

	$email = new \HivePress\Emails\Hpva_Analytics_Summary(
		[
			'recipient' => $user->user_email,

			'tokens'    => [
				'user_name'     => $user->display_name,
				'vendor_name'   => get_the_title( $vendor_id ),
				'period'        => date_i18n( 'F Y', strtotime( $from . ' UTC' ) ),
				'listing_views' => number_format_i18n( $m( 'view' ) ),
				'profile_views' => number_format_i18n( $m( 'vendor_view' ) ),
				'messages'      => number_format_i18n( $m( 'message' ) ),
				'bookings'      => number_format_i18n( $m( 'booking_confirmed' ) ),
				'earnings'      => hpva_money( $m( 'earning_minor' ) ),
				'report_url'    => hpva_report_token_url( $vendor_id, $month ),
				'settings_url'  => hivepress()->router->get_url( 'user_edit_settings_page' ),
				'user'          => $user_id,
			],
		]
	);

	return (bool) $email->send();
}

/**
 * Renders inline Markdown from a release body.
 *
 * Everything is escaped FIRST, so no markup in a GitHub release body can reach
 * the page; every tag below is one added here afterwards.
 *
 * @param string $text One line or paragraph of release notes.
 * @return string
 */
function hpva_release_notes_inline( $text ) {
	$text = esc_html( $text );

	// Links. esc_html() has already turned any & into &amp;, so the URL is
	// decoded once before esc_url() sees it, or query strings would break.
	$text = preg_replace_callback(
		'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
		function ( $matches ) {
			return '<a href="' . esc_url( html_entity_decode( $matches[2], ENT_QUOTES ) ) . '">' . $matches[1] . '</a>';
		},
		$text
	);

	$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
	$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );

	return $text;
}

/**
 * Turns a GitHub release body into the HTML the "View version details" popup
 * expects.
 *
 * WordPress renders the `sections` payload as HTML, so escaping the Markdown
 * and running it through wpautop - which is what this did until 1.8.1 - showed
 * readers a literal "## Monthly summary email" and "**bold**" where every other
 * plugin's popup shows headings and bold text. Found on staging 2026-08-16.
 *
 * This handles the subset of Markdown our own release notes actually use:
 * headings, bullet lists, bold, inline code and links. Anything else degrades
 * to plain text, which is the right way for a changelog renderer to fail. A
 * full Markdown parser would be a dependency, and a large one, for a popup.
 *
 * @param string $notes Release body in Markdown.
 * @return string
 */
function hpva_release_notes_html( $notes ) {
	$notes = trim( str_replace( "\r\n", "\n", (string) $notes ) );

	if ( '' === $notes ) {
		return '';
	}

	$lines = explode( "\n", $notes );

	// Sentinel: guarantees the last paragraph or list is closed.
	$lines[] = '';

	$html    = '';
	$para    = [];
	$list    = [];
	$code    = [];
	$in_code = false;

	foreach ( $lines as $line ) {
		$raw  = rtrim( $line );
		$line = trim( $line );

		// Fenced code blocks are handled before anything else, because inside
		// one the leading - and # of a shell command or a diff are not Markdown
		// and must survive untouched. Without this a fence fell through to the
		// paragraph branch and its stray backticks were then paired up by the
		// inline rule, which printed visible junk - and the docblock above
		// promises unsupported syntax degrades to plain text, not to junk.
		if ( 0 === strpos( $line, '```' ) ) {
			if ( $para ) {
				$html .= '<p>' . hpva_release_notes_inline( implode( ' ', $para ) ) . '</p>';
				$para  = [];
			}

			if ( $list ) {
				$html .= '<ul><li>' . implode( '</li><li>', $list ) . '</li></ul>';
				$list  = [];
			}

			if ( $in_code ) {
				$html .= '<pre><code>' . esc_html( implode( "\n", $code ) ) . '</code></pre>';
				$code  = [];
			}

			$in_code = ! $in_code;

			continue;
		}

		if ( $in_code ) {
			$code[] = $raw;

			continue;
		}

		$is_heading = (bool) preg_match( '/^#{1,6}\s+(.+)$/', $line, $heading );
		$is_item    = (bool) preg_match( '/^[-*]\s+(.+)$/', $line, $item );

		// A paragraph ends at a heading, a list item or a blank line.
		if ( $para && ( $is_heading || $is_item || '' === $line ) ) {
			$html .= '<p>' . hpva_release_notes_inline( implode( ' ', $para ) ) . '</p>';
			$para  = [];
		}

		// A list ends at anything that is not another item.
		if ( $list && ! $is_item ) {
			$html .= '<ul><li>' . implode( '</li><li>', $list ) . '</li></ul>';
			$list  = [];
		}

		if ( $is_heading ) {

			// h4 is what WordPress's own plugin popups use for section
			// headings, so this matches the surrounding chrome.
			$html .= '<h4>' . hpva_release_notes_inline( $heading[1] ) . '</h4>';
		} elseif ( $is_item ) {
			$list[] = hpva_release_notes_inline( $item[1] );
		} elseif ( '' !== $line ) {
			$para[] = $line;
		}
	}

	// An unclosed fence still has to emit what it collected.
	if ( $code ) {
		$html .= '<pre><code>' . esc_html( implode( "\n", $code ) ) . '</code></pre>';
	}

	return $html;
}
