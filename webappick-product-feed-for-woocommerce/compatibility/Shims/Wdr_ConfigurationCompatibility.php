<?php
/**
 * Compatibility class for Wdr_ConfigurationCompatibility plugin
 *
 * @package CTXFeed\Compat\Shims
 */

namespace CTXFeed\Compat\Shims;

use CTXFeed\Compat\Support\Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Wdr_ConfigurationCompatibility
 *
 * Discount rules compatibility by flycart.
 *
 * @package CTXFeed\Compat\Shims
 */
class Wdr_ConfigurationCompatibility {
	/**
	 * Memoised Discount Rules manager instance.
	 *
	 * @var \Wdr\App\Controllers\ManageDiscount|null
	 */
	protected $wdr_config_rules;

	/**
	 * Wdr_ConfigurationCompatibility Constructor.
	 */
	public function __construct() {

		// phpcs:disable Squiz.PHP.CommentedOutCode, Squiz.Commenting.InlineComment -- The two disabled registrations below are kept from the V5 original: regular_price (and its with-tax twin) is deliberately NOT discounted, and keeping the calls commented out documents that decision at the point it matters.
		// Get price with discount.
		// add_filter( 'woo_feed_filter_product_regular_price', array( $this, 'get_discounted_price' ), 999, 5 );
		add_filter( 'woo_feed_filter_product_price', array( $this, 'get_discounted_price' ), 999, 5 );
		add_filter( 'woo_feed_filter_product_sale_price', array( $this, 'get_discounted_price' ), 999, 5 );
		// add_filter( 'woo_feed_filter_product_regular_price_with_tax', array( $this, 'get_discounted_price' ), 999, 5 );
		add_filter( 'woo_feed_filter_product_price_with_tax', array( $this, 'get_discounted_price' ), 999, 5 );
		add_filter( 'woo_feed_filter_product_sale_price_with_tax', array( $this, 'get_discounted_price' ), 999, 5 );
		// phpcs:enable Squiz.PHP.CommentedOutCode, Squiz.Commenting.InlineComment
	}


	/**
	 * Get Discounted Price
	 *
	 * @param int         $price product price.
	 * @param \WC_Product $product product object.
	 * @param object      $config config object.
	 * @param bool        $with_tax price with tax or without tax.
	 * @param string      $price_type price type regular_price, price, sale_price.
	 *
	 * @return int
	 */
	public function get_discounted_price( $price, $product, $config, $with_tax, $price_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the 5-argument arity registered with add_filter() must be preserved even though this adapter does not read every argument.

		// In free version we do not support wpml plugin features that's way we make else condition which working only for flycart plugin.
		if ( Helper::is_pro() ) {
			if ( class_exists( 'Wdr\App\Controllers\Configuration' ) && class_exists( 'Wdr\App\Controllers\ManageDiscount' ) && method_exists( 'Wdr\App\Controllers\ManageDiscount', 'calculateInitialAndDiscountedPrice' ) && ! class_exists( 'woocommerce_wpml' ) ) {
				if ( ! $this->wdr_config_rules ) {
					$this->wdr_config_rules = new \Wdr\App\Controllers\ManageDiscount();
					$wdr_config_rules       = $this->wdr_config_rules;
				} else {
					$wdr_config_rules = $this->wdr_config_rules;
				}
				$prices = $wdr_config_rules::calculateInitialAndDiscountedPrice( $product, 1, false, false );

				if ( is_bool( $prices ) ) {
					return $price;
				}

				if ( 'regular_price' === $price_type ) {
					$price = $prices['initial_price'];

					if ( $with_tax ) {
						$price = $prices['initial_price_with_tax'];
					}
				} else {
					$price = $prices['discounted_price'];

					if ( $with_tax ) {
						$price = $prices['discounted_price_with_tax'];
					}
				}
			} else {
				$price = apply_filters( 'advanced_woo_discount_rules_get_product_discount_price_from_custom_price', false, $product, 1, $price, 'discounted_price', true, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook owned by Discount Rules for WooCommerce; the name is fixed by that plugin and must not be prefixed.
			}
		} elseif ( class_exists( 'Wdr\App\Controllers\Configuration' ) && class_exists( 'Wdr\App\Controllers\ManageDiscount' ) && method_exists( 'Wdr\App\Controllers\ManageDiscount', 'calculateInitialAndDiscountedPrice' ) ) {
			if ( ! $this->wdr_config_rules ) {
				$this->wdr_config_rules = new \Wdr\App\Controllers\ManageDiscount();
				$wdr_config_rules       = $this->wdr_config_rules;
			} else {
				$wdr_config_rules = $this->wdr_config_rules;
			}
				$prices = $wdr_config_rules::calculateInitialAndDiscountedPrice( $product, 1, false, false );

			if ( is_bool( $prices ) ) {
				return $price;
			}

			if ( 'regular_price' === $price_type ) {
				$price = $prices['initial_price'];

				if ( $with_tax ) {
					$price = $prices['initial_price_with_tax'];
				}
			} else {
				$price = $prices['discounted_price'];

				if ( $with_tax ) {
					$price = $prices['discounted_price_with_tax'];
				}
			}
		}

		return $price;
	}
}
