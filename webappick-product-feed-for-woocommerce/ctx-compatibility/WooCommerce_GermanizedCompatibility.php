<?php
/**
 * Compatibility class for WooCommerce Germanized plugin.
 *
 * @package CTXFeed\Compatibility
 * @link    https://wordpress.org/plugins/woocommerce-germanized/
 */

namespace CTXFeed\Compatibility;

use CTXFeed\V5\Helper\ProductHelper;

/**
 * Class WooCommerce_GermanizedCompatibility
 *
 * Handles Germanized-specific product attributes like unit pricing.
 *
 * @package CTXFeed\Compatibility
 */
class WooCommerce_GermanizedCompatibility {

	/**
	 * WooCommerce_GermanizedCompatibility Constructor.
	 */
	public function __construct() {
		// Unit price measure hook.
		add_filter( 'woo_feed_filter_unit_price_measure', array( $this, 'unit_price_measure' ), 10, 3 );

		// Unit price base measure hook.
		add_filter( 'woo_feed_filter_unit_price_base_measure', array( $this, 'unit_price_base_measure' ), 10, 3 );
	}

	/**
	 * Get unit price measure from WooCommerce Germanized.
	 *
	 * @param string                     $unit_price_measure Current value.
	 * @param \WC_Product                $product            Product object.
	 * @param \CTXFeed\V5\Utility\Config $config             Config object.
	 *
	 * @return string
	 */
	public function unit_price_measure( $unit_price_measure, $product, $config ) {
		// Only provide value if CTXFeed's own unit pricing is not set.
		if ( ! empty( $unit_price_measure ) ) {
			return $unit_price_measure;
		}

		if ( ! class_exists( 'WooCommerce_Germanized' ) ) {
			return $unit_price_measure;
		}

		$unit  = ProductHelper::get_product_meta( '_unit', $product, $config );
		$value = ProductHelper::get_product_meta( '_unit_product', $product, $config );

		if ( ! empty( $value ) ) {
			return $value . ' ' . $unit;
		}

		return $unit_price_measure;
	}

	/**
	 * Get unit price base measure from WooCommerce Germanized.
	 *
	 * @param string                     $unit_price_base_measure Current value.
	 * @param \WC_Product                $product                 Product object.
	 * @param \CTXFeed\V5\Utility\Config $config                  Config object.
	 *
	 * @return string
	 */
	public function unit_price_base_measure( $unit_price_base_measure, $product, $config ) {
		// Only provide value if CTXFeed's own unit pricing is not set.
		if ( ! empty( $unit_price_base_measure ) ) {
			return $unit_price_base_measure;
		}

		if ( ! class_exists( 'WooCommerce_Germanized' ) ) {
			return $unit_price_base_measure;
		}

		$unit  = ProductHelper::get_product_meta( '_unit', $product, $config );
		$value = ProductHelper::get_product_meta( '_unit_base', $product, $config );

		if ( ! empty( $value ) ) {
			return $value . ' ' . $unit;
		}

		return $unit_price_base_measure;
	}

}
