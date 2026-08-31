<?php
/**
 * TikTokTransform — Applies TikTok Shop-specific formatting rules.
 *
 * - Color/size: remove all spaces from values
 * - Shipping weight: append WooCommerce weight unit
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
 * TikTok transform.
 *
 * @since 8.0.0
 */
class TikTokTransform implements TransformInterface {

	use AppendsCurrencyTrait;

	// Channel spec limits (title hard cap, description cap).
	const TITLE_MAX_LENGTH       = 255;
	const DESCRIPTION_MAX_LENGTH = 5000;

	/**
	 * Transform product data for TikTok Shop compliance.
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

		if ( 'tiktok' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_color_size( $product_data );
		$product_data = $this->transform_shipping_weight( $product_data );

		// Channel money format "<number> <ISO-4217>" — V5 appended the
		// currency universally; the bare number was a V8 regression.
		$product_data = $this->transform_availability( $product_data );
		$product_data = $this->transform_lengths( $product_data );
		$product_data = $this->append_currency( $product_data, $config, array( 'price', 'sale_price' ) );

		return $product_data;
	}

	/**
	 * Remove all spaces from color and size values.
	 *
	 * TikTok requires color/size values without spaces.
	 * E.g., "Red Large" → "RedLarge", "Light Blue" → "LightBlue".
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	private function transform_color_size( array $data ): array {
		$attrs = array( 'color', 'size' );

		foreach ( $attrs as $attr ) {
			if ( ! empty( $data[ $attr ] ) ) {
				$data[ $attr ] = str_replace( ' ', '', $data[ $attr ] );
			}
		}

		return $data;
	}

	/**
	 * Ensure shipping weight has unit suffix.
	 *
	 * Appends WooCommerce weight unit if not already present.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	private function transform_shipping_weight( array $data ): array {
		if ( empty( $data['shipping_weight'] ) ) {
			return $data;
		}

		$weight = $data['shipping_weight'];

		// If weight already contains a unit suffix, skip.
		if ( preg_match( '/[a-zA-Z]+$/', trim( $weight ) ) ) {
			return $data;
		}

		$unit = get_option( 'woocommerce_weight_unit', 'kg' );

		$data['shipping_weight'] = trim( $weight ) . ' ' . $unit;

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
