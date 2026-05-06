<?php
/**
 * Compatibility class for X-Currency plugin
 *
 * @package CTXFeed\V5\Compatibility
 */

namespace CTXFeed\Compatibility;

/**
 * Class XCurrencyCompatibility
 *
 * X-Currency - WooCommerce Multi-Currency Switcher by Crafium support.
 *
 * This class handles:
 * - Currency switching for feed generation
 * - Price conversion using exchange rates
 * - Fixed prices per currency support
 * - Adding currency suffix to product links
 *
 * @package CTXFeed\V5\Compatibility
 * @link https://wordpress.org/plugins/x-currency/
 */
class XCurrencyCompatibility {

	/**
	 * Original selected currency before feed generation.
	 *
	 * @var object|null
	 */
	private $original_currency;

	/**
	 * Target currency for feed.
	 *
	 * @var object|null
	 */
	private $feed_currency;

	/**
	 * Base currency code.
	 *
	 * @var string
	 */
	private $base_currency_code;

	/**
	 * Exchange rate for the feed currency.
	 *
	 * @var float
	 */
	private $exchange_rate = 1;

	/**
	 * XCurrencyCompatibility Constructor.
	 */
	public function __construct() {
		// Switch currency before/after feed generation.
		add_action( 'before_woo_feed_generate_batch_data', array( $this, 'switch_currency' ), 10, 1 );
		add_action( 'after_woo_feed_generate_batch_data', array( $this, 'restore_currency' ), 10, 1 );

		// Handle price conversion and fixed prices per currency.
		add_filter( 'woo_feed_filter_product_regular_price', array( $this, 'get_converted_price' ), 9, 5 );
		add_filter( 'woo_feed_filter_product_price', array( $this, 'get_converted_price' ), 9, 5 );
		add_filter( 'woo_feed_filter_product_sale_price', array( $this, 'get_converted_price' ), 9, 5 );
		add_filter( 'woo_feed_filter_product_regular_price_with_tax', array( $this, 'get_converted_price' ), 9, 5 );
		add_filter( 'woo_feed_filter_product_price_with_tax', array( $this, 'get_converted_price' ), 9, 5 );
		add_filter( 'woo_feed_filter_product_sale_price_with_tax', array( $this, 'get_converted_price' ), 9, 5 );

		// Add currency suffix to product link.
		add_filter( 'woo_feed_filter_product_link', array( $this, 'get_product_link_with_suffix' ), 10, 3 );
	}

	/**
	 * Switch currency before feed generation.
	 *
	 * @param \CTXFeed\V5\Utility\Config $config feed config array.
	 */
	public function switch_currency( $config ) {
		if ( ! function_exists( 'x_currency_selected' ) || ! function_exists( 'x_currency_base_code' ) ) {
			return;
		}

		$feed_currency_code       = $config->get_feed_currency();
		$this->base_currency_code = x_currency_base_code();

		// Store original currency for restoration.
		$this->original_currency = x_currency_selected();

		// Reset exchange rate.
		$this->exchange_rate = 1;

		// Get feed currency object and exchange rate.
		if ( ! empty( $feed_currency_code ) && $feed_currency_code !== $this->base_currency_code ) {
			$this->feed_currency = $this->get_currency_by_code( $feed_currency_code );

			// Switch to feed currency if found and store exchange rate.
			if ( $this->feed_currency ) {
				$this->set_selected_currency( $this->feed_currency );

				// Get exchange rate from currency object.
				if ( isset( $this->feed_currency->rate ) && $this->feed_currency->rate > 0 ) {
					$this->exchange_rate = floatval( $this->feed_currency->rate );
				}
			}
		}

		// WooCommerce Out of Stock visibility override.
		if ( ! $config->get_outofstock_visibility() ) {
			return;
		}

		add_filter( 'pre_option_woocommerce_hide_out_of_stock_items', '__return_false', 999 );
	}

	/**
	 * Restore currency after feed generation.
	 *
	 * @param \CTXFeed\V5\Utility\Config $config feed config array.
	 */
	public function restore_currency( $config ) {
		// Restore original currency.
		if ( $this->original_currency ) {
			$this->set_selected_currency( $this->original_currency );
		}

		// Reset exchange rate.
		$this->exchange_rate = 1;
		$this->feed_currency = null;

		// WooCommerce Out of Stock visibility override.
		if ( ! $config->get_outofstock_visibility() ) {
			return;
		}

		remove_filter( 'pre_option_woocommerce_hide_out_of_stock_items', '__return_false', 999 );
	}

