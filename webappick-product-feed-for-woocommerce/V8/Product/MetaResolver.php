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
use CTXFeed\V8\Utility\NumberFormat;

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
	 * Arrays are reduced to the value under a key named like the meta, else
	 * their first content scalar (CBT-868); scalars are cast to string.
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

		// Array → the first non-empty SCALAR, descending nested structures.
		// Meta shaped like a page-builder repeater ([['url' => …], …]) used
		// to hit `(string) $value[0]` and ship the literal text "Array" into
		// the feed (CBT-571 — a silenced PHP array-to-string cast); assoc
		// arrays without a 0 key silently resolved to '' for the same reason.
		// CBT-868: a theme/page-builder options array (Flatsome stores
		// `_product_video` as [[ '_product_block' => 0, …, '_product_video' =>
		// 'https://…' ]]) holds the real value under a key named like the meta
		// itself — take that first; then the first CONTENT scalar, skipping
		// flag-like leaves (false/true/0/'0') that sort before it; only an
		// array of nothing but flags ships its first flag (old behaviour).
		if ( is_array( $value ) ) {
			$named = self::named_scalar_string( $value, $meta_key );
			if ( '' !== $named ) {
				return $named;
			}
			$content = self::first_scalar_string( $value, true );
			return '' !== $content ? $content : self::first_scalar_string( $value );
		}

		// Scalar → cast to string; a float (a filtered meta) as machine text (CBT-816).
		return is_float( $value ) ? NumberFormat::to_machine( $value ) : (string) $value;
	}

	/**
	 * First non-empty scalar inside a (possibly nested) meta array, as a
	 * string. '' when the structure holds no usable scalar — never a PHP
	 * cast artifact.
	 *
	 * @since 8.0.19
	 * @since 8.0.43 $skip_flags (CBT-868).
	 *
	 * @param array $value      Meta value array.
	 * @param bool  $skip_flags Skip flag-like leaves (bool, 0, '0').
	 *
	 * @return string
	 */
	private static function first_scalar_string( array $value, bool $skip_flags = false ): string {
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				if ( $skip_flags && self::is_flag( $item ) ) {
					continue;
				}
				$item_string = self::scalar_string( $item );
				if ( '' !== trim( $item_string ) ) {
					return $item_string;
				}
				continue;
			}
			if ( is_array( $item ) ) {
				$found = self::first_scalar_string( $item, $skip_flags );
				if ( '' !== $found ) {
					return $found;
				}
			}
		}

		return '';
	}

	/**
	 * The value stored under a key named like the meta itself, at any depth
	 * (`_product_video` inside the `_product_video` meta; the leading
	 * underscore is optional on either side). '' when no such key holds a
	 * usable value.
	 *
	 * @since 8.0.43
	 *
	 * @param array  $value    Meta value array.
	 * @param string $meta_key Meta key being resolved.
	 *
	 * @return string
	 */
	private static function named_scalar_string( array $value, string $meta_key ): string {
		$wanted = ltrim( $meta_key, '_' );
		if ( '' === $wanted ) {
			return '';
		}

		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && ltrim( $key, '_' ) === $wanted ) {
				if ( is_scalar( $item ) ) {
					$item_string = self::scalar_string( $item );
					if ( '' !== trim( $item_string ) ) {
						return $item_string;
					}
				} elseif ( is_array( $item ) ) {
					$found = self::first_scalar_string( $item );
					if ( '' !== $found ) {
						return $found;
					}
				}
			}
		}

		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$found = self::named_scalar_string( $item, $meta_key );
				if ( '' !== $found ) {
					return $found;
				}
			}
		}

		return '';
	}

	/**
	 * Whether a leaf is a flag rather than content: a boolean, or zero
	 * (0, 0.0, '0').
	 *
	 * @since 8.0.43
	 *
	 * @param mixed $item Scalar leaf.
	 *
	 * @return bool
	 */
	private static function is_flag( $item ): bool {
		if ( is_bool( $item ) ) {
			return true;
		}
		if ( is_int( $item ) || is_float( $item ) ) {
			return 0 == $item; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual, WordPress.PHP.StrictComparisons.LooseComparison -- 0 and 0.0 alike.
		}

		return '0' === trim( (string) $item );
	}

	/**
	 * A scalar leaf as text; a float as machine text (CBT-816).
	 *
	 * @since 8.0.43
	 *
	 * @param mixed $item Scalar leaf.
	 *
	 * @return string
	 */
	private static function scalar_string( $item ): string {
		return is_float( $item ) ? NumberFormat::to_machine( $item ) : (string) $item;
	}
}
