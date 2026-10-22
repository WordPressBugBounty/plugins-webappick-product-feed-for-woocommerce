<?php
/**
 * GuestCart — run third-party discount pricing against an empty guest cart.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.40
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feeds price as a logged-out visitor (CBT-739). GuestContext switches the
 * USER only: a background run started from wp-admin (admin-ajax Action
 * Scheduler runner) still carries the admin's WooCommerce cart, so discount
 * plugins' cart conditions ("subtotal over 100", "3 items in the cart")
 * applied the admin's cart to the feed price (CBT-823: 40.5 instead of the
 * guest's 45).
 *
 * The run() helper hides the cart CONTENTS from the discount call only — WooCommerce's
 * `woocommerce_get_cart_contents` and Woo Discount Rules' own
 * `advanced_woo_discount_rules_get_cart` answer an empty cart, removed again
 * in a finally. WC()->cart, the session and the customer are left alone:
 * currency switchers keep the visitor currency in the session, and swapping
 * it re-priced every switched feed (found while building this). Re-entrant
 * calls run directly.
 *
 * @since 8.0.40
 */
final class GuestCart {

	/**
	 * Filters that answer an empty cart while run() is active.
	 *
	 * @var string[]
	 */
	const CART_FILTERS = array( 'woocommerce_get_cart_contents', 'advanced_woo_discount_rules_get_cart' );

	/**
	 * Whether a call is in progress.
	 *
	 * @var bool
	 */
	private static $active = false;

	/**
	 * Run `$callback()` while every cart read sees an empty guest cart.
	 *
	 * @since 8.0.40
	 *
	 * @param callable $callback Work (a discount plugin's price call).
	 * @return mixed The callback's return value.
	 */
	public static function run( callable $callback ) {
		if ( self::$active || ! function_exists( 'add_filter' ) || ! function_exists( 'remove_filter' ) ) {
			return $callback();
		}

		self::$active = true;
		foreach ( self::CART_FILTERS as $hook ) {
			add_filter( $hook, array( self::class, 'empty_cart' ), PHP_INT_MAX );
		}
		try {
			return $callback();
		} finally {
			foreach ( self::CART_FILTERS as $hook ) {
				remove_filter( $hook, array( self::class, 'empty_cart' ), PHP_INT_MAX );
			}
			self::$active = false;
		}
	}

	/**
	 * An empty cart (filter callback).
	 *
	 * @since 8.0.40
	 *
	 * @return array
	 */
	public static function empty_cart(): array {
		return array();
	}
}
