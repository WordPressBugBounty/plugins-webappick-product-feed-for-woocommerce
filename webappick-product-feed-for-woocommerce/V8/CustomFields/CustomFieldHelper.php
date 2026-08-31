<?php
/**
 * CustomFieldHelper — Field definitions for product custom fields.
 *
 * Defines the 18 custom fields (identifiers, information, units, custom)
 * that can be enabled/disabled in the Settings → Custom Fields tab.
 * Mirror of V5\Helper\CustomFieldHelper with V8 filter names.
 *
 * @package    CTXFeed
 * @subpackage V8/CustomFields
 * @since      8.0.0
 */

namespace CTXFeed\V8\CustomFields;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom field definitions helper.
 *
 * Each field is an array of: [ label, default_enabled, type ].
 *   - type 'text': Rendered as text input on product pages.
 *   - type 'taxonomy': Registered as a WordPress taxonomy.
 *   - type 'date': Rendered as text input (same as 'text' for input rendering).
 *
 * @since 8.0.0
 */
class CustomFieldHelper {

	/**
	 * Get all custom field definitions.
	 *
	 * @since 8.0.0
	 *
	 * @return array Associative array of field_key => [ label, default_enabled, type ].
	 */
	public static function get_fields(): array {
		$custom_fields = array(
			'brand'                     => array( __( 'Brand', 'woo-feed' ), true, 'taxonomy' ),
			'gtin'                      => array( __( 'GTIN', 'woo-feed' ), true, 'text' ),
			'mpn'                       => array( __( 'MPN', 'woo-feed' ), true, 'text' ),
			'ean'                       => array( __( 'EAN', 'woo-feed' ), true, 'text' ),
			'isbn'                      => array( __( 'ISBN', 'woo-feed' ), true, 'text' ),
			'age_group'                 => array( __( 'Age group', 'woo-feed' ), true, 'text' ),
			'gender'                    => array( __( 'Gender', 'woo-feed' ), true, 'text' ),
			'material'                  => array( __( 'Material', 'woo-feed' ), true, 'text' ),
			'cost_of_good_sold'         => array( __( 'Cost of good sold', 'woo-feed' ), true, 'text' ),
			'availability_date'         => array( __( 'Availability Date', 'woo-feed' ), true, 'date' ),
			'unit'                      => array( __( 'Unit', 'woo-feed' ), true, 'text' ),
			'unit_pricing_measure'      => array( __( 'Unit Price Measure', 'woo-feed' ), true, 'text' ),
			'unit_pricing_base_measure' => array( __( 'Unit Price Base Measure', 'woo-feed' ), true, 'text' ),
			'custom_field_0'            => array( __( 'Custom field 0', 'woo-feed' ), true, 'text' ),
			'custom_field_1'            => array( __( 'Custom field 1', 'woo-feed' ), true, 'text' ),
			'custom_field_2'            => array( __( 'Custom field 2', 'woo-feed' ), true, 'text' ),
			'custom_field_3'            => array( __( 'Custom field 3', 'woo-feed' ), true, 'text' ),
			'custom_field_4'            => array( __( 'Custom field 4', 'woo-feed' ), true, 'text' ),
		);

		/**
		 * Filter custom field definitions.
		 *
		 * V8 fires both the legacy V5 filter and the new V8 filter
		 * to ensure backward compatibility with third-party extensions.
		 *
		 * @since 8.0.0
		 *
		 * @param array $custom_fields Field definitions.
		 */
		$custom_fields = apply_filters( 'woo_feed_product_custom_fields', $custom_fields );

		return apply_filters( 'ctxfeed_product_custom_fields', $custom_fields );
	}

	/**
	 * Get only taxonomy-type fields.
	 *
	 * @since 8.0.0
	 *
	 * @return array Filtered array of taxonomy fields.
	 */
	public static function get_taxonomy_fields(): array {
		return array_filter(
			self::get_fields(),
			function ( $field ) {
				return 'taxonomy' === $field[2];
			} 
		);
	}

	/**
	 * V5-actual install defaults for the custom-field toggles.
	 *
	 * Mirrors verbatim what `V5\Utility\Settings::get('defaults')` returns —
	 * every field starts `'disable'` except `availability_date`. The helper's
	 * `default_enabled = true` flag (the third tuple element of `get_fields()`)
	 * is documented intent but dead code in V5: `Settings::get` populates
	 * every key with `'disable'` first, so the helper's fallback never fires.
	 * V8 mirrors V5 actual so customers migrating from V5 see no surprise
	 * behavior change. The helper flag stays as documented intent for
	 * future opt-in. PROD-FRD-10.3.
	 *
	 * Shape:
	 *   [
	 *     'woo_feed_taxonomy'   => [ 'brand' => 'disable' ],
	 *     'woo_feed_identifier' => [ 'gtin' => 'disable', ..., 'availability_date' => 'enable', ... ],
	 *   ]
	 *
	 * @since 8.0.0
	 *
	 * @return array{woo_feed_taxonomy: array<string,string>, woo_feed_identifier: array<string,string>}
	 */
	public static function get_install_defaults(): array {
		// Fields V5 ships enabled out-of-the-box. Keep this list narrow —
		// adding to it changes the fresh-install experience.
		$enabled_by_default = array(
			'availability_date',
		);

		$taxonomy   = array();
		$identifier = array();

		foreach ( self::get_fields() as $key => $def ) {
			$type  = isset( $def[2] ) ? (string) $def[2] : 'text';
			$value = in_array( $key, $enabled_by_default, true ) ? 'enable' : 'disable';

			if ( 'taxonomy' === $type ) {
				$taxonomy[ $key ] = $value;
			} else {
				$identifier[ $key ] = $value;
			}
		}

		/**
		 * Filter the install-time defaults for custom-field toggles.
		 *
		 * Fired once at boot when the seeder writes missing keys. Use this
		 * to flip a specific field on by default for new installs, e.g.
		 * `add_filter( 'ctxfeed_custom_field_install_defaults', function ( $d ) {
		 *     $d['woo_feed_identifier']['gtin'] = 'enable';
		 *     return $d;
		 * } );`
		 *
		 * @since 8.0.0
		 *
		 * @param array $defaults [ woo_feed_taxonomy, woo_feed_identifier ].
		 */
		return apply_filters(
			'ctxfeed_custom_field_install_defaults',
			array(
				'woo_feed_taxonomy'   => $taxonomy,
				'woo_feed_identifier' => $identifier,
			)
		);
	}

	/**
	 * Get only text/date (input-renderable) fields.
	 *
	 * @since 8.0.0
	 *
	 * @return array Filtered array of input fields.
	 */
	public static function get_input_fields(): array {
		return array_filter(
			self::get_fields(),
			function ( $field ) {
				return in_array( $field[2], array( 'text', 'date' ), true );
			} 
		);
	}
}
