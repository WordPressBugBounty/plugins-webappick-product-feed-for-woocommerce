<?php
/**
 * Compatibility class for RP_WCDPDCompatibility plugin
 *
 * @package CTXFeed\Compat\Shims
 */

namespace CTXFeed\Compat\Shims;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CTXFeed\Compat\Support\ProductHelper;
use CTXFeed\Compat\Support\PriceStage;

/**
 * Class RP_WCDPDCompatibility
 *
 * PLUGIN: WooCommerce Dynamic Pricing & Discounts
 * URL: https://codecanyon.net/item/woocommerce-dynamic-pricing-discounts/7119279
 *
 * @package CTXFeed\Compat\Shims
 */
class RP_WCDPDCompatibility {

	/**
	 * RP_WCDPDCompatibility Constructor.
	 */
	public function __construct() {
		// Get price with discount.
		// Discount stage of the price pipeline (CBT-795): after the bundle /
		// composite models, before currency conversion.
		add_filter( 'woo_feed_filter_product_regular_price', array( $this, 'get_discounted_price' ), PriceStage::DISCOUNT, 5 );
		add_filter( 'woo_feed_filter_product_price', array( $this, 'get_discounted_price' ), PriceStage::DISCOUNT, 5 );
		add_filter( 'woo_feed_filter_product_sale_price', array( $this, 'get_discounted_price' ), PriceStage::DISCOUNT, 5 );
		add_filter( 'woo_feed_filter_product_regular_price_with_tax', array( $this, 'get_discounted_price' ), PriceStage::DISCOUNT, 5 );
		add_filter( 'woo_feed_filter_product_price_with_tax', array( $this, 'get_discounted_price' ), PriceStage::DISCOUNT, 5 );
		add_filter( 'woo_feed_filter_product_sale_price_with_tax', array( $this, 'get_discounted_price' ), PriceStage::DISCOUNT, 5 );

		add_filter( 'rightpress_product_price_shop_calculate_by_price_test', '__return_false', 9999 );
	}

	/**
	 * Get Discounted Price
	 *
	 * @param int         $price product price.
	 * @param \WC_Product $product product object.
	 * @param object      $config config object.
	 * @param bool        $with_tax price with tax or without tax.
	 * @param string      $price_type price type regular_price, price, sale_price.
	 *
	 * @return int
	 */
	public function get_discounted_price( $price, $product, $config, $with_tax, $price_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the 5-argument arity registered with add_filter() must be preserved even though this adapter does not read every argument.

		// Guard against a breaking plugin update: if RightPress's price API is
		// gone, return the price untouched instead of fataling.
		if ( ! class_exists( '\\RightPress_Product_Price_Shop' ) ) {
			return $price;
		}
		$discount = new \RightPress_Product_Price_Shop();
		if ( ! method_exists( $discount, 'get_price_for_product' ) ) {
			return $price;
		}

		// On a tax-inclusive row the incoming value already carries tax; ask
		// RightPress about the product's own tax-exclusive price of that type
		// and add tax to its answer only — it used to be handed the taxed
		// price and taxed again (CBT-795).
		$base = $price;
		if ( $with_tax && $product instanceof \WC_Product ) {
			if ( 'regular_price' === $price_type ) {
				$base = $product->get_regular_price();
			} elseif ( 'sale_price' === $price_type ) {
				$base = $product->get_sale_price();
			} else {
				$base = $product->get_price();
			}
		}

		$adjusted = $discount->get_price_for_product( $base, $price_type, $product );

		if ( ! $with_tax ) {
			return $adjusted;
		}
		if ( '' === $adjusted || null === $adjusted || ! is_numeric( $adjusted ) || ( is_numeric( $base ) && abs( (float) $adjusted - (float) $base ) < 0.005 ) ) {
			return $price; // No adjustment: the taxed incoming value stands.
		}

		return ProductHelper::get_price_with_tax( $adjusted, $product );
	}
}
