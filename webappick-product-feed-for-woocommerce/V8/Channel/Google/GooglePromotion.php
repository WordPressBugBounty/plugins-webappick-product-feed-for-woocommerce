<?php
/**
 * GooglePromotion — Merchant promotion feed for Google Shopping.
 *
 * Google Promotions channel for merchant promotion data.
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
 * Google Promotions channel.
 *
 * @since 8.0.0
 */
class GooglePromotion extends AbstractChannel {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'google_promotion';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'Google Promotions';
	}

	/**
	 * Get required attributes for Google Promotions.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return string[] Required attribute names.
	 */
	public function get_required_attributes(): array {
		return array( 'promotion_id', 'product_applicability', 'offer_type', 'long_title', 'promotion_effective_dates' );
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
