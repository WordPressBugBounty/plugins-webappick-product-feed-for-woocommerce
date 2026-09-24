<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "wf" is an established V5-era prefix registered in phpcs.xml.dist; renaming would break 100K+ existing installs.
/**
 * CustomFieldResolver — Resolves custom field data from ACF, Toolset, and raw post meta.
 *
 * Detects which custom field plugin is active and uses its API.
 * Falls back to raw get_post_meta() when no plugin is detected.
 * The acf_fields_ / toolset_fields_ picker prefixes are Pro (CBT-652):
 * their engine lives in the Pro plugin and reuses the public static
 * normalisation helpers here.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-10.1
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom field resolver.
 *
 * @since 8.0.0
 */
class CustomFieldResolver {

	/**
	 * Resolve a custom field value for a product.
	 *
	 * Bare field names run the historic detection chain: ACF, then Toolset,
	 * then raw post meta. Arrays are joined with ", "; scalars are cast to
	 * string. The dedicated picker prefixes (acf_fields_ / toolset_fields_)
	 * are Pro groups: the router hands them to the Pro
	 * CustomFieldPrefixResolver engine (CBT-652), which reuses
	 * {@see normalize()}.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-10.1
	 *
	 * @param \WC_Product $product    WooCommerce product.
	 * @param string      $field_name Custom field name.
	 * @param Config      $config     Feed configuration.
	 *
	 * @return string Resolved field value or empty string.
	 */
	public function resolve( \WC_Product $product, string $field_name, Config $config ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $config is part of the shared resolver signature; AttributeResolver invokes every resolver uniformly.
		$product_id = $product->get_id();

		// 1. ACF (Advanced Custom Fields). get_field() falls back to raw
		// meta itself for keys ACF doesn't own, so no extra fallback here.
		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $field_name, $product_id );
		} elseif ( function_exists( 'types_render_field' ) ) {
			// 2. Toolset Types. types_render_field() only answers for slugs
			// Toolset owns — for anything else (a plain WP meta key mapped
			// as a custom field) it renders empty, so fall back to raw meta
			// rather than shipping an empty column.
			$value = types_render_field(
				$field_name,
				array( 'post_id' => $product_id )
			);
			if ( null === $value || '' === $value ) {
				$value = get_post_meta( $product_id, $field_name, true );
			}
		} else {
			// 3. Raw post meta fallback.
			$value = get_post_meta( $product_id, $field_name, true );
		}

		return self::normalize( $value );
	}

	/**
	 * Normalize a resolved value to the string the feed ships.
	 *
	 * Public static since 8.0.27 so the Pro prefix engine ships identical
	 * values (CBT-652).
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $value Resolved value.
	 *
	 * @return string
	 */
	public static function normalize( $value ): string {
		// Unset / blank only. `empty()` also swallowed '0', 0 and false —
		// an explicit ACF True/False "No" and a stored zero exported as
		// nothing, indistinguishable from an unfilled field (CBT-610 /
		// BUG-0103). V5 string-cast the raw value, so '0' survived there.
		if ( null === $value || '' === $value || array() === $value ) {
			return '';
		}

		// ACF True/False returns PHP booleans; (string) false is ''.
		// Export ACF's own stored representation so "No" is a value.
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_array( $value ) ) {
			// ACF Image / File (default "Array" return format) and Gallery
			// describe attachments as arrays carrying a `url` key — export
			// the URL(s), not a dump of every key and thumbnail size
			// (CBT-615 / BUG-0102). Any other array keeps the generic join.
			$urls = self::attachment_urls( $value );
			if ( null !== $urls ) {
				return implode( ',', $urls );
			}

			return implode( ', ', self::flatten( $value ) );
		}

		return (string) $value;
	}

	/**
	 * URL(s) from an ACF attachment-shaped array, or null when the array is
	 * not one.
	 *
	 * Accepts a single attachment record (`['ID' => …, 'url' => …, 'sizes'
	 * => …]`) → one URL, or a list of such records (Gallery) → one URL per
	 * member. A list is only treated as a gallery when EVERY member is an
	 * attachment record, so a checkbox/select list of plain values keeps
	 * the generic comma-join. The full-size `url` is used, never a size.
	 *
	 * @since 8.0.24
	 *
	 * @param array $value Resolved array value.
	 *
	 * @return string[]|null
	 */
	public static function attachment_urls( array $value ): ?array {
		if ( isset( $value['url'] ) && is_string( $value['url'] ) && '' !== $value['url'] ) {
			return array( $value['url'] );
		}

		if ( array() === $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			return null;
		}

		$urls = array();
		foreach ( $value as $member ) {
			if ( ! is_array( $member ) || ! isset( $member['url'] ) || ! is_string( $member['url'] ) || '' === $member['url'] ) {
				return null;
			}
			$urls[] = $member['url'];
		}

		return $urls;
	}

	/**
	 * Flatten a (possibly nested) value array to a list of strings
	 * (Toolset checkbox groups store nested arrays).
	 *
	 * @since 8.0.19
	 *
	 * @param array $value Value array.
	 *
	 * @return array List of scalar strings.
	 */
	public static function flatten( array $value ): array {
		$out = array();
		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$out = array_merge( $out, self::flatten( $item ) );
			} elseif ( is_scalar( $item ) ) {
				$out[] = (string) $item;
			}
		}
		return $out;
	}
}
