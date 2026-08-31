<?php
/**
 * SkroutzTransform — Applies Skroutz-specific formatting rules.
 *
 * - Availability: "in stock" → "Delivery 1 to 3 days",
 *   all others → "Delivery up to 30 days"
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
 * Skroutz transform.
 *
 * @since 8.0.0
 */
class SkroutzTransform implements TransformInterface {

	/**
	 * Transform product data for Skroutz compliance.
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

		if ( 'skroutz' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_availability( $product_data );

		return $product_data;
	}

	/**
	 * Convert availability to Skroutz delivery time format.
	 *
	 * Skroutz uses human-readable delivery time strings.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	private function transform_availability( array $data ): array {
		if ( ! isset( $data['availability'] ) ) {
			return $data;
		}

		$data['availability'] = self::format_availability( (string) $data['availability'] );

		return $data;
	}

	/**
	 * Map a raw stock/availability value to Skroutz's delivery-time string.
	 *
	 * In-stock → "Delivery 1 to 3 days"; anything else → "Delivery up to
	 * 30 days". Shared so per-variation availability (SkroutzVariationsBuilder)
	 * uses the exact same wording as the product-level transform.
	 *
	 * @since 8.0.0
	 *
	 * @param string $availability Raw availability value.
	 * @return string Skroutz delivery-time string.
	 */
	public static function format_availability( string $availability ): string {
		$normalized = str_replace( '_', ' ', strtolower( trim( $availability ) ) );

		return 'in stock' === $normalized ? 'Delivery 1 to 3 days' : 'Delivery up to 30 days';
	}
}
