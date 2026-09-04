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
	 * Config object the memoised format below was resolved from.
	 *
	 * Holding the reference (not just an id) means PHP cannot recycle the
	 * object id for a different Config while the memo is alive.
	 *
	 * @since 8.0.11
	 * @var Config|null
	 */
	private $memo_config = null;

	/**
	 * Memoised resolved number format: [ decimals, dec_sep, thou_sep ].
	 *
	 * The format depends only on feed config + store settings, both fixed
	 * for the life of a batch, so it is resolved once per Config instead of
	 * once per product (wc_get_price_*() are option lookups).
	 *
	 * @since 8.0.11
	 * @var array|null
	 */
	private $memo_format = null;

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
		if ( $config !== $this->memo_config ) {
			$this->memo_config = $config;
			$this->memo_format = $this->resolve_format( $config );
		}
		list( $decimals, $dec_sep, $thou_sep ) = $this->memo_format;

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
			if ( ! isset( $product_data[ $attr ] ) ) {
				continue;
			}

			// Parse BEFORE formatting so this stage is idempotent. The value
			// has usually ALREADY been formatted by PriceResolver with the
			// same config — a bare (float) cast read the thousand separator
			// as a decimal point ("1.499" → 1.499 → "1"), shipping a
			// 1 499 SEK product to Google as 1 SEK (support #68878).
			// @implements XFRM-FRD-5.3.
			$parsed = \CTXFeed\V8\Utility\LocalizedNumber::parse( $product_data[ $attr ], $dec_sep, $thou_sep );
			if ( null === $parsed ) {
				continue; // Not a number in any accepted form — leave untouched.
			}

			$product_data[ $attr ] = number_format( $parsed, $decimals, $dec_sep, $thou_sep );
		}

		return $product_data;
	}

	/**
	 * Resolve the effective number format from feed config.
	 *
	 * V5-compatible keys — user-entered values override WC defaults, but an
	 * EMPTY STRING means "use the WooCommerce default". A blank feed field is
	 * persisted as '' (the key is present), so Config::get()'s default
	 * argument never fires; casting that '' directly would make (int) '' === 0
	 * and round every price to a whole number. Guard the empty string first,
	 * exactly as PriceResolver::format_price() and OutputTypeTransform do.
	 *
	 * @since 8.0.11
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return array{0:int,1:string,2:string} Decimals, decimal separator, thousand separator.
	 */
	private function resolve_format( Config $config ): array {
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

		return array( $decimals, $dec_sep, $thou_sep );
	}
}
