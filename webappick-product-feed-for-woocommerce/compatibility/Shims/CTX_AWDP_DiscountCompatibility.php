<?php
/**
 * Compatibility class for CTX_AWDP_DiscountCompatibility plugin
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
 * Class CTX_AWDP_DiscountCompatibility
 *
 * PLUGIN: Dynamic Pricing With Discount Rules for WooCommerce
 * URL: https://wordpress.org/plugins/aco-woo-dynamic-pricing/
 *
 * @package CTXFeed\Compat\Shims
 */
class CTX_AWDP_DiscountCompatibility {

	/**
	 * CTX_AWDP_DiscountCompatibility Constructor.
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
	public function get_discounted_price( $price, $product, $config, $with_tax, $price_type ) {
		// Price as a guest: an empty cart, never the runner's (an admin's
		// cart in admin-ajax background runs) — cart conditions must not
		// reach the feed (CBT-823). Older free without the helper: as before.
		$compute = function () use ( $price, $product, $config, $with_tax, $price_type ) {
			return $this->compute_discounted_price( $price, $product, $config, $with_tax, $price_type );
		};
		if ( class_exists( '\\CTXFeed\\V8\\Utility\\GuestCart' ) && is_callable( array( '\\CTXFeed\\V8\\Utility\\GuestCart', 'run' ) ) ) {
			return \CTXFeed\V8\Utility\GuestCart::run( $compute );
		}
		return $compute();
	}

	/**
	 * Discounted price computation (see get_discounted_price()).
	 *
	 * @since 8.0.40
	 *
	 * @param mixed       $price      Incoming price.
	 * @param \WC_Product $product    Product.
	 * @param object      $config     Feed config.
	 * @param bool        $with_tax   Tax-inclusive row.
	 * @param string      $price_type Price type.
	 * @return mixed
	 */
	private function compute_discounted_price( $price, $product, $config, $with_tax, $price_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the 5-argument arity registered with add_filter() must be preserved even though this adapter does not read every argument.
		// The regular price is never Acowebs' business: its `originalPrice` is
		// the price BEFORE its discount — for a product on sale that is the
		// sale price, so the regular row shipped the sale price (20 → 18,
		// CBT-795). The bridge already carries the product's regular price.
		if ( 'regular_price' === $price_type || ! class_exists( 'AWDP_Discount' ) ) {
			return $price;
		}

		$prices = \AWDP_Discount::instance()->wdpWCPAPrice( $price, $product );
		if ( ! is_array( $prices ) || empty( $prices['price'] ) || ! is_numeric( $prices['price'] ) ) {
			// No discount for this product: the incoming value stands as-is —
			// on a tax-inclusive row it ALREADY carries tax (adding it again
			// taxed every undiscounted product twice, CBT-795).
			return $price;
		}

		// Acowebs computes from the product's own (tax-exclusive) price.
		$price = $prices['price'];
		if ( $with_tax ) {
			$price = ProductHelper::get_price_with_tax( $price, $product );
		}

		return $price;
	}
}
