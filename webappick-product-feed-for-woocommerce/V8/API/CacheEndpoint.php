<?php
/**
 * CacheEndpoint — REST API for the user-facing Clear Cache action.
 *
 * Backs the "Clear Cache" button in the Manage Feeds and Make Feed
 * admin screens. Clears every plugin cache (V8 `ctxfeed_*` + V5
 * `__woo_feed_cache_*` transients + object cache) via
 * Utility\Cache::flush_plugin().
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.0
 * @implements API-FRD-4.5
 */

namespace CTXFeed\V8\API;

use CTXFeed\V8\Utility\Cache;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache REST endpoint.
 *
 * @since 8.0.0
 */
class CacheEndpoint extends RestController {

	/**
	 * Cache utility (constructor-injected; defaults for prod wiring).
	 *
	 * @since 8.0.0
	 * @var Cache
	 */
	private $cache;

	/**
	 * Constructor.
	 *
	 * @since 8.0.0
	 *
	 * @param Cache|null $cache Cache utility instance.
	 */
	public function __construct( ?Cache $cache = null ) {
		$this->cache = $cache ?? new Cache();
	}

	/**
	 * Register the cache route.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-4.5
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/cache/clear',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear_cache' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);
	}

	/**
	 * Clear every plugin cache.
	 *
	 * POST /ctxfeed/v8/cache/clear
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function clear_cache( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		$deleted = $this->cache->flush_plugin();

		return $this->success(
			array(
				'deleted' => $deleted,
				'message' => __( 'Cache cleared successfully.', 'woo-feed' ),
			) 
		);
	}
}
