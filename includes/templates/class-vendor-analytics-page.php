<?php
/**
 * Vendor analytics page template.
 *
 * Extends the core account page template (verified: User_Account_Page
 * provides the account sidebar chrome and an empty page_content container).
 *
 * @package HivePress\Templates
 */

namespace HivePress\Templates;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Vendor analytics page template class.
 */
class Vendor_Analytics_Page extends User_Account_Page {

	/**
	 * Class constructor.
	 *
	 * @param array<string, mixed> $args Template arguments.
	 */
	public function __construct( $args = [] ) {
		// merge_trees, not merge_blocks, is correct here. A template subclass
		// declares its tree before the parent constructor contributes the
		// account-page chrome, so 'page_content' does not exist in $args yet -
		// merge_blocks would find no match and silently discard the block.
		// merge_blocks is for the template filters, where the tree is complete.
		$args = hp\merge_trees(
			[
				'blocks' => [
					'page_content' => [
						'blocks' => [
							'vendor_analytics' => [
								'type'   => 'vendor_analytics',
								'_order' => 10,
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
