<?php
/**
 * Vendor analytics summary block.
 *
 * Compact per-listing summary injected above the official Statistics
 * extension's Google Analytics chart (that page sets a 'listing' template
 * context - verified in its controller).
 *
 * @package HivePress\Blocks
 */

namespace HivePress\Blocks;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Vendor analytics summary block class.
 */
class Vendor_Analytics_Summary extends Block {

	/**
	 * Renders block HTML.
	 *
	 * @return string
	 */
	public function render() {
		$listing = $this->get_context( 'listing' );

		if ( hp\is_class_instance( $listing, '\HivePress\Models\Listing' ) ) {
			return hpva_render_listing_summary( $listing );
		}

		return '';
	}
}
