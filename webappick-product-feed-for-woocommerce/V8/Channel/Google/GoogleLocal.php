<?php
/**
 * GoogleLocal — Local product inventory feed for Google.
 *
 * Google Local Inventory Ads channel for store-level inventory data.
 *
 * @package    CTXFeed
 * @subpackage V8/Channel/Google
 * @since      8.0.0
 * @implements CHAN-FRD-4.1
 */

namespace CTXFeed\V8\Channel\Google;

use CTXFeed\V8\Channel\AbstractChannel;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Local Inventory channel.
 *
 * @since 8.0.0
 */
class GoogleLocal extends AbstractChannel {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'google_local';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'Google Local Inventory';
	}

	/**
	 * Get required attributes for Google Local.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return string[] Required attribute names.
	 */
	public function get_required_attributes(): array {
		return array( 'id', 'store_code', 'quantity', 'price' );
	}

	/**
	 * Get default attribute mappings.
	 *
	 * @since 8.0.0
	 *
	 * @return array Default mappings.
	 */
	public function get_default_mappings(): array {
		return array();
	}
}
