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

	use AppendsCurrencyTrait;

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
		$product_data = $this->transform_availability_date( $product_data );
		$product_data = $this->transform_color_size( $product_data );

		// Google money format "<number> <ISO-4217>" (e.g. "48.00 USD").
		// PriceResolver emits bare numbers — the channel owns the format.
		$product_data = $this->append_currency( $product_data, $config, array( 'price', 'sale_price' ) );

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
