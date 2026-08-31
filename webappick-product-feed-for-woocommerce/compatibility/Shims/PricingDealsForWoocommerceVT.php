<?php
/**
 * Compatibility class for Pricing Deals for WooCommerce (Varktech).
 *
 * @package CTXFeed\Compat\Shims
 * @link    https://wordpress.org/plugins/pricing-deals-for-woocommerce/
 */

namespace CTXFeed\Compat\Shims;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PricingDealsForWoocommerceVT
 *
 * Replays the plugin's active rule set against a feed price, because the
 * discount is otherwise only applied in cart/front-end context.
 *
 * @package CTXFeed\Compat\Shims
 */
class PricingDealsForWoocommerceVT {


	/**
	 * Apply the plugin's enabled pricing deals to a product price.
	 *
	 * @param string|float $price   Current price value.
	 * @param \WC_Product  $product Product object.
	 *
	 * @return string|float
	 */
	public function vt_pricing_deals_discount_price( $price, $product ) {
		if ( 'variation' === $product->get_type() ) {
			$price = $product->get_regular_price();
		}
		if ( class_exists( 'VTPRD_Controller' ) ) {
			global $vtprd_rules_set;
			$vtprd_rules_set = maybe_unserialize( get_option( 'vtprd_rules_set' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Global owned by the Pricing Deals plugin (VTPRD); the name is theirs and priming it is exactly what makes the plugin's own code see the rule set.
			if ( ! empty( $vtprd_rules_set ) && is_array( $vtprd_rules_set ) ) {
				foreach ( $vtprd_rules_set as $vtprd_rule_set ) {
					$status = $vtprd_rule_set->rule_on_off_sw_select;
					if ( 'on' === $status || 'onForever' === $status ) {
						$discount_type = $vtprd_rule_set->rule_deal_info[0]['discount_amt_type'];
						$discount      = (float) $vtprd_rule_set->rule_deal_info[0]['discount_amt_count'];
						if ( 'currency' === $discount_type || 'fixedPrice' === $discount_type ) {
							$price = (float) $price - $discount;
						} elseif ( 'percent' === $discount_type ) {
							$price = (float) $price - ( ( (float) $price * $discount ) / 100 );
						}                   
					}               
				}
			}
		}

		return $price;
	}
}
