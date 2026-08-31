<?php
/**
 * CustomFieldInstaller — Idempotent on-boot seeding of custom-field toggles.
 *
 * Runs every page load but is safe: it only fills MISSING keys in
 * `woo_feed_settings.{woo_feed_identifier, woo_feed_taxonomy}`. Existing
 * user choices are never touched.
 *
 * Why this exists: V5 ships defaults via `Settings::get('defaults')` — every
 * custom-field key is present (most `'disable'`, only `availability_date`
 * `'enable'`). But V5 only merges those at *read*-time via wp_parse_args;
 * the option in the DB stays empty until the user clicks Save. The V8
 * `CustomFieldRegistrar` reads `get_option('woo_feed_settings')` directly
 * and short-circuits when the `woo_feed_identifier` array is missing —
 * meaning a fresh V8 install renders zero custom-field inputs on product
 * edit screens. Seeding writes the V5-actual default map on first boot so
 * the registrar finds populated state immediately.
 *
 * Default values match V5 verbatim (only `availability_date` enabled);
 * sourced from `CustomFieldHelper::get_install_defaults()` so V5/V8 agree
 * on one place.
 *
 * @package    CTXFeed
 * @subpackage V8/CustomFields
 * @since      8.0.0
 * @implements PROD-FRD-10.3
 */

namespace CTXFeed\V8\CustomFields;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds missing custom-field toggle defaults on every V8 boot.
 *
 * @since 8.0.0
 */
class CustomFieldInstaller {

	/**
	 * Shared option key with V5.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const SETTINGS_KEY = 'woo_feed_settings';

	/**
	 * Ensure `woo_feed_settings.woo_feed_identifier` and
	 * `woo_feed_settings.woo_feed_taxonomy` contain a default entry for
	 * every helper-registered field.
	 *
	 * Idempotent: only adds keys that don't yet exist. Persists the option
	 * only if at least one key was added (no needless writes on repeat
	 * page loads).
	 *
	 * @since 8.0.0
	 *
	 * @return bool True if the option was updated, false if already complete.
	 */
	public function seed_defaults(): bool {
		$install_defaults   = CustomFieldHelper::get_install_defaults();
		$default_taxonomy   = isset( $install_defaults['woo_feed_taxonomy'] ) && is_array( $install_defaults['woo_feed_taxonomy'] )
			? $install_defaults['woo_feed_taxonomy']
			: array();
		$default_identifier = isset( $install_defaults['woo_feed_identifier'] ) && is_array( $install_defaults['woo_feed_identifier'] )
			? $install_defaults['woo_feed_identifier']
			: array();

		if ( empty( $default_taxonomy ) && empty( $default_identifier ) ) {
			return false;
		}

		$settings = get_option( self::SETTINGS_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$identifier = isset( $settings['woo_feed_identifier'] ) && is_array( $settings['woo_feed_identifier'] )
			? $settings['woo_feed_identifier']
			: array();
		$taxonomy   = isset( $settings['woo_feed_taxonomy'] ) && is_array( $settings['woo_feed_taxonomy'] )
			? $settings['woo_feed_taxonomy']
			: array();

		$mutated = false;

		foreach ( $default_taxonomy as $key => $default_value ) {
			if ( ! array_key_exists( $key, $taxonomy ) ) {
				$taxonomy[ $key ] = $default_value;
				$mutated          = true;
			}
		}

		foreach ( $default_identifier as $key => $default_value ) {
			if ( ! array_key_exists( $key, $identifier ) ) {
				$identifier[ $key ] = $default_value;
				$mutated            = true;
			}
		}

		if ( ! $mutated ) {
			return false;
		}

		$settings['woo_feed_identifier'] = $identifier;
		$settings['woo_feed_taxonomy']   = $taxonomy;

		// `false` = no autoload — V5 stores this as non-autoloaded; preserve.
		update_option( self::SETTINGS_KEY, $settings, false );

		/**
		 * Fires after V8 seeds default custom-field toggles.
		 *
		 * @since 8.0.0
		 *
		 * @param array $identifier Current identifier toggle map.
		 * @param array $taxonomy   Current taxonomy toggle map.
		 */
		do_action( 'ctxfeed_custom_fields_defaults_seeded', $identifier, $taxonomy );

		return true;
	}
}
