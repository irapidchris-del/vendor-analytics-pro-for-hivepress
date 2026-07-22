<?php
/**
 * Vendor analytics block.
 *
 * Renders the vendor-wide dashboard, or the full per-listing dashboard when a
 * 'listing' template context is present (our listing Analytics tab).
 *
 * @package HivePress\Blocks
 */

namespace HivePress\Blocks;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Vendor analytics block class.
 */
class Vendor_Analytics extends Block {

	/**
	 * Renders block HTML.
	 *
	 * @return string
	 */
	public function render() {
		$listing = $this->get_context( 'listing' );

		if ( hp\is_class_instance( $listing, '\HivePress\Models\Listing' ) ) {
			return hpva_render_listing_dashboard( $listing );
		}

		return hpva_render_dashboard();
	}
}
