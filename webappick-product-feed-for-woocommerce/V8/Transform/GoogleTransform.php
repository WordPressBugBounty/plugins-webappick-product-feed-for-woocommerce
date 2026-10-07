<?php
/**
 * GoogleTransform — Applies Google Shopping-specific formatting rules.
 *
 * Enforces Google Merchant Center requirements:
 * - Title max 150 characters
 * - Description max 5000 characters
 * - Availability with underscores (in_stock, out_of_stock, preorder)
 * - Color/size separator normalization to "/"
 * - availability_date ISO 8601 formatting
 *
 * Fires legacy V5 hooks for backward compatibility.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements G-03
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Shopping transform.
 *
 * @since 8.0.0
 */
class GoogleTransform implements TransformInterface {

	use FeedCurrencyFallbackTrait;


	/**
	 * Google title max length.
	 *
	 * @var int
	 */
	const TITLE_MAX_LENGTH = 150;

	/**
	 * Google description max length.
	 *
	 * @var int
	 */
	const DESCRIPTION_MAX_LENGTH = 5000;

	/**
	 * Transform product data for Google Shopping compliance.
	 *
	 * No-ops for non-Google providers.
	 *
	 * @since 8.0.0
	 * @implements G-03
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Transformed product data.
	 */
	public function transform( array $product_data, Config $config ): array {
		$provider = $config->get( 'provider', '' );

		// Only apply to Google providers — and Perplexity, whose Merchant
		// Program reuses the Google Shopping product spec verbatim.
		if ( 'google' !== substr( $provider, 0, 6 ) && 'perplexity' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_title( $product_data, $config );
		$product_data = $this->transform_description( $product_data, $config );
		$product_data = $this->transform_availability( $product_data );
		if ( 'google_local_inventory' === $provider ) {
			$product_data = $this->transform_local_availability( $product_data );
		}
		$product_data = $this->transform_availability_date( $product_data );
		$product_data = $this->transform_color_size( $product_data );
		$product_data = $this->transform_units( $product_data );

		// Price / sale_price = bare number + the row's configured suffix as
		// written; the feed currency is appended ONLY when the suffix is empty
		// and a multi-currency plugin provided that currency (CBT-602, owner).
		$product_data = $this->apply_feed_currency_fallback( $product_data, $config, array( 'price', 'sale_price' ) );

		return $product_data;
	}

	/**
	 * Truncate title to Google max length.
	 *
	 * Fires legacy hook: woo_feed_filter_product_title.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $data   Product data.
	 * @param Config $config Feed configuration.
	 *
	 * @return array Modified product data.
	 */
	private function transform_title( array $data, Config $config ): array {
		if ( empty( $data['title'] ) ) {
			return $data;
		}

		$title = $data['title'];

		// Fire legacy V5 hook for backward compatibility.
		$title = apply_filters( 'woo_feed_filter_product_title', $title, $data, $config );

		// Truncate to Google max.
		if ( mb_strlen( $title ) > self::TITLE_MAX_LENGTH ) {
			$title = mb_substr( $title, 0, self::TITLE_MAX_LENGTH );
		}

		$data['title'] = $title;

		return $data;
	}

	/**
	 * Truncate description to Google max length.
	 *
	 * Fires legacy hook: woo_feed_filter_product_description.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $data   Product data.
	 * @param Config $config Feed configuration.
	 *
	 * @return array Modified product data.
	 */
	private function transform_description( array $data, Config $config ): array {
		if ( empty( $data['description'] ) ) {
			return $data;
		}

		$description = $data['description'];

		// Fire legacy V5 hook.
		$description = apply_filters( 'woo_feed_filter_product_description', $description, $data, $config );

		// Truncate to Google max.
		if ( mb_strlen( $description ) > self::DESCRIPTION_MAX_LENGTH ) {
			$description = mb_substr( $description, 0, self::DESCRIPTION_MAX_LENGTH );
		}

		$data['description'] = $description;

		return $data;
	}

	/**
	 * Normalize availability to Google underscore format.
	 *
	 * Google requires: in_stock, out_of_stock, preorder, backorder.
	 * WC uses spaces: "in stock", "out of stock".
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

		// Replace spaces with underscores for Google format.
		$availability = str_replace( ' ', '_', $availability );

		// Normalize common variants.
		$map = array(
			'instock'      => 'in_stock',
			'outofstock'   => 'out_of_stock',
			'out_of_stock' => 'out_of_stock',
			'in_stock'     => 'in_stock',
			'preorder'     => 'preorder',
			'backorder'    => 'backorder',
		);

		$normalized = str_replace( '-', '_', $availability );

		if ( isset( $map[ $normalized ] ) ) {
			$availability = $map[ $normalized ];
		}

		$data['availability'] = $availability;

		return $data;
	}

	/**
	 * Local inventory availability vocabulary (CBT-713).
	 *
	 * Local inventory feeds accept only in_stock, limited_availability,
	 * on_display_to_order and out_of_stock — the online map's backorder /
	 * preorder are not valid there. A backordered or pre-order item cannot
	 * be picked up in the store today, so it is reported out_of_stock
	 * rather than advertised as available. Merchant-typed local values
	 * pass through.
	 *
	 * @since 8.0.32
	 *
	 * @param array $data Product data (online availability already normalised).
	 * @return array
	 */
	private function transform_local_availability( array $data ): array {
		if ( ! isset( $data['availability'] ) || ! is_string( $data['availability'] ) || '' === $data['availability'] ) {
			return $data;
		}

		$valid = array( 'in_stock', 'limited_availability', 'on_display_to_order', 'out_of_stock' );
		if ( ! in_array( $data['availability'], $valid, true ) ) {
			$data['availability'] = 'out_of_stock';
		}

		return $data;
	}

	/**
	 * Google unit tokens for weights and dimensions (CBT-715).
	 *
	 * Weights accept lb / oz / g / kg — WooCommerce's "lbs" is not one of
	 * them. Dimensions accept in / cm only — WooCommerce's m, mm and yd are
	 * converted. Applies to the `"<number> <unit>"` values the weight /
	 * length / width / height attributes produce, under Google's weight and
	 * dimension attribute names only; anything else is left alone.
	 *
	 * @since 8.0.32
	 *
	 * @param array $data Product data keyed by merchant attribute.
	 * @return array
	 */
	private function transform_units( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) || '' === $value ) {
				continue;
			}
			$name = (string) preg_replace( '/^g:/', '', $key );
			// Google's own template keys are weight / length / width / height
			// (→ g:shipping_*); product_* and shipping_* are picker variants.
			if ( ! preg_match( '/^(?:(?:shipping|product)_)?(weight|length|width|height)$/', $name, $m ) ) {
				continue;
			}
			if ( ! preg_match( '/^\s*(-?\d+(?:[.,]\d+)?)\s*([a-zA-Z]+)\s*$/', $value, $parts ) ) {
				continue;
			}
			$number = (float) str_replace( ',', '.', $parts[1] );
			$unit   = strtolower( $parts[2] );

