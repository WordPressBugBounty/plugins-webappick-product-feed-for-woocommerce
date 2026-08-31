<?php
/**
 * Cache — transient-backed cache helper for the compat layer.
 *
 * Native (V5-free) replacement for `CTXFeed\V5\Utility\Cache`. Behaviour and
 * the `__woo_feed_cache_` transient prefix are preserved verbatim so the
 * rewritten shims read/write the same cache entries as the live plugin.
 *
 * @package CTXFeed\Compat\Support
 * @since   8.0.0
 */

namespace CTXFeed\Compat\Support;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient cache helper.
 *
 * @since 8.0.0
 */
class Cache {

	/**
	 * Get cached data.
	 *
	 * @since 8.0.0
	 *
	 * @param string $key    Cache name.
	 * @param string $prefix Transient prefix.
	 * @return mixed|false False when not found.
	 */
	public static function get( $key, $prefix = '__woo_feed_cache_' ) {
		if ( empty( $key ) ) {
			return false;
		}

		return get_transient( $prefix . $key );
	}

	/**
	 * Set cached data.
	 *
	 * @since 8.0.0
	 *
	 * @param string   $key        Cache name.
	 * @param mixed    $data       Data to cache.
	 * @param int|bool $expiration Seconds until expiry, or false to read the plugin default.
	 * @param string   $prefix     Transient prefix.
	 * @return bool
	 */
	public static function set( $key, $data, $expiration = false, $prefix = '__woo_feed_cache_' ) {
		if ( empty( $key ) ) {
			return false;
		}

		if ( false === $expiration ) {
			$expiration = get_option( 'woo_feed_settings', array( 'cache_ttl' => 6 * HOUR_IN_SECONDS ) );
			$expiration = (int) $expiration['cache_ttl'];
		}

		return set_transient( $prefix . $key, $data, $expiration );
	}

	/**
	 * Delete a cache entry.
	 *
	 * @since 8.0.0
	 *
	 * @param string $key    Cache name.
	 * @param string $prefix Transient prefix.
	 * @return bool
	 */
	public static function delete( $key, $prefix = '__woo_feed_cache_' ) {
		if ( empty( $key ) ) {
			return false;
		}

		return delete_transient( $prefix . $key );
	}

	/**
	 * Delete all plugin cache entries.
	 *
	 * @since 8.0.0
	 *
	 * @return bool|int
	 */
	public static function flush() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bulk transient purge; no WP API deletes transients by LIKE pattern, and caching a DELETE is meaningless.
		return $wpdb->query( "DELETE FROM $wpdb->options WHERE ({$wpdb->options}.option_name LIKE '_transient_timeout___woo_feed_cache_%') OR ({$wpdb->options}.option_name LIKE '_transient___woo_feed_cache_%')" );
	}
}
