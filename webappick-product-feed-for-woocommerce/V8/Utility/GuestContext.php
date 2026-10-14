<?php
/**
 * GuestContext — resolve feed data as a logged-out visitor.
 *
 * A feed must carry the price a shopper sees on the product page while
 * logged out — that is what Google checks the landing page against.
 * Scheduled runs (WP-Cron / Action Scheduler) already have no user, but a
 * small feed generates inside the admin's own REST request and the browser
 * runner drives batches from the admin's session, so prices were calculated
 * for the admin: member discounts (WooCommerce Memberships), role prices and
 * B2B rules leaked into manual runs, and the price flipped back on the next
 * scheduled run (CBT-739, HelpScout #69187).
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.36
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run a callback with no current user, restoring the caller afterwards.
 *
 * @since 8.0.36
 */
class GuestContext {

	/**
	 * Run `$callback` as a logged-out visitor.
	 *
	 * Switches to user 0 only when someone is logged in, and ALWAYS restores
	 * the caller (even when the callback throws), so the rest of the REST
	 * request — permission checks, the response, notices — still runs as the
	 * admin. Nested calls are no-ops (the inner call already sees user 0).
	 *
	 * @since 8.0.36
	 *
	 * @hook ctxfeed_generate_as_guest Return false to keep the current user
	 *                                 (e.g. a feed that deliberately carries
	 *                                 the logged-in user's prices).
	 *
	 * @param callable $callback Work to run.
	 * @return mixed The callback's return value.
	 */
	public static function run( callable $callback ) {
		$previous = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		$switch = $previous > 0
			&& function_exists( 'wp_set_current_user' )
			&& (bool) apply_filters( 'ctxfeed_generate_as_guest', true, $previous );

		if ( ! $switch ) {
			return $callback();
		}

		wp_set_current_user( 0 );
		try {
			return $callback();
		} finally {
			wp_set_current_user( $previous );
		}
	}
}
