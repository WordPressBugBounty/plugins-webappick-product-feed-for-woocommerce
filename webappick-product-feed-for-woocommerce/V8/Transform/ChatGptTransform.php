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

	use FeedCurrencyFallbackTrait;


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
		// Price / sale_price = bare number + the row's configured suffix as
		// written; the feed currency is appended ONLY when the suffix is empty
		// and a multi-currency plugin provided that currency (CBT-602, owner).
		$product_data = $this->apply_feed_currency_fallback( $product_data, $config, array( 'price', 'sale_price' ) );
		$product_data = $this->strip_thousand_separators( $product_data, array( 'price', 'sale_price' ) );

		return $product_data;
	}

	/**
	 * Strip thousand-separator commas from money values (CBT-575).
	 *
	 * OpenAI rejects "1,099.90 RON" as misformatted (razvan199's first
	 * report shape). Only grouping commas are removed — a comma followed
	 * by exactly three digits — so "1,099.90 RON" → "1099.90 RON" while
	 * decimal commas ("1099,90") are left for the number-format settings
	 * to own.
	 *
	 * @since 8.0.19
	 *
	 * @param array    $data  Product data.
	 * @param string[] $attrs Money attribute keys.
	 * @return array Modified product data.
	 */
	private function strip_thousand_separators( array $data, array $attrs ): array {
		foreach ( $attrs as $attr ) {
			if ( empty( $data[ $attr ] ) || ! is_scalar( $data[ $attr ] ) ) {
				continue;
			}
			$data[ $attr ] = preg_replace( '/(\d),(?=\d{3}(\D|$))/', '$1', (string) $data[ $attr ] );
		}

		return $data;
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
