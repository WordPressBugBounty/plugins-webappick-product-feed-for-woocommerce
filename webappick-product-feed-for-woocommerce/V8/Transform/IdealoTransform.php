<?php
/**
 * IdealoTransform — Applies Idealo-specific formatting rules.
 *
 * - Price: 2 decimals, "." decimal separator, no thousand separator — a
 *   FIXED format: {@see fixed_number_format()} forces it on the
 *   `ctx_feed_number_format` filter at priority 20, over the Pro Filters-tab
 *   boxes (V5 `Override/IdealoTemplate.php:18` parity, CBT-811)
 * - Images: semicolon separator instead of comma
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Idealo transform.
 *
 * @since 8.0.0
 */
class IdealoTransform implements TransformInterface {

	/**
	 * Transform product data for Idealo compliance.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Transformed product data.
	 */
	public function transform( array $product_data, Config $config ): array {
		$provider = $config->get( 'provider', '' );

		if ( 'idealo' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_prices( $product_data );
		$product_data = $this->transform_images( $product_data );

		return $product_data;
	}

	/**
	 * Idealo's fixed number format: 2 decimals, "." decimal, no thousands.
	 *
	 * Hooked on the V5 `ctx_feed_number_format` filter at priority 20 (after
	 * the Pro engine's Filters-tab boxes at 10) by
	 * `TransformServiceProvider::boot()`. V5 registered the same override in
	 * `Override/IdealoTemplate.php:18`; without it the Pro boxes "," / "."
	 * turned 19.99 into "19,99", which {@see transform_prices()} then read as
	 * 1999 (`1999.00 EUR`, CBT-809 C2). Any other provider, or a call without
	 * a feed Config, is returned untouched.
	 *
	 * @since 8.0.39
	 * @implements XFRM-FRD-4.6 (codes 6/7 on idealo), CBT-811
	 *
	 * @param mixed $number_format { decimals, decimal_separator, thousand_separator }.
	 * @param mixed $config        Feed configuration (Config), or anything else → untouched.
	 *
	 * @return mixed The fixed triple on an idealo feed, otherwise $number_format.
	 */
	public static function fixed_number_format( $number_format, $config = null ) {
		if ( ! $config instanceof Config || 'idealo' !== $config->get_provider() ) {
			return $number_format;
		}

		return array_merge(
			(array) $number_format,
			array(
				'decimals'           => 2,
				'decimal_separator'  => '.',
				'thousand_separator' => '',
			)
		);
	}

	/**
	 * Reformat price fields for Idealo.
	 *
	 * Idealo requires: 2 decimals, dot separator, no thousand separator.
	 * A machine-format number is formatted directly ("19.99 EUR" stays
	 * "19.99 EUR", "1299 EUR" → "1299.00 EUR"). A value that is not a
	 * machine number is left as the row produced it (CBT-833), except an
	 * unambiguous comma grouping ("1,000.50 EUR" → "1000.50 EUR"): the old
	 * fallback stripped every comma and space and could not tell a decimal
	 * comma, so "19,99" shipped as "1999.00". Price rows use the
	 * fixed Idealo number format ({@see fixed_number_format()}), so they
	 * always arrive as machine numbers.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	private function transform_prices( array $data ): array {
		$price_attrs = array(
			'price',
			'regular_price',
			'sale_price',
			'price_with_tax',
			'regular_price_with_tax',
			'sale_price_with_tax',
			'price_excluding_tax',
		);

		foreach ( $price_attrs as $attr ) {
			if ( empty( $data[ $attr ] ) ) {
				continue;
			}

			$value = $data[ $attr ];

			// Extract currency suffix if present (e.g., " EUR").
			$currency = '';
			if ( preg_match( '/\s+([A-Z]{3})$/', $value, $matches ) ) {
				$currency = ' ' . $matches[1];
				$value    = preg_replace( '/\s+[A-Z]{3}$/', '', $value );
			}

			if ( ! is_numeric( $value ) ) {
				// Only an UNAMBIGUOUS comma grouping ("1,000.50", "12,345") is
				// unwrapped. Anything else (a custom field's "19,99", a
				// formatted "1 299,00") is left exactly as the row produced
				// it: stripping every comma guessed a decimal comma as
				// grouping, so "19,99" shipped as "1999.00" — 100x (CBT-833).
				if ( 1 !== preg_match( '/^\d{1,3}(?:,\d{3})+(?:\.\d+)?$/', $value ) ) {
					continue;
				}
				$value = str_replace( ',', '', $value );
			}

			// A machine-format number: 2 decimals, "." (CBT-811).
			$data[ $attr ] = number_format( (float) $value, 2, '.', '' ) . $currency;
		}

		return $data;
	}

	/**
	 * Replace comma with semicolon in image attribute separators.
	 *
	 * Idealo uses semicolon-separated image URLs.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	private function transform_images( array $data ): array {
		$image_attrs = array( 'additional_image_link', 'images' );

		foreach ( $image_attrs as $attr ) {
			if ( ! empty( $data[ $attr ] ) && is_string( $data[ $attr ] ) ) {
				$data[ $attr ] = str_replace( ',', ';', $data[ $attr ] );
			}
		}

		return $data;
	}
}
