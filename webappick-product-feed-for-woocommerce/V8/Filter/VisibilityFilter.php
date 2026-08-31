<?php
/**
 * VisibilityFilter — Excludes hidden products from the feed.
 *
 * Uses V5-compatible feedrules keys:
 * - `product_visibility`    → when TRUTHY, hidden products are included
 *                             (filter is bypassed). When FALSY, hidden
 *                             products are excluded.
 * - `outofstock_visibility` → when FALSY and the WooCommerce
 *                             "Hide out of stock items" setting is enabled,
 *                             out-of-stock products are also excluded.
 *
 * Mirrors V5 Filter::exclude_hidden_products() + exclude_override_out_of_stock_isibility().
 *
 * @package    CTXFeed
 * @subpackage V8/Filter
 * @since      8.0.0
 */

namespace CTXFeed\V8\Filter;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hidden / out-of-stock visibility filter (V5-compatible).
 *
 * @since 8.0.0
 */
class VisibilityFilter implements FilterInterface {

	/**
	 * Check if a product passes the visibility filter.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return bool True if product passes, false to exclude.
	 */
	public function passes( \WC_Product $product, Config $config ): bool {
		// V5 key: `product_visibility` — TRUE means include hidden products,
		// FALSE (default) means remove hidden products.
		$include_hidden = FilterHelper::bool( $config->get( 'product_visibility', false ) );

		if ( ! $include_hidden ) {
			$visibility = $product->get_catalog_visibility();

			if ( 'hidden' === $visibility ) {
				return false;
			}
		}

		// V5 key: `outofstock_visibility` — when FALSE (override OFF), respect
		// WooCommerce's "Hide out of stock items" catalog setting.
		$override_out_of_stock = FilterHelper::bool( $config->get( 'outofstock_visibility', false ) );

		if ( ! $override_out_of_stock
			&& 'outofstock' === $product->get_stock_status()
			&& 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' )
		) {
			return false;
		}

		return true;
	}
}
