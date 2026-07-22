<?php
/**
 * Vendor analytics controller.
 *
 * Route pattern mirrors the official Statistics extension (verified): a route
 * based on an existing core route, with title / redirect / action callbacks.
 *
 * @package HivePress\Controllers
 */

namespace HivePress\Controllers;

use HivePress\Helpers as hp;
use HivePress\Models;
use HivePress\Blocks;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Vendor analytics controller class.
 */
final class Vendor_Analytics extends Controller {

	/**
	 * Class constructor.
	 *
	 * @param array<string, mixed> $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'routes' => [
					'listing_analytics_page' => [
						'base'     => 'listing_edit_page',
						'path'     => '/analytics',
						'title'    => esc_html__( 'Analytics', 'hivepress-vendor-analytics' ),
						'redirect' => [ $this, 'redirect_listing_analytics_page' ],
						'action'   => [ $this, 'render_listing_analytics_page' ],
					],

					'vendor_analytics_page'  => [
						'base'     => 'user_account_page',
						'path'     => '/analytics',
						'title'    => esc_html__( 'Analytics', 'hivepress-vendor-analytics' ),
						'redirect' => [ $this, 'redirect_vendor_analytics_page' ],
						'action'   => [ $this, 'render_vendor_analytics_page' ],
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * Redirects the listing analytics page when access requirements are not
	 * met. Base route callbacks do not run for child routes, so the listing
	 * must be resolved from the URL parameter here (the same pattern core
	 * uses for listing_renew_page, another listing_edit_page child route).
	 *
	 * @return mixed
	 */
	public function redirect_listing_analytics_page() {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hivepress()->router->get_return_url( 'user_login_page' );
		}

		// Get listing (ownership via the user field).
		$listing = Models\Listing::query()->get_by_id( hivepress()->request->get_param( 'listing_id' ) );

		if ( empty( $listing ) || get_current_user_id() !== $listing->get_user__id() || $listing->get_status() !== 'publish' ) {
			return hivepress()->router->get_url( 'listings_edit_page' );
		}

		// Set request context.
		hivepress()->request->set_context( 'listing', $listing );

		return false;
	}

	/**
	 * Renders the listing analytics page.
	 *
	 * @return string
	 */
	public function render_listing_analytics_page() {
		return ( new Blocks\Template(
			[
				'template' => 'listing_analytics_page',

				'context'  => [
					'listing' => hivepress()->request->get_context( 'listing' ),
				],
			]
		) )->render();
	}

	/**
	 * Redirects the analytics page when access requirements are not met.
	 *
	 * @return mixed
	 */
	public function redirect_vendor_analytics_page() {

		// Check authentication.
		if ( ! is_user_logged_in() ) {
			return hivepress()->router->get_return_url( 'user_login_page' );
		}

		// Check vendor.
		$vendor = Models\Vendor::query()->filter( [ 'user' => get_current_user_id() ] )->get_first();

		if ( empty( $vendor ) ) {
			return hivepress()->router->get_url( 'user_account_page' );
		}

		// Set request context.
		hivepress()->request->set_context( 'vendor', $vendor );

		return false;
	}

	/**
	 * Renders the analytics page.
	 *
	 * @return string
	 */
	public function render_vendor_analytics_page() {
		return ( new Blocks\Template(
			[
				'template' => 'vendor_analytics_page',

				'context'  => [
					'vendor' => hivepress()->request->get_context( 'vendor' ),
				],
			]
		) )->render();
	}
}
