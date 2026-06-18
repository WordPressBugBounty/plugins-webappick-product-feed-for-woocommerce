<?php
/**
 * Compatibility class for WooCommerce Subscriptions plugin.
 *
 * @package CTXFeed\Compatibility
 * @link    https://woocommerce.com/products/woocommerce-subscriptions/
 */

namespace CTXFeed\Compatibility;

use CTXFeed\V5\Helper\ProductHelper;

/**
 * Class WC_SubscriptionsCompatibility
 *
 * Handles subscription product attributes for WooCommerce Subscriptions plugin.
 *
 * @package CTXFeed\Compatibility
 */
class WC_SubscriptionsCompatibility {

	/**
	 * WC_SubscriptionsCompatibility Constructor.
	 */
	public function __construct() {
		// Subscription period hook.
		add_filter( 'woo_feed_filter_subscription_period', array( $this, 'subscription_period' ), 10, 3 );

		// Subscription period interval hook.
		add_filter( 'woo_feed_filter_subscription_period_interval', array( $this, 'subscription_period_interval' ), 10, 3 );

		// Installment months hook.
		add_filter( 'woo_feed_filter_installment_months', array( $this, 'installment_months' ), 10, 3 );
	}

	/**
	 * Get subscription period.
	 *
	 * @param string                     $period  Current period value.
	 * @param \WC_Product                $product Product object.
	 * @param \CTXFeed\V5\Utility\Config $config  Config object.
	 *
	 * @return string
	 */
	public function subscription_period( $period, $product, $config ) {
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return $period;
		}

		return ProductHelper::get_product_meta( '_subscription_period', $product, $config );
	}

	/**
	 * Get subscription period interval.
	 *
	 * @param string                     $interval Current interval value.
	 * @param \WC_Product                $product  Product object.
	 * @param \CTXFeed\V5\Utility\Config $config   Config object.
	 *
	 * @return string
	 */
	public function subscription_period_interval( $interval, $product, $config ) {
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return $interval;
		}

		return ProductHelper::get_product_meta( '_subscription_period_interval', $product, $config );
	}

	/**
	 * Get installment months (subscription length).
	 *
	 * @param string                     $months  Current months value.
	 * @param \WC_Product                $product Product object.
	 * @param \CTXFeed\V5\Utility\Config $config  Config object.
	 *
	 * @return string
	 */
	public function installment_months( $months, $product, $config ) {
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return $months;
		}

		return ProductHelper::get_product_meta( '_subscription_length', $product, $config );
	}

}
