<?php
/**
 * SnapchatTransform — Applies Snapchat-specific formatting rules.
 *
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
 * Snapchat transform.
 *
 * @since 8.0.0
 */
class SnapchatTransform implements TransformInterface {

	use AppendsCurrencyTrait;

	// Channel spec limits (title hard cap, description cap).
	const TITLE_MAX_LENGTH       = 150;
	const DESCRIPTION_MAX_LENGTH = 5000;

	/**
	 * Transform product data for Snapchat compliance.
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

		if ( 'snapchat' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_availability_date( $product_data );

		// Channel money format "<number> <ISO-4217>" — V5 appended the
		// currency universally; the bare number was a V8 regression.
		$product_data = $this->transform_availability( $product_data );
		$product_data = $this->transform_lengths( $product_data );
		$product_data = $this->append_currency( $product_data, $config, array( 'price', 'sale_price' ) );

		return $product_data;
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
	 * Normalize availability to the channel's space-separated enums.
	 *
	 * 2026 accepted set: in stock / out of stock / preorder /
	 * available for order / discontinued. WooCommerce's backorder is
	 * NOT a valid value — it maps to "available for order"
	 * (purchasable now, ships later).
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 * @return array Modified product data.
	 */
	private function transform_availability( array $data ): array {
		if ( empty( $data['availability'] ) ) {
			return $data;
		}

		$availability = strtolower( trim( str_replace( array( '_', '-' ), ' ', $data['availability'] ) ) );

		$map = array(
			'instock'             => 'in stock',
			'in stock'            => 'in stock',
			'outofstock'          => 'out of stock',
			'out of stock'        => 'out of stock',
			'onbackorder'         => 'available for order',
			'on backorder'        => 'available for order',
			'backorder'           => 'available for order',
			'preorder'            => 'preorder',
			'available for order' => 'available for order',
			'discontinued'        => 'discontinued',
		);

		if ( isset( $map[ $availability ] ) ) {
			$data['availability'] = $map[ $availability ];
		}

		return $data;
	}

	/**
	 * Truncate title/description to the channel's documented limits.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 * @return array Modified product data.
	 */
	private function transform_lengths( array $data ): array {
		if ( ! empty( $data['title'] ) && is_string( $data['title'] ) && mb_strlen( $data['title'] ) > self::TITLE_MAX_LENGTH ) {
			$data['title'] = mb_substr( $data['title'], 0, self::TITLE_MAX_LENGTH );
		}
		if ( ! empty( $data['description'] ) && is_string( $data['description'] ) && mb_strlen( $data['description'] ) > self::DESCRIPTION_MAX_LENGTH ) {
			$data['description'] = mb_substr( $data['description'], 0, self::DESCRIPTION_MAX_LENGTH );
		}

		return $data;
	}
}
