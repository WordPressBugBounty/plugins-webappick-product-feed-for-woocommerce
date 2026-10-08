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

		// OpenAI's own feed format (owner, CBT-712). Works on the configured
		// names — native (item_id, group_id, …) or legacy (id, item_group_id,
		// …); AttributeNameMapper writes the native names afterwards.
		$product_data = $this->transform_sale_price( $product_data );
		$product_data = $this->transform_weight( $product_data );
		$product_data = $this->transform_variants( $product_data );
		$product_data = $this->transform_image_list( $product_data );
		$product_data = $this->cap_text( $product_data );

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
		if ( ! array_key_exists( 'availability', $data ) || is_array( $data['availability'] ) ) {
			return $data;
		}
		// OpenAI's own format (has seller_name) vs its Google-compatible
		// profile, which spells preorder and has no "unknown" (CBT-712).
		$google_compatible = ! array_key_exists( 'seller_name', $data );

		if ( '' === trim( (string) $data['availability'] ) ) {
			// OpenAI rejects a row with an empty availability; unknown is its
			// explicit "stock status unavailable" value (CBT-712).
			if ( ! $google_compatible ) {
				$data['availability'] = 'unknown';
			}
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
		if ( $google_compatible && 'pre_order' === $data['availability'] ) {
			$data['availability'] = 'preorder';
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

	/**
	 * The leading decimal amount of a money string ("59.99 USD" → 59.99).
	 *
	 * @param mixed $value Value.
	 * @return float|null
	 */
	private static function amount( $value ): ?float {
		if ( ! is_scalar( $value ) || 1 !== preg_match( '/^\s*(\d+(?:\.\d+)?)/', (string) $value, $m ) ) {
			return null;
		}

		return (float) $m[1];
	}

	/**
	 * OpenAI ignores a sale price that is not strictly between 0 and the
	 * regular price; send none instead (CBT-712).
	 *
	 * @param array $data Product data.
	 * @return array
	 */
	private function transform_sale_price( array $data ): array {
		if ( ! array_key_exists( 'sale_price', $data ) || '' === (string) ( is_scalar( $data['sale_price'] ) ? $data['sale_price'] : '' ) ) {
			return $data;
		}
		$sale  = self::amount( $data['sale_price'] );
		$price = self::amount( $data['price'] ?? null );
		if ( null === $sale || $sale <= 0 || ( null !== $price && $sale >= $price ) ) {
			$data['sale_price'] = '';
		}

		return $data;
	}

	/**
	 * Weight as a bare decimal plus item_weight_unit (g, kg, oz, lb) — the
	 * WooCommerce value arrives as "0.75 kg" / "2 lbs". Only split when the
	 * feed has an item_weight_unit column, so older feeds keep the unit
	 * with the number (CBT-712).
	 *
	 * @param array $data Product data.
	 * @return array
	 */
	private function transform_weight( array $data ): array {
		$units = array(
			'g'      => 'g',
			'gram'   => 'g',
			'grams'  => 'g',
			'kg'     => 'kg',
			'kgs'    => 'kg',
			'oz'     => 'oz',
			'ozs'    => 'oz',
			'ounce'  => 'oz',
			'ounces' => 'oz',
			'lb'     => 'lb',
			'lbs'    => 'lb',
			'pound'  => 'lb',
			'pounds' => 'lb',
		);

		if ( array_key_exists( 'item_weight_unit', $data ) && is_scalar( $data['item_weight_unit'] ) ) {
			$unit                     = strtolower( trim( (string) $data['item_weight_unit'] ) );
			$data['item_weight_unit'] = $units[ $unit ] ?? '';
		}

		if ( ! array_key_exists( 'item_weight_unit', $data ) || ! isset( $data['weight'] ) || ! is_scalar( $data['weight'] ) || '' === trim( (string) $data['weight'] ) ) {
			return $data;
		}

		if ( 1 === preg_match( '/^\s*(\d+(?:[.,]\d+)?)\s*([a-zA-Z]*)\s*$/', (string) $data['weight'], $m ) ) {
			$data['weight'] = str_replace( ',', '.', $m[1] );
			$unit           = strtolower( $m[2] );
			if ( '' === $data['item_weight_unit'] && isset( $units[ $unit ] ) ) {
				$data['item_weight_unit'] = $units[ $unit ];
			}
		}
		if ( '' === $data['item_weight_unit'] ) {
			$data['weight'] = ''; // A weight without a valid unit is not usable.
		}

		return $data;
	}

	/**
	 * Set listing_has_variations = true on every variant row: the row's group
	 * differs from its own item ID (CBT-712). Empty otherwise.
	 *
	 * @param array $data Product data.
	 * @return array
	 */
	private function transform_variants( array $data ): array {
		if ( ! array_key_exists( 'listing_has_variations', $data ) ) {
			return $data;
		}
		$group = (string) ( $data['group_id'] ?? $data['item_group_id'] ?? '' );
		$item  = (string) ( $data['item_id'] ?? $data['id'] ?? '' );

		$data['listing_has_variations'] = ( '' !== $group && $group !== $item ) ? 'true' : '';

		return $data;
	}

	/**
	 * Additional images: comma-separated, no spaces (OpenAI: "do not use
	 * spaces or semicolons as list separators").
	 *
	 * @param array $data Product data.
	 * @return array
	 */
	private function transform_image_list( array $data ): array {
		foreach ( array( 'additional_image_urls', 'additional_image_link' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) && '' !== (string) $data[ $key ] ) {
				$urls         = array_filter( array_map( 'trim', explode( ',', (string) $data[ $key ] ) ), 'strlen' );
				$data[ $key ] = implode( ',', $urls );
			}
		}

		return $data;
	}

	/**
	 * OpenAI: titles up to 150 characters, descriptions up to 5,000.
	 *
	 * @param array $data Product data.
	 * @return array
	 */
	private function cap_text( array $data ): array {
		$limits = array(
			'title'       => 150,
			'description' => 5000,
		);
		foreach ( $limits as $key => $max ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && mb_strlen( $data[ $key ] ) > $max ) {
				$data[ $key ] = mb_substr( $data[ $key ], 0, $max );
			}
		}

		return $data;
	}
}
