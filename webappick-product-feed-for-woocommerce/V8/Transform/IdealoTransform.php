<?php
/**
 * IdealoTransform — Applies Idealo-specific formatting rules.
 *
 * - Price: 2 decimals, "." decimal separator, no thousand separator
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
	 * Reformat price fields for Idealo.
	 *
	 * Idealo requires: 2 decimals, dot separator, no thousand separator.
	 * E.g., "1,000.50 EUR" → "1000.50 EUR".
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

			// Remove existing thousand separators and normalize decimal.
			$value = str_replace( array( ',', ' ' ), '', $value );

			// Format with dot decimal, no thousand separator.
			if ( is_numeric( $value ) ) {
				$value = number_format( (float) $value, 2, '.', '' );
			}

			$data[ $attr ] = $value . $currency;
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
