<?php
/**
 * WpOptionsEndpoint — REST API for WP Options management.
 *
 * Allows users to "bookmark" WordPress options they want visible
 * in the feed configuration UI. The list of tracked option names
 * is stored in the `wpfp_option` wp_option (V5-compatible).
 *
 * V5 storage format in `wpfp_option`:
 *   [
 *       'siteurl' => [ 'option_id' => 'siteurl', 'option_name' => 'wf_option_siteurl' ],
 *       ...
 *   ]
 *
 * V8 API returns a flat map of option_name => option_value for display.
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.0
 */

namespace CTXFeed\V8\API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Options REST endpoint.
 *
 * @since 8.0.0
 */
class WpOptionsEndpoint extends RestController {

	/**
	 * WordPress option key that stores the tracked options list.
	 * Matches V5 constant for backward compatibility.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const STORAGE_KEY = 'wpfp_option';

	/**
	 * Register WP Options routes.
	 *
	 * @since 8.0.0
	 * @return void
	 */
	public function register_routes(): void {
		// List tracked options / Add a new tracked option.
		register_rest_route(
			$this->namespace,
			'/options',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_options' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_option' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_option' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			) 
		);

		// Get all available WP options for the dropdown.
		register_rest_route(
			$this->namespace,
			'/options/available',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_available_options' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);
	}

	// =====================================================================
	// CRUD endpoints.
	// =====================================================================

	/**
	 * List all tracked WP Options with their current values.
	 *
	 * Returns a flat object { option_name: option_value, ... }
	 * which the React frontend iterates with Object.entries().
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_options( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		$tracked = $this->get_tracked_options();

		$result = array();

		foreach ( $tracked as $key => $item ) {
			// V5 stores option_id as the actual WP option name.
			$option_name = isset( $item['option_id'] ) ? $item['option_id'] : $key;
			$value       = get_option( $option_name, '' );

			// Arrays/objects are flattened to comma-separated string for display.
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			} elseif ( is_object( $value ) ) {
				$value = wp_json_encode( $value );
			}

			$result[ $option_name ] = (string) $value;
		}

		return $this->success( $result );
	}

	/**
	 * Add a WordPress option to the tracked list.
	 *
	 * Saves in V5-compatible format for backward compatibility.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function add_option( \WP_REST_Request $request ): \WP_REST_Response {
		$option_name = sanitize_text_field( $request->get_param( 'option' ) );

		if ( empty( $option_name ) ) {
			return $this->error( __( 'Option name is required.', 'woo-feed' ), 400 );
		}

		$tracked = $this->get_tracked_options();

		// Check for duplicate.
		if ( isset( $tracked[ $option_name ] ) ) {
			return $this->error( __( 'Option already added.', 'woo-feed' ), 400 );
		}

		// Store in V5-compatible format.
		$tracked[ $option_name ] = array(
			'option_id'   => $option_name,
			'option_name' => 'wf_option_' . $option_name,
		);

		update_option( self::STORAGE_KEY, $tracked, false );

		return $this->success(
			array(
				'message' => __( 'Option successfully added.', 'woo-feed' ),
				'option'  => $option_name,
			) 
		);
	}

	/**
	 * Remove a WordPress option from the tracked list.
	 *
	 * Does NOT delete the actual WordPress option — only stops tracking it.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function delete_option( \WP_REST_Request $request ): \WP_REST_Response {
		$option_name = sanitize_text_field( $request->get_param( 'option' ) );

		if ( empty( $option_name ) ) {
			return $this->error( __( 'Option name is required.', 'woo-feed' ), 400 );
		}

		$tracked = $this->get_tracked_options();

		if ( ! isset( $tracked[ $option_name ] ) ) {
			return $this->error( __( 'Option not found in tracked list.', 'woo-feed' ), 404 );
		}

		unset( $tracked[ $option_name ] );
		update_option( self::STORAGE_KEY, $tracked, false );

		return $this->success(
			array(
				'message' => __( 'Option deleted successfully.', 'woo-feed' ),
			) 
		);
	}

	/**
	 * Get all available WordPress option names for the dropdown.
	 *
	 * Uses wp_load_alloptions() to fetch every autoloaded option,
	 * then returns just the names as a flat array of strings.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_available_options( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		$all_options = wp_load_alloptions();
		$names       = array_keys( $all_options );

		// Transients are ephemeral cache entries (values + timeouts), not
		// store settings — feeds must never carry them (owner decision).
		$names = array_values(
			array_filter(
				$names,
				static function ( $name ) {
					return 0 !== strpos( $name, '_transient_' )
					&& 0 !== strpos( $name, '_site_transient_' );
				} 
			) 
		);

		sort( $names );

		return $this->success( $names );
	}

	// =====================================================================
	// Internal helpers.
	// =====================================================================

	/**
	 * Retrieve the tracked options array from the database.
	 *
	 * @since 8.0.0
	 *
	 * @return array Tracked options array (V5 format).
	 */
	private function get_tracked_options(): array {
		$options = get_option( self::STORAGE_KEY, array() );

		if ( ! is_array( $options ) ) {
			return array();
		}

		return $options;
	}
}
