<?php
/**
 * PinterestTransform — Applies Pinterest-specific formatting rules.
 *
 * Enforces Pinterest Catalog requirements:
 * - Title max 150 characters
 * - Description max 9999 characters
 * - Availability: backorder → preorder
 * - Availability date: ISO 8601 format
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
 * Pinterest transform.
 *
 * @since 8.0.0
 */
class PinterestTransform implements TransformInterface {

	use AppendsCurrencyTrait;

	/**
	 * Pinterest title max length.
	 *
	 * @var int
	 */
	// Pinterest's documented maximum is 500 characters — truncating at
	// 150 discarded up to 350 chars of merchant titles.
	const TITLE_MAX_LENGTH = 500;

	/**
	 * Pinterest description max length.
	 *
	 * @var int
	 */
	const DESCRIPTION_MAX_LENGTH = 9999;

	/**
	 * Transform product data for Pinterest compliance.
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

		// Only apply to Pinterest (not pinterest_rss).
		if ( 'pinterest' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_title( $product_data, $config );
		$product_data = $this->transform_description( $product_data, $config );
		$product_data = $this->transform_availability( $product_data );
		$product_data = $this->transform_availability_date( $product_data );

		// Pinterest money format "<number> <ISO-4217>" (e.g. "48.00 USD").
		// PriceResolver emits bare numbers — the channel owns the format.
		$product_data = $this->append_currency( $product_data, $config, array( 'price', 'sale_price' ) );

		return $product_data;
	}

	/**
	 * Truncate title to Pinterest max length.
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

		$title = apply_filters( 'woo_feed_filter_product_title', $data['title'], $data, $config );

		if ( mb_strlen( $title ) > self::TITLE_MAX_LENGTH ) {
			$title = mb_substr( $title, 0, self::TITLE_MAX_LENGTH );
		}

		$data['title'] = $title;

		return $data;
	}

	/**
	 * Truncate description to Pinterest max length.
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

		$description = apply_filters( 'woo_feed_filter_product_description', $data['description'], $data, $config );

		if ( mb_strlen( $description ) > self::DESCRIPTION_MAX_LENGTH ) {
			$description = mb_substr( $description, 0, self::DESCRIPTION_MAX_LENGTH );
		}

		$data['description'] = $description;

		return $data;
	}

	/**
	 * Normalize availability for Pinterest.
	 *
	 * Pinterest uses space format: "in stock", "out of stock", "preorder".
	 * Maps "on backorder" → "preorder".
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
		$availability = str_replace( '_', ' ', $availability );

		$map = array(
			'in stock'     => 'in stock',
			'out of stock' => 'out of stock',
			'on backorder' => 'preorder',
			'backorder'    => 'preorder',
			'preorder'     => 'preorder',
		);

		if ( isset( $map[ $availability ] ) ) {
			$availability = $map[ $availability ];
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
}
