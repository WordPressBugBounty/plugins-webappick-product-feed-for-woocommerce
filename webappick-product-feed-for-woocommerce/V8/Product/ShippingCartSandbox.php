<?php
/**
 * ShippingCartSandbox — a throwaway guest cart for the opt-in cart-API
 * shipping path.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.39
 */

namespace CTXFeed\V8\Product;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs WooCommerce cart work on in-memory guest objects (CBT-820, I11).
 *
 * The cart-API shipping path (filter `ctxfeed_shipping_use_cart_api`) used
 * to call wc_load_cart(), which binds WooCommerce's session to the request's
 * cookies. On an admin-ajax or REST runner that is the admin's own session:
 * the pricing loop emptied the admin's live cart, WooCommerce saved it at
 * shutdown, and the price depended on that customer's address. On cron it
 * created a fresh guest session that was written to the sessions table.
 *
 * run() swaps a session that is never initialised (no cookie, no sessions
 * table, no shutdown save), a guest customer and a cart without session
 * hooks into WC(), runs the callback, and puts the request's own objects
 * back in a finally. Nothing is read from or written to cookies, the
 * sessions table or user meta, so every runner prices the same guest cart.
 *
 * @since 8.0.39
 */
final class ShippingCartSandbox {

	/**
	 * The sandbox cart, built once per request.
	 *
	 * Reused (emptied) for every computation: building a cart registers
	 * hooks, which the sandbox removes once, right after construction.
	 *
	 * @since 8.0.39
	 * @var \WC_Cart|null
	 */
	private static $cart = null;

	/**
	 * Run `$callback( $cart, $customer, $session )` on throwaway guest objects.
	 *
	 * @since 8.0.39
	 *
	 * @param callable $callback Work to run; receives the sandbox cart, customer and session.
	 * @return mixed The callback's return value, or null when WooCommerce's cart classes are unavailable.
	 */
	public static function run( callable $callback ) {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$wc = WC();

		if ( ! is_object( $wc ) || ! class_exists( '\WC_Customer' ) || ! class_exists( '\WC_Session_Handler' ) ) {
			return null;
		}

		$cart = self::cart();

		if ( null === $cart ) {
			return null;
		}

		$previous = array( $wc->session, $wc->customer, $wc->cart );

		// A session that is never init()ed has no cookie, no sessions-table
		// row and no shutdown save; get()/set() work on its in-memory data.
		$session  = new \WC_Session_Handler();
		$customer = new \WC_Customer( 0, false );

		$wc->session  = $session;
		$wc->customer = $customer;
		$wc->cart     = $cart;

		try {
			// false: never touch a logged-in user's persistent cart meta.
			$cart->empty_cart( false );

			return $callback( $cart, $customer, $session );
		} finally {
			$wc->session  = $previous[0];
			$wc->customer = $previous[1];
			$wc->cart     = $previous[2];
		}
	}

	/**
	 * Forget the sandbox cart (tests, long-running processes).
	 *
	 * @since 8.0.39
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$cart = null;
	}

	/**
	 * The request's sandbox cart, built on first use.
	 *
	 * Built with WooCommerce's `woocommerce_cart_session_initialize` filter
	 * returning false (6.9+), so its session layer registers no cookie,
	 * persistent-cart or session-write hooks. Every hook the construction
	 * still adds (the cart's own recalculation listeners, and on older
	 * WooCommerce the session hooks) is removed again, so the sandbox can
	 * never react to the request's real cart.
	 *
	 * @since 8.0.39
	 *
	 * @return \WC_Cart|null Null when the cart class is unavailable or fails to build.
	 */
	private static function cart(): ?\WC_Cart {
		if ( null !== self::$cart ) {
			return self::$cart;
		}

		if ( ! class_exists( '\WC_Cart' ) ) {
			return null;
		}

		$no_session_hooks = static function () {
			return false;
		};

		$before = self::hook_ids();

		add_filter( 'woocommerce_cart_session_initialize', $no_session_hooks, PHP_INT_MAX );

		try {
			$cart = new \WC_Cart();
		} catch ( \Throwable $e ) {
			$cart = null;
		} finally {
			remove_filter( 'woocommerce_cart_session_initialize', $no_session_hooks, PHP_INT_MAX );
		}

		if ( ! $cart instanceof \WC_Cart ) {
			return null;
		}

		self::remove_hooks_added_since( $before );

		self::$cart = $cart;

		return $cart;
	}

	/**
	 * Every registered callback, as "hook|priority|id" keys.
	 *
	 * @since 8.0.39
	 *
	 * @return array<string,bool>
	 */
	private static function hook_ids(): array {
		$ids = array();

		foreach ( self::hooks() as $hook_name => $hook ) {
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( array_keys( (array) $callbacks ) as $id ) {
					$ids[ $hook_name . '|' . $priority . '|' . $id ] = true;
				}
			}
		}

		return $ids;
	}

	/**
	 * Remove every callback registered after the `$before` snapshot.
	 *
	 * @since 8.0.39
	 *
	 * @param array<string,bool> $before Snapshot from hook_ids().
	 * @return void
	 */
	private static function remove_hooks_added_since( array $before ): void {
		$added = array();

		foreach ( self::hooks() as $hook_name => $hook ) {
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( (array) $callbacks as $id => $callback ) {
					if ( ! isset( $before[ $hook_name . '|' . $priority . '|' . $id ] ) && isset( $callback['function'] ) ) {
						$added[] = array( $hook_name, $callback['function'], $priority );
					}
				}
			}
		}

		foreach ( $added as $entry ) {
			remove_filter( $entry[0], $entry[1], $entry[2] );
		}
	}

	/**
	 * The registered hooks.
	 *
	 * @since 8.0.39
	 *
	 * @return array<string,\WP_Hook>
	 */
	private static function hooks(): array {
		$hooks = array();

		if ( ! isset( $GLOBALS['wp_filter'] ) || ! is_array( $GLOBALS['wp_filter'] ) ) {
			return $hooks;
		}

		foreach ( $GLOBALS['wp_filter'] as $hook_name => $hook ) {
			if ( $hook instanceof \WP_Hook ) {
				$hooks[ (string) $hook_name ] = $hook;
			}
		}

		return $hooks;
	}
}
