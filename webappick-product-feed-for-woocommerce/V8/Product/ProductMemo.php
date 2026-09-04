<?php
/**
 * Per-batch product object memo.
 *
 * @package    CTXFeed
 * @subpackage V8\Product
 * @since      8.0.11
 */

namespace CTXFeed\V8\Product;

/**
 * Small request-scoped cache for repeated `wc_get_product()` loads.
 *
 * During generation the resolvers load a variation's PARENT product many
 * times per item (attribute fallbacks, image fallbacks, category lookups,
 * legacy-bridge arguments, output-command parent resolution). Variations of
 * the same variable product are processed back to back, so the same parent
 * was being re-hydrated dozens of times per product. This memo makes each
 * distinct product object a one-time cost per batch.
 *
 * Notes:
 * - Negative results (`false`/`null` from `wc_get_product()`) are cached too,
 *   so a missing parent costs one lookup per batch instead of one per use.
 * - The cache is capped (FIFO) so a pathological batch cannot hoard memory;
 *   variations of one parent are contiguous in the batch, so a small cap
 *   still yields a near-perfect hit rate.
 * - {@see ProductMemo::clear()} runs at the start of every batch (and in the
 *   unit-test base case) so entries never outlive one batch of work.
 *
 * @since 8.0.11
 */
final class ProductMemo {

	/**
	 * Maximum number of memoized products (FIFO eviction beyond this).
	 *
	 * @since 8.0.11
	 * @var int
	 */
	const MAX_ENTRIES = 200;

	/**
	 * Memoized products keyed by product ID.
	 *
	 * @since 8.0.11
	 * @var array<int, \WC_Product|false|null>
	 */
	private static $cache = array();

	/**
	 * The product currently being processed by the generator loop.
	 *
	 * A one-slot register so transforms that re-load "their own" product by
	 * ID (e.g. FacebookTransform) get the already-constructed object without
	 * inserting single-use rows into the FIFO cache and evicting parents.
	 *
	 * @since 8.0.12
	 * @var \WC_Product|null
	 */
	private static $current = null;

	/**
	 * Memoised parent-resolved VALUES, keyed "parent_id|mapping-hash".
	 *
	 * 50 variations of one parent fall back for the same empty attributes
	 * with identical results (config is fixed for the run), so the parent
	 * re-resolution runs once per parent per attribute, not once per
	 * variation. Shares clear()'s per-batch lifecycle with the object memo.
	 *
	 * @since 8.0.12
	 * @var array<string, mixed>
	 */
	private static $values = array();

	/**
	 * Set true by the shadow verifier on a mismatch: the rest of the run
	 * uses the slow-but-correct fresh path. Reset by clear().
	 *
	 * @since 8.0.12
	 * @var bool
	 */
	private static $values_disabled = false;

	/**
	 * Load a product through the memo.
	 *
	 * Mirrors `wc_get_product()` semantics: returns the product object, or
	 * `false`/`null` when the ID does not resolve to one.
	 *
	 * @since 8.0.11
	 *
	 * @param int $product_id Product (or parent product) ID.
	 *
	 * @return \WC_Product|false|null Product object or falsy when not found.
	 */
	public static function get( int $product_id ) {
		if ( $product_id <= 0 ) {
			return false;
		}

		if ( null !== self::$current && self::$current->get_id() === $product_id ) {
			return self::$current;
		}

		if ( array_key_exists( $product_id, self::$cache ) ) {
			return self::$cache[ $product_id ];
		}

		$product = wc_get_product( $product_id );

		if ( count( self::$cache ) >= self::MAX_ENTRIES ) {
			// FIFO: drop the oldest entry. unset() by key — array_shift()
			// would renumber the integer product-ID keys.
			unset( self::$cache[ array_key_first( self::$cache ) ] );
		}

		self::$cache[ $product_id ] = $product;

		return $product;
	}

	/**
	 * Empty the memo.
	 *
	 * Called at the start of each generation batch so objects never go stale
	 * across batches, and from the test base case so mocked products cannot
	 * leak between tests.
	 *
	 * @since 8.0.11
	 *
	 * @return void
	 */
	public static function clear(): void {
		self::$cache           = array();
		self::$current         = null;
		self::$values          = array();
		self::$values_disabled = false;
	}

	/**
	 * Whether a parent-resolved value is memoised for this key.
	 *
	 * @since 8.0.12
	 *
	 * @param string $key Memo key ("parent_id|mapping-hash").
	 *
	 * @return bool
	 */
	public static function has_value( string $key ): bool {
		return ! self::$values_disabled && array_key_exists( $key, self::$values );
	}

	/**
	 * Read a memoised parent-resolved value.
	 *
	 * @since 8.0.12
	 *
	 * @param string $key Memo key.
	 *
	 * @return mixed
	 */
	public static function get_value( string $key ) {
		return self::$values[ $key ] ?? null;
	}

	/**
	 * Store a parent-resolved value (FIFO-capped like the object memo).
	 *
	 * @since 8.0.12
	 *
	 * @param string $key   Memo key.
	 * @param mixed  $value Resolved value.
	 *
	 * @return void
	 */
	public static function set_value( string $key, $value ): void {
		if ( self::$values_disabled ) {
			return;
		}
		if ( count( self::$values ) >= 10 * self::MAX_ENTRIES ) {
			unset( self::$values[ array_key_first( self::$values ) ] );
		}
		self::$values[ $key ] = $value;
	}

	/**
	 * Fail open: stop serving memoised values for the rest of this batch.
	 *
	 * Called by the shadow verifier when a memoised value disagrees with a
	 * fresh resolution — a nondeterministic filter is in play, so correctness
	 * wins over speed until the next batch re-detects.
	 *
	 * @since 8.0.12
	 *
	 * @return void
	 */
	public static function disable_values(): void {
		self::$values_disabled = true;
		self::$values          = array();
	}

	/**
	 * Register the product the generator loop is currently processing.
	 *
	 * @since 8.0.12
	 *
	 * @param \WC_Product $product Current loop product.
	 *
	 * @return void
	 */
	public static function remember_current( \WC_Product $product ): void {
		self::$current = $product;
	}
}
