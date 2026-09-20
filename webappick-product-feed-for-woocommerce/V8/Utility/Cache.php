<?php
/**
 * Cache — Transient-based caching for expensive operations.
 *
 * Thin wrapper around WordPress Transients API with a V8-specific prefix
 * and bulk flush capability. Used for channel lists, attribute registries,
 * and AI responses.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.0
 * @implements UTIL-FRD-5.1, UTIL-FRD-5.2, UTIL-FRD-5.3, UTIL-FRD-5.4
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient-based cache service.
 *
 * @since 8.0.0
 */
class Cache {

	/**
	 * Key prefix for all V8 cache entries.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const PREFIX = 'ctxfeed_v8_';

	/**
	 * Default TTL in seconds (1 hour).
	 *
	 * @since 8.0.0
	 * @var int
	 */
	const DEFAULT_TTL = 3600;

	/**
	 * Retrieve a cached value.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-5.1
	 *
	 * @param string $key Cache key (without prefix).
	 *
	 * @return mixed Cached value, or false if not found or expired.
	 */
	public function get( string $key ) {

		return get_transient( self::PREFIX . $key );
	}

	/**
	 * Store a value in the cache.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-5.2
	 *
	 * @param string $key   Cache key (without prefix).
	 * @param mixed  $value Value to cache.
	 * @param int    $ttl   Time-to-live in seconds. Default 3600 (1 hour). 0 = no expiration.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function set( string $key, $value, int $ttl = self::DEFAULT_TTL ): bool {

		return set_transient( self::PREFIX . $key, $value, $ttl );
	}

	/**
	 * Remove a single cached value.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-5.3
	 *
	 * @param string $key Cache key (without prefix).
	 *
	 * @return bool True on success, false on failure.
	 */
	public function delete( string $key ): bool {

		return delete_transient( self::PREFIX . $key );
	}

	/**
	 * Flush ALL V8 cache entries in a single SQL query.
	 *
	 * More efficient than iterating individual delete_transient() calls
	 * when there are many cached entries.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-5.4
	 *
	 * @return int Number of entries deleted.
	 */
	public function flush(): int {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk-deletes the plugin's own transient rows by key prefix; no WP API can enumerate transients by prefix, and caching a cache-flush is meaningless.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . self::PREFIX . '%',
				'_transient_timeout_' . self::PREFIX . '%'
			)
		);

		return ( false === $deleted ) ? 0 : (int) $deleted;
	}

	/**
	 * Flush EVERY plugin cache — the user-facing "Clear Cache" action.
	 *
	 * Clears in one pass:
	 *   • All V8 transients (`ctxfeed_*`): the Utility cache
	 *     (ctxfeed_v8_*), product-ID query snapshots, batch history,
	 *     progress records, filter counts, category-mapping and
	 *     dashboard caches — AND stale generation locks/progress, which
	 *     is deliberate: "Clear Cache" is the support remedy for a feed
	 *     stuck mid-generation.
	 *   • All V5 cache transients (`__woo_feed_cache_*`) — V5 and V8
	 *     coexist in the same install; a user clearing "the plugin
	 *     cache" means both engines.
	 *
	 * This deliberately does NOT call wp_cache_flush(): that would drop the
	 * entire site's object cache (every plugin, theme, and core cache), a
	 * disruptive side effect for a plugin-scoped "Clear Cache" button (owner
	 * decision, 2026-08-03).
	 *
	 * On persistent object-cache installs (Redis/Memcached) transients live
	 * in memory rather than wp_options, so the bulk delete below finds no
	 * rows there. Until 8.0.25 that left the object-cached copies in place
	 * until their TTL — the Make Feed attribute picker (6 h) kept hiding
	 * newly added post metas after "Clear Cache" (CBT-625, #69222). Now
	 * {@see flush_object_cached_transients()} runs as well: every component
	 * deletes its known keys through the transient API (which reaches the
	 * object cache), and the plugin's transient groups are flushed when the
	 * drop-in supports group flushes.
	 *
	 * @since 8.0.0
	 * @since 8.0.25 Also clears object-cached transients (CBT-625).
	 *
	 * @return int Number of option rows deleted (transients + timeouts).
	 */
	public function flush_plugin(): int {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk-deletes the plugin's own transient rows by key prefix; no WP API can enumerate transients by prefix, and caching a cache-flush is meaningless.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE %s OR option_name LIKE %s
				    OR option_name LIKE %s OR option_name LIKE %s",
				'_transient_ctxfeed_%',
				'_transient_timeout_ctxfeed_%',
				'_transient___woo_feed_cache_%',
				'_transient_timeout___woo_feed_cache_%'
			)
		);

		/**
		 * Fires after the plugin cache has been cleared.
		 *
		 * Pro / extensions hook here to drop their own caches.
		 *
		 * @since 8.0.0
		 *
		 * @param int $deleted Option rows deleted.
		 */
		$this->flush_object_cached_transients();

		do_action( 'ctxfeed_cache_cleared', ( false === $deleted ) ? 0 : (int) $deleted );

		return ( false === $deleted ) ? 0 : (int) $deleted;
	}

	/**
	 * Drop the plugin's transients from a persistent object cache.
	 *
	 * Two passes, because drop-ins differ (CBT-625):
	 *
	 *   1. `ctxfeed_flush_transients` — every component that owns transient
	 *      keys deletes them through delete_transient(), which reaches the
	 *      object cache on any drop-in. The API layer hooks the Make Feed
	 *      picker and Filters-tab caches here (ProductCacheInvalidator).
	 *   2. When an external object cache is in use and the drop-in supports
	 *      group flushes (Redis Object Cache ≥ 2.1, Object Cache Pro, WP
	 *      core's own cache since 6.1), flush the `transient` and
	 *      `site-transient` groups so the per-feed keys we cannot enumerate
	 *      (progress, batch history, locks, filter counts) go too. This is
	 *      still NOT wp_cache_flush(): posts, terms, options and every other
	 *      group stay warm — only transients, which regenerate on demand by
	 *      design, are dropped.
	 *
	 * Without an external object cache the transient rows are already gone
	 * (the SQL in flush_plugin()), so only the first pass runs.
	 *
	 * @since 8.0.25
	 *
	 * @return bool True when the object cache's transient groups were flushed.
	 */
	public function flush_object_cached_transients(): bool {
		/**
		 * Components delete the transient keys they own.
		 *
		 * @since 8.0.25
		 */
		do_action( 'ctxfeed_flush_transients' );

		if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			return false;
		}

		if ( ! function_exists( 'wp_cache_supports' ) || ! wp_cache_supports( 'flush_group' ) ) {
			return false;
		}

		$flushed = (bool) wp_cache_flush_group( 'transient' );
		wp_cache_flush_group( 'site-transient' );

		return $flushed;
	}
}
