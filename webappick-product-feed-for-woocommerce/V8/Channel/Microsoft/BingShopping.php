<?php
/**
 * BingShopping — Microsoft Merchant Center product feed.
 *
 * Extends GoogleShopping since Microsoft accepts the same product
 * data specification. Only overrides channel identity and spec URL.
 *
 * @package    CTXFeed
 * @subpackage V8/Channel/Microsoft
 * @since      8.0.0
 * @implements CHAN-FRD-4.1
 */

namespace CTXFeed\V8\Channel\Microsoft;

use CTXFeed\V8\Channel\Google\GoogleShopping;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bing Shopping channel (Microsoft Merchant Center).
 *
 * Inherits required/optional attributes and default mappings from
 * GoogleShopping — AD-CHAN-003 inheritance for similar channels.
 *
 * @since 8.0.0
 */
class BingShopping extends GoogleShopping {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'bing_shopping';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'Bing Shopping (Microsoft)';
	}

	/**
	 * Get Microsoft Merchant Center specification URL.
	 *
	 * @since 8.0.0
	 *
	 * @return string Specification URL.
	 */
	public function get_spec_url(): string {
		return 'https://help.ads.microsoft.com/apex/index/3/en/51084';
	}
}