			if ( 'weight' === $m[1] ) {
				if ( 'lbs' === $unit ) {
					$data[ $key ] = $parts[1] . ' lb';
				}
				continue;
			}

			$convert = array(
				'm'  => array( 100, 'cm' ),
				'mm' => array( 0.1, 'cm' ),
				'yd' => array( 36, 'in' ),
			);
			if ( isset( $convert[ $unit ] ) ) {
				$amount       = round( $number * $convert[ $unit ][0], 2 );
				$data[ $key ] = rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' ) . ' ' . $convert[ $unit ][1];
			}
		}

		return $data;
	}

	/**
	 * Ensure availability_date is ISO 8601 formatted.
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

		// If already ISO 8601, skip.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T/', $date ) ) {
			return $data;
		}

		// Try to parse and convert.
		$timestamp = strtotime( $date );

		if ( false !== $timestamp ) {
			$data['availability_date'] = gmdate( 'c', $timestamp );
		}

		return $data;
	}

	/**
	 * Normalize color and size separators for Google.
	 *
	 * Google uses "/" as multi-value separator for color and size.
	 * WC often stores as comma-separated.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	private function transform_color_size( array $data ): array {
		$attrs = array( 'color', 'size', 'material', 'pattern' );

		foreach ( $attrs as $attr ) {
			if ( ! empty( $data[ $attr ] ) ) {
				$data[ $attr ] = str_replace( array( ', ', ',' ), '/', $data[ $attr ] );
			}
		}

		return $data;
	}
}
