<?php
/**
 * RedditTransform — Reddit Dynamic Product Ads catalog formatting.
 *
 * Reddit DPA catalogs (Reddit Ads Manager, 2025+) consume a
 * Google-Shopping-like CSV with UNDERSCORE column names — the raw
 * merchant attribute keys are the column names (bing pattern, no name
 * map). This transform owns the value formats:
 * - money "<number> <ISO-4217>" on price/sale_price
 * - google-style underscore availability enums
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
 * Reddit DPA transform.
 *
 * @since 8.0.0
 */
class RedditTransform implements TransformInterface {

	use AppendsCurrencyTrait;

	/**
	 * Transform product data for Reddit DPA catalogs.
	 *
	 * No-ops for non-Reddit providers.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Transformed product data.
	 */
	public function transform( array $product_data, Config $config ): array {
		if ( 'reddit' !== $config->get( 'provider', '' ) ) {
			return $product_data;
		}

		$product_data = $this->transform_availability( $product_data );
		$product_data = $this->append_currency( $product_data, $config, array( 'price', 'sale_price' ) );

		return $product_data;
	}

	/**
	 * Normalize availability to underscore enums (google-style).
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

		$availability = str_replace( array( ' ', '-' ), '_', strtolower( trim( $data['availability'] ) ) );

		$map = array(
			'instock'      => 'in_stock',
			'in_stock'     => 'in_stock',
			'outofstock'   => 'out_of_stock',
			'out_of_stock' => 'out_of_stock',
			'onbackorder'  => 'preorder',
			'backorder'    => 'preorder',
			'preorder'     => 'preorder',
		);

		if ( isset( $map[ $availability ] ) ) {
			$data['availability'] = $map[ $availability ];
		}

		return $data;
	}
}
