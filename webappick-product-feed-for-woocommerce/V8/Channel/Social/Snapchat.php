<?php
/**
 * Snapchat — Product catalog feed for Snapchat Dynamic Ads.
 *
 * Snapchat Product Catalog channel for dynamic product ads.
 *
 * @package    CTXFeed
 * @subpackage V8/Channel/Social
 * @since      8.0.0
 * @implements CHAN-FRD-4.1
 */

namespace CTXFeed\V8\Channel\Social;

use CTXFeed\V8\Channel\AbstractChannel;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Snapchat Catalog channel.
 *
 * @since 8.0.0
 */
class Snapchat extends AbstractChannel {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'snapchat';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'Snapchat Catalog';
	}

	/**
	 * Get required attributes for Snapchat.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return string[] Required attribute names.
	 */
	public function get_required_attributes(): array {
		return array( 'id', 'title', 'description', 'link', 'image_link', 'price', 'availability', 'brand' );
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
