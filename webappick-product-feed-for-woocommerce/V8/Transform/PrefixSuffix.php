<?php
/**
 * PrefixSuffix — Adds prefix or suffix text to attribute values.
 *
 * Commonly used to add currency symbols, units, or branding text.
 * Reads per-attribute prefix/suffix arrays from feed config.
 * Empty values are skipped to prevent orphaned decorators.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements XFRM-FRD-3.1, XFRM-FRD-3.2, XFRM-FRD-3.3
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Product\ProductRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prefix/suffix transform.
 *
 * @since 8.0.0
 */
class PrefixSuffix implements TransformInterface {

	/**
	 * Apply prefix and suffix text to product attributes.
	 *
	 * Reads `prefix` and `suffix` config arrays (keyed by attribute name).
	 * Skips empty values and non-scalar types.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-3.1, XFRM-FRD-3.2
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Product data with prefixes/suffixes applied.
	 */
	public function transform( array $product_data, Config $config ): array {
		$prefixes = $this->build_lookup( (array) $config->get( 'prefix', array() ), $config );
		$suffixes = $this->build_lookup( (array) $config->get( 'suffix', array() ), $config );

		if ( empty( $prefixes ) && empty( $suffixes ) ) {
			return $product_data;
		}

		foreach ( $product_data as $attr => &$value ) {
			// Skip non-scalar values (arrays, objects). @implements XFRM-FRD-3.3.
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = (string) $value;

			// Skip empty values to prevent orphaned decorators. @implements XFRM-FRD-3.1.
			if ( '' === $value ) {
				continue;
			}

			// Strip the ##N duplicate-position suffix so repeated merchant
			// attributes (product_detail triplets) share the base entry.
			$lookup_attr = ProductRepository::strip_dup_suffix( (string) $attr );

			// `'' !==` instead of empty(): '0' and whitespace-only
			// decorators are legitimate (space-preservation contract).
			if ( isset( $prefixes[ $lookup_attr ] ) && '' !== $prefixes[ $lookup_attr ] ) {
				$value = $prefixes[ $lookup_attr ] . $value;
			}

			if ( isset( $suffixes[ $lookup_attr ] ) && '' !== $suffixes[ $lookup_attr ] ) {
				$value .= $suffixes[ $lookup_attr ];
			}
		}

		return $product_data;
	}

	/**
	 * Normalise a prefix/suffix config array into a merchant-attr-keyed map.
	 *
	 * V5 (and the V8 React UI) persist `prefix`/`suffix` as POSITIONAL
	 * arrays zipped with `mattributes` — same storage shape
	 * OutputTypeTransform::build_lookup() handles for `output_type`.
	 * A name-keyed array (used by some fixtures and external filters)
	 * passes through unchanged.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $items  Raw prefix or suffix config array.
	 * @param Config $config Feed configuration (for mattributes zip).
	 *
	 * @return array<string,string> Merchant attribute → decorator text.
	 */
	private function build_lookup( array $items, Config $config ): array {
		if ( empty( $items ) ) {
			return array();
		}

		// Name-keyed already → use as-is.
		$keys            = array_keys( $items );
		$is_zero_indexed = range( 0, count( $items ) - 1 ) === $keys;
		if ( ! $is_zero_indexed ) {
			return $items;
		}

		// Positional parallel-array shape → zip with mattributes.
		$mattributes = (array) $config->get_merchant_attributes();
		$lookup      = array();
		foreach ( $mattributes as $i => $attr ) {
			if ( isset( $items[ $i ] ) && '' !== $items[ $i ] ) {
				$lookup[ (string) $attr ] = (string) $items[ $i ];
			}
		}

		return $lookup;
	}
}
