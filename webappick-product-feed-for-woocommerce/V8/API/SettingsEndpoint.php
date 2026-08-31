<?php
/**
 * SettingsEndpoint — REST API for plugin settings management.
 *
 * Handles global settings, AI configuration, and feature toggles
 * via FeatureGate.
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.0
 * @implements API-FRD-4.2
 */

namespace CTXFeed\V8\API;

use CTXFeed\V8\Common\DropdownRegistry;
use CTXFeed\V8\Core\FeatureGate;
use CTXFeed\V8\CustomFields\CustomFieldHelper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings REST endpoint.
 *
 * @since 8.0.0
 */
class SettingsEndpoint extends RestController {

	/**
	 * Register settings routes.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-4.2
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			) 
		);

		register_rest_route(
			$this->namespace,
			'/settings/features',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_features' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		// Dropdown data for admin UI selects.
		register_rest_route(
			$this->namespace,
			'/dropdowns',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_dropdowns' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'type'  => array(
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Dropdown type key. Omit to get all types.', 'woo-feed' ),
					),
					'types' => array(
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Comma-separated list of type keys to batch-fetch.', 'woo-feed' ),
					),
				),
			) 
		);

		// List available dropdown type keys.
		register_rest_route(
			$this->namespace,
			'/dropdowns/types',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_dropdown_types' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);
	}

	/**
	 * WordPress option key for plugin settings.
	 * Shared with V5 for backward compatibility.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const SETTINGS_KEY = 'woo_feed_settings';

	/**
	 * The single cache lifetime (seconds) that governs CTX Feed's data caches.
	 *
	 * One source of truth for every TTL-based data cache — the feed-builder
	 * product-attribute list and the category list, plus the ACF / discount
	 * compat caches — so the "Cache lifetime" setting controls them all
	 * (BUG-0062). Those caches are ALSO busted on the underlying data change
	 * (product / attribute / term save — see ApiServiceProvider and
	 * CategoryMappingEndpoint::register_cache_invalidation_hooks), so this TTL is
	 * a safety net rather than the only freshness guarantee: `0` ("Never expire")
	 * is valid and simply relies on that invalidation, and a positive value below
	 * a minute is floored so a mis-set tiny TTL can't rebuild the expensive lists
	 * on every request.
	 *
	 * @since 8.0.0
	 *
	 * @return int Cache lifetime in seconds (0 = never expire).
	 */
	public static function get_cache_ttl(): int {
		$saved = get_option( self::SETTINGS_KEY, array() );
		$ttl   = isset( $saved['cache_ttl'] ) ? (int) $saved['cache_ttl'] : 6 * HOUR_IN_SECONDS;

		if ( $ttl < 0 ) {
			return 6 * HOUR_IN_SECONDS;
		}

		if ( 0 < $ttl && $ttl < MINUTE_IN_SECONDS ) {
			return MINUTE_IN_SECONDS;
		}

		return $ttl;
	}

	/**
	 * Get default settings values.
	 *
	 * Mirrors V5 defaults from V5\Utility\Settings for compatibility.
	 *
	 * @since 8.0.0
	 *
	 * @return array Default settings array.
	 */
	private function get_defaults(): array {
		$defaults = array(
			'cron_job_new_cron_system_enabled' => false,
			'product_query_type'               => 'wc',
			'variation_query_type'             => 'individual',
			'enable_error_debugging'           => 'off',
			'cache_ttl'                        => 6 * HOUR_IN_SECONDS,
			'overridden_structured_data'       => 'off',
			'disable_mpn'                      => 'enable',
			'disable_brand'                    => 'enable',
			'disable_pixel'                    => 'disable',
			'pixel_id'                         => '',
			'disable_remarketing'              => 'disable',
			'remarketing_id'                   => '',
			'remarketing_label'                => '',
			'pinterest_tag_id'                 => '',
			'pinterest_conversion_tracking'    => 'disable',
			'allow_all_shipping'               => 'no',
			'only_free_shipping'               => 'yes',
			'only_local_pickup_shipping'       => 'no',
			'enable_ftp_upload'                => 'no',
			'enable_cdata'                     => 'yes',
		);

		// V5-actual install defaults for custom-field toggles — only
		// `availability_date` is enabled out-of-the-box. Sourced from
		// CustomFieldHelper::get_install_defaults() so V5 and V8 agree.
		// PROD-FRD-10.3.
		$install_defaults                = CustomFieldHelper::get_install_defaults();
		$defaults['woo_feed_taxonomy']   = $install_defaults['woo_feed_taxonomy'];
		$defaults['woo_feed_identifier'] = $install_defaults['woo_feed_identifier'];

		return apply_filters( 'ctxfeed_settings_defaults', $defaults );
	}

