<?php
/**
 * Compatibility class for WAD_DiscountCompatibility plugin
 *
 * @package CTXFeed\Compat\Shims
 */

namespace CTXFeed\Compat\Shims;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CTXFeed\Compat\Support\ProductHelper;
use CTXFeed\Compat\Support\Cache;

/**
 * Class WAD_DiscountCompatibility
 *
 * PLUGIN: Conditional Discounts for WooCommerce
 * URL: https://wordpress.org/plugins/woo-advanced-discounts/
 *
 * @package CTXFeed\Compat\Shims
 */
class WAD_DiscountCompatibility {

	/**
	 * WAD_DiscountCompatibility Constructor.
	 */
	public function __construct() {
		// Get price with discount.
		add_filter( 'woo_feed_filter_product_price', array( $this, 'get_discounted_price' ), 99999999, 5 );
		add_filter( 'woo_feed_filter_product_sale_price', array( $this, 'get_discounted_price' ), 99999999, 5 );
		add_filter( 'woo_feed_filter_product_price_with_tax', array( $this, 'get_discounted_price' ), 99999999, 5 );
		add_filter( 'woo_feed_filter_product_sale_price_with_tax', array( $this, 'get_discounted_price' ), 99999999, 5 );
	}

	/**
	 * Get Discounted Price
	 *
	 * @param int         $price product price.
	 * @param \WC_Product $product product object.
	 * @param object      $config config object.
	 * @param bool        $with_tax price with tax or without tax.
	 * @param string      $price_type price type regular_price, price, sale_price.
	 * @return int
	 */
	public function get_discounted_price( $price, $product, $config, $with_tax, $price_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the 5-argument arity registered with add_filter() must be preserved even though this adapter does not read every argument.
		// Guard against a breaking plugin update: if the WAD API this shim reads
		// is gone, return the price untouched instead of fataling.
		if ( ! function_exists( 'wad_get_active_discounts' ) || ! class_exists( '\\WAD_Discount' ) ) {
			return $price;
		}
		if ( ! Cache::get( 'wad_discounts' ) ) {
			Cache::set( 'wad_discounts', wad_get_active_discounts( true ) );
			$wad_discounts = Cache::get( 'wad_discounts' );
			$price         = self::add_discounts( $wad_discounts, $price, $product, $config, $with_tax, $price_type );
		} else {
			$wad_discounts = Cache::get( 'wad_discounts' );
			$price         = self::add_discounts( $wad_discounts, $price, $product, $config, $with_tax, $price_type );
		}

		return $price;
	}

	/**
	 * Get Discounted Price
	 *
	 * @param array       $wad_discounts product price.
	 * @param int         $price product price.
	 * @param \WC_Product $product product object.
	 * @param object      $config config object.
	 * @param bool        $with_tax price with tax or without tax.
	 * @param string      $price_type price type regular_price, price, sale_price.
	 * @return int
	 */
	private static function add_discounts( $wad_discounts, $price, $product, $config, $with_tax, $price_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the 5-argument arity registered with add_filter() must be preserved even though this adapter does not read every argument.
		if ( ! isset( $wad_discounts['product'] ) ) {
			return $price;
		}

		$original_price = $price;

		if ( isset( $wad_discounts['product'] ) ) {
			$discounted_amount = 0;

			foreach ( $wad_discounts['product'] as $discount_id ) {
				$wad_obj    = new \WAD_Discount( $discount_id );
				$is_disable = $wad_obj->settings['disable-on-product-pages'];

				if ( ! $wad_obj->is_applicable( $product->get_id() ) || 'no' !== $is_disable ) {
					continue;
				}

				// Check if this product is eligible for this discount.
				if ( ! self::is_product_eligible_for_discount( $wad_obj, $product ) ) {
					continue;
				}

				$discounted_amount += (float) $wad_obj->get_discount_amount( $product->get_price() );
			}

			if ( $discounted_amount > 0 && empty( $price ) ) {
				$price = (float) $product->get_price() - $discounted_amount;
			} else {
				$price = $original_price;
			}
		}

		if ( $with_tax ) {
			$price = ProductHelper::get_price_with_tax( $price, $product );
		}

		return $price;
	}

