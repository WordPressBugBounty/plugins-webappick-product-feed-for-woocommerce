<?php
/**
 * FreshOption — re-read an option from the database when a persistent
 * object cache may hold a stale copy.
 *
 * WordPress core's update_option() returns early when the UPDATE changes
 * no rows — BEFORE it refreshes the object cache. So when Redis/Memcached
 * holds an older value than the database (the database already has the new
 * one), every update_option() is a no-op and the stale copy survives every
 * request. A "run once per version" gate built on get_option() then fires
 * on every request (CBT-853: the post-update purge cancelled a disabled
 * feed's manual run ~1.5 times a second).
 *
 * Call {@see self::verify()} only on the rare path where the cached value
 * says "work to do" — it costs one indexed query there and nothing on the
 * normal path.
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
 * Stale-cache-proof option reads for one-shot gates.
 *
 * @since 8.0.40
 */
class FreshOption {

	/**
	 * Confirm a cached option value against the database.
	 *
	 * When the database row differs from `$cached`, the cached copies of the
	 * option are dropped (so later reads in every request see the database
	 * value) and the database value is returned. When the row is missing,
	 * `false` is returned, like get_option() without a default.
	 *
	 * @since 8.0.40
	 *
	 * @param string $option Option name.
	 * @param mixed  $cached The value get_option() returned.
	 * @return mixed The database value (unserialized), or false when absent.
	 */
	public static function verify( string $option, $cached ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The whole point is to bypass a possibly stale object cache.
		$row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );

		$fresh = null === $row ? false : maybe_unserialize( $row );

		if ( maybe_serialize( $fresh ) === maybe_serialize( $cached ) ) {
			return $cached;
		}

		// Stale cache: drop every cached copy the option can live in.
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return $fresh;
	}
}
