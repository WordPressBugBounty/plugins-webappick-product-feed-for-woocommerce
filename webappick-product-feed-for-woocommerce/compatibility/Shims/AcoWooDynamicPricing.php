<?php
/**
 * Compatibility class for Dynamic Pricing With Discount Rules for WooCommerce.
 *
 * @package CTXFeed\Compat\Shims
 * @link    https://wordpress.org/plugins/aco-woo-dynamic-pricing/
 */

namespace CTXFeed\Compat\Shims;

use AWDP_Discount;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AcoWooDynamicPricing
 *
 * Resolves the discounted price the plugin only applies on the front end.
 *
 * @package CTXFeed\Compat\Shims
 */
class AcoWooDynamicPricing {

	/**
	 * Resolve the ACO dynamic price for a product.
	 *
	 * @param string|float $price   Current price value.
	 * @param \WC_Product  $product Product object.
	 *
	 * @return string|float
	 */
	public function aco_dynamic_pricing( $price, $product ) {
		/**
		 * PLUGIN: Dynamic Pricing With Discount Rules for WooCommerce
		 * URL: https://wordpress.org/plugins/aco-woo-dynamic-pricing/
		 *
		 * This plugin does not apply discount on product page.
		 *
		 * Don't apply discount manually.
		 */
		if ( class_exists( 'AWDP_Discount' ) ) {

			$price = AWDP_Discount::instance()->wdpWCPAPrice( $product->get_price(), $product );
			if ( isset( $price['price'] ) ) {
				// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- AWDP_Discount returns '', null or a numeric price; the loose compare is the documented "no discount" test and tightening it would change which branch runs.
				if ( '' == $price['price'] ) {
					$sale_price = $price['originalPrice'];
				} else {
					$sale_price = $price['price'];
				}
				$price = $sale_price;
			}
		}
		return $price;
	}
}
