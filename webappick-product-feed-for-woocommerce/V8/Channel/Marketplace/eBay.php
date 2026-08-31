<?php
/**
 * Pro-only eBay marketplace channel.
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

// phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital -- "eBay" is the merchant's own brand casing and doubles as the PSR-4 file name (eBay.php) that the V8 autoloader resolves; renaming the class would break channel registration for existing Pro installs.
/**
 * Marketplace channel for eBay (Pro-only).
 *
 * @since 8.0.0
 */
class eBay extends AbstractChannel {
	// phpcs:enable PEAR.NamingConventions.ValidClassName.StartWithCapital

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'ebay';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'eBay';
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
