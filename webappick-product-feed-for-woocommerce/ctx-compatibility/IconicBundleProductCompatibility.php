<?php
/**
 * Compatibility class for Iconic WooCommerce Bundled Products plugin.
 *
 * @package CTXFeed\Compatibility
 * @link    https://iconicwp.com/products/woocommerce-bundled-products/
 */

namespace CTXFeed\Compatibility;

use CTXFeed\V5\Helper\ProductHelper;

/**
 * Class IconicBundleProductCompatibility
 *
 * Handles price calculation for Iconic WooCommerce Bundled Products.
 *
 * @package CTXFeed\Compatibility
 */
class IconicBundleProductCompatibility {

	/**
	 * IconicBundleProductCompatibility Constructor.
	 */
	public function __construct() {
		// Regular price hooks.
		add_filter( 'woo_feed_filter_product_regular_price', array( $this, 'regular_price' ), 10, 5 );
		add_filter( 'woo_feed_filter_product_regular_price_with_tax', array( $this, 'regular_price' ), 10, 5 );

		// Price hooks.
		add_filter( 'woo_feed_filter_product_price', array( $this, 'price' ), 10, 5 );
		add_filter( 'woo_feed_filter_product_price_with_tax', array( $this, 'price' ), 10, 5 );

		// Sale price hooks.
		add_filter( 'woo_feed_filter_product_sale_price', array( $this, 'sale_price' ), 10, 5 );
		add_filter( 'woo_feed_filter_product_sale_price_with_tax', array( $this, 'sale_price' ), 10, 5 );
	}

	/**
	 * Get Iconic Bundle Product price.
	 *
	 * @param float                      $price      Product price.
	 * @param \WC_Product                $product    Product object.
	 * @param \CTXFeed\V5\Utility\Config $config     Config object.
	 * @param bool                       $with_tax   Price with tax or without tax.
	 * @param string                     $price_type Price type: regular_price, price, sale_price.
	 *
	 * @return float|string
	 */
	private function get_bundle_price( $price, $product, $config, $with_tax = false, $price_type = 'price' ) {
		// Check if Iconic Bundled Products plugin is active.
		if ( ! class_exists( 'WC_Product_Bundled' ) ) {
			return $price;
		}

		// Check if this is an Iconic Bundle Product (bundle type).
		if ( ! $product->is_type( 'bundle' ) ) {
			return $price;
		}

		// Get bundle product instance.
		$bundle = new \WC_Product_Bundled( $product->get_id() );

		if ( ! $bundle ) {
			return $price;
		}

		$calculated_price = 0;

		// Get price display mode.
		$price_display = '';
		if ( isset( $bundle->options['price_display'] ) && ! is_null( $bundle->options['price_display'] ) ) {
			$price_display = $bundle->options['price_display'];
		}

		// Get bundled product IDs.
		$product_ids = isset( $bundle->options['product_ids'] ) ? $bundle->options['product_ids'] : array();

		// Set discount.
		$discount = 0;
		if ( ! empty( $bundle->options['fixed_discount'] ) ) {
			$discount = floatval( $bundle->options['fixed_discount'] );
		}

		// Calculate price from bundled products.
		if ( is_array( $product_ids ) && ! empty( $product_ids ) ) {
			$prices = array();

			foreach ( $product_ids as $pid ) {
				$bundled_product = wc_get_product( $pid );

				if ( ! $bundled_product ) {
					continue;
				}

				switch ( $price_type ) {
					case 'regular_price':
						$bundled_price = $bundled_product->get_regular_price();
						break;
					case 'sale_price':
						$bundled_price = $bundled_product->is_on_sale() ? $bundled_product->get_sale_price() : '';
						break;
					default:
						$bundled_price = $bundled_product->get_price();
						break;
				}

				if ( '' !== $bundled_price && is_numeric( $bundled_price ) ) {
					$prices[] = floatval( $bundled_price );
				}
			}

			// Calculate final price based on display mode.
			if ( ! empty( $prices ) ) {
				if ( 'range' === $price_display ) {
					$calculated_price = min( $prices );
				} else {
					$calculated_price = array_sum( $prices );
				}
			}
		}

		// Apply discount for sale_price and price types.
		if ( $discount > 0 && 'regular_price' !== $price_type ) {
			$calculated_price = max( 0, $calculated_price - $discount );
		}

		// Convert currency if multi-currency plugin is active.
		$calculated_price = $this->convert_currency( $calculated_price, $product, $config, $price_type );

		// Add tax if required.
		if ( $with_tax && $calculated_price > 0 ) {
			$calculated_price = ProductHelper::get_price_with_tax( $calculated_price, $product );
		}

		return $calculated_price > 0 ? $calculated_price : '';
	}

	/**
	 * Get Regular Price.
	 *
	 * @param float                      $price      Product price.
	 * @param \WC_Product                $product    Product object.
	 * @param \CTXFeed\V5\Utility\Config $config     Config object.
	 * @param bool                       $with_tax   Price with tax or without tax.
	 * @param string                     $price_type Price type.
	 *
	 * @return float|string
	 */
	public function regular_price( $price, $product, $config, $with_tax, $price_type ) {
		return $this->get_bundle_price( $price, $product, $config, $with_tax, 'regular_price' );
	}

	/**
	 * Get Price.
	 *
	 * @param float                      $price      Product price.
	 * @param \WC_Product                $product    Product object.
	 * @param \CTXFeed\V5\Utility\Config $config     Config object.
	 * @param bool                       $with_tax   Price with tax or without tax.
	 * @param string                     $price_type Price type.
	 *
	 * @return float|string
	 */
	public function price( $price, $product, $config, $with_tax, $price_type ) {
		return $this->get_bundle_price( $price, $product, $config, $with_tax, 'price' );
	}

	/**
	 * Get Sale Price.
	 *
	 * @param float                      $price      Product price.
	 * @param \WC_Product                $product    Product object.
	 * @param \CTXFeed\V5\Utility\Config $config     Config object.
	 * @param bool                       $with_tax   Price with tax or without tax.
	 * @param string                     $price_type Price type.
	 *
	 * @return float|string
	 */
	public function sale_price( $price, $product, $config, $with_tax, $price_type ) {
		return $this->get_bundle_price( $price, $product, $config, $with_tax, 'sale_price' );
	}

	/**
	 * Convert currency if multi-currency plugin is active.
	 *
	 * @param float                      $price      Product price.
	 * @param \WC_Product                $product    Product object.
	 * @param \CTXFeed\V5\Utility\Config $config     Config object.
	 * @param string                     $price_type Price type.
	 *
	 * @return float
	 */
	private function convert_currency( $price, $product, $config, $price_type ) {
		return apply_filters(
			'woo_feed_wcml_price',
			$price,
			$product->get_id(),
			$config->get_feed_currency(),
			'_' . $price_type
		);
	}

}
