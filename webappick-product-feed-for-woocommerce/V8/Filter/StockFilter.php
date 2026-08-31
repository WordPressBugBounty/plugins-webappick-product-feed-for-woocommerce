<?php
/**
 * StockFilter — Filters products based on stock status and backorder rules.
 *
 * Uses V5-compatible feedrules keys for backwards compatibility with the
 * 70K+ existing installs:
 * - `is_outOfStock`  → when truthy, excludes 'outofstock' products (but
 *                      keeps 'onbackorder' — backorder has its own filter).
 * - `is_backorder`   → when truthy, excludes 'onbackorder' products.
 *
 * Mirrors V5 Filter::exclude_out_of_stock_products() + exclude_back_order_products().
 *
 * @package    CTXFeed
 * @subpackage V8/Filter
 * @since      8.0.0
 * @implements FLTR-FRD-3.1, FLTR-FRD-3.2
 */

namespace CTXFeed\V8\Filter;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stock / backorder filter (V5-compatible).
 *
 * @since 8.0.0
 */
class StockFilter implements FilterInterface {

	/**
	 * Check if a product passes the stock filter.
	 *
	 * @since 8.0.0
	 * @implements FLTR-FRD-3.1, FLTR-FRD-3.2
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return bool True if product passes, false to exclude.
	 */
	public function passes( \WC_Product $product, Config $config ): bool {
		$stock_status = $product->get_stock_status();
		$stock_qty    = $product->get_stock_quantity(); // Keep raw — may be null.

		// V5 key: `is_outOfStock` — excludes products that are truly out of
		// stock. Mirrors V5 exclude_out_of_stock_products(): a product only
		// gets excluded when the filter is ON *and* it's actually out of
		// stock (status === 'outofstock' OR quantity is strictly 0) *and*
		// it's not a backorder product (backorder gets a free pass here).
		//
		// Critical: use !== 0 (loose comparison against raw value). Products
		// that don't manage stock return null for quantity — `null !== 0`
		// evaluates to true, so they pass. A strict int-cast would turn null
		// into 0 and wrongly exclude every non-stock-managed product.
		$remove_out_of_stock = FilterHelper::bool( $config->get( 'is_outOfStock', false ) );

		if ( $remove_out_of_stock ) {
			$in_stock = ( 'outofstock' !== $stock_status && 0 !== $stock_qty )
				|| ( 'onbackorder' === $stock_status );

			if ( ! $in_stock ) {
				return false;
			}
		}

		// V5 key: `is_backorder` — excludes 'onbackorder' products.
		$remove_backorder = FilterHelper::bool( $config->get( 'is_backorder', false ) );

		if ( $remove_backorder && 'onbackorder' === $stock_status ) {
			return false;
		}

		return true;
	}
}
