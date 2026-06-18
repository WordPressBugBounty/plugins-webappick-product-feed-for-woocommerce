<?php
/**
 * Compatibility class for WPC Grouped Product for WooCommerce plugin.
 *
 * @package CTXFeed\Compatibility
 * @link    https://wordpress.org/plugins/wpc-grouped-product/
 */

namespace CTXFeed\Compatibility;

use CTXFeed\V5\Helper\ProductHelper;

/**
 * Class WPCGroupedProductCompatibility
 *
 * Handles price calculation for WPC Grouped Products (woosg product type).
 *
 * @package CTXFeed\Compatibility
 */
class WPCGroupedProductCompatibility {

	/**
	 * WPCGroupedProductCompatibility Constructor.
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
	 * Get WPC Grouped Product price.
	 *
	 * @param float                      $price      Product price.
	 * @param \WC_Product                $product    Product object.
	 * @param \CTXFeed\V5\Utility\Config $config     Config object.
	 * @param bool                       $with_tax   Price with tax or without tax.
	 * @param string                     $price_type Price type: regular_price, price, sale_price.
	 *
	 * @return float|string
	 */
	private function get_grouped_price( $price, $product, $config, $with_tax = false, $price_type = 'price' ) {
		// Check if this is a WPC Grouped Product (woosg type).
		if ( ! $product->is_type( 'woosg' ) ) {
			return $price;
		}

		$grouped_product_ids = get_post_meta( $product->get_id(), 'woosg_ids', true );
		$calculated_price    = 0;

		if ( ! empty( $grouped_product_ids ) && is_array( $grouped_product_ids ) ) {
			foreach ( $grouped_product_ids as $item ) {
				$child_product = wc_get_product( $item['id'] );

				if ( ! is_object( $child_product ) ) {
					continue;
				}

				switch ( $price_type ) {
					case 'regular_price':
						$get_price = $child_product->get_regular_price();
						break;
					case 'sale_price':
						// Only get sale price if product is currently on sale.
						$get_price = $child_product->is_on_sale() ? $child_product->get_sale_price() : '';
						break;
					default:
						$get_price = $child_product->get_price();
						break;
				}

				if ( '' !== $get_price && is_numeric( $get_price ) ) {
					$item_price = (float) $get_price * (float) $item['qty'];

					// Convert currency.
					$item_price = $this->convert_currency( $item_price, $product, $config, $price_type );

					// Add tax if required.
					if ( $with_tax ) {
						$item_price = ProductHelper::get_price_with_tax( $item_price, $child_product );
					}

					$calculated_price += $item_price;
				}
			}
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
		return $this->get_grouped_price( $price, $product, $config, $with_tax, 'regular_price' );
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
		return $this->get_grouped_price( $price, $product, $config, $with_tax, 'price' );
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
		return $this->get_grouped_price( $price, $product, $config, $with_tax, 'sale_price' );
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
