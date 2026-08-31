<?php
/**
 * GoogleDSA — Feed for Google Ads dynamic search campaigns.
 *
 * Google Dynamic Search Ads channel for page-level ad targeting.
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
 * Google Dynamic Search Ads channel.
 *
 * @since 8.0.0
 */
class GoogleDSA extends AbstractChannel {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'google_dsa';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'Google Dynamic Search Ads';
	}

	/**
	 * Get required attributes for Google DSA.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return string[] Required attribute names.
	 */
	public function get_required_attributes(): array {
		return array( 'Page URL', 'Custom Label' );
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
