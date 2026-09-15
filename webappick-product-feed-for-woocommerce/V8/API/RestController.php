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
	 * Validate that a string parameter is present and not blank after trimming.
	 *
	 * Core's type check accepts any string, including ''; this closes that gap
	 * uniformly so callers get a structured rest_invalid_param 400 naming the
	 * parameter instead of a handler-specific error (CBT-588).
	 *
	 * @since 8.0.22
	 *
	 * @param mixed            $value   Parameter value.
	 * @param \WP_REST_Request $request REST request object.
	 * @param string           $param   Parameter name.
	 * @return true|\WP_Error
	 */
	public function validate_non_blank_string( $value, $request, $param ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the validate_callback signature.
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return true;
		}

		return new \WP_Error(
			'rest_invalid_param',
			sprintf(
				/* translators: %s: parameter name. */
				__( '%s must be a non-empty string.', 'woo-feed' ),
				$param
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Format a success response that carries user-facing warnings.
	 *
	 * CBT-588 envelope convention: warnings ride INSIDE data as a flat,
	 * deduplicated string list under the `warnings` key — the shape
	 * CBT-583/587 established — so every consumer (React UI, MCP
	 * abilities, tests) reads one place. An empty merged list adds no key.
	 *
	 * @since 8.0.22
	 * @implements API-FRD-1.1
	 *
	 * @param array $data     Response data (may already carry a warnings list).
	 * @param array $warnings Additional warning strings to surface.
	 * @param int   $status   HTTP status code.
	 * @return \WP_REST_Response
	 */
	protected function success_with_warnings( array $data, array $warnings, int $status = 200 ): \WP_REST_Response {
		$existing = isset( $data['warnings'] ) && is_array( $data['warnings'] ) ? $data['warnings'] : array();
		$merged   = array_values( array_unique( array_merge( array_map( 'strval', $existing ), array_map( 'strval', $warnings ) ) ) );

		if ( ! empty( $merged ) ) {
			$data['warnings'] = $merged;
		}

		return $this->success( $data, $status );
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