	/**
	 * Get converted price for the feed currency.
	 *
	 * @param float                      $price product price.
	 * @param \WC_Product                $product product object.
	 * @param \CTXFeed\V5\Utility\Config $config config object.
	 * @param bool                       $with_tax price with tax or without tax.
	 * @param string                     $price_type price type regular_price, price, sale_price.
	 *
	 * @return float
	 */
	public function get_converted_price( $price, $product, $config, $with_tax, $price_type ) { // phpcs:ignore
		if ( ! function_exists( 'x_currency_base_code' ) ) {
			return $price;
		}

		$feed_currency_code = $config->get_feed_currency();
		$base_currency_code = x_currency_base_code();

		// Skip if using base currency or no feed currency specified.
		if ( empty( $feed_currency_code ) || $feed_currency_code === $base_currency_code ) {
			return $price;
		}

		// Skip if price is empty or zero.
		if ( empty( $price ) || ! is_numeric( $price ) ) {
			return $price;
		}

		// Check for fixed price first.
		$fixed_price = $this->get_fixed_price( $product, $feed_currency_code, $price_type );

		if ( false !== $fixed_price && $fixed_price > 0 ) {
			return $fixed_price;
		}

		// Convert price using exchange rate.
		if ( $this->exchange_rate > 0 && $this->exchange_rate != 1 ) {
			$price = floatval( $price ) * $this->exchange_rate;
		}

		return $price;
	}

	/**
	 * Get fixed price for a product in a specific currency.
	 *
	 * X-Currency stores fixed prices in post meta as JSON:
	 * - Simple products: 'x_currency_simple' => {"USD": {"regular_price": "10", "sale_price": ""}, ...}
	 * - Variations: 'x_currency_variation' => {"USD": {"regular_price": "10", "sale_price": ""}, ...}
	 *
	 * @param \WC_Product $product product object.
	 * @param string      $currency_code currency code.
	 * @param string      $price_type price type.
	 *
	 * @return float|false
	 */
	private function get_fixed_price( $product, $currency_code, $price_type ) {
		$product_id = $product->get_id();

		// Determine meta key based on product type.
		$meta_key = $product->is_type( 'variation' ) ? 'x_currency_variation' : 'x_currency_simple';

		$fixed_prices = get_post_meta( $product_id, $meta_key, true );

		if ( empty( $fixed_prices ) ) {
			return false;
		}

		// Decode JSON if string.
		if ( is_string( $fixed_prices ) ) {
			$fixed_prices = json_decode( $fixed_prices, true );
		}

		if ( ! is_array( $fixed_prices ) || ! isset( $fixed_prices[ $currency_code ] ) ) {
			return false;
		}

		$currency_prices = $fixed_prices[ $currency_code ];

		switch ( $price_type ) {
			case 'regular_price':
				$fixed_price = isset( $currency_prices['regular_price'] ) ? $currency_prices['regular_price'] : false;
				break;
			case 'sale_price':
				$fixed_price = isset( $currency_prices['sale_price'] ) ? $currency_prices['sale_price'] : false;
				break;
			case 'price':
				// Return sale price if available and valid, otherwise regular price.
				$sale_price = isset( $currency_prices['sale_price'] ) ? $currency_prices['sale_price'] : '';
				if ( ! empty( $sale_price ) && is_numeric( $sale_price ) && floatval( $sale_price ) > 0 ) {
					$fixed_price = $sale_price;
				} else {
					$fixed_price = isset( $currency_prices['regular_price'] ) ? $currency_prices['regular_price'] : false;
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
		if ( ! function_exists( 'x_currency_base_code' ) ) {
			return $link;
		}

		$feed_currency_code = $config->get_feed_currency();
		$base_currency_code = x_currency_base_code();

		// Only add suffix if feed currency differs from base.
		if ( empty( $feed_currency_code ) || $feed_currency_code === $base_currency_code ) {
			return $link;
		}

		// X-Currency uses 'currency' as URL parameter.
		$jointer         = substr( $link, -1 ) === '/' ? '?' : '&';
		$currency_suffix = $jointer . 'currency=' . $feed_currency_code;

		$link .= $currency_suffix;

		return $link;
	}

	/**
	 * Get currency object by currency code.
	 *
	 * @param string $currency_code Currency code (e.g., 'USD', 'EUR').
	 *
	 * @return object|null Currency object or null if not found.
	 */
	private function get_currency_by_code( $currency_code ) {
		if ( ! function_exists( 'x_currency_singleton' ) || ! class_exists( 'XCurrency\App\Repositories\CurrencyRepository' ) ) {
			return null;
		}

		try {
			$currency_repository = x_currency_singleton( 'XCurrency\App\Repositories\CurrencyRepository' );
			if ( method_exists( $currency_repository, 'get_by_first' ) ) {
				return $currency_repository->get_by_first( 'code', $currency_code );
			}
		} catch ( \Exception $e ) {
			return null;
		}

		return null;
	}

	/**
	 * Set the selected currency.
	 *
	 * @param object $currency Currency object.
	 */
	private function set_selected_currency( $currency ) {
		global $x_currency;

		if ( isset( $x_currency ) && is_array( $x_currency ) ) {
			$x_currency['selected_currency'] = $currency;
		}
	}

}