	/**
	 * Get all plugin settings merged with defaults.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_settings( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		$defaults = $this->get_defaults();
		$saved    = get_option( self::SETTINGS_KEY, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = wp_parse_args( $saved, $defaults );

		// Deep-merge nested arrays that wp_parse_args handles only shallowly.
		foreach ( array( 'woo_feed_identifier', 'woo_feed_taxonomy' ) as $nested_key ) {
			if ( isset( $saved[ $nested_key ] ) && is_array( $saved[ $nested_key ] ) ) {
				$settings[ $nested_key ] = wp_parse_args( $saved[ $nested_key ], $defaults[ $nested_key ] );
			}
		}

		// Normalize toggle values: V5 may store booleans (true/false) instead
		// of 'enable'/'disable' strings. Convert to canonical string format.
		$settings = $this->normalize_toggle_values( $settings );

		// Telemetry opt-in lives with the AppServices tracker, not in the
		// settings option — expose it so the Diagnostics toggle hydrates.
		$settings['tracking_opt_in'] = $this->tracking_allowed() ? 'yes' : 'no';

		return $this->success( $settings );
	}

	/**
	 * Whether usage tracking is currently opted in (AppServices tracker).
	 *
	 * Seam for unit tests — the tracker boots WP-heavy machinery.
	 *
	 * @since 8.0.0
	 *
	 * @return bool
	 */
	protected function tracking_allowed(): bool {
		return \CTXFeed\V8\AppServices\AppServices::instance()->is_tracking_allowed();
	}

	/**
	 * Opt the site in to / out of usage tracking via the AppServices tracker.
	 *
	 * Seam for unit tests.
	 *
	 * @since 8.0.0
	 *
	 * @param bool $allow True to opt in, false to opt out.
	 * @return void
	 */
	protected function set_tracking( bool $allow ): void {
		$app = \CTXFeed\V8\AppServices\AppServices::instance();

		if ( $allow ) {
			$app->opt_in();
		} else {
			$app->opt_out();
		}
	}

