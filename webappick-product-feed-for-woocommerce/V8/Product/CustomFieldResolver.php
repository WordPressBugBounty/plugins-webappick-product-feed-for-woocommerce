<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "wf" is an established V5-era prefix registered in phpcs.xml.dist; renaming would break 100K+ existing installs.
/**
 * CustomFieldResolver — Resolves custom field data from ACF, Toolset, and raw post meta.
 *
 * Detects which custom field plugin is active and uses its API.
 * Falls back to raw get_post_meta() when no plugin is detected.
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
	 * Checks for ACF, then Toolset, then falls back to raw post meta.
	 * Arrays are joined with ", "; scalars are cast to string.
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
		$value      = null;

		// ACF fields are offered in the feed UI with the CTX Feed `acf_fields_`
		// dropdown prefix (e.g. `acf_fields_field_material`). ACF's get_field()
		// expects the BARE field key/name, so a prefixed key returns null and the
		// column ships empty. Strip the prefix before resolving; get_field() still
		// applies the field's return_format, so an image field returns its URL,
		// not the attachment ID.
		if ( 0 === strpos( $field_name, 'acf_fields_' ) ) {
			$field_name = substr( $field_name, strlen( 'acf_fields_' ) );
		}

		// 1. ACF (Advanced Custom Fields).
		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $field_name, $product_id );
		} elseif ( function_exists( 'types_render_field' ) ) {
			// 2. Toolset Types.
			$value = types_render_field(
				$field_name,
				array( 'post_id' => $product_id )
			);
		} else {
			// 3. Raw post meta fallback.
			$value = get_post_meta( $product_id, $field_name, true );
		}

		// Normalize to string.
		if ( empty( $value ) && false !== $value ) {
			return '';
		}

		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return (string) $value;
	}
}
