<?php
/**
 * V5CustomFieldResolver — V5-faithful resolver for `woo_feed_*` identifier
 * custom fields.
 *
 * Verbatim port of `V5\Helper\ProductHelper::get_custom_field()` (V5 line 253),
 * which 70K+ Pro customers' feeds depend on. Bare `MetaResolver::resolve()`
 * misses four V5 lookups, so variation feeds export empty for the per-variation
 * value the user typed in the variation panel, and pre-meta-key-migration
 * customers (data still under `woo_feed_identifier_*`) export empty too.
 *
 * V5 lookup chain (preserved here):
 *   1. Strip `wf_cattr_` prefix if present (POST_META_PREFIX) — used when the
 *      admin picks a custom field via the "Custom field" attribute dropdown.
 *   2. Append `_var` suffix for variations (skip if field already has `_var`
 *      or `_cattr` was the original prefix).
 *   3. Apply `woo_feed_custom_field_meta` filter (third-party remap hook).
 *   4. Try TWO meta keys in parallel:
 *        - new key: `_identifier`-stripped variant (e.g. `woo_feed_gtin_var`)
 *        - old key: `_identifier_`-prefixed variant (legacy pre-migration data)
 *      Prefer new; fall back to old.
 *   5. Variation→parent meta fallback: if `_var` value empty for a variation,
 *      retry WITHOUT `_var` suffix on the variation (to catch the value cached
 *      from the parent product).
 *   6. Special date formatting: `availability_date` → ISO 8601 via
 *      `date('c', strtotime($value))`.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-10.2
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * V5-compat custom-field resolver.
 *
 * @since 8.0.0
 */
class V5CustomFieldResolver {

	/**
	 * V5 POST_META_PREFIX — admin-side dropdown "Custom field" picker prefix.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const POST_META_PREFIX = 'wf_cattr_';

	/**
	 * V5 PRODUCT_CUSTOM_IDENTIFIER — the canonical identifier-meta prefix
	 * used by V5\CustomFields\InputCustomFiled (admin) and the V5
	 * ProductHelper retrieval path.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const PRODUCT_CUSTOM_IDENTIFIER = 'woo_feed_';

	/**
	 * Resolve a V5-style custom field value for a product.
	 *
	 * Routes through the same six-step lookup V5 used. Drop-in replacement
	 * for `MetaResolver::resolve()` when the attribute is a V5 custom-field
	 * key (woo_feed_* or wf_cattr_woo_feed_*).
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product (may be a variation).
	 * @param string      $field   V5 attribute key (e.g. `woo_feed_gtin`).
	 * @param Config      $config  Feed configuration (forwarded to filter hooks).
	 *
	 * @return string Resolved custom field value, possibly date-formatted.
	 */
	public function resolve( \WC_Product $product, string $field, Config $config ): string {
		$field_name = $field;

		// Step 1 — Strip wf_cattr_ admin-dropdown prefix if present.
		if ( false !== strpos( $field, '_cattr' ) ) {
			$field = str_replace( self::POST_META_PREFIX, '', $field );
		}

		// Step 2 — Variation suffix logic.
		// Skip the _var append if the key already contains _var (caller knows
		// what they're doing). Otherwise variations get the _var-suffixed key.
		if ( false !== strpos( $field, '_var' ) ) {
			$meta_key = $field;
		} else {
			$meta_key = $product->is_type( 'variation' )
				? $field . '_var'
				: $field;
		}

		// Step 3 — Allow third-party remap of the resolved meta key.
		// V5 hook name preserved verbatim.
		$meta_key = apply_filters( 'woo_feed_custom_field_meta', $meta_key, $product, $config );

		// Step 4 — Build the new/old key pair.
		// V5 logic: if meta_key contains '_identifier' it's already the legacy
		// shape, so strip it for the new key. Otherwise meta_key IS the new
		// key, and the old key is the `woo_feed_` → `woo_feed_identifier_`
		// expanded form.
		if ( false !== strpos( $meta_key, '_identifier' ) ) {
			$new_meta_key = str_replace( '_identifier', '', $meta_key );
			$old_meta_key = $meta_key;
		} else {
			$new_meta_key = $meta_key;
			$old_meta_key = str_replace( 'woo_feed_', 'woo_feed_identifier_', $meta_key );
		}

		// Try the new key first, then the old key.
		$new_meta_value = $this->get_product_meta( $new_meta_key, $product, $config );
		$old_meta_value = $this->get_product_meta( $old_meta_key, $product, $config );

		// Step 5 — Variation→parent fallback: if the per-variation _var key
		// has no value, retry on the variation WITHOUT _var. The variation's
		// own meta cache will return the parent's cached value in many cases.
		// (V5 only does this when no _cattr was in the original key).
		if ( false === strpos( $field_name, '_cattr' ) ) {
			if ( empty( $new_meta_value ) && $product->is_type( 'variation' ) ) {
				$fallback_key   = str_replace( '_var', '', $new_meta_key );
				$new_meta_value = $this->get_product_meta( $fallback_key, $product, $config );
			}
		}

		// Step 6 — Resolve the winning value (prefer new, fall back to old)
		// then run through availability_date formatting.
		$value = empty( $new_meta_value ) ? $old_meta_value : $new_meta_value;

		return $this->format_value( (string) $value, $meta_key );
	}

