<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "wf" is an established V5-era prefix registered in phpcs.xml.dist; renaming would break 100K+ existing installs.
/**
 * MetaResolver — Resolves post meta values for products.
 *
 * All meta is already cache-primed by CacheWarmer, so
 * get_post_meta() calls hit the WP object cache (0 queries).
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-6.1
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post meta resolver.
 *
 * @since 8.0.0
 */
class MetaResolver {

	/**
	 * Resolve a post meta value for a product.
	 *
	 * Retrieves meta from the WP object cache (primed by CacheWarmer).
	 * Arrays are reduced to their first element; scalars are cast to string.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-6.1
	 *
	 * @param \WC_Product $product  WooCommerce product.
	 * @param string      $meta_key Meta key to resolve.
	 * @param Config      $config   Feed configuration.
	 *
	 * @return string Resolved meta value or empty string.
	 */
	public function resolve( \WC_Product $product, string $meta_key, Config $config ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $config is part of the shared resolver signature; AttributeResolver invokes every resolver uniformly.
		$value = get_post_meta( $product->get_id(), $meta_key, true );

		// Treat empty values (other than boolean false) as an empty string.
		if ( empty( $value ) && false !== $value ) {
			return '';
		}

		// Array → first element cast to string.
		if ( is_array( $value ) ) {
			return isset( $value[0] ) ? (string) $value[0] : '';
		}

		// Scalar → cast to string.
		return (string) $value;
	}
}
