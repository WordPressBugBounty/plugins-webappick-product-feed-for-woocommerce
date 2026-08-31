<?php
/**
 * StringTransform — Applies string operations to attribute values.
 *
 * Supports uppercase, lowercase, trim, ucfirst, strip_tags, character limit,
 * and shortcode removal. Operations are configurable per-attribute via the
 * `output_type` config array.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements XFRM-FRD-4.1, XFRM-FRD-4.2, XFRM-FRD-4.3, XFRM-FRD-4.4, XFRM-FRD-4.5
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Product\ProductRepository;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * String transform.
 *
 * @since 8.0.0
 */
class StringTransform implements TransformInterface {

	/**
	 * Apply string operations to product attributes.
	 *
	 * Reads `output_type` per-attribute config for operation type,
	 * per-attribute `strip_tags_` override, per-attribute `limit_` for
	 * character truncation, and global `remove_shortcodes` flag.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-4.1
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Product data with string operations applied.
	 */
	public function transform( array $product_data, Config $config ): array {
		$output_types = $config->get( 'output_type', array() );

		// V5-compatible string-replace rules: array of { subject, search, replace }.
		$str_replace_rules = (array) $config->get( 'str_replace', array() );

		// Build a merchant-attribute → source-product-attribute lookup so the
		// str_replace `subject` can be matched against the ORIGINAL product
		// attribute (the thing user picks in the UI: "title", "wf_attr_pa_color",
		// "wp_attr_mapping_author", "wf_dattribute_foo", etc.). This mirrors V5's
		// AttributeValueByType::process_output(), which passes $this->attribute
		// (the product attribute) into ProductHelper::str_replace(), not the
		// merchant key. Without this, rules on dynamic attributes / attribute
		// mappings silently failed to match because $product_data is keyed by
		// merchant attribute names like "g:title".
		$merchant_to_source = array();
		$mattributes        = (array) $config->get_merchant_attributes();
		$src_attributes     = (array) $config->get_attributes();
		foreach ( $mattributes as $i => $merchant_attr ) {
			if ( '' === $merchant_attr ) {
				continue;
			}
			$merchant_to_source[ $merchant_attr ] = isset( $src_attributes[ $i ] )
				? (string) $src_attributes[ $i ]
				: '';
		}

		foreach ( $product_data as $attr => &$value ) {
			// Skip non-string values. @implements XFRM-FRD-4.5.
			if ( ! is_string( $value ) ) {
				continue;
			}

			// Strip the ##N duplicate-position suffix so config lookups
			// keyed by the base merchant attribute still fire for the
			// second, third, … occurrences of the same triplet.
			// PROD-FRD-10.11.
			$lookup_attr = ProductRepository::strip_dup_suffix( (string) $attr );

			$type = isset( $output_types[ $lookup_attr ] ) ? $output_types[ $lookup_attr ] : '';

			// Apply output_type operation. @implements XFRM-FRD-4.1.
			switch ( $type ) {
				case 'uppercase':
					$value = mb_strtoupper( $value );
					break;

				case 'lowercase':
					$value = mb_strtolower( $value );
					break;

				case 'trim':
					$value = trim( $value );
					break;

				case 'ucfirst':
					$value = ucfirst( mb_strtolower( $value ) );
					break;

				case 'strip_tags':
					$value = wp_strip_all_tags( $value );
					break;
			}

			// Strip HTML tags via per-attribute config override. @implements XFRM-FRD-4.2.
			if ( 'strip_tags' !== $type && $config->get( 'strip_tags_' . $lookup_attr ) ) {
				$value = wp_strip_all_tags( $value );
			}

			// Apply V5-compatible string-replace rules. Match against the
			// source product attribute (title, wf_dattribute_*,
			// wp_attr_mapping_*, etc.) AND the merchant key, so rules fire
			// regardless of whether the user picked the source attribute
			// or the merchant attribute in the UI.
			if ( ! empty( $str_replace_rules ) ) {
				$source_attr = isset( $merchant_to_source[ $lookup_attr ] ) ? $merchant_to_source[ $lookup_attr ] : '';
				$value       = self::apply_str_replace_rules( $value, $lookup_attr, $source_attr, $str_replace_rules );
			}

			// Remove shortcodes (global setting). @implements XFRM-FRD-4.4.
			//
			// V5 parity: WP core `strip_shortcodes()` only removes
			// registered tags. Unregistered patterns (leftover from
			// deactivated plugins, e.g. `[old_plugin_widget]`) survive,
			// which is the bug a customer reported on "Remove ShortCodes".
			// V5's `CommonHelper::remove_shortcodes()` ran an additional
			// catch-all regex to strip ANY `[…]` pattern. Mirror the full
			// sequence so the global flag actually clears all bracket
			// content like V5 customers expect.
			// A merchant-authored JSON value (importer_address object) or
			// Meta's single-quoted internal_label list (['summer','trending'])
			// is bracket-wrapped like a shortcode — the V5 catch-all's
			// ` -=` range even matches the commas and quotes inside,
			// deleting the whole value. Valid JSON (directly, or after
			// swapping Meta's single quotes for double quotes) is never a
			// WP shortcode, so it bypasses the cleanup.
			$is_json = in_array( substr( ltrim( $value ), 0, 1 ), array( '[', '{' ), true )
				&& ( null !== json_decode( $value, true )
					|| null !== json_decode( str_replace( "'", '"', $value ), true ) );

			if ( $config->get( 'remove_shortcodes', true ) && '' !== $value && ! $is_json ) {
				if ( function_exists( 'do_shortcode' ) ) {
					$value = (string) do_shortcode( $value );
				}
				$value = strip_shortcodes( $value );
				$value = preg_replace(
					'/\[\/*[č?a-zA-Z1-90_| -=\'"\{\}]*\/*\]/m',
					'',
					$value
				) ?? $value;
			}

			// Character limit (applied last). @implements XFRM-FRD-4.3.
			// V5 parity: truncate on a word boundary (never split a word) and
			// strip the trailing whitespace — see word_limit().
			$limit = (int) $config->get( 'limit_' . $lookup_attr, 0 );

			if ( $limit > 0 ) {
				$value = self::word_limit( $value, $limit );
			}
		}

		return $product_data;
	}

