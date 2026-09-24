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
 * Free runs the V5-free defaults; the two Filters-tab opt-ins are Pro and
 * arrive through `ctxfeed_visibility_filter_options` (CBT-650).
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
		/**
		 * Filter the two Pro toggles of this filter (CBT-650).
		 *
		 * Free defaults are V5-free behaviour: hidden products are removed and
		 * WooCommerce's "Hide out of stock items" setting is respected. The
		 * Filters tab locks both opt-ins (`product_visibility` = include
		 * hidden, `outofstock_visibility` = override the store setting) behind
		 * Pro, so the saved keys are read by the Pro engine
		 * (`Pro\Engine\Filter\VisibilityOverrides`), never here.
		 *
		 * @since 8.0.27
		 *
		 * @param array  $options { include_hidden: bool, override_out_of_stock: bool }.
		 * @param Config $config  Feed configuration.
		 */
		$options = (array) apply_filters(
			'ctxfeed_visibility_filter_options',
			array(
				'include_hidden'        => false,
				'override_out_of_stock' => false,
			),
			$config
		);

		$include_hidden = ! empty( $options['include_hidden'] );

		if ( ! $include_hidden ) {
			$visibility = $product->get_catalog_visibility();

			if ( 'hidden' === $visibility ) {
				return false;
			}
		}

		$override_out_of_stock = ! empty( $options['override_out_of_stock'] );

		if ( ! $override_out_of_stock
			&& 'outofstock' === $product->get_stock_status()
			&& 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' )
		) {
			return false;
		}

		return true;
	}
}
