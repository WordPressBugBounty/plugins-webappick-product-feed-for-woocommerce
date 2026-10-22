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
	 * Config the lookups below were built from (held by reference so its
	 * identity cannot be recycled while the memo lives). Config has no
	 * setters, so one feed's lookups are built once, not per product
	 * (CBT-854).
	 *
	 * @since 8.0.40
	 * @var Config|null
	 */
	private $memo_config = null;

	/**
	 * `[ [ prefixes, by_row ], [ suffixes, by_row ] ]` for {@see $memo_config}.
	 *
	 * @since 8.0.40
	 * @var array
	 */
	private $memo_lookups = array();

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
		if ( $config !== $this->memo_config ) {
			$this->memo_config  = $config;
			$this->memo_lookups = array(
				$this->build_lookup( (array) $config->get( 'prefix', array() ), $config ),
				$this->build_lookup( (array) $config->get( 'suffix', array() ), $config ),
			);
		}
		list( $prefixes, $prefix_by_row ) = $this->memo_lookups[0];
		list( $suffixes, $suffix_by_row ) = $this->memo_lookups[1];

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

			// Positional configs are keyed per ROW (`name`, `name##2`, …), so a
			// repeated merchant attribute keeps its own decorator (CBT-847).
			// Name-keyed configs (fixtures, filters) apply to every repeat.
			if ( $prefix_by_row && $suffix_by_row ) {
				$prefix_key = (string) $attr;
				$suffix_key = $prefix_key;
			} else {
				$base       = ProductRepository::strip_dup_suffix( (string) $attr );
				$prefix_key = $prefix_by_row ? (string) $attr : $base;
				$suffix_key = $suffix_by_row ? (string) $attr : $base;
			}

			// `'' !==` instead of empty(): '0' and whitespace-only
			// decorators are legitimate (space-preservation contract).
			if ( isset( $prefixes[ $prefix_key ] ) && '' !== $prefixes[ $prefix_key ] ) {
				$value = $prefixes[ $prefix_key ] . $value;
			}

			if ( isset( $suffixes[ $suffix_key ] ) && '' !== $suffixes[ $suffix_key ] ) {
				$value .= $this->currency_spaced_suffix( $suffixes[ $suffix_key ] );
			}
		}

		return $product_data;
	}

	/**
	 * Prepend the space every money format mandates when the suffix is a
	 * bare currency code (CBT-575 — V5 parity restored).
	 *
	 * V5's process_prefix_suffix() carried exactly this heal: a suffix
	 * found in get_woocommerce_currencies() was appended as ' ' . $suffix
	 * ("Add space before suffix if attribute contain price"), which is why
	 * V5 shipped "35.90 RON" even from configs whose ' RON' suffix the
	 * save path had trimmed. The V8 rewrite dropped the branch, and every
	 * such config started shipping "35.90RON" — rejected by OpenAI and
	 * wrong on Google too ("62.25AUD", #69086). Decoration runs before ANY
	 * template renders, so CSV/TSV/TXT/XML all heal, on every channel.
	 *
	 * Divergence from V5, on purpose: a suffix that already carries its
	 * own leading whitespace (' RON', the correct post-PROD-FRD-10.12
	 * shape) is appended verbatim — V5 would have double-spaced it.
	 * Without WooCommerce loaded, a bare 3-letter uppercase code is the
	 * fallback heuristic.
	 *
	 * @since 8.0.19
	 *
	 * @param string $suffix Configured suffix.
	 *
	 * @return string Suffix to append, currency-spaced when applicable.
	 */
	private function currency_spaced_suffix( string $suffix ): string {
		if ( ltrim( $suffix ) !== $suffix ) {
			return $suffix;
		}

		$is_currency = function_exists( 'get_woocommerce_currencies' )
			? array_key_exists( trim( $suffix ), get_woocommerce_currencies() )
			: 1 === preg_match( '/^[A-Z]{3}$/', trim( $suffix ) );

		return $is_currency ? ' ' . $suffix : $suffix;
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
	 * @return array{0: array<string,string>, 1: bool} Key → decorator text,
	 *         and whether the keys are per-row product-data keys.
	 */
	private function build_lookup( array $items, Config $config ): array {
		if ( empty( $items ) ) {
			return array( array(), false );
		}

		// Name-keyed already → use as-is.
		$keys            = array_keys( $items );
		$is_zero_indexed = range( 0, count( $items ) - 1 ) === $keys;
		if ( ! $is_zero_indexed ) {
			return array( $items, false );
		}

		// Positional parallel-array shape → one entry per ROW, keyed like the
		// resolved values (CBT-847).
		$row_keys = ProductRepository::row_keys( (array) $config->get_merchant_attributes() );
		$lookup   = array();
		foreach ( $row_keys as $i => $key ) {
			if ( '' !== $key && isset( $items[ $i ] ) && '' !== $items[ $i ] ) {
				$lookup[ $key ] = (string) $items[ $i ];
			}
		}

		return array( $lookup, true );
	}
}
