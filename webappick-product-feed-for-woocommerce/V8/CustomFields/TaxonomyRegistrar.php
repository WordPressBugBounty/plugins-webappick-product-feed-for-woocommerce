<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "wf" is an established V5-era prefix registered in phpcs.xml.dist; renaming would break 100K+ existing installs.
/**
 * TaxonomyRegistrar — Registers custom taxonomies for product custom fields.
 *
 * When the Brand custom field is enabled in Settings → Custom Fields,
 * this class registers the `woo-feed-brand` taxonomy on the `product`
 * post type. Replaces V5's woo_feed_custom_taxonomy() function.
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
 * Custom taxonomy registrar.
 *
 * @since 8.0.0
 */
class TaxonomyRegistrar {

	/**
	 * WordPress option key for plugin settings.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const SETTINGS_KEY = 'woo_feed_settings';

	/**
	 * Initialize taxonomy registration on WordPress init hook.
	 *
	 * Uses priority 5 to register before most other hooks that might
	 * query taxonomy terms.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function init(): void {
		// Remove V5 taxonomy registration to prevent conflicts.
		$this->remove_v5_taxonomy_hook();

		add_action( 'init', array( $this, 'register_taxonomies' ), 5 );
	}

	/**
	 * Remove V5's woo_feed_custom_taxonomy hook.
	 *
	 * V5 registers woo_feed_custom_taxonomy() on 'init' via helper.php.
	 * We remove it and handle registration in V8 instead.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	private function remove_v5_taxonomy_hook(): void {
		if ( function_exists( 'woo_feed_custom_taxonomy' ) ) {
			remove_action( 'init', 'woo_feed_custom_taxonomy' );
		}
	}

	/**
	 * Get the combined taxonomy and identifier settings.
	 *
	 * @since 8.0.0
	 *
	 * @return array Merged settings array.
	 */
	private function get_custom_attribute_settings(): array {
		$settings = get_option( self::SETTINGS_KEY, array() );

		if ( ! is_array( $settings ) ) {
			return array();
		}

		$taxonomy   = isset( $settings['woo_feed_taxonomy'] ) ? (array) $settings['woo_feed_taxonomy'] : array();
		$identifier = isset( $settings['woo_feed_identifier'] ) ? (array) $settings['woo_feed_identifier'] : array();

		return array_merge( $taxonomy, $identifier );
	}

	/**
	 * Register custom taxonomies for enabled taxonomy-type fields.
	 *
	 * Hooked to: init (priority 5)
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function register_taxonomies(): void {
		$attributes    = $this->get_custom_attribute_settings();
		$taxonomy_defs = CustomFieldHelper::get_taxonomy_fields();

		if ( empty( $taxonomy_defs ) ) {
			return;
		}

		foreach ( $taxonomy_defs as $key => $field_def ) {
			// Only register if the field is enabled.
			if ( ! isset( $attributes[ $key ] ) || 'enable' !== $attributes[ $key ] ) {
				continue;
			}

			$taxonomy_name = esc_html( $field_def[0] );
			$taxonomy_key  = sprintf( 'woo-feed-%s', strtolower( $key ) );

			$labels = array(
				'name'                       => $taxonomy_name . ' ' . __( 'by CTX Feed', 'woo-feed' ),
				'singular_name'              => $taxonomy_name,
				'menu_name'                  => $taxonomy_name . 's ' . __( 'by CTX Feed', 'woo-feed' ),
				'all_items'                  => __( 'All', 'woo-feed' ) . ' ' . $taxonomy_name . 's',
				'parent_item'                => __( 'Parent', 'woo-feed' ) . ' ' . $taxonomy_name,
				'parent_item_colon'          => __( 'Parent:', 'woo-feed' ) . ' ' . $taxonomy_name . ':',
				'new_item_name'              => __( 'New', 'woo-feed' ) . ' ' . $taxonomy_name . ' ' . __( 'Name', 'woo-feed' ),
				'add_new_item'               => __( 'Add New', 'woo-feed' ) . ' ' . $taxonomy_name,
				'edit_item'                  => __( 'Edit', 'woo-feed' ) . ' ' . $taxonomy_name,
				'update_item'                => __( 'Update', 'woo-feed' ) . ' ' . $taxonomy_name,
				'separate_items_with_commas' => __( 'Separate', 'woo-feed' ) . ' ' . $taxonomy_name . ' ' . __( 'with commas', 'woo-feed' ),
				'search_items'               => __( 'Search', 'woo-feed' ) . ' ' . $taxonomy_name,
				'add_or_remove_items'        => __( 'Add or remove', 'woo-feed' ) . ' ' . $taxonomy_name,
				'choose_from_most_used'      => __( 'Choose from the most used', 'woo-feed' ) . ' ' . $taxonomy_name . 's',
			);

			$args = array(
				'labels'             => $labels,
				'hierarchical'       => true,
				'public'             => true,
				'show_ui'            => true,
				'show_admin_column'  => false,
				'show_in_rest'       => true,
				'show_in_nav_menus'  => true,
				'show_tagcloud'      => true,
				'show_in_quick_edit' => false,
			);

			/**
			 * Filter taxonomy registration arguments.
			 *
			 * @since 8.0.0
			 *
			 * @param array  $args          Taxonomy arguments.
			 * @param string $taxonomy_key  Taxonomy slug (e.g. 'woo-feed-brand').
			 * @param string $key           Field key (e.g. 'brand').
			 */
			$args = apply_filters( 'ctxfeed_custom_taxonomy_args', $args, $taxonomy_key, $key );

			register_taxonomy( $taxonomy_key, 'product', $args );
		}
	}
}
