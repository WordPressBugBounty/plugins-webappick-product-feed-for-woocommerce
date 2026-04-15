<?php
/**
 * Compatibility class for YayCurrency plugin
 *
 * @package CTXFeed\V5\Compatibility
 */

namespace CTXFeed\Compatibility;

use Yay_Currency\Helpers\YayCurrencyHelper;
use Yay_Currency\Helpers\Helper;

/**
 * Class YayCurrencyCompatibility
 *
 * YayCurrency - WooCommerce Multi-Currency Switcher by YayCommerce support.
 *
 * Note: YayCurrency has built-in support for CTXFeed via their WooCommerceProductFeed compatibility class.
 * They handle currency switching in `before_woo_feed_generate_batch_data` hook.
 * This class complements their implementation by:
 * - Adding currency suffix to product links
 * - Handling fixed prices per currency
 * - Managing out of stock visibility
 *
 * @package CTXFeed\V5\Compatibility
 * @link https://yaycommerce.com/yaycurrency-woocommerce-multi-currency-switcher/
 */
class YayCurrencyCompatibility {

	/**
	 * Target currency for feed.
	 *
	 * @var array
	 */
	private $apply_currency;

	/**
	 * YayCurrencyCompatibility Constructor.
	 */
	public function __construct() {
		// Handle out of stock visibility.
		add_action( 'before_woo_feed_generate_batch_data', array( $this, 'setup_feed_generation' ), 10, 1 );
		add_action( 'after_woo_feed_generate_batch_data', array( $this, 'cleanup_feed_generation' ), 10, 1 );

		// Handle fixed prices per currency (YayCurrency doesn't handle this for CTXFeed).
		add_filter( 'woo_feed_filter_product_regular_price', array( $this, 'get_fixed_price_if_available' ), 9, 5 );
		add_filter( 'woo_feed_filter_product_price', array( $this, 'get_fixed_price_if_available' ), 9, 5 );
		add_filter( 'woo_feed_filter_product_sale_price', array( $this, 'get_fixed_price_if_available' ), 9, 5 );
		add_filter( 'woo_feed_filter_product_regular_price_with_tax', array( $this, 'get_fixed_price_if_available' ), 9, 5 );
		add_filter( 'woo_feed_filter_product_price_with_tax', array( $this, 'get_fixed_price_if_available' ), 9, 5 );
		add_filter( 'woo_feed_filter_product_sale_price_with_tax', array( $this, 'get_fixed_price_if_available' ), 9, 5 );

		// Add currency suffix to product link.
		add_filter( 'woo_feed_filter_product_link', array( $this, 'get_product_link_with_suffix' ), 10, 3 );
	}

	/**
	 * Setup before feed generation
	 *
	 * @param \CTXFeed\V5\Utility\Config $config feed config array.
	 */
	public function setup_feed_generation( $config ) {
		if ( ! class_exists( 'Yay_Currency\Helpers\YayCurrencyHelper' ) ) {
			return;
		}

		$feed_currency        = $config->get_feed_currency();
		$this->apply_currency = YayCurrencyHelper::get_currency_by_currency_code( $feed_currency );

		// WooCommerce Out of Stock visibility override.
		if ( ! $config->get_outofstock_visibility() ) {
			return;
		}

		add_filter( 'pre_option_woocommerce_hide_out_of_stock_items', '__return_false', 999 );
	}

	/**
	 * Cleanup after feed generation
	 *
	 * @param \CTXFeed\V5\Utility\Config $config feed config array.
	 */
	public function cleanup_feed_generation( $config ) {
		// WooCommerce Out of Stock visibility override.
		if ( ! $config->get_outofstock_visibility() ) {
			return;
		}

		remove_filter( 'pre_option_woocommerce_hide_out_of_stock_items', '__return_false', 999 );
	}

	/**
	 * Get fixed price if available for the currency
	 *
	 * YayCurrency stores fixed prices as post meta with pattern: regular_price_{CURRENCY_CODE}
	 * This method checks for fixed prices and returns them if available.
	 *
	 * @param float                      $price product price.
	 * @param \WC_Product                $product product object.
	 * @param \CTXFeed\V5\Utility\Config $config config object.
	 * @param bool                       $with_tax price with tax or without tax.
	 * @param string                     $price_type price type regular_price, price, sale_price.
	 *
	 * @return float
	 */
	public function get_fixed_price_if_available( $price, $product, $config, $with_tax, $price_type ) {// phpcs:ignore
		if ( ! class_exists( 'Yay_Currency\Helpers\Helper' ) ) {
			return $price;
		}

		$feed_currency    = $config->get_feed_currency();
		$default_currency = Helper::default_currency_code();

		// Skip if using default currency.
		if ( empty( $feed_currency ) || $feed_currency === $default_currency ) {
			return $price;
		}

		// Check for fixed price.
		$fixed_price = $this->get_fixed_price( $product, $feed_currency, $price_type );

		if ( false !== $fixed_price && $fixed_price > 0 ) {
			return $fixed_price;
		}

		return $price;
	}

	/**
	 * Get fixed price for a product in a specific currency.
	 *
	 * @param \WC_Product $product product object.
	 * @param string      $currency_code currency code.
	 * @param string      $price_type price type.
	 *
	 * @return float|false
	 */
	private function get_fixed_price( $product, $currency_code, $price_type ) {
		$product_id = $product->get_id();

		// YayCurrency stores fixed prices with meta key pattern: regular_price_{CURRENCY_CODE}
		switch ( $price_type ) {
			case 'regular_price':
				$fixed_price = get_post_meta( $product_id, "regular_price_{$currency_code}", true );
				break;
			case 'sale_price':
				$fixed_price = get_post_meta( $product_id, "sale_price_{$currency_code}", true );
				break;
			case 'price':
				// Return sale price if available, otherwise regular price.
				$sale_price = get_post_meta( $product_id, "sale_price_{$currency_code}", true );
				if ( ! empty( $sale_price ) && is_numeric( $sale_price ) && floatval( $sale_price ) > 0 ) {
					$fixed_price = $sale_price;
				} else {
					$fixed_price = get_post_meta( $product_id, "regular_price_{$currency_code}", true );
				}
				break;
			default:
				return false;
		}

		if ( ! empty( $fixed_price ) && is_numeric( $fixed_price ) ) {
			return floatval( $fixed_price );
		}

		return false;
	}

	/**
	 * Get product link with currency suffix.
	 *
	 * @param string                     $link product link.
	 * @param \WC_Product                $product product object.
	 * @param \CTXFeed\V5\Utility\Config $config config object.
	 *
	 * @return string
	 */
	public function get_product_link_with_suffix( $link, $product, $config ) { // phpcs:ignore
		if ( ! class_exists( 'Yay_Currency\Helpers\Helper' ) ) {
			return $link;
		}

		$feed_currency    = $config->get_feed_currency();
		$default_currency = Helper::default_currency_code();

		// Only add suffix if feed currency differs from default.
		if ( empty( $feed_currency ) || $feed_currency === $default_currency ) {
			return $link;
		}

		// YayCurrency uses 'yay-currency' as URL parameter (customizable via filter).
		$param_name      = apply_filters( 'yay_currency_param_name', 'yay-currency' );
		$jointer         = substr( $link, -1 ) === '/' ? '?' : '&';
		$currency_suffix = $jointer . $param_name . '=' . $feed_currency;

		$link .= $currency_suffix;

		return $link;
	}

}
