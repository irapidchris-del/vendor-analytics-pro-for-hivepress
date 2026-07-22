<?php
/**
 * Listing analytics page template.
 *
 * Extends the core listing manage template (verified: the official Statistics
 * extension's page template extends the same base), which provides the
 * listing manage menu chrome.
 *
 * @package HivePress\Templates
 */

namespace HivePress\Templates;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Listing analytics page template class.
 */
class Listing_Analytics_Page extends Listing_Manage_Page {

	/**
	 * Class constructor.
	 *
	 * @param array<string, mixed> $args Template arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_trees(
			[
				'blocks' => [
					'page_content' => [
						'blocks' => [
							'vendor_analytics' => [
								'type'   => 'vendor_analytics',
								'_order' => 20,
							],
						],
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
