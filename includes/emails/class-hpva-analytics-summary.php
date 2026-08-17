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
				'description' => esc_html__( 'This email is sent on the first of each month, summarising the month just gone. Who receives it is set under HivePress > Settings > Analytics.', 'hivepress-vendor-analytics' ),
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

		// With vendor choice switched off the two controls are removed from the
		// account settings page, so telling a vendor to turn the email off
		// there is an instruction they cannot carry out. That configuration is
		// the only one in which the Vendor choice setting does anything, so
		// getting this wrong would be wrong in every email the setting exists
		// to enable. Read at send time, so it always reflects the current
		// setting. Note the leading backslash: this file is in the
		// HivePress\Emails namespace and hpva_get_option() is global.
		$footer = ( ! function_exists( 'hpva_get_option' ) || \hpva_get_option( 'vendor_analytics_monthly_vendors', true ) )
			? esc_html__( 'To stop receiving this email, turn it off in your account settings: %settings_url%', 'hivepress-vendor-analytics' )
			: esc_html__( 'This email is sent by the site owner. Contact them if you would rather not receive it.', 'hivepress-vendor-analytics' );

		$args = hp\merge_arrays(
			[
				'subject' => esc_html__( 'Your listings last month', 'hivepress-vendor-analytics' ),

				// This body is written as real HTML, unlike every core HivePress
				// email, and both reasons are the same one: it is the first
				// multi-line body on the platform. Core's are all a single
				// sentence, so bare newlines never mattered there; the email is
				// sent as text/html, and in an HTML client a newline is
				// whitespace, so this list of figures collapsed into one run-on
				// paragraph - the whole point of the email, read as prose.
				// Found on staging, 2026-08-16.
				//
				// The report link is an anchor for the same family of reason:
				// core's emails link to short readable page URLs that sit fine
				// inline, whereas this is a signed link over 150 characters and
				// pasted raw it looks like spam. Core echoes the body unescaped
				// (templates/email/email-content.php) so the markup renders, and
				// make_clickable() leaves URLs already inside an anchor alone.
				//
				// Tags are kept OUT of the translatable strings so translators
				// never have to carry markup. Note an admin who edits this email
				// replaces the body wholesale, and their version is run through
				// the_content, so it gets its paragraphs from wpautop instead.
				'body'    => '<p>' . esc_html__( 'Hi, %user_name%! Here is how your listings did in %period%.', 'hivepress-vendor-analytics' ) . '</p>'
					. '<p>'
					. esc_html__( 'Listing views: %listing_views%', 'hivepress-vendor-analytics' ) . '<br>'
					. esc_html__( 'Profile views: %profile_views%', 'hivepress-vendor-analytics' ) . '<br>'
					. esc_html__( 'Messages received: %messages%', 'hivepress-vendor-analytics' ) . '<br>'
					. esc_html__( 'Bookings confirmed: %bookings%', 'hivepress-vendor-analytics' ) . '<br>'
					. esc_html__( 'Earnings: %earnings%', 'hivepress-vendor-analytics' )
					. '</p>'
					. '<p>' . esc_html__( 'Your full report for the month shows your best performing listings and the searches that found you.', 'hivepress-vendor-analytics' ) . '</p>'
					. '<p><a href="%report_url%" style="display:inline-block;padding:12px 22px;background:#4a5568;color:#ffffff;text-decoration:none;border-radius:3px;font-weight:600">'
					. esc_html__( 'View my full report', 'hivepress-vendor-analytics' )
					. '</a></p>'
					. '<p>' . $footer . '</p>',
			],
			$args
		);

		parent::__construct( $args );
	}
}
