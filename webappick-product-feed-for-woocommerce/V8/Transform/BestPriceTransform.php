<?php
/**
 * BestPriceTransform — Applies BestPrice-specific formatting rules.
 *
 * - Availability: "in stock" → "Y", all others → "N"
 * - Category path: ">" separator → ", " separator
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
 * BestPrice transform.
 *
 * @since 8.0.0
 */
class BestPriceTransform implements TransformInterface {

	/**
	 * Transform product data for BestPrice compliance.
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

		if ( 'bestprice' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_availability( $product_data );
		$product_data = $this->transform_category_path( $product_data );

		return $product_data;
	}

	/**
	 * Convert availability to BestPrice format.
	 *
	 * BestPrice uses simple "Y"/"N" availability flags.
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

		$data['availability'] = ( 'in stock' === $availability ) ? 'Y' : 'N';

		return $data;
	}

	/**
	 * Convert category path separator for BestPrice.
	 *
	 * Replaces " > " hierarchy separator with ", " for BestPrice format.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	private function transform_category_path( array $data ): array {
		$category_attrs = array( 'product_type', 'product_cat', 'categories', 'product_full_cat' );

		foreach ( $category_attrs as $attr ) {
			if ( ! empty( $data[ $attr ] ) && is_string( $data[ $attr ] ) ) {
				$data[ $attr ] = str_replace( ' > ', ', ', $data[ $attr ] );
			}
		}

		return $data;
	}
}
