<?php
/**
 * Compatibility class for Advanced Custom Fields (ACF) plugin.
 *
 * @package CTXFeed\Compat\Shims
 * @link    https://wordpress.org/plugins/advanced-custom-fields/
 */

namespace CTXFeed\Compat\Shims;

use CTXFeed\Compat\Support\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ACFCompatibility
 *
 * Handles ACF field value retrieval.
 *
 * @package CTXFeed\Compat\Shims
 */
class ACFCompatibility {

	/**
	 * ACFCompatibility Constructor.
	 */
	public function __construct() {
		// ACF field list for dropdown.
		add_filter( 'woo_feed_acf_field_list', array( $this, 'get_acf_field_list' ), 10, 1 );

		// ACF field value hook.
		add_filter( 'woo_feed_filter_acf_field_value', array( $this, 'get_acf_field_value' ), 10, 3 );
	}

	/**
	 * Get ACF field list for dropdown.
	 *
	 * @param array $options Current field options.
	 *
	 * @return array
	 */
	public function get_acf_field_list( $options ) {
		$acf_fields = Cache::get( 'acf_field_list' );

		if ( false === $acf_fields && function_exists( 'acf_get_field_groups' ) ) {
			$acf_fields   = array();
			$field_groups = acf_get_field_groups();

			foreach ( $field_groups as $group ) {
				// DO NOT USE here: $fields = acf_get_fields($group['key']);
				// because it causes repeater field bugs and returns "trashed" fields.
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- ACF stores field definitions as `acf-field` posts; there is no ACF API that returns them without the repeater/trashed bugs noted above. The result is memoised in the Cache wrapper below.
				$fields = get_posts(
					array(
						// phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_posts_per_page -- A field group holds a handful of field definitions and ALL of them must be listed in the attribute dropdown; paging would silently truncate the mapping UI.
						'posts_per_page'         => -1,
						'post_type'              => 'acf-field',
						'orderby'                => 'menu_order',
						'order'                  => 'ASC',
						// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- Deliberate: WPML rewrites this query to the current language and would drop field definitions from the list.
						'suppress_filters'       => true, // DO NOT allow WPML to modify the query.
						'post_parent'            => $group['ID'],
						'post_status'            => 'any',
						'update_post_meta_cache' => false,
					)
				);

				foreach ( $fields as $field ) {
					$acf_fields[ 'acf_fields_' . $field->post_name ] = $field->post_title;
				}
			}

			Cache::set( 'acf_field_list', $acf_fields );
		}

		return is_array( $acf_fields ) ? $acf_fields : $options;
	}

	/**
	 * Get ACF field value.
	 *
	 * @param mixed       $value     Current field value.
	 * @param \WC_Product $product   Product object.
	 * @param string      $field_key The ACF field key.
	 *
	 * @return mixed
	 */
	public function get_acf_field_value( $value, $product, $field_key ) {
		// Return existing value if already set.
		if ( ! empty( $value ) ) {
			return $value;
		}

		// Check if ACF is installed and active.
		if ( ! class_exists( 'ACF' ) || ! function_exists( 'get_field' ) ) {
			return $value;
		}

		// Remove the prefix to get the actual ACF field key.
		$acf_field_key = str_replace( 'acf_fields_', '', $field_key );

		// Retrieve and return the ACF field value.
		return get_field( $acf_field_key, $product->get_id() );
	}
}
