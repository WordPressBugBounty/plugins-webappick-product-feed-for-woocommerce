<?php
/**
 * PricingEndpoint — CTX Feed Pro prices for the admin app (CBT-688).
 *
 * GET /ctxfeed/v8/pricing returns the Pro prices from webappick.com via
 * Admin\ProPricing (12-hour cache, last good copy, bundled fallback).
 *
 * @package    CTXFeed
 * @subpackage V8\API
 * @since      8.0.31
 */

namespace CTXFeed\V8\API;

use CTXFeed\V8\Admin\ProPricing;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Pricing" REST endpoint.
 *
 * @since 8.0.31
 */
class PricingEndpoint extends RestController {

	/**
	 * Register the /pricing route.
	 *
	 * @since 8.0.31
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/pricing',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_pricing' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				'schema' => array( $this, 'get_response_schema' ),
			)
		);
	}

	/**
	 * GET /pricing.
	 *
	 * @since 8.0.31
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_pricing( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		return $this->success( ProPricing::get() );
	}

	/**
	 * Response schema for GET /pricing (CBT-588).
	 *
	 * @since 8.0.31
	 *
	 * @return array
	 */
	public function get_response_schema(): array {
		$amounts = array(
			'type'       => 'object',
			'properties' => array(
				'price'   => array( 'type' => 'number' ),
				'regular' => array( 'type' => 'number' ),
			),
		);
		$tier    = array(
			'type'       => 'object',
			'properties' => array(
				'year' => $amounts,
				'life' => $amounts,
			),
		);

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'ctxfeed-pricing',
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'data'    => array(
					'type'       => 'object',
					'properties' => array(
						'source'   => array(
							'type' => 'string',
							'enum' => array( 'live', 'cached', 'builtin' ),
						),
						'currency' => array(
							'type'       => 'object',
							'properties' => array(
								'code'   => array( 'type' => 'string' ),
								'prefix' => array( 'type' => 'string' ),
								'suffix' => array( 'type' => 'string' ),
							),
						),
						'tiers'    => array(
							'type'       => 'object',
							'properties' => array(
								'single' => $tier,
								'five'   => $tier,
								'ten'    => $tier,
							),
						),
					),
				),
			),
		);
	}
}
