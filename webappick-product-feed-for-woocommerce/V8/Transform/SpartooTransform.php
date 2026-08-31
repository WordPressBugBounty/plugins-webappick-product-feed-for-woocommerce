<?php
/**
 * SpartooTransform — Applies Spartoo-specific formatting rules.
 *
 * - Product type: "variation" → "child", all others → "parent"
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
 * Spartoo transform.
 *
 * @since 8.0.0
 */
class SpartooTransform implements TransformInterface {

	/**
	 * Transform product data for Spartoo compliance.
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

		if ( 'spartoo.fi' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_product_type( $product_data );

		return $product_data;
	}

	/**
	 * Convert product type to Spartoo format.
	 *
	 * Spartoo uses "child" for variations and "parent" for everything else.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	private function transform_product_type( array $data ): array {
		if ( ! isset( $data['product_type'] ) ) {
			return $data;
		}

		$type = strtolower( trim( $data['product_type'] ) );

		$data['product_type'] = ( 'variation' === $type ) ? 'child' : 'parent';

		return $data;
	}
}
