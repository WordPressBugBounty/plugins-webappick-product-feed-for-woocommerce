<?php
/**
 * WooContext — make WooCommerce's request-dependent includes available.
 *
 * WooCommerce loads part of itself only for what it considers a "frontend"
 * request, and feed generation is never one: Action Scheduler, WP-Cron and
 * the REST API are all explicitly excluded by
 * {@see \WooCommerce::is_request()}. Core still calls those functions from
 * code paths a feed run reaches, so the plugin loads them itself.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.26
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce request-context helpers.
 *
 * @since 8.0.26
 */
class WooContext {

	/**
	 * Function whose absence proves the cart helpers were not loaded.
	 *
	 * `WC_Customer::get_taxable_address()` calls it unguarded for its
	 * local-pickup base-tax check, and every tax lookup that resolves
	 * through a customer reaches it — so on a cron run it takes the whole
	 * batch down, one caught fatal per product (CBT-629 sibling, CBT-631).
	 *
	 * @since 8.0.26
	 * @var string
	 */
	const CART_PROBE = 'wc_get_chosen_shipping_method_ids';

	/**
	 * Ensure WooCommerce's cart helper functions are defined.
	 *
	 * WooCommerce includes `wc-cart-functions.php` from
	 * `frontend_includes()`, and `is_request( 'frontend' )` is false for
	 * `DOING_CRON` and for REST requests — which is exactly how feeds
	 * generate. The file itself only declares functions plus two hooks
	 * (`woocommerce_add_to_cart_validation` and a `template_redirect`
	 * cart-clear that never fires in a background request), so loading it
	 * early is safe and idempotent.
	 *
	 * @since 8.0.26
	 *
	 * @return bool True when the helpers are available.
	 */
	public static function ensure_cart_functions(): bool {
		if ( function_exists( self::CART_PROBE ) ) {
			return true;
		}

		if ( ! defined( 'WC_ABSPATH' ) ) {
			return false;
		}

		$file = WC_ABSPATH . 'includes/wc-cart-functions.php';

		if ( ! file_exists( $file ) ) {
			return false;
		}

		include_once $file;

		return function_exists( self::CART_PROBE );
	}
}