	/**
	 * Retrieve a meta value with V5's variation→parent walk.
	 *
	 * If the variation has no value for the given key, walk to the parent
	 * product. This is the same fallback `V5\Helper\ProductHelper::get_product_meta`
	 * implements, minus RankMath + taxonomy branches that don't apply to
	 * the woo_feed_* identifier path.
	 *
	 * @since 8.0.0
	 *
	 * @param string      $meta_key Meta key.
	 * @param \WC_Product $product  Product (may be variation).
	 * @param Config      $config   Feed configuration.
	 *
	 * @return string Meta value (filtered through V5 hook).
	 */
	private function get_product_meta( string $meta_key, \WC_Product $product, Config $config ): string {
		if ( '' === $meta_key ) {
			return '';
		}

		$value = get_post_meta( $product->get_id(), $meta_key, true );

		// Variation→parent walk if empty.
		if ( empty( $value ) && $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				$value = get_post_meta( $parent_id, $meta_key, true );
			}
		}

		// V5-compat hook — same name V5 fired so existing extensions keep working.
		return (string) apply_filters( 'woo_feed_filter_product_meta', $value, $product, $config );
	}

	/**
	 * Format the value based on its meta key.
	 *
	 * Currently only `availability_date` gets ISO 8601 (RFC 3339) formatting,
	 * matching V5's `format_custom_field_value()`. Anything else returns
	 * unchanged.
	 *
	 * @since 8.0.0
	 *
	 * @param string $value    Raw meta value.
	 * @param string $meta_key Meta key (used to detect availability_date).
	 *
	 * @return string Formatted value.
	 */
	private function format_value( string $value, string $meta_key ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( false === strpos( $meta_key, 'availability_date' ) ) {
			return $value;
		}

		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			// V5 returns the raw value when strtotime fails — preserve.
			return $value;
		}

		// V5 uses gmdate-equivalent `date('c', ...)`. We use gmdate to keep
		// output deterministic across server timezones — both produce a valid
		// ISO 8601 timestamp Google accepts.
		return gmdate( 'c', $timestamp );
	}

	/**
	 * Detect whether an attribute key should route through V5 custom-field
	 * resolution.
	 *
	 * Returns true for any key that:
	 *   - starts with `woo_feed_` (PRODUCT_CUSTOM_IDENTIFIER), OR
	 *   - starts with `wf_cattr_woo_feed_` (admin dropdown picker prefix).
	 *
	 * @since 8.0.0
	 *
	 * @param string $attr Attribute key.
	 *
	 * @return bool True if the key needs V5 custom-field handling.
	 */
	public static function handles( string $attr ): bool {
		if ( 0 === strpos( $attr, self::PRODUCT_CUSTOM_IDENTIFIER ) ) {
			return true;
		}
		if ( 0 === strpos( $attr, self::POST_META_PREFIX . self::PRODUCT_CUSTOM_IDENTIFIER ) ) {
			return true;
		}
		return false;
	}
}
