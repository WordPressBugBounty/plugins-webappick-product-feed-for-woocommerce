<?php
/**
 * Compatibility class for WOOMC_APICompatibility plugin
 *
 * @package CTXFeed\V5\Compatibility
 */

namespace CTXFeed\Compatibility;

/**
 * Class WOOMC_APICompatibility
 *
 *  Compatibility with Woocommerce Multi Currency by TIV.NET INC
 *
 * @package CTXFeed\V5\Compatibility
 */
class WOOMC_APICompatibility {

	/**
	 * WOOMC_APICompatibility Constructor.
	 */
	public function __construct() {
		// Get price with currency conversion.
		add_filter( 'woo_feed_filter_product_regular_price', array( $this, 'get_converted_price' ), 10, 5 );
		add_filter( 'woo_feed_filter_product_price', array( $this, 'get_converted_price' ), 10, 5 );
		add_filter( 'woo_feed_filter_product_sale_price', array( $this, 'get_converted_price' ), 10, 5 );
		add_filter( 'woo_feed_filter_product_regular_price_with_tax', array( $this, 'get_converted_price' ), 10, 5 );
		add_filter( 'woo_feed_filter_product_price_with_tax', array( $this, 'get_converted_price' ), 10, 5 );
		add_filter( 'woo_feed_filter_product_sale_price_with_tax', array( $this, 'get_converted_price' ), 10, 5 );

		// Add WOOMC currencies to dropdown options.
		add_filter( 'ctx_feed_active_currencies', array( $this, 'get_active_currencies' ), 10, 1 );
	}

	/**
	 * Currency Convert for Currency Switcher
	 *
	 * @param int                        $price product price.
	 * @param \WC_Product                $product product object.
	 * @param \CTXFeed\V5\Utility\Config $config config object.
	 * @param bool                       $with_tax price with tax or without tax.
	 * @param string                     $price_type price type regular_price, price, sale_price.
	 * @return int
	 */
	public function get_converted_price( $price, $product, $config, $with_tax, $price_type ) {// phpcs:ignore
		$woocommerce_currency = get_option( 'woocommerce_currency' );

		if ( $config->get_feed_currency() !== $woocommerce_currency ) {
			if ( ! empty( $price ) && class_exists( '\WOOMC\API' ) ) {
				$price = \WOOMC\API::convert( $price, $config->get_feed_currency(), $woocommerce_currency );
			}
		}

		return $price;
	}

	/**
	 * Get active WOOMC currencies for dropdown.
	 *
	 * @param array $currencies Existing currencies array.
	 *
	 * @return array
	 */
	public function get_active_currencies( $currencies ) {
		if ( ! class_exists( 'WOOMC\DAO\Factory' ) ) {
			return $currencies;
		}

		$enabled_currencies = \WOOMC\DAO\Factory::getDao()->getEnabledCurrencies();
		if ( ! empty( $enabled_currencies ) && is_array( $enabled_currencies ) ) {
			foreach ( $enabled_currencies as $currency ) {
				$currencies[ $currency ] = $currency;
			}
		}

		return $currencies;
	}

}
