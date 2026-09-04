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

		// 1+2+4. Bulk-prime post objects, ALL post meta, and taxonomy term
		// relationships in a handful of queries. One `_prime_post_caches()`
		// per ID set does all three correctly — it derives each post's real
		// taxonomies from its post type. (The previous explicit
		// `update_object_term_cache( $ids, $taxonomies )` call passed
		// TAXONOMY names where WP expects POST TYPES, so it was a silent
		// no-op, and the explicit `update_meta_cache()` was a duplicate
		// traversal of what this call already primes.)
		// @implements PROD-FRD-1.1, PROD-FRD-1.2, PROD-FRD-1.4
		_prime_post_caches( $product_ids, true, true );

		// 3. Pre-load parent products for variations.
		// @implements PROD-FRD-1.3
		$parent_ids = $this->get_parent_ids( $product_ids );

		if ( ! empty( $parent_ids ) ) {
			_prime_post_caches( $parent_ids, true, true );
		}

		// 3b. Prime image attachments. Every image attribute resolves
		// through wp_get_attachment_image_url(), which reads the
		// attachment's post row and its _wp_attachment_metadata /
		// _wp_attached_file meta — 1-3 lazy queries per attachment per
		// batch when un-primed. The IDs are already in the warm product
		// meta, so this is one bulk prime instead of thousands of
		// single-row reads.
		$attachment_ids = array();
		foreach ( array_merge( $product_ids, $parent_ids ) as $pid ) {
			$thumb_id = (int) get_post_meta( $pid, '_thumbnail_id', true );
			if ( $thumb_id > 0 ) {
				$attachment_ids[ $thumb_id ] = true;
			}

			$gallery = (string) get_post_meta( $pid, '_product_image_gallery', true );
			if ( '' !== $gallery ) {
				foreach ( explode( ',', $gallery ) as $gallery_id ) {
					$gallery_id = (int) $gallery_id;
					if ( $gallery_id > 0 ) {
						$attachment_ids[ $gallery_id ] = true;
					}
				}
			}
		}

		if ( ! empty( $attachment_ids ) ) {
			_prime_post_caches( array_keys( $attachment_ids ), false, true );
		}

		// 3c. Warm the CHILDREN of variable products in the batch. A
		// parents-only feed with `quantity` mapped reads each child's
		// `_stock` meta (AttributeResolver::resolve_quantity via
		// get_visible_children) — one lazy meta query per child per parent
		// when un-warmed, because step 1 only covers the batch IDs and
		// step 3 only covers PARENTS OF variations, never children of
		// variables.
		$child_ids = $this->get_child_ids( $product_ids );
		if ( ! empty( $child_ids ) ) {
			update_meta_cache( 'post', $child_ids );
		}

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

	/**
	 * Variation children of any variable products in the batch.
	 *
	 * Mirrors {@see get_parent_ids()}: one raw ID query per batch.
	 *
	 * @since 8.0.12
	 *
	 * @param int[] $product_ids Batch product IDs.
	 *
	 * @return int[] Child variation IDs.
	 */
	private function get_child_ids( array $product_ids ): array {
		global $wpdb;

		if ( empty( $product_ids ) ) {
			return array();
		}

		$ids_placeholder = implode( ',', array_map( 'absint', $product_ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache-priming helper: this single query IS what populates the caches for the batch, so caching it would be circular. One query per Action Scheduler batch.
		$child_ids = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ids_placeholder is built above by absint()-casting every element and joining with commas, so it can only ever contain digits and commas; $wpdb->prepare() has no placeholder for a variable-length IN() list.
			"SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ({$ids_placeholder}) AND post_type = 'product_variation' AND post_status IN ( 'publish', 'private' )"
		);

		return array_map( 'absint', $child_ids );
	}
}
