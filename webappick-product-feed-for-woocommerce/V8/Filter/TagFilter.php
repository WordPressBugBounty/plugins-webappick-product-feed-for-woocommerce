<?php
/**
 * TagFilter — Includes/excludes products by WooCommerce product tags.
 *
 * Supports both inclusion and exclusion lists with exclusion taking
 * priority. Logs WP_Error from taxonomy queries before graceful degradation.
 *
 * @package    CTXFeed
 * @subpackage V8/Filter
 * @since      8.0.0
 * @implements FLTR-FRD-6.1, FLTR-FRD-6.2, FLTR-FRD-6.3
 */

namespace CTXFeed\V8\Filter;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Product\TermCache;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tag filter.
 *
 * @since 8.0.0
 */
class TagFilter implements FilterInterface {

	/**
	 * Check if a product passes the tag filter.
	 *
	 * Evaluates include/exclude tag lists. Exclusion takes priority.
	 *
	 * @since 8.0.0
	 * @implements FLTR-FRD-6.1, FLTR-FRD-6.2, FLTR-FRD-6.3
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return bool True if product passes, false to exclude.
	 */
	public function passes( \WC_Product $product, Config $config ): bool {
		// Clean both lists BEFORE the emptiness check: an empty selection can
		// be persisted as '' or [ '' ], and a [ '' ] include list would arm
		// the filter with a list that matches nothing (#68878).
		$include_tags = FilterHelper::clean_list( $config->get( 'include_tags', array() ) );
		$exclude_tags = FilterHelper::clean_list( $config->get( 'exclude_tags', array() ) );

		// No tags configured — pass all. @implements FLTR-FRD-6.1.
		if ( empty( $include_tags ) && empty( $exclude_tags ) ) {
			return true;
		}

		$product_tags = TermCache::get_ids( $product->get_id(), 'product_tag' );

		// Log WP_Error before graceful degradation. @implements FLTR-FRD-6.3.
		if ( is_wp_error( $product_tags ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging for taxonomy errors.
			error_log(
				sprintf(
					'CTXFeed: TagFilter WP_Error for product %d: %s',
					$product->get_id(),
					$product_tags->get_error_message()
				)
			);
			return true;
		}

		// Exclusion takes priority. @implements FLTR-FRD-6.2.
		if ( ! empty( $exclude_tags ) && array_intersect( $product_tags, $exclude_tags ) ) {
			return false;
		}

		// If inclusion list set, product must be in at least one.
		if ( ! empty( $include_tags ) && ! array_intersect( $product_tags, $include_tags ) ) {
			return false;
		}

		return true;
	}
}
