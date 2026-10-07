<?php
/**
 * BingTransform — Applies Microsoft Bing/Merchant Center formatting rules.
 *
 * - Availability date: ISO 8601 format
 * - Availability: underscore format (in_stock, out_of_stock)
 * - Shipping: simplified format for CSV/TXT (country:service:price, no region)
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
 * Bing transform.
 *
 * @since 8.0.0
 */
class BingTransform implements TransformInterface {

	/**
	 * Microsoft Merchant Center title limit (characters).
	 *
	 * @var int
	 */
	const TITLE_MAX_LENGTH = 150;

	/**
	 * Microsoft Merchant Center description limit (characters).
	 *
	 * @var int
	 */
	const DESCRIPTION_MAX_LENGTH = 10000;


	use FeedCurrencyFallbackTrait;


	/**
	 * Transform product data for Bing compliance.
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

		if ( 'bing' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_availability( $product_data );
		$product_data = $this->transform_availability_date( $product_data );
		$product_data = $this->transform_shipping( $product_data, $config );
		$product_data = $this->transform_identifier_exists( $product_data );
		$product_data = $this->cap_lengths( $product_data );

		// Price / sale_price = bare number + the row's configured suffix as
		// written; the feed currency is appended ONLY when the suffix is empty
		// and a multi-currency plugin provided that currency (CBT-602, owner).
		$product_data = $this->apply_feed_currency_fallback( $product_data, $config, array( 'price', 'sale_price' ) );

		return $product_data;
	}

	/**
	 * Normalize availability to underscore format (same as Google).
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	private function transform_availability( array $data ): array {
		if ( empty( $data['availability'] ) ) {
			return $data;
		}

		$availability = strtolower( trim( $data['availability'] ) );
		$availability = str_replace( ' ', '_', $availability );

		$map = array(
			'instock'      => 'in_stock',
			'outofstock'   => 'out_of_stock',
			'in_stock'     => 'in_stock',
			'out_of_stock' => 'out_of_stock',
			'preorder'     => 'preorder',
			// Microsoft accepts only in stock / out of stock / preorder; an
			// item taking orders for later delivery is "preorder" (owner,
			// CBT-714). `backorder` was rejected.
			'backorder'    => 'preorder',
			'onbackorder'  => 'preorder',
			'on_backorder' => 'preorder',
		);

		$normalized = str_replace( '-', '_', $availability );

		if ( isset( $map[ $normalized ] ) ) {
			$availability = $map[ $normalized ];
		}

		$data['availability'] = $availability;

		return $data;
	}

	/**
	 * Convert availability date to ISO 8601.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	private function transform_availability_date( array $data ): array {
		if ( empty( $data['availability_date'] ) ) {
			return $data;
		}

		$date = $data['availability_date'];

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T/', $date ) ) {
			return $data;
		}

		$timestamp = strtotime( $date );

		if ( false !== $timestamp ) {
			$data['availability_date'] = gmdate( 'c', $timestamp );
		}

		return $data;
	}

	/**
	 * Simplify shipping format for Bing.
	 *
	 * Bing uses country:service:price (no region field).
	 * Strips region from composite shipping strings in CSV/TXT feeds.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $data   Product data.
	 * @param Config $config Feed configuration.
	 *
	 * @return array Modified product data.
	 */
	private function transform_shipping( array $data, Config $config ): array {
		if ( empty( $data['shipping'] ) ) {
			return $data;
		}

		// Microsoft: shipping price is numeric — "12.99", no currency
		// (CBT-714). XML carries the entries as arrays.
		if ( is_array( $data['shipping'] ) ) {
			foreach ( $data['shipping'] as $i => $entry ) {
				if ( is_array( $entry ) && isset( $entry['price'] ) && is_scalar( $entry['price'] ) ) {
					$data['shipping'][ $i ]['price'] = self::bare_amount( (string) $entry['price'] );
				}
			}
			return $data;
		}

		// NB: the feedrules key is camelCase `feedType` — reading the
		// snake_case key returned the default forever, leaving this
		// whole reformat dead.
		$feed_type = strtolower( $config->get( 'feedType', 'xml' ) );

		// Only modify for CSV/TXT/TSV (string format). XML uses nested elements.
		if ( ! in_array( $feed_type, array( 'csv', 'txt', 'tsv' ), true ) ) {
			return $data;
		}

		// Parse composite shipping string: "country:region:service:price"
		// Convert to Bing format: "country:service:price".
		$shipping = $data['shipping'];
		$entries  = explode( ',', $shipping );
		$result   = array();

		foreach ( $entries as $entry ) {
			$parts = explode( ':', trim( $entry ) );

			if ( count( $parts ) >= 4 ) {
				// Remove region (index 1): country:service:price.
				$result[] = $parts[0] . ':' . $parts[2] . ':' . self::bare_amount( $parts[3] );
			} else {
				$result[] = trim( $entry );
			}
		}

		$data['shipping'] = implode( ',', $result );

		return $data;
	}

	/**
	 * A money value without its currency code ("6.49 USD" → "6.49").
	 *
	 * @since 8.0.32
	 *
	 * @param string $amount Amount.
	 * @return string
	 */
	private static function bare_amount( string $amount ): string {
		return trim( (string) preg_replace( '/\s*[A-Za-z]{3}\s*$/', '', trim( $amount ) ) );
	}

	/**
	 * Microsoft: identifier_exists is Boolean TRUE / FALSE (CBT-714).
	 *
	 * @since 8.0.32
	 *
	 * @param array $data Product data.
	 * @return array
	 */
	private function transform_identifier_exists( array $data ): array {
		if ( ! isset( $data['identifier_exists'] ) || ! is_scalar( $data['identifier_exists'] ) ) {
			return $data;
		}

		$value = strtolower( trim( (string) $data['identifier_exists'] ) );
		if ( in_array( $value, array( 'yes', 'y', 'true', '1' ), true ) ) {
			$data['identifier_exists'] = 'TRUE';
		} elseif ( in_array( $value, array( 'no', 'n', 'false', '0' ), true ) ) {
			$data['identifier_exists'] = 'FALSE';
		}

		return $data;
	}

	/**
	 * Microsoft limits: title 150, description 10000 characters (CBT-714).
	 *
	 * @since 8.0.32
	 *
	 * @param array $data Product data.
	 * @return array
	 */
	private function cap_lengths( array $data ): array {
		$limits = array(
			'title'       => self::TITLE_MAX_LENGTH,
			'description' => self::DESCRIPTION_MAX_LENGTH,
		);
		foreach ( $limits as $key => $max ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && mb_strlen( $data[ $key ] ) > $max ) {
				$data[ $key ] = mb_substr( $data[ $key ], 0, $max );
			}
		}

		return $data;
	}
}
