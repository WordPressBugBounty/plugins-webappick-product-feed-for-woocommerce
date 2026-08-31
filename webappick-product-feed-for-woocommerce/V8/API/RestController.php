<?php
/**
 * RestController — Abstract base for all V8 REST API endpoints.
 *
 * Provides shared authentication via `manage_woocommerce` capability,
 * standardised success/error response formatting, and the common
 * `/ctxfeed/v8/` namespace.
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.0
 * @implements API-FRD-1.1
 */

namespace CTXFeed\V8\API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract REST controller base class.
 *
 * @since 8.0.0
 */
abstract class RestController extends \WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	protected $namespace = 'ctxfeed/v8';

	/**
	 * Check if the current user can manage feeds.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-1.1
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return bool True if user has `manage_woocommerce` capability.
	 */
	public function permission_check( \WP_REST_Request $request ): bool { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST permission_callback signature.
		return current_user_can( 'manage_woocommerce' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- manage_woocommerce is registered by WooCommerce core.
	}

	/**
	 * Format a success response.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-1.1
	 *
	 * @param mixed $data   Response data.
	 * @param int   $status HTTP status code.
	 * @return \WP_REST_Response
	 */
	protected function success( $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			$status 
		);
	}

	/**
	 * Format an error response.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-1.1
	 *
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 * @return \WP_REST_Response
	 */
	protected function error( string $message, int $status = 400 ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => false,
				'message' => $message,
				'error'   => $message,
			),
			$status 
		);
	}
}
