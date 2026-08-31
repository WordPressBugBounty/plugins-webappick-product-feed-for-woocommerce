<?php
/**
 * XTransform — X (Twitter) Shopping catalog formatting.
 *
 * X's Shopping Manager spec (business.x.com/en/help/shopping-specs):
 * raw underscore field names in CSV/TSV feeds, money as
 * "<number> <ISO-4217>" with a period decimal, Title-Case availability
 * enums, title ≤150 chars, description ≤5,000 chars. X does NOT accept
 * Google feeds natively — this transform owns the value conversions.
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
 * X Shopping transform.
 *
 * @since 8.0.0
 */
class XTransform implements TransformInterface {

	use AppendsCurrencyTrait;

	// Shopping Manager limits: id ≤100, title ≤150 (≤70 renders best in
	// ads), description ≤5,000.
	const TITLE_MAX_LENGTH       = 150;
	const DESCRIPTION_MAX_LENGTH = 5000;

	/**
	 * Transform product data for X Shopping catalogs.
	 *
	 * No-ops for non-X providers.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Transformed product data.
	 */
	public function transform( array $product_data, Config $config ): array {
		if ( 'x' !== $config->get( 'provider', '' ) ) {
			return $product_data;
		}

		$product_data = $this->transform_availability( $product_data );
		$product_data = $this->transform_lengths( $product_data );
		$product_data = $this->append_currency( $product_data, $config, array( 'price', 'sale_price' ) );

		return $product_data;
	}

	/**
	 * Normalize availability to X Shopping Manager's enums.
	 *
	 * Accepted set (lowercase, space-form): in stock / out of stock /
	 * preorder / available for order / discontinued (BUG-0029, owner-confirmed
	 * against the X data-source spec). WooCommerce's backorder maps to
	 * "available for order". This matches the sibling social channels
	 * (Facebook / Snapchat / TikTok), which all use the lowercase space-form.
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
	 * Truncate title/description to X's documented limits.
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
