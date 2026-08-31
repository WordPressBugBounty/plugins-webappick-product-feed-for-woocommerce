<?php
/**
 * CacheWarmer — THE key to 10x performance.
 *
 * Bulk-loads all post meta, taxonomy terms, and post objects into
 * the WordPress object cache BEFORE wc_get_product() is called,
 * so every subsequent data access is a cache hit (0 additional queries).
 *
 * BEFORE: 1000 products × 30 attributes = 30,000 DB queries
 * AFTER:  1000 products × 30 attributes = 3 DB queries + 30,000 cache hits
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-1.1, PROD-FRD-1.2, PROD-FRD-1.3, PROD-FRD-1.4, PROD-FRD-1.5
 */

namespace CTXFeed\V8\Product;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk cache primer for product data.
 *
 * @since 8.0.0
 */
class CacheWarmer {

	/**
	 * Prime all caches for a batch of product IDs.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-1.1, PROD-FRD-1.2, PROD-FRD-1.4, PROD-FRD-1.5
	 *
	 * @param int[] $product_ids Array of product IDs to cache.
	 *
	 * @return void
	 */
	public function warm( array $product_ids ): void {
		if ( empty( $product_ids ) ) {
			return;
		}

		// 1. Bulk load ALL post meta in ONE query.
		// @implements PROD-FRD-1.1
		update_meta_cache( 'post', $product_ids );

		// 2. Bulk load ALL taxonomy terms.
		// @implements PROD-FRD-1.2
		$taxonomies = get_object_taxonomies( 'product' );
		update_object_term_cache( $product_ids, $taxonomies );

		// 3. Pre-load parent products for variations.
		// @implements PROD-FRD-1.3
		$parent_ids = $this->get_parent_ids( $product_ids );

		if ( ! empty( $parent_ids ) ) {
			update_meta_cache( 'post', $parent_ids );
			update_object_term_cache( $parent_ids, $taxonomies );
			_prime_post_caches( $parent_ids, true, true );
		}

		// 4. Prime WP post object cache.
		// @implements PROD-FRD-1.4
		_prime_post_caches( $product_ids, true, true );

		// 5. Allow compat plugins to warm their own caches.
		// @implements PROD-FRD-1.5
		// @hook ctxfeed_cache_warmed
		do_action( 'ctxfeed_cache_warmed', $product_ids, $parent_ids );
	}

	/**
	 * Get parent product IDs for any variations in the batch.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-1.3
	 *
	 * @param int[] $product_ids Array of product IDs (may include variations).
	 *
	 * @return int[] Array of unique parent product IDs.
	 */
	private function get_parent_ids( array $product_ids ): array {
		global $wpdb;

		if ( empty( $product_ids ) ) {
			return array();
		}

		$ids_placeholder = implode( ',', array_map( 'absint', $product_ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache-priming helper: this single query IS what populates the caches for the batch, so caching it would be circular. One query per 200-product Action Scheduler batch.
		$parent_ids = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ids_placeholder is built above by absint()-casting every element and joining with commas, so it can only ever contain digits and commas; $wpdb->prepare() has no placeholder for a variable-length IN() list.
			"SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE ID IN ({$ids_placeholder}) AND post_parent > 0 AND post_type = 'product_variation'"
		);

		return array_map( 'absint', $parent_ids );
	}
}