	/**
	 * Update plugin settings.
	 *
	 * Accepts partial updates — only provided keys are changed.
	 * Validates each key with the same rules as V5 Settings::save().
	 * Supports special action keys: clear_all_logs, purge_feed_cache, opt_in.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function update_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$args     = $request->get_json_params();
		$defaults = $this->get_defaults();
		$saved    = get_option( self::SETTINGS_KEY, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$data     = wp_parse_args( $saved, $defaults );
		$original = $data;
		$actions  = array();

		// Handle special action keys (not persisted as settings).
		if ( ! empty( $args['clear_all_logs'] ) ) {
			$actions[] = 'clear_all_logs';
			( new \CTXFeed\V8\Utility\FeedLogger() )->delete_all();
			unset( $args['clear_all_logs'] );
		}

		if ( ! empty( $args['purge_feed_cache'] ) ) {
			$actions[] = 'purge_feed_cache';
			( new \CTXFeed\V8\Utility\Cache() )->flush_plugin();
			unset( $args['purge_feed_cache'] );
		}

		if ( isset( $args['opt_in'] ) ) {
			$actions[] = 'opt_in';
			$raw       = $args['opt_in'];
			$allow     = is_string( $raw )
				? in_array( strtolower( sanitize_text_field( $raw ) ), array( 'yes', 'on', 'enable', 'true', '1' ), true )
				: (bool) $raw;
			$this->set_tracking( $allow );
			unset( $args['opt_in'] );
		}

		// Validate and apply each setting.
		if ( array_key_exists( 'cron_job_new_cron_system_enabled', $args ) ) {
			$data['cron_job_new_cron_system_enabled'] = (bool) $args['cron_job_new_cron_system_enabled'];
			unset( $args['cron_job_new_cron_system_enabled'] );
		}

		if ( array_key_exists( 'enable_error_debugging', $args ) ) {
			$val                            = strtolower( sanitize_text_field( $args['enable_error_debugging'] ) );
			$data['enable_error_debugging'] = in_array( $val, array( 'on', 'off' ), true ) ? $val : $defaults['enable_error_debugging'];
			unset( $args['enable_error_debugging'] );
		}

		if ( array_key_exists( 'cache_ttl', $args ) ) {
			$data['cache_ttl'] = absint( $args['cache_ttl'] );
			unset( $args['cache_ttl'] );
		}

		if ( array_key_exists( 'overridden_structured_data', $args ) ) {
			$val                                = strtolower( sanitize_text_field( $args['overridden_structured_data'] ) );
			$data['overridden_structured_data'] = in_array( $val, array( 'on', 'off' ), true ) ? $val : $defaults['overridden_structured_data'];
			unset( $args['overridden_structured_data'] );
		}

		if ( array_key_exists( 'disable_pixel', $args ) ) {
			$val                   = strtolower( sanitize_text_field( $args['disable_pixel'] ) );
			$data['disable_pixel'] = in_array( $val, array( 'enable', 'disable' ), true ) ? $val : $defaults['disable_pixel'];
			unset( $args['disable_pixel'] );
		}

		if ( array_key_exists( 'pixel_id', $args ) ) {
			$data['pixel_id'] = ! empty( $args['pixel_id'] ) ? sanitize_text_field( $args['pixel_id'] ) : '';
			unset( $args['pixel_id'] );
		}

		if ( array_key_exists( 'disable_remarketing', $args ) ) {
			$val                         = strtolower( sanitize_text_field( $args['disable_remarketing'] ) );
			$data['disable_remarketing'] = in_array( $val, array( 'enable', 'disable' ), true ) ? $val : $defaults['disable_remarketing'];
			unset( $args['disable_remarketing'] );
		}

		if ( array_key_exists( 'remarketing_id', $args ) ) {
			$data['remarketing_id'] = ! empty( $args['remarketing_id'] ) ? sanitize_text_field( $args['remarketing_id'] ) : '';
			unset( $args['remarketing_id'] );
		}

		if ( array_key_exists( 'remarketing_label', $args ) ) {
			$data['remarketing_label'] = ! empty( $args['remarketing_label'] ) ? sanitize_text_field( $args['remarketing_label'] ) : '';
			unset( $args['remarketing_label'] );
		}

		if ( array_key_exists( 'pinterest_tag_id', $args ) ) {
			$data['pinterest_tag_id'] = ! empty( $args['pinterest_tag_id'] ) ? sanitize_text_field( $args['pinterest_tag_id'] ) : '';
			unset( $args['pinterest_tag_id'] );
		}

		if ( array_key_exists( 'pinterest_conversion_tracking', $args ) ) {
			$val                                   = strtolower( sanitize_text_field( $args['pinterest_conversion_tracking'] ) );
			$data['pinterest_conversion_tracking'] = in_array( $val, array( 'enable', 'disable' ), true ) ? $val : $defaults['pinterest_conversion_tracking'];
			unset( $args['pinterest_conversion_tracking'] );
		}

		if ( array_key_exists( 'allow_all_shipping', $args ) ) {
			$val                        = strtolower( sanitize_text_field( $args['allow_all_shipping'] ) );
			$data['allow_all_shipping'] = in_array( $val, array( 'yes', 'no' ), true ) ? $val : $defaults['allow_all_shipping'];
			unset( $args['allow_all_shipping'] );
		}

		if ( array_key_exists( 'only_free_shipping', $args ) ) {
			$val                        = strtolower( sanitize_text_field( $args['only_free_shipping'] ) );
			$data['only_free_shipping'] = in_array( $val, array( 'yes', 'no' ), true ) ? $val : $defaults['only_free_shipping'];
			unset( $args['only_free_shipping'] );
		}

		if ( array_key_exists( 'only_local_pickup_shipping', $args ) ) {
			$val                                = strtolower( sanitize_text_field( $args['only_local_pickup_shipping'] ) );
			$data['only_local_pickup_shipping'] = in_array( $val, array( 'yes', 'no' ), true ) ? $val : $defaults['only_local_pickup_shipping'];
			unset( $args['only_local_pickup_shipping'] );
		}

		if ( array_key_exists( 'enable_ftp_upload', $args ) ) {
			$val                       = strtolower( sanitize_text_field( $args['enable_ftp_upload'] ) );
			$data['enable_ftp_upload'] = in_array( $val, array( 'yes', 'no' ), true ) ? $val : $defaults['enable_ftp_upload'];
			unset( $args['enable_ftp_upload'] );
		}

		if ( array_key_exists( 'enable_cdata', $args ) ) {
			$val                  = strtolower( sanitize_text_field( $args['enable_cdata'] ) );
			$data['enable_cdata'] = in_array( $val, array( 'yes', 'no' ), true ) ? $val : $defaults['enable_cdata'];
			unset( $args['enable_cdata'] );
		}

		// Nested objects: taxonomy and identifier custom fields.
		// Track whether either changed so we can invalidate the V5
		// product-attribute cache transient — V5 did this on every toggle
		// flip and admin attribute dropdowns rely on it for fresh state.
		$custom_fields_changed = false;

		if ( array_key_exists( 'woo_feed_taxonomy', $args ) && is_array( $args['woo_feed_taxonomy'] ) ) {
			$new_value = array_map( 'sanitize_text_field', $args['woo_feed_taxonomy'] );
			if ( ! isset( $original['woo_feed_taxonomy'] ) || $new_value !== (array) $original['woo_feed_taxonomy'] ) {
				$custom_fields_changed = true;
			}
			$data['woo_feed_taxonomy'] = $new_value;
			unset( $args['woo_feed_taxonomy'] );
		}

		if ( array_key_exists( 'woo_feed_identifier', $args ) && is_array( $args['woo_feed_identifier'] ) ) {
			$new_value = array_map( 'sanitize_text_field', $args['woo_feed_identifier'] );
			if ( ! isset( $original['woo_feed_identifier'] ) || $new_value !== (array) $original['woo_feed_identifier'] ) {
				$custom_fields_changed = true;
			}
			$data['woo_feed_identifier'] = $new_value;
			unset( $args['woo_feed_identifier'] );
		}

		// Allow extensions to handle custom setting keys via filter.
		if ( ! empty( $args ) ) {
			foreach ( $args as $key => $value ) {
				if ( has_filter( "woo_feed_save_{$key}_option" ) ) {
					$data[ $key ] = apply_filters( "woo_feed_save_{$key}_option", sanitize_text_field( $value ) );
				}
			}
		}

		update_option( self::SETTINGS_KEY, $data, false );

		// V5 parity: when identifier/taxonomy toggles change, flush the
		// product-attribute dropdown transient so admin UI dropdowns refresh
		// on next render. V5 fired this from its per-toggle AJAX handler.
		if ( $custom_fields_changed ) {
			delete_transient( '__woo_feed_cache_woo_feed_dropdown_product_attributes' );
			$actions[] = 'product_attribute_cache_cleared';

			/**
			 * Fires after a custom-field toggle change is persisted, mirroring
			 * the V5 cache-invalidation moment for third-party listeners.
			 *
			 * @since 8.0.0
			 *
			 * @param array $woo_feed_identifier Current identifier toggles.
			 * @param array $woo_feed_taxonomy   Current taxonomy toggles.
			 */
			do_action(
				'ctxfeed_custom_fields_settings_changed',
				isset( $data['woo_feed_identifier'] ) ? $data['woo_feed_identifier'] : array(),
				isset( $data['woo_feed_taxonomy'] ) ? $data['woo_feed_taxonomy'] : array()
			);
		}

		/**
		 * Fires after settings are updated via V8 REST API.
		 *
		 * @since 8.0.0
		 *
		 * @param array $data     Updated settings.
		 * @param array $original Settings before update.
		 * @param array $actions  Special actions executed.
		 */
		do_action( 'ctxfeed_settings_updated', $data, $original, $actions );

		$response = array(
			'message'  => __( 'Settings saved successfully.', 'woo-feed' ),
			'settings' => $data,
		);

		if ( ! empty( $actions ) ) {
			$response['actions_executed'] = $actions;
		}

		return $this->success( $response );
	}

	/**
	 * Normalize toggle values in settings arrays.
	 *
	 * V5 may store booleans (true/false) instead of 'enable'/'disable'
	 * strings in woo_feed_identifier and woo_feed_taxonomy. This method
	 * converts any truthy/falsy value to canonical 'enable'/'disable'
	 * strings for consistent V8 usage.
	 *
	 * @since 8.0.0
	 *
	 * @param array $settings Settings array.
	 * @return array Normalized settings.
	 */
	private function normalize_toggle_values( array $settings ): array {
		$toggle_keys = array( 'woo_feed_identifier', 'woo_feed_taxonomy' );

		foreach ( $toggle_keys as $key ) {
			if ( ! isset( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) ) {
				continue;
			}

			foreach ( $settings[ $key ] as $field => $value ) {
				$settings[ $key ][ $field ] = $this->to_enable_disable( $value );
			}
		}

		return $settings;
	}

	/**
	 * Convert a toggle value to 'enable' or 'disable' string.
	 *
	 * Handles booleans, strings ('enable', 'disable', 'on', 'off',
	 * 'yes', 'no', '1', '0'), and integer/null values.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $value Raw toggle value.
	 * @return string 'enable' or 'disable'.
	 */
	private function to_enable_disable( $value ): string {
		// Already canonical.
		if ( 'enable' === $value ) {
			return 'enable';
		}

		if ( 'disable' === $value ) {
			return 'disable';
		}

		// Boolean true/false (common V5 edge case).
		if ( true === $value || 1 === $value || '1' === $value ) {
			return 'enable';
		}

		if ( false === $value || 0 === $value || '0' === $value || null === $value || '' === $value ) {
			return 'disable';
		}

		// String variants.
		$lower  = strtolower( (string) $value );
		$truthy = array( 'on', 'yes', 'y', 'true' );
		$falsy  = array( 'off', 'no', 'n', 'false' );

		if ( in_array( $lower, $truthy, true ) ) {
			return 'enable';
		}

		if ( in_array( $lower, $falsy, true ) ) {
			return 'disable';
		}

		// Unknown value — treat as disabled for safety.
		return 'disable';
	}

	/**
	 * Get feature flags from FeatureGate.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_features( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		return $this->success( FeatureGate::get_all_features() );
	}

	// =====================================================================
	// Dropdown endpoints — V8 replacement for V5 /drop_down/?type=X
	// =====================================================================

	/**
	 * Get dropdown data.
	 *
	 * Supports three modes:
	 *   - ?type=file_types        — single dropdown
	 *   - ?types=file_types,delimiters — batch multiple
	 *   - (no params)             — all dropdowns
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_dropdowns( \WP_REST_Request $request ): \WP_REST_Response {
		$type  = $request->get_param( 'type' );
		$types = $request->get_param( 'types' );

		// Single type request.
		if ( ! empty( $type ) ) {
			$data = DropdownRegistry::get( $type );

			if ( null === $data ) {
				return $this->error(
					sprintf(
						/* translators: %s: dropdown type key */
						__( 'Unknown dropdown type: %s', 'woo-feed' ),
						$type
					),
					404
				);
			}

			return $this->success(
				array(
					'type' => $type,
					'data' => $data,
				) 
			);
		}

		// Batch request with comma-separated types.
		if ( ! empty( $types ) ) {
			$type_list = array_map( 'trim', explode( ',', $types ) );
			$data      = DropdownRegistry::get_multiple( $type_list );

			return $this->success(
				array(
					'types' => array_keys( $data ),
					'data'  => $data,
				) 
			);
		}

		// No params — return all dropdowns.
		$data = DropdownRegistry::get_all();

		return $this->success(
			array(
				'types' => array_keys( $data ),
				'data'  => $data,
			) 
		);
	}

	/**
	 * List available dropdown type keys.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_dropdown_types( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		return $this->success(
			array(
				'types' => DropdownRegistry::get_types(),
				'total' => count( DropdownRegistry::get_types() ),
			) 
		);
	}
}
