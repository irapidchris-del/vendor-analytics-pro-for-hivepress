<?php
/**
 * Monthly analytics summary email.
 *
 * Registered by HivePress itself: core globs includes/emails/*.php across every
 * registered extension and derives the email name from the file name, so this
 * file becomes the email "hpva_analytics_summary" (class-core.php:443-464).
 * Giving the class a truthy `label` is what makes it editable by the site owner
 * under HivePress > Emails, because the Email component then looks for an
 * hp_email post whose slug matches that name and swaps in its subject and body
 * (components/class-email.php:59-91).
 *
 * The class name carries the Hpva prefix deliberately. Core instantiates
 * \HivePress\Emails\{Filename} across all extensions and the autoloader loads
 * exactly one file per class name, so a second extension shipping
 * class-analytics-summary.php would silently stop one of them loading.
 *
 * @package HivePress\Vendor_Analytics
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Monthly analytics summary email class.
 *
 * @class Hpva_Analytics_Summary
 */
class Hpva_Analytics_Summary extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				/* translators: %s: recipient. */
				'label'       => sprintf( esc_html__( 'Monthly Analytics Summary (%s)', 'hivepress-vendor-analytics' ), hivepress()->translator->get_string( 'vendor' ) ),
				'description' => esc_html__( 'This email is sent on the first of each month to vendors who have chosen to receive it, summarising the month just gone.', 'hivepress-vendor-analytics' ),
				'recipient'   => hivepress()->translator->get_string( 'vendor' ),

				'tokens'      => [
					'user_name',
					'vendor_name',
					'period',
					'listing_views',
					'profile_views',
					'messages',
					'bookings',
					'earnings',
					'report_url',
					'settings_url',
					'user',
				],
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Class constructor.
	 *
	 * @param array $args Email arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'subject' => esc_html__( 'Your listings last month', 'hivepress-vendor-analytics' ),

				// Email::boot() runs make_clickable() over the body, so the bare
				// report URL becomes a link without any markup here.
				'body'    => esc_html__( 'Hi, %user_name%! Here is how your listings did in %period%.', 'hivepress-vendor-analytics' ) . "\n\n"
					. esc_html__( 'Listing views: %listing_views%', 'hivepress-vendor-analytics' ) . "\n"
					. esc_html__( 'Profile views: %profile_views%', 'hivepress-vendor-analytics' ) . "\n"
					. esc_html__( 'Messages received: %messages%', 'hivepress-vendor-analytics' ) . "\n"
					. esc_html__( 'Bookings confirmed: %bookings%', 'hivepress-vendor-analytics' ) . "\n"
					. esc_html__( 'Earnings: %earnings%', 'hivepress-vendor-analytics' ) . "\n\n"
					. esc_html__( 'Open your full report for the month, including your best performing listings and the searches that found you: %report_url%', 'hivepress-vendor-analytics' ) . "\n\n"
					. esc_html__( 'To stop receiving this email, turn it off in your account settings: %settings_url%', 'hivepress-vendor-analytics' ),
			],
			$args
		);

		parent::__construct( $args );
	}
}
