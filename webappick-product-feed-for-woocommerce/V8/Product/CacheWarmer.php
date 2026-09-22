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

use CTXFeed\V8\Core\Logger;
use CTXFeed\V8\Utility\Memory;

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
	 * Post IDs primed per pass.
	 *
	 * Every prime call materialises the whole result set for its ID list
	 * before it lands in the object cache, so the list is walked in slices
	 * instead of in one allocation.
	 *
	 * @since 8.0.26
	 * @var int
	 */
	const PRIME_CHUNK_SIZE = 500;

	/**
	 * Fraction of memory_limit at which cache priming stops.
	 *
	 * Priming is an optimisation: everything it loads is also loadable
	 * lazily. Past this mark the run is close enough to the limit that the
	 * slower path is the safe trade.
	 *
	 * @since 8.0.26
	 * @var float
	 */
	const MEMORY_BUDGET_PERCENT = 0.75;

	/**
	 * Estimated retained memory per primed variation child (bytes).
	 *
	 * A variation carries roughly 20 meta rows; each cached row costs the
	 * string pair plus PHP array overhead. 20 KB is the conservative
	 * per-child figure used to bound the step 3c fan-out.
	 *
	 * @since 8.0.26
	 * @var int
	 */
	const MEMORY_PER_CHILD = 20480;

	/**
	 * Hard ceiling on variation-child IDs fetched per batch.
	 *
	 * @since 8.0.26
	 * @var int
	 */
	const CHILD_ID_CEILING = 20000;

	/**
	 * Whether the memory budget already stopped priming in this pass.
	 *
	 * @since 8.0.26
	 * @var bool
	 */
	private $budget_tripped = false;

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

		// Every prime below is chunked and stops if the run is already near
		// the memory limit — see prime_posts(). Priming is an optimisation,
		// never a correctness requirement: whatever is skipped still loads
		// lazily.
		$this->budget_tripped = false;

		// 1+2+4. Bulk-prime post objects, ALL post meta, and taxonomy term
		// relationships in a handful of queries. One `_prime_post_caches()`
		// per ID set does all three correctly — it derives each post's real
		// taxonomies from its post type. (The previous explicit
		// `update_object_term_cache( $ids, $taxonomies )` call passed
		// TAXONOMY names where WP expects POST TYPES, so it was a silent
		// no-op, and the explicit `update_meta_cache()` was a duplicate
		// traversal of what this call already primes.)
		// @implements PROD-FRD-1.1, PROD-FRD-1.2, PROD-FRD-1.4
		$this->prime_posts( $product_ids, true, true );

		// 3. Pre-load parent products for variations.
		// @implements PROD-FRD-1.3
		$parent_ids = $this->get_parent_ids( $product_ids );

		if ( ! empty( $parent_ids ) ) {
			$this->prime_posts( $parent_ids, true, true );
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
			$this->prime_posts( array_keys( $attachment_ids ), false, true );
		}

		// 3c. Warm the CHILDREN of variable products in the batch. A
		// parents-only feed with `quantity` mapped reads each child's
		// `_stock` meta (AttributeResolver::resolve_quantity via
		// get_visible_children) — one lazy meta query per child per parent
		// when un-warmed, because step 1 only covers the batch IDs and
		// step 3 only covers PARENTS OF variations, never children of
		// variables.
		// The fan-out here is unbounded by nature: one batch of variable
		// parents can own tens of thousands of children, and priming all of
		// their meta in one pass is what exhausted memory on large catalogs
		// (CBT-629). The ID query is capped to what the remaining memory
		// headroom can hold, and the priming itself is chunked.
		$child_ids = $this->get_child_ids( $product_ids, $this->child_id_limit( count( $product_ids ) ) );
		if ( ! empty( $child_ids ) ) {
			$this->prime_child_meta( $child_ids );
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
	 * @param int   $limit       Maximum number of child IDs to return.
	 *
	 * @return int[] Child variation IDs.
	 */
	private function get_child_ids( array $product_ids, int $limit ): array {
		global $wpdb;

		if ( empty( $product_ids ) || $limit < 1 ) {
			return array();
		}

		$ids_placeholder = implode( ',', array_map( 'absint', $product_ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache-priming helper: this single query IS what populates the caches for the batch, so caching it would be circular. One query per Action Scheduler batch.
		$child_ids = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ids_placeholder is built above by absint()-casting every element and joining with commas, so it can only ever contain digits and commas; $wpdb->prepare() has no placeholder for a variable-length IN() list.
			"SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ({$ids_placeholder}) AND post_type = 'product_variation' AND post_status IN ( 'publish', 'private' ) LIMIT " . absint( $limit )
		);

		return array_map( 'absint', $child_ids );
	}

	/**
	 * Prime post objects/meta/terms for an ID list, in chunks, within budget.
	 *
	 * @since 8.0.26
	 *
	 * @param int[] $ids          Post IDs to prime.
	 * @param bool  $prime_terms  Whether to prime the term relationship cache.
	 * @param bool  $prime_meta   Whether to prime the post meta cache.
	 *
	 * @return int Number of IDs actually primed.
	 */
	private function prime_posts( array $ids, bool $prime_terms, bool $prime_meta ): int {
		$primed = 0;

		foreach ( array_chunk( $ids, $this->chunk_size() ) as $chunk ) {
			if ( $this->over_budget() ) {
				$this->report_budget_stop( count( $ids ), $primed );

				return $primed;
			}

			_prime_post_caches( $chunk, $prime_terms, $prime_meta );
			$primed += count( $chunk );
		}

		return $primed;
	}

	/**
	 * Prime variation-child post meta, in chunks, within budget.
	 *
	 * @since 8.0.26
	 *
	 * @param int[] $child_ids Variation child IDs.
	 *
	 * @return int Number of IDs actually primed.
	 */
	private function prime_child_meta( array $child_ids ): int {
		$primed = 0;

		foreach ( array_chunk( $child_ids, $this->chunk_size() ) as $chunk ) {
			if ( $this->over_budget() ) {
				$this->report_budget_stop( count( $child_ids ), $primed );

				return $primed;
			}

			update_meta_cache( 'post', $chunk );
			$primed += count( $chunk );
		}

		return $primed;
	}

	/**
	 * IDs primed per pass.
	 *
	 * @since 8.0.26
	 *
	 * @return int Chunk size (at least 1).
	 */
	private function chunk_size(): int {
		/**
		 * Filter the number of post IDs primed per cache-warming pass.
		 *
		 * @since 8.0.26
		 *
		 * @param int $chunk_size Default PRIME_CHUNK_SIZE.
		 */
		$chunk_size = (int) apply_filters( 'ctxfeed_cache_warm_chunk_size', self::PRIME_CHUNK_SIZE );

		return max( 1, $chunk_size );
	}

	/**
	 * Maximum number of variation children to fetch for this batch.
	 *
	 * Derived from the memory still available under the priming budget, so
	 * a variation-heavy batch primes what it can afford and leaves the rest
	 * to lazy loading instead of fatalling.
	 *
	 * @since 8.0.26
	 *
	 * @param int $batch_size Number of top-level product IDs in the batch.
	 *
	 * @return int Child ID limit (0 = prime none).
	 */
	private function child_id_limit( int $batch_size ): int {
		$headroom = $this->memory_headroom();
		$limit    = $headroom > 0 ? (int) floor( $headroom / self::MEMORY_PER_CHILD ) : 0;

		if ( $limit > self::CHILD_ID_CEILING ) {
			$limit = self::CHILD_ID_CEILING;
		}

		/**
		 * Filter the maximum number of variation children primed per batch.
		 *
		 * @since 8.0.26
		 *
		 * @param int $limit      Headroom-derived limit, capped at CHILD_ID_CEILING.
		 * @param int $batch_size Number of top-level product IDs in the batch.
		 */
		$limit = (int) apply_filters( 'ctxfeed_cache_warm_child_limit', $limit, $batch_size );

		return max( 0, $limit );
	}

	/**
	 * Whether the priming budget is already spent.
	 *
	 * @since 8.0.26
	 *
	 * @return bool True when no further priming should happen.
	 */
	private function over_budget(): bool {
		return $this->memory_headroom() <= 0;
	}

	/**
	 * Memory still available under the priming budget.
	 *
	 * @since 8.0.26
	 *
	 * @return float Bytes available; PHP_INT_MAX when memory_limit is unlimited.
	 */
	private function memory_headroom(): float {
		$limit = $this->memory_limit_bytes();

		if ( $limit <= 0 ) {
			return (float) PHP_INT_MAX;
		}

		/**
		 * Filter the fraction of memory_limit cache priming may occupy.
		 *
		 * @since 8.0.26
		 *
		 * @param float $percent Default MEMORY_BUDGET_PERCENT.
		 */
		$percent = (float) apply_filters( 'ctxfeed_cache_warm_memory_percent', self::MEMORY_BUDGET_PERCENT );
		$percent = min( 1.0, max( 0.1, $percent ) );

		return ( $limit * $percent ) - Memory::usage();
	}

	/**
	 * PHP memory_limit in bytes.
	 *
	 * @since 8.0.26
	 *
	 * @return float Bytes, or 0 when unlimited/unreadable.
	 */
	private function memory_limit_bytes(): float {
		$bytes = Memory::limit_bytes();

		/**
		 * Filter the memory limit cache priming budgets against.
		 *
		 * `memory_limit` is not always the limit that actually kills the
		 * process: containerised hosts cap the whole PHP worker below what
		 * ini reports. Return the effective ceiling in bytes, or 0/negative
		 * for unlimited.
		 *
		 * @since 8.0.26
		 *
		 * @param float $bytes Bytes parsed from the memory_limit ini value.
		 */
		$bytes = (float) apply_filters( 'ctxfeed_cache_warm_memory_limit', $bytes );

		return $bytes > 0 ? $bytes : 0.0;
	}

	/**
	 * Log the first budget stop of the current pass.
	 *
	 * @since 8.0.26
	 *
	 * @param int $requested Number of IDs the pass was asked to prime.
	 * @param int $primed    Number of IDs primed before stopping.
	 *
	 * @return void
	 */
	private function report_budget_stop( int $requested, int $primed ): void {
		if ( $this->budget_tripped ) {
			return;
		}

		$this->budget_tripped = true;

		Logger::info(
			'Cache priming stopped early to stay inside the memory budget; remaining data loads on demand.',
			array(
				'requested_ids' => $requested,
				'primed_ids'    => $primed,
				'memory_usage'  => (int) Memory::usage(),
				'memory_limit'  => (int) $this->memory_limit_bytes(),
			)
		);
	}
}
