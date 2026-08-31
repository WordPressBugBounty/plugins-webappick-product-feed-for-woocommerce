<?php
/**
 * NumberTransform — Formats numeric values (prices, weights).
 *
 * Handles decimal separators, thousand separators, and rounding
 * using WooCommerce default settings with feed-config overrides.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements XFRM-FRD-5.1, XFRM-FRD-5.2, XFRM-FRD-5.3
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Number transform.
 *
 * @since 8.0.0
 */
class NumberTransform implements TransformInterface {

	/**
	 * Format numeric price attributes in product data.
	 *
	 * Reads decimal count, decimal separator, and thousand separator
	 * from feed config, falling back to WooCommerce store settings.
	 * Only formats attributes in the configurable price attribute list.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-5.1, XFRM-FRD-5.2
	 * @hook ctxfeed_number_transform_attributes Filter to override price attribute list.
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Product data with formatted numbers.
	 */
	public function transform( array $product_data, Config $config ): array {
		// V5-compatible keys — user-entered values override WC defaults, but an
		// EMPTY STRING means "use the WooCommerce default". A blank feed field is
		// persisted as '' (the key is present), so Config::get()'s default
		// argument never fires; casting that '' directly would make (int) '' === 0
		// and round every price to a whole number. Guard the empty string first,
		// exactly as PriceResolver::format_price() and OutputTypeTransform do.
		$decimals_raw = $config->get( 'decimals', '' );
		$dec_sep_raw  = $config->get( 'decimal_separator', '' );
		$thou_sep_raw = $config->get( 'thousand_separator', '' );

		$decimals = ( '' === $decimals_raw || null === $decimals_raw || ! is_numeric( $decimals_raw ) )
			? (int) wc_get_price_decimals()
			: (int) $decimals_raw;
		$dec_sep  = ( '' === $dec_sep_raw || null === $dec_sep_raw )
			? wc_get_price_decimal_separator()
			: wp_specialchars_decode( wp_unslash( $dec_sep_raw ) );
		$thou_sep = ( '' === $thou_sep_raw || null === $thou_sep_raw )
			? wc_get_price_thousand_separator()
			: wp_specialchars_decode( wp_unslash( $thou_sep_raw ) );

		$price_attrs = array(
			'price',
			'regular_price',
			'sale_price',
			'price_with_tax',
			'regular_price_with_tax',
			'sale_price_with_tax',
		);

		/**
		 * Filter the list of attributes that receive number formatting.
		 *
		 * @since 8.0.0
		 *
		 * @param string[] $price_attrs Attribute names to format.
		 * @param Config   $config      Feed configuration.
		 */
		$price_attrs = apply_filters( 'ctxfeed_number_transform_attributes', $price_attrs, $config );

		foreach ( $price_attrs as $attr ) {
			// Only format numeric values. @implements XFRM-FRD-5.3.
			if ( isset( $product_data[ $attr ] ) && is_numeric( $product_data[ $attr ] ) ) {
				$product_data[ $attr ] = number_format(
					(float) $product_data[ $attr ],
					$decimals,
					$dec_sep,
					$thou_sep
				);
			}
		}

		return $product_data;
	}
}
