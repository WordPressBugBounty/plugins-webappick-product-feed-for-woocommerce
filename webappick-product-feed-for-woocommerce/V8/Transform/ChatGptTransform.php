<?php
/**
 * ChatGptTransform — OpenAI / ChatGPT Shopping feed formatting.
 *
 * The OpenAI Product Feed Spec (chatgpt.com/merchants) uses its own
 * schema — the merchant attribute keys ARE the OpenAI field names
 * (is_eligible_search, is_eligible_checkout, seller_name, return_policy, … —
 * V5's chatgpt template, ported verbatim). This transform owns the
 * value formats:
 * - money "<number> <ISO-4217>" on price/sale_price
 * - availability normalized to the spec enum (in_stock / out_of_stock /
 *   pre_order / backorder — OpenAI rejects rows with other values, #69076)
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
	 * Normalize availability to OpenAI's spec enum.
	 *
	 * The spec set is `in_stock` / `out_of_stock` / `pre_order` /
	 * `backorder` / `unknown`, and OpenAI REJECTS rows carrying anything
	 * else (#69076). Two earlier spellings were wrong: backordered items
	 * were mapped to `preorder` (OpenAI has a distinct `backorder` enum —
	 * a backordered item is not a pre-order), and pre-orders were emitted
	 * as `preorder` (Google's spelling; OpenAI hyphenates with an
	 * underscore, so the row was rejected).
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
			'onbackorder'  => 'backorder',
			'backorder'    => 'backorder',
			'preorder'     => 'pre_order',
			'pre_order'    => 'pre_order',
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
		// Primary spec names first; enable_search / enable_checkout are
		// OpenAI's legacy aliases — feeds created before 8.0.12 still carry
		// them in their saved mapping and must keep normalizing.
		foreach ( array( 'is_eligible_search', 'is_eligible_checkout', 'enable_search', 'enable_checkout', 'is_ads_eligible' ) as $flag ) {
			if ( ! isset( $data[ $flag ] ) || '' === $data[ $flag ] || is_array( $data[ $flag ] ) ) {
				continue;
			}

			$value = strtolower( trim( (string) $data[ $flag ] ) );

			$data[ $flag ] = in_array( $value, array( 'true', '1', 'yes', 'y', 'on' ), true ) ? 'true' : 'false';
		}

		return $data;
	}
}
