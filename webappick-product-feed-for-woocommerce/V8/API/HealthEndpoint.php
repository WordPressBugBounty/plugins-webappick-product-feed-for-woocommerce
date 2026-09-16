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

use CTXFeed\V8\Product\ProductTypeSupport;
use CTXFeed\V8\Core\FeatureGate;

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
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_health' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(),
				),
				'schema' => array( $this, 'get_health_response_schema' ),
			)
		);
	}

	/**
	 * Response schema for GET /health (CBT-588).
	 *
	 * @since 8.0.22
	 *
	 * @return array
	 */
	public function get_health_response_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'ctxfeed-health',
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'data'    => array(
					'type'       => 'object',
					'properties' => array(
						'status'                    => array(
							'type' => 'string',
							'enum' => array( 'ok' ),
						),
						'engine'                    => array( 'type' => 'string' ),
						'php_version'               => array( 'type' => 'string' ),
						'wp_version'                => array( 'type' => 'string' ),
						'wc_version'                => array( 'type' => 'string' ),
						'memory_limit'              => array( 'type' => 'string' ),
						'max_execution_time'        => array( 'type' => 'string' ),
						// Products the CURRENT install will not export (free only;
						// empty on Pro) — slug => published count (CBT-600).
						'unsupported_product_types' => array(
							'type'       => 'object',
							'properties' => array(
								'total' => array( 'type' => 'integer' ),
								'types' => array(
									'type'                 => 'object',
									'additionalProperties' => array( 'type' => 'integer' ),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Products of a type this install will not export, by type.
	 *
	 * @since 8.0.23
	 *
	 * @return array{total:int,types:array<string,int>}
	 */
	private function unsupported_product_types(): array {
		$types = FeatureGate::is_pro() ? array() : ProductTypeSupport::unsupported_counts();

		return array(
			'total' => (int) array_sum( $types ),
			'types' => (object) $types, // {} when empty, never [] — a stable JSON shape for agents.
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
				'status'                    => 'ok',
				'engine'                    => 'v8',
				'php_version'               => PHP_VERSION,
				'wp_version'                => get_bloginfo( 'version' ),
				'wc_version'                => defined( 'WC_VERSION' ) ? WC_VERSION : 'N/A',
				'memory_limit'              => ini_get( 'memory_limit' ),
				'max_execution_time'        => ini_get( 'max_execution_time' ),
				// Free never exports these product types; Pro adds them to the
				// query, so an active Pro reports none (CBT-600).
				'unsupported_product_types' => $this->unsupported_product_types(),
			)
		);
	}
}
