<?php
/**
 * CategoryMapping — Maps WooCommerce categories to channel-specific taxonomies.
 *
 * Used for Google Product Category, Facebook Category, etc.
 * Reads a mapping table from feed config and writes the mapped value
 * to a configurable target attribute.
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements XFRM-FRD-6.1, XFRM-FRD-6.2, XFRM-FRD-6.3, XFRM-FRD-6.4
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category mapping transform.
 *
 * @since 8.0.0
 */
class CategoryMapping implements TransformInterface {

	/**
	 * Map WooCommerce product category to channel taxonomy.
	 *
	 * Reads `category_mapping` config (keyed by WC category string).
	 * Writes the mapped value to the target attribute (default:
	 * `google_product_category`). Returns unchanged if no mappings
	 * or no product_cat.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-6.1, XFRM-FRD-6.2
	 * @hook ctxfeed_category_mapped Filter to override mapping results.
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Product data with mapped category.
	 */
	public function transform( array $product_data, Config $config ): array {
		// @implements S-02 — Custom category mapping support (wf_cmapping_).
		$product_data = $this->apply_custom_mapping( $product_data, $config );

		$mappings = $config->get( 'category_mapping', array() );

		// No-op on empty. @implements XFRM-FRD-6.4.
		if ( empty( $mappings ) || empty( $product_data['product_cat'] ) ) {
			return $product_data;
		}

		$wc_category = $product_data['product_cat'];

		// Configurable target attribute. @implements XFRM-FRD-6.2.
		$target = $config->get( 'category_mapping_target', 'google_product_category' );

		// Direct mapping lookup. @implements XFRM-FRD-6.1.
		if ( isset( $mappings[ $wc_category ] ) ) {
			$product_data[ $target ] = $mappings[ $wc_category ];
		}

		/**
		 * Filter category mapping results.
		 *
		 * Allows developers to override mappings or add additional
		 * channel-specific mappings.
		 *
		 * @since 8.0.0
		 *
		 * @param array  $product_data Product data with mapped category.
		 * @param array  $mappings     Category mapping table.
		 * @param Config $config       Feed configuration.
		 */
		$product_data = apply_filters( 'ctxfeed_category_mapped', $product_data, $mappings, $config );

		return $product_data;
	}

	/**
	 * Apply custom category mappings from wf_cmapping_ options.
	 *
	 * V5 stores custom category mappings in WordPress options named
	 * `wf_cmapping_{mapping_name}`. Each option contains an array of
	 * WC term_id => merchant category string mappings.
	 *
	 * Product data attributes matching `wf_cmapping_*` prefix trigger
	 * lookup against product's WC categories.
	 *
	 * @since 8.0.0
	 * @implements S-02
	 *
	 * @param array  $product_data Product data.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Product data with custom mappings applied.
	 */
	private function apply_custom_mapping( array $product_data, Config $config ): array {
		// Look for any attributes that reference a custom mapping.
		$custom_mapping_name = $config->get( 'custom_category_mapping', '' );

		if ( empty( $custom_mapping_name ) ) {
			return $product_data;
		}

		$mapping_option = get_option( 'wf_cmapping_' . sanitize_key( $custom_mapping_name ), array() );

		if ( empty( $mapping_option ) || ! is_array( $mapping_option ) ) {
			return $product_data;
		}

		// Get product ID from data.
		$product_id = ! empty( $product_data['id'] ) ? (int) $product_data['id'] : 0;

		if ( empty( $product_id ) ) {
			return $product_data;
		}

		// Get product's WC categories.
		$product_terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

		if ( is_wp_error( $product_terms ) || empty( $product_terms ) ) {
			return $product_data;
		}

		// Find the deepest matching category in the mapping table.
		$matched_value = '';
		$deepest_depth = -1;

		foreach ( $product_terms as $term_id ) {
			$term_id_str = (string) $term_id;

			if ( isset( $mapping_option[ $term_id_str ] ) && ! empty( $mapping_option[ $term_id_str ] ) ) {
				$depth = count( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) );

				if ( $depth > $deepest_depth ) {
					$deepest_depth = $depth;
					$matched_value = $mapping_option[ $term_id_str ];
				}
			}
		}

		if ( ! empty( $matched_value ) ) {
			$target                  = $config->get( 'category_mapping_target', 'google_product_category' );
			$product_data[ $target ] = $matched_value;
		}

		return $product_data;
	}
}
