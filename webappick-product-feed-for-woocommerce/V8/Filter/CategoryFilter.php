<?php
/**
 * CategoryFilter — Includes/excludes products by WooCommerce category.
 *
 * V5-compatible: reads `categories` (array of slugs) + `filter_mode.categories`
 * ('include' | 'exclude') from feedrules. Matches V5 Filter::include_categories()
 * and Filter::exclude_categories().
 *
 * When `categories` is empty, no filtering is applied (pass-through).
 *
 * For variations, the parent product's categories are checked — same as V5.
 *
 * @package    CTXFeed
 * @subpackage V8/Filter
 * @since      8.0.0
 * @implements FLTR-FRD-5.1, FLTR-FRD-5.2, FLTR-FRD-5.3
 */

namespace CTXFeed\V8\Filter;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category filter (V5-compatible).
 *
 * @since 8.0.0
 */
class CategoryFilter implements FilterInterface {

	/**
	 * Check if a product passes the category filter.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return bool True if product passes, false to exclude.
	 */
	public function passes( \WC_Product $product, Config $config ): bool {
		$categories = (array) $config->get( 'categories', array() );

		if ( empty( $categories ) ) {
			return true;
		}

		$mode_all = (array) $config->get( 'filter_mode', array() );
		$mode     = isset( $mode_all['categories'] ) ? $mode_all['categories'] : 'include';

		if ( ! in_array( $mode, array( 'include', 'exclude' ), true ) ) {
			$mode = 'include';
		}

		// For variations, check the parent's categories (same as V5).
		$cat_product_id = $product->is_type( 'variation' )
			? $product->get_parent_id()
			: $product->get_id();

		// has_term() accepts an array of term slugs/ids; using slugs here
		// matches V5's storage format.
		$has_any = has_term( $categories, 'product_cat', $cat_product_id );

		if ( 'exclude' === $mode ) {
			// Exclude mode: reject if product is in any of the listed categories.
			return ! $has_any;
		}

		// Include mode (default): keep only products in listed categories.
		return (bool) $has_any;
	}
}
