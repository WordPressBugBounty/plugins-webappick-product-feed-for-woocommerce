<?php
/**
 * Compatibility class for Easy WooCommerce Discounts.
 *
 * @package CTXFeed\Compat\Shims
 * @link    https://wordpress.org/plugins/easy-woocommerce-discounts/
 */

namespace CTXFeed\Compat\Shims;

use WCCS_Pricing;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EasyWoocommerceDiscounts
 *
 * Replays the plugin's enabled pricing rules onto a feed price, because the
 * discount is otherwise only applied in cart/front-end context.
 *
 * @package CTXFeed\Compat\Shims
 */
class EasyWoocommerceDiscounts {


	/**
	 * Apply the plugin's active pricing rules to a product price.
	 *
	 * @param string|float $price   Current price value.
	 * @param \WC_Product  $product Product object.
	 *
	 * @return string|float
	 */
	public function easy_woocommerce_discounts_price( $price, $product ) {

		// Guard against a breaking plugin update: if the pricing API this shim
		// replays is gone, return the price untouched instead of fataling.
		if ( ! function_exists( 'WCCS' ) || ! class_exists( 'WCCS_Pricing' ) ) {
			return $price;
		}
		$wccs = WCCS();
		if ( ! is_object( $wccs ) || ! isset( $wccs->WCCS_Conditions_Provider ) || ! method_exists( $wccs->WCCS_Conditions_Provider, 'get_pricings' ) ) {
			return $price;
		}
		$pricing = new WCCS_Pricing(
			$wccs->WCCS_Conditions_Provider->get_pricings( array( 'status' => 1 ) )
		);
		if ( ! method_exists( $pricing, 'get_all_pricing_rules' ) ) {
			return $price;
		}
		$pricing_rules = $pricing->get_all_pricing_rules();

		if ( count( $pricing_rules ) > 0 ) {
			foreach ( $pricing_rules as $key => $value ) {
				$discount_type = $pricing_rules[ $key ]->discount_type;
				if ( isset( $pricing_rules[ $key ]->discount ) ) {
					$discount = (float) $pricing_rules[ $key ]->discount;
				} else {
					$discount = '';
				}
				// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- $price arrives from the feed filter chain as '' | 0 | '0.00' | float; the loose test is the shim's "no price yet" check and tightening it would skip the fallback.
				if ( '' == $price ) {
					$price = (float) $product->get_price();
				}

				$product_discounts_type = $pricing_rules[ $key ]->items[0]['item'];
				$with_products          = $pricing_rules[ $key ]->items[0]['products'];
				if ( is_numeric( $discount ) && $discount > 0 ) {
					if ( 'all_products' === $product_discounts_type ) {
						if ( 'percentage_discount' === $discount_type ) {
							$price = $price - ( ( $price * $discount ) / 100 );
						} elseif ( 'price_discount' === $discount_type ) {
							$price = $price - $discount;
						}
					} elseif ( 'products_in_list' === $product_discounts_type ) {

						if ( is_array( $with_products ) && count( $with_products ) > 0 ) {

							// phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- The plugin stores its product lists as strings while WC_Product::get_id() returns an int; a strict compare would never match.
							if ( in_array( $product->get_id(), $with_products ) ) {
								if ( 'percentage_discount' === $discount_type ) {
									$price = $price - ( ( $price * $discount ) / 100 );
								} elseif ( 'price_discount' === $discount_type ) {
									$price = $price - $discount;
								}
							}                       
						}
					} elseif ( 'products_not_in_list' === $product_discounts_type ) {
						// phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- The plugin stores its product lists as strings while WC_Product::get_id() returns an int; a strict compare would never match.
						if ( ! in_array( $product->get_id(), $with_products ) ) {
							if ( 'percentage_discount' === $discount_type ) {
								$price = $price - ( ( $price * $discount ) / 100 );
							} elseif ( 'price_discount' === $discount_type ) {
								$price = $price - $discount;
							}
						}
					}
				}
			}
		}

		return $price;
	}
}
