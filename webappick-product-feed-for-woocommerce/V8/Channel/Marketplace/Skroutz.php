<?php
/**
 * Skroutz — Pro-only marketplace channel for Skroutz.gr.
 *
 * Stub channel registered via Pro plugin filter on the
 * `ctxfeed_channels_registered` action hook.
 *
 * @package    CTXFeed
 * @subpackage V8/Channel/Marketplace
 * @since      8.0.0
 * @implements CHAN-FRD-4.1
 */

namespace CTXFeed\V8\Channel\Marketplace;

use CTXFeed\V8\Channel\AbstractChannel;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Skroutz Marketplace channel (Pro-only).
 *
 * @since 8.0.0
 */
class Skroutz extends AbstractChannel {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'skroutz';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'Skroutz';
	}

	/**
	 * Get required attributes.
	 *
	 * @since 8.0.0
	 *
	 * @return string[] Required attribute names.
	 */
	public function get_required_attributes(): array {
		return array();
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
