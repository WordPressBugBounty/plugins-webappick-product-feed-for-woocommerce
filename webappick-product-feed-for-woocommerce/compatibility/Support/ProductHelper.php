<?php
/**
 * ProductHelper — product price/meta helpers for the compat layer.
 *
 * Native (V5-free) replacement for the `CTXFeed\V5\Helper\ProductHelper`
 * methods the rewritten shims depend on: `get_price_with_tax()` and
 * `get_product_meta()`.
 *
 * @package CTXFeed\Compat\Support
 * @since   8.0.0
 */

namespace CTXFeed\Compat\Support;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product helper.
 *
 * @since 8.0.0
 */
class ProductHelper {

	/**
	 * Return a price with tax included.
	 *
	 * Mirrors `V5\Helper\ProductHelper::get_price_with_tax()` (WooCommerce 3.0+
	 * path — the plugin's minimum supported WC is 3.2).
	 *
	 * @since 8.0.0
	 *
	 * @param float|string $price   Base price.
	 * @param \WC_Product  $product WooCommerce product.
	 * @return float|string
	 */
	public static function get_price_with_tax( $price, $product ) {
		if ( function_exists( 'wc_get_price_including_tax' ) ) {
			return wc_get_price_including_tax( $product, array( 'price' => $price ) );
		}

		return $price;
	}

	/**
	 * Read a product meta value with variation-parent fallback.
	 *
	 * Mirrors `V5\Helper\ProductHelper::get_product_meta()` for the callers the
	 * shims use (WC Subscriptions, Germanized). The V5 taxonomy-prefix branch is
	 * intentionally omitted — none of those callers pass a taxonomy-prefixed key.
	 *
	 * @since 8.0.0
	 *
	 * @param string      $meta    Meta key.
	 * @param \WC_Product $product WooCommerce product.
	 * @param mixed       $config  Feed configuration (passed through to filters).
	 * @return mixed
	 */
	public static function get_product_meta( $meta, $product, $config = null ) {
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		$value = get_post_meta( $product->get_id(), $meta, true );

		// Fall back to the parent product for variations with empty meta.
		if ( empty( $value ) && $product->is_type( 'variation' ) ) {
			$value = get_post_meta( $product->get_parent_id(), $meta, true );
		}

		// RankMath primary-category term-ID → name conversion via filter.
		if ( 'rank_math_primary_product_cat' === $meta && is_numeric( $value ) ) {
			$value = apply_filters( 'woo_feed_filter_rank_math_primary_category', $value, $product, $config );
		}

		return apply_filters( 'woo_feed_filter_product_meta', $value, $product, $config );
	}
}