	/**
	 * Check if a product is eligible for a specific discount.
	 *
	 * @param \WAD_Discount $wad_obj  WAD Discount object.
	 * @param \WC_Product   $product  Product object.
	 * @return bool
	 */
	private static function is_product_eligible_for_discount( $wad_obj, $product ) {
		$settings = $wad_obj->settings;

		// Get parent ID for variations.
		$parent_id = $product->get_parent_id();

		// Check products-list condition - this is a WAD List ID, not product IDs.
		if ( isset( $settings['products-list'] ) && ! empty( $settings['products-list'] ) ) {
			$list_id = intval( $settings['products-list'] );
			if ( $list_id > 0 ) {
				// Get the WAD List and check if product matches its conditions.
				if ( ! self::is_product_in_wad_list( $list_id, $product, $parent_id ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Check if product is in a WAD product list.
	 *
	 * @param int         $list_id   WAD List post ID.
	 * @param \WC_Product $product   Product object.
	 * @param int         $parent_id Parent product ID for variations.
	 * @return bool
	 */
	private static function is_product_in_wad_list( $list_id, $product, $parent_id ) {
		// Get the WAD List settings from 'o-list' meta key.
		$list_settings = get_post_meta( $list_id, 'o-list', true );

		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found, Squiz.Commenting.InlineComment.InvalidEndChar -- Kept from the V5 original: the one-line probe used to inspect an unfamiliar WAD list payload while debugging this adapter.
		// error_log(print_r($list_settings,true))

		if ( empty( $list_settings ) || ! is_array( $list_settings ) ) {
			return true;
		}

		$product_id = $product->get_id();
		$check_ids  = $parent_id ? array( $product_id, $parent_id ) : array( $product_id );

		// Check if using "By ID" mode with specific product IDs.
		if ( isset( $list_settings['ids'] ) && ! empty( $list_settings['ids'] ) && 'by-id' === $list_settings['type'] ) {
			$product_ids = array_map( 'intval', explode( ',', $list_settings['ids'] ) );
			foreach ( $product_ids as $check_id ) {
				if ( $check_id === $product_id ) {
					return true;
				}
			}
			return false;
		}

		// Check excluded products.
		if ( isset( $list_settings['post__not_in'] ) && ! empty( $list_settings['post__not_in'] ) ) {
			$excluded_ids = array_map( 'intval', explode( ',', $list_settings['post__not_in'] ) );
			foreach ( $check_ids as $check_id ) {
				if ( in_array( $check_id, $excluded_ids, true ) ) {
					return false;
				}
			}
		}

		// Check tax_query condition (taxonomies like Brand, Category, etc.).
		if ( isset( $list_settings['tax_query'] ) && isset( $list_settings['tax_query']['queries'] ) && ! empty( $list_settings['tax_query']['queries'] ) ) {
			if ( ! self::check_tax_query_conditions( $list_settings['tax_query'], $product, $parent_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if product matches tax_query conditions.
	 *
	 * @param array       $tax_query  Tax query settings.
	 * @param \WC_Product $product    Product object.
	 * @param int         $parent_id  Parent product ID for variations.
	 * @return bool
	 */
	private static function check_tax_query_conditions( $tax_query, $product, $parent_id ) {
		$queries  = $tax_query['queries'];
		$relation = isset( $tax_query['relation'] ) ? $tax_query['relation'] : 'AND';
		$results  = array();

		foreach ( $queries as $query ) {
			if ( ! isset( $query['taxonomy'] ) || ! isset( $query['terms'] ) ) {
				continue;
			}

			$taxonomy = $query['taxonomy'];
			$operator = isset( $query['operator'] ) ? $query['operator'] : 'IN';
			$terms    = array_map( 'intval', (array) $query['terms'] );

			// Get product term IDs for this taxonomy.
			$product_terms = wp_get_post_terms( $product->get_id(), $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $product_terms ) ) {
				$product_terms = array();
			}

			// For variations, also check parent terms.
			if ( $parent_id ) {
				$parent_terms = wp_get_post_terms( $parent_id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $parent_terms ) ) {
					$product_terms = array_merge( $product_terms, $parent_terms );
				}
			}

			$product_terms = array_unique( array_map( 'intval', $product_terms ) );
			$match         = self::evaluate_tax_operator( $operator, $product_terms, $terms );
			$results[]     = $match;
		}

		if ( empty( $results ) ) {
			return true;
		}

		// Apply relation (AND/OR).
		if ( 'OR' === $relation ) {
			return in_array( true, $results, true );
		}

		// Default is AND.
		return ! in_array( false, $results, true );
	}

	/**
	 * Evaluate tax query operator condition.
	 *
	 * @param string $operator      Operator (IN, NOT IN, AND).
	 * @param array  $product_terms Product's taxonomy term IDs.
	 * @param array  $terms         Term IDs to check against.
	 * @return bool
	 */
	private static function evaluate_tax_operator( $operator, $product_terms, $terms ) {
		switch ( strtoupper( $operator ) ) {
			case 'IN':
				return ! empty( array_intersect( $product_terms, $terms ) );
			case 'NOT IN':
				return empty( array_intersect( $product_terms, $terms ) );
			case 'AND':
				return count( array_intersect( $product_terms, $terms ) ) === count( $terms );
			default:
				return ! empty( array_intersect( $product_terms, $terms ) );
		}
	}
}
