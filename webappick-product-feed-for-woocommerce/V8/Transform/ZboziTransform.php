<?php
/**
 * ZboziTransform — Applies Zbozi.cz-specific formatting rules.
 *
 * - Visibility: "visible" → 1, all others → 0
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
 * Zbozi.cz transform.
 *
 * @since 8.0.0
 */
class ZboziTransform implements TransformInterface {

	/**
	 * Transform product data for Zbozi.cz compliance.
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

		if ( 'zbozi.cz' !== $provider ) {
			return $product_data;
		}

		$product_data = $this->transform_visibility( $product_data );

		return $product_data;
	}

	/**
	 * Convert visibility to boolean integer for Zbozi.
	 *
	 * Zbozi uses 1/0 for product visibility flags.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 *
	 * @return array Modified product data.
	 */
	private function transform_visibility( array $data ): array {
		if ( ! isset( $data['visibility'] ) ) {
			return $data;
		}

		$visibility = strtolower( trim( $data['visibility'] ) );

		$data['visibility'] = ( 'visible' === $visibility ) ? '1' : '0';

		return $data;
	}
}
