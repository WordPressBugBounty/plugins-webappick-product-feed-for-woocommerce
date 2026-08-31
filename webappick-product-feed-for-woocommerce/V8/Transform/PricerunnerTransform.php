<?php
/**
 * PricerunnerTransform — Applies Pricerunner-specific formatting rules.
 *
 * - Availability: "in stock" → "Yes", all others → "No"
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
 * Pricerunner transform.
 *
 * @since 8.0.0
 */
class PricerunnerTransform implements TransformInterface {

	/**
	 * Transform product data for Pricerunner compliance.
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

		if ( 'pricerunner' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_availability( $product_data );

		return $product_data;
	}

	/**
	 * Convert availability to Pricerunner format.
	 *
	 * Pricerunner uses "Yes"/"No" availability strings.
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

		$availability = strtolower( trim( $data['availability'] ) );
		$availability = str_replace( '_', ' ', $availability );

		$data['availability'] = ( 'in stock' === $availability ) ? 'Yes' : 'No';

		return $data;
	}
}
