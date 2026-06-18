<?php
/**
 * Compatibility class for WC_Aelia_CurrencySwitcher plugin
 *
 * @package CTXFeed\V5\Compatibility
 */

namespace CTXFeed\Compatibility;

/**
 * Class WC_Aelia_CurrencySwitcherCompatibility
 *
 * @package CTXFeed\V5\Compatibility
 */
class WC_Aelia_CurrencySwitcherCompatibility {

	/**
	 * WC_Aelia_CurrencySwitcherCompatibility Constructor.
	 */
	public function __construct() {
		add_action( 'before_woo_feed_generate_batch_data', array( $this, 'switch_currency' ), 10, 1 );

		// Add currency suffix to product link.
		add_filter( 'woo_feed_filter_product_link', array( $this, 'get_product_link_with_suffix' ), 10, 3 );

		// Add Aelia currencies to dropdown options.
		add_filter( 'ctx_feed_active_currencies', array( $this, 'get_active_currencies' ), 10, 1 );
	}

	/**
	 * Switch currency before feed generation
	 *
	 * @param \CTXFeed\V5\Utility\Config $config feed config array.
	 */
	public function switch_currency( $config ) {
		$currency_code = $config->get_feed_currency();

		add_filter(
			'wc_aelia_cs_selected_currency',
			function ( $selected_currency ) use ( $currency_code ) { // phpcs:ignore
				return $currency_code;
			},
			99999
		);

		// WooCommerce Out of Stock visibility override
		if ( ! $config->get_outofstock_visibility() ) {
			return;
		}

		// just return false as wc expect the value should be 'yes' with eqeqeq (===) operator.
		add_filter( 'pre_option_woocommerce_hide_out_of_stock_items', '__return_false', 999 );
	}

	/**
	 * Get product link with currency suffix.
	 *
	 * @param string                     $link product link.
	 * @param \WC_Product                $product product object.
	 * @param \CTXFeed\V5\Utility\Config $config config object.
	 * @return string
	 */
	public function get_product_link_with_suffix( $link, $product, $config ) { // phpcs:ignore
		$jointer         = substr( $link,  - 1 ) == '/' ? '?' : '&';
		$currency_suffix = $jointer . 'aelia_cs_currency=' . $config->get_feed_currency();

		$link .= $currency_suffix;

		return $link;
	}

	/**
	 * Get active Aelia currencies for dropdown.
	 *
	 * @param array $currencies Existing currencies array.
	 *
	 * @return array
	 */
	public function get_active_currencies( $currencies ) {
		if ( ! class_exists( 'WC_Aelia_CurrencySwitcher' ) ) {
			return $currencies;
		}

		$base_currency  = get_woocommerce_currency();
		$get_currencies = apply_filters( 'wc_aelia_cs_enabled_currencies', $base_currency );

		if ( ! empty( $get_currencies ) ) {
			if ( is_array( $get_currencies ) ) {
				foreach ( $get_currencies as $currency ) {
					$currencies[ $currency ] = $currency;
				}
			} elseif ( gettype( $get_currencies ) === 'string' ) {
				$currencies[ $get_currencies ] = $get_currencies;
			}
		}

		return $currencies;
	}

}
