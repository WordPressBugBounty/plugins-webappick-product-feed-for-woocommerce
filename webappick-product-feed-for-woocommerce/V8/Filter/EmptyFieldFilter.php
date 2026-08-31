<?php
/**
 * EmptyFieldFilter — Excludes products with empty description, image, or price.
 *
 * Uses V5-compatible feedrules keys:
 * - `is_emptyDescription` → excludes products with empty description.
 *                           For variations, falls back to the parent product's
 *                           description before excluding.
 * - `is_emptyImage`       → excludes products with no featured image
 *                           (get_image_id); variations fall back to the parent.
 * - `is_emptyPrice`       → excludes products with empty get_price().
 *
 * Mirrors V5 Filter::exclude_empty_description_products(),
 * exclude_empty_image_products(), exclude_empty_price_products().
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
 * Empty-field filter (V5-compatible).
 *
 * @since 8.0.0
 */
class EmptyFieldFilter implements FilterInterface {

	/**
	 * Check if a product passes the empty-field filters.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return bool True if product passes, false to exclude.
	 */
	public function passes( \WC_Product $product, Config $config ): bool {
		// is_emptyDescription.
		//
		// Mirrors V5 exactly: for a variation, only exclude when BOTH the
		// variation's own description AND the parent's description are
		// empty. Non-variation products are excluded when their description
		// is empty. Uses empty() to catch '', null, 0, '0'.
		if ( FilterHelper::bool( $config->get( 'is_emptyDescription', false ) ) ) {
			$description = $product->get_description();

			if ( empty( $description ) ) {
				if ( $product->is_type( 'variation' ) ) {
					$parent = wc_get_product( $product->get_parent_id() );
					if ( $parent instanceof \WC_Product && empty( $parent->get_description() ) ) {
						return false;
					}
				} else {
					return false;
				}
			}
		}

		// is_emptyImage.
		//
		// "Has an image" is a DATA question: use get_image_id() — the
		// `_thumbnail_id` attachment id, which is exactly what V5 filters on at
		// the query level (V5 ProductQuery.php:236-242, `_thumbnail_id != ''`).
		// The old code used get_image( 'woocommerce_thumbnail', …, false ), but
		// that RENDERS an <img> and returns '' for EVERY product in the async
		// feed-generation context (WP-Cron / Action Scheduler, where the theme's
		// image sizes aren't set up), so turning the filter on emptied the whole
		// feed (BUG-0030). Variations with no own image fall back to the parent,
		// mirroring the description branch above.
		if ( FilterHelper::bool( $config->get( 'is_emptyImage', false ) ) ) {
			if ( empty( $product->get_image_id() ) ) {
				if ( $product->is_type( 'variation' ) ) {
					$parent = wc_get_product( $product->get_parent_id() );
					if ( $parent instanceof \WC_Product && empty( $parent->get_image_id() ) ) {
						return false;
					}
				} else {
					return false;
				}
			}
		}

		// is_emptyPrice.
		//
		// Uses PHP empty() to match V5 exactly — catches '', null, 0, '0', false.
		// V5 treats free products (price == 0) as "empty price" and excludes
		// them when the filter is on, which aligns with most merchant feed
		// rules (Google Shopping rejects $0 items, etc.).
		if ( FilterHelper::bool( $config->get( 'is_emptyPrice', false ) ) ) {
			if ( empty( $product->get_price() ) ) {
				return false;
			}
		}

		return true;
	}
}
