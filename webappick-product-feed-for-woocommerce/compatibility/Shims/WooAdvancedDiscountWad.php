<?php
/**
 * Compatibility class for Conditional Discounts for WooCommerce (WAD).
 *
 * @package CTXFeed\Compat\Shims
 * @link    https://wordpress.org/plugins/woo-advanced-discounts/
 */

namespace CTXFeed\Compat\Shims;

use WAD_Discount;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WooAdvancedDiscountWad
 *
 * @package CTXFeed\Compat\Shims
 */
class WooAdvancedDiscountWad {


	/**
	 * Subtract the plugin's active product discounts from a feed price.
	 *
	 * @param string|float $price   Current price value.
	 * @param \WC_Product  $product Product object.
	 *
	 * @return string|float
	 */
	public function wad_discount_price( $price, $product ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.

		// Guard against a breaking plugin update: if the WAD API this shim reads
		// is gone, return the price untouched instead of fataling.
		if ( ! function_exists( 'wad_get_active_discounts' ) || ! class_exists( 'WAD_Discount' ) ) {
			return $price;
		}
		$wad_discounts   = wad_get_active_discounts( true );
		$discount_amount = 0;

		if ( isset( $wad_discounts['product'] ) ) {
			// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Kept from the V5 original: records that seeding $price from the product was tried here and deliberately dropped in favour of the caller-supplied value.
			// $price = $product->get_price();
			foreach ( $wad_discounts['product'] as $discount_id ) {

				$wad_obj    = new WAD_Discount( $discount_id );
				$is_disable = $wad_obj->settings['disable-on-product-pages'];
				if ( 'no' === $is_disable ) {

					// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- The call is kept for its side effect of priming WAD_Discount::$products_list; the value is only read by the disabled block below.
					$discount_products_list = $wad_obj->products_list->get_products( true );
					// phpcs:disable Squiz.Commenting.BlockComment, Squiz.PHP.CommentedOutCode -- Kept from the V5 original: the per-product / is_applicable() discount paths this shim replaced. Retained verbatim as the reference for how WAD computes fixed vs percentage amounts.
					/*
					if ( is_array( $discount_products_list ) && count( $discount_products_list ) > 0 ) {
						if (in_array($product->get_id(), $discount_products_list)) {

							if ( isset($wad_obj->settings ) ) {
								$settings = $wad_obj->settings;
								$discount_type = $wad_obj->settings['action'];

								if ( false !== strpos( $discount_type, 'fixed' ) ) {
									$discount_amount = (float)$wad_obj->get_discount_amount( $price );
								} elseif (false !== strpos($discount_type, 'percentage')) {
									$percentage = $settings['percentage-or-fixed-amount'];
									$discount_amount = ($price * ($percentage / 100));
								}
							}

						}
					}
					else {
						if ( $wad_obj->is_applicable( $product->get_id() ) ) {
							if (isset($wad_obj->settings)) {
								$settings = $wad_obj->settings;
								$discount_type = $wad_obj->settings['action'];

								if (false !== strpos($discount_type, 'fixed')) {
									$discount_amount = (float)$wad_obj->get_discount_amount($price);
								} elseif (false !== strpos($discount_type, 'percentage')) {
									$percentage = $settings['percentage-or-fixed-amount'];
									$discount_amount = ($price * ($percentage / 100));
								}
							}
						}
					}*/
					// phpcs:enable Squiz.Commenting.BlockComment, Squiz.PHP.CommentedOutCode
					$price = (float) $price - (float) $discount_amount;
				}
			}
			// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Kept from the V5 original: records the alternative "recompute from the product price" ending that was rejected because it discards upstream filters.
			// $price = (float) $product->get_price() - (float) $discount_amount;
		}
		return $price;
	}
}
