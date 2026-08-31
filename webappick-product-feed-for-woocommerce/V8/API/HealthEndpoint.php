<?php
/**
 * HealthEndpoint — REST API for system health and diagnostics.
 *
 * Returns plugin status, environment info, and runtime details.
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.0
 * @implements API-FRD-4.4
 */

namespace CTXFeed\V8\API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Health REST endpoint.
 *
 * @since 8.0.0
 */
class HealthEndpoint extends RestController {

	/**
	 * Register health route.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-4.4
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/health',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_health' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);
	}

	/**
	 * Get system health information.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_health( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		return $this->success(
			array(
				'status'             => 'ok',
				'engine'             => 'v8',
				'php_version'        => PHP_VERSION,
				'wp_version'         => get_bloginfo( 'version' ),
				'wc_version'         => defined( 'WC_VERSION' ) ? WC_VERSION : 'N/A',
				'memory_limit'       => ini_get( 'memory_limit' ),
				'max_execution_time' => ini_get( 'max_execution_time' ),
			) 
		);
	}
}
