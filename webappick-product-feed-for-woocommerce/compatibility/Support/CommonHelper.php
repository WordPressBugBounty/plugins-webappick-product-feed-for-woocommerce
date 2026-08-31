<?php
/**
 * CommonHelper — text/product helpers for the compat layer.
 *
 * Native (V5-free) replacement for the `CTXFeed\V5\Helper\CommonHelper`
 * methods the rewritten shims depend on: `parent_product_id()`,
 * `remove_shortcodes()`, `strip_invalid_xml()`, `strip_all_tags()`.
 *
 * @package CTXFeed\Compat\Support
 * @since   8.0.0
 */

namespace CTXFeed\Compat\Support;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Common helper.
 *
 * @since 8.0.0
 */
class CommonHelper {

	/**
	 * Get the meta-bearing product ID (parent for variations).
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @return int
	 */
	public static function parent_product_id( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return 0;
		}

		return $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
	}

	/**
	 * Strip WordPress shortcodes and shortcode-like tokens from content.
	 *
	 * @since 8.0.0
	 *
	 * @param string $content Raw content.
	 * @return string
	 */
	public static function remove_shortcodes( $content ) {
		if ( '' === $content ) {
			return '';
		}

		$content = strip_shortcodes( do_shortcode( $content ) );
		$content = self::strip_invalid_xml( $content );

		// Target residual shortcode-like patterns.
		$expression = '/\[\/*[č?a-zA-Z1-90_| -=\'"\{\}]*\/*\]/m';

		return preg_replace( $expression, '', $content );
	}

	/**
	 * Remove characters that are invalid in XML documents.
	 *
	 * @since 8.0.0
	 *
	 * @param string|int|float $value Value to clean.
	 * @return string|int|float
	 */
	public static function strip_invalid_xml( $value ) {
		$ret = '';

		if ( empty( $value ) ) {
			return $ret;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		$length = strlen( $value );
		for ( $i = 0; $i < $length; $i++ ) {
			$current = ord( $value[ $i ] );
			if (
				( 0x9 === $current )
				|| ( 0xA === $current )
				|| ( 0xD === $current )
				|| ( $current >= 0x20 && $current <= 0xD7FF )
				|| ( $current >= 0xE000 && $current <= 0xFFFD )
				|| ( $current >= 0x10000 && $current <= 0x10FFFF )
			) {
				$ret .= chr( $current );
			}
		}

		return $ret;
	}

	/**
	 * Strip all HTML tags (tolerating WP_Error input).
	 *
	 * @since 8.0.0
	 *
	 * @param string|\WP_Error $value Value to strip.
	 * @return string
	 */
	public static function strip_all_tags( $value ) {
		if ( $value instanceof \WP_Error ) {
			return '';
		}

		return wp_strip_all_tags( $value );
	}
}
