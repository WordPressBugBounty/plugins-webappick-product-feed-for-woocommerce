<?php
/**
 * Compatibility class for WCCS_PricingCompatibility plugin
 *
 * @package CTXFeed\Compat\Shims
 */

namespace CTXFeed\Compat\Shims;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCCS_PricingCompatibility
 *
 * PLUGIN: Easy woo-commerce discount plugin
 * URL: https://wordpress.org/plugins/easy-woocommerce-discounts/
 *
 * @package CTXFeed\Compat\Shims
 */
class WCCS_PricingCompatibility {

	/**
	 * WCCS_PricingCompatibility Constructor.
	 */
	public function __construct() {
		add_action( 'before_woo_feed_generate_batch_data', array( $this, 'apply_discount' ) );
	}

	/**
	 * Apply discount for WCCS_PricingCompatibility plugin
	 *
	 * @return void
	 */
	public function apply_discount() {
		// Guard against a breaking plugin update: skip silently if the plugin's
		// price-replace API is not available as this shim expects.
		if ( ! function_exists( 'WCCS' ) ) {
			return;
		}
		$wccs = WCCS();
		if ( ! is_object( $wccs ) || ! isset( $wccs->WCCS_Product_Price_Replace ) ) {
			return;
		}
		$replace = $wccs->WCCS_Product_Price_Replace;
		if ( ! is_object( $replace ) || ! method_exists( $replace, 'set_should_replace_prices' ) ) {
			return;
		}
		$replace->set_should_replace_prices( true )->set_change_regular_price( false )->enable_hooks();
	}
}
