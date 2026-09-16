<?php
/**
 * ProductTypeSupport — which WooCommerce product types the FREE plugin
 * exports, and how many published products fall outside that set.
 *
 * The free product query reads only the four core types (see FREE_TYPES).
 * Every other `product_type` term (bundle, woosb, woosg, composite,
 * subscription, variable-subscription, auction, custom types) is added to
 * the query by a Pro compatibility shim, so on free those products never
 * reach a feed. This helper is the single place that names that set, so
 * the admin notice, the health endpoint (MCP `ctxfeed/get-health`) and the
 * query agree (CBT-600).
 *
 * @package CTXFeed\V8\Product
 * @since   8.0.23
 */

namespace CTXFeed\V8\Product;

defined( 'ABSPATH' ) || exit;

/**
 * Class ProductTypeSupport
 *
 * @since 8.0.23
 */
class ProductTypeSupport {

	/**
	 * Product types the free plugin queries by default.
	 *
	 * @var string[]
	 */
	const FREE_TYPES = array( 'simple', 'variable', 'grouped', 'external' );

	/**
	 * Human labels for the product types Pro compat shims add.
	 *
	 * @var array<string,string>
	 */
	const LABELS = array(
		'bundle'                => 'Product bundles',
		'bundled'               => 'Bundled items',
		'yith_bundle'           => 'YITH bundles',
		'woosb'                 => 'WPC bundles',
		'woosg'                 => 'WPC grouped products',
		'composite'             => 'Composite products',
		'subscription'          => 'Subscriptions',
		'variable-subscription' => 'Variable subscriptions',
		'auction'               => 'Auctions',
	);

	/**
	 * Published-product counts per product type OUTSIDE the free set.
	 *
	 * One `get_terms` on the `product_type` taxonomy — WordPress keeps the
	 * published-object count on the term, so no product query runs.
	 *
	 * @since 8.0.23
	 *
	 * @return array<string,int> slug => count, only types with at least one product.
	 */
	public static function unsupported_counts(): array {
		$counts = array();

		if ( function_exists( 'get_terms' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_type',
					'hide_empty' => true,
				)
			);

			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$slug  = isset( $term->slug ) ? (string) $term->slug : '';
					$count = isset( $term->count ) ? (int) $term->count : 0;
					if ( '' === $slug || $count < 1 || in_array( $slug, self::FREE_TYPES, true ) ) {
						continue;
					}
					$counts[ $slug ] = $count;
				}
			}
		}

		/**
		 * Filter the per-type counts of products the free plugin will not export.
		 *
		 * @since 8.0.23
		 *
		 * @param array<string,int> $counts slug => published product count.
		 */
		return (array) apply_filters( 'ctxfeed_unsupported_product_type_counts', $counts );
	}

	/**
	 * Human label for a product-type slug.
	 *
	 * @since 8.0.23
	 *
	 * @param string $slug product_type term slug.
	 * @return string
	 */
	public static function label( string $slug ): string {
		if ( isset( self::LABELS[ $slug ] ) ) {
			return self::LABELS[ $slug ];
		}

		return ucfirst( str_replace( array( '-', '_' ), ' ', $slug ) );
	}
}
