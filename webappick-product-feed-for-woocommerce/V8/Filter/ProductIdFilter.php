<?php
/**
 * ProductIdFilter — Includes/excludes specific products by ID.
 *
 * V5-compatible: reads `product_ids` (array of ints) + `filter_mode.product_ids`
 * ('include' | 'exclude') from feedrules. Mirrors V5 Filter::include_products()
 * and Filter::exclude_products().
 *
 * For variations, the parent product's ID is checked so that excluding a
 * parent also excludes its variations (matches V5 variation-handling).
 *
 * When `product_ids` is empty, no filtering is applied (pass-through).
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
 * Product-ID include/exclude filter (V5-compatible).
 *
 * @since 8.0.0
 */
class ProductIdFilter implements FilterInterface {

	/**
	 * Check if a product passes the product-ID filter.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return bool True if product passes, false to exclude.
	 */
	public function passes( \WC_Product $product, Config $config ): bool {
		// Clean the raw list BEFORE the emptiness check ('' / [ '' ] shapes,
		// #68878), then drop entries that don't name a real post ID — an ''
		// entry used to intval to 0, arming the filter with an unmatchable
		// list that zeroed the feed in include mode.
		$ids = array_values(
			array_filter(
				array_map( 'intval', FilterHelper::clean_list( $config->get( 'product_ids', array() ) ) )
			)
		);

		if ( empty( $ids ) ) {
			return true;
		}

		$mode_all = (array) $config->get( 'filter_mode', array() );
		$mode     = isset( $mode_all['product_ids'] ) ? $mode_all['product_ids'] : 'include';

		if ( ! in_array( $mode, array( 'include', 'exclude' ), true ) ) {
			$mode = 'include';
		}

		/**
		 * Filter the configured product-id list before matching.
		 *
		 * The list holds whatever ids the merchant saved — usually the
		 * default-language ids everyone copies from the Products list. On a
		 * multilingual store the product reaching this filter is the
		 * TRANSLATED post, so a compat shim can expand each id to all of its
		 * translations (CBT-626 / BUG-0110).
		 *
		 * @since 8.0.26
		 *
		 * @param int[]       $ids     Saved ids (cleaned, non-zero).
		 * @param \WC_Product $product Product being filtered.
		 * @param Config      $config  Feed configuration.
		 */
		$ids = array_values( array_unique( array_map( 'intval', (array) apply_filters( 'ctxfeed_filter_product_ids_list', $ids, $product, $config ) ) ) );

		// Check both the product's own ID and — for variations — the parent
		// ID. V5 matches V5 include_products/exclude_products semantics.
		$product_id = (int) $product->get_id();
		$parent_id  = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : 0;

		$matches = in_array( $product_id, $ids, true )
			|| ( $parent_id > 0 && in_array( $parent_id, $ids, true ) );

		if ( 'exclude' === $mode ) {
			return ! $matches;
		}

		// Include mode (default): keep only listed products.
		return $matches;
	}
}
