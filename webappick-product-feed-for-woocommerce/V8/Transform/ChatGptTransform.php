<?php
/**
 * ChatGptTransform — OpenAI / ChatGPT Shopping feed formatting.
 *
 * The OpenAI Product Feed Spec (chatgpt.com/merchants) uses its own
 * schema — the merchant attribute keys ARE the OpenAI field names
 * (enable_search, enable_checkout, seller_name, return_policy, … —
 * V5's chatgpt template, ported verbatim). This transform owns the
 * value formats:
 * - money "<number> <ISO-4217>" on price/sale_price
 * - underscore availability enums (in_stock / out_of_stock / preorder)
 * - eligibility flags normalized to lowercase true/false
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
 * OpenAI / ChatGPT Shopping transform.
 *
 * @since 8.0.0
 */
class ChatGptTransform implements TransformInterface {

	use AppendsCurrencyTrait;

	/**
	 * Transform product data for ChatGPT Shopping feeds.
	 *
	 * No-ops for non-chatgpt providers.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Transformed product data.
	 */
	public function transform( array $product_data, Config $config ): array {
		if ( 'chatgpt' !== $config->get( 'provider', '' ) ) {
			return $product_data;
		}

		$product_data = $this->transform_availability( $product_data );
		$product_data = $this->transform_flags( $product_data );
		$product_data = $this->append_currency( $product_data, $config, array( 'price', 'sale_price' ) );

		return $product_data;
	}

	/**
	 * Normalize availability to OpenAI's underscore enums.
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

	/**
	 * Normalize the OpenAI eligibility flags to lowercase true/false.
	 *
	 * Merchants map patterns like "TRUE", "Yes", "1" — OpenAI's spec
	 * wants boolean literals.
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Product data.
	 * @return array Modified product data.
	 */
	private function transform_flags( array $data ): array {
		foreach ( array( 'enable_search', 'enable_checkout', 'is_ads_eligible' ) as $flag ) {
			if ( ! isset( $data[ $flag ] ) || '' === $data[ $flag ] || is_array( $data[ $flag ] ) ) {
				continue;
			}

			$value = strtolower( trim( (string) $data[ $flag ] ) );

			$data[ $flag ] = in_array( $value, array( 'true', '1', 'yes', 'y', 'on' ), true ) ? 'true' : 'false';
		}

		return $data;
	}
}