	/**
	 * Truncate a string to a character limit on a WORD boundary (V5 parity).
	 *
	 * Mirrors V5's `woo_feed_filter_product_title` / `_description` backup
	 * loop (includes/helper.php): when the value is longer than the limit,
	 * back up from the limit to the previous space so a word is never cut in
	 * half, then drop the trailing whitespace. A single word longer than the
	 * limit has no earlier space to back up to, so it hard-cuts at the limit.
	 *
	 * @since 8.0.0
	 *
	 * @param string $value Value to truncate.
	 * @param int    $limit Maximum length in characters (<= 0 = no limit).
	 * @return string Truncated value.
	 */
	public static function word_limit( string $value, int $limit ): string {
		if ( $limit <= 0 || mb_strlen( $value ) <= $limit ) {
			return $value;
		}

		$cut  = mb_substr( $value, 0, $limit );
		$last = mb_strrpos( $cut, ' ' );
		if ( false !== $last && $last > 0 ) {
			$cut = mb_substr( $cut, 0, $last );
		}

		return rtrim( $cut );
	}

	/**
	 * Apply V5-compatible `str_replace` rules to a single attribute value.
	 *
	 * Mirrors V5 ProductHelper::str_replace() with one extension: the rule's
	 * `subject` matches against either the **source product attribute** (V5's
	 * behaviour — "title", "wf_dattribute_foo", "wp_attr_mapping_author",
	 * etc.) OR the **merchant attribute** (convenience: "g:title"). This
	 * means rules fire correctly for Attribute Mapping and Dynamic Attribute
	 * outputs, matching how V5 runs str_replace mid-resolution.
	 *
	 * If the `search` string contains a slash, plain str_replace is used;
	 * otherwise it's treated as a regex pattern with the /mi flags (multiline,
	 * case-insensitive) — same as V5.
	 *
	 * @since 8.0.0
	 *
	 * Public so Custom2Template can run the same V5-parity rules against
	 * `{attribute}` placeholder output (V5 Custom2Template applied
	 * ProductHelper::str_replace() to every attribute body — line 256).
	 *
	 * @param string $value            Resolved attribute value.
	 * @param string $merchant_attr    Merchant attribute key (e.g. 'g:title').
	 * @param string $source_attr      Source product attribute (e.g. 'title',
	 *                                 'wf_dattribute_foo'). May be empty for
	 *                                 pattern-typed mappings with no source.
	 * @param array  $rules            Array of { subject, search, replace } rows.
	 *
	 * @return string
	 */
	public static function apply_str_replace_rules( string $value, string $merchant_attr, string $source_attr, array $rules ): string {
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['subject'] ) ) {
				continue;
			}

			$subject = (string) $rule['subject'];
			$search  = isset( $rule['search'] ) ? (string) $rule['search'] : '';
			$replace = isset( $rule['replace'] ) ? (string) $rule['replace'] : '';

			$matches_source   = ( '' !== $source_attr ) && (
				$subject === $source_attr
				|| 'product_' . $source_attr === $subject // V5 PRODUCT_ATTRIBUTE_PREFIX.
			);
			$matches_merchant = $subject === $merchant_attr;

			if ( ! $matches_source && ! $matches_merchant ) {
				continue;
			}

			if ( '' === $search ) {
				continue;
			}

			if ( false === strpos( $search, '/' ) ) {
				// Treat as regex with /mi flags — same as V5.
				$pattern = '/' . stripslashes( $search ) . '/mi';
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden -- User-supplied pattern from feed config may be malformed; @ mutes the E_WARNING (V5 parity) and the null check below handles the failure.
				$replaced = @preg_replace( $pattern, $replace, $value );
				// preg_replace returns null on error; keep original value if so.
				if ( null !== $replaced ) {
					$value = $replaced;
				}
			} else {
				// Search contains a slash — fall back to plain str_replace.
				$value = str_replace( $search, $replace, $value );
			}
		}

		return $value;
	}
}
