<?php
/**
 * TrovaprezziTransform — Applies Trovaprezzi-specific formatting rules.
 *
 * Trovaprezzi (Italian price comparison) requires:
 * - Availability as numeric codes: 2 (in stock), 3 (out of stock)
 * - Price: comma decimal separator, no thousands, 2 decimals
 * - Category separator: comma instead of " > "
 * - XML wrappers: itemsWrapper = "Products", itemWrapper = "Offer"
 *
 * The end-of-record marker (`<endrecord>`) is handled by CSVTemplate
 * via the `ctxfeed_csv_row_suffix` filter, not in this transform.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @see        https://www.trovaprezzi.it
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trovaprezzi transform.
 *
 * @since 8.0.0
 */
class TrovaprezziTransform implements TransformInterface {

	/**
	 * Transform product data for Trovaprezzi compliance.
	 *
	 * No-ops for non-Trovaprezzi providers.
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

		if ( 'trovaprezzi' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_availability( $product_data );
		$product_data = $this->transform_price( $product_data );
		$product_data = $this->transform_category( $product_data );

		return $product_data;
	}

	/**
	 * Convert availability to Trovaprezzi numeric codes.
	 *
	 * V5 parity: 2 = in stock, 3 = out of stock.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified data.
	 */
	private function transform_availability( array $data ): array {
		if ( ! isset( $data['availability'] ) ) {
			return $data;
		}

		$availability = strtolower( str_replace( array( '_', '-' ), '', trim( $data['availability'] ) ) );

		switch ( $availability ) {
			case 'instock':
			case 'in stock':
				$data['availability'] = '2';
				break;
			default:
				$data['availability'] = '3';
				break;
		}

		return $data;
	}

	/**
	 * Format prices for Trovaprezzi.
	 *
	 * V5 parity: comma decimal, no thousands separator, 2 decimal places.
	 * Example: "1234,50"
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified data.
	 */
	private function transform_price( array $data ): array {
		$price_keys = array(
			'price',
			'current_price',
			'sale_price',
			'regular_price',
			'ShippingCost',
		);

		foreach ( $price_keys as $key ) {
			if ( empty( $data[ $key ] ) ) {
				continue;
			}

			$value = $data[ $key ];

			// Strip any existing currency symbols and whitespace.
			$value = preg_replace( '/[^\d.,\-]/', '', $value );

			// Normalize: remove existing thousands separators, convert decimal point.
			// Handle case where comma is already decimal (European input).
			if ( false !== strpos( $value, ',' ) && false !== strpos( $value, '.' ) ) {
				// Both present — period is thousands, comma is decimal (e.g., "1.234,50").
				$value = str_replace( '.', '', $value );
				$value = str_replace( ',', '.', $value );
			} elseif ( false !== strpos( $value, ',' ) ) {
				// Only comma — could be decimal.
				$parts = explode( ',', $value );
				if ( count( $parts ) === 2 && strlen( $parts[1] ) <= 2 ) {
					// Comma is decimal separator.
					$value = str_replace( ',', '.', $value );
				} else {
					// Comma is thousands separator.
					$value = str_replace( ',', '', $value );
				}
			}

			$numeric      = (float) $value;
			$data[ $key ] = number_format( $numeric, 2, ',', '' );
		}

		return $data;
	}

	/**
	 * Convert category path separator to comma.
	 *
	 * V5 parity: Trovaprezzi uses comma instead of " > " for category hierarchy.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified data.
	 */
	private function transform_category( array $data ): array {
		$cat_keys = array(
			'product_type',
			'product_cat',
			'categories',
			'product_full_cat',
			'Categories',
		);

		foreach ( $cat_keys as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$data[ $key ] = str_replace( ' > ', ', ', $data[ $key ] );
			}
		}

		return $data;
	}
}
