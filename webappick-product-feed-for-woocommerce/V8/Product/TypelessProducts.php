<?php
/**
 * TypelessProducts — products that have no product type saved (CBT-683).
 *
 * Imports, supplier syncs and migrations can leave a product without a
 * `product_type` term. WooCommerce loads such a product as a simple product,
 * but a typed product query never matches it — V8 dropped them from every
 * feed (#69315: 3,064 of 4,346 products) where V5's "wp" query mode exported
 * them as simple. They are often half-written, so each one is also checked
 * before it enters a feed.
 *
 * Built so feed generation time does not change: stores WITHOUT such
 * products (almost all) pay two tiny cached reads per request; the
 * per-product check runs on the product object the generation loop already
 * loaded, only for products that really have no type.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.30
 */

namespace CTXFeed\V8\Product;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typeless product helpers.
 *
 * @since 8.0.30
 */
final class TypelessProducts {

	/**
	 * Per-request memo of exist(), keyed by the status set.
	 *
	 * @var array<string,bool>
	 */
	private static $exist = array();

	/**
	 * Whether the store has products without a product type — cheaply.
	 *
	 * For published products WordPress keeps a per-term object count on
	 * every product_type term, and a typed product carries exactly one such
	 * term, so "published products − sum of product_type counts" > 0 means
	 * typeless products exist. Two tiny cached reads, independent of catalog
	 * size (a NOT EXISTS scan costs ~100 ms at 50K products). Counts only
	 * cover published posts, so a feed that also includes other statuses is
	 * treated as "may have". A stale count errs safely: too low → the query
	 * is widened for nothing (same result); too high → the pre-8.0.30
	 * behaviour.
	 *
	 * @since 8.0.30
	 *
	 * @param string|array $status Post status(es) the feed queries.
	 * @return bool
	 */
	public static function exist( $status = 'publish' ): bool {
		$statuses = array_values( array_unique( array_map( 'strval', (array) $status ) ) );
		sort( $statuses );
		$key = implode( ',', $statuses );
		if ( isset( self::$exist[ $key ] ) ) {
			return self::$exist[ $key ];
		}

		$has = true;
		try {
			if ( array( 'publish' ) === $statuses && function_exists( 'wp_count_posts' ) && function_exists( 'get_terms' ) ) {
				$counts    = wp_count_posts( 'product' );
				$published = isset( $counts->publish ) ? (int) $counts->publish : 0;
				$terms     = get_terms(
					array(
						'taxonomy'   => 'product_type',
						'hide_empty' => false,
					)
				);
				if ( ! is_array( $terms ) ) {
					// WP_Error (taxonomy gone): can't tell — keep the original query.
					$has = false;
				} else {
					$typed = 0;
					foreach ( $terms as $term ) {
						$typed += isset( $term->count ) ? (int) $term->count : 0;
					}
					$has = $published > $typed;
				}
			}
		} catch ( \Throwable $e ) {
			// Fail open to the pre-8.0.30 query: never let this probe break a feed.
			$has = false;
		}

		/**
		 * Whether the store has products without a product type. Decides if
		 * feed queries are widened to include them and if the per-product
		 * check runs. Return true to force both when term counts are known
		 * to be stale.
		 *
		 * @since 8.0.30
		 *
		 * @param bool     $has      Cheap count-based verdict.
		 * @param string[] $statuses Post statuses the feed queries.
		 */
		self::$exist[ $key ] = (bool) apply_filters( 'ctxfeed_has_typeless_products', $has, $statuses );

		return self::$exist[ $key ];
	}

	/**
	 * Whether a product post has no product_type term. Reads the term cache
	 * the batch cache warmer already primed — no query in a feed run.
	 *
	 * @since 8.0.30
	 *
	 * @param int $product_id Product (not variation) id.
	 * @return bool
	 */
	public static function lacks_type( int $product_id ): bool {
		try {
			$terms = get_the_terms( $product_id, 'product_type' );
		} catch ( \Throwable $e ) {
			return false; // Can't tell → treat as typed (no extra check).
		}

		return ! is_wp_error( $terms ) && empty( $terms );
	}

	/**
	 * Whether a product without a product type is safe to feed: WooCommerce
	 * loaded it as a simple product and the built-in getters a feed row
	 * calls do not throw. Works on the already-loaded object (the getters
	 * read loaded data), so it costs microseconds.
	 *
	 * @since 8.0.30
	 *
	 * @param \WC_Product $product Loaded product.
	 * @return bool
	 */
	public static function is_usable( \WC_Product $product ): bool {
		try {
			$valid = $product->get_id() > 0 && $product->is_type( 'simple' );

			if ( $valid ) {
				$product->get_name();
				$product->get_price();
				$product->get_regular_price();
				$product->get_sale_price();
				$product->get_stock_status();
				$product->get_permalink();
				$product->get_image_id();
				$product->get_gallery_image_ids();
				$product->get_category_ids();
				$product->get_attributes();
			}
		} catch ( \Throwable $e ) {
			$valid = false;
		}

		/**
		 * Whether a product that has no product type is included in feeds.
		 * Runs after CTX Feed's own check (loaded as a simple product, its
		 * getters don't throw).
		 *
		 * @since 8.0.30
		 *
		 * @param bool        $valid   CTX Feed's verdict.
		 * @param \WC_Product $product The product.
		 */
		return (bool) apply_filters( 'ctxfeed_typeless_product_is_valid', $valid, $product );
	}

	/**
	 * Forget the per-request memo (tests, long-running CLI processes).
	 *
	 * @since 8.0.30
	 * @return void
	 */
	public static function flush(): void {
		self::$exist = array();
	}
}
